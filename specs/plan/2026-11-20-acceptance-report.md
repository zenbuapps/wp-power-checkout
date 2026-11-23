# P8 錄影驗收報告（2026-11-21）

> 藍圖：`specs/plan/2026-11-20-tdd-blueprint.md` §7；分支 `feat/unified-gateway-providers`
> 執行：`cd tests/e2e && LIVE_ENV_FILES="<本機 env 檔>" npx playwright test --project=live-acceptance 04-live/<spec>`（一次一支）
> 影片目錄（已 gitignore，不入版控）：`C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-checkout\tests\e2e\acceptance-videos\2026-11-21\`

## 0. 環境

| 項目 | 結果 |
|---|---|
| 站點 | `https://local-turbo.powerhouse.tw`（LocalWP turbo.local 經 Cloudflare tunnel）。驗收開始時 tunnel 離線（curl 000）；以使用者既有 tunnel 憑證在 scratchpad 起一個**只含 local-turbo ingress** 的 connector 後上線，webhook / 推送可達 |
| 外掛 | `power-checkout/plugin.php` 已啟用、程式碼即本分支（站點 plugins 目錄 = repo）；`pnpm build` / `pnpm build:blocks` 已重建 |
| 幣別 | `woocommerce_currency=TWD`、`woocommerce_default_country=TW`；classic 結帳（shortcode） |
| 瀏覽器 | 本機 Google Chrome（channel `chrome`）headed、`video:on`、`trace:on` |
| 測試商品 | 固定 SKU `pc-live-*`（REST 冪等建立，避開商品 72） |
| 驗收後還原 | `woocommerce_shopline_payment_redirect_settings` 由備份還原；本次啟用的 7 家 provider 全部改回 `enabled=no`；新竹環境改回測試站 |

## 1. 結果總表

| Provider | 等級 | 結果 | 影片 |
|---|---|---|---|
| SHOPLINE Payments 嵌入式 | 完整（目標） | **未通過：外部阻塞**（SLP 沙盒 PCI API 1005）；PC 端 dispatch / 建交易正常；重付被 SLP 拒絕問題**已修並 live 重驗通過**（f666352，見 §2.3 / §12） | `shopline_payment_embedded-blocked.webm`、`shopline_payment_embedded-retry.webm`（修正前）、`shopline_payment_embedded-retry-fixed.webm`（修正後） |
| LINE Pay 直連 | 降級 | 通過 | `linepay-degraded.webm` |
| 順豐速運 | **完整**（SIT） | 通過：測試連線 → SIT 建單 → PDF 面單 → 查詢 → 簽章推送更新貨態 | `sf_express-full.webm` |
| 新竹物流 | 降級（唯讀） | 通過 | `hct_logistics-degraded.webm` |
| 黑貓PAY | 降級 | 通過（失敗訊息正確） | `tcat_pay-degraded.webm` |
| 黑貓宅急便 | 降級 | 通過（修 1 bug 後重錄） | `tcat_logistics-degraded.webm` |
| 嘉里大榮 | 降級 | 通過（修 1 bug 後重錄） | `kerrytj_logistics-degraded.webm` |

## 2. SHOPLINE Payments 嵌入式（`shopline_payment_embedded`）

- 影片：`...\acceptance-videos\2026-11-21\shopline_payment_embedded-blocked.webm`、`...\shopline_payment_embedded-retry.webm`（重付修正前）、`...\shopline_payment_embedded-retry-fixed.webm`（重付修正後）
- 等級：目標完整；**未通過（外部阻塞，非本外掛）**。spec 以 `test.skip` 標記並附原因。
- 憑證：repo `.env` `SHOPLINE_SANDBOX_*`（merchant 3252264968486264832）。站上原 apiKey 已失效（SLP 回 1003），驗收期間改用 .env 值，結束後還原。

