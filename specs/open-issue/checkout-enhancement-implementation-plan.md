# 實作計劃：結帳體驗強化（Checkout Enhancement）

> 產出：2026-11-13 planner。上游規格：`specs/open-issue/checkout-enhancement-execution-plan.md`（Phase 01-04 完成，CiC=0）、
> `specs/clarify/2026-11-13-1232.md`（D1-D14）、`specs/features/checkout/*.feature`（4）、`specs/features/settings/checkout-enhancement-settings.feature`、
> `specs/features/系統抽象.md`、`specs/features/checkout/句型.md`、`specs/erm.dbml` 1.5.0、`specs/api.yml`（CheckoutEnhancementSettings / FreeShippingHint）。
> 下游：`@zenbu-powers:tdd-coordinator`（AIBDD / TDD，Red → Green → Refactor）。

---

## 範圍模式：HOLD SCOPE

- 規格已凍結（用戶回答 Q1-Q7，AI 決策 D1-D14），本計劃**不擴張也不縮減**功能。
- 預估新增/修改正式碼約 20 檔（PHP 13、前端 6、文件 3），測試約 10 檔。超過 15 檔門檻，但範圍由規格明定、拆成 7 個可獨立合併的階段，不進 REDUCTION；遠低於 30 檔暫停門檻。
- 規劃途中「發現但不做」的事一律列在〈限制條件〉，不默默擴張。

## 概述

在新領域 `Domains/Checkout/` 實作 6 個可獨立開關（預設全關）的台灣結帳強化：姓在前名在後、台灣縣市/鄉鎮/郵遞區號、台灣手機格式檢查、全虛擬購物車免填地址、全虛擬訂單自動完成、差多少免運提示。設定以虛擬 provider id `checkout_enhancement` 重用 `GET/POST /settings/{provider_id}`，後台 Vue SPA 新增「結帳」選單。傳統結帳與區塊結帳各自依支援矩陣實作並分別驗證。

## 需求重述

| # | 子功能 | 開關 key | 傳統 | 區塊 | 主要 hook / 入口 |
|---|--------|---------|------|------|------------------|
| F1 | 姓在前名在後（TW 限定） | `last_name_first` | ✅ | ✅ | `woocommerce_get_country_locale`（TW：last_name 10 / first_name 20 + class 對調） |
| F2a | 台灣 22 縣市下拉 | `tw_address` | ✅ | ✅ | `woocommerce_states['TW']`（key = label = 中文全名，「臺」） |
| F2b | 鄉鎮下拉 + 郵遞區號帶入 | `tw_address` | ✅ | ❌ | 傳統結帳頁 enqueue `inc/assets/js/classic-tw-address.js` + localize 對照表 |
| F3 | 手機 `^09\d{8}$`（先去空白/連字號，存原值；運送電話有填也檢查） | `tw_phone_validation` | ✅ | ✅ | 傳統 `woocommerce_after_checkout_validation`；區塊 `woocommerce_store_api_checkout_update_order_from_request` 丟 `RouteException` 400 |
| F4 | 全虛擬購物車隱藏並改非必填 country/state/city/postcode/address_1/address_2 | `virtual_hide_address` | ✅ | ✅（載入時判定） | 傳統 `woocommerce_checkout_fields`（unset）；區塊 locale `hidden=true`+`required=false` |
| F5 | 全虛擬訂單轉處理中 → 自動已完成（含管理員手動） | `virtual_auto_complete` | — | — | `woocommerce_order_status_changed`（to=processing）→ **請求結束時**統一完成（見 R1） |
| F6 | 差多少免運提示 | `free_shipping_hint` | ✅ | ✅ | 傳統 `woocommerce_cart_totals_before_shipping` / `woocommerce_review_order_before_shipping`；區塊 Store API cart extension `power-checkout.free_shipping_hint` + slot fill |

成功的樣子：5 份 feature 的每個 Example 都有對應的 PHPUnit 或 Playwright 測試且全綠；開關全關時 WC 行為與原生完全相同；既有測試（基線見〈測試策略〉）無一由綠轉紅。

---

## 已知風險（研究結果，含本次程式碼驗證）

> 驗證環境：`.wp-env.json` → WooCommerce **10.1.4**、WP 7.0、PHP 8.3。

### R1（高）自動完成造成「狀態來回 + 已完成通知寄兩次」— 需求未處理，本計劃補上

- 證據：`Payuni/Managers/StatusManager.php:134-135`、`PayuniUniEmbed/...:137-138`、`Paynow/...:163-164`、`NewebpayMpg/...:114-115` 都是 `payment_complete()` 後**立刻** `update_status('processing')`。
- 若在 `woocommerce_order_status_changed` 當下就 `update_status('completed')`：processing → completed（自動完成）→ 金流再 update_status → completed → processing → 又自動完成。結果是已完成通知信寄兩次、多一組狀態備註、發票 hook 觸發兩次。
- 緩解（架構決策，非業務問題）：`VirtualOrderAutoCompleteService` 在 status_changed 只把 order_id **放進待處理清單**，並掛一次 `shutdown` 回呼 `flush_queue()`；flush 時重新 `wc_get_order()`，確認**最終狀態仍是 processing** 且全虛擬，才 `update_status('completed', 備註)`。每張訂單只完成一次。`flush_queue()` 設為 public static，測試直接呼叫。
- 管理員手動改狀態：儲存請求在 redirect 前就跑完 shutdown，重新導回後看到「已完成」，與 feature 一致。

### R2（中，已驗證有保護）發票重複開立

- 證據：amego `AmegoProvider::issue()` L99-105、ecpay `EcpayInvoiceProvider::issue()` L104-110、ezpay `EzpayInvoiceProvider::issue()` L100-106、paynow_invoice `PaynowInvoiceProvider::issue()` L123-129 都已經有「已有 `issued_data` 就直接回傳、不打 API」的冪等保護。`Invoice\ProviderRegister::run_auto_action` 是 provider-agnostic 的。
- 再加上 R1 的延後完成，processing 開立 → completed 時 provider 冪等跳過。**不需要改發票程式碼**，只補一支整合測試（feature「只開 1 張」）把保護鎖住。
- 測試陷阱：`ProviderRegister::register_provider_hooks()` 在 bootstrap 時依當下設定掛 `woocommerce_order_status_{status}`，測試在 set_up 後才寫設定，hook 不會自動出現。要比照 `tests/Integration/Invoice/InvoiceAutoIssueWrapperTest.php` 手動 `add_action(... auto_issue_wrapper)`；訂單要有合法的 `pc_issue_invoice_params`（否則 dispatch 驗證回 VALIDATION、0 次 API，測試會假綠）。用 `pre_http_request` 攔截並計算 amego `f0401` 的呼叫次數，斷言 = 1。

