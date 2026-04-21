# 實作計劃：Issue #12 — SLP 分期期數「0 期」仍顯示的 Bug 修復

## 概述

商家在 WC 後台取消勾選「中租分期 0 期」並儲存後，買家在 SLP hosted checkout page 仍看到「0 期（一次付清）」選項。需同步檢查信用卡分期是否有同樣問題，並修復至「商家設定什麼、SLP 顯示什麼」的一致狀態。範圍為 bug fix（HOLD SCOPE），以防彈資料流與回歸測試為核心。

## 需求重述

- **問題**：`installmentCounts` 設定從 WC option → REST → DTO → SLP API 的傳遞過程中，「空陣列」或「未設定」的語意與 SLP 平台端的 fallback 行為不一致，導致商家想「排除 0 期」時無法達成。
- **成功樣態**：
  1. 商家取消勾選信用卡「0 期」→ SLP checkout page 不顯示「0 期」。
  2. 商家取消勾選中租「0 期」→ SLP checkout page 不顯示「0 期」。
  3. 商家若完全未勾選任何期數，UX 層面必須給出明確回饋（PM 決策 A/B 案，見下）。
  4. 已儲存的舊資料無需遷移（HOLD SCOPE）。
- **不在範圍內**：重新設計期數 UI、支援自訂期數、移除支援的期數常數列表、其他付款方式的任何改動。

## 範圍模式

**HOLD SCOPE** — Bug fix，範圍已定。不追加功能、不重構相關檔案的無關部分。

---

## 已知風險（來自研究）

- **風險 1：SLP 平台端對「空 `installmentCounts`」的 fallback 行為未明文**
  SLP 官方文件（`mcp__slpayment-mcp__get_slpayment_docs`）首頁可取得但 Session API 子頁 schema 需實作者上 `https://docs.shoplinepayments.com/api/trade/session/` 查證。**很可能 SLP 收到 `installmentCounts: []` 或未送該 key 時，就以「全部支援期數」作為預設值**，這正是 bug 的觸發源。
  **緩解**：Red 階段必須先印出 `CreateSessionDTO::to_array()` 的 JSON payload，證明當商家取消勾選 0 期時，送到 SLP 的究竟是 `[]`、`[3,6,...]` 還是整個 key 不見。

- **風險 2：`ProviderUtils::update_option` 使用 `wp_parse_args`（淺層合併）**
  `inc/classes/Shared/Utils/ProviderUtils.php:93` 用 `wp_parse_args($values, $settings_array)`。top-level 的 `paymentMethodOptions` 會被整個前端 payload 覆蓋（預期行為）——但若**前端某次儲存時根本沒送 `paymentMethodOptions` key**（例如某個分支情境），DB 會保留舊值，導致「看似儲存成功但實際沒寫入」。
  **緩解**：Red 階段整合測試要驗證：呼叫 `POST /settings/shopline_payment_redirect` 後，`get_option('woocommerce_shopline_payment_redirect_settings')` 取出的陣列內 `paymentMethodOptions.ChaileaseBNPL.installmentCounts` 必須與 payload 完全一致。

- **風險 3：`PaymentMethodOption::$installmentCounts` 無預設值（未初始化）**
  `inc/classes/Domains/Payment/ShoplinePayment/DTOs/Components/PaymentMethodOption.php:22`：`public array $installmentCounts;`（無初始值）。若 DB 存的 `paymentMethodOptions.ChaileaseBNPL` 是 `[]`（沒有 installmentCounts key），DTO 屬性未初始化 → `DTO::to_array()` 會 skip 這個屬性 → 送給 SLP 的 payload 裡「沒有」`installmentCounts` key。這就可能觸發 SLP 的 fallback（= 顯示全部期數，包含 0）。
  **緩解**：方案是在 `PaymentMethodOption` 中把 `$installmentCounts` 初始化為 `[]`（或 `Options::create()` 確保 key 存在），讓 payload 一定帶 `installmentCounts` key，空陣列就代表「什麼都不顯示」。**但這一步必須先驗證 SLP 對空陣列的行為**（見風險 1）。

