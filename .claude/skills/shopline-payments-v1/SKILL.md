---
name: shopline-payments-v1
description: >
  SHOPLINE Payments（SLP）v1 金流完整參考，涵蓋兩種串接：導轉式 redirect / regular mode（trade/sessions/create、
  sessionUrl）與內嵌式 embedded / SDK / direct mode（前端 JS SDK @shoplinepayments/payment-web、ShoplinePayments()、
  clientKey、createPayment() 取 paySession、pay(nextAction)、Server trade/payment/create acquirerType SDK），
  含綁卡 CardBind、快捷 QuickPayment、定期扣款 Recurring、customerToken。共用 merchantId / apiKey / signKey、
  Webhook HMAC-SHA256 驗簽、session.* / trade.* 事件、請款 capture、取消 cancel、退款 refund、錯誤碼、沙盒測試卡，
  以及官方 AI 輔助串接工具 MCP（slpayment-mcp、get_slpayment_docs）。
  Use this skill whenever the task involves SHOPLINE Payments、shoplinepayments.com、SLP API、ShoplineService、
  ShoplineController、導轉式、內嵌式、embedded checkout、前端付款元件、收銀台、paySession、nextAction、
  trade/payment/create、signKey 驗簽、ApplePay 網域驗證、LINE Pay、JKOPay、VirtualAccount、ChaileaseBNPL、
  SHOPLINE MCP、AI 工具串接、mcp server，或任何 SHOPLINE Payments 的 TWD 金流整合。API v1（webhook V1.2）。
---

# SHOPLINE Payments v1（導轉式 + 內嵌式）

> API v1 ｜ Webhook `apiVersion: V1.2` ｜ 官方文件 https://docs.shoplinepayments.com/ ｜ 本 skill 最後同步：2026-11-16

SHOPLINE Payments（SLP）是 SHOPLINE 的第三方代收付服務，只支援 **TWD**。有兩種串接方式，共用同一組憑證、Webhook、錯誤碼與請款 / 取消 / 退款 / 查詢 API。

## 兩種模式怎麼選

| | 導轉式（redirect / regular mode） | 內嵌式（embedded / SDK） |
|---|---|---|
| 官方 guide | `/guide/session/`「導轉式付款串接」 | `/guide/normal/`「一般付款串接」、`/guide/quick/`「綁卡/快捷/定期付款串接」 |
| 交易物件 | **結帳交易 Session**（一筆可產生多筆付款交易 Trade） | **付款交易 Trade**（一筆一種付款方式） |
| 建立 API | `POST /api/v1/trade/sessions/create`（`mode: "regular"`） | `POST /api/v1/trade/payment/create`（`acquirerType: "SDK"` + `paySession`） |
| 前端 | 只需 `window.location.href = sessionUrl`；不需 SDK | 引入 JS SDK、留容器、初始化、`createPayment()`、`pay(nextAction)` |
| 用到的金鑰 | `merchantId` + `apiKey`（Server）+ `signKey`（Webhook） | 另加 `clientKey`（前端 SDK） |
| 卡號輸入位置 | SLP 託管付款頁 | 特店結帳頁內的 SDK 收銀台（卡資料由 SDK 收集，特店不經手） |
| PCI 範圍 | 官方文件未載明（SLP 具 PCI DSS Level 1） | 官方文件未載明（卡資訊由 SDK 處理、不經特店 Server） |
| 前端工作量 | 極低 | 中（付款方式選單、容器、錯誤處理、ApplePay 網域驗證） |
| 付款方式 | CreditCard（含分期）、VirtualAccount、JKOPay、ApplePay、LinePay、ChaileaseBNPL；**一頁多選**（`allowPaymentMethodList`） | 同 6 種；**SDK 實例一次一種**（`paymentMethod`） |
| 綁卡 / 快捷 / 定期扣款 | **不支援**（官方：僅一般付款交易） | 支援（`paymentBehavior`） |
| 手動請款 `autoCapture: false` | Session API 無此欄位 | 支援 |
| 3DS | SLP 付款頁處理 | SDK `pay(nextAction)` 處理（勿放 iframe） |
| 主要 Webhook | `session.succeeded` / `session.expired`（+ 視需要 `trade.*`） | `trade.succeeded` / `failed` / `expired` / `cancelled` |
| 適用 | 想最快上線、不想碰前端、不需存卡 | 結帳頁內完成付款、需綁卡 / 訂閱 / 快捷、需自訂 UI |

## 共用基礎

### 環境

| 環境 | Server API Base | SDK `env` |
|---|---|---|
| 沙盒 | `https://api-sandbox.shoplinepayments.com` | `'sandbox'` |
| 正式 | `https://api.shoplinepayments.com` | `'production'`（預設） |

### 憑證（後台「設定 → 開發者管理」）

