# Execution Plan — 結帳體驗強化（台灣地址/手機/姓名排序、虛擬商品免地址、虛擬自動完成、差多少免運）

> Phase 01-04 完成（2026-11-13）。起始狀態：**existing**。CiC = 0。
> 澄清紀錄與 AI 決策（D1-D14）：`specs/clarify/2026-11-13-1232.md`。用戶答案：Q1 B / Q2 A / Q3 A / Q4 A / Q5 C / Q6 yes / Q7 A。

## 結帳型態支援矩陣（定案）

| 功能 | 開關 key | 傳統結帳 | 區塊結帳 | 實作手段 |
|------|---------|---------|---------|---------|
| 姓在前名在後（TW 限定） | last_name_first | ✅ | ✅ | `woocommerce_get_country_locale` TW：last_name priority 10 / first_name 20 |
| 台灣縣市下拉 | tw_address | ✅ | ✅ | `woocommerce_states['TW']` 22 縣市（key=label=中文全名，「臺」） |
| 鄉鎮下拉 + 郵遞區號帶入 | tw_address | ✅ | ❌ | 傳統結帳頁 localize 對照資料 + 前端 JS（Playwright E2E 驗互動） |
| 台灣手機格式 `^09\d{8}$` | tw_phone_validation | ✅ | ✅ | 傳統 `woocommerce_after_checkout_validation`；區塊 Store API 結帳伺服器端（400） |
| 虛擬商品隱藏地址 | virtual_hide_address | ✅ | ✅（載入時判定） | 傳統 `woocommerce_checkout_fields`；區塊 locale hidden + required=false |
| 虛擬商品自動完成 | virtual_auto_complete | ✅ | ✅ | `woocommerce_order_status_changed` to=processing（含管理員手動） |
| 差多少免運 | free_shipping_hint | ✅ | ✅ | 傳統 hook 輸出 HTML（購物車 + 結帳運送區上方）；區塊 Store API cart extension `power-checkout.free_shipping_hint` + slot fill |

所有開關預設 `no`。既有 `DefaultSetting::modify_tw_address_formats` 維持永遠啟用、不改。

## 概覽

| 類型 | 數量 |
|------|------|
| Create | Activity 1、Feature 5（含 Examples）、Actor 1、句型.md 2、系統抽象.md 1、Clarify 1 |
| Modify | erm.dbml（1.5.0：+1 table、+2 enum）、api.yml（/settings/{provider_id} + 3 schemas） |
| Delete | 0 |

## Phase 02: Entity Modeling — ✅ 完成

| 操作 | 目標 |
|------|------|
| create | `wp_options_checkout_enhancement_settings`（10 鍵，含預設值） |
| create | Enum `yes_no`、`wc_free_shipping_requires`（none/coupon/min_amount/either/both） |
| — | 無新 order meta；自動完成只寫 order note |

## Phase 03: BDD Analysis — ✅ 完成

| 操作 | 目標 |
|------|------|
| create | `specs/features/系統抽象.md`（scope 僅本功能） |
| create | `specs/features/checkout/句型.md`、`specs/features/settings/句型.md` |
| create | 5 個 feature 的 Examples（已移除 `@ignore`） |

## Phase 04: API Contract — ✅ 完成

| 操作 | 目標 |
|------|------|
| modify | `GET/POST /settings/{provider_id}`：example `checkout_enhancement`、`403` 回應、anyOf 引用新 schema |
| create | `CheckoutEnhancementSettings`、`CheckoutEnhancementSettingsUpdate`、`FreeShippingHint`（Store API extension，非 PC 路徑） |
| 備註 | redocly lint 2 errors 為既有問題（/refund/manual nullable、另一處），與本次無關 |

## Phase 05-07: Implementation（TDD）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `inc/classes/Domains/Checkout/ProviderRegister.php` | `register_hooks()`，各 service 依開關掛 hook；Bootstrap 呼叫 |
| create | `Domains/Checkout/DTOs/CheckoutEnhancementSettingsDTO.php` | 預設值、鍵級驗證（yes/no、#RRGGBB、含 {price}）、`get()` 補齊預設 |
| create | `Services/NameOrderService`、`TwAddressService`、`PhoneValidationService`、`VirtualCartAddressService`、`VirtualOrderAutoCompleteService`、`FreeShippingHintService` | static hook callbacks、never-throw |
| create | `Domains/Checkout/Shared/Helpers/TwAdminDivision.php` | 22 縣市 → 鄉鎮 → 郵遞區號常數（可移植 woomp twzipcode 資料，改「臺」） |
| create | `Domains/Checkout/Shared/Helpers/CartInspector.php` | 全虛擬判定、TW 判定（共用契約） |
| modify | `Domains/Settings/Services/SettingApiService.php` | `checkout_enhancement` 特例：GET 以 DTO 補齊預設；POST 走 DTO 鍵級驗證後 merge（目前 POST 直接 update_option，GET 只回已存值） |
| create | 前端：`js/src/pages/Checkout/index.vue` + router `/checkout` + `ROUTER_MAPPER.checkout_enhancement` + App.vue 選單「結帳」 | Element Plus；woomp 並存提示文案 |
| create | 前端：傳統結帳鄉鎮下拉 JS；區塊結帳免運提示 slot fill（`inc/assets/blocks/` 或 Vue bundle 外掛點，由 planner 定） | |
| create | `tests/Integration/Checkout/*Test.php`、`tests/Integration/Settings/CheckoutEnhancementSettingsTest.php` | 每個 test 帶 smoke/happy/error/edge/security 之一 |
| create | `tests/e2e/02-frontend/`：鄉鎮下拉/郵遞區號帶入、區塊結帳免運提示呈現 | Playwright |
| modify | `Bootstrap.php`、`.claude/CLAUDE.md`、`.claude/rules/*` | 新 domain 文件 |

## 實作風險（交 planner）

1. **發票重複開立**：自動完成讓訂單連續經過 processing → completed；若 `auto_issue_order_statuses` 兩者皆含，需確認各 invoice provider（amego / ecpay / ezpay / paynow_invoice）是否已有「已開立則跳過」guard；沒有就要在 `Invoice\ProviderRegister::run_auto_action` 或 Checkout service 補（feature 已有 Example：只開 1 張）。
2. **區塊結帳動態 locale**：依購物車在 locale 設 `hidden`/`required=false`，需驗證目前 WC 版本的區塊 AddressForm 支援 `hidden`，且 Store API 結帳伺服器端驗證讀到的是同一份（同請求下購物車判定一致）的 locale；不一致會出現「前端隱藏但後端要求必填」。
3. **欄位排序連動**：姓在前改 TW locale priority，可能打亂 PC 既有 `CheckoutFields`（發票欄位）、物流選店欄位、ECPay AIO 分期欄位的相對位置；需回歸測試。另 woomp 並存站雙方同功能都開會重複驗證 / 重複提示（僅 UI 提示，不做偵測）。