- **風險 4：`RedirectSettingsDTO::$paymentMethodOptions` 有 greedy 預設值**
  DTO 宣告時直接帶全部期數的預設值（見 `RedirectSettingsDTO.php:74-81`）。若 DB 從未存過 `paymentMethodOptions` key（全新安裝或舊版升級），讀回時會 fallback 到「全部期數」。對新安裝商家，第一次看到設定頁時預設勾選全部期數、使用者覺得沒問題；但一旦取消勾選 0 期並儲存，DB 會正確寫入不含 0 的陣列。**此預設值本身不是 bug，但放大了風險 3**。
  **緩解**：不改預設值（這是 UX 預期行為），改 `PaymentMethodOption` 初始化。

- **風險 5：測試 `paymentMethodOptions` 欄位不相容**
  `tests/e2e/02-frontend/payment-method-selection.spec.ts` 第 132-139 行的測試 payload 是 `{ CreditCard: { installment: true }, LinePay: {} }`——這**不符合 DTO schema**（`installment` 不是合法 key、`LinePay` 不在 4 個 options 中）。目前測試只驗證「不 crash」（`status < 600`）。修復時要考慮是否要加強這個測試，確認錯誤 payload 被正確處理（拒絕 or 忽略）。
  **緩解**：本次 bug fix 不擴大處理範圍；僅新增一組「合法 payload 寫入後回讀一致」的斷言。

---

## 架構變更

### 後端
1. `inc/classes/Domains/Payment/ShoplinePayment/DTOs/Components/PaymentMethodOption.php`
   - `public array $installmentCounts;` → `public array $installmentCounts = [];`（初始化為空陣列）
   - `to_array()`：無論屬性是否初始化，`installmentCounts` key 必須存在於輸出（目前 parent `to_array()` 會 skip 未 init 屬性）。由於加了預設值後屬性一定 initialized，parent 就會輸出，改動自然完成。
   - 驗證：若專案商業規則為「空陣列 = 禁用該付款方式」，可額外在 `validate()` 加「空陣列 → throw」的檢查。本計劃 **不加** validate 限制（避免阻斷 UX A 案），改在 Vue 前端攔截。

2. `inc/classes/Domains/Payment/ShoplinePayment/DTOs/Trade/Session/CreateSessionDTO.php`
   - 在 `create()` 過濾：若某個 method（CreditCard/ChaileaseBNPL）的 `installmentCounts` 為空，從 `allowPaymentMethodList` 移除該 method，讓 SLP 根本不顯示該付款方式。
   - **替代方案**：如果 SLP API 文件明確允許「送空陣列 = 不顯示任何期數、付款方式正常」則不需此過濾。**實作者在 Red 階段實測決定**，Red 測試要覆蓋兩種策略。

### 前端
3. `js/src/pages/Payments/SLP/index.vue`
   - **UX 防呆（PM 決策 A/B 案）**：
     - **A 案（保守）**：`rules` 裡加 validator：若 `allowPaymentMethodList` 包含 `CreditCard`/`ChaileaseBNPL` 但對應 `installmentCounts` 長度為 0，則儲存時擋下並提示「請至少選擇一種期數」。
     - **B 案（放寬）**：允許儲存空陣列，在 checkbox-group 下方顯示 `el-alert`（warning）「目前未選擇任何期數，此付款方式將不會在結帳頁顯示」。
   - `watch(data, ...)` 的 `merge(form, filteredData)` 保持不變（lodash merge 對陣列是「以新陣列取代」的語意，已 OK；但 pick 之前 `form.paymentMethodOptions` 的巢狀結構需確保 filteredData 可以完整覆蓋）。
   - **確認點**：實作者需在 Red 階段以 `console.log(toRaw(form))` 或 devtool 驗證「儲存時送出的 JSON 確實為 `paymentMethodOptions.ChaileaseBNPL.installmentCounts: ['3','6']`」。

### PM 決策（需在計劃確認階段回覆）

| 方案 | 利 | 弊 |
| --- | --- | --- |
| **A 案 — 阻止儲存** | UX 明確；避免商家儲存後不知道付款方式消失；與 `allowPaymentMethodList` 至少選一種的現有 validator 一致 | 較嚴格；商家若想「只啟用信用卡、不啟用分期」會卡住（需同時取消勾 CreditCard/ChaileaseBNPL） |
| **B 案 — 允許儲存但警告** | 彈性；商家可先儲存再調整 | 商家可能忽略警告，買家端付款方式消失時才察覺 |

