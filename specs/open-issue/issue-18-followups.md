# Issue #18 衍生待辦（本次刻意不做，需獨立處理）

> 來源：issue #18（SLP 跳轉付款導回不認列）修復過程中發現。
> 四項皆經 acceptance-evaluator 獨立驗收確認「不阻擋本次上線」，但都是真實缺口，不該只留在對話紀錄裡。
> 第 1~3 項是需要動手的待辦，第 4 項純屬環境事實記錄。
> 狀態：**尚未開 GitHub issue**（對外動作待 user 決定）。

---

## 1. `$required_properties` 拼錯 20 處 —— 必填驗證從未生效（最高優先）

### 症狀

20 個 DTO 宣告的「必填欄位」完全沒有被驗證。欄位缺席時不會 throw，屬性留在「未初始化 typed property」狀態，直到後續有人存取才拋 `Error` —— 而且往往拋在離根因很遠的地方。

### 根因

`vendor/j7-dev/wp-utils` 的 DTO 基底只認 **`require_properties`**：

```php
// vendor/j7-dev/wp-utils/src/Classes/DTO.php
:19    protected array $require_properties = [];
:212   foreach ( $this->require_properties as $property ) {
```

但專案裡 20 個檔案寫成 **`required_properties`**（多一個 `d`），基底完全不認得，等同沒宣告。

### 影響範圍（`grep -rn "required_properties" inc/ --include=*.php | grep -v "require_properties"`）

**SLP 金流 18 處**
- `Payment/ShoplinePayment/DTOs/Components/` — `Address.php` / `Amount.php` / `Billing.php` / `Client.php` / `CreditCard.php` / `Customer.php` / `ErrorMessage.php` / `PaymentDetail.php` / `PersonalInfo.php` / `VirtualAccount.php`
- `Payment/ShoplinePayment/DTOs/Components/Order/` — `Order.php` / `Product.php` / `Shipping.php`
- `Payment/ShoplinePayment/DTOs/Components/Webhook/` — `Address.php` / `Customer.php` / `Order.php` / `PersonalInfo.php`
- `Payment/ShoplinePayment/DTOs/RequestHeader.php`

**Amego 發票 2 處**
- `Invoice/Amego/DTOs/IssueInvoiceParamsDTO.php`
- `Invoice/Amego/DTOs/IssueInvoiceResponseDTO.php`

### 它已經造成實害（不是理論風險）

issue #18 修復時，`StatusManager` 的金額守衛改比對 `payment->paidAmount`（實付）。在 `paidAmount` 缺席的情境下：

```
StatusManager 四道守衛通過（降級用 order->amount 比對成功）
  → PaymentDTO::to_human_html()          （畫 order note）
  → Payment::to_human_array()
  → Amount::to_human_array()             （Amount.php:57）
  → (float) $this->value / 100           （Amount.php:63，未初始化 typed property）
  → Error
  → 被 ReturnSyncManager / WebHook 的 never-throw 邊界吸收
  → 付款靜默不認列，webhook 照樣回 200
```

**守衛放行了，訂單卻沒認列，而且無聲無息。** 突變測試 MUT-H 證實：拿掉 `StatusManager::get_payment_detail_html()` 的降級保護 → `Tests: 27, Errors: 1`（是 Errors 不是 Failures，真的拋出來）。

本次的繞法是 `StatusManager` 一律用 `$dto->to_array()` 取值、不直接存取屬性（見 `read_amount()` / `get_payment_detail_html()`），**沒有動底層 DTO**。

### 為什麼本次不做

推理 trace：

- **X1**：這 20 個 DTO 的必填驗證**從未生效**過
- **X2**：修對拼字 = **一次喚醒 20 個休眠驗證**
- **X3**：真實 payload 只要任一欄位缺席，行為就從「靜默略過」翻轉成「直接 throw」
- **X4**：涵蓋 SLP 金流 + Amego 發票，全都是生產路徑
- **→ Y**：這是「改一個字會引爆 20 個潛在 regression」的典型，blast radius 遠超 issue #18。混進本次等於拿 issue #18 的交付去賭

### 動手時的注意事項

