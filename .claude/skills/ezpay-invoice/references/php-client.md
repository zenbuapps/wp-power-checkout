# ezPay 電子發票 — PHP 參考實作

對應 EZP_INVI_1.2.1 標準版。本 repo 規劃的實作是 **PHP + Vue 的 WordPress 外掛**，
本檔提供 PHP 8.x 參考實作：加解密、CheckCode、送出與重送、金額與品項整數化。
HTTP 用 WordPress 的 `wp_remote_post()`；不在 WordPress 內時換成 cURL / Guzzle 即可，
其他邏輯不變。

> 演算法依據：加密見 concepts.md、CheckCode 適用範圍見 concepts.md
> 「哪些 API 能驗 CheckCode」、重送見 concepts.md「重送與冪等」、
> 金額見 api-reference.md「金額計算」。

## 1. 加解密與 CheckCode

```php
<?php
declare(strict_types=1);

final class EzpayCrypto
{
    private const BLOCK_SIZE = 32; // ezPay 規定 32，不是 AES 標準的 16

    public function __construct(
        private string $hashKey, // 32 bytes
        private string $hashIv,  // 16 bytes
    ) {}

    /** 業務參數 → query string → PKCS#7(32) → AES-256-CBC → 小寫 hex */
    public function encrypt(array $params): string
    {
        $plain = http_build_query($params);
        $pad = self::BLOCK_SIZE - (strlen($plain) % self::BLOCK_SIZE);
        $plain .= str_repeat(chr($pad), $pad);

        $raw = openssl_encrypt(
            $plain,
            'AES-256-CBC',
            $this->hashKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, // padding 已自行補，叫 openssl 不要再補
            $this->hashIv,
        );
        if ($raw === false) {
            throw new RuntimeException('ezPay encrypt failed');
        }
        return bin2hex($raw);
    }

    /** 解密（除錯 / 單元測試用） */
    public function decrypt(string $hex): string
    {
        $plain = openssl_decrypt(
            (string) hex2bin($hex),
            'AES-256-CBC',
            $this->hashKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $this->hashIv,
        );
        if ($plain === false || $plain === '') {
            throw new RuntimeException('ezPay decrypt failed');
        }
        $pad = ord($plain[strlen($plain) - 1]);
        if ($pad < 1 || $pad > self::BLOCK_SIZE) {
            throw new RuntimeException('ezPay invalid padding');
        }
        return substr($plain, 0, -$pad);
    }

    /**
     * 只用在 invoice_issue / invoice_touch_issue / invoice_search 的回應。
     * 作廢、折讓類回應沒有這 5 個欄位，不要呼叫。
     */
    public function verifyCheckCode(array $result): bool
    {
        $fields = [];
        foreach (['InvoiceTransNo', 'MerchantID', 'MerchantOrderNo', 'RandomNum', 'TotalAmt'] as $k) {
            $fields[$k] = isset($result[$k]) ? (string) $result[$k] : ''; // 缺值代空字串
        }
        ksort($fields);
        $expected = strtoupper(hash(
            'sha256',
            'HashIV=' . $this->hashIv . '&' . http_build_query($fields) . '&HashKey=' . $this->hashKey,
        ));
        return hash_equals($expected, (string) ($result['CheckCode'] ?? ''));
    }
}
```

## 2. 金額與品項整數化

輸入是「已攤完折扣的含稅小計」，運費 / 手續費各自一列。輸出可直接放進開立參數。