**推薦：A 案**，理由：現有 `allowPaymentMethodList` 已採「至少選一種」阻擋策略，保持一致性。B 案在 SLP 端的「付款方式消失」屬於 silent failure，debug 成本高。

---

## 資料流分析

### 寫入流程（Admin 設定儲存）

```
Vue Form ──▶ toRaw(form) ──▶ POST /settings/{id} ──▶ sanitize_text_field_deep ──▶ wp_parse_args ──▶ update_option
    │              │                │                        │                          │                │
    ▼              ▼                ▼                        ▼                          ▼                ▼
[空陣列?]     [ref 洩漏?]      [nonce 過期?]          [key 被過濾?]                [top-level 覆蓋]     [write fail?]
[未選?]       [序列化漏?]      [403?]                 [false-y 保留?]              [巢狀保留舊值?]     [option 不存在?]
```

**檢查點**：
- 寫入後，用 `wp_cache_delete('options', 'options')` + `get_option()` 重讀，斷言 `paymentMethodOptions.ChaileaseBNPL.installmentCounts === ['3','6']`。

### 讀取流程（送給 SLP）

```
get_option ──▶ RedirectSettingsDTO::instance() ──▶ CreateSessionDTO::create ──▶ PaymentMethodOptions::create ──▶ to_array ──▶ JSON to SLP
    │                     │                                 │                              │                          │              │
    ▼                     ▼                                 ▼                              ▼                          ▼              ▼
[key 不存在?]     [預設值 fallback]              [order 不完整?]                 [type 錯誤?]                [空陣列 sort?]  [SLP fallback?]
[型別錯?]         [merge 汙染?]                  [amount 0?]                     [未初始化 skip?]            [key 消失?]     [顯示全部?]
```

**Critical gap**：`PaymentMethodOption::$installmentCounts` 未初始化 → `to_array()` skip → SLP 收不到該 key → fallback 顯示全部。

### 結帳 → SLP 互動流程