| 憑證 | 用途 | 使用位置 |
|---|---|---|
| `merchantId` | 特店 ID（平台模式為子特店 ID） | Server header、SDK 初始化 |
| `apiKey` | Server-API 認證，**機密，只能放 Server** | Server header |
| `clientKey` | SDK 通訊憑證，**僅內嵌式需要** | 前端 SDK 初始化 |
| `signKey` | Webhook 驗簽；**每個 Webhook URL 一把** | Server 驗簽 |
| `platformId` | 平台特店才有 | Server header、SDK 初始化 |

### Server API 共用 Header

| Header | 型別 | 必填 | 說明 |
|---|---|---|---|
| `Content-Type` | String | 是 | `application/json` |
| `merchantId` | String | 是 | |
| `apiKey` | String | 是 | |
| `requestId` | String(32) | 是 | 每個 HTTP 請求唯一 → `randomUUID().replace(/-/g,'')` |
| `platformId` | String | 平台特店必填 | |
| `idempotentKey` | String(32) | 否 | 冪等 KEY |

錯誤：HTTP 400 / 429 / 500 → `{ code, msg }`（`references/error-codes.md`）。

### 金額

一律 `{ value: 台幣 × 100, currency: 'TWD' }`：NT$100 → `10000`。SDK 的 `amount` 也是 ×100。

### 共用 API（兩模式皆用付款交易 `tradeOrderId`）

| 用途 | Endpoint | 必填 body |
|---|---|---|
| 查詢付款交易 | `POST /api/v1/trade/payment/get` | `tradeOrderId` |
| 請款（手動請款） | `POST /api/v1/trade/payment/capture` | `referenceOrderId`、`tradeOrderId`、`amount` |
| 取消授權 | `POST /api/v1/trade/payment/cancel` | `referenceOrderId`、`tradeOrderId` |
| 建立退款 | `POST /api/v1/trade/refund/create` | `referenceOrderId`（特店**退款**單號）、`tradeOrderId`、`amount`；`reason` 選填 |
| 查詢退款 | `POST /api/v1/trade/refund/get` | `refundOrderId` |

完整欄位：`references/api-reference.md`。

### Webhook 驗簽（HMAC-SHA256）

Header：`apiVersion`（`V1.2`）、`timestamp`（**毫秒**字串）、`sign`（hex）。Body：`{ id, type, created, data }`。

```typescript
import { createHmac, timingSafeEqual } from 'node:crypto'

export function verifySlpWebhook(signKey: string, timestamp: string, rawBody: string, sign: string, toleranceMs = 5 * 60_000): boolean {
  const expected = createHmac('sha256', signKey).update(`${timestamp}.${rawBody}`, 'utf8').digest('hex')
  const a = Buffer.from(expected), b = Buffer.from(sign.toLowerCase())
  if (a.length !== b.length || !timingSafeEqual(a, b)) return false
  const ts = Number(timestamp)
  return Number.isFinite(ts) && Math.abs(Date.now() - ts) <= toleranceMs   // 容忍值由特店自訂（官方未給數字）
}
```

- 必須用**原始 body 字串**（NestJS `rawBody: true` / Express `express.raw()`）。
- 回 HTTP 200 才算成功；否則重試共 16 次（15s → 12h）。handler 必須冪等（以 `id` 去重）。
- 事件總表與 data 結構：`references/webhook-events.md`。

## 導轉式核心流程

1. Server `POST /api/v1/trade/sessions/create` → 取得 `sessionId`、`sessionUrl`。
2. 前端 `window.location.href = sessionUrl`（**不要** iframe、不要 `window.open`；直接開網址會因 `document.referrer` 為空而報錯）。
3. 顧客在 SLP 頁選付款方式付款 → 回到 `returnUrl`（只作顯示，不可當成付款成功依據）。
4. Webhook `session.succeeded` / `session.expired`，或 `POST /api/v1/trade/sessions/query`（body 只有 `sessionId`）。
5. 保存 `paymentDetails[].tradeOrderId`（退款要用）。需要卡號前六後四 / ATM 付款人帳號 → 訂閱 `trade.*` 或用 `tradeOrderId` 查 `trade/payment/get`。