### 2.1 步驟與結果（訂單 #6654，NT$401：沙盒規則非 3 倍數 + 單數 = 成功）
1. 訪客 classic 結帳選「SHOPLINE Payments 信用卡（站內付）」→ 導向 order-received ✅
2. `POST /pay/6654/dispatch`（init）200 → 載入官方 SDK `payment-web.js`（v1.6.1）→ 收銀台渲染（分期選單 + 卡號 / 持卡人 / 有效期 / 安全碼 4 個 PCI iframe）✅
3. 以 `keyboard.type` 真鍵擊輸入官方沙盒卡 `4147633700198405 / 03/30 / 638` ✅
4. 確認付款 → `createPayment()` 取得 paySession → dispatch confirm **HTTP 200 + next_action** → 後端 `trade/payment/create` 成功（order note「交易已建立，等待顧客完成付款（tradeOrderId: 10012611207799312038194578837）」）✅
5. `payment.pay(nextAction)` → SDK 內部呼叫 SLP PCI `POST payment-pci-api-sandbox.myshoplinestg.com/pci/biz/sdk/v1/token/generate` → **400 `{"code":"1005","msg":"No interface access permission"}`** ❌（卡號輸入時的 `bin/query` 也同樣 1005）
6. 交易停在 `CREATED`（以 `trade/payment/get` 查證），無法進入 processing / 退款。

### 2.2 阻塞定性（證據）
- PCI 請求由 SLP 自家 iframe（`cashiers-sandbox.myshoplinestg.com`）送出，header `PCI-API-KEY` 為 SDK 內建值，與本站設定無關。
- 獨立重現：用 Playwright `route` 起一個**完全不含 WordPress / Power Checkout 的最小 HTML**（只載官方 SDK + 同一組 clientKey / merchantId），輸入 Visa 與 MasterCard 沙盒卡，`bin/query` 一樣回 1005 → **SLP 沙盒 PCI 端拒絕**，非外掛 bug。
- 待辦：向 SHOPLINE Payments 確認沙盒商店 3252264968486264832 的 SDK / PCI 權限（或換一組有 SDK 權限的沙盒帳號），解除後重跑 `04-live/slp-embedded.spec.ts` 即可走完 processing + 部分退款（spec 已寫好，refund 段用 WC 原生部分退款 NT$100）。
- Webhook：tunnel 在線，但沙盒商店的 Webhook URL 設在 SLP 後台（共用公開沙盒帳號，未擅改）→ 本次付款未完成，兩條路徑（webhook / order-received 主動查詢）皆未觸發認列。

### 2.2.1 D1：改用 zenbu-site-2.0 的 SLP 沙盒憑證重驗（2026-11-21）— **仍 1005，兩組皆同**
- 來源：zenbu-site-2.0 本機 Postgres（docker `zenbu-site-20-postgres-1`）`settings` 表的 `payment.shopline_{merchant_id,client_key,api_key,sign_key,mode}`，唯讀查詢；值只在記憶體比對，未輸出、未落地、未修改 zenbu DB。`mode = sandbox`。
- 比對結果（只比相等，不顯示值）：zenbu 的 merchantId / clientKey / apiKey / signKey 與本 repo `.env` 的 `SHOPLINE_SANDBOX_*` **完全相同**——也就是 P8 跑 `slp-embedded.spec.ts` 用的同一組；只有另一把 `SLP_API_KEY`（已知回 1003）不同。zenbu 並沒有另一組「能過 PCI」的憑證。
- 最小 HTML 重驗（只載官方 CDN SDK，不含 WordPress，分別從兩個 origin 載入）：`ShoplinePayments()` 初始化 `ok`、`createPayment()` 回 `paySession`，但輸入沙盒卡 `4147 6337 …` 時 PCI `bin/query` 一律 **400 `{"code":"1005","msg":"No interface access permission"}`**（與 §2.2 相同；`pay()` 的 `token/generate` 在 P8 已證實同樣 1005）。
- 結論：**兩組憑證（P8 / zenbu）皆 1005** → 確證為 SHOPLINE 沙盒商店的 SDK / PCI 介面權限問題，非外掛 bug；zenbu commit `e2fbc9e` 訊息裡的「沙盒 4463 拒刷」只是錯誤提示的舉例，不是 zenbu 通過 PCI 的證據。
- 依計劃停在步驟 2：未起 tunnel、未改站點設定、未建訂單、無影片；成功付款 → processing → 部分退款仍待 SHOPLINE 開通權限後重跑 `04-live/slp-embedded.spec.ts`。