```
WC Place Order ──▶ process_payment ──▶ ApiClient::create_session ──▶ SLP hosted page renders ──▶ user sees installment options
                                                    │                            │
                                                    ▼                            ▼
                                           [installmentCounts key 存在?]   [顯示全部? 僅商家選的?]
                                           [是空陣列?]                     [SLP fallback 規則]
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| Vue `onSubmit` | `allowPaymentMethodList` 包含 CreditCard 但 `installmentCounts` 空 | 驗證失敗 | Element Plus form validator 阻擋（A 案）或 el-alert warning（B 案） | 是（表單紅字 / 警告） |
| `POST /settings/{id}` | nonce 過期 | 403 | apiClient interceptor 提示重新整理 | 是 |
| `POST /settings/{id}` | JSON 反序列化失敗 | 500 | `try/catch \Throwable` → `Plugin::logger()` → 回 500 通用錯誤 | 是（通用錯誤） |
| `ProviderUtils::update_option` | `update_option` 回 false（寫入失敗） | DB 寫入失敗 | 現況無處理（直接回 `$settings_array`） | **GAP — 靜默** |
| `CreateSessionDTO::create` | `installmentCounts` 空 + `allowPaymentMethodList` 包含該 method | 業務邏輯衝突 | 過濾 allowPaymentMethodList（移除空期數的 method）或拋例外 | 是（無法結帳或該方式消失） |
| `PaymentMethodOption::validate` | `installmentCounts` 含非數字 | DTO 驗證 | DTO 本身 throw Exception，被 DTO::__construct 的 `wp_get_environment_type === local` 決定 rethrow 或 log | 看環境 |
| SLP API 回 4xx（`installmentCounts` 不合法） | SLP schema 拒絕 | 遠端錯誤 | `AbstractPaymentGateway::logger` → order note + wc_add_notice | 是 |

**GAP 處理**：`ProviderUtils::update_option` 寫入失敗目前靜默，但已存在於現有程式碼中、不在本次 bug fix 範圍。**本次不處理，記錄於計劃、留給未來 issue**。

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| Vue 儲存空期數 | 商家誤以為已儲存但結帳端消失 | ❌（現況） | ❌ | 否（silent） | A 案阻擋 or B 案警告（本次新增） |
| DTO to_array skip | 送 SLP 不含 installmentCounts | ❌（現況） | ❌ | 否（silent） | 初始化 `[]`（本次新增） |
| SLP fallback 顯示全部 | 買家看到 0 期 | ❌（現況） | ❌ | 是（就是 bug） | 本次修復 |
| `wp_parse_args` 舊值殘留 | 商家局部更新，其他 key 保留 | ✅（預期行為） | ✅（settings-get-single.spec.ts 邊緣） | —— | —— |
| 前端 lodash merge 陣列 | v4.17+ merge 對陣列是「索引合併」非「取代」 | ⚠️ 需驗證 | ❌ | 否 | Red 階段驗證；若有問題改用 `Object.assign` / 明確指派 |
| 既有商家 DB 無 paymentMethodOptions | DTO fallback 全部期數 | ✅（預期） | ❌ | 否 | 無須遷移（見下方） |
| 既有商家 DB 有空 installmentCounts | 送 SLP 無 key → 全期數 | ❌ | ❌ | 是 | 本次修復（初始化 `[]`）後，回存時會帶 key |

---

## 資料遷移

**結論：不需 migration，但需補充「被動修正」機制**。

- 既有商家資料三種狀態：
  1. **狀態 A — DB 無 `paymentMethodOptions` key**：升級後 DTO fallback 為全部期數（含 0）。與現況一致，無回歸。商家下次儲存時會寫入當前勾選狀態。
  2. **狀態 B — DB 有 `paymentMethodOptions` 但某 method 無 `installmentCounts` key**：升級後 `PaymentMethodOption::$installmentCounts = []`（本次新增的預設），`to_array()` 會送 `[]` 給 SLP。若採 A 案 UX + 後端 `CreateSessionDTO` 過濾「空期數 method」自 allowPaymentMethodList 移除，則結帳頁不顯示該付款方式（符合商家意圖或需商家重新設定）。**商家下次打開設定頁儲存即修正**。
  3. **狀態 C — DB 有完整 `installmentCounts`（含 '0'）**：商家原本就要 0 期，升級後行為不變。
- **升級相容性風險**：狀態 B 的商家若沒重新儲存、又沒勾過 CreditCard/ChaileaseBNPL 期數，會突然發現該付款方式消失。**緩解**：Release notes 明確提示「升級後請進入 SLP 設定頁檢查『信用卡分期期數』與『中租分期期數』至少各勾選一項」。

---

## 實作步驟

### 第一階段：根因驗證（Red 前置調查）

1. **印出 payload 確認真兇**（檔案：新增 debug 測試 `tests/Integration/Payment/InstallmentCountsDebugTest.php`）
   - 行動：寫一個暫時性 debug test，模擬：(a) DB 存 `paymentMethodOptions.ChaileaseBNPL.installmentCounts = ['3','6']`；(b) DB 存 `paymentMethodOptions.ChaileaseBNPL = []`；(c) DB 無 `paymentMethodOptions`。各建立 `CreateSessionDTO` 後 `var_export($dto->to_array())` 並斷言 JSON 結構。
   - 原因：搞清楚 DTO skip 未初始化屬性、fallback 預設值等行為，決定要改 `PaymentMethodOption` 還是 `CreateSessionDTO::create()` 還是兩者都改。
   - 依賴：無
   - 風險：低
   - **完成條件**：三種 DB 狀態下的 payload JSON 明確被列出，bug 重現在 state (b)。

2. **查證 SLP 端對空 `installmentCounts` 的行為**（檔案：無——只查文件）
   - 行動：上 `https://docs.shoplinepayments.com/api/trade/session/` 或透過 `mcp__slpayment-mcp__get_slpayment_docs` 追 `paymentMethodOptions` schema；若文件模糊，在 sandbox 用 `composer test:sandbox` 實測一次。
   - 原因：決定「送空陣列」是否會被 SLP 接受（= 不顯示期數）或 fallback 全部。
   - 依賴：步驟 1
   - 風險：中（外部依賴）
   - **決策輸出**：選擇「過濾 allowPaymentMethodList」（方案 1）或「送空陣列 OK」（方案 2）。