### R3（中高）區塊結帳依購物車隱藏地址 — WC 10.1.4 支援度與後端一致性

- 已驗證：`StoreApi/Utilities/OrderController::validate_address_fields()` L471-497 會讀 `wc()->countries->get_country_locale()`，`hidden=true` 或 `required=false` 的欄位都**跳過必填檢查**。`AbstractAddressSchema::validate_callback` 只有在 country 非空時才檢查。`Blocks/Utils/CartCheckoutUtils::get_country_data()` L327-345 把 locale 傳給前端（priority → index，保留 hidden）。
- 坑 1：country 隱藏時送出的 country 可能是空字串 → 後端改讀 `$all_locales['default']`。區塊前端讀的是「所選國家」的 locale。→ **hidden/required=false 要同時寫進 `default` 和 locale 陣列裡的每個國家 key**，前端、後端才會讀到同一份。
- 坑 2：`WC_Countries::get_country_locale()` 會把結果快取在 `$this->locale`（`class-wc-countries.php:854`）。如果在同一請求、購物車載入前就被算過，判定會是「非全虛擬」→ 後端照樣要求必填。→ 在 `woocommerce_store_api_checkout_update_order_from_request`（`Routes/V1/Checkout.php:450`，早於 `validate_order_before_payment` L458）**重設 `WC()->countries->locale = []`**，讓驗證用的 locale 依已載入的購物車重算。
- 坑 3（未驗證）：區塊前端是否接受用 locale 把 **country** 欄位隱藏。→ 第 0 階段 Spike 用 Playwright 驗。**若不支援**：country 欄位在區塊結帳會照樣顯示，與 feature `checkout-address-fields` 的「區塊 / 全虛擬 / country 隱藏」範例不符 → **停下來回報用戶，選擇「改 feature 為顯示但非必填」或其他方案**。這是本計劃唯一預設的暫停點。

### R4（中）欄位排序與既有結帳欄位的互動

- 發票欄位：`Shared/DTOs/CheckoutFieldDTO` 預設 `field_type=billing`、`priority=200`，排在所有地址欄位之後；F1 只改 10/20，**不受影響**。F4 用 unset 只移除 6 個地址鍵，發票欄位保留。
- ECPay AIO 分期：放在 `payment_fields()`（付款方式區塊內），與地址欄位排序無關。
- 物流選店：`inc/assets/js/classic-logistics.js` 用 DOM id 選取，跟 priority 無關。但全虛擬購物車沒有運送，該 JS 本來就不作用。
- 縣市改下拉後，TW 的 `state` 變成「有 states 的國家」，WC 會改要求必填，並驗證值必須在清單內。這是預期行為。但 F2 + F4 同時開時，F4 的隱藏必須**最後套用**（hook priority 較晚），否則 state 會被 F2 又設回必填。
- 緩解：第 7 階段回歸跑既有 E2E `tests/e2e/02-frontend/checkout-page-render.spec.ts`、`invoice-form.spec.ts`、`ecpay-checkout.spec.ts`，再加一支「開關全開時發票欄位 / 分期欄位仍在」的回歸 spec。

### R5（低）woomp 並存與舊資料

- 實際解析 `woomp/public/js/twzipcode.js`：22 縣市、371 鄉鎮，**全部已用「臺」**（臺北市、臺中市、臺南市、臺東縣）。所以 key = label = 中文全名就能直接相容 woomp 存下的 `billing_state`，**不需要「台→臺」正規化**（列入限制條件）。
- 並存站兩邊都開同名功能時，會重複驗證 / 重複提示。依 D7，只在設定頁放提示文字，不做偵測。

### R6（低）測試快取汙染

- `WC()->countries` 會快取 locale 和 states。測試在切換設定後，必須 `WC()->countries = new \WC_Countries()`（或清空 `locale`），否則前一支測試的結果會殘留。這條寫進共用 harness。

### R7（低）DTO 必填驗證失效的已知坑

- 專案 memory：wp-utils `DTO` 的 `required_properties` 拼錯，全專案 20 個 DTO 的必填驗證從未生效。→ `CheckoutEnhancementSettingsDTO` **不繼承 wp-utils DTO / BaseSettingsDTO**，改用純 `final class` 加 static 方法，自己做鍵級驗證，避開這個坑也避免順手修它。

---

## 架構變更

### 新增（正式碼）

| 檔案 | 職責 |
|------|------|
| `inc/classes/Domains/Checkout/ProviderRegister.php` | `register_hooks()`：無條件掛全部 hook（static callback）；**開關在 callback 內執行期判斷**（關閉時直接 return 原值 = 零副作用，也方便測試同一請求內切換設定） |
| `inc/classes/Domains/Checkout/DTOs/CheckoutEnhancementSettingsDTO.php` | `PROVIDER_ID='checkout_enhancement'`、`DEFAULTS`（10 鍵）、`get_all(): array`（預設值補齊）、`is_on(string $key): bool`、`get_string(string $key): string`、`merge_valid(array $input, array $stored): array`（鍵級驗證，不合法鍵保留原值，未知鍵丟棄） |
| `inc/classes/Domains/Checkout/Services/NameOrderService.php` | F1：filter `woocommerce_get_country_locale`（priority 20）只動 `TW` |
| `inc/classes/Domains/Checkout/Services/TwAddressService.php` | F2：filter `woocommerce_states` 加 `TW`；`wp_enqueue_scripts` 只在傳統結帳 enqueue + localize `pc_tw_address_data` |
| `inc/classes/Domains/Checkout/Services/PhoneValidationService.php` | F3：`validate_classic($data, $errors)`、`validate_store_api(\WC_Order $order, \WP_REST_Request $request)`；共用 `is_valid_tw_mobile(string): bool`、`resolve_country(shipping, billing): string` |
| `inc/classes/Domains/Checkout/Services/VirtualCartAddressService.php` | F4：`woocommerce_checkout_fields`（priority 1000，傳統 unset）；`woocommerce_get_country_locale`（priority 1000，區塊 default + 全部國家 hidden/required=false）；Store API reset locale |
| `inc/classes/Domains/Checkout/Services/VirtualOrderAutoCompleteService.php` | F5：`on_status_changed($order_id, $from, $to, $order)` 放入佇列；`flush_queue()`（shutdown）；filter seam `power_checkout_virtual_auto_complete_should_complete`（bool, WC_Order） |
| `inc/classes/Domains/Checkout/Services/FreeShippingHintService.php` | F6：傳統兩個輸出 hook；`woocommerce_blocks_loaded` 註冊 `woocommerce_store_api_register_endpoint_data`（CartSchema、namespace `power-checkout`）；區塊 enqueue |
| `inc/classes/Domains/Checkout/Shared/Helpers/FreeShippingCalculator.php` | 純計算：`calculate(\WC_Cart $cart): ?array{achieved:bool,diff:float,text:string,bg_color:string,text_color:string}`，結構對齊 api.yml `FreeShippingHint` |
| `inc/classes/Domains/Checkout/Shared/Helpers/TwAdminDivision.php` | `COUNTIES`（22，順序同 twzipcode）、`DISTRICTS`（縣市 → [鄉鎮 => 郵遞區號]，371 筆，從 woomp twzipcode 移植）；`get_states(): array<string,string>` |
| `inc/classes/Domains/Checkout/Shared/Helpers/CartInspector.php` | `is_all_virtual_cart(?\WC_Cart)`、`is_all_virtual_order(\WC_Order)`（品項 ≥1、商品都存在、全部 `is_virtual()`）、`resolve_tw(string $shipping, string $billing): bool`（運送 → 帳單 → `WC()->countries->get_base_country()`）、`is_classic_checkout(): bool`（邏輯同 `WC_EcpayLogisticsShipping::is_classic_checkout`，在本領域另寫一份，避免跨領域依賴 private 方法） |
| `inc/assets/js/classic-tw-address.js` | 免 build 的 jQuery：TW + 選縣市 → 把 `#billing_city`/`#shipping_city` 換成鄉鎮 select；選鄉鎮 → 帶入 postcode；監聽 `country_to_state_changed`；非 TW 時還原文字輸入 |
| `inc/assets/blocks/pc_free_shipping_hint.tsx` | `registerPlugin('pc-free-shipping-hint', { scope: 'woocommerce-checkout' })` + slot fill（優先 `ExperimentalOrderShippingPackages`，Spike 不合再改 `ExperimentalOrderMeta`）；`useSelect(wc/store/cart).extensions['power-checkout'].free_shipping_hint`；文字用 `decodeEntities` 後交給 React 跳脫 |
| `js/src/pages/Checkout/index.vue` | Element Plus 表單：6 個 `el-switch`、2 個文案 `el-input`、2 個 `el-color-picker`（`show-alpha=false`）、woomp 並存提示 `el-alert`；TanStack Query GET/POST `settings/checkout_enhancement` |
| `js/src/pages/Checkout/Shared/types.ts` | `TCheckoutEnhancementSettings` |

