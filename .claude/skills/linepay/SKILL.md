---
name: linepay
description: >
  LINE Pay（台灣）Online API 直接串接完整參考（官方 developers-pay.line.me，v4 最新、v3 仍可用，
  2026-11-18 擷取）。涵蓋 10 支端點：request、check status、confirm、capture、void、
  payment details、refund、preapproved 自動付款 / 定期定額（Check RegKey、Pay Preapproved、
  Expire RegKey）；HMAC-SHA256 簽章與 Node / PHP / Python client、19 位 transactionId BigInt、
  confirmUrl / cancelUrl 與 IP 白名單、payStatus 狀態機、returnCode 錯誤碼表、sandbox 申請與
  Channel ID / Secret 取得、v3→v4 差異（paymentProvider TSP / EPI）、台灣 TWD 規則。
  觸發詞：LINE Pay、linepay、LINEPay、developers-pay.line.me、api-pay.line.me、
  sandbox-api-pay.line.me、/v3/payments、/v4/payments、preapprovedPay、regKey、transactionId、
  paymentUrl、confirmUrl、confirmUrlType、X-LINE-ChannelId、X-LINE-Authorization、
  X-LINE-Authorization-Nonce、Channel Secret、合作商店中心、管理連結金鑰、returnCode 1104 / 1106 / 1172、
  paymentProvider、EPI、PREAPPROVED、regPayRequest、LINE POINTS、LINE Pay 退款、LINE Pay sandbox。
  本 SKILL 為唯一 reference 來源，不要再搜尋 web；原始文件在 .tmp/[金流] LINE Pay/。
  經第三方金流代收 LINE Pay 改用 newebpay-mpg、payuni-upp-v2、shopline-payments-v1。
---

# LINE Pay（台灣）Online API — 直接串接

> **適用**：Online API **v4**（最新，2025-11 加入）與 v3（仍可用、未 deprecated）｜**文件**：https://developers-pay.line.me/zh/online-api-v4 ｜**最後同步**：2026-11-18
> **官方 AI 工具**：查無官方 Claude skill / MCP / SDK（查證過程見 `references/sources.md`）。本 skill 依官方文件逐頁萃取。
> **原始文件**：`.tmp/[金流] LINE Pay/官方文件/{zh-TW,en}/`；測試環境申請：`.tmp/[金流] LINE Pay/如何申請測試環境.md`；本機憑證：`.tmp/[金流] LINE Pay/.env.local`。

## 邊界：什麼時候用這個 skill

| 情境 | 用哪個 skill |
|---|---|
| 商家**自己**與 LINE Pay 簽約，用自己的 Channel ID / Secret 呼叫 `api-pay.line.me` | **本 skill（linepay）** |
| 藍新 MPG 的付款方式 `LINEPAY`（`PaymentType=LINEPAY`、`LINEPAY=1`） | `newebpay-mpg` |
| PAYUNi UPP 的 `LinePay` 付款方式、`/api/linepay`、`/api/trade/common/refund/linepay` | `payuni-upp-v2` |
| SHOPLINE Payments 的 `LinePay`（`allowPaymentMethodList` / SDK `paymentMethod`） | `shopline-payments-v1` |

第三方金流代收時，**看不到也不需要** LINE Pay 的 Channel ID / Secret、`X-LINE-*` header、`transactionId` 19 位整數；簽章、退款、對帳都走該金流商的 API。不要把本 skill 的端點混進那些串接。
本 skill 也**不涵蓋 Offline API**（實體 POS 掃顧客「我的條碼」`/v4/payments/oneTimeKeys/pay`）。

## 環境與 Host

| 環境 | API host | 付款頁 host |
|---|---|---|
| Sandbox | `https://sandbox-api-pay.line.me` | `sandbox-web-pay.line.me` |
| Production | `https://api-pay.line.me` | 一律使用回應的 `info.paymentUrl.web` / `.app`，不要自己組 |

URL = `https://{host}{apiPath}?{queryString}`。**所有回應 HTTP 一律 200**，成敗只看 body `returnCode`（字串，`"0000"` 成功）。失敗時沒有 `info`。

## 端點速查（v4；v3 把 `/v4/` 換成 `/v3/`）