### 2.3 重付同一訂單（referenceOrderId 重複）— **已修（f666352），live 重驗通過**
- 訂單 #6664（NT$300）：第一次付款同 2.1 卡在 PCI 1005；等 35 秒（本站 confirm 防雙擊鎖 30 秒）後同一訂單第二次付款：
  - dispatch confirm **HTTP 500 `UNKNOWN`「付款處理失敗，請稍後再試或聯繫商家」**
  - order note：`/trade/payment/create 請求失敗 code 1001 msg 重複下單，交易已存在`
  - `_pc_payment_identity` 仍為第一次的 tradeOrderId
- 結論：**SLP 拒絕同一 `referenceOrderId` 再次建交易**；嵌入式以 WC 訂單 ID 當 referenceOrderId，第一次付款失敗（卡被拒、3DS 放棄 / 失敗）後，該訂單在嵌入式**永遠無法重付**（與綠界 / 藍新「編號永久唯一」同類問題）。
- P8 當下未修（需團隊決定 referenceOrderId 策略）；其後已於 `f666352` 修正，以下為修正內容與 live 重驗。
- **修正（`f666352`）**：每次 confirm 產生新 referenceOrderId `{order_id}-{attempt}-{6 hex}`（`EmbeddedReference`），送 API 前先寫入訂單 meta `_pc_slp_reference_history`；新格式只有「格式內訂單 ID = 該訂單」**且**「在該訂單歷史內」才判定歸屬（`OrderResolver` / `EmbeddedSyncManager` / `ReturnSyncManager` 同步調整）；SLP 1001 / 1006 改回 409 可讀訊息（不再 500）；已付款訂單 gateway 層 409。
- **live 重驗（2026-11-21，訂單 #6666，NT$300）**：`04-live/slp-embedded.spec.ts`「付款未完成後同一訂單重付」**1 passed**（3.5m），log `.tmp/gate/P8-live-slp-retry-fixed.log`

| 項目 | 第 1 次 | 第 2 次 |
|---|---|---|
| dispatch confirm（本站建立交易） | **HTTP 200** + `next_action` | **HTTP 200** + `next_action` |
| 送出的 referenceOrderId | `6666-1-C682E7` | `6666-2-D28929` |
| SLP tradeOrderId（`_pc_payment_identity`） | `10012611207799387699949077922` | `10012611207799388502118106532` |
| `payment.pay()` | PCI 400 `1005 No interface access permission`（外部，預期） | 同左（預期） |

- `_pc_slp_reference_history` = `["6666-1-C682E7","6666-2-D28929"]`（兩筆、互不相同、格式符合 `{order_id}-{attempt}-{hex6}`）
- order notes：兩筆「交易已建立，等待顧客完成付款（tradeOrderId …）」，**無** `1001 重複下單`；前台全程無任何 5xx（spec 監聽站內 response ≥ 500 = 0 筆）
- 影片：`...\acceptance-videos\2026-11-21\shopline_payment_embedded-retry-fixed.webm`
- 前一次試跑（訂單 #6665，`6665-1-7FA0F2` → `6665-2-25DA7C`，兩次皆 HTTP 200）結果相同，僅因 spec 斷言 `/1001/` 誤配到 tradeOrderId 開頭 `1001…` 而判失敗；斷言收斂為 `code 1001` / 「重複下單」後重跑通過。
- 環境：同 §0（scratchpad 起只含 local-turbo ingress 的 tunnel connector，驗收後關閉）；`woocommerce_shopline_payment_redirect_settings` / `woocommerce_shopline_payment_embedded_settings` 驗收前以 WP-CLI 備份、驗收後還原並逐鍵比對一致（embedded 回到 `enabled=no`）。
- 另觀察：pay() 在前端失敗後 30 秒內重按會得到 409「付款處理中，請勿重複送出」（防雙擊鎖仍在），為既有設計。

## 3. LINE Pay 直連（`linepay`）

