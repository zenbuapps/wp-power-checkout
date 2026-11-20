# 程式碼範例 — 統一客樂得多元支付平台 V1.28.2

分兩類：
- **§1~§2 原廠 PDF 範例**（PHP / C#，逐字轉錄）——取得 Token。
- **§3~§6 依規格推導的 TypeScript / Node.js 實作**——驗章公式與端點呼叫皆直接對應規格；`checksum` 測試向量取自 PDF 已驗證範例，可自我驗證。

> 平台**無欄位加解密**（非 AES）；驗章只有 `checksum`（MD5，`:` 串接）與 `chk`（MD5，`$` 串接 + `hash_base`）。

---

## 1. 取得 Token — PHP（PDF 原廠範例）

```php
<?php
/** 取得授權 authentication token function **/
function get_token($server_url, $username, $password) {
    $cl = curl_init("$server_url/token");
    curl_setopt($cl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($cl, CURLOPT_SSLVERSION, 6); // TLS v1.2
    curl_setopt($cl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($cl, CURLOPT_HTTPHEADER, "Content-Type: application/x-www-form-urlencoded");
    curl_setopt($cl, CURLOPT_POST, true);
    curl_setopt($cl, CURLOPT_POSTFIELDS, "grant_type=password&username=$username&password=$password");
    $auth_response = curl_exec($cl);
    if ($auth_response === false) {
        echo "Failed to authenticate\n";
        var_dump(curl_getinfo($cl));
        curl_close($cl);
        return NULL;
    }
    curl_close($cl);
    return json_decode($auth_response, true);
}
/* server_url 請依正式或測試環境設定 */
$server_url = "https://test.4128888card.com.tw/app";
$token = get_token($server_url, "帳號", "API 密碼");
```

## 2. 取得 Token — C#（.NET Framework，PDF 原廠範例）

```csharp
string username = "帳號";
string apiPassword = "API密碼";
string apiURL = "https://test.4128888card.com.tw/app/";

var client = new HttpClient();
ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls |
    SecurityProtocolType.Tls11 | SecurityProtocolType.Tls12;

client.BaseAddress = new Uri(apiURL);
HttpResponseMessage response = client.PostAsync("Token",
    new StringContent(
        string.Format("grant_type=password&username={0}&password={1}",
            HttpUtility.UrlEncode(username),
            HttpUtility.UrlEncode(apiPassword)),
        Encoding.UTF8,
        "application/x-www-form-urlencoded")).Result;

string resultJSON = response.Content.ReadAsStringAsync().Result;
```

---

## 3. TypeScript — 驗章 helper（含 PDF 已驗證測試向量）

```typescript
import * as crypto from "crypto";

const md5 = (s: string): string =>
  crypto.createHash("md5").update(s, "utf8").digest("hex"); // 小寫 hex，32 碼

/** APN 主動通知驗章（CVS 與 COCS 通用）
 *  checksum = MD5(api_id + ":" + trans_id + ":" + amount + ":" + status + ":" + nonce) */
export function apnChecksum(p: {
  api_id: string; trans_id: string; amount: number | string; status: string; nonce: string;
}): string {
  return md5(`${p.api_id}:${p.trans_id}:${p.amount}:${p.status}:${p.nonce}`);
}

/** CvsIbonUpdateDate 送單驗章
 *  checksum = MD5(cust_order_no + ":" + order_amount + ":" + nonce) */
export function ibonUpdateDateChecksum(p: {
  cust_order_no: string; order_amount: number | string; nonce: string;
}): string {
  return md5(`${p.cust_order_no}:${p.order_amount}:${p.nonce}`);
}

/** COCS 授權完成回報 chk（+ 續期收款首次授權完成回報）
 *  chk = MD5(hash_base$order_amount$send_time$ret$acquire_time$auth_code$card_no$notify_time$cust_order_no) */
export function cocsAuthSuccessChk(hashBase: string, q: {
  order_amount: string; send_time: string; ret: string; acquire_time: string;
  auth_code: string; card_no: string; notify_time: string; cust_order_no: string;
}): string {
  return md5([hashBase, q.order_amount, q.send_time, q.ret, q.acquire_time,
    q.auth_code, q.card_no, q.notify_time, q.cust_order_no].join("$"));
}

/** COCS 授權失敗回報 chk（少了 acquire_time / auth_code / card_no）
 *  chk = MD5(hash_base$order_amount$send_time$ret$notify_time$cust_order_no) */
export function cocsAuthFailChk(hashBase: string, q: {
  order_amount: string; send_time: string; ret: string; notify_time: string; cust_order_no: string;
}): string {
  return md5([hashBase, q.order_amount, q.send_time, q.ret, q.notify_time, q.cust_order_no].join("$"));
}

// ── 自我驗證（值取自 PDF 已驗證範例）──
console.assert(ibonUpdateDateChecksum({ cust_order_no: "20190402000001", order_amount: 250, nonce: "21" })
  === "e309160d46bcefaa7dd8db18a23f179f", "CvsIbonUpdateDate checksum 不符");
console.assert(apnChecksum({ api_id: "CV0000000000", trans_id: "550e8400e29b41d4a716446655440000",
  amount: 1250, status: "D", nonce: "1234569999" }) === "3579609ba3914a49441e98cb7e8a55de", "CVS APN checksum 不符");
console.assert(apnChecksum({ api_id: "CC0000000001", trans_id: "550e8400e29b41d4a716446655440000",
  amount: 1250, status: "B", nonce: "1234569999" }) === "d09d5532767453ad4c6ba9b649034187", "COCS APN checksum 不符");
```

---

## 4. TypeScript — 取得 Token 與呼叫 /api/Collect

