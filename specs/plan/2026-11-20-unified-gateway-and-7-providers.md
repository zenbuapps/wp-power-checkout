# 實作計劃：統一金流/物流介面 + 7 家新 provider（SLP 嵌入式、黑貓PAY、LINE Pay、黑貓宅急便、新竹、順豐、嘉里大榮）

> 建立日期：2026-11-20　｜　規劃者：planner　｜　執行者：@zenbu-powers:tdd-coordinator
> 實作分支：**`feat/unified-gateway-providers`（從 `master` 開，不是目前的 `chore/update-claude-skills`）**
> 範圍模式：**EXPANSION（已由用戶 / orchestrator 核定範圍，見下方「範圍聲明」）**

---

## 範圍聲明（必讀）

- 預估新增 / 修改 **約 150 個檔案**（遠超 30 檔門檻）。範圍由用戶明確要求「全部要完成」、orchestrator 已核定，**本計劃不縮減範圍**，改以「每個 Phase 可獨立合併 + 檢查點」控制風險。
- 每個 Phase 結束都必須是**可獨立合併的綠燈狀態**（mock 全綠 + PHPStan L9 + PHPCS），後一 Phase 不依賴前一 Phase 的未合併成果以外的東西（P1 起皆依賴 P0）。
- 若執行中發現任何 Phase 預估檔案數比本計劃多出 50% 以上 → 停下回報，重新判定範圍。

---

## 概述

參考 zenbu-site-2.0（NestJS）的 `PaymentGateway` / `ShippingGateway` 抽象，為 power-checkout 補上**只加不改**的統一能力宣告（`get_provider_meta()`）、統一 webhook 外框（`verify_callback()` + `handle_webhook()`）、嵌入式統一 dispatch REST，再以這套接口串接 3 家金流（SLP 嵌入式、黑貓PAY、LINE Pay）與 4 家物流（黑貓宅急便、新竹、順豐、嘉里大榮），最後以 Playwright 錄影完成 live 驗收。

## 需求重述

| 項目 | 內容 |
|---|---|
| 要建的東西 | (1) 金流 / 物流統一 provider meta 與 capabilities；(2) 新金流的統一 webhook 外框；(3) 嵌入式金流統一 dispatch REST；(4) 7 個新 provider 完整後端 + Vue 設定頁 + router + blocks（金流）/ WC 運送方式（物流） |
| 服務對象 | 使用 power-checkout 的 WooCommerce 站長（後台設定 / 出貨）與其顧客（結帳付款） |
| 成功的樣子 | 7 家 provider 全部 mock 測試綠燈；可 live 的家數有成功付款 / 建單錄影；不可 live 的家數有明確降級驗收與阻塞原因；既有 7 個 gateway、3 個物流行為零回歸 |
| 不做的事 | 不重寫既有 7 個 gateway、不碰 `final process_payment()`、不把既有 gateway 的 webhook 遷移到新外框、不把 capture/void/query 預設改拋 UNSUPPORTED（見「限制條件」） |

### Provider ID 一覽（定案，後續所有檔名 / option / meta 前綴依此）

| Provider | ID | WC option | Meta 前綴 | 領域 |
|---|---|---|---|---|
| SHOPLINE Payments 嵌入式 | `shopline_payment_embedded` | `woocommerce_shopline_payment_embedded_settings` | 重用 SLP 既有 `_pc_identity` / `_pc_payment_identity` / `_pc_payment_detail` / `_pc_payment_processed_status` + 新增 `_pc_slp_embedded_*` | Payment |
| 黑貓PAY（Kolet） | `tcat_pay` | `woocommerce_tcat_pay_settings` | `_pc_tcat_pay_` | Payment |
| LINE Pay 直連 | `linepay` | `woocommerce_linepay_settings` | `_pc_linepay_` | Payment |
| 黑貓宅急便直串 | `tcat_logistics` | `woocommerce_tcat_logistics_settings` | `_pc_tcat_logistics_` | Logistics |
| 新竹物流 | `hct_logistics` | `woocommerce_hct_logistics_settings` | `_pc_hct_logistics_` | Logistics |
| 順豐國際 | `sf_express` | `woocommerce_sf_express_settings` | `_pc_sf_express_` | Logistics |
| 嘉里大榮 | `kerrytj_logistics` | `woocommerce_kerrytj_logistics_settings` | `_pc_kerrytj_logistics_` | Logistics |

> 已確認無 ID / option 撞名：既有 `LinePayTest.php`、`tests/e2e/record-linepay-success.ts`、`tests/e2e/03-integration/linepay-checkout-flow.spec.ts` 都是 **SLP 代收 LINE Pay**（`ShoplinePayment\...\PaymentMethod::LinePay`），與直連 `linepay` gateway 無關，**不得修改或刪除**；新測試檔一律以 `LinepayDirect*` 命名避免混淆。

---

## 已知風險（來自研究）

| # | 風險 | 來源 | 緩解 | 需求中已處理? |
|---|---|---|---|---|
| R1 | 黑貓PAY APN `checksum = MD5(api_id:trans_id:amount:status:nonce)` **不含任何金鑰**，任何人可偽造合法 checksum | `.claude/skills/tcat-pay/references/callbacks-apn.md` §2；zenbu `payments/kolet/kolet-apn-verify.ts` 註解 | APN 只當「觸發」：驗 checksum 通過後**一律再以 `CocsOrderQuery` / `CvsOrderQuery` 平台查詢確認**才標 paid（同 PayNow 物流 status-callback 手法）；加 10 秒節流 transient | 否 → 本計劃處理 |
| R2 | 黑貓PAY APN 需回**純文字 `OK`** 才停止重送（每 15 分鐘、同狀態最多 3 次） | tcat-pay SKILL.md §交易結果 | 統一外框允許 provider 覆寫 ack response（`callback_ack_response()`），tcat_pay 回 `text/plain OK` | 否 → 本計劃處理 |
| R3 | LINE Pay **沒有 webhook**；顧客關瀏覽器就永遠不會打 confirmUrl；request 後約 20 分鐘 confirm 即失敗（`1180`）；重複 confirm 回 `1172` | linepay SKILL.md §狀態與結果碼、§常見陷阱 | confirmUrl handler 立即 confirm + `1172/1198` 視為已付先查明細；request 成功時排一個 Action Scheduler 單次任務（+25 分鐘）查詢付款狀態補單；後台「查詢補單」order action | 部分（orchestrator 已提對帳） |
| R4 | LINE Pay `transactionId` 為 19 位整數，PHP `json_decode` 預設轉 float 失真 → `1150/1155` | linepay SKILL.md §transactionId | `json_decode($body, true, 512, JSON_BIGINT_AS_STRING)`；meta 一律存字串；測試含 19 位 golden case | 否 → 本計劃處理 |
| R5 | LINE Pay sandbox 付款頁需 LINE 帳號登入，自動化走不完 | orchestrator 實測、linepay SKILL.md §2026-11-19 sandbox 實測 | live 驗收降級：自動化做到取得 `paymentUrl` + 截圖；人工登入完成付款的錄影列為「可選加分」 | 是（降級標準） |
| R6 | SLP 嵌入式 `nextAction` 官方明示「不要解析、資料類型隨時可變」；沒有 `nextAction` 代表建單失敗不可呼叫 `pay()` | `shopline-payments-v1/references/embedded-sdk.md` §6 | 後端原樣轉傳 `nextAction`（JSON string pass-through，不做 DTO 解析）；前端無 `next_action` 即顯示錯誤 | 否 → 本計劃處理 |
| R7 | SLP 嵌入式沒有 session，付款結果只來自 `trade.*` 事件；既有 `OrderResolver::is_slp_order()` 只認 `shopline_payment_redirect` | `ShoplinePayment/Managers/OrderResolver.php:157`；zenbu `shopline-embedded.ts` 註解 | `is_slp_order()` 改為 SLP gateway ID 白名單（redirect + embedded）——這是本計劃**唯一對既有 SLP 程式的修改**，以既有 `SlpOrderResolverTest` 全綠作回歸守門 | 否 → 本計劃處理 |
| R8 | 黑貓宅急便 `OBTStatus` 硬性限流：每日 3,000 次 / 併發 3 / **同一單號 2 小時 1 次** | tcat-logistics SKILL.md §重要前提 | `query_shipment` 加 2 小時 per-BLN transient 快取，命中快取直接回上次結果並標 `cached: true`；不做背景輪詢 | 否 → 本計劃處理 |
| R9 | 新竹物流 SOAP 無官方 WSDL namespace / SOAPAction；標籤 image 為 ~2 萬字元 hex | hct-logistics SKILL.md §待澄清、`references/version-notes.md` | 不用 `SoapClient`（WSDL 不可靠），以 `wp_remote_post` 手組 envelope（照 skill `references/code-examples.md` 與 zenbu `hct-soap-client.ts`）；SOAP envelope 用 golden fixture 測 | 否 → 本計劃處理 |
| R10 | 新竹 / 嘉里大榮託運單號段由物流商核發、我方配號；號段用罄或重複 → 建單失敗 | kerrytj SKILL.md §4、hct SKILL.md | 號段設定（起、迄、目前值）存 option，以 `wpdb` 原子遞增（`UPDATE ... SET v = v + 1 WHERE v < end`）配號；用罄拋 `ErrorCode::NUMBER_EXHAUSTED`（既有 enum case） | 否 → 本計劃處理 |
| R11 | 嘉里大榮低溫單號為 9+1 碼 mod 7 檢查碼；常溫 11 碼流水號 | kerrytj SKILL.md | `KerrytjBlnAllocator` 純函式 + golden vector 測試（移植 zenbu `kerrytj-bln.ts` 的已知向量） | 否 → 本計劃處理 |
| R12 | 順豐 body 為 AES 密文 + header SHA-256 signature；accessToken 需快取；台灣島內件（TW→TW）正式可否下單待確認 | sf-express SKILL.md、`references/auth-and-crypto.md` | `SfCrypto` 以 skill §0.8 官方 sample 向量做 golden 測試；accessToken 存 transient（TTL = expires − 60s）；TW→TW 不擋程式但後台設定頁顯示警示文字 | 否 → 本計劃處理 |
| R13 | 物流統一狀態：PC 既有 `Logistics/Shared/Enums/LogisticsStatus` 實為**綠界原始碼 enum**（`'300'`/`'2067'`/`'cancelled'`），無法表達 zenbu 11 態 `ShippingState` | `inc/classes/Domains/Logistics/Shared/Enums/LogisticsStatus.php` | **ASM-1**：不改 `LogisticsStatus`；新增 `Logistics/Shared/Enums/ShippingState`（11 個 case，對應 zenbu `shipping-gateway.types.ts:63-75`），新物流 `query_shipment()` 回傳額外 `unified_state` 鍵；`LogisticsStatus::is_pickup_completed()` 保留給既有綠界 | 與 orchestrator 字面描述不同 → 標 ASM，見「待確認假設」 |
| R14 | WP 測試 DB 並行執行會 InnoDB 死結造成「隨機紅」 | memory `wp-env-gate-commands.md` | 同一時間只允許一個 phpunit 進程；tdd-coordinator 不得平行派多個 test runner | 是（流程規範） |
| R15 | 兩個 pre-existing 失敗：ezpay AES edge test、PHPStan `RedirectSettingsDTO.php:148 isset.property` | memory `wp-env-gate-commands.md` | Gate 比對時扣除，不得順手修（P1 會碰 SLP，但**不碰** `RedirectSettingsDTO`） | 是 |
| R16 | 全專案 20 個 DTO 的 `required_properties` 拼錯，必填驗證從未生效 | memory `dto-require-properties-typo.md` | 新 DTO 不依賴 wp-utils 必填驗證，自行在 `create()` 內顯式驗必填並拋 `\InvalidArgumentException`；**不得**順手修舊 DTO | 是 |
| R17 | 新 Settings DTO 預設 mode 一致性（memory：mode 預設 PROD 一致性 bug） | memory `local-turbo-acceptance.md` | 所有新 provider 的 `mode` 預設 **`test`**（沙箱），由 DTO 單元測試鎖定 | 否 → 本計劃處理 |
| R18 | store 幣別預設 USD，台灣金流測試需顯式 TWD | memory `paynow-status.md` | 每個新 gateway 測試 `set_up()` 顯式 `update_option('woocommerce_currency','TWD')`；`is_available()` 非 TWD 回 false | 是 |
| R19 | 憑證外洩風險（sandbox 憑證在多個 `.env.local`） | 用戶指示 | 計劃與 commit 不含任何憑證值；E2E 只從 `process.env` 讀；`.env*` 已在 `.gitignore`，P8 commit 前 `git diff --cached` 掃 `KEY|SECRET|TOKEN|PASSWORD` | 是 |