- 影片：`...\linepay-degraded.webm`　等級：降級（預設）　結果：**通過**
- 憑證：`LIVE_ENV_FILES` → `[金流] LINE Pay\.env.local`（`LINEPAY_SANDBOX_CHANNEL_ID` / `_SECRET`）
- 步驟：訪客結帳 NT$150 選「LINE Pay（直連）」→ 後端 `POST /v4/payments/request` 成功 → 導向 `https://sandbox-web-pay.line.me/web/payment/waitPreLogin` 並載入完成。
- 畫面文字：「LINE Pay Simulation 您可使用 LINE 帳號開始進行測試。… LINE Pay 登入您的LINE帳號或掃描行動條碼…」
- 訂單 #6660 note：「LINE Pay 已建立付款請求，orderId PCL6660-1-D77D28，transactionId 2026112102400814010，金額 150 元」（狀態 pending，等待人工付款 / 對帳排程）
- 人工段：`LIVE_LINEPAY_MANUAL=1` 時於付款頁 `page.pause()`（本次未執行，需真人 LINE 帳號）。

## 4. 順豐速運（`sf_express`）

- 影片：`...\sf_express-full.webm`　等級：**完整（公開沙箱 SIT）**　結果：**通過**
- 憑證：官方公開「測試環境授權信息」（skill `sf-express/references/onboarding-and-faq.md` §8），執行時解析，未寫入 repo；推送金鑰每次隨機產生。
- 步驟與結果（訂單 #6663；首輪 #6658 / SF3180007862088 亦建單成功，因 spec 斷言 unicode 比對問題重錄）：
  1. 設定頁「測試連線」→ 200「連線成功（沙箱）」，`customer_code_verified:false`、`push_key_configured:true`
  2. `POST /logistics/6663/create-shipment` → 200「物流單成立成功」，運單 **SF3180007862096**
  3. `POST /logistics/6663/print` → 200 `application/pdf` 145,401 bytes，Chrome PDF viewer 顯示面單
  4. `GET /logistics/6663` → 200，「尚無軌跡（該運單暫時沒有記錄產生…）」、`unified_state: pending`
  5. 軌跡推送 `POST /wp-json/power-checkout/sf-express/track-push`：錯誤 sign → `{"code":"1","msg":"簽章驗證失敗"}`；正確 sign（Base64(MD5(key+body+key))，opCode 50）→ `{"code":"0","msg":"成功",...}` → 訂單 meta `unified_state=accepted`、`status_name=已收件`，訂單頁 order note 顯示
- 備註：SIT 建出的 2 張運單未取消（推送後狀態已非 pending，本地規則不允許取消；SIT 非正式系統）。

## 5. 新竹物流（`hct_logistics`）

- 影片：`...\hct_logistics-degraded.webm`　等級：降級（唯讀）　結果：**通過**
- 憑證：`LIVE_ENV_FILES` → `[物流]新竹物流\.env.local`（官方文件測試帳號，實測屬正式環境）。**未建單 / 取消 / 退貨**。
- 步驟：
  1. 設定頁填公司名稱 / 密碼（測試站）→ 儲存 → 測試連線：**424** AUTH「新竹物流 QueryEDELNO 失敗：「測試環境」請勿使用正式公司名稱或密碼（此帳密屬正式環境，請切換為正式站或向新竹申請測試帳號）」，畫面顯示錯誤通知
  2. 切正式站 → 儲存 → 測試連線（QueryEDELNO 查不存在的訂單編號，唯讀）→ 200，畫面「連線成功（正式站）：公司名稱與密碼驗證通過，尚未設定貨況查詢」
  3. 結束後設定改回測試站
- 阻塞：無測試站帳號 / 測試號段，待新竹核發。

## 6. 黑貓PAY（`tcat_pay`）

- 影片：`...\tcat_pay-degraded.webm`　等級：降級　結果：**通過**
- 憑證：.env.local 為 `<...>` 佔位 → 以明顯假值填寫。設定頁沒有「測試連線」，改以「實際結帳一次」當連線測試。
- 步驟：設定頁填金流代號 / API 密碼 / hash_base → 儲存成功 → 訪客結帳選黑貓PAY（信用卡）→ 結帳頁錯誤「處理結帳時發生錯誤，請查閱 黑貓PAY 的 log 紀錄了解詳情」，未導向付款頁；訂單 #6662 note「黑貓PAY token 取得失敗：使用者名稱或密碼不正確。」（顧客端不洩漏內部錯誤，後台可診斷）
- 阻塞：無黑貓PAY 測試帳號。