```php
<?php
declare(strict_types=1);

final class EzpayAmounts
{
    /**
     * 把整筆折扣按比例攤進各列含稅小計；尾差由金額最大的一列吸收。
     * @param int[] $grossList 各列折扣前含稅小計（正整數）
     * @return int[] 各列折扣後含稅小計
     */
    public static function allocateDiscount(array $grossList, int $discount): array
    {
        $sum = array_sum($grossList);
        if ($discount <= 0 || $sum <= 0) {
            return $grossList;
        }
        if ($discount > $sum) {
            throw new InvalidArgumentException('discount exceeds total');
        }
        $shares = [];
        foreach ($grossList as $i => $g) {
            $shares[$i] = (int) round($g * $discount / $sum);
        }
        $maxIdx = array_keys($grossList, max($grossList))[0];
        $shares[$maxIdx] += $discount - array_sum($shares);

        $result = [];
        foreach ($grossList as $i => $g) {
            $result[$i] = $g - $shares[$i];
            if ($result[$i] < 0) {
                throw new RuntimeException('allocated line went negative');
            }
        }
        return $result;
    }

    /**
     * @param array<int, array{name:string, qty:int, gross:int, unit:string}> $lines
     *        gross = 該列含稅小計（已攤折扣），0 元列會被略過
     * @param 'B2B'|'B2C' $category
     * @return array{Amt:int, TaxAmt:int, TotalAmt:int, items: list<array{name:string, count:int, unit:string, price:int, amt:int}>}
     */
    public static function build(array $lines, string $category, float $taxRate = 0.05): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l['gross'] > 0));
        if ($lines === []) {
            throw new InvalidArgumentException('no billable lines');
        }

        $total = array_sum(array_column($lines, 'gross'));
        $amt = (int) round($total / (1 + $taxRate));
        $tax = $total - $amt;

        if ($category === 'B2B') {
            // B2B 品項為未稅：逐列反推，尾差補到金額最大的一列，讓 Σ ItemAmt = Amt
            $nets = array_map(fn ($l) => (int) round($l['gross'] / (1 + $taxRate)), $lines);
            $maxIdx = array_keys($nets, max($nets))[0];
            $nets[$maxIdx] += $amt - array_sum($nets);
        } else {
            $nets = array_column($lines, 'gross'); // B2C 品項為含稅
        }

        $items = [];
        foreach ($lines as $i => $l) {
            $lineAmt = $nets[$i];
            $qty = $l['qty'];
            if ($qty > 0 && $lineAmt % $qty === 0) {
                $items[] = self::item($l['name'], '', $qty, $l['unit'], intdiv($lineAmt, $qty), $lineAmt);
            } else {
                // 除不盡：合成一列，數量寫進品名
                $items[] = self::item($l['name'], " x{$qty}", 1, $l['unit'], $lineAmt, $lineAmt);
            }
        }

        self::assertConsistent($items, $category, $amt, $tax, $total);
        return ['Amt' => $amt, 'TaxAmt' => $tax, 'TotalAmt' => $total, 'items' => $items];
    }

    private static function item(string $name, string $suffix, int $count, string $unit, int $price, int $amt): array
    {
        $name = trim(str_replace('|', '-', wp_strip_all_tags($name)));
        $name = mb_substr($name, 0, 30 - mb_strlen($suffix)) . $suffix; // ItemName ≤ 30 字
        return ['name' => $name, 'count' => $count, 'unit' => $unit, 'price' => $price, 'amt' => $amt];
    }

    /** 送出前的本地檢核，任一不成立就不要送 */
    private static function assertConsistent(array $items, string $category, int $amt, int $tax, int $total): void
    {
        $sum = 0;
        foreach ($items as $it) {
            if ($it['count'] <= 0 || $it['price'] <= 0 || $it['amt'] !== $it['count'] * $it['price']) {
                throw new RuntimeException('item amount mismatch: ' . $it['name']);
            }
            $sum += $it['amt'];
        }
        $expected = $category === 'B2B' ? $amt : $total;
        if ($sum !== $expected || $amt + $tax !== $total) {
            throw new RuntimeException('invoice totals mismatch');
        }
    }
}
```

> `wp_strip_all_tags()` 是 WordPress 函式；不在 WordPress 內改用 `strip_tags()`。
> 只支援單一稅別（`TaxType=1`、5%）；混合稅率（`TaxType=9`）需另外分組計算
> `AmtSales` / `AmtZero` / `AmtFree`，見 api-reference.md 範例。

## 3. Client：開立、重送、作廢、查詢