### 修改

| 檔案 | 變更 |
|------|------|
| `inc/classes/Bootstrap.php` | 加 `Domains\Checkout\ProviderRegister::register_hooks();` |
| `inc/classes/Domains/Settings/Services/SettingApiService.php` | `get_providers_with_id_callback` / `post_providers_with_id_callback` 開頭加 `if (CheckoutEnhancementSettingsDTO::PROVIDER_ID === $provider_id)` 特例分支（只改這兩處，既有 provider 路徑零改動） |
| `js/src/router/index.ts` | route `/checkout` → `@/pages/Checkout/index.vue`；`ROUTER_MAPPER.checkout_enhancement = '/checkout'` |
| `js/src/App.vue` | 選單加 `<el-menu-item index="/checkout"> 結帳 </el-menu-item>`（放在「電子收據」之後） |
| `.claude/CLAUDE.md`、`.claude/rules/vue-frontend.rule.md`、`.claude/rules/react-blocks.rule.md`、`.claude/rules/wordpress-php.rule.md` | 第 7 階段文件同步（新領域、路由、blocks entry、Checkout 領域列入 DDD 清單） |

### 無變更（明確）

- `Domains/Invoice/**`（R2 已驗證冪等）、`Domains/Payment/**/StatusManager.php`（R1 用延後完成解決，不改金流）、`DefaultSetting::modify_tw_address_formats`（維持永遠啟用）、`Shared/Utils/CheckoutFields.php`。
- 無新 order meta、無新 REST 端點、無 DB schema 變更。

---

## 資料流分析

### DF1：設定讀寫（O1 / O2）

```
REST GET/POST /settings/checkout_enhancement
  │ permission (ApiBase: manage_options|manage_woocommerce + nonce)
  ▼
SettingApiService 特例分支 ──GET──▶ DTO::get_all() = wp_parse_args(stored, DEFAULTS) ──▶ 200 data(10 鍵)
  │POST
  ▼
WP::sanitize_text_field_deep(params)  ──▶ DTO::merge_valid(input, stored)
  │                                        │ 逐鍵：未知鍵丟棄 / 開關∉{yes,no} 保留原值 /
  │                                        │ 色碼 !~ ^#[0-9a-fA-F]{6}$ 保留 / text_left 不含 {price} 保留 /
  │                                        │ 文案 sanitize 後空字串 保留(ASM-1)
  ▼                                        ▼
update_option(woocommerce_checkout_enhancement_settings, merged) ──▶ 200 "儲存成功" data=get_all()
Shadow: [option 不存在→DEFAULTS] [option 非 array→視為空] [params 含 provider_id→丟棄]
        [全部鍵不合法→寫回原值，仍 200] [401 訪客 / 403 顧客]
```

### DF2：結帳頁欄位定義（O3）

```
WC()->countries->get_country_locale()  /  WC()->checkout()->get_checkout_fields()
  │
  ├─ NameOrderService(p20)        last_name_first=yes → locale[TW] last_name{priority10,class first} first_name{20,last}
  ├─ TwAddressService(states)     tw_address=yes → states[TW] = 22 縣市
  ├─ VirtualCartAddressService(p1000)
  │     virtual_hide_address=yes && CartInspector::is_all_virtual_cart(WC()->cart)
  │       傳統: unset billing_{country,state,city,postcode,address_1,address_2}
  │       區塊: locale[default + 每個國家][6 鍵] = {hidden:true, required:false}
  ▼
傳統 HTML / 區塊 CartCheckoutUtils::get_country_data()
Shadow: [WC()->cart null(後台/REST 早期)→視為非全虛擬] [空購物車→非全虛擬]
        [商品已刪除→非全虛擬] [locale 快取早於購物車→R3 reset] [\Throwable→log、回原值]
```

### DF3：送單驗證（O4）

```
傳統: process_checkout → validate_checkout → do_action(woocommerce_after_checkout_validation, $data, $errors)
區塊: POST /wc/store/v1/checkout → update_order_from_request → do_action(woocommerce_store_api_checkout_update_order_from_request, $order, $request)
  │
  ├─ VirtualCartAddressService(區塊) reset WC()->countries->locale（讓 validate_address_fields 重算）
  ▼
PhoneValidationService
  tw_phone_validation=no → return
  country = resolve(shipping_country, billing_country, base) ; != TW → return
  billing_phone 非空 → normalize(去 \s 與 -) !~ ^09\d{8}$ → 錯誤
  shipping_phone 非空 → 同上
  │
  ├─ 傳統：$errors->add('validation', '<strong>帳單電話</strong> 格式有誤，請輸入 09 開頭的 10 碼手機號碼')
  └─ 區塊：throw RouteException('power_checkout_invalid_phone', '帳單電話格式有誤，請輸入 09 開頭的 10 碼手機號碼', 400)
原值不改寫（只用 normalize 結果判斷）
Shadow: [phone 空→跳過，必填交給 WC] [國家皆空→商店國家] [+886 開頭→失敗(規格)]
        [區塊錯誤時訂單仍是 checkout-draft→WC 原生行為，不清理] [\Throwable(非 RouteException)→log、不擋單]
```

