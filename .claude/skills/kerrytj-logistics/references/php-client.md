# PHP / WordPress 實作：client、欄位正規化、號段配號、貨況輪詢

> **何時讀這份**：要在 PHP（WordPress / WooCommerce plugin）內串嘉里大榮——建單、修改、退貨取件、印標籤、查貨況。TypeScript 版與 curl 見 `code-examples.md`。
> 欄位 / 合法值：官方《客戶 API 串接規格書》v1.1.0。程式已通過 `php -l` 語法檢查，**尚未以真實帳密端到端跑過**（規格書帳號表空白），第一次在 `EDIEntranceTest` 聯調時逐項核對。

## 目錄

- [1. 檔案配置與設定](#1-檔案配置與設定)
- [2. KerryTjFields.php（正規化、驗證、單號工具）](#2-kerrytjfieldsphp正規化驗證單號工具)
- [3. KerryTjClient.php（wp_remote_post）](#3-kerrytjclientphpwp_remote_post)
- [4. KerryTjStatus.php（貨態映射）](#4-kerrytjstatusphp貨態映射)
- [5. 號段配號（$wpdb + InnoDB 交易）](#5-號段配號wpdb--innodb-交易)
- [6. 使用範例：建單 → 標籤 → 輪詢](#6-使用範例建單--標籤--輪詢)

---

## 1. 檔案配置與設定

- PHP ≥ 8.1、`ext-mbstring`、`ext-json`；WordPress HTTP API（`wp_remote_post`）。
- 憑證**不進 git**：放 `wp-config.php` 常數或加密存 `wp_options`。本專案範本 `.tmp/[物流]嘉里大榮/.env.local`。

```php
// wp-config.php（範例）
define('KERRYTJ_ENV', 'test'); // test | production
define('KERRYTJ_USERNAME', '...');
define('KERRYTJ_PASSWORD', '...');
define('KERRYTJ_CUSTOMER_NO', '...');
```

| 設定 | 測試 | 正式 |
|---|---|---|
| EDI base URL | `https://api.kerrytj.com/EDIEntranceTest` | `https://api.kerrytj.com/EDIEntrance` |
| Tracing base URL | `https://KerryTracingEDI.kerrytj.com`（只有一組） | 同左 |
| 帳密 / 客編 / 號段 | 測試專用（嘉里大榮另發） | 正式專用 |

---

## 2. KerryTjFields.php（正規化、驗證、單號工具）

```php
<?php
declare(strict_types=1);

final class KerryTjFields
{
    /** ShipmentDelivery / UpdateShipmentDelivery 必填 13 欄（規格書橘色） */
    public const SHIPMENT_REQUIRED = [
        'ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd', 'ConsigneePhone', 'BLN',
        'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'Temperature', 'Piece',
    ];

    /** PickupV2 必填 11 欄 */
    public const PICKUP_REQUIRED = [
        'BLN', 'CustomerNo', 'Consignee', 'ConsigneeTel1', 'ConsigneePost', 'ConsigneeAdd',
        'Shipper', 'ShipperTel1', 'ShipperPost', 'ShipperAdd', 'Piece',
    ];

    /** GetLabelPdf / GetLabelPdfA4 只吃這 16 欄 */
    public const LABEL_FIELDS = [
        'ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd', 'ConsigneePhone', 'BLN',
        'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'Piece', 'CON', 'AC', 'Remark', 'Temperature',
    ];

    public static function cut(?string $s, int $n): string
    {
        return mb_substr(trim((string) $s), 0, $n, 'UTF-8');
    }

    public static function zip3(string $zip): string
    {
        return substr(preg_replace('/\D/', '', $zip) ?? '', 0, 3);
    }

    public static function phone(string $p, int $n): string
    {
        return self::cut(preg_replace('/[\s\-]/', '', $p) ?? '', $n);
    }

    /** 台灣時區 yyyyMMdd */
    public static function ymd(?DateTimeInterface $d = null): string
    {
        $d ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
        return $d->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Ymd');
    }

    /** 送出前修成官方長度（收件人 / 寄件人 6 字元、郵遞區號 3 碼…）；所有值轉字串 */
    public static function normalizeShipment(array $s): array
    {
        $fullName = trim((string) ($s['Consignee'] ?? ''));
        $remark = (string) ($s['Remark'] ?? '');
        if (mb_strlen($fullName, 'UTF-8') > 6) {
            $remark = trim("收件人:{$fullName} {$remark}");
        }
        $isCold = ($s['Temperature'] ?? '1') !== '1';

        $s['Consignee']      = self::cut($fullName, 6);
        $s['ConsigneePost']  = self::zip3((string) ($s['ConsigneePost'] ?? ''));
        $s['ConsigneeAdd']   = self::cut($s['ConsigneeAdd'] ?? '', 60);
        $s['ConsigneePhone'] = self::phone((string) ($s['ConsigneePhone'] ?? ''), 15);
        $s['Shipper']        = self::cut($s['Shipper'] ?? '', 6);
        $s['ShipperPost']    = self::zip3((string) ($s['ShipperPost'] ?? ''));
        $s['ShipperAdd']     = self::cut($s['ShipperAdd'] ?? '', 60);
        $s['ShipperTel']     = self::phone((string) ($s['ShipperTel'] ?? ''), 15);
        $s['CON']            = self::cut($s['CON'] ?? '', $isCold ? 10 : 12);
        $s['Remark']         = self::cut($remark, 60);

        return array_map(static fn ($v) => (string) $v, $s);
    }

    /** @return string[] 錯誤訊息；空陣列 = 通過（伺服器 Result 03 不會告訴你原因，送前自檢） */
    public static function validateShipment(array $s): array
    {
        $errors = [];
        foreach (self::SHIPMENT_REQUIRED as $k) {
            if (($s[$k] ?? '') === '') {
                $errors[] = "{$k} 必填";
            }
        }
        if (!preg_match('/^\d{8}$/', (string) ($s['ShipDate'] ?? ''))) {
            $errors[] = 'ShipDate 格式 yyyyMMdd';
        }
        $isCold = ($s['Temperature'] ?? '1') !== '1';
        $bln = (string) ($s['BLN'] ?? '');
        if ($isCold ? !self::isValidColdBln($bln) : !preg_match('/^\d{11}$/', $bln)) {
            $errors[] = $isCold ? 'BLN 應為 10 碼且檢查碼正確' : 'BLN 應為 11 碼數字';
        }
        $piece = (int) ($s['Piece'] ?? 0);
        if ($piece < 1 || $piece > 999) {
            $errors[] = 'Piece 須 1–999';
        }
        foreach (['AC', 'DeclaredValue'] as $k) {
            if (($s[$k] ?? '') !== '' && (int) $s[$k] > 50000) {
                $errors[] = "{$k} 不可超過 50000";
            }
        }
        return $errors;
    }

    /** 低溫單號：9 碼 + 1 碼檢查碼（前 9 碼 mod 7）。667000000 → 6670000002 */
    public static function coldBln(string $serial9): string
    {
        $serial9 = str_pad($serial9, 9, '0', STR_PAD_LEFT);
        return $serial9 . ((int) $serial9 % 7);
    }

    public static function isValidColdBln(string $bln): bool
    {
        return (bool) preg_match('/^\d{10}$/', $bln) && self::coldBln(substr($bln, 0, 9)) === $bln;
    }

    /** 常溫單號：11 碼流水號 +1（PHP 64-bit int 可容納 11 位數） */
    public static function nextNormalBln(string $bln): string
    {
        return str_pad((string) ((int) $bln + 1), 11, '0', STR_PAD_LEFT);
    }

    public static function toLabel(array $shipment): array
    {
        return array_intersect_key($shipment, array_flip(self::LABEL_FIELDS));
    }
}
```

---

## 3. KerryTjClient.php（wp_remote_post）

```php
<?php
declare(strict_types=1);

final class KerryTjException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly mixed $body = null)
    {
        parent::__construct($message);
    }
}

final class KerryTjClient
{
    private const TRACING_BASE = 'https://KerryTracingEDI.kerrytj.com';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $ediBase,   // https://api.kerrytj.com/EDIEntranceTest 或 .../EDIEntrance
        private readonly int $timeout = 60,
    ) {
    }

    public static function fromConstants(): self
    {
        $base = (defined('KERRYTJ_ENV') && KERRYTJ_ENV === 'production')
            ? 'https://api.kerrytj.com/EDIEntrance'
            : 'https://api.kerrytj.com/EDIEntranceTest';
        return new self(KERRYTJ_USERNAME, KERRYTJ_PASSWORD, $base);
    }

    /** @return array{raw: array, succeeded: string[], failed: array<int, array{BLN: string, Error: string}>} */
    public function shipmentDelivery(array $items): array
    {
        return $this->write('ShipmentDelivery', $items);
    }

    /** 已交站所則無法修改 */
    public function updateShipmentDelivery(array $items): array
    {
        return $this->write('UpdateShipmentDelivery', $items);
    }

    /** 常溫取件（退貨）：Shipper* = 取件地（消費者），Consignee* = 送回地（商家）。中午 12 點後 → D+1 派遣 */
    public function pickupV2(array $items): array
    {
        return $this->write('PickupV2', $items);
    }

    /** 單張標籤：body 是單一物件 */
    public function labelPdf(array $shipment): string
    {
        return $this->pdf('Report/GetLabelPdf', KerryTjFields::toLabel($shipment));
    }

    /** A4 3×2 標籤：一次最多 6 筆，自動分批。@return string[] 每個元素是一份 PDF binary */
    public function labelPdfA4(array $shipments): array
    {
        $out = [];
        foreach (array_chunk($shipments, 6) as $chunk) {
            $out[] = $this->pdf('Report/GetLabelPdfA4', array_map([KerryTjFields::class, 'toLabel'], $chunk));
        }
        return $out;
    }

    public function detailPdf(array $shipments): string
    {
        return $this->pdf('Report/GetDetailPdf', array_values($shipments));
    }

    /** 常溫 11 碼。@return array<string, array<int, array>> BLN => 事件（新到舊） */
    public function traceNormal(array $blns): array
    {
        return $this->trace('BLNListTracing', $blns);
    }

    /** 低溫 10 碼 */
    public function traceCold(array $blns): array
    {
        return $this->trace('ColdBLNListTracing', $blns);
    }

    // ------------------------------------------------------------------

    private function post(string $url, array $body): array
    {
        $res = wp_remote_post($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            ],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        if (is_wp_error($res)) {
            throw new KerryTjException('嘉里大榮連線失敗：' . $res->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($res);
        if ($status === 401) {
            throw new KerryTjException('嘉里大榮 Basic Auth 被拒（帳密錯誤、環境用錯或未開通）', 401);
        }
        return [
            'status' => $status,
            'type'   => (string) wp_remote_retrieve_header($res, 'content-type'),
            'body'   => (string) wp_remote_retrieve_body($res),
        ];
    }

    private function write(string $path, array $items): array
    {
        if ($items === []) {
            throw new KerryTjException("{$path}：至少一筆");
        }
        $items = array_values($items); // 一定要是 JSON 陣列
        $res = $this->post("{$this->ediBase}/{$path}", $items);
        $raw = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($raw)) {
            throw new KerryTjException("{$path} HTTP {$res['status']}", $res['status'], $res['body']);
        }
        if (($raw['Result'] ?? '') === '03') {
            throw new KerryTjException("{$path} 錯誤（Result 03，未提供原因）", 200, $raw);
        }
        $failed = $raw['Data'] ?? [];
        $failedBlns = array_column($failed, 'BLN');
        $succeeded = array_values(array_diff(array_column($items, 'BLN'), $failedBlns));
        return ['raw' => $raw, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    private function pdf(string $path, array $body): string
    {
        if (array_is_list($body) && $body === []) {
            throw new KerryTjException("{$path}：空陣列會回空白 PDF");
        }
        $res = $this->post("{$this->ediBase}/{$path}", $body);
        if (!str_contains($res['type'], 'application/pdf')) {
            $detail = json_decode($res['body'], true) ?? $res['body']; // {Message, ModelState}
            throw new KerryTjException("{$path} 未回傳 PDF", $res['status'], $detail);
        }
        return $res['body'];
    }

    private function trace(string $path, array $blns): array
    {
        $body = array_map(static fn (string $bln) => ['BLN' => $bln], array_values($blns));
        $res = $this->post(self::TRACING_BASE . "/api/Tracing/{$path}", $body);
        $json = json_decode($res['body'], true);
        if (!is_array($json) || ($json['Status'] ?? '0') !== '1') {
            throw new KerryTjException("{$path} 失敗：" . ($json['Error'] ?? $res['body']), $res['status'], $json);
        }
        $map = [];
        foreach ($json['Result'] ?? [] as $row) {
            // 官方範例同一 BLN 可能出現兩次：合併 + 去重
            foreach ($row['CargoTracing'] ?? [] as $e) {
                $map[$row['BLN']][$e['Date'] . $e['Time'] . $e['StatusCode']] = $e;
            }
        }
        foreach ($map as $bln => $events) {
            uasort($events, static fn ($a, $b) => strcmp($b['Date'] . $b['Time'], $a['Date'] . $a['Time']));
            $map[$bln] = array_values($events);
        }
        return $map;
    }
}
```

---

## 4. KerryTjStatus.php（貨態映射）

依 `tracking.md` §4 的「建議映射」（推得，非官方）。key 一定要分常溫 type 1 / type 2 / 低溫——同一代碼在三張表意義不同。

```php
<?php
declare(strict_types=1);

final class KerryTjStatus
{
    private const NORMAL_1 = [
        '02' => 'delivered', '09' => 'delivered', '30' => 'delivered', '36' => 'delivered',
        '38' => 'delivered', '62' => 'delivered', '78' => 'delivered',
        '77' => 'returned', '53' => 'returning', '58' => 'returning', '65' => 'returning', '67' => 'returning',
        '19' => 'out_for_delivery', '73' => 'out_for_delivery', '74' => 'out_for_delivery',
        '76' => 'out_for_delivery', '88' => 'out_for_delivery',
        '08' => 'ready_for_pickup', '24' => 'ready_for_pickup', '72' => 'ready_for_pickup',
        '96' => 'ready_for_pickup', 'B1' => 'ready_for_pickup', 'F1' => 'ready_for_pickup',
        '04' => 'info', '63' => 'info', '68' => 'info', 'A1' => 'info',
    ];
    private const NORMAL_1_EXCEPTION = ['00', '05', '06', '10', '11', '12', '13', '14', '15', '16', '17', '18', '22',
        '29', '32', '34', '35', '40', '42', '43', '46', '50', '64', '66', '69', '79', '82', '89', '97',
        'A4', 'B2', 'B3', 'F2', 'F3'];

    private const NORMAL_2 = [
        '01' => 'cancelled', 'C2' => 'cancelled', 'C1' => 'delivered',
        '02' => 'in_transit', '03' => 'in_transit', 'P2' => 'in_transit', 'P1' => 'out_for_delivery',
        'D1' => 'info', 'D2' => 'info', '99' => 'info',
        '04' => 'exception', '05' => 'exception', '06' => 'exception', '07' => 'exception',
        '08' => 'exception', '09' => 'exception',
    ];

    private const COLD = [
        '90' => 'delivered', '91' => 'delivered', '92' => 'delivered',
        '71' => 'out_for_delivery', '73' => 'out_for_delivery', '84' => 'ready_for_pickup',
        '85' => 'returning', '93' => 'returning',
        '8E' => 'exception', '80' => 'exception', '81' => 'exception', '82' => 'exception', '83' => 'exception',
        '86' => 'exception', '87' => 'exception', '88' => 'exception', '89' => 'exception', '96' => 'exception',
        '97' => 'exception',
    ];

    /** 未列出的代碼一律 in_transit（不會誤判為終態） */
    public static function map(array $event, bool $cold): string
    {
        $code = (string) $event['StatusCode'];
        if ($cold) {
            return self::COLD[$code] ?? 'in_transit';
        }
        if ((int) ($event['StatusCodeType'] ?? 1) === 2) {
            return self::NORMAL_2[$code] ?? 'in_transit';
        }
        if (in_array($code, self::NORMAL_1_EXCEPTION, true)) {
            return 'exception';
        }
        return self::NORMAL_1[$code] ?? 'in_transit';
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, ['delivered', 'returned', 'cancelled'], true);
    }
}
```

---

## 5. 號段配號（$wpdb + InnoDB 交易）

```php
<?php
declare(strict_types=1);

/**
 * 資料表：{$wpdb->prefix}kerrytj_bln_ranges
 *   id BIGINT PK, env VARCHAR(10), customer_no VARCHAR(8), is_cold TINYINT(1),
 *   start_no BIGINT, end_no BIGINT, next_no BIGINT   -- 常溫存 11 碼完整單號；低溫存前 9 碼流水號
 * 必須是 InnoDB（SELECT ... FOR UPDATE 才有 row lock）。
 */
function kerrytj_allocate_bln(string $env, string $customerNo, string $temperature): string
{
    global $wpdb;
    $table = $wpdb->prefix . 'kerrytj_bln_ranges';
    $isCold = $temperature !== '1' ? 1 : 0;

    $wpdb->query('START TRANSACTION');
    try {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, next_no FROM {$table}
              WHERE env = %s AND customer_no = %s AND is_cold = %d AND next_no <= end_no
              ORDER BY start_no LIMIT 1 FOR UPDATE",
            $env, $customerNo, $isCold
        ));
        if (!$row) {
            throw new RuntimeException('嘉里大榮託運單號段已用罄，請向營業所申請新號段');
        }
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET next_no = next_no + 1 WHERE id = %d", $row->id));
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }

    return $isCold
        ? KerryTjFields::coldBln((string) $row->next_no)
        : str_pad((string) $row->next_no, 11, '0', STR_PAD_LEFT);
}
```

- 把取到的 BLN **先寫進訂單 meta 再呼叫建單**；建單失敗重送時沿用同一 BLN。
- 剩餘號碼低於門檻（例如 < 200）時發後台通知，提前向營業所申請。

---

## 6. 使用範例：建單 → 標籤 → 輪詢

```php
<?php
declare(strict_types=1);

$kerry = KerryTjClient::fromConstants();
$env = (defined('KERRYTJ_ENV') && KERRYTJ_ENV === 'production') ? 'production' : 'test';

/** @var WC_Order $order */
$bln = $order->get_meta('_kerrytj_bln') ?: kerrytj_allocate_bln($env, KERRYTJ_CUSTOMER_NO, '1');
$order->update_meta_data('_kerrytj_bln', $bln);
$order->save();

$shipment = KerryTjFields::normalizeShipment([
    'ShipDate'       => KerryTjFields::ymd(),
    'CustomerNo'     => KERRYTJ_CUSTOMER_NO,
    'BLN'            => $bln,
    'Shipper'        => '某某商行',
    'ShipperTel'     => '02-2345-6789',
    'ShipperPost'    => '10491',
    'ShipperAdd'     => '台北市中山區某路 1 號',
    'Consignee'      => $order->get_formatted_shipping_full_name(),
    'ConsigneePhone' => $order->get_shipping_phone() ?: $order->get_billing_phone(),
    'ConsigneePost'  => $order->get_shipping_postcode(),
    'ConsigneeAdd'   => $order->get_shipping_state() . $order->get_shipping_city() . $order->get_shipping_address_1(),
    'Temperature'    => '1',
    'Piece'          => '1',
    'CON'            => (string) $order->get_order_number(),
    'AC'             => $order->get_payment_method() === 'cod' ? (string) (int) round((float) $order->get_total()) : '',
    'ETA'            => '4',
]);

if ($errors = KerryTjFields::validateShipment($shipment)) {
    throw new RuntimeException(implode('；', $errors));
}

// 1) 建單（部分成功時 failed 會列出失敗的 BLN）
$result = $kerry->shipmentDelivery([$shipment]);
if ($result['failed']) {
    $order->add_order_note('嘉里大榮建單失敗：' . wp_json_encode($result['failed'], JSON_UNESCAPED_UNICODE));
    return; // 修正後用同一 BLN 重送
}
$order->update_meta_data('_kerrytj_area', $result['raw']['AreaData'][0] ?? []);
$order->update_meta_data('_kerrytj_shipment', $shipment); // 標籤要用同一份資料
$order->save();

// 2) 標籤
[$pdf] = $kerry->labelPdfA4([$shipment]);
$upload = wp_upload_dir();
file_put_contents($upload['basedir'] . "/kerrytj-label-{$bln}.pdf", $pdf);

// 3) WP-Cron 輪詢（例：每 2 小時；常溫 / 低溫分開打）
add_action('kerrytj_poll_tracking', static function () use ($kerry): void {
    $orders = wc_get_orders(['limit' => 50, 'meta_key' => '_kerrytj_bln', 'status' => ['processing', 'shipping']]);
    $blns = [];
    foreach ($orders as $o) {
        $blns[$o->get_meta('_kerrytj_bln')] = $o;
    }
    foreach ($kerry->traceNormal(array_keys($blns)) as $bln => $events) {
        $state = KerryTjStatus::map($events[0], false);
        $blns[$bln]->update_meta_data('_kerrytj_last_event', $events[0]);
        if ($state === 'delivered') {
            $blns[$bln]->update_status('completed', '嘉里大榮：配送完成');
        }
        $blns[$bln]->save();
    }
});
```

> 上例 `wc_get_orders` 的 `'status' => 'shipping'` 是示意的自訂訂單狀態；`traceNormal` 只能放 11 碼常溫單，低溫單要另外用 `traceCold`。