| # | 功能 | Method + Path | Body / Query | Read timeout |
|---|---|---|---|---|
| 1 | 付款請求 | `POST /v4/payments/request` | `amount`, `currency`, `orderId`, `packages[]`, `redirectUrls`, `options?` | ≥10s |
| 2 | 查詢付款請求狀態 | `GET /v4/payments/requests/{transactionId}/check` | — | ≥20s |
| 3 | 付款授權 | `POST /v4/payments/{transactionId}/confirm` | `amount`, `currency` | ≥40s |
| 4 | 請款 | `POST /v4/payments/authorizations/{transactionId}/capture` | `amount`, `currency` | ≥60s |
| 5 | 取消授權 | `POST /v4/payments/authorizations/{transactionId}/void` | — | ≥20s |
| 6 | 查詢付款明細 | `GET /v4/payments` | `transactionId`、`orderId`（可重複，≤100 筆） | ≥20s |
| 7 | 退款 | `POST /v4/payments/{transactionId}/refund` | `refundAmount?`（省略 = 全額） | ≥20s |
| 8 | 檢查 regKey | `GET /v4/payments/preapprovedPay/{regKey}/check` | `creditCardAuth?` | ≥20s |
| 9 | 預先授權付款 | `POST /v4/payments/preapprovedPay/{regKey}/payment` | `productName?`, `amount`, `currency?`, `orderId`, `capture?` | ≥20s |
| 10 | 刪除 regKey | `POST /v4/payments/preapprovedPay/{regKey}/expire` | — | ≥20s |

每支端點的完整 request / response 欄位表（含所有巢狀欄位、長度、必填）、curl 範例與 response 範例 → `references/api-reference.md`。

## 認證（HMAC-SHA256）

```
Headers:
  Content-Type: application/json
  X-LINE-ChannelId: {Channel ID}
  X-LINE-Authorization-Nonce: {UUID v4，每次新產生}
  X-LINE-Authorization: Base64(HMAC-SHA256(key=ChannelSecret, message))

message = POST: ChannelSecret + apiPath + requestBodyJSON + nonce
          GET : ChannelSecret + apiPath + queryString(不含 ?) + nonce
```

- 簽章用的 body 字串 = **實際送出的 bytes**（序列化一次、同一字串簽與送）。空白 / key 順序 / `\/` 跳脫不同都會 `1106`。
- 無 body 的 POST（void / expire / 全額 refund）：**簽空字串、不送 body**（2026-11-19 sandbox 實測通過；照官方範例簽 `"null"` 會 `1106`）。
- Secret **只用於簽章**，不放 header（`X-LINE-ChannelSecret` 是 Offline API v2 的做法，Online v3/v4 不用）。
- 最小 Node 實作：