1. **不要批次 sed 取代**。逐個 DTO 盤點真實 payload：該欄位在所有情境下都一定有值嗎？（例：`paidAmount` 在 ATM 取號階段就沒有）
2. 對「可能合法缺席」的欄位，先補預設值或降級路徑，**再**開必填
3. 每開一個 DTO 的驗證就跑一次全金流回歸，不要開完 20 個才跑
4. 特別注意 webhook / callback 路徑：第三方回傳的 payload 欄位可能隨付款方式而異，比我方自己組的 request DTO 更容易缺欄位
5. 新寫的 DTO 一律用 `require_properties`（無 `d`）

---

## 2. E2E 兩支測試斷言過弱（key 已修，但仍不會紅）

### 症狀

`tests/e2e/02-frontend/` 兩支測試提供**假保護** —— 就算它們驗的行為壞掉也不會轉紅。

### 現況

meta key 名已於 issue #18 修復時一併改對（`'pc_payment_identity'` → `'_pc_payment_identity'`，兩檔各 1 處），但**斷言強度沒改**：

**`order-status-display.spec.ts:138-143`**
```ts
const pcPaymentIdentity = metaData?.find((m) => m.key === '_pc_payment_identity')
if (pcPaymentIdentity) {                    // ← 抓不到就整段跳過
  expect(pcPaymentIdentity.value).toBeTruthy()
}
```
測試名稱是「訂單 meta_data 中應有 pc_payment_identity」，但斷言包在 `if` 裡 —— **meta 不存在時零斷言執行、測試照樣綠**，正好與測試名稱要驗的事相反。

**`webhook-callback.spec.ts`**
下游 8 處（`:108/123/134/145/156/170/182/193`）唯一的斷言是 `expect(res.status).toBeLessThan(600)` —— 這只驗「伺服器沒炸」，任何 4xx / 5xx 都算通過。

### 為什麼值得修

`order-status-display.spec.ts` 驗的正是 **issue #18 的症狀本身**（客戶在 order-received 看到的訂單狀態）；`webhook-callback.spec.ts` 的 8 支跑的正是「webhook 找不到訂單」路徑 —— **那是 issue #18 缺陷 C 的核心症狀**。

這兩支本來最有機會在生產事故前攔下 issue #18，卻因為 key 打錯 + 斷言過弱而從頭到尾沒有察覺。

### 為什麼本次不做

改斷言強度會讓這兩支測試**開始真的驗東西**，可能立刻轉紅（原本就沒在驗，紅了才是正常）。那需要先確認 E2E 環境跑得起來、再逐一釐清紅的是真 bug 還是測試假設過時 —— 屬於獨立工作，與 issue #18 的 PHP 修復無關。

### 動手時的注意事項

1. `tests/e2e/` 有獨立的 `package.json` 與 `playwright.config.ts`，需要跑得起來的站台，不在 PHPUnit / wp-env 範圍內
2. `order-status-display.spec.ts`：拿掉 `if` 包裹，改成強制斷言（`expect(pcPaymentIdentity).toBeDefined()` 再驗 value）
3. `webhook-callback.spec.ts`：把 `toBeLessThan(600)` 改成明確的預期狀態碼。注意 issue #18 修復後的契約是 **驗簽不符 → 401、其餘一律 → 200（絕不 500）**
4. 修完應該要能反向驗證：故意把 `OrderResolver` 的備援拆掉，這兩支要轉紅

---

## 3. `get_session()` 主動補單路徑（原第六階段）

### 症狀

`Http/ApiClient.php:63 get_session()` 目前**零呼叫端**。

issue #18 修復讓它從「必定 throw 的死碼」變成「可用但沒人用的 API」—— `MetaKeys::update_identity()` 已於結帳當下把 `sessionId` 落盤（`RedirectGateway.php:61`，刻意寫在 EXPIRED 判斷之前），所以 `QuerySessionDTO::create()` 現在讀得到值。

### 適用情境：救「SLP 已停止重試」的舊單

issue #18 缺陷 C 造成一批舊訂單卡在「等待付款」（客戶已付款但沒導回 → `_pc_payment_identity` 從未寫入 → webhook 查無訂單 → 回 500 → SLP 重試）。

**修復上線後，SLP 的下一次重試會靠 `referenceOrderId` 備援自動認列** —— 這條路已由突變測試 MUT-C 證實可行（拆掉備援 → 舊單救援測試立刻紅），所以**多數舊單不需要主動補單**。

但若某些訂單的**重試次數已耗盡、SLP 不再推送**，那批就只能主動查。而且：