---

## 待確認假設（ASM，執行前若用戶 / orchestrator 有異議應先改計劃）

| ID | 假設 | 理由 | 若被否決的影響 |
|---|---|---|---|
| ASM-1 | 物流統一狀態用**新 enum `ShippingState`**，不改 `LogisticsStatus` | `LogisticsStatus` 是綠界原始碼 enum，硬塞 11 態會破壞既有 `is_pickup_completed()` 語意 | 改為擴充 `LogisticsStatus` → P0 多改 1 檔 + 既有綠界測試需複核 |
| ASM-2 | SLP 嵌入式為**獨立 gateway** `shopline_payment_embedded`（不是 redirect gateway 的設定切換） | 「只加不改」；結帳頁可同時提供導轉與嵌入兩種；不動 `RedirectGateway` | 改為設定切換 → 需改 `RedirectGateway::before_process_payment`，違反只加不改 |
| ASM-3 | SLP 嵌入式 webhook **共用既有** `/power-checkout/slp/webhook`（SLP 後台一個商店只能設一個 webhook URL），由 `EventTypeManager` 依訂單 gateway 分流；新 gateway 的 `verify_callback()` 委派既有 HMAC 驗簽供契約一致，但端點不遷移到新外框 | 單一 webhook URL 限制 | 若 SLP 允許多 URL → 可改走新外框獨立端點（P1 增 1 個 Callback 類） |
| ASM-4 | SLP 嵌入式第一版只開**信用卡（含分期）** | zenbu `isShoplineEmbeddableMethod()` 只開 `CREDIT`；其他方式 SDK 行為未在沙盒實證 | 擴充其他方式 → P1 增加約 4 小時 |
| ASM-5 | 黑貓PAY 第一版做 **COCS 線上刷卡（api-redirect 短網址）+ CVS ibon/ATM 取號**；不做續期收款（`/api/Period*`） | 定期定額需訂閱架構，PC 目前沒有 | 加續期 → 另開 Phase |
| ASM-6 | 黑貓宅急便第一版只做**宅配**（PrintOBT / DownloadOBT / OBTStatus / ReserveAdd 逆物流）；**不做** 7-11 快速到店 B2S 與電子地圖（需黑貓PAY token + ccat.com.tw 綁定） | zenbu `tcat-logistics.service.ts:410` B2S 走不同端點且依賴金流 token | 加 B2S → P4 增約 6 小時，且需 tcat_pay 已啟用 |
| ASM-7 | 新物流**不做背景輪詢貨態**；貨態只在後台按「查詢」或順豐軌跡推送時更新 | 限流（R8）與範圍控制；PC 既有物流也沒有輪詢 | 加輪詢 → 新增 Action Scheduler 排程 Phase |
| ASM-8 | LINE Pay 使用 **v4** API（`/v4/payments/...`），自動請款（`capture: true`）、`confirmUrlType: CLIENT` | linepay skill 標 v4 為最新且 2026-11-19 sandbox 已實測；zenbu 亦用 v4 | 改 v3 → 只需改 path 常數 |
| ASM-9 | WooCommerce 下所有 gateway 的 `order_creation_strategy` 一律為 `pre-create-pending`（WC 在 `process_payment` 前已建單），欄位保留只為與 zenbu 對齊 | WC 結帳模型 | 無 |

---

## 架構變更

### P0 統一介面（只加不改）

| 檔案 | 動作 | 說明 |
|---|---|---|
| `inc/classes/Domains/Payment/Shared/DTOs/ProviderMetaDTO.php` | 新增 | `final readonly class`：`id`、`payment_mode`（enum）、`order_creation_strategy`（enum）、`capabilities`（`PaymentCapabilitiesDTO`）；`to_array()` |
| `inc/classes/Domains/Payment/Shared/DTOs/PaymentCapabilitiesDTO.php` | 新增 | `refund` / `partial_refund` / `capture` / `void` / `query` / `recurring` 全為必填 bool |
| `inc/classes/Domains/Payment/Shared/Enums/PaymentMode.php` | 新增 | `FORM_REDIRECT='form-redirect'` / `API_REDIRECT='api-redirect'` / `CLIENT_SDK='client-sdk'` |
| `inc/classes/Domains/Payment/Shared/Enums/OrderCreationStrategy.php` | 新增 | `PRE_CREATE_PENDING` / `CALLBACK_CREATES_ORDER` |
| `inc/classes/Domains/Payment/Shared/Interfaces/IPaymentProvider.php` | 修改（只加方法） | 加 `get_provider_meta(): ProviderMetaDTO`、`verify_callback(\WP_REST_Request $request): bool` |
| `inc/classes/Domains/Payment/Shared/Abstracts/AbstractPaymentGateway.php` | 修改（只加方法） | `get_provider_meta()` 保守預設（`FORM_REDIRECT`、全部 capability=false 除 `refund` 依 `$this->supports` 推導）；`verify_callback()` 預設回 `false`（fail-closed）；新增 `final public function handle_webhook(\WP_REST_Request): \WP_REST_Response` 外框 + 可覆寫掛鉤 `resolve_callback_order()` / `get_callback_idempotency_key()` / `process_callback()` / `callback_ack_response()`；外框本體抽到 `WebhookPipeline` 以免 Abstract 再膨脹 |
| `inc/classes/Domains/Payment/Shared/Services/WebhookPipeline.php` | 新增 | 純流程：驗簽 → 找單 → 冪等鍵（`_pc_callback_processed` 陣列 meta，上限保留 50 筆）→ handler → audit log（`Plugin::logger`，只記 provider / key / 結果 / 耗時，不記 body 原文）→ ack；任何 `\Throwable` 吞掉仍回 ack（永遠 200） |
| `inc/classes/Domains/Payment/Shared/Services/PaymentDispatchApiService.php` | 新增 | REST `POST power-checkout/v1/pay/(?P<order_id>\d+)/dispatch`；`permission_callback: __return_true`，body `order_key` 以 `hash_equals` 驗；依訂單 gateway 呼叫 `IEmbeddedPaymentProvider::dispatch()`；回 `{ code, message, data: { mode, provider, ... } }` |
| `inc/classes/Domains/Payment/Shared/Interfaces/IEmbeddedPaymentProvider.php` | 新增 | `dispatch(\WC_Order $order, array $payload): array`（兩段式：無 `pay_session` → init；有 → confirm） |
| 既有 7 個 gateway（`ShoplinePayment/Services/RedirectGateway.php`、`EcpayAIO/Services/AioRedirectGateway.php`、`Ecpg/Services/EcpgGateway.php`、`NewebpayMpg/Services/MpgRedirectGateway.php`、`Payuni/Services/PayuniUppGateway.php`、`PayuniUniEmbed/Services/PayuniUniEmbedGateway.php`、`Paynow/Services/PaynowGateway.php`） | 修改（只加 1 個方法） | 各自覆寫 `get_provider_meta()` 宣告真實 capability（見下表）；**不動其他任何方法** |
| `inc/classes/Domains/Payment/ProviderRegister.php` | 修改 | `register_hooks()` 加 `PaymentDispatchApiService::register_hooks()` |
| `inc/classes/Domains/Logistics/Shared/DTOs/LogisticsProviderMetaDTO.php` | 新增 | `id`、`supported_delivery_methods`（`HOME` / `CVS`）、`capabilities`（`ShipmentCapabilitiesDTO`） |
| `inc/classes/Domains/Logistics/Shared/DTOs/ShipmentCapabilitiesDTO.php` | 新增 | `can_create_shipment` / `can_query_shipment` / `has_cvs_map` / `can_print` / `can_return` / `can_cancel`（全必填） |
| `inc/classes/Domains/Logistics/Shared/Enums/ShippingState.php` | 新增 | 11 態（ASM-1）+ `is_terminal()` |
| `inc/classes/Domains/Logistics/Shared/Interfaces/ILogisticsProvider.php` | 修改（只加方法） | 加 `get_provider_meta(): LogisticsProviderMetaDTO` |
| `inc/classes/Domains/Logistics/Ecpay/Services/EcpayLogisticsProvider.php`、`Payuni/Services/PayuniLogisticsProvider.php`、`Paynow/Services/PaynowLogisticsProvider.php` | 修改（只加 1 個方法） | 實作 `get_provider_meta()` |
| `inc/classes/Domains/Logistics/Shared/Services/LogisticsApiService.php` | 修改 | 新增 `GET power-checkout/v1/logistics/providers/meta`（admin nonce）；REST 層在呼叫 provider 操作前先檢查對應 capability，未宣告 → HTTP 501 + `ErrorCode::UNSUPPORTED`（**只對新 provider 生效**：既有 3 家 capability 宣告與現行行為一致，不改既有回應） |
| `inc/classes/Domains/Settings/Services/SettingApiService.php` | 修改 | `GET /settings` 回傳各 provider 時附 `meta`（給 Vue 隱藏不支援的按鈕） |

既有 7 家 capability 宣告（照現行程式行為填，**不改行為**）：

| Gateway | payment_mode | refund | partial_refund | capture | void | query | recurring |
|---|---|---|---|---|---|---|---|
| `shopline_payment_redirect` | api-redirect | true | true | false | false | true（ReturnSync） | false |
| `ecpay_aio` | form-redirect | true | true | true | true | true | false |
| `ecpay_ecpg` | client-sdk | true | true | false | false | false | false |
| `newebpay_mpg` | form-redirect | true | true | true | true | true | false |
| `payuni_upp` | form-redirect | true | true | true | true | true | false |
| `payuni_uni_embed` | client-sdk | true | true | true | true | true | false |
| `paynow` | client-sdk | true | true | false | false | true | false |

> 執行者須在 Red 階段先以 grep 確認每家 `capture()` / `void_auth()` / `query_trade()` 是否真有覆寫（上表為規劃時依 CLAUDE.md 推定），**以程式現況為準**修正上表後再寫測試。

### P1–P7 新 provider 目錄（每家遵循 `.claude/rules/provider-guide.rule.md`）

詳見各 Phase。

---

## 資料流分析

### 流程 A：統一 webhook 外框（tcat_pay APN / linepay confirmUrl 用；SLP 嵌入式只用 verify_callback）