### DF4：自動完成（O5）

```
payment_complete() / update_status('processing') / 後台存檔
  │ woocommerce_order_status_changed($id,$from,'processing',$order)
  ▼
on_status_changed: virtual_auto_complete=yes && to==='processing' → queue[$id]=true；首次掛 shutdown→flush_queue
  ▼ (請求結束)
flush_queue: foreach id
   try {
     $order = wc_get_order(id)  ── null → skip
     status !== processing     ── skip（金流後續又改狀態 / 已被別人完成）
     !CartInspector::is_all_virtual_order ── skip（含實體 / 無品項 / 商品已刪）
     !apply_filters('power_checkout_virtual_auto_complete_should_complete', true, $order) ── skip
     $order->update_status('completed', 'Power Checkout：全虛擬商品訂單，自動完成')
       └─ 回 false（WC 內部吃掉例外）→ Plugin::logger(含 #id, error)
   } catch \Throwable → Plugin::logger("…#{id}…", 'error')  ← never-throw
   清空 queue（避免同請求重入時重跑）
Shadow: [同請求多次轉 processing→佇列 key 去重] [flush 期間 completed 又觸發 status_changed→to≠processing 不入列]
        [發票 completed hook→provider 冪等] [shutdown 前 fatal→不完成，管理員可手動，與 WC 原生一致]
```

### DF5：免運提示（O6）