> SLP 的 `/trade/payment/get` **只吃 `tradeOrderId`**（見 `.claude/skills/shopline-payments-v1/references/api-reference.md:219`）。
> 「客戶從未導回」的訂單根本沒有 `tradeOrderId`，
> **唯一的主動查詢途徑就是 `/trade/sessions/query`（吃 `sessionId`）** —— 這正是 `update_identity()` 必須在結帳當下落盤的理由。

### 為什麼本次不做

觸發點只可能是後台按鈕或排程 = 原計畫書的第六階段（`pc_slp_query_trade` / `QueryTradeManager`），該階段已明確排除本次範圍。硬補等於把排除掉的東西換個名字做回來。

計畫書位置：`specs/open-issue/issue-18-plan.md:475`、`:632`（標註「（P3 選配）」）。

### 動手時的注意事項

1. **先確認真的有救不回來的單**：查生產站 `power-checkout-*.log` 是否仍出現「找不到訂單，tradeOrderId: ...」。**還在出現 → 重試通道活著 → 舊單會自己回來，不需要這個功能。**
2. 流程是「查 session → 從回應的 `paymentDetails[].tradeOrderId` 取回 → 再走 `StatusManager` 認列」，不是直接用 sessionId 認列
3. 必須複用既有的四道守衛（冪等 / 終態 / 幣別 / 金額），不要另寫一套
4. 補單是管理員手動觸發的高權限動作，需要 nonce + capability 檢查
5. `ApiClient.php:55-64` 的 docblock 已標註此保留用途，動手前先讀

---

## 4. `phpcs.xml:24` 的 `parallel=8` 是 no-op（純記錄，無害）

`phpcs.xml:24` 設了 `<arg name="parallel" value="8"/>`，但 wp-env 容器內 **`pcntl_fork` 不存在**：

```bash
$ npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
    php -r 'echo function_exists("pcntl_fork") ? "AVAILABLE" : "MISSING";'
→ MISSING
```

PHPCS 10.39.0 在 pcntl 缺席時**靜默退回單進程序列執行**，不會報錯也不會警告。

**影響**：無功能問題，只是這個設定沒有生效。若日後有人想靠它加速 lint，需要知道它在此環境是死的（要嘛在容器裝 pcntl 擴充，要嘛在有 pcntl 的環境跑）。

**附帶價值**：這個事實在一次查證中派上用場——PHPCS 曾出現兩次結果不同（`1 ERROR/5 FILES` → `0 ERRORS/4 FILES`），有人歸因為「與 deadlock 同源的並行干擾」。`parallel=8` 一度讓這個說法看似合理，但 pcntl 缺席直接斷了它；再加上同容器內同時跑 3 個 PHPCS 得到 byte-identical 輸出，證實 **PHPCS 在此環境完全確定性**，真正原因是檔案內容在兩次跑之間變了。

順帶記一條方法論：**deadlock 歸因只對「經由 `WP_UnitTestCase` 外層交易碰 MySQL」的工具成立**——PHPUnit 在邊界內，PHPCS / PHPStan / eslint / tsc 全在邊界外。歸因要能指出機制，機制要能被實驗證偽。

---

## 附註：本次已修、無須再處理

以下在 issue #18 修復中一併解決，列此避免重複開單：

- `.claude/CLAUDE.md` Order Meta Keys 表格三行缺前導底線（HEAD 就錯，已補）
- `.claude/skills/shopline-payments-v1/references/api-reference.md:212` 端點 `trade/orders/query` → `trade/payment/get`（並補「不接受 referenceOrderId」警語）
- `Shared/Traits/TradeOrderIdTrait.php` docblock `(32)` → `(64)`（`SessionIdTrait` 的 `(32)` 正確，未動）
- E2E 兩處 meta key 名（斷言強度見上方第 2 項）

## 附註：已確認不是問題

- `QuerySessionDTO.php:47` 的 `sessionId` 長度上限 32 —— 官方規格 `api-reference.md:125` 就是 `String(32)`，與 `tradeOrderId` 的 `String(64)` 不同型，維持 32 正確
- `WebhookSignatureTest.php:391,451` 的 `$response` 在 try 內賦值 —— 例外會穿過 `finally` 繼續上拋，PHPUnit 以原始例外報錯，走不到該行，非正確性問題