```php
<?php
declare(strict_types=1);

/** 暫時性錯誤：用同一份 PostData_ 重送 */
final class EzpayTransientException extends RuntimeException {}

final class EzpayInvoiceClient
{
    public function __construct(
        private string $merchantId,
        private EzpayCrypto $crypto,
        private bool $production = false,
    ) {}

    private function baseUrl(): string
    {
        return $this->production ? 'https://inv.ezpay.com.tw' : 'https://cinv.ezpay.com.tw';
    }

    /** 送出已加密的 PostData_；網路錯誤、HTTP 非 2xx、非 JSON 都丟暫時性例外 */
    private function send(string $path, string $postData): array
    {
        $res = wp_remote_post($this->baseUrl() . $path, [
            'timeout' => 30,
            'body'    => ['MerchantID_' => $this->merchantId, 'PostData_' => $postData], // 注意底線
        ]);
        if (is_wp_error($res)) {
            throw new EzpayTransientException('network: ' . $res->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            throw new EzpayTransientException("HTTP {$code}");
        }
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($json) || !isset($json['Status'])) {
            throw new EzpayTransientException('non-JSON response');
        }
        return $json; // ['Status' => ..., 'Message' => ..., 'Result' => JSON 字串]
    }

    private function post(string $path, array $params): array
    {
        return $this->send($path, $this->crypto->encrypt($params));
    }

    /**
     * 第 1 步：組開立參數並加密。呼叫端必須在 sendIssue 之前把回傳值存進資料庫
     * （連同 MerchantOrderNo、TotalAmt，狀態記「開立中」）。
     *
     * @param array $buyer ['category'=>'B2B'|'B2C','name'=>..,'ubn'=>..,'email'=>..,
     *                      'carrierType'=>'0'|'1'|'2'|'', 'carrierNum'=>.., 'loveCode'=>..]
     * @param array $amounts EzpayAmounts::build() 的回傳值
     */
    public function buildIssuePostData(string $merchantOrderNo, array $buyer, array $amounts): string
    {
        $isB2B = $buyer['category'] === 'B2B';
        $hasCarrier = !$isB2B && ($buyer['carrierType'] ?? '') !== '';
        $hasLoveCode = !$isB2B && !$hasCarrier && ($buyer['loveCode'] ?? '') !== '';
        $items = $amounts['items'];

        $params = [
            'RespondType'     => 'JSON',
            'Version'         => '1.5',
            'TimeStamp'       => time(),              // 只取這一次，重送沿用存下的 PostData_
            'MerchantOrderNo' => $merchantOrderNo,    // 例：{訂單編號}_{序號}，≤ 20 字、限英數與 _
            'Status'          => '1',                 // 即時開立
            'Category'        => $buyer['category'],
            'BuyerName'       => $buyer['name'],
            'BuyerUBN'        => $isB2B ? $buyer['ubn'] : '',
            'BuyerEmail'      => $buyer['email'] ?? '',
            'CarrierType'     => $hasCarrier ? $buyer['carrierType'] : '',
            'CarrierNum'      => $hasCarrier ? rawurlencode(trim((string) $buyer['carrierNum'])) : '',
            'LoveCode'        => $hasLoveCode ? $buyer['loveCode'] : '',
            // B2B 必印；B2C 無載具且無捐贈時必印
            'PrintFlag'       => ($isB2B || (!$hasCarrier && !$hasLoveCode)) ? 'Y' : 'N',
            'TaxType'         => '1',
            'TaxRate'         => '5',
            'Amt'             => $amounts['Amt'],
            'TaxAmt'          => $amounts['TaxAmt'],
            'TotalAmt'        => $amounts['TotalAmt'],
            'ItemName'        => implode('|', array_column($items, 'name')),
            'ItemCount'       => implode('|', array_column($items, 'count')),
            'ItemUnit'        => implode('|', array_column($items, 'unit')),
            'ItemPrice'       => implode('|', array_column($items, 'price')),
            'ItemAmt'         => implode('|', array_column($items, 'amt')),
        ];
        return $this->crypto->encrypt($params);
    }

    /** 第 2 步：首次與重送都呼叫這支，傳入存下的同一份 PostData_ */
    public function sendIssue(string $postData, string $merchantOrderNo, int $totalAmt): array
    {
        $res = $this->send('/Api/invoice_issue', $postData);

        if ($res['Status'] === 'LIB10003') {
            // 多半是前一次其實成功了：用自訂編號 + 金額取回原發票
            return $this->searchByOrderNo($merchantOrderNo, $totalAmt);
        }
        if (in_array($res['Status'], ['NOR10001', 'KEY10014'], true)) {
            throw new EzpayTransientException($res['Status']);
        }
        if ($res['Status'] !== 'SUCCESS') {
            throw new RuntimeException("ezPay invoice_issue failed: {$res['Status']} {$res['Message']}");
        }
        $result = json_decode($res['Result'], true);
        if (!is_array($result) || !$this->crypto->verifyCheckCode($result)) {
            throw new RuntimeException('ezPay CheckCode mismatch');
        }
        return $result; // InvoiceNumber / InvoiceTransNo / RandomNum / CreateTime ...
    }

    /** 作廢：LIB10005（已作廢過）視為成功；回應不驗 CheckCode */
    public function invalidate(string $invoiceNumber, string $reason): void
    {
        $res = $this->post('/Api/invoice_invalid', [
            'RespondType'   => 'JSON',
            'Version'       => '1.0',
            'TimeStamp'     => time(),
            'InvoiceNumber' => $invoiceNumber,
            'InvalidReason' => mb_substr($reason, 0, 6), // 中文 6 字 / 英文 20 字
        ]);
        if ($res['Status'] !== 'SUCCESS' && $res['Status'] !== 'LIB10005') {
            throw new RuntimeException("ezPay invoice_invalid failed: {$res['Status']} {$res['Message']}");
        }
    }

    public function searchByNumber(string $invoiceNumber, string $randomNum): array
    {
        return $this->search(['SearchType' => '0', 'InvoiceNumber' => $invoiceNumber, 'RandomNum' => $randomNum]);
    }

    public function searchByOrderNo(string $merchantOrderNo, int $totalAmt): array
    {
        return $this->search(['SearchType' => '1', 'MerchantOrderNo' => $merchantOrderNo, 'TotalAmt' => $totalAmt]);
    }

    private function search(array $keys): array
    {
        $res = $this->post('/Api/invoice_search', [
            'RespondType' => 'JSON',
            'Version'     => '1.3',
            'TimeStamp'   => time(),
        ] + $keys);
        if ($res['Status'] !== 'SUCCESS') {
            throw new RuntimeException("ezPay invoice_search failed: {$res['Status']} {$res['Message']}");
        }
        $result = json_decode($res['Result'], true);
        if (!is_array($result) || !$this->crypto->verifyCheckCode($result)) {
            throw new RuntimeException('ezPay CheckCode mismatch');
        }
        return $result; // 含 UploadStatus / InvoiceStatus / ItemDetail（JSON 字串，需再 decode）
    }
}
```

