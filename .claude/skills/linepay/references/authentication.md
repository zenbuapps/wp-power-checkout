# LINE Pay Online API — 認證、簽章與可執行 Client

> 來源：https://developers-pay.line.me/zh/online/prerequisites 、https://developers-pay.line.me/zh/online-api-v4#request-header 、
> https://developers-pay.line.me/zh/online-api-v4#transaction-id 、https://developers-pay.line.me/zh/faq#failed-to-use-online-api-with-1106-return-code
> 下方三語 client 為本 skill 依官方演算法撰寫。2026-11-18 驗證：(1) 以假憑證實打 sandbox / production 皆回 `1104 Merchant not found.`（header 格式通過）；(2) 對本機「依官方公式驗簽」的模擬伺服器打 request / details（重複 query key）/ void（無 body）/ refund / checkRegKey，三語簽章全部吻合。**2026-11-19 sandbox 實測（真憑證）**：Python / Node / PHP client 的付款請求皆回 `0000`，GET 帶 query（查明細）與無 body POST 皆通過驗簽。紀錄：`.tmp/[金流] LINE Pay/sandbox-實測紀錄-2026-11-19.md`。
> 三語 client 皆讀 `.env.local` 的 `LINEPAY_API_BASE_URL`、`LINEPAY_API_VERSION`（未設定 fallback `https://sandbox-api-pay.line.me` + `v4`）。

## 目錄

1. 共通請求標頭
2. 簽章演算法（HMAC-SHA256）
3. 交叉驗證向量
4. 常見 1106 失敗原因
5. 交易 ID 的 BigInt 處理
6. Node.js client（零依賴）
7. PHP client（curl）
8. Python client（stdlib）
9. 官方範例程式的已知瑕疵

## 1. 共通請求標頭（v3 / v4 相同）

| Header | 必填 | 值 |
|---|---|---|
| `Content-Type` | Y | `application/json` |
| `X-LINE-ChannelId` | Y | Channel ID（合作商店中心「管理連結金鑰」取得） |
| `X-LINE-Authorization-Nonce` | Y | 每次請求新產生：UUID v1 / v4，或時間戳記 |
| `X-LINE-Authorization` | Y | Base64( HMAC-SHA256( key = ChannelSecret, message ) ) |
| `X-LINE-MerchantDeviceProfileId` | N | 裝置序號，用於報表分裝置統計；須與 `X-LINE-MerchantDeviceType` 一起帶 |
| `X-LINE-MerchantDeviceType` | N | 合作商店自訂的裝置類型；須與上者一起帶 |

> **不存在** `X-LINE-ChannelSecret` header 於 v3/v4 Online API——那是 Offline API v2 / v2.4 的做法（已核對 https://developers-pay.line.me/zh/offline-api-v2 ）。v3 起 secret 只用來簽章，**永遠不要放進 header 或送出去**。

## 2. 簽章演算法

```
nonce   = UUID v4（或 v1 / timestamp），每次請求唯一
message = GET  → ChannelSecret + apiPath + queryString + nonce
          POST → ChannelSecret + apiPath + requestBody   + nonce
X-LINE-Authorization = Base64( HMAC_SHA256(key = ChannelSecret, data = message) )
```

- `apiPath`：含版本的路徑，例如 `/v4/payments/request`、`/v4/payments/2018082512345678910/confirm`；**不含 host、不含 `?`**。
- `queryString`：`?` 之後的字串（不含 `?`），例如 `orderId=A&orderId=B`；無 query 時為空字串。簽章用的字串必須與實際 URL 上的完全一致（含編碼）。
- `requestBody`：**實際送出的 JSON 原文**。先 `JSON.stringify` / `json_encode` 一次、拿同一個字串簽章並當 body 送出；不要簽完再重新序列化（空白、key 順序、`\/` 跳脫都會讓 MAC 改變——官方 FAQ 明載）。
- 無 body 的 POST（void、expire、全額 refund）：**簽空字串、不送 body**（2026-11-19 sandbox 實測：void / refund / expire 簽空字串皆通過驗簽；照官方 prerequisites 範例簽 `"null"` 三支全部 `1106 request verification Failed`；送 `{}` 並簽 `{}` 也可；送 `{}` 卻簽空字串則 `1106`——伺服器以「實際收到的 body」驗簽）。
- 輸出是 **Base64**（不是 hex）。

## 3. 交叉驗證向量（本 skill 自算，非官方；Node / PHP / Python 三者一致）

