# Event Storming: Power Checkout

> WooCommerce 結帳整合外掛，串接 Shopline Payment 金流、光貿電子發票，並提供結帳欄位擴充。
> **版本:** 1.0.28 | **文件日期:** 2026-03-11

---

## Actors

- **管理員** [人]: WordPress 後台管理者，管理金流/發票設定、手動退款、手動開立/作廢發票
- **顧客** [人]: WooCommerce 前台消費者，進行結帳付款、填寫發票資訊
- **Shopline Payment** [外部系統]: 第三方金流服務商，透過 Webhook 通知付款/退款結果
- **WooCommerce** [系統]: 訂單狀態變更時自動觸發開立/作廢發票

---

## Aggregates

### ProviderSettings（服務設定）

> 儲存於 `wp_options` 表，option_name 為 `woocommerce_{provider_id}_settings`

| 屬性 | 說明 |
|------|------|
| `enabled` | 是否啟用（`yes` / `no`） |
| `title` | 前台顯示標題 |
| `description` | 前台顯示描述 |
| `mode` | 模式（`test` / `prod`） |
| `icon` | 圖示 URL |
| _（其餘因 provider 而異）_ | 見各 provider DTO |

**已知 Provider IDs:**
- `shopline_payment_redirect` — Shopline Payment 跳轉支付
- `amego` — 光貿電子發票

### ShoplinePaymentSettings（SLP 金流設定）

> 儲存於 `wp_options`，option_name = `woocommerce_shopline_payment_redirect_settings`

| 屬性 | 說明 |
|------|------|
| `platformId` | SLP 平台 ID |
| `merchantId` | SLP 特店 ID |
| `apiKey` | API 介面金鑰 |
| `clientKey` | 客戶端金鑰 |
| `signKey` | Webhook 簽名密鑰 |
| `apiUrl` | SLP API 端點 |
| `allowPaymentMethodList` | 允許的付款方式列表 |
| `paymentMethodOptions` | 付款方式選項（如分期期數） |
| `expire_min` | 付款期限（分鐘） |
| `min_amount` | 最小金額 |
| `max_amount` | 最大金額 |
| `order_button_text` | 付款按鈕文字 |

### AmegoSettings（光貿電子發票設定）

> 儲存於 `wp_options`，option_name = `woocommerce_amego_settings`

| 屬性 | 說明 |
|------|------|
| `invoice` | 商家統一編號 |
| `app_key` | 光貿 API 金鑰 |
| `tax_rate` | 稅率（預設 0.05） |
| `auto_issue_order_statuses` | 自動開立發票的訂單狀態列表 |
| `auto_cancel_order_statuses` | 自動作廢發票的訂單狀態列表 |

### WC_Order（WooCommerce 訂單）

> WooCommerce 內建訂單（HPOS 或傳統 `wp_posts`），Power Checkout 使用以下 order meta：

| Meta Key | 說明 |
|----------|------|
| `_pc_identity` | 第三方金流識別碼（SLP sessionId） |
| `_pc_payment_identity` | 付款識別碼（SLP tradeOrderId），防重複處理 |
| `_pc_payment_detail` | 付款詳情（array） |
| `_pc_refund_detail` | 退款詳情（array） |
| `_pc_issued_invoice_data` | 電子發票開立回傳資料（array） |
| `_pc_cancelled_invoice_data` | 電子發票作廢回傳資料（array） |
| `_pc_invoice_provider_id` | 使用的發票 Provider ID |
| `_pc_issue_invoice_params` | 結帳頁填寫的發票資訊（JSON） |
| `_pc_tax_type` | 商品稅別（發票用） |
| `tmp_refund_reason` | 暫存退款原因（等待 Webhook 回傳後使用） |

### PowerCheckoutSettings（全域設定）

> 儲存於 `wp_options`，option_name = `power_checkout_settings`

| 屬性 | 說明 |
|------|------|
| _(尚未使用，為日後全域設定預留)_ | — |

---

## Commands

### GetAllSettings（取得所有設定）

- **Actor**: 管理員
- **Aggregate**: ProviderSettings
- **Predecessors**: 無
- **參數**: 無
- **Description**:
  - **What**: 取得所有金流/發票/物流 provider 的設定清單
  - **Why**: 在設定頁面顯示所有服務的啟用狀態與設定
  - **When**: 管理員進入 Power Checkout 設定頁時

#### Rules

- 後置（回應）: 回傳 `gateways`、`invoices`、`logistics` 三個分類的設定陣列

---

### GetProviderSettings（取得單一服務設定）