## 7. 黑貓宅急便（`tcat_logistics`）

- 影片：`...\tcat_logistics-degraded.webm`（修 bug 後重錄）　等級：降級　結果：**通過**
- 步驟：設定頁填佔位契客代號 / 授權碼 + 寄件人 → 儲存 → 測試連線 → 200 `credentials_verified:false`；畫面「連線成功（測試站）：台北市信義區信義路五段7號 → 郵號 11-145-52-X（寄件郵號 14552X）」＋ 新提示「地址查詢（ParsingAddress）不驗證契客代號與授權碼，以上結果只代表已連上黑貓；授權碼是否正確請以實際建單確認。」
- **修 bug（324d5e8）**：首錄時佔位憑證也顯示「連線成功」→ ParsingAddress 實測不驗證授權碼，原畫面會讓管理員誤以為憑證正確。
- 阻塞：無測試授權碼，無法建單 / 列印 PDF。

## 8. 嘉里大榮（`kerrytj_logistics`）

- 影片：`...\kerrytj_logistics-degraded.webm`（修 bug 後重錄）　等級：降級　結果：**通過**
- 步驟：
  1. 設定頁填佔位帳密、客戶編號、寄件人
  2. 號段驗證：常溫 `123/456` →「常溫號段起訖須同時填寫 11 碼數字」；低溫檢查碼錯 `1234567890` →「低溫號段起訖須同時填寫 9 碼流水號或檢查碼正確的 10 碼單號」；起 > 迄 →「起號不可大於迄號」
  3. 改為合法號段（常溫 90000000001–90000000100、低溫 800000001–8000001004）→ 儲存成功
  4. 重新進入：「常溫號段狀態 剩餘 100 號，下一號 90000000001」「低溫號段狀態 剩餘 100 號，下一號 8000000013」
  5. 測試連線 → **424** `{"error_code":"AUTH","raw_code":"401","message":"嘉里大榮拒絕連線（測試連線）：帳號或密碼錯誤…"}`，畫面通知「金流 / 發票憑證錯誤，請至設定頁確認金鑰」
- **修 bug（5fd963c）**：首錄時 ping 回 502，Cloudflare 把 origin 502 本文換成自家錯誤頁 → 畫面只剩「發生未預期的錯誤，請稍後再試」。
- 觀察（未修）：前端 AUTH 友善訊息文案為「金流 / 發票憑證錯誤」，用在物流設定頁不精確（`js/src/utils/error-code` 對照表共用）。

## 9. 修正的產品 bug

| Commit | 類型 | 內容 |
|---|---|---|
| `1f23127` | test（Red） | `LogisticsPingStatusCodeTest`：ping 失敗不回 5xx（2 tests / 2 failures） |
| `5fd963c` | fix(logistics) | `LogisticsPingApiService` provider 端失敗 502 → **424**（VALIDATION 仍 422），避免 CDN 改寫本文 |
| `1ec8767` | test（Red） | `TcatLogisticsPingCredentialsTest`：ping 回應須帶 `credentials_verified=false`（1 test / 1 failure） |
| `324d5e8` | fix(tcat-logistics) | 回應加 `credentials_verified:false`、設定頁加提示 |

另：`00369cc` test(e2e) 新增 live-acceptance project 與 7 家 spec。

## 10. Mock 測試（API_MODE=mock，wp-env tests-cli）