```
ChannelSecret = a917ab6a2367b536f8e5a6e2977e06f4
nonce         = c6b6a6a2-0c62-4b5a-9a43-4f4b8b1b6f3e

POST /v4/payments/request
body  = {"amount":100,"currency":"TWD","orderId":"ORDER_1"}
→ X-LINE-Authorization = cv91+KhazyLoyDqa5g0EAFvmjwMj27l14nd+xcCJzUA=

GET /v4/payments?orderId=ORDER_1
→ X-LINE-Authorization = FDyiT1trsOcYZoeO5gPEChdAaC5/7dFuYDyuo9S66Bk=
```

## 4. 1106「Header information error」常見原因

實測 header 檢查順序：`X-LINE-ChannelId` → `X-LINE-Authorization` → `X-LINE-Authorization-Nonce` → 商店存在性（`1104`）→ 簽章。

| `returnMessage` 片段 | 原因 |
|---|---|
| `channelId is required header.` | 缺 `X-LINE-ChannelId` |
| `authorization is required header.` | 缺 `X-LINE-Authorization` |
| `authorization-nonce is required header.` | 缺 `X-LINE-Authorization-Nonce`（注意官方「預先授權付款請求」curl 範例誤寫成 `X-LINE-Authorize-Nonce`） |
| `request verification Failed`（2026-11-19 實測訊息） | MAC 不符：簽的 body 與送出 body 不同、GET 誤用 body、query 字串多了 `?`、secret 用錯環境、Base64 誤用 hex |

## 5. 交易 ID 的 BigInt 處理

`transactionId` / `refundTransactionId` / `originalTransactionId` 是 **19 位整數（64-bit long）**，在 JSON 中是 number。
JavaScript `Number` 只有 53 bit，`2023010112345678910` 會變 `2023010112345678800`。官方作法：拿 response 純文字，`JSON.parse` 前把 16 位以上連續數字轉成字串：

```js
function handleBigInteger(text) {
  const largeNumberRegex = /:\s*(\d{16,})\b/g;
  const processedText = text.replace(largeNumberRegex, ': "$1"');
  return JSON.parse(processedText);
}
```

- DB 欄位用 `BIGINT`（unsigned 不需要）或 `VARCHAR(19+)`；建議存字串。
- 呼叫 confirm / capture / void / refund 時，path 上的 transactionId 直接放字串即可。
- PHP 64-bit 的 `json_decode` 會把 19 位 ID 解成 `int`（不失真；`JSON_BIGINT_AS_STRING` 只對超過 `PHP_INT_MAX` 的數字生效），存檔 / 組 URL 前用 `(string)` 轉字串；32-bit PHP 必須靠 `JSON_BIGINT_AS_STRING`。Python `int` 無精度問題。

## 6. Node.js client（Node >= 18，零依賴，ESM）

