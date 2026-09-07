# 實作計劃：Issue #18 — SLP 跳轉付款導回不認列付款（客戶看到「尚未付款」）

## 概述

SHOPLINE Payment 跳轉式金流（`shopline_payment_redirect`）付款成功後，客戶導回
`order-received` 頁仍顯示「尚未付款」。根因是 `before_order_received()` 只儲存
`tradeOrderId`，**不查詢、不認列付款**，把付款認列 100% 委託給非同步 webhook，形成
race condition。連帶暴露三個結構性缺陷（webhook 回 500 導致 SLP 無限重試、webhook 查單
主鍵依賴客戶瀏覽器導回、sessionId 從未寫入使 `get_session()` 為死碼）。

本計劃將四個缺陷一次修完，並補上本 gateway 缺席已久的**金額防竄改 / 冪等 / 終態守衛**
（其他 5 個金流皆已具備，SLP 是唯一缺口）。

真實案例：skyisland.tw 訂單 #40165（NT$48,800，Power Checkout 1.3.1）。

---

## 範圍模式

**Bug fix + 安全補強**（非新功能）。不改動 SLP 的付款流程架構、不改前端 Vue/Blocks、
不新增 REST 端點（P3 選配的後台 order action 除外）。

---

## 已知風險（來自研究，全部已於程式碼驗證）

### R1. `before_order_received()` 掛在 `wp` action，每次載入 order-received 都會跑

`AbstractPaymentGateway::before_page_render()`（`inc/classes/Domains/Payment/Shared/Abstracts/AbstractPaymentGateway.php:321-337`，
`final`，由 `add_action('wp', [$this, 'before_page_render'])` 於 L97 註冊）在每次頁面
載入時執行。因此「在此同步查詢」技術可行，**但必須有冪等 + 節流**，否則客戶每按一次
重新整理就打一次 SLP API。

同一方法在呼叫 `before_order_received()` 之後才執行 `WC()->cart->empty_cart()`——
**若我們的新程式碼 throw，購物車不會被清空**（客戶會看到舊購物車），這是 never-throw
鐵律在此處的額外理由。

### R2. `Requester::TIMEOUT = 60`（`Shared/Helpers/Requester.php:24`）

60 秒是給後台非同步流程用的。若原封不動用在 thankyou 頁同步查詢，SLP 一旦卡住，
客戶會**盯著白畫面 60 秒**——比原 bug 更糟。同步路徑必須改用短 timeout（建議 10 秒）。

### R3. `$_GET['tradeOrderId']` 目前未經驗證即寫入 `_pc_payment_identity`

`RedirectGateway.php:144,156`。而 `_pc_payment_identity` 正是 webhook 的查單主鍵
（`WebHook.php:69,179`）。任何人只要知道 order id 就能打
`/checkout/order-received/{id}/?tradeOrderId=<任意值>` 汙染此 meta：

- 汙染自己的訂單 → 讓自己的 webhook 找不到訂單（自傷，低危）。
- **把受害者的 tradeOrderId 寫進攻擊者自己的訂單 → 受害者付款的 webhook 反查到攻擊者
  的訂單 → 攻擊者訂單被標記為已付款（高危，可白嫖）**。

本計劃以三道閘門封死：`order_key` 比對 → `referenceOrderId` 必須等於本訂單 ID →
金額 / 幣別守衛。且 `_pc_payment_identity` 改為**驗證通過後才寫**。

### R4. `PaymentDTO` 的 `referenceOrderId` 是必填且由我方送出

`CreateSessionDTO::create()`（`DTOs/Trade/Session/CreateSessionDTO.php:77`）建立 session 時
送 `referenceId = $order->get_id()`；SLP 於 webhook / 查詢回應以 `referenceOrderId` 回傳，
且列於 `PaymentDTO::$require_properties` 與 `RefundDTO::$require_properties`。
→ 這是比 `_pc_payment_identity` **更可靠的查單主鍵**，且對「客戶沒導回」的情境免疫。

### R5. SLP 查詢付款 API 只吃 `tradeOrderId`

已向官方文件確認（`https://docs.shoplinepayments.com/api/trade/query/`）：

```
POST {DOMAIN}/api/v1/trade/payment/get      Body: { tradeOrderId }   ← 只有這一個欄位
```

`ApiClient::get_payment()`（`Http/ApiClient.php:81-89`）的端點是**正確的**
（.claude/skills/shopline-payments-v1 的 `trade/orders/query` 與官方站不符，**以官方站為準**）。
但代表：**沒有 tradeOrderId 就查不了付款**，只能改查 session
（`/trade/sessions/query` 接受 `sessionId` 或 `referenceId`，回應含 `paymentDetails[].tradeOrderId`）。
→ 這正是缺陷 D（補寫 sessionId）的實務價值所在。

### R6. `GetPaymentDTO` 硬讀 `$_GET`，且長度上限只有 32

`DTOs/Trade/Payment/GetPaymentDTO.php:28` 直接 `$_GET['tradeOrderId']`（不可測、不可重用、
未 sanitize）；`:45` 以 `StrHelper(..., 32)` 驗長度，而 SLP 文件標示 tradeOrderId 可達
64 字元。**若實際 id 超過 32 字元，整個同步查詢會被 DTO 自己 throw 掉**（且因 never-throw
被吞掉，變成無聲失敗）。必須參數化 + 放寬到 64。

### R7. `StatusManager` 目前無任何守衛

