# LINE Pay Online — 付款流程、狀態機、重定向頁面

> 來源（2026-11-18 擷取，原文存於 `.tmp/[金流] LINE Pay/官方文件/zh-TW/`）：
> https://developers-pay.line.me/zh/online 、/zh/online/implement-basic-payment 、/zh/online/implement-capture-separated-payment 、
> /zh/online/implement-payment-without-confirmUrl 、/zh/online/implement-preapproved-payment 、/zh/online/handle-refund 、
> /zh/online/retrieve-payment-info 、/zh/online-api-v4/merchant/redirection-pages
> 官方指南的程式範例仍寫 `/v3/...`；v4 路徑結構相同，只換版本前綴。

## 目錄

1. 角色與四步驟
2. 狀態機（payStatus）
3. 基本付款（自動請款）
4. 授權與請款分開（capture=false）
5. 無 confirmURL（confirmUrlType=NONE / SERVER）
6. 預先授權付款（Preapproved / regKey / 定期定額）
7. 退款
8. 查詢付款明細
9. 重定向頁面（confirmUrl / cancelUrl）規格與 IP 白名單

## 1. 角色與四步驟

角色：顧客（LINE App / 瀏覽器）、合作商店伺服器（merchant server）、LINE Pay 伺服器。

| 順序 | 步驟 | 說明 |
|---|---|---|
| 1 | 付款請求 | 商店伺服器 `POST /v4/payments/request`，取得 `transactionId` + `paymentUrl` |
| 2 | LINE Pay 認證 | 顧客在 LINE App / 彈出網頁登入、確認內容、輸入付款密碼、最終確認 |
| 3 | 付款授權 | 顧客被導回 `confirmUrl`（GET，帶 `transactionId`、`orderId`）→ 商店伺服器 `POST /v4/payments/{transactionId}/confirm` |
| 4 | 請款 | 預設授權時自動請款；`capture=false` 時另呼叫 capture。請款後不能 void，只能 refund |

## 2. 狀態機

`GET /v4/payments` 回應的 `info[].payStatus`：

| payStatus | 意義 | 可做的動作 |
|---|---|---|
| `AUTHORIZATION` | 完成付款授權（僅 capture=false 時會停在此狀態） | capture（≤ 授權金額）/ void |
| `CAPTURE` | 完成請款 | refund（全額 / 部分，可多次） |
| `EXPIRED_AUTHORIZATION` | 授權逾期（未在 `authorizationExpireDate` 前請款，系統自動取消） | 重新付款 |
| `VOIDED_AUTHORIZATION` | 已取消授權 | — |

`transactionType`：`PAYMENT`、`PAYMENT_REFUND`（全額退款）、`PARTIAL_REFUND`（部分退款）。

```
request ──(顧客認證)──> [confirmUrl 被呼叫 / 狀態 0110] ──confirm──┬─ capture=true (預設) ─> CAPTURE ──refund──> PAYMENT_REFUND / PARTIAL_REFUND
                 │                                              └─ capture=false ─> AUTHORIZATION ──capture──> CAPTURE
                 └─(顧客取消/逾時 → cancelUrl / 狀態 0121)                          ├─void──> VOIDED_AUTHORIZATION
                                                                                    └─(逾期)─> EXPIRED_AUTHORIZATION
```

狀態查詢 API（`GET /v4/payments/requests/{transactionId}/check`）的 returnCode：
`0000` 尚未認證 → `0110` 已認證可 confirm → `0121` 取消/逾時；`0122` 付款失敗；`0123` 付款完成。官方建議輪詢間隔 ≥ 1 秒。

> 2026-11-19 sandbox 實測：認證完成時回 `0110 authentication is done.`，但**`0110` 不保證還能 confirm**——建立請求約 20 分鐘後 confirm 回 `1180 Expired the payment date`，交易隨即變 `0122`（推論有效期約 20 分鐘，官方未寫精確值）。未認證時回 `0000 reserved transaction.`（另一輪實測：顧客未在期限內認證時，建單後 19 分 17 秒仍是 `0000`、21 分 45 秒變 `0121 canceled transaction`——付款請求整體有效期約 20 分鐘，推論）；**在未認證時呼叫 confirm（得 `1169`）後，狀態變成 `0122 failed transaction`，該筆作廢**。不要用 confirm 探測付款狀態。