```typescript
import { randomUUID } from 'node:crypto'

const res = await fetch(`${process.env.SLP_API_BASE}/api/v1/trade/sessions/create`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    merchantId: process.env.SLP_MERCHANT_ID!,
    apiKey: process.env.SLP_API_KEY!,
    requestId: randomUUID().replace(/-/g, ''),
  },
  body: JSON.stringify({
    referenceId: 'ORD20261116001',                 // String(32)，唯一
    amount: { value: 10000, currency: 'TWD' },
    returnUrl: 'https://shop.example.com/checkout/result',
    mode: 'regular',                               // 固定值
    language: 'zh-TW',
    expireTime: 360,                               // 分鐘，預設 360
    allowPaymentMethodList: ['CreditCard', 'LinePay', 'VirtualAccount'],   // 順序 = 顯示順序
    paymentMethodOptions: {
      CreditCard: { installmentCounts: ['0', '3', '6'] },
      VirtualAccount: { paymentExpireTime: 4320 },  // 1440–86400
    },
    order: {
      products: [{ id: 'SKU-1', name: '商品', quantity: 1, amount: { value: 10000, currency: 'TWD' } }],
      shipping: {
        shippingMethod: '宅配', carrier: '黑貓宅配',
        personalInfo: { lastName: '王', firstName: '小明', phone: '+886912345678' },
        address: { countryCode: 'TW', city: '台北市', street: '信義路五段7號' },
      },
    },
    billing: {
      personalInfo: { lastName: '王', firstName: '小明', email: 'buyer@example.com' },
      address: { countryCode: 'TW', street: '信義路五段7號' },
    },
    customer: {
      referenceCustomerId: 'CUST_123456',
      personalInfo: { lastName: '王', firstName: '小明', email: 'buyer@example.com' },
    },
    client: { ip: '203.0.113.10' },
  }),
})
const data = await res.json()
if (!res.ok) throw new Error(`SLP ${data.code}: ${data.msg}`)
// data: { sessionId, referenceId, status: 'CREATED', sessionUrl, createTime, amount, paymentDetails? }
```

導轉式邊界：一筆 Session 可能對應多筆 Trade；也可能 **Session 已逾時但 Trade 成功**（ATM、通道延遲），Session 不會轉成功 → 要靠 `trade.*` 補單。

## 內嵌式核心流程

1. 前端：`npm install @shoplinepayments/payment-web`（或 CDN `https://cdn.shoplinepayments.com/sdk/v1/payment-web.js`），準備空容器 `<div id="paymentContainer">`。
2. `const { payment, error } = await ShoplinePayments({ clientKey, merchantId, paymentMethod, currency: 'TWD', amount, element: '#paymentContainer', env })`。
3. 顧客點結帳 → **立刻** `const { paySession, error } = await payment.createPayment()`（ApplePay 中間不可有任何 await）。
4. 前端把 `paySession` 傳到特店 Server → Server `POST /api/v1/trade/payment/create`（`acquirerType: 'SDK'`、`paySession`、`confirm.paymentMethod`、`confirm.paymentBehavior: 'Regular'`…）→ 取得 `tradeOrderId`、`nextAction`。
5. 前端 `await payment.pay(nextAction)`（無 `nextAction` = 建單失敗）；成功會跳 `returnUrl` 或 3DS 頁；失敗看 `payResult.error`。
6. 以 Webhook `trade.*` 或 `trade/payment/get` 確認結果。

```typescript
// 前端
const { payment, error } = await ShoplinePayments({
  clientKey: CLIENT_KEY, merchantId: MERCHANT_ID,
  paymentMethod: 'CreditCard', currency: 'TWD', amount: 10000,
  element: '#paymentContainer', env: 'sandbox',
})
if (error) return
submitBtn.addEventListener('click', async () => {
  const { paySession, error } = await payment.createPayment()
  if (error) return
  const { nextAction } = await (await fetch('/api/slp/pay', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ paySession }) })).json()
  if (!nextAction) return
  const r = await payment.pay(nextAction)
  if (r && r.error) console.warn(r.error.code)
})
```

```typescript
// 後端 body 重點（完整欄位見 references/api-reference.md §3）
{
  acquirerType: 'SDK',
  referenceOrderId: 'ORD20261116001',
  amount: { value: 10000, currency: 'TWD' },
  language: 'zh-TW',                         // 內嵌式為必填
  returnUrl: 'https://shop.example.com/checkout/result',
  paySession,                                // 前端 createPayment() 結果，原樣帶入
  confirm: { paymentMethod: 'CreditCard', paymentBehavior: 'Regular', autoCapture: true },
  order: { products: [/*...*/], shipping: {/*...*/} },
  billing: {/*...*/}, customer: {/*...*/}, client: { ip: '203.0.113.10' },
}
```

綁卡 / 快捷 / 定期扣款：`references/card-binding-recurring.md`。SDK 所有參數、方法、各付款方式差異、theme、錯誤碼：`references/embedded-sdk.md`。

## 狀態

| 付款交易 `status` | 說明 |
|---|---|
| `CREATED` | 建立、校驗中 |
| `CUSTOMER_ACTION` | 顧客 3DS / 生物辨識 / 掃碼中 |
| `PROCESSING` | 處理中；`subStatus`：`AUTHORIZED`（手動請款待 capture）、`PENDING_REVIEW`、`RISK_PENDING` |
| `SUCCEEDED` | 成功（信用卡 = 請款成功） |
| `FAILED` | 失敗；`subStatus`：`RISK_REJECTED`、`CONFIRM_FAILED`、`CAPTURE_FAILED`、`FAILED` |
| `CANCELLED` | 已取消 |
| `EXPIRED` | 逾期（3DS、未付款 6 小時、銀行無回應） |