`Managers/StatusManager.php:33-48`：無冪等、無金額、無幣別、無終態守衛，且用
`update_status()` 而非 `payment_complete()`。開放同步查詢路徑後，**同一筆付款會有兩條
認列路徑（導回 + webhook）**，若無冪等會產生重複 order note、重複狀態轉換，甚至重複觸發
發票自動開立（`Invoice\ProviderRegister::run_auto_action` 掛在
`woocommerce_order_status_{status}`；所幸各 provider 的 `issue()` 本身已有「已開立直接回傳」
的冪等守衛，見 `AmegoProvider.php:99-101`，故重複開立風險可控但不應依賴）。

### R8. 幣別陷阱（已在 PayNow 踩過）

store 預設幣別可能是 USD（見 memory `paynow-status`）。金額守衛若不比對幣別，USD 訂單的
`get_total()` 與 SLP 的 TWD `amount.value` 有機會在換算後偶然相符。必須同時比對幣別。

### R9. 浮點誤差

`Components\Amount::create()`（`DTOs/Components/Amount.php:33-38`）以 `$amount * 100` 產生
cents；PHP 浮點運算可能讓 `19.99 * 100` 變成 `1998.9999...` 而截斷為 `1998`。金額守衛若用
`(int) round($total * 100)` 嚴格比對，可能把**合法付款誤判為竄改**。→ 守衛需容許 ±1 cent。

### R10. 既有測試會被改動打到（必須同步更新，不可放著紅）

- `tests/Integration/Payment/WebhookSignatureTest.php:352,414`（FM-06 兩測）目前斷言
  「驗簽失敗會 throw」。缺陷 B 修好後改為回 401 response（不再 throw）→ **兩測必須改寫**，
  但核心不變式（訂單維持 pending、不寫 payment_detail）保持不變。
- `tests/Integration/Payment/StatusManagerTest.php` 的 5 個既有測試使用
  `create_wc_order()`（**無 line item、total = 0**）。加入金額守衛後，`SUCCEEDED` 分支會因
  「訂單應收 0 ≠ 通知金額 10000」被守衛擋下 → **既有 3 個 happy 測試會轉紅**。
  必須改為建立帶正確 total 的訂單（`create_wc_order(['total' => '100.00'])`，對應
  `amount.value = 10000`）。

### R11. 生產站已在跑 1.3.1（skyisland.tw）

`_pc_identity` 對既有訂單一律不存在；`_pc_payment_identity` 對「客戶有導回」的訂單存在。
所有新程式碼必須容忍這兩種 meta 缺席（不得 throw、不得因缺 meta 而拒絕處理 webhook）。

---

## 架構變更

### 後端（新增檔案）

| 檔案 | 職責 |
|---|---|
| `inc/classes/Domains/Payment/ShoplinePayment/Managers/ReturnSyncManager.php` | 缺陷 A 核心：導回時的同步查詢 + 認列，含 order_key 閘門、冪等、節流、referenceOrderId 比對。`final class`，never-throw，回傳 `ReturnSyncResult` 供測試與 log 判讀 |
| `inc/classes/Domains/Payment/ShoplinePayment/Managers/OrderResolver.php` | 缺陷 C 核心：`identity 優先 → referenceOrderId 備援 → gateway 驗證 → 補寫 identity` 的統一查單邏輯，webhook 付款 / 退款兩路共用 |
| `inc/classes/Domains/Payment/ShoplinePayment/Shared/Enums/ReturnSyncResult.php` | 同步結果列舉：`SKIPPED_NO_TRADE_ID` / `SKIPPED_INVALID_KEY` / `SKIPPED_NOT_PENDING` / `SKIPPED_THROTTLED` / `MISMATCHED_ORDER` / `API_FAILED` / `UPDATED` |
| `inc/classes/Domains/Payment/ShoplinePayment/Shared/Enums/StatusSource.php` | 狀態來源列舉：`RETURN_SYNC`（導回同步）/ `WEBHOOK`（通知），用於 order note 標題，讓客服一眼看出是誰認列的 |
| `inc/classes/Domains/Payment/ShoplinePayment/Shared/Exceptions/SignatureException.php` | 缺陷 B：讓 webhook 能區分「驗簽不符（可重送）」與「業務失敗（不該重送）」 |

### 後端（修改檔案）

| 檔案 | 變更 |
|---|---|
| `Services/RedirectGateway.php` | ① `before_order_received()` 改為委派 `ReturnSyncManager`；② `before_process_payment()` 補 `update_identity($response_dto->sessionId)`（缺陷 D） |
| `Http/ApiClient.php` | `get_payment(string $trade_order_id, ?int $timeout = null)`——參數化 + 可指定 timeout |
| `DTOs/Trade/Payment/GetPaymentDTO.php` | `create(string $trade_order_id)`——移除 `$_GET` 相依；長度上限 32 → 64（R6） |
| `Shared/Helpers/Requester.php` | `post(string $endpoint, array $body = [], ?int $timeout = null)`——同步路徑用短 timeout（R2） |
| `Managers/StatusManager.php` | 加入冪等 / 終態 / 幣別 / 金額守衛 + `StatusSource` + 寫入 `transaction_id`（R7-R9） |
| `Domains/Payment/Shared/Helpers/MetaKeys.php` | 新增 `get_processed_status()` / `push_processed_status()`（冪等鍵 `"{tradeOrderId}:{status}"`，對齊 `_pc_logistics_processed_status` 既有慣例） |
| `Http/WebHook.php` | ① catch 區塊改回 200（缺陷 B）；② 驗簽移入 try 並區分 401/200；③ `$is_valid` 未使用的假驗證修掉；④ 查單改用 `OrderResolver`（缺陷 C，付款 + 退款兩處） |

### 新增 order meta

| Key | 用途 |
|---|---|
| `_pc_payment_processed_status` | 冪等守衛陣列，元素為 `"{tradeOrderId}:{status}"`（同一筆付款狀態只處理一次，導回與 webhook 共用） |