```
傳統: woocommerce_cart_totals_before_shipping / woocommerce_review_order_before_shipping → echo <tr>
區塊: GET /wc/store/v1/cart → extensions['power-checkout'].free_shipping_hint
  │
  ▼
FreeShippingCalculator::calculate(WC()->cart)
  free_shipping_hint=no / cart 空 / !needs_shipping → null
  package = cart->get_shipping_packages()[0] ; zone = WC_Shipping_Zones::get_zone_matching_package(package)
  methods = zone->get_shipping_methods(true) 中 id==='free_shipping'；無 → null
  has_fs_coupon = 已套用優惠券中 get_free_shipping()===true
  base = cart->get_displayed_subtotal()
         − (display_prices_including_tax ? discount_tax : 0)
         − (ignore_discounts==='no' ? discount_total : 0)      ← 與 WC_Shipping_Free_Shipping::is_available 同式
  base = NumberUtil::round(base, wc_get_price_decimals())
  每個 method 依 requires：
     ''/none   → achieved(diff 0)
     coupon    → 不列入
     min_amount→ diff = min − base
     either    → has_fs_coupon ? achieved : diff
     both      → has_fs_coupon ? diff : 不列入
  候選為空 → null ；任一 achieved → achieved ；否則 min(正 diff)
  text = achieved ? text_achieved : str_replace('{price}', number_format(diff, dec, dec_sep, thou_sep), text_left)
  return {achieved, diff(achieved?0:diff), text: esc_html(text), bg_color, text_color}
  ▼
傳統：<tr class="pc-free-shipping-hint"><td colspan="2" style="background:esc_attr;color:esc_attr">text</td></tr>
區塊：TSX 呈現；null → 不渲染
Shadow: [min_amount 空字串/非數字→該 method 視為 none?→ASM-2：視為不列入] [多區域→只看第一個 package]
        [購物車頁未填國家→WC 用 customer 預設地址比對 zone] [\Throwable→log、回 null / 不輸出]
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|-------------|---------|---------|------------|
| `SettingApiService` GET 特例 | option 損毀（非 array） | 資料 | 視為空 → 回 DEFAULTS | 否（看到預設值） |
| `SettingApiService` POST 特例 | 鍵值不合法 | 驗證 | 該鍵保留原值，其他照存，回 200 | 是（前端重新讀取後看到原值） |
| `SettingApiService` POST 特例 | 無權限 | 授權 | ApiBase 401/403 | 是 |
| `NameOrderService::filter_locale` | `\Throwable` | 例外 | catch → `Plugin::logger` → 回原 locale | 否 |
| `TwAddressService::enqueue` | 非傳統結帳頁 | 流程 | 不 enqueue | 否 |
| `classic-tw-address.js` | localize 資料缺失 / 縣市不在對照表 | 前端 | 保留原文字輸入（不阻擋） | 否 |
| `VirtualCartAddressService` | cart 未載入 | 流程 | 視為非全虛擬（顯示地址，偏安全） | 否 |
| `VirtualCartAddressService` | locale 快取早於 cart（區塊） | 時序 | 在 update_order_from_request reset locale | 否 |
| `PhoneValidationService::validate_classic` | 格式不符 | 驗證 | `$errors->add` | 是（含 `<strong>` 欄位名） |
| `PhoneValidationService::validate_store_api` | 格式不符 | 驗證 | `RouteException` 400 | 是（純文字） |
| `PhoneValidationService::*` | 其他 `\Throwable` | 例外 | catch → log → 不擋單 | 否 |
| `VirtualOrderAutoCompleteService::flush_queue` | update_status 回 false / `\Throwable` / filter 拋例外 | 例外 | catch → `Plugin::logger(含 #id)`，訂單維持 processing | 否（log；管理員可手動完成） |
| `FreeShippingCalculator::calculate` | zone/method 設定異常、`\Throwable` | 例外 | catch → log → null（不顯示） | 否 |
| Store API extension data_callback | `\Throwable` | 例外 | catch → `['free_shipping_hint' => null]` | 否 |

無「處理方式=無 且 靜默」的 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|------------|---------|
| 自動完成 + 金流二次 update_status | 狀態來回、通知寄兩次（R1） | 是（shutdown 佇列） | 是（`test_金流付款後再設處理中_只完成一次`） | 否 | — |
| 自動完成 + 發票兩狀態 | 重複開立（R2） | 是（provider 冪等） | 是（feature「只開 1 張」） | 否 | — |
| 區塊全虛擬送單 | 前端隱藏、後端要求必填（R3） | 是（default + 全國家 locale、reset 快取） | 是（Store API 整合測試 + E2E） | 是（若失敗會擋單） | Spike 不過就停下來回報用戶 |
| 區塊 country 無法隱藏 | 與 feature 範例不符 | 待 Spike | E2E | 是 | 回報用戶改 feature |
| F2 + F4 同時開 | state 被 F2 設回必填 | 是（F4 priority 1000 最後套用） | 是（edge 測試） | 是 | — |
| 測試快取 | 前一支測試的 locale/states 殘留 | 是（harness 重建 WC_Countries） | — | 否 | — |
| woomp 並存 | 重複驗證/提示 | 只做 UI 提示 | 否（不偵測） | 是 | 管理員擇一關閉 |
| 文案 XSS | 腳本注入 | 是（存檔時 sanitize，輸出時 esc_html/esc_attr） | 是（settings + free-shipping 各 1） | — | — |

---

## 實作步驟

> 每個階段結束時的 Gate：該階段新增測試全綠，`tests/Integration/` 全套與基線比對無新增紅燈（扣除 pre-existing：`EzpayAesCryptoTest::test_edge_標準AES16blockPadding…`、`LinePayTest` 3 支），PHPStan L9 無新增 error（扣除 pre-existing `RedirectSettingsDTO.php:148`），PHPCS 對新/改檔 0 error。每個階段各自 commit（Conventional Commits，`feat(checkout): …`），**不 push**。

### 第 0 階段：分支、測試 harness、Spike（複雜度：中，風險：中）

1. **開分支**：`git switch -c feat/checkout-enhancement master`（master 上剛合併、還沒 push 的 commit 一起帶走；不 push）。未追蹤的 specs 檔案會跟著分支，第一個 commit 先提交規格：`docs(specs): 結帳體驗強化規格（AIBDD Phase 01-04）`，只包含 `specs/**` 這次的新增/修改，**不要**加入 `.serena/project.yml`、`.tmp/`。
2. **共用 harness**（檔案：`tests/Integration/Checkout/CheckoutTestCase.php`，abstract，extends `Tests\Integration\TestCase`）
   - `set_checkout_settings(array $kv)`：寫入 option（其他鍵用預設值）+ `WC()->countries = new \WC_Countries()`
   - `init_cart_session()`：`WC()->session = new \WC_Session_Handler(); WC()->session->init(); WC()->customer = new \WC_Customer(0, true); WC()->cart = new \WC_Cart();`
   - `create_product(string $name, float $price, bool $virtual, bool $downloadable = false): \WC_Product_Simple`
   - `add_to_cart(array $rows)`：對應句型 G5/G6/G7
   - `create_free_shipping_zone(array $methods)`：對應句型 G10/G11/G12（`WC_Shipping_Zone` + `add_shipping_method` + `woocommerce_{id}_{instance}_settings`）
   - `classic_validate(array $post): \WP_Error`：設定 `$_POST`（含 `woocommerce-process-checkout-nonce`、有 `shipping_*` 時加 `ship_to_different_address=1`），`$data = WC()->checkout()->get_posted_data()`，用 ReflectionMethod 呼叫 protected `validate_checkout($data, $errors)`
   - `classic_create_order(array $post): \WC_Order`：驗證通過後 `WC()->checkout()->create_order($data)`
   - `store_api_checkout(array $body): \WP_REST_Response`：`add_filter('woocommerce_store_api_disable_nonce_check', '__return_true')` + `rest_do_request(POST /wc/store/v1/checkout)`
   - 每個 test method 都要帶 `@group` 白名單之一，類別另外帶 `@group checkout`
3. **Spike S1（PHPUnit）**：用 harness 做一次「全虛擬購物車 + 空地址」Store API checkout，確認能在 PHPUnit 跑出 200/400。
   - 不通（Store API 在 WP_UnitTestCase 下載不到購物車）→ **降級方案**：區塊 Example 改成直接呼叫 hook callback（`do_action('woocommerce_store_api_checkout_update_order_from_request', $order, $request)` + ReflectionMethod 呼叫 `OrderController::validate_order_before_payment`），完整送單交給 E2E。把決定記進 tdd-coordinator 藍圖。
4. **Spike S2（Playwright，暫時用的 mu-plugin）**：在 dev 站用 locale default + TW 設 6 鍵 `hidden:true`，確認區塊結帳 **country** 是否隱藏、送單是否成功。
   - 不通 → **暫停並回報用戶**（R3 坑 3）。其餘階段可以繼續，F4 區塊部分等用戶決定。
   - Spike 結束後刪除 mu-plugin。
- 成功標準：分支已建、規格已 commit；harness 能建購物車並跑傳統驗證；S1/S2 結論寫進 tdd-coordinator 藍圖。
- 依賴：無。

### 第 1 階段：設定（DTO + REST 特例 + 領域骨架）（複雜度：低，風險：低）

對應 feature：`settings/checkout-enhancement-settings.feature` 全部（權限 3 例、預設值、部分更新、不合法鍵 4 例、script 移除）。

1. `DTOs/CheckoutEnhancementSettingsDTO.php`
   - `TOGGLE_KEYS` 6 個、`COLOR_KEYS` 2 個、`TEXT_KEYS` 2 個；`DEFAULTS` 照 erm.dbml 1.5.0
   - `merge_valid()` 規則：開關 `in_array($v, ['yes','no'], true)`；色碼 `preg_match('/^#[0-9a-fA-F]{6}$/')`；`free_shipping_text_left` 須含 `{price}`；文案空字串保留原值（ASM-1）；非 string 一律不合法
2. `ProviderRegister.php`（先是空骨架，後面各階段逐一接上 service）+ `Bootstrap.php` 一行
3. `SettingApiService.php` 兩個特例分支（GET → `DTO::get_all()`；POST → sanitize → `merge_valid` → `update_option` → 回 `get_all()`）。用 `\update_option` 直接寫（不走 `ProviderUtils::update_option` 的 wp_parse_args，因為 merge 已在 DTO 完成），寫之前一樣 `StrHelper::trim_invisible_deep`。
4. 測試：`tests/Integration/Settings/CheckoutEnhancementSettingsTest.php`
   - `security`：訪客 GET/POST 401、顧客 POST 403
   - `happy`：首次 GET 10 鍵預設；部分 POST 保留其他鍵；回應 message「儲存成功」
   - `error`：Scenario Outline 4 例不合法值維持原值
   - `security`：`<script>alert(1)</script>免運啦` → `免運啦`
   - `edge`：未知鍵 / `provider_id` 不寫入 option；文案空字串保留原值（ASM-1）
   - 單元：`CheckoutEnhancementSettingsDTOTest`（`smoke`/`edge`，`merge_valid` 純邏輯）
- 成功標準：上述測試全綠；既有 `tests/Integration/Settings/*` 無回歸。
- 依賴：第 0 階段。

### 第 2 階段：後台 Vue 設定頁（複雜度：低，風險：低）

1. `js/src/pages/Checkout/Shared/types.ts`、`js/src/pages/Checkout/index.vue`
   - 參考既有頁（如 `js/src/pages/Invoices/Ezpay/index.vue`）的 query/mutation 寫法；POST 成功由 interceptor 顯示通知（**不要**自己呼叫 ElNotification）
   - 分組：「地址與姓名」（last_name_first、tw_address + 註記「鄉鎮下拉僅傳統結帳」）、「欄位檢查」（tw_phone_validation）、「虛擬商品」（virtual_hide_address、virtual_auto_complete）、「免運提示」（switch + 兩個文案 + 兩個色碼 + 即時預覽）
   - 頂部 `el-alert type="info"`：「若同站 woomp 已開啟同名功能，請擇一啟用以免重複顯示或重複驗證」
   - 前端也檢查 text_left 含 `{price}`（UX 用；後端仍是最終把關）
2. `js/src/router/index.ts`、`js/src/App.vue`
3. 驗證：`pnpm lint`、`pnpm build`；E2E `tests/e2e/01-admin/checkout-enhancement-settings.spec.ts`（進入「結帳」選單 → 開關 → 儲存 → 重新整理後值仍在）
- 依賴：第 1 階段。

### 第 3 階段：結帳地址欄位（F1、F2、F4 欄位面）（複雜度：中，風險：中）

對應 feature：`checkout/checkout-address-fields.feature` 全部 Example。

1. `Shared/Helpers/TwAdminDivision.php`：用一次性 node 腳本把 twzipcode 轉成 PHP array（腳本放 scratchpad，**不 commit**），貼成常數；檔頭註明來源 woomp twzipcode v1.7.14（MIT）。
2. `Shared/Helpers/CartInspector.php`
3. `Services/NameOrderService.php`：locale TW 合併 `['last_name'=>['priority'=>10,'class'=>['form-row-first']], 'first_name'=>['priority'=>20,'class'=>['form-row-last']]]`
4. `Services/TwAddressService.php`：`woocommerce_states`、傳統結帳 enqueue（handle `pc-classic-tw-address`、deps `jquery`,`wc-checkout`、localize `pc_tw_address_data = {counties:{縣市:{鄉鎮:郵遞區號}}}`）
5. `inc/assets/js/classic-tw-address.js`（billing 與 shipping 兩組，行為參考 woomp `woomp-checkout.js` 但不依賴 twzipcode 外掛）
6. `Services/VirtualCartAddressService.php`（欄位面：傳統 unset、區塊 locale）
7. 測試：`tests/Integration/Checkout/CheckoutAddressFieldsTest.php`
   - `smoke`：三開關關閉時欄位不變（first 10 / last 20 / address_1 50 必填；TW state 無 options）
   - `happy`：姓名排序 Outline 3 例（傳統用 `WC()->countries->get_address_fields('TW','billing_')`；區塊用 `CartCheckoutUtils::get_country_data()['TW']['locale']` 的 `index`）
   - `happy`：縣市 22 個（傳統 `WC()->countries->get_states('TW')`；區塊 `get_country_data()['TW']['states']`），包含 臺北市/新北市/臺中市/高雄市/連江縣
   - `happy`：傳統結帳頁對照資料（建 `[woocommerce_checkout]` 頁 → `go_to` → 呼叫 enqueue → `wp_scripts()->get_data('pc-classic-tw-address','data')` 含 臺北市 中正區 100、信義區 110；連江縣 南竿鄉 209）
   - `happy`：區塊結帳頁（內容含 `<!-- wp:woocommerce/checkout /-->`）不 enqueue；locale TW city 沒有 options
   - `happy`：虛擬隱藏 Outline 5 例（傳統：key 不存在；區塊：locale TW 與 default 都 `hidden=true` 且 `required=false`；phone 顯示且必填）
   - `edge`：F2+F4 同時開、全虛擬 → state 隱藏非必填；空購物車 → 非全虛擬；商品已刪除 → 非全虛擬
   - `edge`：`TwAdminDivision` 22 縣市、371 鄉鎮、郵遞區號都是 3 碼數字、縣市名不含「台」
- 依賴：第 1 階段（與第 4、5、6 階段互相獨立）。

### 第 4 階段：送單驗證（F3、F4 送單面）（複雜度：中，風險：中高）

對應 feature：`checkout/checkout-field-validation.feature` 全部 Example。

1. `Services/PhoneValidationService.php`
   - 傳統 hook：`woocommerce_after_checkout_validation`（10, 2）
   - 區塊 hook：`woocommerce_store_api_checkout_update_order_from_request`（10, 2），失敗時 `throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(...)`（**不要**被自己的 catch \Throwable 吞掉：先 catch RouteException 再丟出去，或只在驗證邏輯外包 try）
   - 欄位標籤：帳單電話 / 收件人電話（傳統用 `<strong>…</strong>` 包欄位名，區塊用純文字）
2. `VirtualCartAddressService`：加上區塊 locale reset hook（同一個 action，priority 1，早於 phone 驗證）
3. 測試：`tests/Integration/Checkout/CheckoutFieldValidationTest.php`（背景：商店國家 TW）
   - `happy`：關閉時市話能下單；Outline 帳單電話 8 例（傳統用 `classic_validate`/`classic_create_order`，「訂單帳單電話為 0912-345-678」要真的建單後斷言原值；區塊 2 例走 `store_api_checkout`，失敗斷言 400 與純文字訊息）
   - `happy`/`error`：運送電話 Outline 3 例
   - `edge`：國家判定 Outline 3 例；全虛擬隱藏國家後用商店國家判斷
   - `happy`/`error`：虛擬免填 Outline 3 例（傳統全虛擬成功、區塊全虛擬成功、含實體失敗且錯誤含帳單地址必填）
   - `edge`：billing_phone 空字串 → 本服務不報錯（交給 WC 必填）
   - `security`：`0912345678<script>` → 失敗且不改寫
- 依賴：第 3 階段（CartInspector、VirtualCartAddressService）。

### 第 5 階段：虛擬訂單自動完成（F5）（複雜度：中，風險：高 → 已用 R1 設計降為中）

對應 feature：`checkout/virtual-order-auto-complete.feature` 全部 Example。

1. `Services/VirtualOrderAutoCompleteService.php`（DF4）
   - hook：`woocommerce_order_status_changed`（priority 20, 4 參數）
   - `private static array $queue = []`、`private static bool $shutdown_hooked = false`
   - `public static function flush_queue(): void`；另提供 `public static function reset_queue(): void` 給測試 tear_down 用
2. 測試：`tests/Integration/Checkout/VirtualOrderAutoCompleteTest.php`（每個 Example 在 When 之後呼叫 `flush_queue()`）
   - `smoke`：關閉時維持處理中
   - `happy`：Outline 2 例（`payment_complete()`；保留 → `update_status('processing')`）→ 已完成 + 備註
   - `edge`：含實體、商品已刪（`wp_delete_post($product_id, true)` 後再付款）、混合、無品項、轉保留 → 不處理
   - `happy`：發票 amego 兩狀態只開 1 張（R2 測試陷阱照做）
   - `error`：never-throw — `add_filter('power_checkout_virtual_auto_complete_should_complete', fn() => throw new \RuntimeException('boom'))`（對應句型 G16）→ 不拋出、DB 狀態 processing、log 含 `1007`（攔截 `Plugin::logger` 的輸出：用 WC logger 的 `woocommerce_logger_log_message` filter 或專案 TestCase 既有作法）
   - `edge`（R1 回歸）：模擬金流 `payment_complete(); update_status('processing');` → flush 後 completed，且 `did_action('woocommerce_order_status_completed')` 增量 = 1
   - `edge`：flush 前狀態已被改成 on-hold → 不完成
- 依賴：第 1 階段（與第 3、4、6 階段互相獨立）。

### 第 6 階段：差多少免運（F6）（複雜度：中高，風險：中）

對應 feature：`checkout/free-shipping-hint.feature` 全部 Example。

1. `Shared/Helpers/FreeShippingCalculator.php`（DF5，純邏輯、never-throw）
2. `Services/FreeShippingHintService.php`
   - 傳統：`woocommerce_cart_totals_before_shipping`、`woocommerce_review_order_before_shipping` → `render_row()`（`<tr class="pc-free-shipping-hint">`）
   - 區塊：`woocommerce_blocks_loaded` → `woocommerce_store_api_register_endpoint_data(['endpoint'=>CartSchema::IDENTIFIER,'namespace'=>'power-checkout','data_callback'=>[__CLASS__,'store_api_data'],'schema_callback'=>[__CLASS__,'store_api_schema'],'schema_type'=>ARRAY_A])`
   - 區塊 enqueue：只在含 `woocommerce/cart` 或 `woocommerce/checkout` block 的頁面；依賴參考 `BlocksInvoiceIntegration::enqueue_block_assets`；開關關閉時不 enqueue
3. `inc/assets/blocks/pc_free_shipping_hint.tsx`（`pnpm build:blocks` 產出 `inc/assets/dist/blocks/pc_free_shipping_hint.js`）
4. 測試：`tests/Integration/Checkout/FreeShippingHintTest.php`（背景：小數位 0、含稅顯示、zone「台灣」flat_rate 100 + free_shipping min_amount 1000；customer shipping country TW）
   - `smoke`：關閉不顯示
   - `happy`：位置 Outline 4 例 → 傳統斷言 `render_row()` 輸出（購物車 / 結帳兩個 hook 都會輸出）；區塊斷言 `store_api_data()` 的 `text`
   - `happy`/`edge`：差額 Outline 7 例 + 千分位 1,250
   - `edge`：requires Outline 5 例；多門檻取最小
   - `edge`：不顯示 Outline 3 例（全虛擬、method 停用、空購物車）→ `rest_do_request(GET /wc/store/v1/cart)` 的 `extensions['power-checkout']['free_shipping_hint'] === null`（Spike S1 不通就直接呼叫 `store_api_data()`）
   - `security`：`&lt;b&gt;` 文案 → 輸出不含 `<b>`；色碼經 `esc_attr`
- 依賴：第 1 階段。

### 第 7 階段：E2E、回歸、文件（複雜度：中，風險：低）

1. Playwright（`tests/e2e/02-frontend/`）
   - `checkout-tw-address.spec.ts`：傳統結帳選 臺北市 → 鄉鎮下拉出現 → 選 信義區 → postcode = 110；切 US 還原文字輸入；shipping 地址同樣可用
   - `checkout-virtual-address-blocks.spec.ts`：區塊結帳全虛擬 → 地址欄位不顯示 → 送單成功；含實體 → 欄位顯示
   - `free-shipping-hint-blocks.spec.ts`：區塊購物車 / 結帳顯示「再買 150 元即可享免運」；調數量到門檻 → 「已享免運」
   - `checkout-enhancement-regression.spec.ts`：6 開關全開時，傳統結帳的發票欄位、ECPay AIO 分期選單、物流選店按鈕仍在且可操作（R4）
   - 既有回歸：`checkout-page-render.spec.ts`、`invoice-form.spec.ts`、`ecpay-checkout.spec.ts`
   - 環境：tunnel 離線時改用 `http://localhost:8891`（wp-env dev 站，需 `woocommerce_default_country TW`、`woocommerce_currency TWD`）；卡號類輸入不在此範圍
2. 文件：`.claude/CLAUDE.md`（Integrated Services 加 Checkout Enhancement、Backend Domain Structure 加 `Checkout/`、Key WordPress Hooks 加新 hook、Vue 選單）、`vue-frontend.rule.md`（`/checkout` 路由）、`react-blocks.rule.md`（`pc_free_shipping_hint.tsx`）、`wordpress-php.rule.md`（領域清單加 Checkout）
3. 全套 Gate：PHPUnit 全套、PHPStan L9、PHPCS、`pnpm lint`、`pnpm build`、`pnpm build:blocks`

---

## 測試策略

- **單元**：`CheckoutEnhancementSettingsDTOTest`、`FreeShippingCalculator`（以 harness 建的真實 cart 驅動，不 mock WC）、`PhoneValidationService::is_valid_tw_mobile`、`TwAdminDivision` 資料完整性。
- **整合**（`tests/Integration/Checkout/`、`tests/Integration/Settings/`）：每個 feature Example 對應一個 test method（Outline 用 `@dataProvider`），方法名稱用中文描述情境，跟既有慣例一致。
- **E2E**（`tests/e2e/`）：只放 PHPUnit 驗不到的部分（JS 互動、區塊實際渲染、區塊 country 隱藏、slot 位置、R4 回歸）。
- **群組**：每個 method 至少帶 smoke/happy/error/edge/security 其中一個；類別加 `@group checkout`（不在白名單內，只當分類用）。
- **執行指令**（memory：先確認容器內沒有其他 phpunit 在跑，避免 InnoDB 死結造成假紅；用 exit code 或 `grep -q "^OK ("` 判斷結果）：
  ```bash
  # 單一目錄 + filter（--filter 要帶路徑，否則 0 收集）
  npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/ --no-coverage"
  npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Settings/ --filter CheckoutEnhancement --no-coverage"
  # tests-cli 沒在跑時的繞法
  cd "C:/Users/User/.wp-env/d5a5492d5679ff98cc7c025012f4fdfb" && docker compose -p d5a5492d5679ff98cc7c025012f4fdfb up -d tests-mysql && MSYS_NO_PATHCONV=1 docker compose -p d5a5492d5679ff98cc7c025012f4fdfb run --rm --no-deps -w /var/www/html/wp-content/plugins/power-checkout tests-cli vendor/bin/phpunit tests/Integration/Checkout/ --no-coverage
  # PHPStan（-d 是 PHP 參數）
  npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress"
  # 前端
  pnpm lint && pnpm build && pnpm build:blocks
  # E2E
  cd tests/e2e && npx playwright test 02-frontend/checkout-tw-address.spec.ts
  ```
- **關鍵邊界**：差額 = 0 / 負數 / 千分位；電話 9 碼 / 國碼 / 夾帶分隔符號；空購物車 / 商品被刪 / 混合購物車；同請求多次轉 processing；F2+F4 疊加；locale 快取。

## 依賴項目

- WooCommerce 10.1.4（Store API ExtendSchema、`RouteException`、`CartCheckoutUtils::get_country_data`、slot fill `ExperimentalOrderShippingPackages`）
- 既有：`J7\WpUtils\Classes\ApiBase`、`WP::sanitize_text_field_deep`、`StrHelper::trim_invisible_deep`、`Plugin::logger`
- 資料來源：woomp `public/js/twzipcode.js`（MIT）
- 無新 composer / npm 依賴

## 風險與緩解措施

- **高**：R1 自動完成觸發狀態來回 / 雙重通知 — shutdown 佇列加上最終狀態檢查，用專屬測試鎖住。
- **中高**：R3 區塊結帳隱藏地址後，後端仍要求必填 — default + 全部國家 locale、送單前 reset locale 快取、Spike S1/S2；country 隱藏不支援時暫停回報用戶。
- **中**：R2 發票重複開立 — provider 已冪等，補測試鎖住。
- **中**：R4 欄位排序 / 隱藏影響既有結帳欄位 — priority 規劃 + E2E 回歸。
- **低**：R5 woomp 並存 — 設定頁提示；R6 測試快取 — harness 重建；R7 DTO 坑 — 不繼承 wp-utils DTO。

## 錯誤處理策略

- 所有前台 hook callback 都採 never-throw：外層 `try { … } catch (\Throwable $e) { Plugin::logger(…, 'error', [], 5); return 原值; }`。**例外**是預期的驗證失敗：傳統用 `$errors->add`，區塊用 `RouteException`，必須讓它穿出 catch。
- 判斷不確定時一律退回「WooCommerce 原生行為」（顯示地址、不擋單、不完成訂單、不顯示提示），不讓強化功能變成結帳阻礙。

## 限制條件（本計劃不做）

- 不改任何金流 StatusManager 或發票 provider 程式碼（R1/R2 都在 Checkout 領域內解決）。
- 不做 woomp 偵測或自動停用；不做「台→臺」舊資料正規化（已驗證 woomp 資料就是「臺」）。
- 不做區塊結帳的鄉鎮下拉 / 郵遞區號帶入（規格明定 ❌）。
- 不做區塊結帳頁內「移除商品後即時重算隱藏地址」（D5 trade-off）。
- 不做 woomp 的「郵遞區號優先排序」「姓名至少 2 字」。
- 不支援 PC 自有物流運送方式的免運門檻（D9/Q4：只讀 WC 內建 free_shipping）。
- 不新增 REST 端點、不新增 order meta、不改 `GET /settings` 列表。
- 不處理「虛擬 + 可下載」訂單在既有金流下 payment_complete → completed → 金流改回 processing 時 WC 原生多寄一次通知的既有行為（已存在、非本功能引入；本功能會讓它最終停在已完成）。
- 不 push、不發版。

## 假設（ASM，交 tdd-coordinator 照做；如需改請提出）

- **ASM-1**：文案（text_left / text_achieved）在 sanitize 後若是空字串，視為不合法並保留原值。
- **ASM-2**：free_shipping method 的 `min_amount` 為空或非數字，且 requires 需要金額（min_amount/either/both）時，該 method 不列入候選。
- **ASM-3**：區塊 slot 優先用 `ExperimentalOrderShippingPackages`，E2E 確認位置不在「運送區上方」時改用 `ExperimentalOrderMeta`。這是 UI 位置的實作細節，不影響 Example 斷言。
- **ASM-4**：傳統結帳的「隱藏」用 unset 欄位實作（與 woomp 相同），測試斷言欄位 key 不存在。

## 成功標準

- [ ] 分支 `feat/checkout-enhancement` 從 master 建立，各階段各自 commit，未 push
- [ ] 5 份 feature 的每個 Example 都有對應的 PHPUnit 或 Playwright 測試且全綠
- [ ] 開關全關時，原生行為測試（smoke）全綠
- [ ] R1 回歸測試證明「金流 payment_complete + update_status(processing)」只完成一次、completed hook 只觸發一次
- [ ] 發票兩狀態自動開立只打 1 次 API
- [ ] 區塊結帳全虛擬未填地址可送單（PHPUnit 或 E2E 至少一邊證明）
- [ ] PHPUnit 全套、PHPStan L9、PHPCS 與基線比對無新增問題；`pnpm lint`、`pnpm build`、`pnpm build:blocks` 成功
- [ ] `.claude/CLAUDE.md` 與相關 rules 已同步

## 預估複雜度：中高

（7 個階段；第 3、4、5、6 階段在第 1 階段完成後可以並行，但第 4 階段依賴第 3 階段的 CartInspector / VirtualCartAddressService。）

---

## WHERE NEXT / Hand-off → @zenbu-powers:tdd-coordinator

- 計劃檔：`specs/open-issue/checkout-enhancement-implementation-plan.md`（本檔）
- 規格來源：`specs/open-issue/checkout-enhancement-execution-plan.md`、`specs/features/checkout/*.feature`、`specs/features/settings/checkout-enhancement-settings.feature`、`specs/features/checkout/句型.md`、`specs/features/settings/句型.md`、`specs/features/系統抽象.md`、`specs/erm.dbml`、`specs/api.yml`、`specs/clarify/2026-11-13-1232.md`
- 執行順序：第 0 階段 → 第 1 階段 → {第 3 階段 → 第 4 階段}、第 5 階段、第 6 階段（可並行）→ 第 2 階段（可在第 1 階段後任何時間）→ 第 7 階段
- 預設暫停點：只有 Spike S2（區塊 country 無法隱藏）需要回報用戶
- 下游 agent：PHP → `wordpress-master`；Vue / TSX → `react-master`（TSX）與 Vue 頁（遵守 `.claude/rules/vue-frontend.rule.md`）；測試 → `test-creator`；文件 → `doc-updater`
- Milestone：`specs/milestones/` 不存在，略過掛接