## 3. 基本付款（自動請款）

1. 顧客下單 → 商店伺服器呼叫付款請求：

```json
{
  "amount": 100,
  "currency": "TWD",
  "orderId": "EXAMPLE_ORDER_20230422_1000001",
  "packages": [
    {
      "id": "1",
      "amount": 100,
      "products": [
        { "id": "PEN-B-001", "name": "Pen Brown", "imageUrl": "https://store.example.com/images/pen_brown.jpg", "quantity": 2, "price": 50 }
      ]
    }
  ],
  "redirectUrls": {
    "confirmUrl": "https://store.example.com/order/payment/authorize",
    "cancelUrl": "https://store.example.com/order/payment/cancel"
  }
}
```

金額規則（官方欄位定義）：`amount` = Σ `packages[].amount`；`packages[].amount` = Σ (`products[].price` × `products[].quantity`)。

2. 回應 `info.paymentUrl`：
   - **PC**：以彈出視窗開 `info.paymentUrl.web`（建議寬 700px × 高 546px）或整頁導向。
   - **行動裝置**：用 `info.paymentUrl.app` deep link 開 LINE App。
   - **Sandbox 不支援 App**：只能用 `paymentUrl.web` 測試（FAQ）。2026-11-19 實測 sandbox 付款頁標示「LINE Pay Simulation」，須以 LINE 帳號登入（`access.line.me` email / 密碼）或用手機 LINE 掃 QR，沒有免登入的模擬按鈕。
   - 回應另含 `paymentAccessToken`（不用掃描器時在 LINE Pay App 輸入的代碼）。2026-11-19 實測 sandbox 回應**沒有** `paymentUrl.universal`；帶 `locale: "zh_TW"` 時 `paymentUrl.web` 結尾附 `&locale=zh-TW_LP`。
3. 顧客完成認證 → LINE Pay 以 GET 呼叫 `confirmUrl?orderId=…&transactionId=…`（顧客取消則呼叫 `cancelUrl`）。顧客可隨時取消認證流程。
4. 商店伺服器在 confirmUrl handler 內呼叫 confirm（body：`{ amount, currency }`，與付款請求相同金額），成功即完成付款（自動請款），顯示完成頁。
5. **自動請款時無法 void，只能 refund。**

**2026-11-19 sandbox 實測 confirm 成功回應**（v4，信用卡）：`info.transactionId`、`info.orderId`、`info.payInfo[]`（`method: "CREDIT_CARD"`、`amount`、`maskedCreditCardNumber: "************1111"`——sandbox 也回遮罩卡號）、`info.paymentProvider: "TSP"`、`info.packages[]`（含 `products[]` 與 **`userFeeAmount: 0`**，v4 文件已移除此欄位但實際仍回傳）。confirm 後查狀態回 `0123 completed transaction`；同一筆再 confirm 回 `1172 Already confirmed transaction`。

confirm 成功回應重點欄位：`info.orderId`、`info.transactionId`、`info.payInfo[]`（`method`: `BALANCE` / `CREDIT_CARD` / `POINT`，可能多筆組合）、`info.paymentProvider`（`TSP` / `EPI`，null 視為 TSP）、台灣限定 `info.payInfo[].maskedCreditCardNumber`（需另申請）、`info.merchantReference.affiliateCards[]`（電子發票手機條碼載具 `cardType = "MOBILE_CARRIER"`，需聯絡 LINE Pay 開通）。

## 4. 授權與請款分開（capture=false）

> **台灣預設自動請款，未經事先申請不能分開請款**——要用請先聯絡 LINE Pay 負責人。
> 2026-11-19 sandbox 實測：自助申請的 sandbox 商店帶 `capture: false` 會在付款請求階段被拒 `` 2103 Parameter is not allowed. [capture:`false`] ``（v3 / v4 皆同），所以**自助 sandbox 無法測分開請款**，capture / void 成功路徑未能實測。

