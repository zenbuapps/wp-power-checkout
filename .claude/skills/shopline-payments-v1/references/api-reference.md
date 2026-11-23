# SHOPLINE Payments v1 — Server API Reference

> 來源（2026-11-16 以 agent-browser 重新爬取）：
> https://docs.shoplinepayments.com/api/ 、/api/trade/session/ 、/api/trade/sessionQuery/ 、/api/trade/create/ 、
> /api/trade/query/ 、/api/trade/capture/ 、/api/trade/cancel/ 、/api/trade/refund/ 、/api/trade/refundQuery/ 、
> /api/customer-paymentInstrument/**（會員 / 付款工具）
> 列舉值取自各欄位旁「參考」彈出表。

## 目錄

0. [通訊、認證、共用 Header、錯誤回應](#0-通訊與共用規範)
1. [建立結帳交易（導轉式）`trade/sessions/create`](#1-建立結帳交易導轉式)
2. [查詢結帳交易（導轉式）`trade/sessions/query`](#2-查詢結帳交易導轉式)
3. [建立付款交易（內嵌式）`trade/payment/create`](#3-建立付款交易內嵌式)
4. [付款交易查詢 `trade/payment/get`](#4-付款交易查詢)
5. [交易請款 `trade/payment/capture`](#5-交易請款)
6. [交易取消 `trade/payment/cancel`](#6-交易取消)
7. [建立退款交易 `trade/refund/create`](#7-建立退款交易)
8. [退款交易查詢 `trade/refund/get`](#8-退款交易查詢)
9. [會員與付款工具 API（綁卡 / 快捷 / 定期用）](#9-會員與付款工具-api)
10. [共用物件定義](#10-共用物件定義)
11. [附錄：列舉值](#附錄列舉值)

## 端點總表

| 用途 | Method | Path | 模式 |
|---|---|---|---|
| 建立結帳交易 | POST | `/api/v1/trade/sessions/create` | 導轉式 |
| 查詢結帳交易 | POST | `/api/v1/trade/sessions/query` | 導轉式 |
| 建立付款交易 | POST | `/api/v1/trade/payment/create` | 內嵌式 |
| 付款交易查詢 | POST | `/api/v1/trade/payment/get` | 共用（導轉式用 session 回應中的 `tradeOrderId`） |
| 交易請款 | POST | `/api/v1/trade/payment/capture` | 共用 |
| 交易取消 | POST | `/api/v1/trade/payment/cancel` | 共用 |
| 建立退款交易 | POST | `/api/v1/trade/refund/create` | 共用 |
| 退款交易查詢 | POST | `/api/v1/trade/refund/get` | 共用 |
| 建立會員 | POST | `/api/v1/customer/create` | 內嵌式（綁卡） |
| 會員查詢 | POST | `/api/v1/customer/get` | 內嵌式（綁卡） |
| 會員授權（customerToken） | POST | `/api/v1/customer/token` | 內嵌式（快捷） |
| 更新會員 | POST | `/api/v1/customer/update` | 內嵌式 |
| 註銷會員 | POST | `/api/v1/customer/delete` | 內嵌式 |
| 付款工具列表查詢 | POST | `/api/v1/customer/paymentInstrument/query` | 內嵌式（定期） |
| 解綁付款工具 | POST | `/api/v1/customer/paymentInstrument/unbind` | 內嵌式 |

> **舊版 skill 錯誤更正**：先前寫的 `trade/orders/create|capture|cancel|query`、`trade/refund/query` 在官方文件**不存在**；
> 正確為 `trade/payment/create|get|capture|cancel`、`trade/refund/get`。
> 平台專用（分帳 split、轉帳 transfer、結算 settle、KYC、Connect）不在本 skill 範圍，見官方 /api/trade/split/ 等頁。

---

## 0. 通訊與共用規範

- HTTPS only；純 HTTP 呼叫會失敗。body 為 JSON，`Content-Type: application/json`。
- 認證：每個請求 Header 帶 `apiKey`。`apiKey` 權限大，**不可放前端 / 公開 repo**。
- 環境（官方以無 scheme 的 host 列出）：

| 環境 | Host |
|---|---|
| 沙盒 | `api-sandbox.shoplinepayments.com` |
| 正式 | `api.shoplinepayments.com` |

### 共用 Http.header（所有 Server API）

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `Content-Type` | String | 必填 | 固定 `application/json` |
| `platformId` | String | 選填 | SLP 平台 ID，**平台特店必填** |
| `merchantId` | String | 必填 | 直連特店：SLP 特店 ID；平台特店：子特店 ID |
| `apiKey` | String | 必填 | API 介面金鑰 |
| `requestId` | String(32) | 必填 | 請求流水號，**每個 HTTP 請求唯一** |
| `idempotentKey` | String(32) | 選填 | 冪等 KEY（冪等行為細節官方文件未載明） |

> `requestId` 長度 32：`crypto.randomUUID()` 為 36 字元（含 `-`），請用 `randomUUID().replace(/-/g, '')`（32 字元）。

### 錯誤回應（HTTP 400 / 429 / 500）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `code` | String | 必填 | 錯誤碼，見 `error-codes.md` |
| `msg` | String | 必填 | 錯誤描述 |

HTTP 200 = 處理成功（但付款結果仍要看 `status`）。

### 金額

所有 `Amount` 物件：`value` Number(14)（**台幣 ×100**，1 元傳 `100`）、`currency` String（目前僅 `TWD`）。

---

## 1. 建立結帳交易（導轉式）

```
POST {DOMAIN_NAME}/api/v1/trade/sessions/create
```

### Http.body

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceId` | String(32) | 必填 | 特店訂單號 |
| `amount` | Amount | 必填 | 金額 |
| `language` | String(6) | 選填 | `en` / `zh-TW` |
| `expireTime` | Integer | 選填 | 結帳交易逾時（分鐘），預設 `360` |
| `returnUrl` | String(256) | 必填 | 顧客付款完成後回到特店的頁面 |
| `mode` | String | 必填 | **固定 `regular`** |
| `allowPaymentMethodList` | String[] | 必填 | SessionURL 可用付款方式；**陣列順序 = 顯示順序**。例：`["CreditCard","ApplePay","VirtualAccount"]`。列舉見附錄 paymentMethod |
| `paymentMethodOptions` | map[string]Object | 選填 | 各付款方式設定；**ApplePay 與 LINE Pay 暫不支援設定** |
| `paymentMethodOptions.CreditCard.installmentCounts` | String[] | 選填 | 分期期數，`"0"` = 一般交易；不帶則只有一般交易 |
| `paymentMethodOptions.ChaileaseBNPL.installmentCounts` | String[] | 選填 | 同上 |
| `paymentMethodOptions.ChaileaseBNPL.paymentExpireTime` | Integer | 選填 | 分鐘；建議 / 預設 `4320`（3 天）；非整數天向上取整 |
| `paymentMethodOptions.JKOPay.paymentExpireTime` | Integer | 選填 | 分鐘；預設 `60` |
| `paymentMethodOptions.VirtualAccount.paymentExpireTime` | Integer | 選填 | 分鐘；最小 `1440`、最大 `86400`；預設 `4320`；非整數天向上取整 |
| `confirm` | ConfirmOptions | 選填 | 付款項內容 |
| `confirm.autoSettle` | Boolean | 選填 | 自動結算，預設 `true`。**僅平台特店可帶** |
| `confirm.splitInfo` | SplitInfo | 選填 | 平台分帳設定；僅已開通分帳權限的平台商可傳，否則請求被攔截 |
| `confirm.splitInfo.splitMode` | String(16) | 必填 | 目前僅 `DELAYED` |
| `order` | SessionPurchaseOrder | 必填 | 交易訂單資訊，見 §10 PurchaseOrder |
| `billing` | BillingInfo | 必填 | 帳單資訊，見 §10 |
| `customer` | Customer | 必填 | 顧客資訊（SLP 智慧風控必需），見 §10 |
| `client` | ClientInfo | 必填 | 終端資訊，見 §10 |

> Session 建立 API **沒有** `confirm.autoCapture`、`passthrough`、`additionalData`、`paySession`、`acquirerType` 欄位（與內嵌式不同）。
> 舊版 skill 寫的 `confirm.autoSettle`「Auto-settle after authorization」語意錯誤：官方為**自動結算、僅平台特店**。

### 200 回應

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `sessionId` | String(32) | 必填 | SLP 結帳交易訂單編號 |
| `referenceId` | String(32) | 必填 | 特店訂單號 |
| `status` | String(16) | 必填 | 結帳交易狀態（`CREATED`/`PENDING`/`SUCCEEDED`/`EXPIRED`） |
| `sessionUrl` | String(256) | 必填 | 提供給顧客付款的 URL |
| `createTime` | Integer | 必填 | 訂單建立時間 |
| `amount` | Amount | 必填 | 金額 |
| `paymentDetails` | Array\<paymentDetail\> | 選填 | 付款交易詳細資訊列表 |
| `paymentDetails[].tradeOrderId` | String(64) | 必填 | SLP 付款交易訂單編號（**退款要用這個**） |
| `paymentDetails[].status` | String(128) | 必填 | 付款狀態 |
| `paymentDetails[].paymentSuccessTime` | Integer | 必填 | 付款成功時間 |
| `paymentDetails[].paymentMethod` | String(512) | 必填 | 付款方式 |
| `paymentDetails[].autoSettle` | Boolean | 選填 | 自動結算（平台特店） |

### 前端導轉規則（guide/session）

```typescript
window.location.href = sessionUrl
```

- **強烈建議不要**在 iFrame 或用 `window.open` 開 `sessionUrl`。
- 若頁面顯示「抱歉，當前訂單可能出現一些狀況，請確認付款情況並稍後重試」，通常是直接存取導致 `document.referrer` 為空字串（例如直接輸入網址或書籤）而驗證失敗；須由特店頁面正常導轉。

---

## 2. 查詢結帳交易（導轉式）

```
POST {DOMAIN_NAME}/api/v1/trade/sessions/query
```

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `sessionId` | String(32) | 必填 | SLP 結帳交易訂單編號 |

> 舊版 skill 寫「`sessionId` 或 `referenceId` 擇一」為錯誤；官方 request body **只有 `sessionId`（必填）**。

200 回應：與 §1 回應欄位完全相同（`sessionId`、`referenceId`、`status`、`sessionUrl`、`createTime`、`amount`、`paymentDetails[]`）。

---

## 3. 建立付款交易（內嵌式）

```
POST {DOMAIN_NAME}/api/v1/trade/payment/create
```

前置：前端 SDK `payment.createPayment()` 取得 `paySession`（見 `embedded-sdk.md`）。
定期扣款（`Recurring`）不需 SDK，`paySession` 傳 `"{}"`（見 `card-binding-recurring.md`）。

### Http.body

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `acquirerType` | String(6) | 必填 | 串接模式，**固定 `SDK`** |
| `referenceOrderId` | String(32) | 必填 | 特店訂單號 |
| `amount` | Amount | 必填 | 金額 |
| `language` | String(6) | 必填 | `en` / `zh-TW` |
| `expireTime` | Integer | 選填 | 部分付款方式支援（如 ATM 銀行轉帳），單位 min |
| `returnUrl` | String(256) | 必填 | 顧客付款完成後回到特店的頁面 |
| `paySession` | JSON String | 必填 | SDK 資訊，直接從 SDK 取得傳入 |
| `passthrough` | String(256) | 選填 | 透傳資訊，response 原路回應 |
| `order` | PurchaseOrder | 必填 | 交易訂單資訊，見 §10 |
| `billing` | BillingInfo | 必填 | 帳單資訊，見 §10 |
| `confirm` | ConfirmOptions | 必填 | 付款項內容（下表） |
| `customer` | Customer | 必填 | 顧客資訊，見 §10 |
| `client` | ClientInfo | 必填 | 終端資訊，見 §10 |
| `additionalData` | Map | 選填 | 附加資訊 |

#### `confirm`（ConfirmOptions，內嵌式）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `paymentMethod` | String(16) | 必填 | 付款方式（與 SDK 初始化的 `paymentMethod` 一致） |
| `subPaymentMethod` | String(16) | 選填 | 子付款方式（列舉官方文件未載明） |
| `autoConfirm` | Boolean | 選填 | SDK 自動確認，預設 `false`。**僅 `paymentBehavior = Recurring` 時設 `true`** |
| `autoCapture` | Boolean | 選填 | 是否自動請款，預設 `true`。設 `false` = 手動請款（授權後需呼叫 §5 請款） |
| `autoSettle` | Boolean | 選填 | 自動結算，預設 `true`，**僅平台特店** |
| `splitInfo` | SplitInfo | 選填 | 平台分帳；`splitMode` 必填，僅 `DELAYED` |
| `paymentBehavior` | String(32) | 必填 | 付款場景：`Regular` / `CardBind` / `CardBindPayment` / `QuickPayment` / `Recurring` |
| `paymentCustomerId` | String(32) | 選填 | SLP 付款會員 ID，**`QuickPayment` / `Recurring` 必填** |
| `paymentInstrument` | PaymentInstrument | 選填 | 付款工具資訊 |
| `paymentInstrument.paymentInstrumentId` | String(32) | 選填 | 付款工具 ID（`Recurring` 必填） |
| `paymentInstrument.savePaymentInstrument` | Boolean | 選填 | 是否儲存付款工具；**`CardBind` / `CardBindPayment` 時須為 `true`** |

### 200 回應（付款交易物件 — 查詢 API 與 `trade.*` webhook 的 `data` 結構相同）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `acquirerType` | String(6) | 必填 | 固定 `SDK`（僅建立回應有；查詢回應無此欄） |
| `referenceOrderId` | String(32) | 必填 | 特店訂單號 |
| `tradeOrderId` | String(32) | 必填 | SLP 付款交易訂單編號（**務必保存**） |
| `sessionId` | String(32) | 選填 | SLP 結帳交易訂單編號（導轉式產生的 trade 才有） |
| `status` | String(32) | 必填 | 付款狀態，見附錄 |
| `subStatus` | String(32) | 必填 | 子付款狀態；**手動請款時要看** |
| `paymentMsg` | PaymentError | 選填 | 付款失敗原因 `{ code, msg }` |
| `actionType` | String(16) | 選填 | 下一步動作類型，列舉僅 `SDK`（執行 SDK） |
| `nextAction` | NextAction | 選填 | 下一步動作，**特店可忽略內容，原樣傳給 SDK `pay()`** |
| `order` | Object | 必填 | SLP 訂單資訊（下表） |
| `payment` | Object | 必填 | 訂單付款資訊（下表） |
| `additionalData` | Map | 選填 | 附加資訊 |

`order`：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `merchantId` | String(32) | 必填 | 特店 ID |
| `amount` | CurrencyAmount | 必填 | 訂單金額 |
| `referenceOrderId` | String(32) | 必填 | 特店訂單號 |
| `createTime` | String(32) | 必填 | 訂單建立時間（webhook 範例實際為數字 ms） |
| `customer.referenceCustomerId` | String(32) | 必填 | 顧客唯一識別碼 |
| `customer.customerId` | String(32) | 必填 | SLP 會員 ID；若 referenceCustomerId 不存在會新建 |

`payment`：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `paymentMethod` | String(16) | 必填 | 付款方式 |
| `subPaymentMethod` | String(16) | 選填 | 子付款方式 |
| `autoConfirm` | Boolean | 選填 | 同請求 |
| `autoCapture` | Boolean | 選填 | 同請求 |
| `paymentBehavior` | String(32) | 必填 | 付款場景 |
| `paymentSuccessTime` | String(32) | 選填 | 付款成功時間 |
| `autoSettle` | Boolean | 選填 | 平台特店 |
| `isSettle` | Boolean | 必填 | 是否已結算，預設 `false` |
| `splitInfo.splitMode` | String(16) | 必填（若有 splitInfo） | `DELAYED` |
| `paidAmount` | CurrencyAmount | 必填 | 實際付款金額；**手動請款時要看** |
| `channelDealId` | String | 選填 | 第三方平台流水號（街口、LINE Pay 對帳用） |
| `paymentCustomerId` | String(32) | 選填 | SLP 付款會員 ID |
| `creditCard.type` | String | 必填 | `CREDIT` 信用卡 / `DEBIT` 金融卡 |
| `creditCard.bin` | String | 必填 | 卡號前六位（範例為 8 碼） |
| `creditCard.last4` | String | 必填 | 卡號後四位 |
| `creditCard.category` | String | 選填 | 卡類別 |
| `creditCard.issuer` | String | 選填 | 發卡行 |
| `creditCard.issuerCountry` | String | 選填 | 發卡國家 |
| `creditCard.brand` | String | 選填 | 卡組織 |
| `virtualAccount.dueDate` | String | 必填 | 轉帳截止日期 |
| `virtualAccount.dueDateDesc` | String | 必填 | 轉帳截止日期說明 |
| `virtualAccount.recipientAccountNum` | String | 必填 | 轉帳虛擬帳號 |
| `virtualAccount.recipientBankCode` | String | 必填 | 虛擬帳號銀行代碼 |
| `virtualAccount.paymentAccountNum` | String | 選填 | 付款人轉出帳號 |
| `virtualAccount.paymentBankCode` | String | 選填 | 付款人轉出銀行代碼 |
| `linePay[]` | Array\<Object\> | 選填 | LINE Pay 付款資訊 |
| `linePay[].amount` | Number(14) | 必填 | 金額 ×100 |
| `linePay[].currency` | String(3) | 必填 | `TWD` |
| `linePay[].paymentMethod` | String(32) | 必填 | `BALANCE`（LINE Pay 餘額）/ `CREDIT_CARD`（信用卡，含簽帳卡）/ `POINT`（LINE POINTS） |
| `linePay[].bin` | String(16) | 選填 | `CREDIT_CARD` 時有值 |
| `linePay[].last4` | String(8) | 選填 | `CREDIT_CARD` 時有值 |
| `paymentInstrument.paymentInstrumentId` | String | 選填 | 付款工具 ID |
| `paymentInstrument.savePaymentInstrument` | Boolean | 選填 | 是否儲存 |
| `paymentMethodOptions.installments.count` | String | 選填 | 分期期數 |
| `paymentMethodOptions.installments.installDownPay` | String(12) | 選填 | 首期金額 ×100 |
| `paymentMethodOptions.installments.installPay` | String(12) | 選填 | 每期金額 ×100 |

`NextAction` 結構：官方標示「特店可忽略」，未提供欄位規格。Event 查詢頁範例中出現過 `{ method, data, confirmStatus, type: "Redirect", url, target: "Channel" }`，**僅供觀察，不可依賴**。

---

## 4. 付款交易查詢

```
POST {DOMAIN_NAME}/api/v1/trade/payment/get
```

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `tradeOrderId` | String(32) | 必填 | SLP 付款交易訂單編號 |

> 舊版 skill 寫 `trade/orders/query` + `referenceOrderId` 擇一，皆錯誤；官方只接受 `tradeOrderId`。

200 回應：同 §3 回應（無 `acquirerType`）。

---

## 5. 交易請款

```
POST {DOMAIN_NAME}/api/v1/trade/payment/capture
```

適用 `confirm.autoCapture: false` 的授權交易（`status=PROCESSING`、`subStatus=AUTHORIZED`）。

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceOrderId` | String(32) | 必填 | 官方說明文字：「referenceRefundOrderId 和 tradeOrderId 二者至少傳入其一」（官方原文如此，欄位卻標必填，語意矛盾；建議兩者都傳） |
| `tradeOrderId` | String(32) | 必填 | SLP 付款交易訂單編號 |
| `amount` | Amount | 必填 | 請款金額（部分請款規則官方文件未載明；超額回 `7001`/`7402`） |
| `additionalData` | Map | 選填 | 附加資訊 |

200 回應：`tradeOrderId` String(48) 必填、`status` String(16) 必填（付款狀態列舉）、`additionalData` Map 選填。
結果通知：請款交易 Event（範例 type `manual.trade.capture.succeeded`），見 `webhook-events.md`。

> 舊版 skill 寫 `amount` 選填（省略=全額）為錯誤；官方 **`amount` 必填**、`referenceOrderId` 必填。

---

## 6. 交易取消

```
POST {DOMAIN_NAME}/api/v1/trade/payment/cancel
```

取消已授權未請款交易（已請款回 `6002`）。

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceOrderId` | String(32) | 必填 | 特店業務號 |
| `tradeOrderId` | String(48) | 必填 | SLP 付款交易訂單編號 |
| `additionalData` | Map | 選填 | 附加資訊 |

200 回應：`tradeOrderId` String(48)、`status` String(16)、`additionalData`。
結果通知：取消交易 Event（範例 type `manual.trade.cancel.succeeded`）。

---

## 7. 建立退款交易

```
POST {DOMAIN_NAME}/api/v1/trade/refund/create
```

導轉式與內嵌式都對 **付款交易 `tradeOrderId`** 退款（結帳交易 Session 本身不能退款）。

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceOrderId` | String(32) | 必填 | **特店退款訂單號**（每筆退款唯一） |
| `tradeOrderId` | String(32) | 必填 | SLP 付款交易訂單編號 |
| `amount` | Amount | 必填 | 退款金額 |
| `reason` | String(256) | 選填 | 退款原因 |
| `additionalData` | Map | 選填 | 附加資訊 |

> 舊版 skill 的欄位名 `referenceRefundId` 為錯誤；官方是 `referenceOrderId`（語意為特店退款單號）。

### 200 回應（= 退款查詢回應 = `trade.refund.*` webhook data）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `refundOrderId` | String(32) | 必填 | SLP 退款訂單號 |
| `referenceOrderId` | String(32) | 必填 | 特店退款訂單號 |
| `tradeOrderId` | String(32) | 必填 | SLP 付款交易訂單編號 |
| `amount` | Amount | 必填 | 金額 |
| `status` | String(6) | 必填 | `CREATED` / `PROCESSING` / `SUCCEEDED` / `FAILED` |
| `refundMsg` | PaymentError | 選填 | 退款失敗原因 `{ code, msg }` |
| `destinationDetails.linePay[]` | Array\<Object\> | 選填 | 特定付款方式退款明細（欄位同 §3 `linePay[]`） |

限制（錯誤碼頁 / guide）：可退款時效為付款成功後 **180 天**（`1020` / `4703`）；同一筆付款前一筆退款仍處理中不可再發起（`4706`）；退款金額超過可退金額（`4701`）；不支援部分退款的交易（`4707`）；通路不支援線上退款只能線下（`1202`）。各付款方式是否支援部分退款：官方文件未載明逐項清單。

---

## 8. 退款交易查詢

```
POST {DOMAIN_NAME}/api/v1/trade/refund/get
```

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `refundOrderId` | String(32) | 必填 | SLP 退款訂單編號 |

200 回應：同 §7 回應。

> 舊版 skill 的 `trade/refund/query` + `referenceRefundId` 擇一皆錯誤。

---

## 9. 會員與付款工具 API

僅內嵌式綁卡 / 快捷 / 定期扣款使用；流程見 `card-binding-recurring.md`。

### 9.1 建立會員 `POST /api/v1/customer/create`

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceCustomerId` | String | 必填 | 特店會員 ID |
| `customer` | Customer | 必填 | 顧客資訊 |
| `customer.email` | String | 選填 | email / 電話至少擇一 |
| `customer.phoneNumber` | String | 選填 | 需帶國碼 |
| `customer.shipping` | Shipping | 選填 | 物流資訊 |
| `customer.shipping.personalInfo` | PersonalInfo | 選填 | `firstName` String(64)、`lastName` String(64)、`email`、`phoneNumber`、`phoneCountryCode`、`homeTelephone`、`identityNumber`（皆選填） |
| `customer.shipping.address` | Address | 選填 | `countryCode`、`state`、`stateCode`、`city`、`district`、`street`、`street2`、`street3`、`postcode`（皆 String 選填；有物流地址時必填） |
| `customer.shipping.shippingMethod` | String | 選填 | 物流方式 |
| `customer.shipping.carrier` | String | 選填 | 運輸通路，有則必傳 |
| `customer.shipping.tax` | CurrencyAmount | 選填 | 郵費稅（`value` Long、`currency`） |
| `customer.shipping.amount` | CurrencyAmount | 選填 | 郵費金額（含稅） |
| `customer.attachData` | Map | 選填 | 透傳資訊 |
| `customer.name` | String | 選填 | 姓名 |

200：`customerId` String(32)、`referenceCustomerId` String(32)、`attachData` Map。

### 9.2 會員查詢 `POST /api/v1/customer/get`

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `customerId` | String(32) | 選填 | 與 `referenceCustomerId` 至少擇一 |
| `referenceCustomerId` | String(32) | 選填 | 同上 |

200：`referenceCustomerId`、`customerId`、`status`（`ENABLED` 建立 / `FROZEN` 凍結 / `DISABLED` 註銷）、`createTime` Integer。

> **文件衝突**：guide/quick 範例寫 `GET /api/v1/customer/query?referenceCustomerId=...`、回應 `status: "ACTIVE"`；API reference 頁為 `POST /api/v1/customer/get`、狀態列舉無 `ACTIVE`。**以 API reference 頁為準**。

### 9.3 會員授權 `POST /api/v1/customer/token`

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `customerId` | String | 必填 | 會員 ID |

200：`customerId` String(32)、`customerToken` String(64)、`expireTime` Integer（**逾期時間，秒**）。`customerToken` 傳給 SDK 初始化參數 `customerToken`。

### 9.4 更新會員 `POST /api/v1/customer/update`

Body：`customerId` String(32) 必填、`referenceCustomerId` String(32) 選填、`customer` 必填（結構同 9.1）。200：同 9.1。

### 9.5 註銷會員 `POST /api/v1/customer/delete`

Body：`customerId` String(64) 必填。200：`customerId` String(64)、`referenceCustomerId` String(32)、`deleteTime` Integer。

### 9.6 建立付款工具

**無 API**。官方：出於卡資訊安全，不直接提供建立 API，需透過「建立付款交易 API + SDK」（`CardBind` / `CardBindPayment`）完成。

### 9.7 付款工具列表查詢 `POST /api/v1/customer/paymentInstrument/query`

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `customerId` | String | 必填 | 會員 ID |
| `paymentInstrument.paymentInstrumentId` | String | 選填 | 指定 ID |
| `paymentInstrument.instrumentType` | String | 選填 | 僅 `CreditCard` |
| `paymentInstrument.instrumentStatus` | String | 選填 | `CREATED` / `SUCCESSED` / `DISABLED` / `FAILED`；與 `instrumentStatusList` 二選一，同時存在以 `instrumentStatus` 為準 |
| `paymentInstrument.instrumentStatusList` | Array | 選填 | 多狀態查詢 |

> 官方欄位層級為 `paymentInstrument` 下的子欄位；`instrumentType` / `instrumentStatus` 是否位於 `paymentInstrument` 內，頁面縮排與 `paymentInstrumentId` 同層，依此解讀。

200：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `customerId` | String(32) | 必填 | SLP 會員 ID |
| `referenceCustomerId` | String(32) | 必填 | 特店顧客識別碼 |
| `paymentInstruments` | PaymentInstrument（列表） | 選填 | |
| `paymentInstruments[].instrumentId` | String | 必填 | 付款工具 ID（= 建單時的 `paymentInstrumentId`） |
| `paymentInstruments[].instrumentType` | String | 必填 | `CreditCard` |
| `paymentInstruments[].instrumentStatus` | String | 必填 | 見上 |
| `paymentInstruments[].instrumentCard` | Object | 選填 | `type` 必填、`brand`、`expireMonth`、`expireYear`、`expired`（String `"true"`/`"false"`）、`holder` 必填、`first` 必填（前六）、`last` 必填（官方標 Array，後四位）、`issuer`、`issuerCountry` |
| `paymentInstruments[].billing` | — | 選填 | `personalInfo`（firstName/lastName/email/phoneNumber/phoneCountryCode/homeTelephone/identityNumber）、`address`（同 9.1）、`descriptor`（`city`、`name`） |
| `paymentInstruments[].additionalData` | Map | 選填 | |
| `paymentInstruments[].passthrough` | String | 選填 | |

### 9.8 解綁付款工具 `POST /api/v1/customer/paymentInstrument/unbind`

Body：`customerId` String(64) 必填、`paymentInstrumentId` String 必填。
200：`customerId`、`paymentInstrumentId`、`unbindTime` String。

---

## 10. 共用物件定義

### PurchaseOrder / SessionPurchaseOrder（`order`）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `purchaseScene` | String(16) | 選填 | `WEB` 網頁購物 / `APP` APP 內購物 |
| `products` | Array\<Product\> | 必填 | 商品列表（SLP 智慧風控必需） |
| `products[].id` | String(64) | 必填 | 商品編號 |
| `products[].name` | String(128) | 必填 | 商品名稱 |
| `products[].quantity` | Integer | 必填 | 數量 |
| `products[].amount` | Amount | 必填 | 金額（官方未說明是單價或小計） |
| `products[].desc` | String(512) | 選填 | 描述 |
| `products[].url` | String(256) | 選填 | 商品連結 |
| `products[].sku` | String(64) | 選填 | SKU |
| `shipping` | Shipping | 必填 | 物流資訊（SLP 智慧風控必需） |
| `shipping.shippingMethod` | String(64) | 必填 | 物流方式，如超商取貨 / 宅配 |
| `shipping.carrier` | String(64) | 必填 | 物流通路，如黑貓宅配 |
| `shipping.personalInfo` | PersonalInfo | 必填 | 收貨人資訊 |
| `shipping.address` | Address | 必填 | 送貨地址 |

### PersonalInfo

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `firstName` | String(128) | 選填 | firstName + lastName 總長 ≤ 128 |
| `lastName` | String(128) | 必填 | 同上 |
| `email` | String(128) | 選填 | email 與 phone 至少擇一 |
| `phone` | String(64) | 選填 | 需帶國碼，例 `+6287654321876` |
| `gender` | String(1) | 選填 | **僅 customer.personalInfo**：`1` 男 / `2` 女 |
| `identityType` | String(20) | 選填 | **僅 customer.personalInfo**，特定國家需要 |
| `identityNumber` | String(64) | 選填 | **僅 customer.personalInfo**，特定國家需要 |

### Address

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `countryCode` | String(2) | 必填 | `CN` / `TW` / `HK` |
| `stateCode` | String(12) | 選填 | 州或省代碼 |
| `state` | String(128) | 選填 | 州或省名稱 |
| `city` | String(128) | 選填 | 城市 |
| `district` | String(128) | 選填 | 區域 |
| `street` | String(128) | 必填 | 詳細街道地址 |
| `postcode` | String(32) | 選填 | 郵遞區號 |

### BillingInfo（`billing`）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `description` | String(32) | 選填 | 帳單描述 |
| `personalInfo` | PersonalInfo | 必填 | |
| `address` | Address | 必填 | |

### Customer（`customer`）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceCustomerId` | String(32) | 必填 | 顧客唯一識別碼 |
| `type` | String(1) | 選填 | `0` 遊客 / `1` 登入會員 |
| `personalInfo` | PersonalInfo | 必填 | 顧客資訊（可含 gender / identityType / identityNumber） |

> guide/quick 的綁卡範例只帶 `customer.referenceCustomerId`；API reference 標 `personalInfo` 必填。正式串接請依 API reference 帶齊。

### ClientInfo（`client`）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `ip` | String(32) | 必填 | 顧客付款 IP；`Recurring` 可填特店辦公室 / 伺服器 IP |
| `screenWidth` | String(16) | 選填 | 螢幕寬（px） |
| `screenHeight` | String(16) | 選填 | 螢幕高（px） |
| `javaEnabled` | String(16) | 選填 | 終端是否能執行 Java |
| `timeZoneOffset` | String(16) | 選填 | `new Date().getTimezoneOffset()` 的值（分鐘） |
| `transactionWebSite` | String(512) | 選填 | 瀏覽器目前 domain |
| `userAgent` | String(128) | 選填 | User-Agent |
| `language` | String(32) | 選填 | `navigator.language` |
| `colorDepth` | String(16) | 選填 | `screen.colorDepth`，例 `32` |
| `accept` | String(128) | 選填 | 瀏覽器 Accept header |

> 注意 ClientInfo 數值欄位型別都是 **String**。

---

## 附錄：列舉值

| 欄位 | 值 |
|---|---|
| `language` | `en` 英文、`zh-TW` 繁體中文 |
| `env`（SDK） | `sandbox` 沙盒、`production` 正式（預設） |
| `paymentMethod` / `allowPaymentMethodList[]` | `CreditCard`、`VirtualAccount`、`JKOPay`、`ApplePay`、`LinePay`、`ChaileaseBNPL` |
| `paymentBehavior` | `Regular` 一般付款、`CardBind` 純綁卡、`CardBindPayment` 付款並綁卡、`QuickPayment` 快捷付款、`Recurring` 定期扣款 |
| `actionType` | `SDK` 執行 SDK |
| `purchaseScene` | `WEB`、`APP` |
| `countryCode` | `CN`、`TW`、`HK` |
| `customer.type` | `0` 遊客、`1` 登入會員 |
| `gender` | `1` 男、`2` 女 |
| `creditCard.type` | `CREDIT` 信用卡、`DEBIT` 金融卡 |
| `linePay[].paymentMethod` | `BALANCE`、`CREDIT_CARD`、`POINT` |
| `splitMode` | `DELAYED` |
| 付款狀態 `status` | `CREATED`、`CUSTOMER_ACTION`、`PROCESSING`、`SUCCEEDED`、`FAILED`、`CANCELLED`、`EXPIRED` |
| 結帳狀態 | `CREATED`、`PENDING`、`SUCCEEDED`、`EXPIRED` |
| 退款狀態 | `CREATED`、`PROCESSING`、`SUCCEEDED`、`FAILED` |
| 會員狀態 | `ENABLED`、`FROZEN`、`DISABLED` |
| 付款工具狀態 | `CREATED`、`SUCCESSED`（官方拼字）、`FAILED`、`DISABLED` |
| 付款工具類型 | `CreditCard` |

### 付款狀態語意（appendix/orderStatus）

| 狀態 | 信用卡 | ApplePay | 其他付款方式 |
|---|---|---|---|
| `CREATED` | 發起付款請求、訂單建立校驗（金額 / 幣種）、風控、特店狀態校驗 | 同左 | 同左 |
| `CUSTOMER_ACTION` | 顧客進行 3DS（可能） | 指紋 / 臉部辨識 | 掃 QR Code 並在付款頁確認 |
| `PROCESSING` | SLP 受理處理、授權成功 | 同左 | N/A |
| `SUCCEEDED` | 請款成功 | 請款成功 | 交易成功 |
| `FAILED` | 授權 / 請款 / 3DS 失敗、下單失敗、風控攔截 | 授權 / 請款 / 生物辨識失敗等 | 交易失敗 |
| `CANCELLED` | 顧客或特店取消 | 同左 | 顧客關 QR / 付款頁取消、訂單未回應時特店取消 |
| `EXPIRED` | 3DS 逾期、未付款 SLP 6 小時逾期、銀行無回應 / 系統逾期 | 同左 | QR 逾期、銀行系統逾期、6 小時逾期 |

### 子狀態 `subStatus`

| status | subStatus | 說明 |
|---|---|---|
| `PROCESSING` | `AUTHORIZED` | 已授權（手動請款時等待 capture / cancel） |
| `PROCESSING` | `PENDING_REVIEW` | 人工審核中 |
| `PROCESSING` | `RISK_PENDING` | 風控審核中 |
| `FAILED` | `RISK_REJECTED` | 風控拒絕 |
| `FAILED` | `CONFIRM_FAILED` | 授權失敗 |
| `FAILED` | `CAPTURE_FAILED` | 請款失敗 |
| `FAILED` | `FAILED` | 交易失敗 |