### 新增 transient（非 meta，自動過期）

| Key | TTL | 用途 |
|---|---|---|
| `pc_slp_return_sync_{order_id}` | 30 秒 | 節流：避免客戶連續重新整理造成 API 風暴 |

### 前端

**無變更。** 前端輪詢的決策見下方「決策 3」。

### 測試

新增 4 個測試檔（全部放 `tests/Integration/Payment/`，namespace `Tests\Integration\`，
繼承 `Tests\Integration\TestCase`，每個測試方法**必掛** `smoke`/`happy`/`error`/`edge`/`security`
其一，否則 `phpunit.xml.dist` 的 group 白名單不會收集）：

- `SlpReturnSyncTest.php`（缺陷 A）
- `SlpOrderResolverTest.php`（缺陷 C）
- `SlpWebhookResponseTest.php`（缺陷 B）
- `SlpSessionIdentityTest.php`（缺陷 D）

修改 2 個既有測試檔：`StatusManagerTest.php`、`WebhookSignatureTest.php`（見 R10）。

---

## 關鍵決策（含理由）

### 決策 1：同步查詢仍走 `payment/get`，不改成查 session

`get_payment()` 端點已與官方文件核對無誤（R5），且導回時 `tradeOrderId` 一定在 query string
（issue 已佐證）。查 session 是**沒有 tradeOrderId 時**的備援（P3 後台補單用），不放進 P1 熱路徑。

### 決策 2：`update_status(PROCESSING)` 保留，**不改用 `payment_complete()`**

雖然 PayNow / PAYUNi 的 StatusManager 都是 `payment_complete()` + `update_status(PROCESSING)`，
但 SLP 若改用會有兩個副作用：

1. `WC_Order::payment_complete()` 對「無 line item / 純虛擬商品」訂單會直接設為 `completed`
   （`needs_processing()` 為 false），再被 `update_status(PROCESSING)` **降級**回 processing
   → 多一次 `completed → processing` 的狀態轉換，會多觸發一輪
   `woocommerce_order_status_*`（發票自動開立、通知信）。
2. 這是 1.3.1 生產站的行為變更，而本 issue 的訴求是「顯示與競態」，不是「付款認列語意」。

因此 P1 維持既有語意，只補上目前缺的 `set_transaction_id($tradeOrderId)`
（`date_paid` 由 WC 的 `maybe_set_date_paid()` 在 `save()` 時自動補，已於
`woocommerce/includes/class-wc-order.php:321,341` 確認，不需我方處理）。
是否統一為 `payment_complete()` 列入「不在本次範圍」，另案評估。

### 決策 3：**不做前端輪詢**（issue 提及的「治標」）

理由：

1. 同步查詢在 `wp` action 執行，**早於 thankyou 模板 render**（模板自 DB 重讀訂單），
   狀態在畫面繪出前就已正確 → 輪詢無事可做。
2. 修好後唯一還會顯示「尚未付款」的情境是：**ATM / 虛擬帳號（本來就真的還沒付）**——
   輪詢也不會讓它變成已付款，只會製造誤導。
3. 輪詢需新增前台 REST 端點（又一個 order_key 認證面）+ JS bundle 改動，
   為了一個已被治本解消滅的症狀，投報率為負。
4. 附帶收益：`PaymentDTO::to_human_html()`（`DTOs/Trade/Payment/PaymentDTO.php:107-112`）
   本來就會在有 `paymentMsg.code` 時掛 `woocommerce_before_thankyou` 印錯誤訊息——
   過去在 webhook context 執行等於無效，**改到導回同步後這段程式碼第一次真正生效**，
   付款失敗的客戶會當場看到失敗原因。這比輪詢更有價值且零成本。

若日後實測仍有殘留案例，再以 P4「單次延遲重查」（非持續輪詢）處理，並以
「查詢結果為非終態 + 付款方式非離線型」為觸發條件。

### 決策 4：webhook 回應碼——**驗簽不符回 401，其餘一律回 200**

判準只有一個：**「同一份 payload 之後有沒有可能成功？」有 → 值得重送；沒有 → 不該重送。**

| 情境 | 回應 | 理由 |
|---|---|---|
| `sign` 不符 | **401** | 極可能是商家 signKey 設定錯誤。回 200 會讓 SLP 永久放棄這筆通知，商家改好設定也救不回來。回 401 保留重送機會，且不洩漏任何資訊 |
| `timestamp` 超出 5 分鐘容差 | **200** | 同一份 payload 重送幾次都不會通過（時間只會更久），重送純粹是浪費。回 200 止血，另記 error log |
| 找不到訂單（identity + referenceOrderId 皆未命中） | **200** | issue 明確要求。重送不會讓訂單長出來 |
| DTO 解析失敗 / 未知 EventType / 業務例外 | **200** | 同上，重送無意義 |
| 正常處理完成 | **200** | 原行為 |

這與 PayNow / PAYUNi 的「一律 200」略有差異，差異僅在驗簽不符這一格，且理由明確
（SLP 是**目前唯一**把 signKey 交由商家自行填寫的金流，設定錯誤的機率遠高於其他家）。
若審查認為必須與其他金流嚴格對齊，改成 401 → 200 只需刪一個 catch 分支，
但請一併移除 `SignatureException`。

### 決策 5：`_pc_payment_identity` 改為「驗證後才寫」

原行為（未驗證即寫）是 R3 的攻擊入口。新行為的寫入點只有兩處，且都已驗證：

1. `ReturnSyncManager`：`order_key` 通過 **且** 查詢回傳的 `referenceOrderId === (string) $order->get_id()`。
2. `OrderResolver`：webhook 以 `referenceOrderId` 備援命中且 gateway 相符時**回填**。

代價：查詢 API 失敗時該筆訂單不會有 identity。**但這不影響 webhook 找單**——因為缺陷 C
的備援（referenceOrderId）本來就不依賴 identity。兩個修復互為安全網。

---

## 資料流分析

### 流程 1：付款成功導回（缺陷 A 修復後的主線）

```
客戶於 SLP 付款成功
  → 瀏覽器 GET /checkout/order-received/{id}/?key=wc_order_xxx&tradeOrderId=YYY
  → action 'wp' → AbstractPaymentGateway::before_page_render()      [final]
      ├ 取 $wp->query_vars['order-received'] → wc_get_order()
      ├ 比對 payment_method === 'shopline_payment_redirect'
      ├ record_exception_to_order_note($order)   // 讓 logger 同步寫 order note
      └ RedirectGateway::before_order_received($order)
           └ try { (new ReturnSyncManager($this, $order))->sync() } catch (\Throwable) { logger }
                ├ ① 取 $_GET['tradeOrderId'] → wp_unslash → sanitize_text_field
                │    → preg_match('/^[A-Za-z0-9_-]{1,64}$/')   失敗 → SKIPPED_NO_TRADE_ID
                ├ ② order_key 閘門：hash_equals($order->get_order_key(), $_GET['key'])
                │    失敗 → SKIPPED_INVALID_KEY（記 warning log，供監控是否誤擋）
                ├ ③ 冪等：!$order->has_status(['pending','failed']) → SKIPPED_NOT_PENDING
                ├ ④ 節流：get_transient("pc_slp_return_sync_{$id}") → SKIPPED_THROTTLED
                │    未命中 → set_transient(..., 1, 30) （設在 API 之前，逾時也不會被連打）
                ├ ⑤ ApiClient::get_payment($trade_order_id, 10)   // 10 秒 timeout
                │    throw → API_FAILED（catch 於外層，維持 pending，頁面照常 render）
                ├ ⑥ referenceOrderId 比對：(string) $order->get_id()
                │    不符 → MISMATCHED_ORDER（記 error log + order note，絕不轉狀態）
                ├ ⑦ MetaKeys::update_payment_identity($trade_order_id)   // 驗證後才寫
                └ ⑧ (new StatusManager($dto, $order, StatusSource::RETURN_SYNC))->update_order_status()
                       → 見流程 3
  → WC()->cart->empty_cart()
  → the_content → WC_Shortcode_Checkout::output() 重新 wc_get_order() → 讀到 processing ✅