- 付款請求帶 `options.payment.capture: false`。
- confirm 回應多 `info.authorizationExpireDate`（ISO 8601）；**期限前未請款自動取消**。
- capture：`POST /v4/payments/authorizations/{transactionId}/capture`，body `{ amount, currency }`。
  - 金額可 **≤ 授權金額**；小於時為部分請款，未請款餘額自動部分取消；**不可大於**付款請求金額。
  - 回 `1199` / `1280`–`1298` 時付款會自動取消。
- void：`POST /v4/payments/authorizations/{transactionId}/void`，無 body；僅限未請款前。請款後只能 refund。

## 5. 無 confirmURL（confirmUrlType）

`redirectUrls.confirmUrlType`（預設 `"CLIENT"`）：

| 值 | 行為 |
|---|---|
| `"CLIENT"` | 認證完成後在 LINE App / 彈出頁中把顧客的頁面導到 confirmUrl（預設） |
| `"NONE"` | 不提供重定向；LINE Pay 不會對商店發出任何 HTTP 請求。商店伺服器需**輪詢** `GET /v4/payments/requests/{transactionId}/check`（建議每 1 秒），拿到 `0110` 後自行 confirm |
| `"SERVER"` | LINE Pay 伺服器不在 App / 彈出頁內做頁面重導，而是**由 LINE Pay 伺服器另行呼叫** confirmUrl（server-to-server）。若 confirmUrl 有 IP 存取控管，需放行 LINE Pay IP（見 §9） |

`options.display.checkConfirmUrlBrowser`（預設 `false`）：`true` 時若顧客認證所在的瀏覽器不是發起付款的那一個，會引導回原瀏覽器再進 confirmUrl。

適用情境：POS / Kiosk 類不方便接收重定向的系統（NONE）；或想由伺服器端可靠接收授權通知（SERVER）。

## 6. 預先授權付款（Preapproved、regKey、定期定額）

> **台灣需事先申請才能使用預先授權付款**（聯絡 LINE Pay 負責人）；開發階段可用 sandbox 帳號測試。未開通會得 `1194`。

流程：

1. **簽發 regKey**：付款請求帶 `options.payment.payType: "PREAPPROVED"`（可同時帶 `options.regPayRequest` 設定定期定額規則，v4 新增）→ 顧客認證 → confirm 回應帶 `info.regKey`（例 `"RK2AE3519XTFXHM"`）。官方範例首次付款 `packages[].amount: 0`、`products[].price: 0` 但 `amount: 1000`——**2026-11-19 sandbox 實測照抄會回 `2101`**（`form.amount != sum(packages[].amount) + …`）。全部改 0（`amount: 0`、`packages[].amount: 0`、`price: 0`）**請求與 confirm 都成功**（confirm 帶 `amount: 0`）：回應含 `regKey`（例 `RK837FC19XTI1YK`）、`payInfo[]`（`CREDIT_CARD`、`amount: 0`、`creditCardBrand`、`creditCardNickname`、`maskedCreditCardNumber`），**但沒有 `transactionId`、也沒有 `paymentProvider`**；該綁定交易查明細回 `1150`。
2. **（選用）檢查 regKey**：`GET /v4/payments/preapprovedPay/{regKey}/check` → `0000` 可用（實測 `0000 Success.`；未開通 `creditCardAuth` 的商店帶 `creditCardAuth=true` 回 `2103`）；`?creditCardAuth=true` 會做 LINE Pay 驗證 + 信用卡最低金額授權驗證（需 LINE Pay 審核，TW only）。v3 文件寫此參數預設 `true`，v4 文件未寫預設值。
3. **扣款**：`POST /v4/payments/preapprovedPay/{regKey}/payment`，body：`productName`、`amount`（必填）、`currency`、`orderId`（必填，唯一）、`capture`（預設 true）。**不經顧客認證、不需 confirm**，直接完成付款；`capture: false` 時回 `authorizationExpireDate`，需在期限前 capture。
4. 回 `1141`、`1282`–`1287`、`1290`–`1295` → **regKey 被註銷**，需重新走步驟 1。
5. **銷毀 regKey**：顧客解約 / 不再提供服務時，`POST /v4/payments/preapprovedPay/{regKey}/expire`（無 body）。

`options.regPayRequest`（v4 新增，僅 `payType = "PREAPPROVED"` 有意義）：