- **Actor**: 管理員
- **Aggregate**: ProviderSettings
- **Predecessors**: 無
- **參數**: `provider_id` (string)
- **Description**:
  - **What**: 取得指定 provider 的詳細設定
  - **Why**: 編輯特定金流/發票服務時需要載入完整設定
  - **When**: 管理員點擊某個 provider 進入其設定頁面

#### Rules

- 前置（參數）: `provider_id` 必須對應到已啟用且存在於容器中的 provider
- 後置（回應）: 回傳該 provider 完整設定 array

---

### UpdateProviderSettings（更新服務設定）

- **Actor**: 管理員
- **Aggregate**: ProviderSettings
- **Predecessors**: 無
- **參數**: `provider_id` (string), 設定欄位 key-value pairs
- **Description**:
  - **What**: 更新指定 provider 的設定值
  - **Why**: 管理員需要修改金流/發票服務的 API 金鑰、模式等設定
  - **When**: 管理員在設定頁面儲存表單

#### Rules

- 前置（參數）: `provider_id` 必須有效
- 前置（參數）: 所有參數值經過 `sanitize_text_field_deep` 消毒
- 後置（狀態）: `woocommerce_{provider_id}_settings` option 被更新

---

### ToggleProvider（開關服務）

- **Actor**: 管理員
- **Aggregate**: ProviderSettings
- **Predecessors**: 無
- **參數**: `provider_id` (string)
- **Description**:
  - **What**: 切換指定 provider 的啟用/停用狀態
  - **Why**: 管理員需要快速啟用或停用某金流/發票服務
  - **When**: 管理員點擊 provider 的開關按鈕

#### Rules

- 前置（參數）: `provider_id` 必須有效
- 後置（狀態）: `woocommerce_{provider_id}_settings` 中的 `enabled` 值在 `yes`/`no` 之間切換

---

### ProcessPayment（處理付款）

- **Actor**: 顧客
- **Aggregate**: WC_Order
- **Predecessors**: 顧客在結帳頁選擇 Shopline Payment 並提交訂單
- **參數**: `order_id` (int)
- **Description**:
  - **What**: 建立 SLP Session 並將顧客導向 Shopline Payment 付款頁面
  - **Why**: 顧客需要透過第三方金流完成付款
  - **When**: WooCommerce 呼叫 `process_payment` 時

#### Rules

- 前置（狀態）: 訂單必須存在
- 前置（狀態）: Gateway 必須啟用且訂單金額在 min_amount ~ max_amount 範圍內
- 後置（狀態）: 訂單加入 order note「Pay via Shopline Payment 線上付款」
- 後置（狀態）: 扣減庫存
- 後置（狀態）: 若 Session 已過期 → 訂單狀態改為 `cancelled`

---

### HandleWebhook（處理 Webhook 通知）

- **Actor**: Shopline Payment（外部系統）
- **Aggregate**: WC_Order
- **Predecessors**: SLP 完成付款/退款處理後發送 Webhook
- **參數**: Webhook body（含 eventType, data 等）, Headers（timestamp, sign, apiVersion）
- **Description**:
  - **What**: 接收 SLP Webhook 通知，根據付款/退款狀態更新訂單
  - **Why**: 非同步確認付款結果，確保訂單狀態與金流狀態一致
  - **When**: SLP 系統向 `/wp-json/power-checkout/slp/webhook` 發送 POST

#### Rules

- 前置（參數）: timestamp 與伺服器時間差不超過 5 分鐘
- 前置（參數）: HMAC-SHA256 簽章驗證通過（`hash_hmac('sha256', "{timestamp}.{body}", signKey)`）
- 前置（參數）: 透過 `tradeOrderId` 找到對應的 WC_Order
- 後置（狀態）: 付款成功（SUCCEEDED） → 訂單狀態改為 `processing`
- 後置（狀態）: 付款過期（EXPIRED） → 訂單狀態改為 `cancelled`
- 後置（狀態）: 其他狀態 → 訂單狀態改為 `pending`
- 後置（狀態）: 退款失敗 → 刪除最近一筆 WC_Order_Refund
- 後置（狀態）: 退款成功 → 記錄退款詳情到 order meta 與 order note
- 後置（回應）: 始終回傳 HTTP 200（避免 SLP 重試）

---

### Refund（Gateway 退款）

- **Actor**: 管理員
- **Aggregate**: WC_Order
- **Predecessors**: 訂單已完成付款
- **參數**: `order_id` (int)
- **Description**:
  - **What**: 透過原付款 Gateway 執行全額退款（剩餘可退金額）
  - **Why**: 管理員需要透過金流 API 進行退款
  - **When**: 管理員在訂單詳情頁點擊退款按鈕

#### Rules

