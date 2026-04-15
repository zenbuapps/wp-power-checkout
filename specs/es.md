# Event Storming: Power Contract

> WordPress 線上簽合約 & 審批外掛。管理者建立合約模板（含 shortcode 欄位），前台用戶填寫、簽名送出，管理者審核。可選整合 WooCommerce 結帳流程。
> **版本:** 0.0.12 | **文件日期:** 2026-04-09

---

## Actors

- **管理員** [人]: WordPress 後台管理者，建立合約模板、審核合約（核准/拒絕/批量操作）、管理設定
- **顧客** [人]: 前台用戶，瀏覽合約模板頁面、填寫欄位、簽名、送出合約
- **系統** [系統]: WordPress 系統，處理狀態轉換事件、寄送 Email 通知
- **WooCommerce** [系統]: WooCommerce 結帳流程，觸發結帳前/後合約重導

---

## Aggregates

### ContractTemplate（合約模板）

> CPT: `contract_template`，使用 WordPress Block Editor 編輯合約內容

| 屬性 | 說明 |
|------|------|
| `ID` | WordPress post ID |
| `post_title` | 合約模板名稱 |
| `post_content` | 合約內容（含 shortcodes） |
| `post_status` | publish / draft |
| `seal_url` (meta) | 公司章圖片 URL |

### Contract（合約）

> CPT: `contract`，由前台簽約 AJAX 自動建立

| 屬性 | 說明 |
|------|------|
| `ID` | WordPress post ID |
| `post_title` | `{模板名} 合約 - {用戶名} 對應 user_id: #{id}` |
| `post_status` | pending / approved / rejected |
| `post_author` | 簽約用戶 ID（guest 為 0） |
| `contract_template_id` (meta) | 來源合約模板 ID |
| `user_name` (meta) | 用戶填寫姓名 |
| `user_address` (meta) | 用戶填寫地址 |
| `user_identity` (meta) | 用戶填寫身分證字號 |
| `user_phone` (meta) | 用戶填寫手機號碼 |
| `contract_amount` (meta) | 合約金額 |
| `signature` (meta) | 簽名 base64 data URL |
| `screenshot_url` (meta) | 完整合約截圖 URL（上傳至 WP Media） |
| `_order_id` (meta) | 關聯 WooCommerce 訂單 ID |
| `client_ip` (meta) | 簽署時的 IP 地址 |

### PowerContractSettings（外掛設定）

> wp_options key: `power_contract_settings`

| 屬性 | 說明 |
|------|------|
| `ajax_signed_title` | 簽約完成 Modal 標題 |
| `ajax_signed_description` | 簽約完成 Modal 描述 |
| `ajax_signed_btn_text` | Modal 按鈕文字（空=隱藏） |
| `ajax_signed_btn_link` | Modal 按鈕連結 |
| `display_order_info` | 是否自動帶入訂單資訊 |
| `display_contract_before_checkout` | 結帳前顯示合約 |
| `display_contract_after_checkout` | 感謝頁前顯示合約 |
| `emails` | 通知信收件人陣列 |
| `chosen_contract_template` | 結帳流程使用的合約模板 ID |

---

## Commands

### CreateContract（建立合約）

- **Actor**: 顧客
- **Aggregate**: Contract
- **Predecessors**: 顧客在合約模板頁面填寫完所有欄位並簽名
- **參數**: `contract_template_id`, `user_name`, `user_address`, `user_identity`, `user_phone`, `contract_amount`, `signature`, `screenshot`, `_order_id?`, `_redirect?`
- **Description**:
  - **What**: 透過 AJAX 建立一份新合約（pending 狀態）
  - **Why**: 記錄顧客的簽約內容與簽名截圖
  - **When**: 顧客在前台合約頁面點擊「送出」按鈕

#### Rules

- 前置（參數）: nonce 驗證必須通過（`check_ajax_referer`）
- 前置（參數）: `contract_template_id` 為必填
- 後置（狀態）: 合約建立，狀態為 `pending`
- 後置（狀態）: screenshot base64 上傳為 WP media，URL 存入 `screenshot_url` meta
- 後置（事件）: 觸發 `power_contract_contract_created` action
- 後置（事件）: 觸發 Email 通知（寄到 settings.emails）
- 後置（回應）: 回傳 `redirect_url`（根據 `_redirect` 參數決定）

---

### ApproveContract（核准合約）

- **Actor**: 管理員
- **Aggregate**: Contract
- **Predecessors**: 合約為 pending 狀態
- **參數**: `post_id` (int)
- **Description**:
  - **What**: 將合約狀態從 pending 改為 approved
  - **Why**: 管理員審閱合約內容後核准
  - **When**: 管理員點擊合約詳情頁的「Approve」按鈕