| 欄位 | 說明 |
|---|---|
| `regPayPeriodType` | `"RECURRING"` 定期定額 / `"NON_RECURRING"` 一般預先授權 |
| `perTransactionLimit` | 單筆扣款上限，只在 `NON_RECURRING` 有效 |
| `productPrice` | 扣款金額 |
| `recurringPeriod` | `RECURRING` 時：`"WEEK"` / `"MONTH"` / `"YEAR"` |
| `recurringDayOfWeek` | `WEEK` 時：`"SUN"`…`"SAT"`，否則 `null` |
| `recurringDay` | `MONTH` / `YEAR` 時：1–31（需為該月有效日），否則 `null` |
| `recurringMonth` | `YEAR` 時：1–12，否則 `null` |

> 文件沒說 LINE Pay 會依 `regPayRequest` 自動扣款；扣款仍由商店伺服器排程呼叫 preapproved payment API。2026-11-19 sandbox 實測：帶 `regPayRequest`（RECURRING / MONTH / day 5 / productPrice 10）綁定後，**confirm 回應沒有任何定期規則欄位**，綁定交易也查不到明細，商店端完全拿不到 LINE Pay 端的規則回饋。LINE Pay 是否會依此自動扣款 **[待確認：需保留一個有效 regKey 等到扣款日觀察；本次測試已 expire]**。
>
> 2026-11-19 sandbox 付款請求階段實測：RECURRING / MONTH / day 5、NON_RECURRING + `perTransactionLimit` 都 `0000`；`recurringDay: 40`（超出 1–31）**也 `0000`，請求階段不驗範圍**，商店端要自己先檢查；`recurringPeriod: "DAILY"` 這類非列舉值回 `2102`；沒帶 `payType: PREAPPROVED` 卻帶 `regPayRequest` 也 `0000`（推測被忽略）。

### 6.1 對應 WooCommerce Subscriptions 的實作建議

> 依官方文件與 2026-11-19 sandbox 實測整理的設計建議，非官方規定。

- **續約由商店排程主動呼叫 Pay Preapproved**：在 `woocommerce_scheduled_subscription_payment_{gateway_id}` hook 內，用訂閱存的 regKey 呼叫 `POST /v4/payments/preapprovedPay/{regKey}/payment`（每次續約用新的 `orderId`，例如續約訂單 ID）。
- **不要依賴 `regPayRequest` 自動扣款**：官方文件沒說 LINE Pay 會依此規則自動扣款；實測只確認請求階段接受、不驗範圍。可以帶上作為規則揭示，但扣款時間由 WooCommerce Subscriptions 的排程決定。
- **regKey 存在訂閱（`WC_Subscription`）的 meta**，不要只存在父訂單，換付款方式或續約時才讀得到。顧客取消訂閱或改用其他付款方式時，呼叫 `expire` 銷毀舊 regKey。
- **扣款回 `1141`、`1282`–`1287`、`1290`–`1295` 時 regKey 已被註銷**：把續約訂單標為失敗，訂閱轉 on-hold，引導顧客重新綁定（再走一次 `payType: PREAPPROVED` 付款請求）。其他失敗碼（如 `1142` 餘額不足、`1280` 暫時錯誤）可交給 Subscriptions 的重試規則。
- **扣款前可先 Check RegKey**（回 `0000` 才扣），以減少失敗訂單；帶 `creditCardAuth=true` 需 LINE Pay 審核，未開通回 `2103`（實測）。
- **regKey 失效的判斷**：expire 之後 Check RegKey / Pay Preapproved 都回 `1193 The regKey expired.`（實測），訂閱端遇 `1190` / `1193` 都應要求重新綁定。
- **首次綁定**：可用 0 元綁定（2026-11-19 sandbox 實測請求與授權階段都接受，confirm 回 `regKey`）或直接收第一期費用；首期付款仍走一般認證流程，confirmUrl handler 必須立即 confirm（約 20 分鐘內，見 §2 實測）。
- 台灣正式環境需事先向 LINE Pay 申請開通預先授權付款，未開通回 `1194`。

## 7. 退款