### 第二階段：測試先行（Red）

3. **整合測試：DTO 序列化行為**（檔案：`tests/Integration/Payment/RedirectGatewayValidationTest.php` 加測試；或新檔 `tests/Integration/Payment/PaymentMethodOptionSerializationTest.php`）
   - 測試 1：`CreditCard` 勾選 `['3','6']`（不含 0）→ `to_array()` 的 `paymentMethodOptions.CreditCard.installmentCounts === ['3','6']`（sort 後），且**陣列不含 '0'**。
   - 測試 2：`ChaileaseBNPL` 勾選 `['3','6','12']` → 同上斷言。
   - 測試 3：DB 存 `paymentMethodOptions.ChaileaseBNPL = []`（沒 installmentCounts key）→ 在修復後，`PaymentMethodOption` 的 `installmentCounts` 屬性初始化為 `[]`，`to_array()` 有 `installmentCounts: []` key（**currently fails**）。
   - 測試 4：DB 無 `paymentMethodOptions` → DTO fallback 為全部期數（保持現況，非 bug）。
   - 原因：鎖定「CreateSessionDTO 送出去的 payload 內容」的正確性。
   - 依賴：步驟 1, 2
   - 風險：低

4. **整合測試：REST 寫入-讀回一致性**（檔案：`tests/Integration/Settings/SettingApiServiceTest.php`——若不存在則新建）
   - 測試：`POST /settings/shopline_payment_redirect` 帶 `paymentMethodOptions.ChaileaseBNPL.installmentCounts = ['3','6']` → 讀 `get_option` → 驗證完全一致。
   - 測試：同樣 payload 再 POST 一次、內容改成 `['6','12']` → 讀回必須是 `['6','12']` 而非 `['3','6','6','12']`（驗證 `wp_parse_args` 不會累加陣列）。
   - 原因：驗證 `ProviderUtils::update_option` + `wp_parse_args` 對巢狀陣列的覆寫正確性。
   - 依賴：無
   - 風險：低

5. **E2E 測試：前端設定頁防呆 UX**（檔案：`tests/e2e/01-admin/slp-installment-settings.spec.ts`——新建）
   - 測試 1（A 案）：進入 SLP 設定頁 → 取消勾選所有 `ChaileaseBNPL` 期數 → 按儲存 → 看到「請至少選擇一種期數」紅字 → 儲存按鈕未觸發 POST。
   - 測試 2：勾選 `['3','6']` 後儲存 → API 呼叫成功 → 重新整理頁面 → checkbox 狀態正確回填 `['3','6']`。
   - 測試 3（回歸）：取消勾 `CreditCard` 的 0 期 → 儲存 → 重新整理 → 0 期未被勾選。
   - 原因：UX 層防止 silent failure，並回歸「0 期取消後確實持久化」。
   - 依賴：PM 決策 A/B
   - 風險：低

6. **E2E 測試：SLP payload 檢查（可選，視 Red 階段結論）**
   - 若第二階段步驟 2 決定採「CreateSessionDTO 過濾 allowPaymentMethodList」的方案，加一個 PHPUnit 測試驗證：當 `installmentCounts` 為空時，`CreateSessionDTO::create()` 產生的 `allowPaymentMethodList` 不含該 method。
   - 若採「送空陣列 OK」方案，則僅驗證 payload 內 `installmentCounts === []` 即可。
   - 依賴：步驟 2 的決策

### 第三階段：實作（Green）

7. **修改 PaymentMethodOption DTO**（檔案：`inc/classes/Domains/Payment/ShoplinePayment/DTOs/Components/PaymentMethodOption.php`）
   - 行動：`public array $installmentCounts;` → `public array $installmentCounts = [];`
   - 原因：讓屬性一定 initialized，`to_array()` 一定帶 key，避免 SLP 走 fallback。
   - 依賴：測試 3 已存在且紅燈
   - 風險：低
   - **注意**：原 `validate()` 的 `if (isset($this->installmentCounts))` 判斷依然有效（`isset` 對陣列 always true），validate 仍然跑 `array_every` 檢查。