```

**關鍵**：`wp` 早於 `the_content`，模板重新讀 DB，所以同一次 request 內狀態即刻反映到畫面。

### 流程 2：webhook 進來（缺陷 B + C 修復後）

```
SLP POST /wp-json/power-checkout/slp/webhook
  → WebHook::post_webhook_callback()
      try {
        ├ assert_valid($request)
        │    ├ Plugin::$env === 'local' → 略過
        │    ├ timestamp 差 > 5 分鐘 → throw \Exception          → catch → 200
        │    └ hash_equals(sign) 失敗 → throw SignatureException → catch → 401
        ├ Body::create($params)                                  → 失敗 catch → 200
        ├ data instanceof Webhooks\Refund
        │    └ handle_refund() → OrderResolver::resolve(tradeOrderId, referenceOrderId)
        ├ data instanceof Webhooks\Payment && is_successed_or_failed()
        │    ├ OrderResolver::resolve($dto->tradeOrderId, $dto->referenceOrderId)
        │    │    ├ ① MetaKeys::get_order_by_identity_payment_key(tradeOrderId)
        │    │    │     命中 + payment_method 相符 + referenceOrderId 一致 → 回傳
        │    │    │     命中但 referenceOrderId 不一致 → 記 warning（疑似 meta 汙染）→ 落 ②
        │    │    ├ ② wc_get_order((int) referenceOrderId)
        │    │    │     + $order->get_payment_method() === RedirectGateway::ID
        │    │    │     → update_payment_identity(tradeOrderId)  // 回填
        │    │    └ ③ 皆未命中 → null → throw \Exception → catch → 200（不再 500）
        │    └ new StatusManager($dto, $order, StatusSource::WEBHOOK)->update_order_status()
        return 200
      } catch (SignatureException) { logger; return 401 }
        catch (\Throwable)          { logger; return 200 }
```

### 流程 3：`StatusManager::update_order_status()`（強化後，兩條路徑共用）

```
① 冪等鍵：$key = "{$dto->tradeOrderId}:{$dto->status}"
     in_array($key, MetaKeys::get_processed_status()) → return（不寫 note、不轉狀態）
② status === SUCCEEDED 時的三道守衛（任一不過 → order note 告警 + 維持現狀 + return）：
     a. 終態守衛：has_status([refunded, cancelled, completed]) → 拒絕復活
     b. 幣別守衛：$dto->order->amount->currency === $order->get_currency()
     c. 金額守衛：abs($dto->order->amount->value - (int) round($order->get_total() * 100)) <= 1
③ order note（標題冠上 StatusSource label）+ MetaKeys::update_payment_detail()
④ SUCCEEDED → set_transaction_id($dto->tradeOrderId) → update_status(processing)
   EXPIRED   → update_status(cancelled)
   其餘      → update_status(pending)
⑤ MetaKeys::push_processed_status($key)
```

> **注意 ②a 的順序**：終態守衛只作用於 `SUCCEEDED`。`EXPIRED → cancelled` 不受影響
> （維持既有行為）。

### 流程 4：建立 session（缺陷 D）

```
process_payment() → before_process_payment()
  → ApiClient::create_session()   // 回 SessionDTO（含 sessionId / sessionUrl / status）
  → (new MetaKeys($order))->update_identity($dto->sessionId)   ← 新增，寫在 EXPIRED 判斷之前
  → EXPIRED 判斷（既有）
  → return $dto->sessionUrl