```
HTTP POST/GET ──▶ verify_callback() ──▶ resolve_callback_order() ──▶ idempotency key ──▶ process_callback() ──▶ callback_ack_response()
     │                  │                        │                          │                    │                         │
     ▼                  ▼                        ▼                          ▼                    ▼                         ▼
[空 body?]        [簽章錯→log,ack]        [找不到單→log,ack]        [已處理→ack 不重做]   [Throwable→log,ack]     [tcat:'OK' text]
[非 JSON?]        [缺欄位→log,ack]        [gateway 不符→ack]        [meta 寫失敗→log]     [平台查詢逾時→       [linepay:302 導回]
                                           [多筆符合→拒,ack]                                不標 paid,ack]         [預設:200 JSON]
```

規則：任何分支都回 ack（永遠 200 / 或 linepay 302），**唯一例外**是 provider 規格明定簽章錯要回非 200 才重送（目前 7 家新 provider 皆無此需求；SLP 既有 401 規則不受影響，因 SLP 不走此外框）。

### 流程 B：嵌入式 dispatch（SLP 嵌入式，兩段式）

```
結帳 process_payment ──▶ before_process_payment：寫 _pc_slp_embedded_pending, 回 order-received URL
                                │
order-received 頁 ──▶ MountShoplineEmbedded()：POST /pay/{id}/dispatch {order_key}
                                │
                    ┌───────────┴───────────── phase=init ────────────────────────────┐
                    ▼                                                                  │
   [order_key 不符→403] [!needs_payment→409] [非本 gateway→400] [clientKey 空→500 通用訊息]
                    │
                    ▼ 回 {mode:'client-sdk', provider:'shopline_payment_embedded', sdk:{clientKey, env, amount, currency, paymentMethod:'CreditCard'}}
                    │
   前端 SDK init → payment.createPayment() → paySession
                    │   [SDK error→顯示錯誤, 不送後端]
                    ▼
   POST /pay/{id}/dispatch {order_key, pay_session}  ── phase=confirm
                    │
   [重複點擊：transient 鎖 pc_slp_emb_lock_{id} 30s → 409]
   [金額：一律由後端 round(total*100)，忽略前端金額]
   [trade/payment/create 失敗→fail，order note，回通用錯誤]
   [回應無 nextAction→視為失敗]
                    ▼
   寫 _pc_identity? 否；寫 _pc_payment_identity=tradeOrderId（建立後即寫，供 webhook 找單）
   回 {next_action:<原樣 JSON string>}
                    │
   前端 payment.pay(nextAction) → 3DS / 完成 → 導 order-received?key=...&pc_slp_emb=1
                    │
   SLP webhook trade.succeeded → 既有 /slp/webhook → OrderResolver(白名單含 embedded) → StatusManager（既有四道 guard）
                    │
   order-received 回訪：ReturnSync 對嵌入式以 tradeOrderId 主動查詢一次（重用 ReturnSyncManager 的 query 部分）
```

### 流程 C：LINE Pay（api-redirect，無 webhook）

```
process_payment ──▶ POST /v4/payments/request（amount 後端算、orderId=PCL{order_id}-{attempt}）
      │   [returnCode≠0000→wc_add_notice 通用訊息 + order note + fail]
      │   [2101 金額不符→同上，log packages]
      ▼
 寫 _pc_linepay_transaction_id（字串）, _pc_linepay_order_id, 排 AS 單次任務 +25min ──▶ redirect paymentUrl.web
      │
 顧客 LINE 登入付款 ──▶ GET confirmUrl?transactionId&orderId&key=order_key（confirmUrl 自帶 order_key）
      │
 verify_callback: order_key hash_equals + orderId === _pc_linepay_order_id + transactionId === meta
      │   [不符→不 confirm，導 order-received 顯示失敗]
      ▼
 POST /v4/payments/{transactionId}/confirm {amount(後端), currency:'TWD'}
      │   [0000→payment_complete(transactionId)]
      │   [1172/1198→GET payment details 確認已付→payment_complete（冪等）]
      │   [1180 逾時→order note，保持 pending，顯示重新付款]
      │   [網路逾時→不標失敗，留給 AS 補單]
      ▼
 302 → order-received
 AS +25min ──▶ GET /v4/payments/requests/{transactionId}/check ──▶ 若已授權未 confirm → confirm；已付 → 補單；過期 → order note
 cancelUrl ──▶ order note「顧客取消」+ 302 order-pay
```

### 流程 D：黑貓PAY（COCS 刷卡 api-redirect + CVS 取號）

```
process_payment ──▶ Token（transient 快取至 .expires-60s）──▶ /api/Collect cmd=CocsOrderAppend 或 CvsOrderAppend
      │   [Token 401→清快取重取 1 次]  [cust_id 與 token 不匹配→AUTH]  [金額超限→VALIDATION 前置擋]
      ▼
 COCS：回 url 短網址 → redirect      CVS：回繳費代碼 → 寫 _pc_tcat_pay_payment_info → order-received 顯示代碼，保持 on-hold/pending
      │
 COCS 導轉回報（GET + chk with hash_base）──▶ 驗 chk → 平台 CocsOrderQuery 確認 → StatusManager
 APN（POST JSON + checksum 無金鑰）──▶ WebhookPipeline：verify checksum → 找單（cust_order_no=_pc_tcat_pay_trade_no）
      → 10s 節流 → 平台查詢（source of truth）→ 金額比對 pay_amount → StatusManager → 回 'OK'
```

### 流程 E：新物流建單 / 查詢（tcat / hct / sf / kerrytj 共用形狀）