```js
// LINE Pay Online API v4 client (Node.js >= 18, no dependencies)
import crypto from "node:crypto";

const HOSTS = {
  sandbox: "https://sandbox-api-pay.line.me",
  production: "https://api-pay.line.me",
};

/** 16+ 位連續數字轉字串，避免 transactionId 精度遺失（官方 handleBigInteger） */
export function parseLinePayJson(text) {
  return JSON.parse(text.replace(/:\s*(\d{16,})\b/g, ': "$1"'));
}

export function sign(channelSecret, message) {
  return crypto.createHmac("sha256", channelSecret).update(message).digest("base64");
}

/**
 * baseUrl / apiVersion 預設讀 .env.local 的 LINEPAY_API_BASE_URL / LINEPAY_API_VERSION，
 * 未設定時 fallback 到 sandbox + v4。
 */
export function createLinePayClient({
  channelId = process.env.LINEPAY_CHANNEL_ID,
  channelSecret = process.env.LINEPAY_CHANNEL_SECRET,
  baseUrl = process.env.LINEPAY_API_BASE_URL || HOSTS.sandbox,
  apiVersion = process.env.LINEPAY_API_VERSION || "v4",
  timeoutMs = 20000,
} = {}) {
  if (!channelId || !channelSecret) throw new Error("LINEPAY_CHANNEL_ID / LINEPAY_CHANNEL_SECRET is required");
  baseUrl = baseUrl.replace(/\/+$/, "");
  const v = `/${apiVersion}`;

  async function call(method, apiPath, { query, body, readTimeoutMs = timeoutMs } = {}) {
    const nonce = crypto.randomUUID();
    let queryString = "";
    if (query) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) {
        for (const item of [].concat(v)) qs.append(k, String(item)); // 重複 key：orderId=a&orderId=b
      }
      queryString = qs.toString();
    }
    // 簽章用的 body 字串必須與實際送出的 bytes 完全一致
    const bodyText = method === "POST" && body != null ? JSON.stringify(body) : "";
    const message =
      method === "GET"
        ? channelSecret + apiPath + queryString + nonce
        : channelSecret + apiPath + bodyText + nonce;

    const res = await fetch(`${baseUrl}${apiPath}${queryString ? "?" + queryString : ""}`, {
      method,
      headers: {
        "Content-Type": "application/json",
        "X-LINE-ChannelId": String(channelId),
        "X-LINE-Authorization-Nonce": nonce,
        "X-LINE-Authorization": sign(channelSecret, message),
      },
      body: method === "POST" ? bodyText : undefined,
      signal: AbortSignal.timeout(readTimeoutMs),
    });
    return parseLinePayJson(await res.text()); // HTTP 一律 200，判 returnCode
  }

  return {
    request: (payload) => call("POST", `${v}/payments/request`, { body: payload, readTimeoutMs: 10000 }),
    checkRequestStatus: (txId) => call("GET", `${v}/payments/requests/${txId}/check`),
    confirm: (txId, { amount, currency }) =>
      call("POST", `${v}/payments/${txId}/confirm`, { body: { amount, currency }, readTimeoutMs: 40000 }),
    capture: (txId, { amount, currency }) =>
      call("POST", `${v}/payments/authorizations/${txId}/capture`, { body: { amount, currency }, readTimeoutMs: 60000 }),
    void: (txId) => call("POST", `${v}/payments/authorizations/${txId}/void`),
    refund: (txId, refundAmount) =>
      call("POST", `${v}/payments/${txId}/refund`, { body: refundAmount == null ? null : { refundAmount } }),
    details: ({ transactionId, orderId } = {}) =>
      call("GET", `${v}/payments`, { query: { ...(transactionId && { transactionId }), ...(orderId && { orderId }) } }),
    checkRegKey: (regKey, creditCardAuth) =>
      call("GET", `${v}/payments/preapprovedPay/${regKey}/check`,
        { query: creditCardAuth == null ? undefined : { creditCardAuth } }),
    payPreapproved: (regKey, payload) => call("POST", `${v}/payments/preapprovedPay/${regKey}/payment`, { body: payload }),
    expireRegKey: (regKey) => call("POST", `${v}/payments/preapprovedPay/${regKey}/expire`),
  };
}
```

使用：

```js
import { createLinePayClient } from "./linepay-client.mjs";

// 讀 .env.local：LINEPAY_CHANNEL_ID / LINEPAY_CHANNEL_SECRET / LINEPAY_API_BASE_URL / LINEPAY_API_VERSION
// （未設 BASE_URL / VERSION 時 fallback sandbox + v4；切正式環境只要把 LINEPAY_API_BASE_URL 改成 https://api-pay.line.me）
const lp = createLinePayClient();

const res = await lp.request({
  amount: 100,
  currency: "TWD",
  orderId: "ORDER_20261118_0001",
  packages: [{ id: "1", amount: 100, products: [{ name: "Pen", quantity: 2, price: 50 }] }],
  redirectUrls: {
    confirmUrl: "https://shop.example.com/linepay/confirm",
    cancelUrl: "https://shop.example.com/linepay/cancel",
  },
});
if (res.returnCode !== "0000") throw new Error(`${res.returnCode} ${res.returnMessage}`);
// res.info.transactionId 已是字串；導向 res.info.paymentUrl.web（PC / sandbox）或 .app（行動裝置，正式環境）
```

## 7. PHP client（PHP >= 8.0，ext-curl）