```js
import crypto from "node:crypto";

const nonce = crypto.randomUUID();
const apiPath = "/v4/payments/request";
const bodyText = JSON.stringify(payload);
const signature = crypto
  .createHmac("sha256", CHANNEL_SECRET)
  .update(CHANNEL_SECRET + apiPath + bodyText + nonce)
  .digest("base64");

const res = await fetch(`https://sandbox-api-pay.line.me${apiPath}`, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "X-LINE-ChannelId": CHANNEL_ID,
    "X-LINE-Authorization-Nonce": nonce,
    "X-LINE-Authorization": signature,
  },
  body: bodyText,
});
const data = JSON.parse((await res.text()).replace(/:\s*(\d{16,})\b/g, ': "$1"')); // 保住 19 位 transactionId
```

完整 Node / PHP / Python client（10 支端點、已用假憑證實打 sandbox 驗證 header 格式）、交叉驗證向量、1106 排錯 → `references/authentication.md`。

## transactionId 是 19 位整數（必讀）

`transactionId`、`refundTransactionId`、`originalTransactionId` 為 64-bit long（如 `2023042201206549310`），超過 JS `Number.MAX_SAFE_INTEGER`。
JS 端解析前用 regex 把 16+ 位數字轉字串；DB 存 `VARCHAR` 或 `BIGINT`；confirmUrl 收到的 `transactionId` query 也當字串。精度失真會導致 `1150` / `1155`。

## 標準流程（自動請款，台灣預設）

1. **付款請求**：`POST /v4/payments/request`
   ```json
   {
     "amount": 100,
     "currency": "TWD",
     "orderId": "ORDER_20261118_0001",
     "packages": [{ "id": "1", "amount": 100,
       "products": [{ "id": "PEN-B-001", "name": "Pen Brown", "quantity": 2, "price": 50 }] }],
     "redirectUrls": {
       "confirmUrl": "https://shop.example.com/linepay/confirm",
       "cancelUrl": "https://shop.example.com/linepay/cancel"
     },
     "options": { "display": { "locale": "zh_TW" } }
   }
   ```
   金額規則：`amount` = Σ`packages[].amount`；`packages[].amount` = Σ(`price` × `quantity`)。`orderId` 每次付款唯一（`1172`；實測請求階段不擋，confirm 才可能撞號）。金額不符在請求階段回 `2101`。
2. 存 `info.transactionId`（字串）→ 導向付款頁：PC 用 `info.paymentUrl.web`（彈窗 700×546 或整頁），手機用 `info.paymentUrl.app`。**Sandbox 只能用 `web`**，且要用**個人 LINE 帳號**登入。
3. 顧客認證完 → LINE Pay **GET** `confirmUrl?transactionId=…&orderId=…`（取消 → `cancelUrl`）。不要自己在 URL 預放這兩個參數。
4. confirmUrl handler：從**自家 DB** 取該訂單金額（不信任前端），`POST /v4/payments/{transactionId}/confirm` body `{ "amount": 100, "currency": "TWD" }`。`returnCode === "0000"` → 付款完成（已自動請款），存 `payInfo[]`、`paymentProvider`。
5. 之後只能 **refund**（自動請款無法 void）。

訂閱 / 自動續約（WooCommerce Subscriptions）：續約由商店排程主動呼叫 Pay Preapproved，不依賴 `regPayRequest` → `references/flows-and-status.md` §6.1。

其他流程（分開請款、無 confirmUrl 輪詢、預先授權付款 / 定期定額、退款、查明細、狀態機、重定向頁規格與 IP 白名單）→ `references/flows-and-status.md`。

## 狀態與結果碼重點

| 情境 | 判斷 |
|---|---|
| 查詢付款請求狀態 | `0000` 尚未認證、`0110` 可 confirm、`0121` 取消/逾時、`0122` 失敗、`0123` 已完成 |
| 明細 `payStatus` | `AUTHORIZATION` / `CAPTURE` / `EXPIRED_AUTHORIZATION` / `VOIDED_AUTHORIZATION` |
| `1104` | Channel ID 錯或 sandbox / production 憑證混用 |
| `1106` | 缺 header 或簽章不符 |
| `1172` / `1198` | 重複 confirm 實測回 `1172 Already confirmed transaction`；`1198` 重複呼叫 → 先查明細，視為已付款 |
| `1169` | 顧客未完成認證就 confirm——**實測會讓該筆變 `0122` 失敗作廢**，不要拿 confirm 探測狀態 |
| `1172` | 重複 confirm（`Already confirmed transaction`）或用已 confirm 的 orderId 建請求（`Existing same orderId.`） |
| `1180` | 超過付款期限：實測建立請求約 20 分鐘後 confirm 即失敗，confirmUrl handler 要立即 confirm |
| `2103` | 未開通分開請款卻帶 `capture: false`（文件未列，實測） |
| capture 回 `1199`、`1280`–`1298` | 付款已自動取消，需重新付款 |
| void / refund 回 `1900`、`1902`、`1999` | 可重試 |
| 預先授權付款請求（`…/preapprovedPay/{regKey}/payment`）回 `1141`、`1282`–`1287`、`1290`–`1295` | regKey 已註銷，需重新綁定 |

全表（含 v4 新增 `2042`、英文版獨有 `2024`、實測 `2104`）與各端點 Related errors → `references/error-codes.md`。

## 台灣（TWD）必知

- `currency: "TWD"`，金額用整數。`options.display.locale: "zh_TW"` 顯示繁中付款頁。
- **預設自動請款**；分開請款（`options.payment.capture: false`）需事先向 LINE Pay 申請，未開通回 `2103`（自助 sandbox 也一樣，無法測）。
- **預先授權付款（Preapproved / regKey / 定期定額）需事先申請**，未開通回 `1194`；sandbox 可先測。
- v4 回 `paymentProvider`：`TSP`（第三方支付）/ `EPI`（電子支付機構，依《電子支付機構管理條例》）；`null` 視為 TSP；sandbox 線上交易固定 `TSP`。
- TW only 欄位：`options.extra.promotionRestriction`（法規不適用促銷 / 折抵金額）、confirm 回應的 `payInfo[].maskedCreditCardNumber`、`merchantReference.affiliateCards[]`（電子發票**手機條碼載具** `cardType = "MOBILE_CARRIER"`）——後兩者需另申請開通。
- 付款方式 `payInfo[].method`：`BALANCE`（LINE Pay 餘額）、`CREDIT_CARD`（含簽帳卡）、`POINT`（LINE POINTS）；一筆可多種組合。

## 常見陷阱

| 嚴重度 | 陷阱 |
|---|---|
| 高 | JS `JSON.parse` 讓 transactionId 失真 → confirm 打錯交易 |
| 高 | 簽章時重新序列化 body（與送出的字串不同）→ `1106` |
| 高 | confirm 金額取自前端 / query → 可被竄改；一律用自家訂單金額 |
| 高 | 照抄官方指南的 `requestLINEPayAPI()`：query 前綴寫成 `&`（應為 `?`） |
| 中 | 用官方指南的 `/v3/` 範例路徑卻以為是 v4 |
| 中 | confirmUrl 沒處理「顧客重新整理頁面」→ 第二次 confirm 得 `1172`（實測），應冪等（已付款就直接顯示完成） |
| 中 | 只依賴 confirmUrl：顧客關掉瀏覽器時永遠不會被呼叫 → 排程用「查詢付款請求狀態」或「查詢付款明細」補單；或改 `confirmUrlType: "SERVER"` |
| 中 | sandbox 用 `paymentUrl.app` → 打不開；商品圖用 http → 不顯示 |
| 中 | 把 `X-LINE-ChannelSecret` 當 header（舊教學 / Offline v2 做法） |
| 低 | 官方預先授權 curl 範例 header 誤拼 `X-LINE-Authorize-Nonce` |

## 測試憑證

官方**沒有公開**共用測試 Channel ID / Secret。需自助申請 sandbox 帳號（免商家身分、即時寄信）：
https://developers-pay.line.me/zh/sandbox →「註冊sandbox帳號」→ 對象國家 `台灣（TW）`、對象API `Online API`、對應貨幣 `新台幣（TWD）`、email →
收信登入 https://pay.line.me/portal/tw/auth/login → 開發者工具 › 管理連結金鑰 → 查詢 + email 驗證碼 → 取得 Channel ID / Secret。
已簽約合作商店可直接用正式憑證打 sandbox。逐步說明 → `references/sandbox-and-onboarding.md`。

本機 env key（`.tmp/[金流] LINE Pay/.env.local`）：`LINEPAY_ENV`、`LINEPAY_API_BASE_URL`、`LINEPAY_API_VERSION`（三語 client 直接讀這兩個 key，未設 fallback sandbox + v4）、`LINEPAY_CHANNEL_ID`、`LINEPAY_CHANNEL_SECRET`（另有 `LINEPAY_SANDBOX_*` / `LINEPAY_PROD_*` 分組）。

## 2026-11-19 sandbox 實測結論

| 項目 | 結果 |
|---|---|
| 簽章（POST body / GET query / 無 body POST） | 三語 client 全部通過；v4 與 v3 付款請求皆 `0000` |
| 完整付款（認證 → confirm → refund） | **未完成**：sandbox 付款頁要真人 LINE 帳號登入或手機掃 QR |
| 分開請款 `capture: false` | 自助 sandbox 被拒 `2103` |
| PREAPPROVED 首筆 0 元 | 請求與 confirm 都 `0000`，回 `regKey`；回應無 `transactionId` / `paymentProvider`，綁定交易查明細 `1150` |
| 一般付款完整流程 | confirm `0000` → 狀態 `0123` → 重複 confirm `1172` → void `1179` → 部分退 3 元 `0000` → 無 body 退剩餘 `0000`（兩筆都記 `PARTIAL_REFUND`）→ 再退 `1165` |
| 自動付款完整流程 | Check RegKey `0000`（`creditCardAuth=true` → `2103`）→ Pay Preapproved 10 元 `0000` → 全額退 `0000`（`PAYMENT_REFUND`）→ Expire `0000` → 之後 Check / Pay / Expire 都 `1193` |
| orderId 重複 | confirm 前不擋；已 confirm 的 orderId 再建請求 → `1172 Existing same orderId.` |
| `regPayRequest` | 請求與 confirm 都接受，不驗 `recurringDay` 範圍；confirm 回應沒有任何規則欄位；是否自動扣款 [待確認：需保留有效 regKey 等到扣款日] |
| 提前 confirm | `1169`，且該筆變 `0122` 作廢 |
| 付款請求有效期 | 約 20 分鐘（推論）：未認證者 21 分 45 秒變 `0121`；已認證但晚 confirm 回 `1180` |
| 認證完成後隔太久才 confirm | 建立請求約 20 分鐘後 confirm 回 `1180`（狀態查詢當時仍是 `0110`），交易變 `0122`；推論有效期約 20 分鐘 |

完整紀錄：`.tmp/[金流] LINE Pay/sandbox-實測紀錄-2026-11-19.md`。

## References 導引

| 需求 | 檔案 |
|---|---|
| 某支端點的完整 request / response 欄位、curl 與回應範例 | `references/api-reference.md` |
| 簽章演算法、header、BigInt、Node / PHP / Python 完整 client、1106 排錯、官方範例瑕疵 | `references/authentication.md` |
| 付款流程（自動請款 / 分開請款 / 無 confirmUrl / 預先授權 / 退款 / 查明細）、狀態機、重定向頁、IP 白名單 | `references/flows-and-status.md` |
| returnCode 全表、各端點可能錯誤、重試與冪等策略 | `references/error-codes.md` |
| v3 vs v4 差異、遷移清單 | `references/v3-differences.md` |
| sandbox 申請、取得憑證、合作商店申請、台灣特定規則、聯絡窗口 | `references/sandbox-and-onboarding.md` |
| 來源 URL、官方 AI 工具查證、文件不一致清單 | `references/sources.md` |