```
後台 POST /logistics/{id}/create-shipment ──▶ capability 檢查 ──▶ 前置驗證（provider 啟用 / 已有單號→冪等回既有 / 地址電話必填 / 金額 COD 上限）
      │                                                                  [缺欄位→VALIDATION 400]
      ▼
 配號（hct/kerrytj：號段原子遞增；tcat/sf：API 回單號）  [號段用罄→NUMBER_EXHAUSTED 409]
      ▼
 API 呼叫（tcat JSON / hct SOAP / sf AES+sign / kerrytj Basic Auth）  [逾時→NETWORK 502，**不回收號碼**，記 order note]
      ▼
 寫 _pc_{id}_ref + 標籤/列印資料 + order note ──▶ 回 { logistics_id }
 查詢：throttle transient ──▶ API ──▶ raw code → ShippingState 映射 ──▶ 寫 _pc_{id}_status / _pc_{id}_unified_state
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|---|---|---|---|---|
| `WebhookPipeline::handle()` | 簽章錯、找不到單、handler 拋例外 | `SIGNATURE` / `NOT_FOUND` / `UNKNOWN` | log + ack 200（不重送風暴） | 否（log 可見；站長看 WC 日誌） |
| `PaymentDispatchApiService` init | order_key 不符 | `AUTH` | 403 通用訊息 | 是（「訂單驗證失敗」） |
| `PaymentDispatchApiService` init | 訂單已付款 | `CONFLICT` | 409，前端導 order-received | 是 |
| `PaymentDispatchApiService` confirm | 重複送出 | `CONFLICT` | transient 鎖 409 | 是（「付款處理中」） |
| `ShoplineEmbeddedGateway::confirm` | trade/payment/create 失敗 / 無 nextAction | `UNKNOWN` / provider raw | order note + 500 通用訊息 | 是（通用） |
| `LinepayGateway::before_process_payment` | request returnCode≠0000 | `AUTH`/`VALIDATION`/`UNKNOWN` | `wc_add_notice` 通用 + order note 含 returnCode | 是 |
| `LinepayConfirmHandler` | 1172/1198 | `CONFLICT` | 查明細確認後冪等完成 | 否 |
| `LinepayConfirmHandler` | 1180 逾時 | `VALIDATION` | 保持 pending + note + 導 order-pay | 是 |
| `LinepayConfirmHandler` | 網路逾時 | `NETWORK` | 不改狀態，AS 補單 | 是（「確認中，稍後重整」） |
| `LinepayReconcileJob` | 查詢失敗 | `NETWORK` | log，AS 重排 1 次（+10min），之後放棄並 note | 否（note 可見） |
| `TcatPayApiClient::token()` | 401 / 帳密錯 | `AUTH` | 清快取重取 1 次，再失敗拋 | 是（結帳通用訊息） |
| `TcatPayCallback`（APN） | checksum 錯 / 平台查詢與 APN 不一致 | `SIGNATURE` | log + 回 'OK'（不信任、不改單） | 否 |
| `TcatPay` 平台查詢 | 逾時 | `NETWORK` | 不標 paid，**回非 `OK`** 讓平台重送（APN 每 15 分鐘、最多 3 次，可當作自然重試） | 否 |
| `*Gateway::process_refund` | 不支援 / 金額錯 / API 失敗 | `UNSUPPORTED`/`VALIDATION`/provider | 回正規化 `WP_Error`；gateway refund 失敗走既有 `fail_gateway_refund()` | 是（RefundDialog） |
| `*LogisticsProvider::create_shipment` | 欄位缺 | `VALIDATION` | throw → REST 400 | 是 |
| `*LogisticsProvider::create_shipment` | 號段用罄 | `NUMBER_EXHAUSTED` | throw → 409 + admin notice | 是 |
| `*LogisticsProvider::create_shipment` | API 逾時 | `NETWORK` | throw → 502 + note；號碼不回收（避免重複） | 是 |
| `TcatLogisticsProvider::query_shipment` | 2h 限流內 | — | 回快取 + `cached:true` | 是（UI 顯示「快取」） |
| `SfTrackPushCallback`（軌跡推送） | 驗簽失敗 / 解密失敗 | `SIGNATURE` | log + 依 skill `references/tracking.md` 規定的回應格式回 200；不改單 | 否 |
| `HctSoapClient` | SOAP Fault / 非 XML | `UNKNOWN` | 解析 faultstring → throw | 是（通用 + raw 記 note） |
| `KerrytjApiClient` | Basic Auth 401 | `AUTH` | throw | 是 |
| `SfAccessTokenStore` | token 過期 | `AUTH` | 清 transient 重取 1 次 | 否 |

> 無「處理方式=無 且 使用者可見=靜默」項目 → 無 CRITICAL GAP。
> 註：黑貓PAY APN 平台查詢逾時一列刻意回非 `OK`（利用平台 15 分鐘重送當重試），測試須覆蓋。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|---|---|---|---|---|---|
| SLP 嵌入式 confirm | 顧客雙擊付款 | 是（transient 鎖） | 是（edge） | 是 | 等待後重整 |
| SLP 嵌入式 | 3DS 中途關閉 | 是（訂單保持 pending） | 是（edge） | 是 | order-pay 重新 dispatch（新 paySession） |
| SLP 嵌入式 | webhook 先於前端導回 | 是（StatusManager 冪等） | 是 | 否 | — |
| SLP 嵌入式 | 過期 tab 對已付訂單再 dispatch | 是（409） | 是（security） | 是 | 導 order-received |
| LINE Pay | 顧客關瀏覽器沒回 confirmUrl | 是（AS +25min 補單） | 是（edge） | 否 | 自動補單 / 後台查詢補單 |
| LINE Pay | confirmUrl 被重整 | 是（1172 冪等） | 是（edge） | 否 | — |
| LINE Pay | 偽造 confirmUrl（換 transactionId） | 是（meta 比對） | 是（security） | 是 | — |
| LINE Pay | 19 位 transactionId 精度 | 是（BIGINT_AS_STRING） | 是（edge golden） | 否 | — |
| 黑貓PAY | 偽造 APN | 是（平台查詢 source of truth） | 是（security） | 否 | — |
| 黑貓PAY | 重複繳款（pay_amount≠order_amount） | 是（金額 guard，不自動完成，note） | 是（edge） | 是（note） | 人工處理 |
| 黑貓PAY | Token 過期 | 是（重取 1 次） | 是（error） | 否 | — |
| 新竹 / 嘉里 | 號段用罄 | 是 | 是（error） | 是 | 後台補新號段 |
| 新竹 / 嘉里 | 兩請求同時配號 | 是（SQL 原子遞增） | 是（edge：連續兩次配號不重複） | 否 | — |
| 物流建單 | 重複按建單 | 是（已有 ref → 冪等回傳） | 是（edge） | 是 | — |
| 黑貓宅急便 | 查詢超限 | 是（2h 快取） | 是（edge） | 是 | 2h 後再查 |
| 順豐 | accessToken 過期 | 是 | 是（error） | 否 | 自動重取 |
| 順豐 | TW→TW 正式不收 | 部分（設定頁警示） | 否（無法 mock 業務規則） | 是 | 聯繫順豐 |
| 所有新 gateway | 非 TWD 幣別 | 是（is_available=false） | 是（edge） | 是（不顯示付款方式） | 改幣別 |

---

## 實作步驟

> 共通規範（每個 Phase 都適用）
> - TDD：每個 Phase 內依「Red（先寫失敗測試）→ Green（最小實作）→ Refactor」；Red 階段測試必須先在 mock 模式下**確認紅燈**才進 Green。
> - 每個測試 class / method 必須掛 `@group` 白名單其一（`smoke`/`happy`/`error`/`edge`/`security`）＋分類 group（`integration` + `payment`/`logistics` + provider id）。
> - Mock：沿用各既有 provider 的 `API_MODE=mock` 手法（`pre_http_request` filter 攔截 `wp_remote_*`），新 provider 一律提供 `tests/Integration/{Domain}/Fixtures/{provider}/*.json` golden 回應。
> - PHP：`declare(strict_types=1)`、`final class`、static hook callback、text domain `power_checkout`、`Plugin::logger()`、HPOS 用 `$order->get_meta()`。
> - Vue：`<script setup lang="ts">`、`@/` alias、Element Plus、設定頁複製最相近的既有頁（金流參考 `js/src/pages/Payments/Paynow/`、物流參考 `js/src/pages/Logistics/Paynow/`）。
> - 完成標準（每 Phase 相同，下文不再重複）：
>   1. 該 Phase 新測試全綠，且 **全套** `API_MODE=mock` 無新失敗（扣除 R15 的 ezpay edge）
>   2. `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress`（full tree）無新 error（扣除 R15）
>   3. `composer lint`（PHPCS）0 error
>   4. 有前端變更時 `pnpm build` 與（金流）`pnpm build:blocks` 成功、`pnpm lint` 0 error
>   5. 一個 conventional commit（或該 Phase 的一組 commit）推到 `feat/unified-gateway-providers`，寫入 `specs/plan/.progress.json` 檢查點
>
> 測試指令（擇一可用者）：
> ```bash
> # 首選
> npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirect --no-coverage"
> # wp-env tests-wordpress 綁 port 失敗時（memory: wp-env-gate-commands.md）
> cd "C:/Users/User/.wp-env/d5a5492d5679ff98cc7c025012f4fdfb" && docker compose -p d5a5492d5679ff98cc7c025012f4fdfb run --rm --no-deps tests-cli bash -c "cd /var/www/html/wp-content/plugins/power-checkout && API_MODE=mock vendor/bin/phpunit --no-coverage"
> # PHPStan（PowerShell 本地即可）
> php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
> ```
> ⚠️ `--group <非白名單>` 會 0 收集；一律「目錄路徑 + `--filter`」。同一時間只跑一個 phpunit（R14）。

---

### 第零步：分支與檢查點

1. **開分支**（無檔案）
   - 行動：`git fetch && git checkout master && git pull && git checkout -b feat/unified-gateway-providers`
   - 原因：用戶指定；目前分支 `chore/update-claude-skills` 只含 skill 更新，若其尚未合併 master，需先確認 skill 檔（`.claude/skills/*`）在新分支可讀——**若不在**，從 `chore/update-claude-skills` cherry-pick `cc30605`（只含 `.claude/skills/` 變更）。
   - 風險：低
2. **建立進度檔**（`specs/plan/.progress.json`）
   - 行動：`{ "plan": "2026-11-20-unified-gateway-and-7-providers", "phases": { "P0": "pending", ... "P9": "pending" }, "last_commit": null }`；每 Phase 完成更新。
   - 原因：大規模任務檢查點；崩潰或逾時從最後一個 `done` 之後繼續。

---

### P0：統一介面（預估 8 小時，複雜度：中，風險：中）

依賴：無。所有後續 Phase 依賴 P0。

**Red — 新增測試**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Payment/ProviderMetaContractTest.php` | `smoke`,`integration`,`payment` | 7 個既有 gateway 皆 `instanceof IPaymentProvider` 且 `get_provider_meta()` 回 `ProviderMetaDTO`；`id === Gateway::ID`；capabilities 6 鍵皆為 bool（data provider 逐家） |
| 同上 | `happy` | 各家 capability 等於「架構變更」表（以 Red 前 grep 修正後的版本） |
| 同上 | `edge` | 未覆寫 `get_provider_meta()` 的 anonymous gateway（extends AbstractPaymentGateway）回保守預設：`form-redirect`、`pre-create-pending`、capture/void/query/recurring=false |
| `tests/Integration/Payment/WebhookPipelineTest.php` | `happy` | verify=true + 找到單 + 新 key → handler 被呼叫 1 次、key 寫入 meta、回 200 |
| 同上 | `edge` | 同 key 第二次 → handler 不被呼叫、仍回 200 |
| 同上 | `edge` | `_pc_callback_processed` 超過 50 筆時只保留最新 50 |
| 同上 | `security` | verify=false → handler 不被呼叫、回 200、log 含 `SIGNATURE` |
| 同上 | `error` | handler 拋 `\RuntimeException` → 回 200、log error、key **不**寫入（允許重送重試） |
| 同上 | `error` | 找不到單 → 回 200、log `NOT_FOUND` |
| 同上 | `happy` | provider 覆寫 `callback_ack_response()` 回 `text/plain OK` 時外框原樣回傳 |
| 同上 | `security` | 預設 `verify_callback()` 回 false（fail-closed） |
| `tests/Integration/Payment/PaymentDispatchApiServiceTest.php` | `smoke` | 路由 `power-checkout/v1/pay/(?P<order_id>\d+)/dispatch` 已註冊 |
| 同上 | `security` | order_key 錯 → 403；缺 order_key → 403；不存在訂單 → 404 |
| 同上 | `error` | 訂單 gateway 不是 `IEmbeddedPaymentProvider` → 400 `UNSUPPORTED` |
| 同上 | `edge` | 訂單已付款（`!needs_payment()`）→ 409 |
| 同上 | `happy` | 以 test double embedded gateway 回傳 `{mode,provider,...}` 原樣包進 `data` |
| `tests/Integration/Logistics/LogisticsProviderMetaContractTest.php` | `smoke`,`happy` | 既有 3 家 `get_provider_meta()` 回 DTO、capability 與現行實作一致（ecpay `can_return=true`、paynow `can_return=false`…以 Red 前讀碼確認） |
| `tests/Integration/Logistics/ShippingStateTest.php` | `happy`,`edge` | 11 case 值、`is_terminal()`（delivered/picked_up/returned/cancelled/expired_unclaimed/failed 為 true） |
| `tests/Integration/Logistics/LogisticsApiServiceCapabilityTest.php` | `error` | test double provider 宣告 `can_print=false` → `POST /logistics/{id}/print` 回 501 |
| 同上 | `happy` | `GET /logistics/providers/meta` 回已啟用 provider 的 meta（需 admin nonce）；未登入 → 401/403 |

**回歸**：`PaymentProviderContractTest`、`tests/Integration/Logistics/*` 全綠不變。

**Green — 實作**：依「架構變更 P0」表；`AbstractPaymentGateway` 只新增方法，`WebhookPipeline` 承載流程邏輯（每個方法 <50 行）。

**Refactor**：確認 `AbstractPaymentGateway` 新增程式 <60 行；DTO 皆 `final readonly`。

**完成標準**：共通 5 項 + 既有 Payment 基線 tests 數只增不減。

---

### P1：SHOPLINE Payments 嵌入式（預估 16 小時，複雜度：高，風險：高）

依賴：P0。參考：zenbu `payments/shopline/shopline-embedded.ts`、`shopline.service.ts:1242-1390`、`payment-dispatch.service.ts:544-603`、`apps/web/components/shop/checkout/ShoplinePaymentPanel.tsx`；skill `shopline-payments-v1/references/embedded-sdk.md`、`api-reference.md` §3、`webhook-events.md`、`sandbox-resources.md`。

**新增 / 修改檔案**

| 檔案 | 說明 |
|---|---|
| `inc/classes/Domains/Payment/ShoplinePayment/Services/EmbeddedGateway.php` | `final`，extends `AbstractPaymentGateway` implements `IEmbeddedPaymentProvider`；`ID='shopline_payment_embedded'`；`get_provider_meta()`=client-sdk、refund/partial_refund/query=true；`before_process_payment()` 寫 `_pc_slp_embedded_pending=yes` 回 order-received URL；`before_order_received()` localize `power_checkout_slp_embedded_data`（dispatch URL、order_id、order_key、SDK CDN URL、env）；`dispatch()` 兩段式；`process_refund()` 重用既有 `ApiClient::create_refund()`；`verify_callback()` 委派既有 webhook HMAC 驗證（ASM-3） |
| `inc/classes/Domains/Payment/ShoplinePayment/DTOs/EmbeddedSettingsDTO.php` | 欄位：enabled、title、description、mode（預設 `test`，R17）、merchant_id、api_key、sign_key、client_key（test/prod 各一組，沿用 `RedirectSettingsDTO` 的命名慣例但**不繼承**它，避開 R15） |
| `inc/classes/Domains/Payment/ShoplinePayment/DTOs/Trade/Payment/CreateSdkPaymentDTO.php` | `trade/payment/create` request：`acquirerType='SDK'`、`paySession`（原樣字串）、`amount{value,currency}`（後端算）、`referenceOrderId=(string)order_id`、`returnUrl`（order-received + key）、`confirm` 等依 skill §3 |
| `inc/classes/Domains/Payment/ShoplinePayment/Http/ApiClient.php` | **只加** `create_sdk_payment(CreateSdkPaymentDTO): array`（回 `tradeOrderId`、`nextAction` 原樣字串）；不改既有方法 |
| `inc/classes/Domains/Payment/ShoplinePayment/Managers/OrderResolver.php` | `is_slp_order()` 改為 `in_array($order->get_payment_method(), [RedirectGateway::ID, EmbeddedGateway::ID], true)`（R7，唯一行為修改） |
| `inc/classes/Domains/Payment/ShoplinePayment/Managers/EventTypeManager.php` | 確認 `trade.succeeded`/`trade.failed`/`trade.cancelled` 對 embedded 訂單走 `StatusManager`；若既有已處理則**不改**，只補測試 |
| `inc/classes/Domains/Payment/ShoplinePayment/Shared/Helpers/EmbeddedBlocksIntegration.php` | WC Blocks 註冊（參考 `Paynow/Shared/Helpers/PaynowBlocksIntegration.php`） |
| `inc/classes/Domains/Payment/ProviderRegister.php` | `$gateway_services` 加 `EmbeddedGateway::ID` |
| `inc/assets/blocks/shopline_payment_embedded.tsx` | Blocks 付款方式註冊（照 `react-blocks.rule.md` 樣板） |
| `js/src/external/ShoplineEmbedded/index.ts` | `MountShoplineEmbedded()`：載 SLP SDK（skill 指定的 npm / CDN 版本，**鎖定精確版本**）、init → `createPayment()` → POST dispatch(confirm) → `pay(nextAction)` → 導 order-received；按鈕 disabled 防雙擊；錯誤顯示通用訊息 |
| `js/src/index.ts` | 呼叫 `MountShoplineEmbedded()` |
| `js/src/pages/Payments/ShoplineEmbedded/index.vue` + `Shared/types.ts` | 設定頁（複製 `Payments/SLP` 再精簡：無 session 相關欄位，加 client_key） |
| `js/src/router/index.ts` | route `/payments/shopline_payment_embedded` + `ROUTER_MAPPER` |
| `js/src/types/global.d.ts`、`js/src/utils/env.ts` | 新 localize 物件型別與讀取函式 |

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Payment/SlpEmbeddedSettingsDTOTest.php` | `happy`,`edge` | 預設 mode=test；client_key 空時 `is_available()=false`；trim |
| `tests/Integration/Payment/SlpEmbeddedGatewayTest.php` | `smoke` | 已註冊於 `woocommerce_payment_gateways`；`get_provider_meta()` 正確 |
| 同上 | `happy` | `process_payment` 回 order-received URL、寫 pending meta、**不打 API** |
| 同上 | `edge` | 非 TWD → `is_available()=false` |
| `tests/Integration/Payment/SlpEmbeddedDispatchTest.php` | `happy` | init：回 clientKey / env / amount（= round(total*100)）/ currency；不打 API |
| 同上 | `happy` | confirm：帶 pay_session → 打 `trade/payment/create`（mock），body `acquirerType=SDK`、`paySession` 原樣、amount 後端算；回 `next_action` 原樣字串；寫 `_pc_payment_identity` |
| 同上 | `security` | 前端送 `amount` 欄位被忽略（mock 斷言 request 金額 = 訂單金額） |
| 同上 | `edge` | 30 秒內第二次 confirm → 409 |
| 同上 | `error` | API 回應無 nextAction → 500 通用訊息 + order note |
| 同上 | `error` | API 逾時 → 500 + 鎖釋放 |
| 同上 | `security` | pay_session 超長（>10KB）或非字串 → 400 |
| `tests/Integration/Payment/SlpEmbeddedWebhookTest.php` | `happy` | `trade.succeeded`（tradeOrderId 對應 embedded 訂單）→ processing + transaction id |
| 同上 | `security` | `trade.succeeded` 指向 `bacs` 訂單 → 不處理 |
| 同上 | `edge` | 重複事件 → 冪等 |
| 同上 | `error` | 金額不符 → 不轉 processing（既有 guard） |
| `tests/Integration/Payment/SlpEmbeddedRefundTest.php` | `happy`,`error` | 全額 / 部分退款；API 失敗 → `fail_gateway_refund` |
| `tests/Integration/Payment/SlpOrderResolverTest.php`（既有） | 回歸 | 全綠不變 + **新增** 1 案：embedded 訂單可被 resolve |

**完成標準**：共通 5 項 + live 驗收見 P8。

---

### P2：黑貓PAY `tcat_pay`（預估 14 小時，複雜度：高，風險：高）

依賴：P0。參考：zenbu `payments/kolet/*`（`kolet-crypto.ts`、`kolet-apn-verify.ts`、`kolet-client.ts`、`kolet-amount-limits.ts`、`kolet-reconcile.cron.ts`）；skill `tcat-pay/SKILL.md`、`references/api-reference.md`、`callbacks-apn.md`、`codes-reference.md`、`examples.md`（含 checksum/chk 測試向量）。

**新增檔案（`inc/classes/Domains/Payment/TcatPay/`）**

| 檔案 | 說明 |
|---|---|
| `Services/TcatPayGateway.php` | `ID='tcat_pay'`；meta：api-redirect、refund/partial_refund/capture/void/query=true（COCS）；`before_process_payment()` 依所選子方式（`credit` / `cvs_ibon` / `atm`，由 checkout 欄位決定，classic `payment_fields()` + blocks `onPaymentSetup`）呼叫 CocsOrderAppend 或 CvsOrderAppend；`before_order_received()` 處理 COCS 導轉回報（驗 chk + 平台查詢）並顯示 CVS/ATM 代碼；`process_refund()`=CocsOrderRefund（僅 COCS，CVS→`UNSUPPORTED`）；`capture()`=CocsCashRequest；`void_auth()`=CocsOrderCancel；`query_trade()`；`verify_callback()`=APN checksum；`callback_ack_response()`=text `OK` |
| `Http/TcatPayApiClient.php` | Token（transient 快取、401 重取 1 次）+ `/api/Collect` cmd 分派；15 秒逾時 |
| `Http/TcatPayCallback.php` | REST `power-checkout/tcat-pay/apn`（POST）→ `$gateway->handle_webhook()`；**無條件註冊**（同 PayNow 理由） |
| `DTOs/TcatPaySettingsDTO.php` | mode（預設 test）、cust_id、username、password、hash_base（chk 用）、acquirer_type、cvs_payment_type、expire_days、enabled_methods |
| `DTOs/CocsOrderAppendParams.php`、`DTOs/CvsOrderAppendParams.php` | 必填顯式驗證（R16）；`send_time` 當下時間；`order_detail` ≤500（CVS PDF ≤150）由 `ItemName` 截斷 |
| `Managers/StatusManager.php` | APN status 映射：COCS `B`/`E`→processing（以平台查詢為準）、`F`→pending+note、`M`/`Q`→note；CVS `B`→processing、`D`（過期）→**保持 pending + note**（不自動取消，避免誤取消）；金額 guard 以 `pay_amount`；冪等 `_pc_tcat_pay_processed_status` |
| `Shared/Helpers/TcatPayChecksum.php` | `apn_checksum()`、`cocs_chk_success()`、`cocs_chk_fail()`、`ibon_update_date_checksum()`；`hash_equals` 比對 |
| `Shared/Helpers/TcatPayMetaKeys.php`、`TcatPayTradeNo.php`（`PCT{order_id}` + 重試後綴，≤25 碼）、`ItemName.php` | |
| `Shared/Helpers/TcatPayAmountLimits.php` | 移植 zenbu `kolet-amount-limits.ts`（各子方式金額上下限），`is_available()` 與前置驗證共用 |
| `Shared/Enums/TcatPayMethod.php`、`TcatPayApnStatus.php` | |
| `Shared/Helpers/TcatPayBlocksIntegration.php` | |

前端：`inc/assets/blocks/tcat_pay.tsx`（含子方式 radio）、`js/src/pages/Payments/TcatPay/index.vue`、router + `ROUTER_MAPPER`、`ProviderRegister::$gateway_services`。

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Payment/TcatPayChecksumTest.php` | `happy` | skill `references/examples.md` 官方 2 組 APN 向量、chk 成功/失敗向量 golden |
| 同上 | `security` | 大小寫 / 少一欄 → 不相等 |
| `tests/Integration/Payment/TcatPayApiClientTest.php` | `happy` | Token 取得後快取；Bearer header 格式 `Bearer {token}`（含空格） |
| 同上 | `error` | 401 → 清快取重取 1 次；再 401 → 拋 `AUTH` |
| 同上 | `error` | cust_id 不匹配訊息 → `AUTH` |
| `tests/Integration/Payment/TcatPayGatewayTest.php` | `happy` | credit：CocsOrderAppend → redirect 回傳的 url |
| 同上 | `happy` | cvs_ibon：CvsOrderAppend → 寫 payment_info、回 order-received；order-received 顯示代碼與期限 |
| 同上 | `edge` | 金額超過子方式上限 → 子方式不顯示 / 前置 `VALIDATION` |
| 同上 | `edge` | order_detail 超長截斷 |
| `tests/Integration/Payment/TcatPayApnTest.php` | `happy` | 合法 APN `B` + 平台查詢回已付 → processing、回 `OK` |
| 同上 | `security` | checksum 正確但平台查詢回未付 → 不改狀態、回 `OK`、log |
| 同上 | `security` | checksum 錯 → 不打平台查詢、回 `OK` |
| 同上 | `edge` | 同狀態重送 → 冪等 |
| 同上 | `edge` | 10 秒節流內第二次 → 不打平台查詢 |
| 同上 | `error` | 平台查詢逾時 → **回非 OK**（讓平台重送） |
| 同上 | `edge` | pay_amount ≠ order total → 不完成、note「疑似重複/金額不符」 |
| `tests/Integration/Payment/TcatPayReturnTest.php` | `security` | COCS 導轉 chk 錯 → 不改狀態 |
| 同上 | `happy` | chk 對 + 平台已付 → processing |
| `tests/Integration/Payment/TcatPayRefundTest.php` | `happy`,`error` | COCS 部分 / 全額；CVS → `UNSUPPORTED`；API 失敗 → `fail_gateway_refund` |
| `tests/Integration/Payment/TcatPaySettingsDTOTest.php` | `happy`,`edge` | 預設 test 模式；sandbox base `https://test.4128888card.com.tw/app` |

---

### P3：LINE Pay 直連 `linepay`（預估 12 小時，複雜度：中，風險：中）

依賴：P0。參考：zenbu `payments/linepay/linepay.service.ts`、`linepay-signature.ts`、`linepay-reconcile.cron.ts`；skill `linepay/SKILL.md`、`references/authentication.md`、`flows-and-status.md`、`error-codes.md`、`sandbox-and-onboarding.md`。

**新增檔案（`inc/classes/Domains/Payment/Linepay/`）**

| 檔案 | 說明 |
|---|---|
| `Services/LinepayGateway.php` | `ID='linepay'`；meta：api-redirect、refund/partial_refund/query=true、capture/void=false（自動請款）；`before_process_payment()`=request → redirect `paymentUrl.web`；`process_refund()`；`query_trade()`=payment details；admin order action `pc_linepay_query_trade` |
| `Http/LinepayApiClient.php` | v4；`X-LINE-ChannelId` / `X-LINE-Authorization-Nonce`（UUID v4）/ `X-LINE-Authorization`=Base64(HMAC-SHA256(secret, secret+uri+body或query+nonce))；`JSON_BIGINT_AS_STRING`（R4） |
| `Http/LinepayReturnController.php` | REST `power-checkout/linepay/confirm`（GET）與 `/cancel`（GET）→ 統一外框 `handle_webhook()`，ack 覆寫為 302 |
| `Managers/LinepayReconcileJob.php` | Action Scheduler hook `pc_linepay_reconcile`（args: order_id），request 成功時 `as_schedule_single_action(time()+1500, ...)`；已付 / 已非 pending → no-op |
| `Managers/StatusManager.php` | confirm 結果映射；`1172`/`1198` → 查明細；冪等 |
| `DTOs/LinepaySettingsDTO.php`、`DTOs/RequestParams.php`（packages 金額規則：amount=Σpackages、package=Σ(price×qty)，以**整數 TWD** 組，不含小數；運費 / 折扣以獨立 product 行處理，確保總和等於訂單 total） | |
| `Shared/Helpers/LinepaySignature.php`、`LinepayMetaKeys.php`、`LinepayOrderId.php`（`PCL{order_id}-{attempt}`，每次付款唯一，R3 `1172`） | |
| `Shared/Enums/LinepayReturnCode.php` | 至少 `0000`/`1104`/`1106`/`1172`/`1180`/`1198`/`2101` + `to_error_code()` 映射到 `ErrorCode` |
| `Shared/Helpers/LinepayBlocksIntegration.php` | |

前端：`inc/assets/blocks/linepay.tsx`、`js/src/pages/Payments/Linepay/index.vue`、router + `ROUTER_MAPPER`、`ProviderRegister`。

**Red — 測試清單**（檔名前綴 `LinepayDirect` 避免與既有 SLP `LinePayTest` 混淆）

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Payment/LinepayDirectSignatureTest.php` | `happy` | skill `references/authentication.md` 的 golden 簽章（POST body / GET query 兩種） |
| `tests/Integration/Payment/LinepayDirectRequestParamsTest.php` | `happy` | packages 總和 = amount；含運費與折扣 |
| 同上 | `edge` | 含稅小數總額 → 四捨五入後 packages 調整行使總和一致 |
| `tests/Integration/Payment/LinepayDirectGatewayTest.php` | `happy` | process_payment → redirect paymentUrl、寫 transaction_id（字串）、排 AS 任務 |
| 同上 | `error` | returnCode `1104` → notice + note + 不 redirect |
| 同上 | `edge` | 19 位 transactionId（`2026111900000000001`）存取不失真 |
| `tests/Integration/Payment/LinepayDirectConfirmTest.php` | `happy` | confirm `0000` → processing、302 order-received |
| 同上 | `edge` | 第二次 confirmUrl（`1172`）→ 查明細已付 → 維持 processing、不重複 note |
| 同上 | `security` | transactionId 與 meta 不符 → 不 confirm、不改狀態 |
| 同上 | `security` | order_key 錯 → 不 confirm |
| 同上 | `error` | `1180` → 保持 pending + note、302 order-pay |
| 同上 | `error` | confirm 網路逾時 → 不改狀態 |
| 同上 | `happy` | cancelUrl → note + 302 order-pay |
| `tests/Integration/Payment/LinepayDirectReconcileTest.php` | `happy` | pending 訂單 + check 回已授權 → confirm → processing |
| 同上 | `edge` | 已 processing → no-op（不打 API） |
| `tests/Integration/Payment/LinepayDirectRefundTest.php` | `happy`,`error` | 部分 / 全額；`1165` 已全退 → `VALIDATION` |
| `tests/Integration/Payment/LinepayDirectSettingsDTOTest.php` | `happy`,`edge` | 預設 test；sandbox host `https://sandbox-api-pay.line.me` |

---

### P4：黑貓宅急便直串 `tcat_logistics`（預估 12 小時，複雜度：中，風險：中）

依賴：P0。參考：zenbu `shipping/tcat/tcat-logistics.service.ts`、`tcat-api-client.ts`；skill `tcat-logistics/SKILL.md`、`references/api-reference.md`（§11.0 限流）、`cargo-status.md`、`label-spec.md`。範圍見 ASM-6。

**新增檔案（`inc/classes/Domains/Logistics/Tcat/`）**

| 檔案 | 說明 |
|---|---|
| `Services/TcatLogisticsProvider.php` | implements `ILogisticsProvider`；meta：HOME only、create/query/print/return=true、has_cvs_map=false、cancel=false；`get_store_selection`/`parse_store_selection` 拋 `UNSUPPORTED`；`create_shipment()`=PrintOBT（取得 OBTNumber + FileNo）；`print_document()`=DownloadOBT（PDF）；`query_shipment()`=OBTStatus（2h 快取，R8）→ `unified_state`；`create_return()`=ReserveAdd；`cancel_shipment()` 拋 `UNSUPPORTED`；`handle_status_callback()` 回 200 no-op（無推送） |
| `Services/WC_TcatLogisticsShipping.php` | extends `WC_Shipping_Method`（宅配運費、溫層選項：常溫/冷藏/冷凍） |
| `Http/TcatLogisticsApiClient.php` | JSON POST，`CustomerId`/`CustomerToken` 放 body 最上層；E001–E091 錯誤碼映射 |
| `DTOs/TcatLogisticsSettingsDTO.php`、`DTOs/PrintObtParams.php`（收件人、地址、溫層、尺寸、代收、指定時段；必填顯式驗證） | |
| `Shared/Helpers/TcatLogisticsMetaKeys.php`、`Shared/Enums/TcatThermosphere.php`、`TcatSpec.php`、`Shared/Helpers/TcatStatusMapper.php`（貨態碼 → `ShippingState`，照 skill `cargo-status.md`） | |

修改：`Logistics/ProviderRegister.php::$logistics_providers`、`Logistics/Shared/Services/LogisticsApiService.php::PROVIDER_IDS`、`add_shipping_method`；前端 `js/src/pages/Logistics/Tcat/index.vue` + router + `ROUTER_MAPPER`；訂單頁物流操作按鈕依 meta capability 顯示（既有物流 MetaBox 元件若為 hardcode 按鈕，改為讀 `meta.capabilities`——**只對新 provider 生效**的條件分支）。

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Logistics/TcatLogisticsApiClientTest.php` | `happy` | body 最上層含 CustomerId/CustomerToken；無簽章 |
| 同上 | `error` | `IsOK=N` + E0xx → 對應 ErrorCode 例外 |
| `tests/Integration/Logistics/TcatLogisticsProviderTest.php` | `happy` | create_shipment → 寫 `_pc_tcat_logistics_ref`、回 logistics_id |
| 同上 | `edge` | 已有 ref → 冪等回既有、不打 API |
| 同上 | `error` | 收件電話缺 → `VALIDATION` |
| 同上 | `happy` | print_document 回 PDF（base64 / 二進位依 skill 格式） |
| 同上 | `edge` | query 2h 內第二次 → 回快取 `cached=true`、不打 API |
| 同上 | `happy` | 貨態碼映射到 `ShippingState`（data provider 覆蓋 skill 表中每組代表碼） |
| 同上 | `happy`,`error` | create_return（ReserveAdd）成功 / 隔日取件截止（16:30 取嚴格值）錯誤 |
| 同上 | `error` | get_store_selection / cancel_shipment → `UNSUPPORTED` |
| `tests/Integration/Logistics/TcatLogisticsRegisterTest.php` | `smoke` | 註冊、meta、REST print 對 tcat 不回 501 |
| `tests/Integration/Logistics/WC_TcatLogisticsShippingTest.php` | `happy` | 運費計算、溫層 meta 寫入訂單 |

---

### P5：新竹物流 `hct_logistics`（預估 14 小時，複雜度：高，風險：高）

依賴：P0；號段配號器與 P7 共用 → **P5 建立 `Logistics/Shared/Services/WaybillNumberAllocator.php`**。參考：zenbu `shipping/hct/hct-soap-client.ts`、`hct-logistics.service.ts`、`hct-track-crypto.ts`；skill `hct-logistics/SKILL.md`、`references/api-reference.md`、`code-examples.md`、`cargo-tracking-decrypt.md`、`version-notes.md`（WSDL 實打觀察）。

**新增檔案（`inc/classes/Domains/Logistics/Hct/`）**

| 檔案 | 說明 |
|---|---|
| `Services/HctLogisticsProvider.php` | meta：HOME only、create/query/print/return/cancel=true；create=`TransData_Json`（回貨號 edelno + 到著站 + image hex）；print=image hex → PNG（GD）→ HTML；query=貨況查詢（DES-CBC 解密，照 `cargo-tracking-decrypt.md` 已驗證向量）；return=`R_TransData_Json`；cancel=`TransDataCancel_Json` |
| `Http/HctSoapClient.php` | 手組 SOAP 1.1 envelope（namespace / SOAPAction 依 `version-notes.md` 實打值），`wp_remote_post` `text/xml; charset=utf-8`，回應 XML 取 `*Result` 再 `json_decode`；SOAP Fault 解析 |
| `Shared/Helpers/HctTrackCrypto.php` | DES-CBC（`openssl_decrypt` `DES-CBC`；注意 OpenSSL 3 legacy provider，若不可用則測試標 skip 並在設定頁警示） |
| `Shared/Helpers/HctLabelImage.php` | hex → 1bit bitmap → PNG |
| `Shared/Helpers/HctStatusMapper.php` | 貨況 → `ShippingState` |
| `DTOs/HctLogisticsSettingsDTO.php`（company、password、號段起迄、test/prod）、`DTOs/TransDataParams.php` | |
| `Services/WC_HctLogisticsShipping.php` | |
| `inc/classes/Domains/Logistics/Shared/Services/WaybillNumberAllocator.php` | 通用號段配號：option `pc_waybill_range_{provider}` = `{start,end,current}`；`$wpdb->query("UPDATE {$wpdb->options} SET option_value = ... WHERE option_name=%s AND ...")` 原子遞增（實作上以獨立小表或 `wp_options` + `LAST_INSERT_ID(expr)` 技巧取值）；用罄拋 `NUMBER_EXHAUSTED`；可注入 checksum 策略（Codabar 檢查碼 / kerrytj mod 7） |

前端：`js/src/pages/Logistics/Hct/index.vue`（含號段輸入與剩餘量顯示）、router、`ROUTER_MAPPER`；`ProviderRegister`、`LogisticsApiService::PROVIDER_IDS`。

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Logistics/WaybillNumberAllocatorTest.php` | `happy` | 依序配號、current 遞增 |
| 同上 | `edge` | 連續兩次配號不重複；最後一號後用罄 → `NUMBER_EXHAUSTED` |
| 同上 | `error` | 未設定號段 → `VALIDATION` |
| `tests/Integration/Logistics/HctSoapClientTest.php` | `happy` | envelope golden（method、前兩參數 company/password、JSON 參數 CDATA/escape） |
| 同上 | `error` | SOAP Fault → 例外含 faultstring；非 XML → `UNKNOWN` |
| `tests/Integration/Logistics/HctTrackCryptoTest.php` | `happy` | skill 已驗證 test vector |
| `tests/Integration/Logistics/HctLabelImageTest.php` | `happy` | fixture hex → PNG 尺寸正確 |
| `tests/Integration/Logistics/HctLogisticsProviderTest.php` | `happy` | create → ref = edelno、存標籤 |
| 同上 | `edge` | 已建單冪等 |
| 同上 | `happy`,`error` | cancel / return 成功與失敗 |
| 同上 | `happy` | 貨況映射 |
| `tests/Integration/Logistics/HctLogisticsRegisterTest.php` | `smoke` | 註冊 + meta |

---

### P6：順豐國際 `sf_express`（預估 14 小時，複雜度：高，風險：中）

依賴：P0。參考：zenbu `shipping/sf-express/*`（`sf-crypto.ts`、`sf-access-token.store.ts`、`sf-api-client.ts`、`sf-order-payload.ts`、`sf-track-push.ts`、`sf-opcode-state.ts`、`sf-region.ts`、`sf-duplicate-order-adoption.spec.ts`）與 `specs/features/shipping-sf-express/*.feature`；skill `sf-express/SKILL.md`、`references/auth-and-crypto.md`（§0.8 公開沙箱向量）、`iuop-create-order.md`、`iuop-order-ops.md`、`tracking.md`、`taiwan-rules.md`、`address-region.md`、`php-client.md`。

**新增檔案（`inc/classes/Domains/Logistics/SfExpress/`）**

| 檔案 | 說明 |
|---|---|
| `Services/SfExpressProvider.php` | meta：HOME only、create/query/print/cancel=true、return=false、has_cvs_map=false；create=`IUOP_CREATE_ORDER`（重複單號錯誤時採用既有單，照 zenbu duplicate-order-adoption）；print=`IUOP_PRINT_ORDER`；query=`IUOP_QUERY_TRACK`；cancel=`IUOP_CANCEL_ORDER`（依 skill 實際 msgType 名）；`handle_status_callback()`=軌跡推送（驗簽 + 解密 + opCode→`ShippingState`，冪等鍵 `{waybill}:{opCode}:{time}`） |
| `Http/SfApiClient.php` | dispatch 單一入口；header `msgType`/signature；body AES 密文；回應 `apiResultData` 解密 |
| `Http/SfTrackPushCallback.php` | REST `power-checkout/sf-express/track-push`（POST，無條件註冊，永遠 200 + 順豐規定回應格式） |
| `Shared/Helpers/SfCrypto.php`、`SfAccessTokenStore.php`（transient）、`SfOpcodeMapper.php`、`SfRegion.php`（台灣郵編 → 縣市英文名） | |
| `DTOs/SfExpressSettingsDTO.php`（customerCode、appKey、appSecret、encodingAesKey、月結卡號、interProductCode、test/prod）、`DTOs/CreateOrderParams.php` | |
| `Services/WC_SfExpressShipping.php` | |

前端：`js/src/pages/Logistics/SfExpress/index.vue`（含 TW→TW 警示，R12）、router、`ROUTER_MAPPER`；`ProviderRegister`、`PROVIDER_IDS`。

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Logistics/SfCryptoTest.php` | `happy` | skill §0.8 官方 sample 加解密 + signature golden |
| 同上 | `security` | 篡改密文 → 解密失敗例外 |
| `tests/Integration/Logistics/SfAccessTokenStoreTest.php` | `happy`,`edge` | 快取命中、過期重取、401 清快取重取 1 次 |
| `tests/Integration/Logistics/SfExpressProviderTest.php` | `happy` | create → ref = sfWaybillNo |
| 同上 | `edge` | 重複訂單錯誤碼 → 採用既有單 |
| 同上 | `edge` | 已建單冪等 |
| 同上 | `happy` | print、query 映射、cancel |
| 同上 | `error` | apiResultCode 非成功 → 例外含 raw code |
| 同上 | `error` | create_return → `UNSUPPORTED` |
| `tests/Integration/Logistics/SfTrackPushCallbackTest.php` | `happy` | 合法推送 → 寫 status/unified_state |
| 同上 | `security` | 簽章錯 → 不改單、仍回 200 |
| 同上 | `edge` | 重複推送冪等；未知 waybill → 200 no-op |
| `tests/Integration/Logistics/SfRegionTest.php` | `happy` | 郵編 → 英文縣市 |

---

### P7：嘉里大榮 `kerrytj_logistics`（預估 10 小時，複雜度：中，風險：中——無憑證）

依賴：P0、P5 的 `WaybillNumberAllocator`。參考：zenbu `shipping/kerrytj/*`（`kerrytj-api-client.ts`、`kerrytj-bln.ts`、`kerrytj-bln-allocator.ts`、`kerrytj-payload.ts`、`kerrytj-tracking.ts`）+ `docs/shipping-kerrytj.md`；skill `kerrytj-logistics/SKILL.md`、`references/api-reference.md`、`tracking.md`、`php-client.md`、`onboarding-and-business-rules.md`。

**新增檔案（`inc/classes/Domains/Logistics/Kerrytj/`）**

| 檔案 | 說明 |
|---|---|
| `Services/KerrytjLogisticsProvider.php` | meta：HOME only、create/query/print/return=true、cancel 依 skill（`UpdateShipmentDelivery` 可否作廢；不可則 false）；create=`ShipmentDelivery`（我方配 BLN）；print=`Report/GetLabelPdf`；query=`BLNListTracing` / `ColdBLNListTracing`（依溫層）；return=`PickupV2` |
| `Http/KerrytjApiClient.php` | Basic Auth；測試 `https://api.kerrytj.com/EDIEntranceTest/...` |
| `Shared/Helpers/KerrytjBln.php` | 常溫 11 碼流水號、低溫 9+1 mod 7 檢查碼（作為 `WaybillNumberAllocator` 的 checksum 策略） |
| `Shared/Helpers/KerrytjStatusMapper.php` | 常溫貨態表 1/2 + 低溫貨態表 → `ShippingState` |
| `DTOs/KerrytjLogisticsSettingsDTO.php`（帳密、客編、常溫/低溫號段）、`DTOs/ShipmentDeliveryParams.php`（34 欄，必填與合法值驗證） | |
| `Services/WC_KerrytjLogisticsShipping.php` | |

前端：`js/src/pages/Logistics/Kerrytj/index.vue`、router、`ROUTER_MAPPER`；`ProviderRegister`、`PROVIDER_IDS`。

**Red — 測試清單**

| 測試檔 | Group | 案例 |
|---|---|---|
| `tests/Integration/Logistics/KerrytjBlnTest.php` | `happy` | 低溫 mod 7 golden（移植 zenbu `kerrytj-bln.spec.ts` 向量） |
| 同上 | `edge` | 號段邊界、常溫 11 碼補零 |
| `tests/Integration/Logistics/KerrytjApiClientTest.php` | `happy` | Basic Auth header、測試 base URL |
| 同上 | `error` | 401 → `AUTH` |
| `tests/Integration/Logistics/KerrytjShipmentParamsTest.php` | `happy`,`error` | 34 欄組裝；非法值 → `VALIDATION` |
| `tests/Integration/Logistics/KerrytjLogisticsProviderTest.php` | `happy` | create（配號 + API）、print PDF、query（常溫/低溫分流）、return |
| 同上 | `edge` | 已建單冪等；API 失敗號碼不回收 |
| `tests/Integration/Logistics/KerrytjLogisticsRegisterTest.php` | `smoke` | 註冊 + meta |

---

### P8：E2E 錄影驗收（預估 10 小時 + 人工協作時間，複雜度：中，風險：高——外部環境）

依賴：P1–P7 合併至分支。

**基礎設定**
1. `tests/e2e/playwright.config.ts` **新增** project `live-acceptance`：`testMatch: ['04-live/**/*.spec.ts']`、`use.video: 'on'`、`use.trace: 'on'`、`timeout: 180_000`、`headless: false`（memory：headless 易被 WAF 擋）；既有 `api-tests` project 加 `testIgnore: ['04-live/**']`，避免 CI 誤跑 live。
2. `tests/e2e/helpers/live-env.ts`：從 `process.env` 讀憑證（**不得 hardcode**）；缺值 → `test.skip(true, '缺 XXX 憑證')`。
3. `tests/e2e/helpers/live-setup.ts`：用 REST / wp-cli 寫入 provider 設定（test 模式）、確保 `woocommerce_currency=TWD`、建立測試商品（**避開** memory 提到的商品 72 鬼影贈品）。
4. 站點：優先 `https://local-turbo.powerhouse.tw`（需 tunnel 在線，webhook/APN 才打得到）；tunnel 離線時改 `turbo.local` + Playwright `route` 改寫網域（memory `local-frontend-verification.md`），此時 server-to-server 回呼收不到 → 以「後台查詢補單」完成狀態轉換並在報告註明。
5. 憑證注入：執行者在本機 shell 以 `set -a; source <路徑>; set +a` 載入（路徑見下表），**不複製進 repo**。

**各 provider live 驗收標準**

| Provider | 憑證位置（只列路徑） | 完整驗收（錄影必備畫面） | 降級標準（無法完整時） | 已知阻塞 |
|---|---|---|---|---|
| SLP 嵌入式 | power-checkout `.env` 的 `SHOPLINE_SANDBOX_*`（含 `SHOPLINE_SANDBOX_CLIENT_KEY`）；測試卡與金額規則見 skill `shopline-payments-v1/references/sandbox-resources.md` | `04-live/slp-embedded.spec.ts`：結帳選 SLP 嵌入式 → order-received 顯示 SDK 卡片表單 → 輸入測試卡（`keyboard.type` 繞 masking，memory）→ 3DS（若有）→ 導回 → 後台訂單 processing + transaction id；再做部分退款成功 | — | tunnel 離線時 webhook 不到 → 以 ReturnSync 查詢補單仍須成功 |
| 黑貓PAY | `C:\Users\User\DEV\zenbu\features\.tmp\[金流] 黑貓 PAY\.env.local`（**禁用**同資料夾 `.env.production`） | `04-live/tcat-pay.spec.ts`：(a) COCS：結帳 → 刷卡短網址頁 → 測試卡授權 → 導回 → processing；(b) CVS：取得 ibon 代碼並顯示於 order-received | 若查不到測試卡號：(b) CVS 取號錄影 + (a) 取得短網址並進到刷卡頁截圖；狀態轉換以 mock APN 測試佐證 | 缺測試卡號（需查 skill `references/` 或向客樂得索取） |
| LINE Pay | `C:\Users\User\DEV\zenbu\features\.tmp\[金流] LINE Pay\.env.local` | `04-live/linepay.spec.ts`：結帳 → 取得 `paymentUrl` 並導到 LINE Pay 頁（錄影）；**人工協作段**：測試在 LINE 登入頁 `page.pause()`，由用戶手動登入付款 → 腳本續跑驗證 confirm → processing | **降級（預設）**：request 成功、拿到 `paymentUrl`、LINE Pay 頁面載入的錄影 + confirm/1172/reconcile 的 mock 測試全綠 | sandbox 付款頁需 LINE 帳號登入，無法全自動 |
| 黑貓宅急便 | `C:\Users\User\DEV\zenbu\features\.tmp\[物流]黑貓宅急便_自有系統串接_標準印單API參考文件_v2.0.0\.env.local`（`_TEST` 組） | `04-live/tcat-logistics.spec.ts`：後台訂單 → 建立託運單 → 取得託運單號 → 下載 PDF（錄影顯示 PDF）→ 查詢貨態（顯示狀態或限流快取） | 測試站不回貨態 → 建單 + PDF 即算通過 | OBTStatus 限流 |
| 新竹物流 | `C:\Users\User\DEV\zenbu\features\.tmp\[物流]新竹物流\.env.local` + skill 公開測試帳號（company `test`） | `04-live/hct.spec.ts`：建單取得 edelno + 標籤圖顯示 → 取消 | **號段缺**：只錄 `QueryEDELNO` / 地址比對等唯讀操作 + 建單 mock 全綠；註明「待新竹核發測試號段」 | 託運單號段缺 |
| 順豐 | 只有官方公開唯讀憑證（skill `sf-express/references/onboarding-and-faq.md` §8） | — | **降級（預設）**：`04-live/sf-express-readonly.spec.ts` 以公開憑證打唯讀接口（如運費試算 / 路由查詢）成功，錄影顯示後台「測試連線」結果；建單 / 列印 / 推送 mock 全綠 | 無可建單憑證；TW→TW 可否下單待確認 |
| 嘉里大榮 | 無 | — | **降級（預設）**：mock 全綠 + 後台設定頁錄影（可存設定、號段顯示）；`04-live` 不建立 spec | 無任何憑證 |

**後台「測試連線」按鈕**：為支援降級驗收，每個新物流設定頁加「測試連線」（REST `POST power-checkout/v1/logistics/{provider}/ping`，admin nonce），呼叫一支唯讀 API（tcat=`ParsingAddress`、hct=`addrCompare`、sf=唯讀查詢、kerrytj=無 → 回 `UNSUPPORTED`）。此按鈕在 P4–P7 各自實作（列入各 Phase 檔案），P8 只負責錄影。

**產出**：影片在 `tests/e2e/test-results/**/video.webm`；P8 結束時整理到 `tests/e2e/acceptance-videos/2026-11-<日>/`（**加入 `.gitignore`**，不 commit 影片）；不另寫驗收報告檔，驗收結果與影片路徑清單寫進 PR 描述。

---

### P9：文件同步（預估 4 小時，複雜度：低，風險：低）

依賴：P0–P8。交由 `@zenbu-powers:doc-updater`。

| 檔案 | 更新內容 |
|---|---|
| `.claude/CLAUDE.md` | Project Overview 加 7 家；Backend Domain Structure 加新目錄；新增「Unified Provider Interface」章（ProviderMeta、capabilities、WebhookPipeline、dispatch REST）；各新 provider Flow 章；REST API 表加 `/pay/{order_id}/dispatch`、`/tcat-pay/apn`、`/linepay/confirm|cancel`、`/sf-express/track-push`、`/logistics/providers/meta`、`/logistics/{provider}/ping`；Order Meta Keys 表加所有新 meta；Key WordPress Hooks 加 `pc_linepay_reconcile` |
| `.claude/rules/provider-guide.rule.md` | 新增 provider 必須實作 `get_provider_meta()`；webhook 型新金流必須走 `handle_webhook()`；嵌入式必須實作 `IEmbeddedPaymentProvider`；號段型物流用 `WaybillNumberAllocator` |
| `.claude/rules/react-blocks.rule.md` | blocks 清單加 3 個 tsx |
| `.claude/rules/vue-frontend.rule.md` | router 表加 7 條；external 加 `ShoplineEmbedded` |
| `.claude/rules/testing.rule.md` | 新增 `live-acceptance` project 與 `04-live/` 說明、憑證載入方式 |
| memory | 新增 `unified-gateway-providers-status.md`（各家狀態 + GAP） |

---

## 測試策略

- **單元 / 純邏輯**：簽章與加解密 golden vector（`TcatPayChecksumTest`、`LinepayDirectSignatureTest`、`SfCryptoTest`、`HctTrackCryptoTest`、`KerrytjBlnTest`）、狀態映射、DTO 驗證。
- **整合（WP_UnitTestCase，`API_MODE=mock`）**：每個 gateway / provider 的完整流程（建單→callback→狀態→退款 / 建單→查詢→列印→取消）、REST 路由、冪等、安全。
- **契約測試**：`ProviderMetaContractTest`、`LogisticsProviderMetaContractTest` 以 data provider 涵蓋**所有** gateway / provider（新增 provider 時把它加進 matrix 是該 Phase 的必要步驟）。
- **E2E**：P8 `live-acceptance` project（錄影）；既有 `api-tests` project 必須仍可跑且不包含 live spec。
- **測試執行指令**：見「共通規範」。
- **關鍵邊界情況**：雙擊付款、3DS 中途離開、過期 tab、confirmUrl 重整、19 位 transactionId、偽造 APN / 推送、重複繳款金額不符、號段用罄與同時配號、查詢限流、非 TWD 幣別、token 過期、API 逾時、回應缺必要欄位。

## 依賴項目

- PHP 8.1+、OpenSSL（AES / DES-CBC；DES 在 OpenSSL 3 可能需 legacy provider → P5 R9 處理）、GD（新竹標籤圖）
- WooCommerce Action Scheduler（LINE Pay 補單；WC 內建）
- SHOPLINE Payments Web SDK（前端；版本照 skill `embedded-sdk.md`，鎖精確版本）
- Playwright（`tests/e2e/` 既有）
- 外部 sandbox：SLP、黑貓PAY、LINE Pay、黑貓印單 API 測試站、新竹 EDI DEV、順豐公開沙箱
- 測試站 tunnel `local-turbo.powerhouse.tw`（可能離線）

## 風險與緩解措施

| 等級 | 風險 | 緩解 |
|---|---|---|
| 高 | 範圍過大（~150 檔）導致長時間無法合併 | 每 Phase 獨立綠燈 commit + `.progress.json`；P0 先合，金流 P1–P3 與物流 P4–P7 互不依賴（除 P7 依賴 P5 allocator） |
| 高 | 修改 `OrderResolver::is_slp_order()` 影響既有 SLP 導轉 | 只改成白名單；既有 `SlpOrderResolverTest`、`SlpWebhookResponseTest`、`SlpReturnSyncTest` 為回歸守門 |
| 高 | 黑貓PAY APN 可偽造 | 平台查詢為唯一事實來源（R1） |
| 高 | 外部 sandbox / tunnel 不穩，P8 卡住 | 每家都有降級標準；P8 不阻擋 P9 |
| 中 | LINE Pay 補單任務在 WP-cron 不觸發的站點不執行 | 後台 order action 手動查詢補單作後援；文件說明需真 cron |
| 中 | 新竹 SOAP namespace 錯導致全部失敗 | P5 Red 前先 `curl {asmx}?wsdl` 確認並寫成 fixture |
| 中 | 物流 MetaBox 依 capability 顯示按鈕改到既有元件 | 條件只在 `meta` 存在時生效；既有物流 E2E `logistics-*.spec.ts` 回歸 |
| 中 | `AbstractPaymentGateway` 新增 abstract-ish 方法讓第三方子類壞掉 | 全部給預設實作，不新增 abstract 方法 |
| 低 | PHPStan level 9 對 `mixed` 回應陣列報錯 | 回應一律經 DTO / `array{...}` shape；沿用既有 `phpstan.neon` ignore |
| 低 | PHPCS 記憶體不足 | 已有 `ad37387` 排除 `.tmp/release/.claude` |

## 錯誤處理策略

- 金流：沿用既有「正規化錯誤模型」（`Shared/Errors/ErrorCode` + `NormalizedError::from()`）；`process_refund` never-throw；gateway refund 失敗一律 `fail_gateway_refund()`。
- Callback：新金流統一走 `WebhookPipeline`（永遠 ack，僅黑貓PAY 平台查詢逾時刻意回非 OK 以利重送）；物流推送（順豐）比照既有物流 callback 永遠 200。
- 物流操作：失敗一律 throw，由 `LogisticsApiService` 映射 HTTP code（400 VALIDATION / 401 AUTH / 404 NOT_FOUND / 409 CONFLICT·NUMBER_EXHAUSTED / 501 UNSUPPORTED / 502 NETWORK / 500 UNKNOWN）。
- 前端：不暴露內部錯誤，顯示通用訊息；raw code 只進 order note 與 log。
- 未知不確定處以 ASM 標記，執行者遇 ASM 被推翻時停下回報。

## 限制條件（本計劃不會做的事）

- 不重寫既有 7 個 gateway；不動 `final process_payment()` / `before_page_render()`；既有 gateway 只加 `get_provider_meta()`。
- 不把既有 gateway 的 webhook 遷移到 `WebhookPipeline`（列為後續工作）。
- 不把 `capture` / `void_auth` / `query_trade` 預設改成拋 `UNSUPPORTED`。
- 不修 pre-existing 問題（ezpay edge test、`RedirectSettingsDTO:148`、20 個 DTO `required_properties` 拼錯）。
- 不做：黑貓PAY 續期收款、黑貓 B2S 快速到店 / 電子地圖、新物流背景輪詢、SLP 嵌入式非信用卡付款方式、LINE Pay 預先授權 / 定期定額、順豐清關 / IECS 電商件。
- 不 commit 任何憑證值與影片檔；不使用 `.env.production`。
- 不在 `inc/tests/`（legacy）新增測試。

## 成功標準

- [ ] 分支 `feat/unified-gateway-providers` 從 master 開出，P0–P9 各有綠燈 commit 與 `.progress.json` 記錄
- [ ] `IPaymentProvider` / `ILogisticsProvider` 皆有 `get_provider_meta()`；既有 7 gateway + 3 物流宣告正確且行為零回歸
- [ ] `WebhookPipeline` 與 `/pay/{order_id}/dispatch` 上線，tcat_pay / linepay 使用外框、SLP 嵌入式使用 dispatch
- [ ] 7 家新 provider 各自完成：後端 + Settings DTO + ProviderRegister + Vue 設定頁 + router + `ROUTER_MAPPER` +（金流）blocks tsx /（物流）WC_Shipping_Method
- [ ] 全套 `API_MODE=mock` 綠燈（唯一允許的失敗為 pre-existing ezpay edge）；PHPStan L9 full tree 無新 error；PHPCS 0 error；`pnpm build` / `pnpm build:blocks` / `pnpm lint` 通過
- [ ] Live 錄影：SLP 嵌入式成功付款 + 退款、黑貓PAY 成功付款（或降級：CVS 取號 + 刷卡頁）、黑貓宅急便建單 + PDF；LINE Pay / 新竹 / 順豐 / 嘉里大榮依降級標準完成並在 PR 註明阻塞原因
- [ ] P9 文件同步完成

## 預估時間與複雜度

| Phase | 內容 | 預估 | 複雜度 | 風險 | 可平行? |
|---|---|---|---|---|---|
| 第零步 | 分支 + 進度檔 | 0.5 小時 | 低 | 低 | — |
| P0 | 統一介面 | 8 小時 | 中 | 中 | 否（全部依賴） |
| P1 | SLP 嵌入式 | 16 小時 | 高 | 高 | 與 P2–P7 可平行（但 phpunit 不可同時跑，R14） |
| P2 | 黑貓PAY | 14 小時 | 高 | 高 | 同上 |
| P3 | LINE Pay | 12 小時 | 中 | 中 | 同上 |
| P4 | 黑貓宅急便 | 12 小時 | 中 | 中 | 同上 |
| P5 | 新竹物流（含 allocator） | 14 小時 | 高 | 高 | 同上 |
| P6 | 順豐 | 14 小時 | 高 | 中 | 同上 |
| P7 | 嘉里大榮 | 10 小時 | 中 | 中 | 依賴 P5 allocator |
| P8 | E2E 錄影驗收 | 10 小時 + 人工協作約 1 小時（LINE Pay 登入） | 中 | 高 | 依賴 P1–P7 |
| P9 | 文件同步 | 4 小時 | 低 | 低 | 依賴 P8 |
| **合計** | | **約 114.5 工作小時（≈ 15 個 8 小時工作天）** | **高** | | |

## 預估複雜度：高

---

## WHERE NEXT

本計劃交接 **@zenbu-powers:tdd-coordinator**：

1. 先執行「第零步」開 `feat/unified-gateway-providers`，建立 `specs/plan/.progress.json`。
2. 依序 P0 → P1…P7（P0 完成後金流 / 物流 Phase 可交錯，但同一時間只允許一個 phpunit 進程），每 Phase Red→Green→Refactor 並過完成標準 5 項後 commit。
3. 執行前請先確認 ASM-1～ASM-9 無異議；任何 ASM 被推翻或某 Phase 檔案數超估 50% → 停下回報 orchestrator。
4. P8 需用戶在 LINE Pay 登入段人工協作；其餘按降級標準進行。
5. P9 交 `@zenbu-powers:doc-updater`。