- 前置（參數）: `order_id` 必須是數字
- 前置（狀態）: 訂單必須存在
- 前置（狀態）: 訂單尚有可退金額（`remaining_refund_amount > 0`）
- 前置（狀態）: 訂單的 Gateway 必須是 `AbstractPaymentGateway` 的實例
- 前置（狀態）: 付款方式支援退款（ATM 不支援；中租零卡僅支援全額退款；LINE Pay 支援部分+全額退款）
- 後置（狀態）: 建立 `WC_Order_Refund` 記錄
- 後置（狀態）: 呼叫 SLP 退款 API
- 後置（狀態）: 退款失敗 → 刪除 Refund 記錄、加入 order note

---

### ManualRefund（手動退款）

- **Actor**: 管理員
- **Aggregate**: WC_Order
- **Predecessors**: 無
- **參數**: `order_id` (int)
- **Description**:
  - **What**: 僅將訂單狀態改為「已退款」，不透過金流 API
  - **Why**: 管理員已在金流後台手動處理退款，只需同步 WC 訂單狀態
  - **When**: 管理員選擇手動退款

#### Rules

- 前置（參數）: `order_id` 必須是數字
- 前置（狀態）: 訂單必須存在
- 後置（狀態）: 訂單狀態改為 `refunded`

---

### IssueInvoice（開立電子發票）

- **Actor**: 管理員 / WooCommerce（自動觸發）
- **Aggregate**: WC_Order
- **Predecessors**: 訂單進入指定狀態（自動），或管理員手動點擊（API）
- **參數**: `order_id` (int), `provider` (string), `invoiceType`, `individual`, `carrier`, `moica`, `companyName`, `companyId`, `donateCode`
- **Description**:
  - **What**: 透過電子發票 Provider（Amego）開立電子發票
  - **Why**: 台灣法規要求開立電子發票
  - **When**: 訂單進入 `auto_issue_order_statuses` 中的狀態時自動觸發，或管理員在後台手動開立

#### Rules

- 前置（參數）: `provider` 必須是已啟用的 Invoice Provider
- 前置（狀態）: 訂單必須存在
- 前置（狀態）: 如果已經開立過發票（`_pc_issued_invoice_data` 有值），不重複開立，直接回傳已有資料
- 後置（狀態）: 發票開立成功 → 儲存回傳資料到 `_pc_issued_invoice_data`
- 後置（狀態）: 儲存使用的 provider id 到 `_pc_invoice_provider_id`
- 後置（狀態）: 儲存開立參數到 `_pc_issue_invoice_params`

---

### CancelInvoice（作廢電子發票）

- **Actor**: 管理員 / WooCommerce（自動觸發）
- **Aggregate**: WC_Order
- **Predecessors**: 該訂單已開立過發票
- **參數**: `order_id` (int)
- **Description**:
  - **What**: 透過電子發票 Provider（Amego）作廢已開立的電子發票
  - **Why**: 訂單退款或取消時需要作廢對應的電子發票
  - **When**: 訂單進入 `auto_cancel_order_statuses` 中的狀態時自動觸發（預設 `wc-refunded`），或管理員手動作廢

#### Rules

- 前置（狀態）: 訂單必須存在
- 前置（狀態）: 訂單必須有對應的 `_pc_invoice_provider_id`
- 前置（狀態）: Provider 必須是 `IInvoiceService` 實例
- 前置（狀態）: 如果已經作廢過（`_pc_cancelled_invoice_data` 有值），不重複作廢
- 後置（狀態）: 清除 `_pc_issue_invoice_params`、`_pc_issued_invoice_data`、`_pc_invoice_provider_id`
- 後置（狀態）: 儲存作廢回傳資料到 `_pc_cancelled_invoice_data`

---

## Read Models

### GetAllSettings（取得所有設定）

- **Actor**: 管理員
- **Aggregates**: ProviderSettings
- **回傳欄位**: `gateways[]` (id, title, enabled, icon, method_title), `invoices[]` (同), `logistics[]`
- **Description**: 列出所有金流、發票、物流 provider 的清單與啟用狀態

#### Rules

- 後置（回應）: Gateway 清單從 `WC_Payment_Gateways` 取得已註冊的 Power Checkout gateway
- 後置（回應）: Invoice 清單從 `ProviderRegister::$invoice_providers` 建立 DTO

---

### GetProviderSettings（取得單一服務設定）

- **Actor**: 管理員
- **Aggregates**: ProviderSettings
- **回傳欄位**: provider 完整設定 array（因 provider 而異）
- **Description**: 取得指定 provider 的詳細設定值

#### Rules

- 前置（參數）: `provider_id` 必須在已啟用的容器中存在，否則拋出 Exception