- `POST /v4/payments/{transactionId}/refund`；body `refundAmount` 省略 = **全額退款**；帶金額 = 部分退款（可多次，直到退完）。
- 用**原付款**的 transactionId（不是退款交易 ID）。
- 回應 `info.refundTransactionId`、`info.refundTransactionDate`。
- 2026-11-19 sandbox 實測（TWD 10 元）：部分退 3 元 `0000` → 不帶 body 退剩餘 `0000`（退 7 元）→ 明細 `refundList` 兩筆，**兩筆的 `transactionType` 都是 `PARTIAL_REFUND`**（-3、-7；先部分退後再「全額退剩餘」不會記成 `PAYMENT_REFUND`），`payStatus` 仍是 `CAPTURE`。一次全額退（Pay Preapproved 的 10 元）則記為 `PAYMENT_REFUND` -10。退款回應實際多一個 `info.paymentProvider`（文件未列）。退完再退回 `1165`。對已請款交易 void 回 `1179`。
- 限制：超過可退款期限 `1163`；超額 `1164`；已全退 `1165`；未請款狀態 `1179`（應改用 void）；EPI 交易商家退款準備金不足 `2042`。
- 退款與取消授權不同：退款屬已結算交易，**可能產生手續費**（glossary）。

## 8. 查詢付款明細

- `GET /v4/payments?transactionId=…&orderId=…`；兩者至少帶一個，可重複 key 查多筆（`orderId=A&orderId=B`），**一次最多 100 筆**（超過 `1177`）。
- 可查已授權或已請款的交易；有退款時含 `refundList[]`（`refundAmount` 範例為負值）。
- 2026-11-19 sandbox 實測明細欄位：`transactionDate`、`transactionType: "PAYMENT"`、`productName`、`currency`、`authorizationExpireDate`（自動請款時**等於** `transactionDate`）、`paymentProvider`、`payInfo[]`、`orderId`、`payStatus: "CAPTURE"`、`packages[]`（含 `products[]` 與 `userFeeAmount`；Pay Preapproved 的交易沒有 `packages`）。以退款交易 ID 查詢回該退款交易：`transactionType: "PARTIAL_REFUND"`、`payInfo[].amount` 為**正數**、`originalTransactionId`、`payStatus: "CAPTURE"`。
- **0 元 PREAPPROVED 綁定交易查不到明細**（confirm 成功後以其 transactionId 查詢仍回 `1150`）。
- **尚未 confirm 的付款請求查不到**：2026-11-19 sandbox 實測回 `1150 Transaction record not found.`；這階段請用查詢付款請求狀態 API。
- 用退款交易 ID 查詢時，回傳該退款交易（`transactionType = PARTIAL_REFUND / PAYMENT_REFUND`、`originalTransactionId` = 原交易）。
- 指南敘述「也可以只查詢交易訊息或訂單訊息」，但現行 v4 / v3 參數表都沒有對應欄位 **[待確認，不要使用未列出的參數]**。

## 9. 重定向頁面規格（confirmUrl / cancelUrl）

- 商家頁面需使用受信任憑證的 HTTPS（建議），**TLS 1.2 以上**。
- LINE Pay 呼叫條件：HTTP **GET**；Connection timeout **5 秒**；Read timeout **20 秒**。
- 自動附加 query：`orderId`、`transactionId`（confirmUrl：一定帶；cancelUrl：有條件帶）。
  - **不要**自己在 confirmUrl / cancelUrl 預先放這兩個參數；但若你的 URL 已含 `orderId`，LINE Pay 只會補上剩下的參數。
  - 官方範例 URL 拼成 `trasactionId`（筆誤），實際參數名是 `transactionId`。
  - transactionId 是 19 位整數，取 query 時當字串處理。
- confirmUrl handler 職責：用 query 的 transactionId 呼叫 confirm（amount/currency 從**自家訂單資料**取，不信任前端），成功後顯示完成頁。
- cancelUrl handler：告知顧客已取消，或導回付款前頁面。

**IP 白名單**（confirmUrl 有 IP 存取控管時要放行；v3 文件特別註明是 `confirmUrlType=SERVER` 的情境）：

| 環境 | IP |
|---|---|
| Sandbox | `147.92.159.209`、`147.92.159.21`、`147.92.159.68`、`147.92.216.167` |
| Production | `211.249.40.1` ~ `211.249.40.30`、`147.92.220.5` ~ `147.92.220.8` |