8. **修改 CreateSessionDTO**（檔案：`inc/classes/Domains/Payment/ShoplinePayment/DTOs/Trade/Session/CreateSessionDTO.php`）
   - **方案 1（若 SLP 對空陣列 fallback 全期數）**：在 `create()` 組 args 前，檢查 `$settings->paymentMethodOptions[$method]['installmentCounts']` 是否為空；若是，從 `$settings->allowPaymentMethodList` 移除該 method。
   - **方案 2（若 SLP 接受空陣列=不顯示期數）**：不改 CreateSessionDTO，只靠前端 UX 防呆即可。
   - 原因：確保送給 SLP 的 allowPaymentMethodList 與 installmentCounts 語意一致。
   - 依賴：步驟 2 決策、步驟 7
   - 風險：中（誤殺合法 payload）

9. **修改 Vue 設定頁**（檔案：`js/src/pages/Payments/SLP/index.vue`）
   - **A 案**：在 `rules` 加 `paymentMethodOptions.CreditCard.installmentCounts`、`paymentMethodOptions.ChaileaseBNPL.installmentCounts` 的 validator：當 `allowPaymentMethodList` 包含該 method 時，`installmentCounts.length === 0` → callback 錯誤。
   - **B 案**：在兩個 checkbox-group 下方條件渲染 `<el-alert v-if="... && form.paymentMethodOptions.X.installmentCounts.length === 0" type="warning" :closable="false" show-icon>未選擇任何期數，此付款方式將不會顯示</el-alert>`。
   - 原因：UX 防呆，避免 silent failure。
   - 依賴：PM 決策
   - 風險：低

10. **前端驗證 payload 完整性**（檔案：`js/src/pages/Payments/SLP/index.vue`）
    - 行動：不改 `merge(form, filteredData)` 邏輯，但在 Red 階段需確認 `toRaw(form)` 的 `paymentMethodOptions` 確實包含完整巢狀結構，`apiClient.post` 的 JSON body 如預期。若發現 lodash `merge` 對陣列有索引合併問題（實際取代而非合併），改用 `form.paymentMethodOptions = filteredData.paymentMethodOptions ?? form.paymentMethodOptions`。
    - 依賴：步驟 1
    - 風險：中（lodash merge 陷阱）

### 第四階段：重構（Refactor）

11. **一致性掃描**
    - 用 `/aho-corasick` skill 掃 `installmentCounts` 所有引用，確認：
      - `PaymentMethodOption.php`（已改）
      - `RedirectSettingsDTO.php`（預設值不變）
      - `CreateSessionDTO.php`（已改 or 維持）
      - `js/src/pages/Payments/SLP/index.vue`（已改）
      - `js/src/pages/Payments/SLP/Shared/types.ts`（可能需要對齊 type 定義）
      - E2E / Integration 測試檔（已加）
    - 原因：避免殘留引用。

12. **更新 CLAUDE.md「Refund support by payment method」表下方**（若必要）
    - 如果採方案 1（空期數自動從 allowPaymentMethodList 移除），在 CLAUDE.md 的 Shopline Payment Flow 區塊增加一行說明商業規則。

### 第五階段：驗收

13. **回歸測試**
    - `composer test`（API_MODE=mock）全綠
    - `cd tests/e2e && npx playwright test --grep "slp-installment"` 全綠
    - `pnpm lint`、`vendor/bin/phpstan analyse` 無新錯誤
    - 手動測試：在本地 WP 環境，取消勾選 ChaileaseBNPL 0 期、存檔、建立 WC 訂單跳轉到 SLP，確認 0 期不顯示。

---

## 測試策略

- **PHP 整合測試**：
  - `tests/Integration/Payment/PaymentMethodOptionSerializationTest.php`（新建）
  - `tests/Integration/Settings/SettingApiServiceTest.php`（新建，若不存在）
- **E2E 測試（Playwright）**：
  - `tests/e2e/01-admin/slp-installment-settings.spec.ts`（新建）
  - 視情況更新 `tests/e2e/02-frontend/payment-method-selection.spec.ts` 的 `paymentMethodOptions` 區塊
- **前端單元測試**：本專案無 Vitest 設定，暫不新增；改由 E2E 覆蓋 UX。
- **測試執行指令**：
  - `vendor/bin/phpunit --filter PaymentMethodOptionSerialization`
  - `vendor/bin/phpunit --filter SettingApiService`
  - `cd tests/e2e && npx playwright test --grep "slp-installment"`