```php
<?php
declare(strict_types=1);

/** LINE Pay Online API v4 client (PHP >= 8.0, ext-curl, ext-json) */
final class LinePayClient
{
    private const HOSTS = [
        'sandbox'    => 'https://sandbox-api-pay.line.me',
        'production' => 'https://api-pay.line.me',
    ];

    private string $baseUrl;
    private string $v;

    /**
     * $baseUrl / $apiVersion 為 null 時讀 .env.local 的 LINEPAY_API_BASE_URL / LINEPAY_API_VERSION，
     * 未設定則 fallback 到 sandbox + v4。
     */
    public function __construct(
        private string $channelId,
        private string $channelSecret,
        ?string $baseUrl = null,
        ?string $apiVersion = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('LINEPAY_API_BASE_URL') ?: self::HOSTS['sandbox']), '/');
        $this->v = '/' . ($apiVersion ?? (getenv('LINEPAY_API_VERSION') ?: 'v4'));
    }

    /** @return array<string,mixed> */
    public function call(string $method, string $apiPath, ?array $body = null, array $query = [], int $timeout = 20): array
    {
        $nonce = self::uuidV4();
        // 重複 key（orderId=a&orderId=b），不要用 http_build_query 的 orderId[0]= 形式
        $pairs = [];
        foreach ($query as $k => $v) {
            foreach ((array) $v as $item) {
                $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }
        $queryString = implode('&', $pairs);
        $bodyText = ($method === 'POST' && $body !== null)
            ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : '';
        $message = $this->channelSecret . $apiPath . ($method === 'GET' ? $queryString : $bodyText) . $nonce;
        $signature = base64_encode(hash_hmac('sha256', $message, $this->channelSecret, true));

        $ch = curl_init($this->baseUrl . $apiPath . ($queryString !== '' ? '?' . $queryString : ''));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LINE-ChannelId: ' . $this->channelId,
                'X-LINE-Authorization-Nonce: ' . $nonce,
                'X-LINE-Authorization: ' . $signature,
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyText);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('LINE Pay transport error: ' . curl_error($ch));
        }
        curl_close($ch);
        // 64-bit PHP 的 int 可容納 19 位 transactionId；仍建議轉字串保存
        return json_decode($raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    public function request(array $payload): array { return $this->call('POST', "{$this->v}/payments/request", $payload, [], 10); }
    public function confirm(string $txId, int $amount, string $currency = 'TWD'): array
    { return $this->call('POST', "{$this->v}/payments/{$txId}/confirm", ['amount' => $amount, 'currency' => $currency], [], 40); }
    public function capture(string $txId, int $amount, string $currency = 'TWD'): array
    { return $this->call('POST', "{$this->v}/payments/authorizations/{$txId}/capture", ['amount' => $amount, 'currency' => $currency], [], 60); }
    public function void(string $txId): array { return $this->call('POST', "{$this->v}/payments/authorizations/{$txId}/void"); }
    public function refund(string $txId, ?int $refundAmount = null): array
    { return $this->call('POST', "{$this->v}/payments/{$txId}/refund", $refundAmount === null ? null : ['refundAmount' => $refundAmount]); }
    public function details(array $transactionIds = [], array $orderIds = []): array
    { return $this->call('GET', "{$this->v}/payments", null, array_filter(['transactionId' => $transactionIds, 'orderId' => $orderIds])); }
    public function checkRequestStatus(string $txId): array { return $this->call('GET', "{$this->v}/payments/requests/{$txId}/check"); }
    public function checkRegKey(string $regKey, ?bool $creditCardAuth = null): array
    { return $this->call('GET', "{$this->v}/payments/preapprovedPay/{$regKey}/check", null, $creditCardAuth === null ? [] : ['creditCardAuth' => $creditCardAuth]); }
    public function payPreapproved(string $regKey, array $payload): array
    { return $this->call('POST', "{$this->v}/payments/preapprovedPay/{$regKey}/payment", $payload); }
    public function expireRegKey(string $regKey): array { return $this->call('POST', "{$this->v}/payments/preapprovedPay/{$regKey}/expire"); }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
```

## 8. Python client（Python >= 3.9，stdlib only）

