# SHOPLINE Payments v1 — Webhook 通知（Event）

> 來源（2026-11-16 重新爬取）：https://docs.shoplinepayments.com/api/event/ 、/api/event/model/ 、
> /api/event/model/session/ 、/payment/ 、/refund/ 、/capture/ 、/cancel/ 、/member/ 、/instrument/ 、/dispute/ 、/api/event/query/

兩種模式共用同一套 Webhook 與驗簽。

## 目錄

1. [設定與訂閱](#1-設定與訂閱)
2. [事件類型總表](#2-事件類型總表)
3. [HTTP Header 與 Body 外殼](#3-http-header-與-body-外殼)
4. [簽章驗證](#4-簽章驗證)
5. [回應與重試](#5-回應與重試)
6. [各結構 data 欄位](#6-各結構-data-欄位)
7. [模式別建議訂閱與處理](#7-模式別建議訂閱與處理)
8. [Event 查詢 API](#8-event-查詢-api)
9. [NestJS / Express 範例](#9-nestjs--express-範例)

---

## 1. 設定與訂閱

- 在 SHOPLINE Payments 後台「設定 → 開發者管理」取得 API Key / Client Key / Sign Key；**每一個 Webhook URL 對應一把 Sign Key**。
- 預設為特店開通訂閱**全部**事件類型；要改訂閱列表需聯絡串接窗口。
- 官方提醒：未來可能新增事件類型，**不要假設只有列出的類型**（未知 type 應忽略並回 200）。

## 2. 事件類型總表

| 場景 | `type` | 結構 | 說明 |
|---|---|---|---|
| 結帳交易 | `session.created` | 結帳交易 | 結帳建立 |
| | `session.expired` | 結帳交易 | 結帳逾時 |
| | `session.pending` | 結帳交易 | 結帳處理中 |
| | `session.succeeded` | 結帳交易 | 結帳成功 |
| 付款交易 | `trade.succeeded` | 付款交易 | 付款成功 |
| | `trade.failed` | 付款交易 | 付款失敗 |
| | `trade.expired` | 付款交易 | 付款逾時 |
| | `trade.processing` | 付款交易 | 付款處理中 |
| | `trade.cancelled` | 付款交易 | 取消付款 |
| | `trade.customer_action` | 付款交易 | 顧客處理中（3DS / 掃碼等） |
| | `trade.refund.succeeded` | 退款交易 | 退款成功 |
| | `trade.refund.failed` | 退款交易 | 退款失敗 |
| 會員 | `customer.created` / `customer.updated` / `customer.deleted` | 付款會員 | 會員建立 / 更新 / 註銷 |
| 付款工具 | `customer.instrument.binded` / `.updated` / `.unbinded` | 付款工具 | 綁定 / 更新 / 解綁 |
| 爭議 | `dispute.chargeback.created` / `evidence_required` / `evidence_under_review` / `evidence_returned` / `resolved` / `cancelled` / `lost` / `won` / `expired` / `accepted` | 爭議交易 | 拒付流程 |
| | `dispute.pre-chargeback.created` / `in_accept` / `in_reject` / `in_expire` / `accepted` / `merchant_rejected` | 爭議交易 | 預爭議 |
| | `dispute.fraud.finished` | 爭議交易 | 欺詐預警 |
| | `dispute.retrieval.created` / `finished` / `cancelled` | 爭議交易 | 調單 |
| 平台專用 | `trade.settled`、`merchant.kyc.audit`、`trade.split.created` / `processing` / `cancelled` / `succeeded` / `failed` | 結算 / 審核 / 分帳 | 本 skill 不展開 |

> 請款 / 取消結構頁的 `type` 範例為 `manual.trade.capture.succeeded`、`manual.trade.cancel.succeeded`，但**這兩個 type 不在官方事件總表中**；完整 type 列表（是否有 `.failed`）官方文件未載明。
> 退款結構頁範例寫 `refund.succeeded`，總表為 `trade.refund.succeeded`，以總表為準。

## 3. HTTP Header 與 Body 外殼

### Header

| 參數 | 必填 | 型別 | 說明 | 範例 |
|---|---|---|---|---|
| `apiVersion` | 是 | String | API 版本，不同版本報文結構有差異 | `V1.2` |
| `timestamp` | 是 | String | 發送時間戳，**毫秒** | `1629169157000` |
| `sign` | 是 | String | 簽章值（hex） | `873c40ac...` |

各結構頁另列：`Content-Type`（`application/json`）、`platformId`（平台特店）、`merchantId`、`requestId` String(32)、`idempotentKey` String(32) 選填。

> Node.js 的 header 名會被轉小寫：`req.headers['apiversion']`、`['timestamp']`、`['sign']`。

### Body（application/json）

| 參數 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `id` | 是 | String(35) | 通知訊息唯一 ID（**用於去重**） |
| `type` | 是 | String(32) | 事件類型 |
| `created` | 是 | Number(14) | 通知產生時間（ms） |
| `data` | 是 | Object | 事件業務電文 |

## 4. 簽章驗證

演算法：**HMAC-SHA256**，金鑰為該 Webhook URL 對應的 `signKey`。

1. 從 header 取 `timestamp`、`sign`。
2. `payload = "{timestamp}.{bodyString}"`（bodyString = **原始** HTTP body 字串，不可 re-serialize）。
3. `expected = hmacSha256(payload, signKey, UTF-8)`，輸出 hex（官方範例為 64 字元小寫 hex）。
4. 比對 `sign`；相等後再檢查 `timestamp` 與現在的差距是否在容忍範圍（防重播；**容忍值由特店自訂，官方未給數字**）。

```typescript
import { createHmac, timingSafeEqual } from 'node:crypto'

export function verifySlpWebhook(signKey: string, timestamp: string, rawBody: string, sign: string, toleranceMs = 5 * 60_000): boolean {
  const expected = createHmac('sha256', signKey).update(`${timestamp}.${rawBody}`, 'utf8').digest('hex')
  const a = Buffer.from(expected, 'utf8')
  const b = Buffer.from(sign.toLowerCase(), 'utf8')
  if (a.length !== b.length || !timingSafeEqual(a, b)) return false
  const ts = Number(timestamp)
  return Number.isFinite(ts) && Math.abs(Date.now() - ts) <= toleranceMs
}
```

> sign 大小寫：官方範例為小寫 hex，是否可能大寫官方未載明，故比較前轉小寫。5 分鐘容忍值為建議值。

## 5. 回應與重試

- 回 **HTTP 200** = 處理成功，不再通知。
- 非 200 = 失敗，等待下次通知。**共通知 16 次**，間隔：`15s / 15s / 30s / 3m / 10m / 20m / 30m / 1h / 1h / 2h / 3h / 6h / 6h / 6h / 12h / 12h`。
- 回應 body 內容：官方未要求特定格式。
- 因有重試，handler 必須**冪等**（以 `id` 或 `tradeOrderId + status` 去重）。

## 6. 各結構 data 欄位

### 6.1 結帳交易（`session.*`）

與 `trade/sessions/query` 回應相同：`sessionId` String(32)、`referenceId` String(32)、`status`（`CREATED`/`PENDING`/`SUCCEEDED`/`EXPIRED`）、`sessionUrl`、`createTime` Integer、`amount`、`paymentDetails[]`（`tradeOrderId`、`status`、`paymentSuccessTime`、`paymentMethod`、`autoSettle`）。

### 6.2 付款交易（`trade.succeeded` / `failed` / `expired` / `processing` / `cancelled` / `customer_action`）

與 `trade/payment/get` 回應相同（完整欄位表見 `api-reference.md` §3）：
`referenceOrderId`、`tradeOrderId`、`sessionId`（選填，導轉式才有）、`status`、`subStatus`、`paymentMsg{code,msg}`、`actionType`、`nextAction`、`order{merchantId, amount, referenceOrderId, createTime, customer{referenceCustomerId, customerId}}`、`payment{paymentMethod, subPaymentMethod, autoConfirm, autoCapture, paymentBehavior, paymentSuccessTime, autoSettle, isSettle, splitInfo, paidAmount, channelDealId, paymentCustomerId, creditCard{...}, virtualAccount{...}, linePay[...], paymentInstrument{...}, paymentMethodOptions.installments{...}}`、`additionalData`。

官方範例（信用卡付款成功）：

```json
{
  "id": "000100698482394232932302030234328327",
  "type": "trade.succeeded",
  "created": 1718551769058,
  "data": {
    "actionType": "SDK",
    "referenceOrderId": "1600053335",
    "tradeOrderId": "1001001084733463323223973",
    "paymentMsg": null,
    "payment": {
      "paymentSuccessTime": "1718551768922",
      "autoCapture": true,
      "subPaymentMethod": null,
      "paymentBehavior": "Regular",
      "autoConfirm": false,
      "channelDealId": "17185517455610128070000",
      "paymentMethod": "CreditCard",
      "paymentMethodOptions": { "installments": { "installPay": null, "count": null, "installDownPay": null } },
      "paymentCustomerId": "",
      "paymentInstrument": { "savePaymentInstrument": false, "paymentInstrumentId": null },
      "creditCard": {
        "issuerCountry": "TW", "last4": "1234", "bin": "12345678", "type": "CREDIT",
        "category": "BUSINESS SIGNATURE", "brand": "Visa",
        "issuer": "HONG KONG AND SHANGHAI BANKING CORP., LTD."
      },
      "paidAmount": { "currency": "TWD", "value": 10000 }
    },
    "nextAction": null,
    "additionalData": null,
    "subStatus": "",
    "status": "SUCCEEDED",
    "order": {
      "amount": { "currency": "TWD", "value": 10000 },
      "referenceOrderId": "123456789",
      "merchantId": "12345678",
      "createTime": 1718551768994,
      "customer": { "customerId": "", "referenceCustomerId": "123456789" }
    }
  }
}
```

> 注意範例中型別與規格表不一致處：`paymentSuccessTime` 是字串、`order.createTime` 是數字、`subStatus` 為空字串、`bin` 為 8 碼。解析時請寬鬆處理。

### 6.3 退款交易（`trade.refund.succeeded` / `failed`）

`refundOrderId` String(32)、`referenceOrderId` String(32)（特店退款訂單號）、`tradeOrderId` String(32)、`amount`、`status`（`CREATED`/`PROCESSING`/`SUCCEEDED`/`FAILED`）、`refundMsg{code,msg}`、`destinationDetails.linePay[]`、`channelDealId` String(32) 選填。

> 舊版 skill 的 `referenceRefundId`、`reason` 欄位不在官方退款 event 結構中。

### 6.4 請款交易（範例 type `manual.trade.capture.succeeded`）

`referenceOrderId`、`tradeOrderId`、`tradeCaptureOrderId` String(32)（SLP 請款訂單編號）、`status`（付款狀態列舉）、`paymentMsg{code,msg}`。

### 6.5 取消交易（範例 type `manual.trade.cancel.succeeded`）

`referenceOrderId`、`tradeOrderId`、`tradeCancelOrderId` String(32)、`status`、`paymentMsg{code,msg}`。

### 6.6 付款會員（`customer.*`）

`customerId`、`referenceCustomerId`、`createTime` Integer。

### 6.7 付款工具（`customer.instrument.*`）

`customerId`、`referenceCustomerId`、`paymentInstrument{ instrumentId, instrumentType(CreditCard), instrumentStatus(CREATED/SUCCESSED/DISABLED/FAILED), instrumentCard{type, brand, expireYear, expireMonth, holder, first, last, issuer, issuerCountry, expired}, billing{personalInfo, address, descriptor{city,name}}, additionalData, passthrough }`。

### 6.8 爭議交易（`dispute.*`）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `disputeId` | String(32) | 必填 | SLP 拒付訂單編號 |
| `tradeOrderId` | String(32) | 必填 | |
| `referenceOrderId` | String(32) | 必填 | |
| `paymentMethod` | String(16) | 必填 | |
| `disputeType` | String(16) | 必填 | `PRE_CHARGEBACK` / `CHARGEBACK` / `FRAUD_NOTIFICATION` / `RETRIEVAL` |
| `fee` | CurrencyAmount | 選填 | 手續費 |
| `feeType` | String(16) | 選填 | `domestic` / `international` |
| `createTime` / `updateTime` | Integer | 必填 | |
| `disputeEvidenceUpdateDeadline` | Integer | 選填 | 最晚抗辯資料提交時間 |
| `stage` | String(32) | 必填 | `CHARGEBACK` / `PRE_ARBITRATION` / `ARBITRATION` |
| `status` | String(32) | 必填 | `CREATED`、`EVIDENCE_REQUIRED`、`MERCHANT_SUBMITTED`、`EVIDENCE_UNDER_REVIEW`、`EVIDENCE_RETURNED`、`RESOLVED`、`CANCELED`、`LOST`、`WON`、`EXPIRED`、`ACCEPTED`、`MERCHANT_ACCEPTED`、`PRE_CHARGEBACK_CREATED`、`PRE_CHARGEBACK_IN_ACCEPT`、`PRE_CHARGEBACK_IN_REJECT`、`PRE_CHARGEBACK_IN_EXPIRE`、`PRE_CHARGEBACK_ACCEPTED`、`PRE_CHARGEBACK_REJECTED`、`FRAUD_FINISHED`、`RETRIEVAL_CREATED`、`RETRIEVAL_FINISHED`、`RETRIEVAL_CANCELED` |
| `amount` | CurrencyAmount | 選填 | 爭議初始金額 |
| `stageFinalAmount` | CurrencyAmount | 選填 | 階段終態爭議金額 |
| `reason` | String(512) | 必填 | 爭議原因 |

## 7. 模式別建議訂閱與處理

| 模式 | 主要事件 | 處理 |
|---|---|---|
| 導轉式 | `session.succeeded` / `session.expired` | 以結帳交易狀態更新訂單；從 `paymentDetails[].tradeOrderId` 取付款交易 ID 保存（退款用） |
| 導轉式（需卡號前六後四、ATM 付款人帳號等細節） | 另訂閱 `trade.*`，或用 `tradeOrderId` 呼叫 `trade/payment/get` | 官方：**同時訂閱 session 與 trade 通知會兩邊都收到**，須正確處理（去重） |
| 內嵌式 | `trade.succeeded` / `trade.failed` / `trade.expired` / `trade.cancelled` | 以付款交易狀態更新訂單 |
| 手動請款 | `trade.processing`（`subStatus=AUTHORIZED`）→ capture / cancel event | |
| 退款（共用） | `trade.refund.succeeded` / `trade.refund.failed` | |
| 綁卡 / 定期 | `customer.instrument.*` | 見 `card-binding-recurring.md` |

導轉式邊界情況（guide/session）：
- 一筆 Session 在極端情況可能對應**多筆 Trade**（顧客按上一頁改選其他付款方式、或付款交易逾時但 Session 未逾時後重新付款）。
- 可能出現 **Session 已逾時但 Trade 成功**（ATM 虛擬帳號以天計逾時；通道延遲回報）。此時 Trade 會成功，**Session 不會變成功** → 必須訂閱 / 查詢 `trade.*` 才不會漏單。

## 8. Event 查詢 API

官方 /api/event/query/ 頁：

```
GET {URI}/v1/api/events/query/{eventId}
Header: appKey (String, 必填), appId (String, 必填)
```

> 此頁使用 `appKey` / `appId` header 與 `{URI}/v1/api/...` 路徑，與其他 Server API（`apiKey` / `merchantId`、`/api/v1/...`）規格不一致，欄位說明為空白；`appKey` / `appId` 對應哪把金鑰**官方文件未載明**。回應範例為 Event 外殼（`id`、`type`、`created`、`data`）。實際可用性需向串接窗口確認。

## 9. NestJS / Express 範例

NestJS 需開 rawBody：`NestFactory.create(AppModule, { rawBody: true })`。

```typescript
import { Controller, Post, Headers, Req, HttpCode, UnauthorizedException, RawBodyRequest } from '@nestjs/common'
import type { Request } from 'express'
import { verifySlpWebhook } from './slp-webhook.util'

type SlpEvent = { id: string; type: string; created: number; data: Record<string, any> }

@Controller('payments/shopline')
export class ShoplineWebhookController {
  constructor(private readonly svc: ShoplinePaymentService) {}

  @Post('webhook')
  @HttpCode(200)
  async handle(
    @Headers('timestamp') timestamp: string,
    @Headers('sign') sign: string,
    @Req() req: RawBodyRequest<Request>,
  ): Promise<string> {
    const raw = req.rawBody?.toString('utf8') ?? ''
    if (!timestamp || !sign || !verifySlpWebhook(process.env.SLP_SIGN_KEY!, timestamp, raw, sign)) {
      throw new UnauthorizedException()   // 非 200 → SLP 會重試；確認是簽章問題再拒絕
    }
    const evt = JSON.parse(raw) as SlpEvent
    if (await this.svc.alreadyProcessed(evt.id)) return 'OK'

    switch (evt.type) {
      case 'session.succeeded': await this.svc.onSessionSucceeded(evt.data); break
      case 'session.expired':   await this.svc.onSessionExpired(evt.data); break
      case 'trade.succeeded':   await this.svc.onTradeSucceeded(evt.data); break
      case 'trade.failed':
      case 'trade.expired':
      case 'trade.cancelled':   await this.svc.onTradeClosed(evt.data); break
      case 'trade.refund.succeeded':
      case 'trade.refund.failed': await this.svc.onRefund(evt.data); break
      default: break            // 未知 type：忽略並回 200
    }
    await this.svc.markProcessed(evt.id)
    return 'OK'
  }
}
declare class ShoplinePaymentService {
  alreadyProcessed(id: string): Promise<boolean>; markProcessed(id: string): Promise<void>
  onSessionSucceeded(d: any): Promise<void>; onSessionExpired(d: any): Promise<void>
  onTradeSucceeded(d: any): Promise<void>; onTradeClosed(d: any): Promise<void>; onRefund(d: any): Promise<void>
}
```

Express：`app.post('/webhook', express.raw({ type: 'application/json' }), (req, res) => { const raw = req.body.toString('utf8'); ... })`。