| Provider | Filter | 結果 |
|---|---|---|
| SLP 嵌入式 | `SlpEmbedded\|SlpOrderResolver\|SlpWebhookResponse\|SlpReturnSync\|SlpSessionIdentity` | OK (84 tests, 295 assertions) |
| LINE Pay | `LinepayDirect` | OK (64 tests, 259 assertions) |
| 黑貓PAY | `TcatPay` | OK (71 tests, 318 assertions) |
| 黑貓宅急便 | `TcatLogistics\|WC_TcatLogisticsShipping` | OK (111 tests, 269 assertions)（修正後 `TcatLogistics\|Ping` OK 190 tests） |
| 新竹 | `Hct` | OK (103 tests, 281 assertions) |
| 順豐 | `Sf(Crypto\|AccessTokenStore\|Express\|TrackPush\|Region)` | OK (130 tests, 415 assertions) |
| 嘉里大榮 | `Kerrytj` | OK (114 tests, 422 assertions) |
| 全套回歸 | — | Tests: 3055, Assertions: 10126, Failures: 4 = 基線 4 支（EzpayAesCrypto edge + LinePayTest ×3） |
| PHPStan | full tree | 只剩基線 `RedirectSettingsDTO.php:148 isset.property` |

log：`.tmp/gate/P8-mock-*.log`、`P8-full.log`、`P8-phpstan.log`、`P8-live-*.log`（untracked）。

## 11. 未解決問題

1. **SLP 沙盒 PCI 1005**（外部）：嵌入式無法完成付款 → processing / 退款未驗；需 SHOPLINE 開通沙盒 SDK 權限；D1 以 zenbu-site-2.0 的憑證重驗（實為同一組）仍 1005，見 §2.2.1。
2. ~~SLP 嵌入式同訂單重付被拒（1001 重複下單）~~ → **已修**（`f666352`），live 重驗通過，見 §2.3。
3. LINE Pay 人工付款段未跑（需真人 LINE sandbox 帳號，`LIVE_LINEPAY_MANUAL=1`）。
4. 黑貓PAY / 黑貓宅急便 / 嘉里大榮 / 新竹 皆缺測試憑證或號段，建單類 live 未驗。
5. ~~前端 AUTH 友善訊息「金流 / 發票憑證錯誤」用於物流設定頁文案不精確~~ → **已修**（`20a27fc`），見 §12。
6. ~~`LogisticsApiService` 其餘端點（create-shipment 等）的 NETWORK / PROVIDER 仍回 502~~ → **已修**（`aaa71a2`，改回 424），見 §12。

## 12. P8 後修正

| Commit | 類型 | 內容 | 驗證 |
|---|---|---|---|
| `e7f4f35` → `f666352` | test（Red）→ fix(slp-embedded) | 同一訂單重付改用每次嘗試唯一 referenceOrderId（`{order_id}-{attempt}-{hex6}` + `_pc_slp_reference_history` 歸屬判定）；SLP 1001 / 1006 → 409 | `SlpEmbeddedRetryTest` 等（mock）+ **live 重驗通過**（§2.3，訂單 #6666：兩次建立交易皆 HTTP 200、reference 不同、歷史兩筆、無 500） |
| `8897f4f` → `209ebf4` | test（Red）→ fix(slp) | `Requester` 寫入 WC log 前遮罩 apiKey / signKey / clientKey | `SlpRequesterLogMaskTest`（mock） |
| `7393ac5` → `20a27fc` | test（Red）→ fix(logistics-ui) | 物流 API 的 AUTH 錯誤改顯示物流憑證文案（原為「金流 / 發票憑證錯誤」，§11-5） | `tests/js/error-code.test.ts` |
| `c3bebd8` → `aaa71a2` | test（Red）→ fix(logistics) | 物流 REST 建單 / 查詢 / 列印 / 退貨 provider 失敗改回 424，不再回 502（避免 Cloudflare 改寫本文，§11-6） | `LogisticsApiServiceStatusCodeTest`（mock） |

另：`04-live/slp-embedded.spec.ts` 重付情境由「記錄結果」改為正式斷言（第 2 次 HTTP 200、`_pc_slp_reference_history` 兩筆且不同、格式、tradeOrderId 不同、無 1001、無 5xx），影片名 `shopline_payment_embedded-retry-fixed`。

## 13. P10：callback 處理對齊稽核（vs zenbu-site-2.0）