```typescript
const BASE = {
  prod: "https://cocs.4128888card.com.tw",
  test: "https://test.4128888card.com.tw/app",
} as const;

/** 取得 Token：POST /Token，form-urlencoded；username = 客戶金流代號（= 後續 cust_id）*/
export async function getToken(base: string, username: string, password: string): Promise<string> {
  const body = new URLSearchParams({ grant_type: "password", username, password });
  const res = await fetch(`${base}/Token`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body,
  });
  const json = await res.json() as { access_token?: string; error?: string; error_description?: string };
  if (!json.access_token) throw new Error(`取得 Token 失敗：${json.error_description ?? json.error}`);
  return json.access_token; // 到期以回應 ".expires" 為準，過期需重新取得
}

/** 呼叫 /api/Collect（CVS / COCS / 主檔 皆走此端點，以 body.cmd 分派）*/
export async function callCollect<T = any>(base: string, token: string, payload: Record<string, unknown>): Promise<T> {
  const res = await fetch(`${base}/api/Collect`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "Authorization": `Bearer ${token}` }, // Bearer 與 token 間有空格
    body: JSON.stringify(payload),
  });
  return res.json() as Promise<T>;
}

/** 續期收款端點（不帶 cmd）：/api/Period、/api/Period/MdfStatus、/api/Period/Exchange、/api/Period/Modify */
export async function callPeriod<T = any>(base: string, token: string, path: string, payload: Record<string, unknown>): Promise<T> {
  const res = await fetch(`${base}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "Authorization": `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
  return res.json() as Promise<T>;
}
```

## 5. TypeScript — 建立訂單範例

```typescript
// CVS 超商繳款（安源 ibon）：payment_type=0, payment_acquirerType=2
await callCollect(BASE.test, token, {
  cmd: "CvsOrderAppend",
  cust_id: "12656354001",
  cust_order_no: "20170718010712",     // 同一 cust_id 下唯一；安源 ≤30 / 統一金流 ≤25
  order_amount: 50,                    // 整數；安源上限 20000
  expire_date: "2026-07-31",           // YYYY-MM-DD
  payer_mobile: "0912345678",
  order_detail: "測試商品",             // 法規必填品名；開 PDF 綜合繳款單則 ≤150 字
  payment_type: "0",                   // 0 ibon / 1 ATM
  payment_acquirerType: "2",           // 2 安源 / 3 統一金流
});
// 回覆欄位同「訂單查詢」（api-reference §2.2）：ibon_code / st_barcode1~3 / virtual_account …

// COCS 線上刷卡：回傳 url = 消費者刷卡短網址
const cocs = await callCollect<{ status: string; cust_order_no: string; url: string; msg?: string }>(
  BASE.test, token, {
    cmd: "CocsOrderAppend",
    cust_id: "12656354001",
    cust_order_no: "20170718010723",   // 可留空，系統自動編 YYYYMMDD######
    order_amount: 50,
    order_detail: "測試訂單備註",
    acquirer_type: "esun",             // esun 玉山 / chinatrust 中信 / payuni 統一金流
    limit_product_id: "esun.normal|esun.m3",
    send_time: "2026-07-21 10:30:00",  // 必須為傳送當下時間
  });
// cocs.url → 導引消費者前往刷卡

// 指定請款（部分請款，未請款金額退還消費者）
await callCollect(BASE.test, token, {
  cmd: "CocsCashRequest", cust_id: "12656354001", cust_order_no: "20170718010723",
  order_amount: 2000, cr_amount: 1700, send_time: "2026-07-21 11:00:00",
});

// 取消交易 / 退款（限已請款後；可部分）
await callCollect(BASE.test, token, {
  cmd: "CocsOrderRefund", cust_id: "12656354001", cust_order_no: "20170718010723",
  order_amount: 1700, refund_amount: 1700, acquirer_type: "esun", send_time: "2026-07-21 12:00:00",
});
```

## 6. TypeScript — APN 接收端（Express 範例）

```typescript
import express from "express";
import { apnChecksum } from "./tcat-pay-crypto";

const app = express();
app.use(express.json());

// 後台設定的「APN-主動發送訊息網址」對應的路由
app.post("/tcatpay/apn", (req, res) => {
  const b = req.body as {
    api_id: string; trans_id: string; amount: number | string; status: string;
    nonce: string; checksum: string; pay_amount?: number | string; order_no: string;
  };

  // 1) 驗章：checksum = MD5(api_id:trans_id:amount:status:nonce)
  if (apnChecksum(b) !== b.checksum) {
    return res.status(400).send("checksum mismatch"); // 不回 OK → 平台會重送
  }

  // 2) 依 status 更新訂單
  //    CVS：A 待繳 / B 已繳 / C 註銷 / D 過期 / E 已預約撥款 / I 開票 / J 折讓
  //    COCS：B 授權 / O 請款中 / E 請款完成 / F 授權失敗 / D 逾期 / P 請款失敗 /
  //          M 取消交易完成 / N 取消交易失敗 / Q 取消授權完成 / R 取消授權失敗 / I 開票 / J 折讓
  //    ⚠️ 出貨務必以 pay_amount（實際繳款金額）判別實收相符，勿只看 amount
  //    ⚠️ 同一狀態碼最多重送 3 次、每 15 分鐘一次 → 需冪等去重

  // 3) 回純文字 "OK" 才會停止重送
  res.type("text/plain").send("OK");
});
```

> **正式 / 測試環境金鑰、`hash_base` 皆不同**，切換環境務必更換。`chk` 驗章需 `hash_base`（申請服務時系統配發），無法用公開測試向量驗證，請以實際環境金鑰計算。