```python
"""LINE Pay Online API v4 client (Python >= 3.9, stdlib only)."""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import re
import time
import urllib.parse
import urllib.request
import uuid
from typing import Any

HOSTS = {"sandbox": "https://sandbox-api-pay.line.me", "production": "https://api-pay.line.me"}


def sign(channel_secret: str, message: str) -> str:
    digest = hmac.new(channel_secret.encode(), message.encode(), hashlib.sha256).digest()
    return base64.b64encode(digest).decode()


class LinePayClient:
    def __init__(self, channel_id: str, channel_secret: str,
                 base_url: str | None = None, api_version: str | None = None) -> None:
        """base_url / api_version 為 None 時讀 .env.local 的 LINEPAY_API_BASE_URL / LINEPAY_API_VERSION，
        未設定則 fallback 到 sandbox + v4。"""
        self.channel_id, self.channel_secret = channel_id, channel_secret
        self.base_url = (base_url or os.getenv("LINEPAY_API_BASE_URL") or HOSTS["sandbox"]).rstrip("/")
        self.v = "/" + (api_version or os.getenv("LINEPAY_API_VERSION") or "v4")

    def call(self, method: str, api_path: str, body: dict | None = None,
             query: dict[str, Any] | None = None, timeout: float = 20) -> dict:
        nonce = str(uuid.uuid4())
        pairs = [(k, str(v).lower() if isinstance(v, bool) else str(v))
                 for k, vs in (query or {}).items() for v in (vs if isinstance(vs, list) else [vs])]
        query_string = urllib.parse.urlencode(pairs)
        body_text = json.dumps(body, separators=(",", ":"), ensure_ascii=False) if (method == "POST" and body is not None) else ""
        message = self.channel_secret + api_path + (query_string if method == "GET" else body_text) + nonce
        req = urllib.request.Request(
            self.base_url + api_path + (f"?{query_string}" if query_string else ""),
            data=body_text.encode() if method == "POST" else None,
            method=method,
            headers={
                "Content-Type": "application/json",
                "X-LINE-ChannelId": str(self.channel_id),
                "X-LINE-Authorization-Nonce": nonce,
                "X-LINE-Authorization": sign(self.channel_secret, message),
            },
        )
        with urllib.request.urlopen(req, timeout=timeout) as res:
            text = res.read().decode()
        # Python int 本身不會失精度；轉字串只是為了與其他系統一致
        return json.loads(re.sub(r":\s*(\d{16,})\b", r': "\1"', text))

    def request(self, payload: dict) -> dict:
        return self.call("POST", f"{self.v}/payments/request", payload, timeout=10)

    def confirm(self, tx_id: str, amount: int, currency: str = "TWD") -> dict:
        return self.call("POST", f"{self.v}/payments/{tx_id}/confirm", {"amount": amount, "currency": currency}, timeout=40)

    def capture(self, tx_id: str, amount: int, currency: str = "TWD") -> dict:
        return self.call("POST", f"{self.v}/payments/authorizations/{tx_id}/capture", {"amount": amount, "currency": currency}, timeout=60)

    def void(self, tx_id: str) -> dict:
        return self.call("POST", f"{self.v}/payments/authorizations/{tx_id}/void")

    def refund(self, tx_id: str, refund_amount: int | None = None) -> dict:
        return self.call("POST", f"{self.v}/payments/{tx_id}/refund", None if refund_amount is None else {"refundAmount": refund_amount})

    def details(self, transaction_ids: list[str] | None = None, order_ids: list[str] | None = None) -> dict:
        q = {k: v for k, v in {"transactionId": transaction_ids, "orderId": order_ids}.items() if v}
        return self.call("GET", f"{self.v}/payments", query=q)

    def check_request_status(self, tx_id: str) -> dict:
        return self.call("GET", f"{self.v}/payments/requests/{tx_id}/check")

    def check_reg_key(self, reg_key: str, credit_card_auth: bool | None = None) -> dict:
        return self.call("GET", f"{self.v}/payments/preapprovedPay/{reg_key}/check",
                         query=None if credit_card_auth is None else {"creditCardAuth": credit_card_auth})

    def pay_preapproved(self, reg_key: str, payload: dict) -> dict:
        return self.call("POST", f"{self.v}/payments/preapprovedPay/{reg_key}/payment", payload)

    def expire_reg_key(self, reg_key: str) -> dict:
        return self.call("POST", f"{self.v}/payments/preapprovedPay/{reg_key}/expire")
```

## 9. 官方範例程式的已知瑕疵（照抄會出錯）

| 位置 | 官方寫法 | 正確做法 |
|---|---|---|
| prerequisites / v4 總覽 `requestLINEPayAPI()` | `` `${baseUrl}${apiPath}${queryString !== "" ? "&" + queryString : ""}` `` | query 前綴應為 `?`，不是 `&` |
| prerequisites `requestLINEPayAPI()` | POST 無 body 時 `JSON.stringify(null)` → 簽 `"null"` 但不送 body | 簽空字串、不送 body（2026-11-19 sandbox 實測：簽 `"null"` 回 `1106`） |
| implement-payment-without-confirmUrl | `setInterval(getPayRequestStatus("…"), 1000)` | 應傳函式：`setInterval(() => getPayRequestStatus(id), 1000)`；`switch` 也漏了 `break` |
| implement-payment-without-confirmUrl | 欄位寫 `options.redirectUrls.confirmUrlType` | 實際路徑是 **`redirectUrls.confirmUrlType`**（見 api-reference 付款請求表） |
| implement-preapproved-payment | `regKeyStatusCheckResponse.resultCode` 與 `case 0000:`（數字） | 欄位是 `returnCode`，值是字串 `"0000"` |
| v4 總覽「錯誤回應」範例 | `{"resultCode": 1104, "statusMessage": "..."}` | 實測回 `{"returnCode":"1104","returnMessage":"Merchant not found."}` |
| request-preapproved-payment curl | `X-LINE-Authorize-Nonce` | `X-LINE-Authorization-Nonce` |
| implement-basic-payment 等指南 | 範例 apiPath 仍是 `/v3/...` | v4 把 `/v3/` 換成 `/v4/` 即可，路徑結構相同 |