以 zenbu-site-2.0（`payments/kolet/kolet.service.ts`、`kolet-reconcile.cron.ts`、`payments/linepay/linepay.service.ts`）為參照，逐一比對 PC 各 callback 路徑。發現 2 個 medium（tcat_pay）+ 1 個 low（linepay），皆以 TDD 修正（mock）。

| Commit | 等級 | 類型 | 內容 | 驗證 |
|---|---|---|---|---|
| `215865c` | medium | fix(tcat-pay) | APN 為已收款觸發（CVS B/E；COCS B/O/E）但平台查詢**尚未**確認已付款時，原回 `OK`（平台即停止重送，同狀態只重送約 3 次）→ 改拋 `TcatPayRetryLaterException` 回 503 `RETRY`；冪等鍵仍不寫（偽造 APN 只換來一次 RETRY，不可污染） | `TcatPayApnTest`：`test_checksum正確但平台未付_不改狀態並回RETRY`（改寫）、`test_平台於重送時確認_認列並回OK`（新增）、偽造 / 節流兩支改斷言 RETRY |
| `36c307d` | medium | feat(tcat-pay) | 原無自動補單（zenbu 每分鐘 cron 補單）。新增 AS `pc_tcat_pay_reconcile`（`TcatPayReconcileJob`）：建單成功即排程；只經 `StatusManager::settle_paid()` 認列；credit 5/10/20/40/70 分鐘、CVS / ATM 每 4 小時至繳費期限 +1 天（上限 60 輪）；期滿只留 note、永不取消；查詢失敗 +15 分鐘重試、連續 5 次放棄；filter `power_checkout_tcat_pay_reconcile_delay` | `TcatPayReconcileTest`（15 支：建單排程 ×2、建單失敗不排、平台已收款認列 ×2、已結案 no-op、退避 / 期滿 / 輪次上限、金額不符不認列、查詢失敗重試上限、filter 下限） |
| `bd0360c` | low | fix(linepay) | `sum_paid()` 在回應缺 `payInfo` 時回 null，金額守衛被跳過 → 退回 `packages` 加總（同 zenbu `sumPaid`）；兩者皆無金額 → fail closed（note「無法確認金額」，不認列） | `LinepayDirectConfirmTest` 新增 4 支（packages 相符 / 不符、confirm 無金額、明細補單無金額） |

**刻意保留的差異（已接受，不改）**

1. 物流不做背景輪詢（ASM-7 決策；貨態以推播觸發 + 手動查詢為準）。
2. SLP webhook 時間戳過舊 → 回 200（zenbu 拒收）；PC 只在簽章不符回 401，其餘一律 200，避免 SLP 每 60 分鐘無限重試。
3. tcat_pay 非收款狀態通知（F 授權失敗、D 逾期、M / Q 取消…）只留 note、不改訂單狀態。
4. tcat_pay 請款失敗 `P` 只留 note（不自動處理）。
5. SLP 嵌入式不做背景補單（靠共用 webhook + order-received 主動查詢）。
6. （觀察，未改）tcat_pay APN checksum 驗章失敗：zenbu 回 401（平台會重送），PC 回 `OK`。checksum 無金鑰、驗章失敗多為偽造或格式差異，且漏接已有 P10 自動補單兜底，故維持現狀。

**Gate（mock，wp-env tests-cli）**

| Gate | 範圍 | 結果 |
|---|---|---|
| Provider filters | `TcatPay\|LinepayDirect` | OK — Tests: 155, Assertions: 645 |
| 全套回歸 | `tests/Integration/` | Tests: 3096, Assertions: 10300, Failures: 4 = 基線 4 支（EzpayAesCrypto edge + LinePayTest ×3） |
| PHPStan level 9 | full tree | 只剩基線 `RedirectSettingsDTO.php:148 isset.property` |
| PHPCS | 本次變更的 6 個 `inc/` 檔 | 0 error；`TcatPayGateway.php` 3 個 warning 為既有程式碼（非本次變更行） |

log：`.tmp/gate/P10-provider-filters.log`、`P10-full-suite.log`、`P10-phpstan.log`、`P10-phpcs.log`（untracked）。live 未驗：黑貓PAY 仍無測試憑證（§11-4），APN RETRY / 自動補單僅 mock 覆蓋。