#### Rules

- 前置（參數）: `post_id` 必須是 contract post type
- 後置（狀態）: 合約狀態改為 `approved`
- 後置（事件）: 觸發 `power_contract_contract_approved` action
- 後置（導向）: 重導到合約列表頁

---

### RejectContract（拒絕合約）

- **Actor**: 管理員
- **Aggregate**: Contract
- **Predecessors**: 合約為 pending 狀態
- **參數**: `post_id` (int)
- **Description**:
  - **What**: 將合約狀態從 pending 改為 rejected
  - **Why**: 管理員審閱合約內容後拒絕
  - **When**: 管理員點擊合約詳情頁的「Reject」按鈕

#### Rules

- 前置（參數）: `post_id` 必須是 contract post type
- 後置（狀態）: 合約狀態改為 `rejected`
- 後置（事件）: 觸發 `power_contract_contract_rejected` action
- 後置（導向）: 重導到合約列表頁

---

### BatchChangeContractStatus（批量變更合約狀態）

- **Actor**: 管理員
- **Aggregate**: Contract
- **Predecessors**: 管理員在合約列表頁勾選多筆合約
- **參數**: `action` (change-to-pending | change-to-approved | change-to-rejected), `post_ids[]`
- **Description**:
  - **What**: 批量變更所選合約的狀態
  - **Why**: 管理員需要一次處理多筆合約
  - **When**: 管理員在合約列表頁使用 Bulk Actions 下拉選單

#### Rules

- 後置（狀態）: 所有選取的合約狀態更新
- 後置（回應）: 顯示 admin notice 通知變更數量

---

### UpdateSettings（更新外掛設定）

- **Actor**: 管理員
- **Aggregate**: PowerContractSettings
- **參數**: 設定欄位 key-value pairs
- **Description**:
  - **What**: 更新 Power Contract 外掛設定
  - **Why**: 管理員需要調整簽約完成訊息、WooCommerce 整合、Email 通知等設定
  - **When**: 管理員在設定頁面儲存表單

#### Rules

- 前置（權限）: `manage_options` capability
- 後置（狀態）: `power_contract_settings` option 被更新

---

### SaveContractTemplateSeal（儲存合約模板公司章）

- **Actor**: 管理員
- **Aggregate**: ContractTemplate
- **參數**: `post_id`, `seal` (file upload)
- **Description**:
  - **What**: 上傳公司章圖片並儲存到合約模板的 `seal_url` meta
  - **Why**: 合約模板需要公司章圖片供前台 `[pct_seal]` shortcode 顯示
  - **When**: 管理員在合約模板編輯頁面上傳公司章

#### Rules

- 前置（參數）: nonce 驗證通過
- 前置（權限）: `edit_post` capability
- 後置（狀態）: 檔案上傳至 WP media，URL 存入 `seal_url` post meta

---

## Read Models

### ContractList（合約列表）

- **Actor**: 管理員
- **Aggregates**: Contract
- **欄位**: title, user_name, status (pending/approved/rejected), order_id (if WooCommerce)
- **Description**: 在 WordPress admin 合約列表頁顯示所有合約

### ContractDetail（合約詳情）

- **Actor**: 管理員
- **Aggregates**: Contract
- **欄位**: 所有 meta 以 key-value 表格呈現，含簽名圖片、截圖連結、WC 訂單資訊
- **Description**: 在合約編輯頁面顯示完整合約內容

### MyAccountContracts（我的帳號合約）

- **Actor**: 顧客
- **Aggregates**: Contract
- **欄位**: screenshot_url, post_status (status tag)
- **Description**: 在 WooCommerce 我的帳號 > 訂單頁面顯示該訂單關聯的合約截圖與狀態

### OrderContractColumn（訂單合約欄位）

- **Actor**: 管理員
- **Aggregates**: Contract
- **欄位**: contract IDs (linked)
- **Description**: 在 WooCommerce 訂單列表顯示關聯合約 ID 連結（HPOS compatible）

---

## Domain Events

| Event | Trigger | Side Effects |
|---|---|---|
| `power_contract_contract_created` | Contract 建立後 | Email 通知 |
| `power_contract_contract_pending` | Contract 狀態變為 pending | (extensible) |
| `power_contract_contract_approved` | Contract 狀態變為 approved | (extensible) |
| `power_contract_contract_rejected` | Contract 狀態變為 rejected | (extensible) |