- **關鍵邊界情況**：
  - 商家完全未勾選任何期數（A 案 = 擋；B 案 = 允許 + warning）
  - 商家勾選但只剩 0 期（反向情境）
  - `allowPaymentMethodList` 不含 CreditCard/ChaileaseBNPL 時，即使期數為空也不應擋儲存
  - 既有商家 DB 無 `paymentMethodOptions` key（fallback 路徑）
  - 同一設定連存兩次，確認陣列不會累加

---

## 依賴項目

- Vue 3 / Element Plus（form validator、el-alert）
- TanStack Vue Query（無變更）
- PHPUnit 9 + wp-env（整合測試）
- Playwright（E2E）
- `mcp__slpayment-mcp__get_slpayment_docs` MCP server（查 SLP API 文件）

---

## 風險與緩解措施

- **高**：SLP 對空 `installmentCounts` 的 fallback 行為未確認 → Red 階段必查文件或 sandbox 實測。
- **中**：前端 lodash `merge` 對陣列的行為可能誤覆蓋 → Red 階段 `console.log(toRaw(form))` 驗證。
- **中**：既有商家升級後若 DB 為狀態 B，可能發現付款方式消失 → Release notes 提示。
- **低**：`CreateSessionDTO::create` 過濾 allowPaymentMethodList 可能誤殺 → 整合測試覆蓋。

---

## 錯誤處理策略

- **前端層**：Element Plus form validator 阻擋送出（A 案）或 el-alert 警告（B 案）。失敗時不呼叫 API、顯示紅字。
- **REST 層**：維持現有 `try/catch \Throwable` → `Plugin::logger()`，不新增 error code。
- **DTO 層**：`PaymentMethodOption::validate()` 維持現有驗證；`installmentCounts` 空陣列**允許通過**（因為 A 案已在前端擋）。
- **SLP 互動層**：若採方案 1，`CreateSessionDTO::create()` 自動濾掉空期數 method，買家結帳時看不到該方式——已是可接受的業務行為。

---

## 限制條件（計劃不會做的事）

- 不新增「自訂期數」功能
- 不修改 ECPay AIO 或其他付款方式
- 不改 `ProviderUtils::update_option` 的 `wp_parse_args` 策略（超出範圍）
- 不寫 DB migration script（依「資料遷移」章節結論，被動修正即可）
- 不改 `PaymentMethodOption::validate()` 去禁止空陣列（避免與前端 B 案衝突）
- 不更動 `paymentMethodOptions` 的 API schema（維持 SLP 原格式）

---

## 成功標準

- [ ] PM 確認 A/B 案選擇（建議 A 案）
- [ ] Red 階段的 3 個整合測試 + 2 個 E2E 測試全部紅燈、Green 後全綠
- [ ] 手動測試：取消勾選 ChaileaseBNPL 0 期 → 買家 SLP checkout 看不到 0 期
- [ ] 手動測試：取消勾選 CreditCard 0 期 → 買家 SLP checkout 看不到 0 期
- [ ] `pnpm lint`、`phpstan` 無新錯誤
- [ ] Release notes 有「升級提示」
- [ ] 相容性掃描：`installmentCounts` 所有引用已同步

---

## 預估複雜度：**中**

- 後端改動點少（1 行 DTO 預設值 + 可能 1 個 CreateSessionDTO 過濾邏輯）
- 前端改動點少（1 個 validator or 1 個 el-alert）
- 測試覆蓋需要 4~5 個新測試
- 不確定性主要在 SLP API 對空 `installmentCounts` 的行為——Red 階段必須先確認

---

## PM 待決策事項（實作前必須確認）

**Q：商家在設定頁取消勾選所有「信用卡分期期數」或「中租分期期數」，系統應如何反應？**

- **A 案（推薦）**：阻止儲存，顯示紅字「請至少選擇一種期數」
- **B 案**：允許儲存，顯示黃色警告「未選擇任何期數，此付款方式將不會顯示」

推薦 A 案，與現有 `allowPaymentMethodList` 的「至少選一種」策略一致。