> `InvalidReason` 上限是「中文 6 字或英文 20 字」，上面的 `mb_substr(…, 0, 6)`
> 是保守截法；純英文原因可放寬到 20 字。

## 4. 使用範例

```php
$crypto = new EzpayCrypto(getenv('EZPAY_HASH_KEY'), getenv('EZPAY_HASH_IV'));
$client = new EzpayInvoiceClient(getenv('EZPAY_MERCHANT_ID'), $crypto, production: false);

// 兩件商品 + 運費，整筆折 50 元，顧客是個人、存手機條碼
$gross = EzpayAmounts::allocateDiscount([300, 200], 50);           // → [270, 180]
$amounts = EzpayAmounts::build([
    ['name' => '商品一', 'qty' => 3, 'gross' => $gross[0], 'unit' => '個'],
    ['name' => '商品二', 'qty' => 1, 'gross' => $gross[1], 'unit' => '個'],
    ['name' => '運費',   'qty' => 1, 'gross' => 60,        'unit' => '式'],
], 'B2C');
// → TotalAmt=510、Amt=486、TaxAmt=24
//   ItemCount=3|1|1、ItemPrice=90|180|60、ItemAmt=270|180|60

$postData = $client->buildIssuePostData('ORD1001_1', [
    'category' => 'B2C', 'name' => '王小明', 'email' => 'buyer@example.com',
    'carrierType' => '0', 'carrierNum' => '/ABC1234',
], $amounts);
// ← 在這裡把 $postData、'ORD1001_1'、510 存進資料庫（狀態：開立中）

try {
    $result = $client->sendIssue($postData, 'ORD1001_1', $amounts['TotalAmt']);
    // 存 $result['InvoiceNumber'] / ['RandomNum'] / ['InvoiceTransNo']，狀態改「已開立」
} catch (EzpayTransientException $e) {
    // 保持「開立中」，排程稍後用同一份 $postData 再呼叫 sendIssue
}
```

## 5. 測試憑證

- 本 repo 的測試憑證放在 `.tmp/[發票] ezpay/.env`（本 repo 為 public，**絕不可**進版控）。
  檔名一定要是 `.env`：`.gitignore` 會忽略 `.env` 與 `.env.*`，但**刻意不忽略 `.env.example`**，
  真實憑證寫在 `.env.example` 會被 `git add .` 帶進版控。內容：

  ```
  EZPAY_MERCHANT_ID=
  EZPAY_HASH_KEY=
  EZPAY_HASH_IV=
  ```

- 測試平台是 `cinv.ezpay.com.tw`，憑證要在測試平台另外申請；正式平台的憑證不能拿來測。
- 正式環境的外掛應把憑證存在 WordPress 設定（後台欄位），不要讀 `.env`。