結帳交易：`CREATED` / `PENDING` / `SUCCEEDED` / `EXPIRED`。退款：`CREATED` / `PROCESSING` / `SUCCEEDED` / `FAILED`。

## 透過 MCP 查官方文件

SLP 官方提供 MCP 橋接器（STDIO ↔ HTTP），唯一 tool `get_slpayment_docs` 用來**查文件**，不能操作交易。
啟動：`npx -y @shoplinepayments/slpayment-developer-mcp@1.0.1`，env `SLPAYMENT_MCP_HTTP_URL=https://ai.shoplinepayments.com/mcp`、`SLPAYMENT_MCP_HTTP_TIMEOUT_MS=30000`，不需 key，Node ≥ 18。
本 skill 已涵蓋的內容以本 skill 為準；MCP 用來確認新功能或本 skill 標「官方文件未載明」處。Cursor / Claude Desktop / Cline 設定原文、官方範例筆誤與 Claude Code 等價寫法：`references/ai-mcp.md`。

## 沙盒速查

- 帳號：`slpsandbox2@shopline.com` / `shoplinePayments123.`（Merchant ID `2652289079513847808`）；金鑰需登入後台取得。
- 測試卡：Visa `4147633700198405` 03/30 CVC `638`（另有 JCB、MasterCard）。
- 金額（元）為 3 的倍數 → 3DS；否則單數成功、雙數失敗（401 成功、400 失敗）。
- ApplePay 沙盒 Apple ID：`slpsandbox2+001@shopline.com` / `Aa123456!`。
- 完整：`references/sandbox-resources.md`。

## 陷阱

1. **金額 ×100**：`value: 100` 是 NT$1。
2. **路徑**：內嵌式建單是 `trade/payment/create`（不是 `trade/orders/create`）；查詢是 `trade/payment/get`、`trade/refund/get`。
3. **`mode: 'regular'` 只屬於導轉式 Session API**；內嵌式沒有 `mode`，改用 `acquirerType: 'SDK'`。
4. **`clientKey` 只給前端 SDK**；`apiKey` 絕不能出現在前端。
5. **`requestId` 最長 32**：UUID 要去掉 `-`。
6. `referenceId`（Session）/ `referenceOrderId`（Trade、退款單號）≤ 32 且唯一。
7. `lastName` 必填、`firstName` 選填；email 與 phone 至少一個；phone 需國碼。
8. `order.products`、`order.shipping`、`billing`、`customer`、`client` 都**必填**（風控需要）。
9. `paySession`、`nextAction` 都**不可解析**，原樣傳遞。
10. ApplePay：點擊與 `createPayment()` 間不可 await；需託管網域驗證檔（沙盒 / 正式不同檔、去 `.txt`）並在後台登記網域。
11. 信用卡 / ApplePay 必須 HTTPS；3DS 頁不可放 iframe。
12. `returnUrl` 只是瀏覽器導回，付款結果以 Webhook / 查詢為準。
13. 退款限 180 天（`1020`）、前一筆退款處理中不可再退（`4706`）、部分付款方式不支援部分退款（`4707`）。結帳交易 Session 不能退款，要對 `tradeOrderId` 退。
14. 導轉式同時訂閱 `session.*` 與 `trade.*` 會收到兩邊通知，要去重。
15. `Recurring` 要 `autoConfirm: true`、`paySession: "{}"`、`paymentCustomerId`、`paymentInstrumentId`。

## References

| 需要 | 檔案 |
|---|---|
| 所有 Server API 欄位、型別、必填、列舉、共用物件 | `references/api-reference.md` |
| 前端 JS SDK（載入、初始化參數、方法、各付款方式、theme、相容性、SDK 錯誤碼、完整範例） | `references/embedded-sdk.md` |
| 綁卡 / 快捷付款 / 定期扣款、會員與付款工具 | `references/card-binding-recurring.md` |
| Webhook 事件總表、各 data 結構、驗簽、重試、模式別處理 | `references/webhook-events.md` |
| Server 錯誤碼（146 條）+ SDK 錯誤碼 | `references/error-codes.md` |
| 沙盒帳號、測試卡、金額規則、ApplePay 測試、分期銀行 | `references/sandbox-resources.md` |
| 官方 AI 輔助串接工具 MCP | `references/ai-mcp.md` |

不在範圍：平台專用的分帳 split、轉帳 transfer、結算 settle、Connect、KYC / Onboarding（見官方 /api/trade/split/、/connect/、/api/kyc/）。
