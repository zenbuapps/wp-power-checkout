# PHP / TypeScript 實作：加解密、簽名、dispatch client、推送 webhook

> 本檔程式碼依官方 SampleCode（`https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip`、`JS.zip`、`Java.zip`、`Python.zip` 與官方 Skill 包 `sf-ibu-openapi-code.zip`）逐行比對改寫，並於 2026-09-23 驗證：(1) 能解開官方 Demo 的兩組密文；(2) 與官方 `BizMsgCrypt` 互相加解密一致、signature 相同；(3) 以公開沙箱憑證對 `api-ifsp-sit.sf.global` 實際跑通唯讀接口（token → 加密 → 簽名 → dispatch → 解密）。建單範例的 payload 依官方欄位表組裝，**未在沙箱實際送出**（未做寫入型呼叫）。

**何時讀這份**：要在 PHP（WordPress plugin）或 TypeScript / Node 內實作順豐國際 API 呼叫、建單、查單、接推送時，直接複製本檔程式碼。演算法細節與原理見 `auth-and-crypto.md`。

## 目錄

- [1. 檔案配置與依賴](#1-檔案配置與依賴)
- [2. SfGlobalCrypto.php（AES + signature + 推送 sign）](#2-sfglobalcryptophpaes--signature--推送-sign)
- [3. SfGlobalClient.php（WordPress：token 快取 + dispatch）](#3-sfglobalclientphpwordpresstoken-快取--dispatch)
- [4. 使用範例：台灣地址查詢 → 建單 → 面單 → 查單 / 取消](#4-使用範例台灣地址查詢--建單--面單--查單--取消)
- [5. 路由推送 webhook 接收（WordPress REST route）](#5-路由推送-webhook-接收wordpress-rest-route)
- [6. TypeScript（Node.js crypto）版](#6-typescriptnodejs-crypto版)
- [7. 測試向量與實測輸出](#7-測試向量與實測輸出)

---

## 1. 檔案配置與依賴

- PHP ≥ 8.1（用到 readonly promoted properties / union types），需 `ext-openssl`、`ext-json`。
- 不需要官方的 `BizMsgCrypt.php` / `PrpCrypt.php` / `SHA2.php` / `PKCS7Encoder.php` / `ErrorCode.php` 五個檔案——`SfGlobalCrypto` 已涵蓋其行為並修正兩個官方樣例的問題：
  1. 官方 `BizMsgCrypt::__construct` 在 AES key 長度錯誤時 `return ErrorCode`（建構子 return 無效），這裡改成丟例外。
  2. 官方 `decryptMsg()` 回傳 `array(0, $json)`，官方 SampleCode.php 卻直接 `echo $deResult`（會印出 `Array`）；這裡直接回傳字串。
- 憑證放 `wp_options`（加密儲存或 `wp-config.php` 常數），**不要進 git**。沙箱可用官方公開帳號（`onboarding-and-faq.md` 測試環境授權信息）。

---

## 2. SfGlobalCrypto.php（AES + signature + 推送 sign）

```php
<?php
declare(strict_types=1);

/**
 * SF Global Open API (api-ifsp.sf.global) crypto helper.
 * Port of the official BizMsgCrypt sample (Php.zip) with the quirks made explicit.
 */
final class SfGlobalCrypto
{
    private const BLOCK = 32; // official PKCS7Encoder uses 32-byte blocks (not 16)

    private string $key;   // 32 raw bytes
    private string $iv;    // first 16 bytes of key

    public function __construct(private string $appKey, string $encodingAesKey)
    {
        if (strlen($encodingAesKey) !== 43) {
            throw new InvalidArgumentException('encodingAesKey must be 43 chars (IllegalAesKey -40004)');
        }
        $key = base64_decode($encodingAesKey . '=', true);
        if ($key === false || strlen($key) !== 32) {
            throw new InvalidArgumentException('encodingAesKey is not valid base64 (32 bytes after decode)');
        }
        $this->key = $key;
        $this->iv  = substr($key, 0, 16);
    }

    /** plaintext JSON -> base64 ciphertext (the raw HTTP body) */
    public function encrypt(string $plainJson): string
    {
        $random = '';
        $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < 16; $i++) {
            $random .= $pool[random_int(0, strlen($pool) - 1)];
        }
        // random(16) + len(4, big-endian) + json + appKey
        $data = $random . pack('N', strlen($plainJson)) . $plainJson . $this->appKey;
        $pad  = self::BLOCK - (strlen($data) % self::BLOCK); // 1..32
        $data .= str_repeat(chr($pad), $pad);

        $cipher = openssl_encrypt($data, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        if ($cipher === false) {
            throw new RuntimeException('AES encrypt failed (-40006)');
        }
        return base64_encode($cipher);
    }

    /** base64 ciphertext (apiResultData) -> plaintext JSON */
    public function decrypt(string $cipherB64): string
    {
        $raw = base64_decode($cipherB64, true);
        if ($raw === false) {
            throw new RuntimeException('base64 decode failed (-40010)');
        }
        $plain = openssl_decrypt($raw, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        if ($plain === false) {
            throw new RuntimeException('AES decrypt failed (-40007)');
        }
        $pad = ord(substr($plain, -1));
        if ($pad < 1 || $pad > self::BLOCK) {
            $pad = 0;
        }
        $plain = substr($plain, 0, strlen($plain) - $pad);
        $len = unpack('N', substr($plain, 16, 4))[1];
        $json = substr($plain, 20, $len);
        $fromAppKey = substr($plain, 20 + $len);
        if ($fromAppKey !== $this->appKey) {
            throw new RuntimeException('appKey mismatch after decrypt (-40005)');
        }
        return $json;
    }

    /** signature = sha256( sort_string([token, timestamp, nonce, encrypt]) joined ) lowercase hex */
    public static function signature(string $token, string $timestamp, string $nonce, string $encrypt): string
    {
        $arr = [$token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        return hash('sha256', implode('', $arr));
    }

    /** Tracking push (IUOP/IECS 运单轨迹推送) header "sign" = Base64(MD5(key + rawBody + key)) */
    public static function verifyPushSign(string $rawBody, string $pushKey, string $signHeader): bool
    {
        $expected = base64_encode(md5($pushKey . $rawBody . $pushKey, true));
        return hash_equals($expected, $signHeader);
    }
}
```

---

## 3. SfGlobalClient.php（WordPress：token 快取 + dispatch）

```php
<?php
declare(strict_types=1);

/**
 * SF Global Open API client for WordPress (uses WP HTTP API + transients).
 * Requires SfGlobalCrypto.
 */
final class SfApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $layer,          // 'http' | 'platform' | 'business'
        public readonly string|int|null $apiCode = null,
        public readonly ?array $payload = null,
    ) {
        parent::__construct($message);
    }
}

final class SfGlobalClient
{
    public const SANDBOX = 'https://api-ifsp-sit.sf.global';
    public const PRODUCTION = 'https://api-ifsp.sf.global';

    private SfGlobalCrypto $crypto;

    public function __construct(
        private string $baseUrl,
        private string $appKey,
        private string $appSecret,
        string $encodingAesKey,
        private string $lang = 'zh-HK',          // en | zh-CN | zh-HK (no zh-TW)
        private ?string $authCode = null,        // ISV (third-party ERP) apps only
    ) {
        $this->crypto = new SfGlobalCrypto($appKey, $encodingAesKey);
    }

    /** 2h token, shared across processes; refresh 10 min early. A new token invalidates the old one. */
    public function token(bool $forceRefresh = false): string
    {
        $cacheKey = 'sf_global_token_' . md5($this->baseUrl . $this->appKey);
        if (!$forceRefresh) {
            $cached = get_transient($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $url = $this->baseUrl . '/openapi/api/token?' . http_build_query([
            'appKey' => $this->appKey,
            'appSecret' => $this->appSecret,
        ]);
        $res = wp_remote_get($url, ['timeout' => 30, 'headers' => ['lang' => $this->lang]]);
        if (is_wp_error($res)) {
            throw new SfApiException($res->get_error_message(), 'http');
        }
        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($json) || ($json['apiResultCode'] ?? null) !== 0) {
            throw new SfApiException((string) ($json['apiErrorMsg'] ?? 'token request failed'), 'platform', $json['apiResultCode'] ?? null, $json);
        }
        $token = (string) $json['apiResultData']['accessToken'];
        $ttl = max(60, (int) ($json['apiResultData']['expireIn'] ?? 7200) - 600);
        set_transient($cacheKey, $token, $ttl);
        return $token;
    }

    /**
     * Encrypt + sign + POST /openapi/api/dispatch, then decrypt apiResultData.
     * Returns the decoded business array: ['success' => bool, 'code' => ..., 'msg' => ..., 'data' => ...].
     */
    public function dispatch(string $msgType, array|object $body, bool $retried = false): array
    {
        $token = $this->token();
        $plain = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) (int) round(microtime(true) * 1000); // milliseconds
        $nonce = wp_generate_uuid4();
        $encrypt = $this->crypto->encrypt($plain);

        $headers = [
            'appKey' => $this->appKey,
            'token' => $token,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => SfGlobalCrypto::signature($token, $timestamp, $nonce, $encrypt),
            'msgType' => $msgType,
            'lang' => $this->lang,
            'Content-Type' => 'application/json', // anything else => "system error"
        ];
        if ($this->authCode !== null) {
            $headers['authCode'] = $this->authCode;
        }

        $res = wp_remote_post($this->baseUrl . '/openapi/api/dispatch', [
            'timeout' => 60,
            'headers' => $headers,
            'body' => $encrypt, // raw ciphertext string, no key-value, no trailing newline
        ]);
        if (is_wp_error($res)) {
            throw new SfApiException($res->get_error_message(), 'http');
        }
        $status = wp_remote_retrieve_response_code($res);
        $outer = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($status !== 200 || !is_array($outer)) {
            throw new SfApiException("HTTP $status", 'http', $status);
        }

        $code = $outer['apiResultCode'] ?? null;
        if ($code !== 0) {
            $msg = (string) ($outer['apiErrorMsg'] ?? '');
            // 500 "Access denied, token invalid": token was replaced elsewhere -> refresh once
            if (!$retried && (int) $code === 500 && stripos($msg, 'token') !== false) {
                $this->token(true);
                return $this->dispatch($msgType, $body, true);
            }
            throw new SfApiException($msg !== '' ? $msg : 'platform error', 'platform', $code, $outer);
        }

        $data = $outer['apiResultData'] ?? null;
        $biz = is_string($data) ? json_decode($this->crypto->decrypt($data), true) : $data;
        if (!is_array($biz)) {
            throw new SfApiException('cannot decode apiResultData', 'platform', $code, $outer);
        }
        if (($biz['success'] ?? false) !== true) {
            throw new SfApiException((string) ($biz['msg'] ?? 'business error'), 'business', $biz['code'] ?? null, $biz);
        }
        return $biz;
    }
}
```

設計重點：
- token 放 transient，TTL = `expireIn - 600` 秒；**同一組 appKey 只能有一個有效 token**（取新的會讓舊的失效），所以多站台 / 多程序要共享同一份快取（object cache / Redis），否則會互相踢掉。
- 平台層 `500` + 訊息含 token（`Access denied, token invalid`）→ 強制刷新 token 後**只重試一次**。
- 例外分三層：`http`（網路 / 非 200）、`platform`（`apiResultCode ≠ 0`，例 `504` 系統繁忙可退避重試）、`business`（解密後 `success=false`，`apiCode` 為業務碼，對照 `error-codes.md`）。
- `lang` 預設 `zh-HK`（繁體訊息）；可用值只有 `en` / `zh-CN` / `zh-HK`。
- body 用 `JSON_UNESCAPED_UNICODE`，長度欄位用 byte 長度（`SfGlobalCrypto` 內 `strlen`），中文不會壞。
- 空 body 的接口（`IPE_QUERY_PRODUCT_LIST`、`ICMS_GET_CONTINENT_LIST`）要傳 `new stdClass()`（序列化成 `{}`），不能傳 `[]`（會變成 `[]`）。

---

## 4. 使用範例：台灣地址查詢 → 建單 → 面單 → 查單 / 取消

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/SfGlobalCrypto.php';
require_once __DIR__ . '/SfGlobalClient.php';

$sf = new SfGlobalClient(
    SfGlobalClient::SANDBOX,                    // 正式：SfGlobalClient::PRODUCTION
    get_option('sf_app_key'),
    get_option('sf_app_secret'),
    get_option('sf_aes_key'),                   // 43 chars
    'zh-HK'
);
$customerCode = get_option('sf_customer_code'); // IUOP 客戶編號，例 ICRME000SRN93（沙箱）
$monthCard    = get_option('sf_month_card');    // 月結卡號（沙箱 9999999999）

// 1) 台灣郵編 → SF 標準區劃（regionFirst=縣市英文、regionSecond=區英文）
$region = $sf->dispatch('ICMS_QUERY_REGION_BY_POST_CODE', [
    'countryCode' => 'TW',
    'postCode'    => '10048',      // 必須 5 或 6 碼；3 碼會被拒
    'queryMode'   => '0',
])['data'][0];
// 實測：['regionFirst' => 'Taipei City', 'regionSecond' => 'Zhongzheng District', 'isAccessible' => '1', 'isRemote' => '0', ...]

// 2) 建單：台灣（公司件）寄大陸個人（IUOP，國際標快-包裹；產品以簽約為準）
$order = [
    'customerCode'     => $customerCode,
    'orderOperateType' => 1,                       // 1 新增 / 5 改單（僅順豐收件前）
    'customerOrderNo'  => 'WC-100234',             // 不可與歷史重複（123052）
    'erpCode'          => '0000',                  // 自建系統固定 0000
    'erpName'          => '0000',
    'interProductCode' => 'INT0008',               // 收件國 CN → 國際標快（附錄 2）
    'parcelQuantity'   => 1,
    'parcelTotalWeight'=> 1.2,
    'parcelWeightUnit' => 'KG',
    'parcelVolumeUnit' => 'CM',
    'declaredCurrency' => 'NTD',                   // SF 幣種表：新台幣 = NTD
    'pickupType'       => '0',                     // 0 自寄或自行聯繫快遞員 / 1 上門收件（需 pickupAppointTime）
    'labelModeSize'    => 1,                       // 1 = 100*150 面單
    'paymentInfo' => [
        'payMethod'    => '1',                     // 寄方付
        'payMonthCard' => $monthCard,
        'taxPayMethod' => '2',                     // 稅金收方付（其他方式需另簽協議）
    ],
    'senderInfo' => [
        'company'       => 'Example Co., Ltd.',
        'contact'       => 'Wang Da Ming',
        'country'       => 'TW',
        'postCode'      => '10048',
        'regionFirst'   => $region['regionFirst'],
        'regionSecond'  => $region['regionSecond'],
        'address'       => 'No. 1, Sec. 1, Zhongshan S. Rd.',
        'phoneAreaCode' => '886',
        'phoneNo'       => '0912345678',
        'cargoType'     => 2,                      // TW 始發必填：1 個人件 / 2 公司件
        'certType'      => '009',                  // 統編號（適用台灣；124080 寄件方統編號必填）
        'certCardNo'    => '12345678',
    ],
    'receiverInfo' => [
        'contact'       => '李想',                  // 證件為身份證時姓名須中文（124219）
        'country'       => 'CN',
        'postCode'      => '518000',
        'regionFirst'   => 'Guangdong',
        'regionSecond'  => 'Shenzhen',
        'regionThird'   => 'Futian',
        'address'       => '福田区深南大道 1 号',
        'phoneAreaCode' => '86',
        'phoneNo'       => '13800000000',
        'cargoType'     => 1,
        'certType'      => '001',                  // 個人物品需證件（123102）
        'certCardNo'    => '<身份證號>',
    ],
    'customsInfo' => [
        'senderReason'      => 6,                  // 6 銷售
        'exportCustomsType' => '5',                // 5 簡易-銷售（台灣出口用）
        'tradeCondition'    => 'DAP',
    ],
    'parcelInfoList' => [[
        'name'          => '茶葉',
        'eName'         => 'Oolong tea',
        'unit'          => '盒',
        'amount'        => 800,                    // 單件申報價值（申報總值 = Σ amount*quantity）
        'quantity'      => 2,
        'originCountry' => 'TW',
        'hsCode'        => '0902300000',
    ]],
];

try {
    $res = $sf->dispatch('IUOP_CREATE_ORDER', $order);
    $waybill  = $res['data']['sfWaybillNo'];        // 例 SF1334452877497
    $labelUrl = $res['data']['labelUrl'];           // 面單 PDF；通常不必再呼叫 IUOP_PRINT_ORDER
    $invoiceUrl = $res['data']['invoiceUrl'] ?? null;
    $idUpload   = $res['data']['identityUploadUrl'] ?? null; // 清關證件上傳頁
} catch (SfApiException $e) {
    // $e->layer: http | platform | business ; $e->apiCode 例 '123052' 客戶訂單號重複
    // 123052：可能是上一次其實已成功但沒收到回應 → 用 orderOperateType=5 同單號重送，成功會回原單資料
    error_log("SF create failed [{$e->layer}] {$e->apiCode}: {$e->getMessage()}");
    throw $e;
}

// 3) 補印 / 換版型：IUOP_PRINT_ORDER（printType 1 運單 / 2 發票；fileFormat PDF 或 ZPL）
$print = $sf->dispatch('IUOP_PRINT_ORDER', [
    'customerCode' => $customerCode,
    'requestId'    => wp_generate_uuid4(),
    'printType'    => '1',
    'labelModeSize'=> 1,
    'fileFormat'   => 'PDF',
    'resolution'   => '203dpi',                  // 僅 ZPL 時有效；文件標必填；值格式官方未示範 [待確認]
    'printWaybillNoDtoList' => [['sfWaybillNo' => $waybill, 'isPrintSubParent' => 1]],
]);
$pdfUrl = $print['data']['url'];

// 4) 查單 / 取消（取消僅順豐收件前）
$info = $sf->dispatch('IUOP_QUERY_ORDER', ['customerCode' => $customerCode, 'sfWaybillNo' => $waybill]);
$sf->dispatch('IUOP_CANCEL_ORDER', ['customerCode' => $customerCode, 'sfWaybillNo' => $waybill]);

// 5) 運費試算（基本運費，不含附加費）
$fee = $sf->dispatch('IUOP_QUERY_FREIGHT', [
    'customerCode'     => $customerCode,
    'senderCountry'    => 'TW',
    'receiverCountry'  => 'CN',
    'senderPostCode'   => '10048',
    'receiverPostCode' => '518000',
    'interProductCode' => 'INT0008',
    'payMonthCard'     => $monthCard,              // 有白名單限制時不填查不到
    'weight'           => '1.2',
    'currency'         => 'NTD',
]);
// 實測回應 data：{"discountedRate":"","totalFreight":"10","weight":"1","currency":"NTD"}（沙箱為假資料）

// 6) 路由查詢（補充用；主要靠推送）
$track = $sf->dispatch('GTS_QUERY_TRACK', [
    'sfWaybillNoList' => [$waybill],               // 最多 10 筆
    'phoneNo'         => '5678',                   // 下單手機號後四碼
]);
```

---

## 5. 路由推送 webhook 接收（WordPress REST route）

推送規格（`tracking.md` 運單軌跡推送節）：POST、`Content-Type: application/json`、body 為 JSON array（一次 ≤ 20 筆、可混多張運單、**無序**）、header `sign` = `Base64(MD5(key + body + key))`、需開放順豐推送 IP 白名單。回應格式：全部失敗 `{"code":"1","msg":"原因","data":null}`；部分或全部成功 `{"code":"0","msg":"成功","data":[{"code":"0|1","msg":"...","trackUniqueId":"...","sfWaybillNo":"..."}]}`。

```php
<?php
declare(strict_types=1);

add_action('rest_api_init', static function (): void {
    register_rest_route('sf-express/v1', '/track-push', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',   // 以 sign + IP 白名單驗證，非 WP 使用者
        'callback'            => 'sf_express_handle_track_push',
    ]);
});

function sf_express_handle_track_push(WP_REST_Request $request): WP_REST_Response
{
    $raw  = $request->get_body();                      // 必須用原始 body 計算 sign，不可 re-encode
    $sign = (string) $request->get_header('sign');
    $key  = (string) get_option('sf_push_key');        // 聯調時由順豐提供

    if ($key !== '' && !SfGlobalCrypto::verifyPushSign($raw, $key, $sign)) {
        return new WP_REST_Response(['code' => '1', 'msg' => '接收失败，sign不对', 'data' => null], 200);
    }

    $events = json_decode($raw, true);
    if (!is_array($events)) {
        return new WP_REST_Response(['code' => '1', 'msg' => 'invalid body', 'data' => null], 200);
    }

    $result = [];
    foreach ($events as $ev) {
        $uid = (string) ($ev['trackUniqueId'] ?? '');
        try {
            // 冪等：以 trackUniqueId 去重；事件無序，請以 localTm + gmt 排序後再推導最新狀態
            sf_express_store_track_event([
                'track_unique_id' => $uid,
                'waybill'         => (string) ($ev['sfWaybillNo'] ?? ''),
                'op_code'         => (string) ($ev['opCode'] ?? ''),        // 對照 tracking.md 附錄 14
                'op_text'         => (string) ($ev['opCodeTranslation'] ?? ''),
                'reason_code'     => (string) ($ev['reasonCode'] ?? ''),
                'remark'          => (string) ($ev['trackRemark'] ?? ''),
                'country'         => (string) ($ev['trackCountry'] ?? ''),
                'local_time'      => (string) ($ev['localTm'] ?? ''),        // yyyy-MM-dd HH:mm:ss（當地時間）
                'gmt'             => (string) ($ev['gmt'] ?? ''),            // GMT+08:00
                'other_info'      => (string) ($ev['otherInfo'] ?? ''),      // opCode=99 時：1 轉寄 2 返回 3 部分攔截 45/46
            ]);
            $result[] = ['code' => '0', 'msg' => '成功', 'trackUniqueId' => $uid, 'sfWaybillNo' => $ev['sfWaybillNo'] ?? ''];
        } catch (Throwable $e) {
            $result[] = ['code' => '1', 'msg' => $e->getMessage(), 'trackUniqueId' => $uid, 'sfWaybillNo' => $ev['sfWaybillNo'] ?? ''];
        }
    }
    return new WP_REST_Response(['code' => '0', 'msg' => '成功', 'data' => $result], 200);
}
```

`sf_express_store_track_event()` 為自家持久化函式（自行實作：以 `track_unique_id` unique key upsert）。

---

## 6. TypeScript（Node.js crypto）版

```ts
import { createCipheriv, createDecipheriv, createHash, randomInt } from 'node:crypto';

const BLOCK = 32; // official PKCS7Encoder block size

export class SfGlobalCrypto {
  private readonly appKey: string;
  private readonly key: Buffer;
  private readonly iv: Buffer;

  constructor(appKey: string, encodingAesKey: string) {
    this.appKey = appKey;
    if (encodingAesKey.length !== 43) throw new Error('encodingAesKey must be 43 chars (-40004)');
    this.key = Buffer.from(encodingAesKey + '=', 'base64');
    if (this.key.length !== 32) throw new Error('encodingAesKey must decode to 32 bytes');
    this.iv = this.key.subarray(0, 16);
  }

  encrypt(plainJson: string): string {
    const pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let random = '';
    for (let i = 0; i < 16; i++) random += pool[randomInt(pool.length)];
    const body = Buffer.from(plainJson, 'utf8');
    const len = Buffer.alloc(4);
    len.writeUInt32BE(body.length, 0); // UTF-8 byte length, big-endian
    const data = Buffer.concat([Buffer.from(random, 'utf8'), len, body, Buffer.from(this.appKey, 'utf8')]);
    const pad = BLOCK - (data.length % BLOCK); // 1..32
    const padded = Buffer.concat([data, Buffer.alloc(pad, pad)]);
    const cipher = createCipheriv('aes-256-cbc', this.key, this.iv);
    cipher.setAutoPadding(false);
    return Buffer.concat([cipher.update(padded), cipher.final()]).toString('base64');
  }

  decrypt(cipherB64: string): string {
    const decipher = createDecipheriv('aes-256-cbc', this.key, this.iv);
    decipher.setAutoPadding(false);
    let plain = Buffer.concat([decipher.update(Buffer.from(cipherB64, 'base64')), decipher.final()]);
    let pad = plain[plain.length - 1];
    if (pad < 1 || pad > BLOCK) pad = 0;
    plain = plain.subarray(0, plain.length - pad);
    const len = plain.readUInt32BE(16);
    const json = plain.subarray(20, 20 + len).toString('utf8');
    const fromAppKey = plain.subarray(20 + len).toString('utf8');
    if (fromAppKey !== this.appKey) throw new Error('appKey mismatch after decrypt (-40005)');
    return json;
  }

  static signature(token: string, timestamp: string, nonce: string, encrypt: string): string {
    const joined = [token, timestamp, nonce, encrypt].sort().join('');
    return createHash('sha256').update(joined, 'utf8').digest('hex');
  }

  /** tracking push header `sign` = Base64(MD5(key + rawBody + key)) */
  static pushSign(rawBody: string, pushKey: string): string {
    return createHash('md5').update(pushKey + rawBody + pushKey, 'utf8').digest('base64');
  }
}
```

dispatch（Node 18+ 內建 fetch）：

```ts
import { randomUUID } from 'node:crypto';
import { SfGlobalCrypto } from './sfGlobalCrypto';

export async function sfDispatch<T = unknown>(opts: {
  baseUrl: string; appKey: string; aesKey: string; token: string;
  msgType: string; body: object; lang?: 'en' | 'zh-CN' | 'zh-HK';
}): Promise<{ success: boolean; code?: string | number; msg?: string; data?: T }> {
  const crypto = new SfGlobalCrypto(opts.appKey, opts.aesKey);
  const encrypt = crypto.encrypt(JSON.stringify(opts.body));
  const timestamp = String(Date.now());
  const nonce = randomUUID();
  const res = await fetch(`${opts.baseUrl}/openapi/api/dispatch`, {
    method: 'POST',
    headers: {
      appKey: opts.appKey,
      token: opts.token,
      timestamp,
      nonce,
      signature: SfGlobalCrypto.signature(opts.token, timestamp, nonce, encrypt),
      msgType: opts.msgType,
      lang: opts.lang ?? 'zh-HK',
      'Content-Type': 'application/json',
    },
    body: encrypt,
  });
  const outer = (await res.json()) as { apiResultCode: number; apiErrorMsg?: string; apiResultData?: string };
  if (outer.apiResultCode !== 0) throw new Error(`SF platform ${outer.apiResultCode}: ${outer.apiErrorMsg}`);
  return JSON.parse(crypto.decrypt(outer.apiResultData as string));
}
```

---

## 7. 測試向量與實測輸出

官方 Php.zip `Demo.php` / Python `Sample.py` 參數：

| 參數 | 值 |
|---|---|
| encodingAesKey | `dcLutUPXvxDjzVDgLV0SsAI5UAxmEloNHEZVtgxnvuA` |
| appKey | `76a5c52b9027505f4fcc4c358fb58211` |
| token | `auth_d830f571-570a-44e7-a51a-c49b1ec6d79f_1637927849980` |
| timestamp / nonce | `1637927849983` / `1637927849983` |
| 密文 A（Demo.php） | `8J2XeGGpK5oEcIytFfln91CYH4VfeXpV73X7Q+O9lJFTlMr0eV4YH+6r0HpIY8pSJIPqeVqEcjFR+ZS92b9M7jnijYEh6LMkCg85XP5tDBMbbk+eWrU8++mzaa7jKI7W` → `sdfsdafafasdfadfad发生的发生地方` |
| 密文 B（Sample.py） | `T/TnaXcxM1qEBijGMtWbUPzVqEgke4CjKXGA63JMD9JGw4U1vlw5MiFQEWDzsjHHQqPkokq6nOxUZFgg3gtDMA==` → `1231231` |
| 推送 sign 範例 | `key="k"`、`body='[{"a":1}]'` → `H58CQELiXLV+IZi9a1oHxg==` |

加密含 16 bytes 隨機前綴，同一明文每次密文不同——**不要用「重算密文比對」來測試**，要用「解密比對明文」與「對給定密文算 signature」。

2026-09-23 沙箱實測（`SfGlobalClient` + 公開沙箱憑證，`lang: zh-HK`）：

```
ICMS_QUERY_REGION_BY_POST_CODE {"countryCode":"TW","postCode":"10048","queryMode":"0"}
=> {"data":[{"isAccessible":"1","billingAreaCode":"886","regionFourth":"","regionFirstAbbr":"台北市","regionFirst":"Taipei City","cityCode":"886","countryCode":"TW","isRemote":"0","postCode":"10048","portCode":"886","regionSecond":"Zhongzheng District","regionThird":""}],"success":true}

ICMS_QUERY_REGION_BY_POST_CODE {"countryCode":"TW","postCode":"100"}
=> SfApiException[business] 邮编示例格式12345

IUOP_QUERY_ORDER {"customerCode":"ICRME000SRN93","sfWaybillNo":"SF1334456901862"}
=> SfApiException[business] 123002 訂單不存在或訂單已歸檔
```