```

寫在 EXPIRED 判斷**之前**，讓被判逾期而取消的訂單也留下 sessionId 供客服追查。

---

## 錯誤處理登記表

| # | 情境 | 處理 | 對客戶 | log / note |
|---|---|---|---|---|
| E1 | `tradeOrderId` 缺席或格式不合 | `return` | 頁面照常 | 不記（正常情境，例如客戶自行重訪） |
| E2 | `order_key` 不符 / 缺席 | `return`，不打 API | 頁面照常 | `warning` log（用於監控 SLP 是否吃掉 key） |
| E3 | 查詢 API timeout / 連線失敗 | catch，維持 pending | 頁面照常顯示未付款 | `error` log + order note |
| E4 | 查詢 API 回業務錯誤碼（`Requester` throw） | 同 E3 | 同 E3 | 同 E3 |
| E5 | `referenceOrderId` ≠ 本訂單 ID | 不轉狀態、不寫 identity | 頁面照常 | `error` log + order note（資安事件） |
| E6 | 金額 / 幣別不符 | 不轉狀態 | 頁面照常 | order note「疑似竄改」 |
| E7 | webhook 驗簽（sign）失敗 | 回 401，不處理 | — | `error` log |
| E8 | webhook timestamp 超時 | 回 200，不處理 | — | `error` log |
| E9 | webhook 查無訂單 | 回 200 | — | `error` log（含 tradeOrderId + referenceOrderId） |
| E10 | `StatusManager` 內部例外 | 由 webhook / gateway 的 catch 接住 | 頁面照常 | `error` log |
| E11 | `create_session` 成功但 `update_identity` 失敗 | 不影響付款流程（meta 寫入失敗僅記 log） | 正常跳轉 | `error` log |

**鐵律**：`before_order_received` 路徑上任何 `\Throwable` 都必須被吞掉並記 log。
thankyou 頁 **絕不可** 500、白畫面、或因例外而跳過 `empty_cart()`。

---

## 失敗模式登記表

| # | 失敗模式 | 影響 | 緩解 |
|---|---|---|---|
| FM-1 | 客戶狂按重新整理 → API 風暴 | SLP 限流 / 站台變慢 | 狀態閘門（③）+ 30 秒 transient 節流（④），且節流在 API 呼叫**之前**設定 |
| FM-2 | 導回同步與 webhook 幾乎同時到達，兩者都讀到 pending | 重複 order note、重複狀態轉換、重複觸發發票自動開立 | 冪等鍵 `_pc_payment_processed_status`；殘留的毫秒級 TOCTOU 由各 invoice provider 自身的「已開立直接回傳」冪等守衛兜底 |
| FM-3 | 攻擊者以 `?tradeOrderId=<他人的>` 竄改自己的訂單 | 白嫖 | order_key 閘門 + `referenceOrderId` 比對 + 金額守衛（三道獨立） |
| FM-4 | 攻擊者汙染 `_pc_payment_identity` 造成 webhook 誤配 | 他人付款認列到攻擊者訂單 | 決策 5（驗證後才寫）+ `OrderResolver` 對 identity 命中結果再做 `referenceOrderId` 一致性複驗 |
| FM-5 | SLP 回應為非終態（PROCESSING / CUSTOMER_ACTION） | 客戶仍看到未付款 | 走 `default → pending` 分支；由後續 webhook 補上（此為正確行為，非缺陷） |
| FM-6 | `tradeOrderId` 超過 32 字元 | 同步查詢無聲失效（退化為現況） | R6：放寬至 64 + 補測試 |
| FM-7 | 站台裝了全頁快取且未排除 order-received | 同步結果不反映 | WC 自帶 `DONOTCACHEPAGE`；列入部署檢查清單，非程式碼可解 |
| FM-8 | 回 401 後 SLP 無限重送驗簽失敗的通知 | log 噪音（但不再是無限 500） | 決策 4 已將「必然失敗」的 timestamp 情境劃給 200；sign 情境保留重送是刻意設計，並以 log 監控 |

---

## 實作步驟

### 第一階段：可測試性重構（無行為變更，先綠再改）

1. `Shared/Helpers/Requester.php`：`post(string $endpoint, array $request_body = [], ?int $timeout = null)`，
   內部 `'timeout' => $timeout ?? self::TIMEOUT`。
2. `DTOs/Trade/Payment/GetPaymentDTO.php`：`create(string $trade_order_id): self`（移除 `$_GET`
   讀取與其上的 `phpcs:ignore`）；`validate()` 的 `StrHelper` 上限 32 → 64。
3. `Http/ApiClient.php`：`get_payment(string $trade_order_id, ?int $timeout = null): PaymentDTO`。
4. `Domains/Payment/Shared/Helpers/MetaKeys.php`：新增常數
   `PROCESSED_STATUS_KEY = '_pc_payment_processed_status'` 與
   `get_processed_status(): array<int, string>` / `push_processed_status(string $key): void`
   （HPOS 相容：一律 `$this->_order->get_meta()` / `update_meta_data()` + `save_meta_data()`）。
5. 跑一次全套測試確認仍綠（此階段零行為變更，`get_payment()` 目前無呼叫端）。

### 第二階段：`StatusManager` 強化（缺陷 A/C 共用的地基）

6. 新增 `Shared/Enums/StatusSource.php`（`RETURN_SYNC` / `WEBHOOK` + `label()`）。
7. `Managers/StatusManager.php`：
   - 建構子加 `private readonly StatusSource $source = StatusSource::WEBHOOK`（**保留預設值**，
     既有呼叫端 `WebHook.php:75` 不需改動即可維持行為）。
   - 依「流程 3」加入冪等 / 終態 / 幣別 / 金額（±1 cent 容差）守衛。
   - `SUCCEEDED` 分支補 `$order->set_transaction_id($dto->tradeOrderId)`。
   - order note 標題冠上 `$source->label()`。
8. 更新 `tests/Integration/Payment/StatusManagerTest.php`（R10）：既有訂單改為
   `create_wc_order(['total' => '100.00'])` 以對應 `amount.value = 10000`；
   新增金額不符 / 幣別不符 / 終態 / 冪等 4 個測試。

### 第三階段：缺陷 A —— 導回同步認列

9. 新增 `Shared/Enums/ReturnSyncResult.php`。
10. 新增 `Managers/ReturnSyncManager.php`：
    ```php
    final class ReturnSyncManager {
        private const TIMEOUT       = 10;      // 同步路徑短 timeout（R2）
        private const THROTTLE_SEC  = 30;
        public function __construct(
            private readonly RedirectGateway $gateway,
            private readonly \WC_Order $order,
        ) {}
        public function sync(): ReturnSyncResult { /* 流程 1 的 ①～⑧ */ }
    }
    ```
    - 讀 `$_GET` 一律 `\wp_unslash()` + `\sanitize_text_field()` + 正則白名單。
    - `sync()` 本身**不 throw**（內部 catch 後回 `API_FAILED`），呼叫端仍再包一層 try 作為雙保險。
    - timeout 開放 filter：`(int) \apply_filters('power_checkout_slp_return_query_timeout', self::TIMEOUT)`。
11. `Services/RedirectGateway.php::before_order_received()` 改寫為：
    ```php
    protected function before_order_received( \WC_Order $order ): void {
        try {
            $result = ( new ReturnSyncManager( $this, $order ) )->sync();
            if ( ReturnSyncResult::API_FAILED === $result ) {
                $this->logger( "⚠️ {$this->title} 導回同步查詢失敗，改由 Webhook 認列", 'warning', [], 5 );
            }
        } catch ( \Throwable $e ) {
            $this->logger( "❌ {$this->title} 發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
        }
    }
    ```
12. 新增 `tests/Integration/Payment/SlpReturnSyncTest.php`（測試清單見「測試策略」）。

### 第四階段：缺陷 B + C —— webhook 回應碼與查單

13. 新增 `Shared/Exceptions/SignatureException.php`。
14. 新增 `Managers/OrderResolver.php`：
    ```php
    final class OrderResolver {
        public static function resolve( string $trade_order_id, string $reference_order_id ): ?\WC_Order
    }
    ```
    依流程 2 的 ①②③。**必須**驗證 `get_payment_method() === RedirectGateway::ID`。
    - 註：不直接複用 `Webhooks\Body::get_order()`，因為 `handle_refund()` 只拿得到 `Refund` DTO
      （`Body::get_order()` 只認 `Session` / `Payment` 兩型，且無 gateway 驗證、無 identity 優先序、
      無回填）。`OrderResolver` 是 `Body::get_order()` 的超集；`Body::get_order()` 維持原狀
      （仍被 `@deprecated` 的 `EventTypeManager::update_order_status()` 使用），不動。
15. `Http/WebHook.php`：
    - `is_valid()` 改名 `assert_valid(): void`（現況回傳值被賦給 `$is_valid` 後從未使用，
      驗簽實際只靠 throw 生效——改名讓意圖與實作一致，並消除 `WebHook.php:55` 的假象；
      log 中寫死的 `'is_valid' => 'true'` 一併移除或改為實際值）。
    - `verify_hmac_sha256_signature()` 的 throw 改為 `SignatureException`；timestamp 超時維持
      `\Exception`。
    - `assert_valid()` 移入 `try`。
    - catch 分兩支：`SignatureException → 401`；`\Throwable → 200`。
    - 付款分支與 `handle_refund()` 的查單改用 `OrderResolver::resolve()`。
16. 新增 `SlpWebhookResponseTest.php` + `SlpOrderResolverTest.php`；更新
    `WebhookSignatureTest.php` 的 FM-06 兩測（R10）。

### 第五階段：缺陷 D —— sessionId 寫入

17. `Services/RedirectGateway.php::before_process_payment()`：`create_session()` 後立即
    `( new MetaKeys( $order ) )->update_identity( $response_dto->sessionId );`（包在自己的
    try/catch 或確認 DTO 必有 sessionId——`SessionDTO::$require_properties` 已含 `sessionId`，
    故不需額外防呆，但寫入失敗不得中斷付款）。
18. 新增 `SlpSessionIdentityTest.php`。

### 第六階段（P3，選配，可另 PR）：後台補單 action

19. 對齊其他金流的「查詢補單」（`pc_payuni_query_trade` / `pc_paynow_query_trade`），新增
    `pc_slp_query_trade`：
    - 有 `_pc_payment_identity` → `get_payment()` → `StatusManager`。
    - 無 identity 但有 `_pc_identity` → `get_session()` → 取
      `paymentDetails[].tradeOrderId` → 回填 identity → `get_payment()` → `StatusManager`。
    - 這是「客戶付款後從未導回」訂單的**唯一**人工救援路徑，也是缺陷 D 的實際兌現點。
    - 需先掛 `woocommerce_order_actions` filter（SLP 目前完全沒有 order actions）。

### 第七階段：驗證與文件

20. Gate 指令（依 memory `wp-env-gate-commands`；`--filter` 必須帶路徑）：
    ```bash
    npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
      bash -c "API_MODE=mock vendor/bin/phpunit --filter SlpReturnSyncTest tests/Integration/Payment/"
    npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
      bash -c "API_MODE=mock vendor/bin/phpunit"
    npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
      bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse"
    composer lint
    ```
    ⚠️ 已知 pre-existing 失敗（**與本 issue 無關，不要修，但要在改動前先錄基準**）：
    ezpay edge 一支 + `RedirectSettingsDTO` 相關一支。
21. 更新 `.claude/CLAUDE.md`：
    - 「Shopline Payment Flow」段落補上導回同步查詢步驟與守衛。
    - 「Order Meta Keys」表補 `_pc_payment_processed_status`、`_pc_identity`（現行表未列）。
    - REST API 表的 `power-checkout/slp /webhook` 一列補註「驗簽失敗 401，其餘一律 200」。
22. 更新 `specs/features/payment/shopline-payment-checkout.feature` 與
    `shopline-payment-webhook.feature`，補上導回同步、金額守衛、404→200 等場景。

---

## 測試策略

全部 `API_MODE=mock`，HTTP 以 `pre_http_request` filter 攔截
（樣板見 `tests/Integration/Payment/PaynowRestClientTest.php:47-90`，記得在 `tear_down()`
`remove_filter`）。protected 方法以 `\ReflectionMethod` + `setAccessible(true)` 呼叫
（樣板見 `tests/Integration/Payment/MpgSandboxFixesTest.php:216-224`）。
`new RedirectGateway()` 可直接實例化（見 `RedirectGatewayValidationTest`）。
`$_GET` 於測試中直接賦值，並在 `tear_down()` 清空。

### `SlpReturnSyncTest.php`（缺陷 A）

| group | 場景 | 斷言 |
|---|---|---|
| smoke | `ReturnSyncManager` 可實例化 | instanceof |
| happy | 查詢回 `SUCCEEDED` | pending → processing；`_pc_payment_detail` 有值；`_pc_payment_identity` = tradeOrderId；`transaction_id` = tradeOrderId |
| happy | 訂單已 processing 再次進入 | **API 呼叫次數 = 0**（以 filter 計數器斷言）；狀態不變 |
| edge | 30 秒內第二次呼叫 | API 呼叫次數 = 1；回 `SKIPPED_THROTTLED` |
| edge | 查詢回 `PENDING` | 維持 pending；不寫 identity 之外的狀態變更 |
| edge | 無 `tradeOrderId` | 回 `SKIPPED_NO_TRADE_ID`；API 呼叫次數 = 0 |
| edge | `tradeOrderId` 長度 40 字元 | 不因 DTO 長度驗證失敗（R6 迴歸） |
| error | API 回 `WP_Error`（連線失敗） | 不 throw；維持 pending；回 `API_FAILED` |
| error | API 回業務錯誤碼 `{"code":"4001","msg":...}` | 同上 |
| security | `key` 不符 | API 呼叫次數 = 0；不寫任何 meta；回 `SKIPPED_INVALID_KEY` |
| security | `referenceOrderId` 指向別的訂單 | 維持 pending；**不寫 identity**；有告警 note |
| security | 金額不符（cents 差 100） | 維持 pending；有「疑似竄改」note |
| security | 幣別不符（USD 訂單 / TWD 通知） | 維持 pending |

### `SlpOrderResolverTest.php`（缺陷 C）

| group | 場景 | 斷言 |
|---|---|---|
| smoke | resolve 可呼叫 | 回 null 而非 throw（皆未命中時） |
| happy | identity 命中 | 回正確訂單 |
| happy | identity 未命中、referenceOrderId 命中 | 回正確訂單 **且** identity 被回填 |
| edge | 兩者皆未命中 | 回 null |
| edge | referenceOrderId 為非數字字串 | 回 null，不 throw |
| security | referenceOrderId 命中但 payment_method 非 SLP | 回 null（不得誤配他 gateway 訂單） |
| security | identity 命中但 referenceOrderId 指向另一訂單 | 回 referenceOrderId 對應的訂單（防 meta 汙染） |

### `SlpWebhookResponseTest.php`（缺陷 B）

| group | 場景 | 斷言 |
|---|---|---|
| happy | 合法 `trade.succeeded`（env=local 免驗簽） | 200；訂單 → processing |
| error | 查無訂單 | **200**（非 500）；訂單不受影響 |
| error | 未知 EventType | 200 |
| error | `data` 缺必填欄位 | 200 |
| edge | timestamp 超時（env=production） | 200；訂單維持 pending |
| security | sign 不符（env=production） | **401**；訂單維持 pending；無 `_pc_payment_detail` |
| security | 合法簽章但金額被竄改 | 200；訂單維持 pending（守衛在 StatusManager） |

> 測試需暫存並還原 `Plugin::$env`（樣板見 `WebhookSignatureTest.php:354,394`）。

### `SlpSessionIdentityTest.php`（缺陷 D）

| group | 場景 | 斷言 |
|---|---|---|
| happy | `before_process_payment` 成功 | `_pc_identity` = 回應的 sessionId |
| happy | session 回 `EXPIRED` | `_pc_identity` 仍被寫入（寫在判斷之前）；訂單 → cancelled |
| edge | 舊訂單無 `_pc_identity` | `QuerySessionDTO::create()` 丟含 `Session ID not found` 的例外（向後相容行為不變） |

---

## 風險評估與注意事項

### 高優先

1. **R10 既有測試會轉紅**——`StatusManagerTest` 3 支 + `WebhookSignatureTest` 2 支。
   這是**預期內**的改動，必須在同一 PR 內修好，不可 skip、不可放寬斷言到失去意義。
2. **金額守衛誤擋合法付款**（R9）——±1 cent 容差是必須的。實作後請以
   `total = 48800`（issue 真實案例）與 `total = 19.99` 兩組數據各驗一次。
3. **skyisland.tw 已在跑 1.3.1**——升級後既有 pending 訂單若客戶重訪 order-received，
   會觸發一次同步查詢並可能轉為 processing（**這是期望行為，等於自動補單**），
   但會產生 order note。請事先告知站主此現象，避免誤判為異常。
4. **order_key 閘門是新增的前置條件**。若 SLP 在某些付款方式下不保留 `key` query
   參數，會導致同步查詢全面靜默跳過（退化回現況，不會更糟）。上線後**第一週必須查
   `power_checkout_shopline_payment_redirect` log 是否大量出現 `SKIPPED_INVALID_KEY`**；
   若有，改為「key 缺席時仍查詢、但強制要求 referenceOrderId 比對通過」即可（該閘門本就
   是縱深防禦的第一層，第二層已足夠）。

### 中優先

5. **thankyou 頁多一次外部 API 呼叫**——即使 timeout 設 10 秒，SLP 若慢速回應，客戶的
   完成頁 TTFB 會增加。已用狀態閘門 + 節流把呼叫次數壓到「每筆訂單至多一次」。
6. **決策 4 的 401 與其他 5 個金流的「一律 200」不一致**——已於決策段說明理由；
   審查若不接受，改動範圍極小（見決策 4 末段）。
7. **`_pc_payment_identity` 寫入時機改變**——若有外部工具（客服 SOP / 報表）依賴
   「導回後一定有 identity」，行為會變。目前全 repo 只有 SLP 自己讀寫此 meta
   （已 grep 確認：`WebHook.php:69,179`、`RedirectGateway.php:150,156`），無其他消費者。

### 低優先

8. `EventTypeManager::update_order_status()` 已標 `@deprecated`（其 docblock 明白寫著
   「付款成功的狀態變更用 get_payment 同步取得，而非等 webhook」）——**本計劃正是在補完
   當初設計但沒接上的那一段**。該方法本身無呼叫端，本次不動、不刪。
9. webhook 目前只處理 `is_successed_or_failed()`，`trade.expired` 不會轉 cancelled。
   屬既有設計，本次不動（見「不在本次範圍」）。

---

## 不在本次範圍

1. **改用 `payment_complete()`**（決策 2）——另案評估，需一併處理虛擬商品訂單語意。
2. **前端輪詢**（決策 3）——不做。
3. **`trade.expired` webhook 轉 cancelled**——既有行為，不在本 issue 訴求內。
4. **Block Checkout 支援 / SLP 設定頁 UI**——未受影響。
5. **`Webhooks\Session` 事件處理**（`session.succeeded` 目前被忽略）——導回同步 + trade 事件
   已足以認列，session 事件維持忽略。
6. **`.claude/skills/shopline-payments-v1` 的端點錯誤修正**（`trade/orders/query` vs
   官方的 `trade/payment/get`）——建議另開 issue 修 skill，本 PR 只在計劃中註記。

---

## 交付物清單

### 新增檔案（10）

```
inc/classes/Domains/Payment/ShoplinePayment/Managers/ReturnSyncManager.php
inc/classes/Domains/Payment/ShoplinePayment/Managers/OrderResolver.php
inc/classes/Domains/Payment/ShoplinePayment/Shared/Enums/ReturnSyncResult.php
inc/classes/Domains/Payment/ShoplinePayment/Shared/Enums/StatusSource.php
inc/classes/Domains/Payment/ShoplinePayment/Shared/Exceptions/SignatureException.php
tests/Integration/Payment/SlpReturnSyncTest.php
tests/Integration/Payment/SlpOrderResolverTest.php
tests/Integration/Payment/SlpWebhookResponseTest.php
tests/Integration/Payment/SlpSessionIdentityTest.php
（P3 選配）inc/classes/Domains/Payment/ShoplinePayment/Managers/QueryTradeManager.php
```

### 修改檔案（11）

```
inc/classes/Domains/Payment/ShoplinePayment/Services/RedirectGateway.php
inc/classes/Domains/Payment/ShoplinePayment/Http/WebHook.php
inc/classes/Domains/Payment/ShoplinePayment/Http/ApiClient.php
inc/classes/Domains/Payment/ShoplinePayment/Managers/StatusManager.php
inc/classes/Domains/Payment/ShoplinePayment/DTOs/Trade/Payment/GetPaymentDTO.php
inc/classes/Domains/Payment/ShoplinePayment/Shared/Helpers/Requester.php
inc/classes/Domains/Payment/Shared/Helpers/MetaKeys.php
tests/Integration/Payment/StatusManagerTest.php
tests/Integration/Payment/WebhookSignatureTest.php
.claude/CLAUDE.md
specs/features/payment/shopline-payment-checkout.feature
specs/features/payment/shopline-payment-webhook.feature
```

---

## 交接給 wordpress-master

- 專案規範一律遵守：`declare(strict_types=1)`、`final class`、PHPStan level 9、
  hook 用 static method reference、text domain `power_checkout`、
  HPOS 相容（`$order->get_meta()` / `update_meta_data()`，**禁用** `get_post_meta`）、
  前台 never-throw。
- **實作順序請照階段走**：第一階段是零行為變更的重構，先跑綠再往下；
  第二階段的 `StatusManager` 是第三、四階段共用的地基。
- 每階段結束跑一次 `--filter <新測試類> tests/Integration/Payment/`，
  全部完成後跑一次全套 + PHPStan + `composer lint`。
- 改動前請先錄下 pre-existing 失敗基準（ezpay edge 一支 + RedirectSettingsDTO 一支），
  結束時比對「不多不少」。
