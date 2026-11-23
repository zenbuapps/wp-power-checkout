# SHOPLINE Payments — 內嵌式：綁卡 / 快捷付款 / 定期扣款

> 來源：https://docs.shoplinepayments.com/guide/quick/ （串接方式 → 內嵌式 → 綁卡/快捷/定期付款串接）
> 搭配：/api/customer-paymentInstrument/** 、/api/event/model/instrument/ 、/overview/supportedPaymentMethod/CreditCard/
> 爬取日期：2026-11-16

**僅內嵌式支援**（導轉式只支援一般付款交易）。只適用 `CreditCard`。

## 四種場景

| 場景 | `paymentBehavior` | 說明 | 顧客在場 | 需 SDK |
|---|---|---|---|---|
| 純綁卡 | `CardBind` | 只綁卡不付款；**無論傳多少金額，系統固定以 1 元作業，授權成功後自動取消授權** | 是 | 是 |
| 付款並綁卡 | `CardBindPayment` | 付款同時儲存卡片 | 是 | 是 |
| 快捷付款 | `QuickPayment` | 用已綁卡片直接結帳 | 是 | 是 |
| 定期扣款 | `Recurring` | 特店在顧客離場後主動扣款 | **否** | **否** |

> 同一顧客重複綁定同一張卡，SLP **不會**產生新的付款工具 ID，回覆第一次綁定的付款工具資訊。

## 三種 ID 不可混用

| 名稱 | 屬於 | 說明 |
|---|---|---|
| `referenceCustomerId` | API 請求 | 特店自訂的顧客唯一識別碼 |
| `customerId` / `paymentCustomerId` | API 回應 / 請求 | **SLP 會員 ID**。綁卡交易成功後由 SLP 建立，回應 / webhook 的 `order.customer.customerId`；快捷 / 定期建單時以 `confirm.paymentCustomerId` 傳入 |
| `customerToken` | SDK 初始化 | 由 `customerId` 透過 `POST /api/v1/customer/token` 產生的**臨時** token（回應 `expireTime` 單位秒）；SDK 用它載入已綁卡列表 |

關係：`referenceCustomerId` →（會員查詢）→ `customerId` →（會員授權）→ `customerToken` → SDK。

## 參數組合總覽（建立付款交易 `confirm`）

| 場景 | `paymentBehavior` | `paymentInstrument.savePaymentInstrument` | `autoConfirm` | `paymentCustomerId` | `paymentInstrument.paymentInstrumentId` | SDK |
|---|---|---|---|---|---|---|
| 純綁卡 | `CardBind` | `true`（必填） | `false` | 不傳 | 不傳 | 要 |
| 付款並綁卡 | `CardBindPayment` | `true`（必填） | `false` | 不傳 | 不傳 | 要 |
| 快捷付款 | `QuickPayment` | 不傳 | `false` | **必填** | 不傳 | 要（帶 `customerToken`） |
| 定期扣款 | `Recurring` | 不傳 | **`true`（必填）** | **必填** | **必填** | 不要；`paySession: "{}"` |

## SDK 綁卡設定（初始化）

```typescript
const { payment, error } = await ShoplinePayments({
  clientKey: 'YOUR_CLIENT_KEY',
  merchantId: 'YOUR_MERCHANT_ID',
  paymentMethod: 'CreditCard',
  currency: 'TWD',
  amount: 10000,
  element: '#paymentContainer',
  env: 'sandbox',
  paymentInstrument: {
    bindCard: {
      enable: true,
      protocol: {
        switchVisible: false,       // false = 不顯示勾選框，強制綁卡
        defaultSwitchStatus: true,  // switchVisible: true 才有效
        mustAccept: false,          // switchVisible: true 才有效
        textType: {
          paymentAgreement: false,   // 「信用卡交易協議」文字
          subscribeAgreement: false, // 「定期購物信用卡代扣協議」文字
        },
      },
    },
  },
})
```

> 參數預設值（initData 頁）：`enable=false`、`switchVisible=false`、`defaultSwitchStatus=true`、`mustAccept=false`、`paymentAgreement=true`、`subscribeAgreement=true`。組合表見 `embedded-sdk.md` §4。

## 場景一：純綁卡（CardBind）

流程：
1. 初始化 SDK（設 `bindCard`）→ 渲染收銀台
2. 顧客點結帳 → `payment.createPayment()` → `paySession`
3. Server 呼叫 `POST /api/v1/trade/payment/create`
4. SLP 以 1 元驗卡（授權成功後自動取消），回 `tradeOrderId` + `nextAction`
5. `payment.pay(nextAction)` → SDK 顯示結果頁
6. Webhook / 查詢 → 取得 `customerId`（SLP 會員 ID）並保存

建單要點（guide 範例，**僅列重點欄位**；完整必填欄位見 `api-reference.md` §3）：

```json
{
  "amount": { "value": 10000, "currency": "TWD" },
  "confirm": {
    "paymentMethod": "CreditCard",
    "paymentBehavior": "CardBind",
    "paymentInstrument": { "savePaymentInstrument": true }
  },
  "customer": { "referenceCustomerId": "CUST_123456" }
}
```

綁卡成功後，回應中的 `customer.customerId`（付款交易物件的 `order.customer.customerId`）即 SLP 會員 ID，**務必保存**。

## 場景二：付款並綁卡（CardBindPayment）

流程同場景一，但 SLP 完成「授權 + 請款 + 儲存卡片」。若 `switchVisible: false` 則不顯示勾選框、強制綁卡。

```json
{
  "amount": { "value": 10000, "currency": "TWD" },
  "confirm": {
    "paymentMethod": "CreditCard",
    "paymentBehavior": "CardBindPayment",
    "paymentInstrument": { "savePaymentInstrument": true }
  },
  "customer": { "referenceCustomerId": "CUST_123456" }
}
```

## 場景三：快捷付款（QuickPayment）

前置：已保存顧客 `paymentCustomerId`（SLP 會員 ID）。

1. （若未保存）會員查詢 → `customerId`：`POST /api/v1/customer/get`，body `{ "referenceCustomerId": "CUST_123456" }`
   > guide 範例寫 `GET /api/v1/customer/query?referenceCustomerId=...`，與 API reference（`POST /api/v1/customer/get`）衝突，以 API reference 為準。查不到代表尚未首次綁卡，需先走場景一或二。
2. 產生 `customerToken`：`POST /api/v1/customer/token`，body `{ "customerId": "MEMBER_987654" }` → `{ customerId, customerToken, expireTime }`
3. SDK 初始化帶 `customerToken`（有時效，需在有效期內使用）：

```typescript
const { payment, error } = await ShoplinePayments({
  clientKey: 'YOUR_CLIENT_KEY',
  merchantId: 'YOUR_MERCHANT_ID',
  paymentMethod: 'CreditCard',
  currency: 'TWD',
  amount: 10000,
  element: '#paymentContainer',
  customerToken: 'eyJhbGciOi...',
  env: 'sandbox',
})
```

4. 顧客選卡 → `createPayment()` → Server 建單：

```json
{
  "amount": { "value": 10000, "currency": "TWD" },
  "confirm": {
    "paymentMethod": "CreditCard",
    "paymentBehavior": "QuickPayment",
    "paymentCustomerId": "MEMBER_987654"
  },
  "customer": { "referenceCustomerId": "CUST_123456" }
}
```

5. `payment.pay(nextAction)` → 結果頁。

## 場景四：定期扣款（Recurring）

前置：已首次綁卡；已保存 `paymentCustomerId` 與 `paymentInstrumentId`（可由 `POST /api/v1/customer/paymentInstrument/query` 取得，對應回應 `paymentInstruments[].instrumentId`）。

```json
{
  "paySession": "{}",
  "amount": { "value": 10000, "currency": "TWD" },
  "confirm": {
    "paymentMethod": "CreditCard",
    "paymentBehavior": "Recurring",
    "autoConfirm": true,
    "paymentCustomerId": "MEMBER_987654",
    "paymentInstrument": { "paymentInstrumentId": "PI_001" }
  },
  "customer": { "referenceCustomerId": "CUST_123456" },
  "client": { "ip": "特店伺服器 IP" }
}
```

| 參數 | 值 | 說明 |
|---|---|---|
| `autoConfirm` | `true` | **必填**；否則交易停在待確認狀態 |
| `paymentCustomerId` | SLP 會員 ID | 必填 |
| `paymentInstrument.paymentInstrumentId` | 付款工具 ID | 必填 |
| `paySession` | `"{}"` | **必傳空物件字串**，欄位不可省略 |
| `client.ip` | 特店伺服器 IP | 顧客不在場 |

定期扣款專屬錯誤碼：`4900` 通路要求 3DS 但顧客不在場無法完成、`4901` 通路要求 CVS（官方原文）認證無法完成、`4902` 其他原因無法完成。
扣款排程（週期、重試）由特店自行實作；SLP 端無排程 API（官方文件未提供）。

## 交易結果與 customerId 保存

Webhook（`trade.succeeded`）或查詢回應片段（guide 範例）：

```json
{
  "status": "SUCCEEDED",
  "customer": { "referenceCustomerId": "CUST_123456", "customerId": "MEMBER_987654" },
  "payment": { "paymentCustomerId": "MEMBER_987654" }
}
```

> 實際 API 結構中 `customer` 位於 `order.customer`（見 `api-reference.md` §3）。`customerId` 不是 `customerToken` 也不是 `referenceCustomerId`。

Webhook 最多重試 16 次（15 秒逐步拉長到 12 小時），務必回 HTTP 200。退款同一般交易，180 天內。

## 付款工具維護與狀態

| 操作 | 介面 |
|---|---|
| 查已綁卡 | `POST /api/v1/customer/paymentInstrument/query`（傳 `customerId`） |
| 解綁 | `POST /api/v1/customer/paymentInstrument/unbind`（傳 `customerId` + `paymentInstrumentId`） |

| `instrumentStatus` | 說明 | 可快捷 / 定期 |
|---|---|---|
| `SUCCESSED` | 綁定成功 | 是 |
| `CREATED` | 建立中 | 否 |
| `DISABLED` | 已解綁 | 否 |
| `FAILED` | 綁定失敗 | 否 |

| Webhook `type` | 時機 | 處理 |
|---|---|---|
| `customer.instrument.binded` | 顧客完成綁卡 | 付款工具可用 |
| `customer.instrument.updated` | 卡片資訊更新（如即將到期、發卡行更新） | 同步本地卡片資訊 |
| `customer.instrument.unbinded` | 顧客或系統解綁 | 停止對該付款工具扣款 |

Webhook 電文範例（guide）：

```json
{
  "id": "事件 ID",
  "type": "customer.instrument.binded",
  "created": 1718551769058,
  "data": {
    "customerId": "MEMBER_987654",
    "referenceCustomerId": "CUST_123456",
    "paymentInstrument": {
      "instrumentId": "PI_001",
      "instrumentType": "CreditCard",
      "instrumentStatus": "SUCCESSED",
      "instrumentCard": {
        "type": "CREDIT", "brand": "Visa", "first": "400000", "last": "1234",
        "expireYear": "2027", "expireMonth": "12", "expired": false, "issuer": "XX Bank"
      }
    }
  }
}
```

官方建議：每次快捷付款或定期扣款前，先查付款工具列表確認 `instrumentStatus === 'SUCCESSED'` 且 `expired === false`；收到 `unbinded` 或狀態轉 `DISABLED` / `FAILED` 時停止扣款並通知顧客重新綁卡。

綁卡相關錯誤碼：`1200` 暫不支援綁卡、`1201` 系統處理綁卡後流程中（5 分鐘後再試）、`1203` 卡片驗證通路回應失敗、`4800`–`4802`、`2011` 付款工具狀態禁止修改。
