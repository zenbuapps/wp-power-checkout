# TDD 協調藍圖：統一金流/物流介面 + 7 家新 provider

> 建立日期：2026-11-20　｜　規劃者：tdd-coordinator　｜　執行者：主窗口（逐一 spawn 下游 agent）
> 上游計劃：`specs/plan/2026-11-20-unified-gateway-and-7-providers.md`（下稱「計劃」，已核准）
> 實作分支：`feat/unified-gateway-providers`（從 `master` 開）
> ASM-1～ASM-9：orchestrator 已裁決全部照計劃採用。範圍超 30 檔：用戶原始指令已授權，不再停。
> 本藍圖**不修改計劃內容**；計劃中與程式現況不符之處列於「§9 計劃修正點」，並在各 Phase 標明本藍圖採用的保守做法。

---

## §1 環境確認

| 項目 | 結果（2026-11-20 實測） |
|---|---|
| 執行模式 | 本地（`GITHUB_ACTIONS` 未設定）→ 本地收尾：保留分支等用戶驗收，不自動開 PR |
| specs | 有完整計劃檔；非 spec-less 模式 |
| 目前分支 | `chore/update-claude-skills`；`specs/plan/` 為 untracked（切分支時會跟著走） |
| 技術棧 | PHP 8.3（LocalWP，`php` 在 git bash / PowerShell PATH 皆可解析）、WooCommerce、PHPUnit（WP_UnitTestCase）、PHPStan L9、PHPCS、Vue 3 + Vite（`js/src`）、React WC Blocks（`inc/assets/blocks/*.tsx`）、Playwright（`tests/e2e/`） |
| 測試 DB | wp-env 實例 `d5a5492d5679ff98cc7c025012f4fdfb`，**容器目前全部 Exited**（第零步必須先起 `tests-mysql`） |
| Skill 檔 | **master 上沒有** `tcat-pay` / `linepay` / `tcat-logistics` / `hct-logistics` / `sf-express` / `kerrytj-logistics` skill，只在 `cc30605`（chore 分支）→ 第零步必須帶過來 |
| zenbu 參考碼根目錄 | `C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\commerce\`（計劃中 `payments/...`、`shipping/...` 皆相對此路徑） |
| PHPCS 全樹基線 | **420 errors / 364 warnings / 104 files**（非 0）→ Gate 改為「變更檔」判定（見 §2.3） |
| `.gitignore` | 已忽略 `.env`、`*.local`（含 `.env.local`）、`**/dist/**`；**未**忽略 `.env.production` 等 `.env.*`、`tests/e2e/acceptance-videos/`、`.tmp/` |
| e2e `.gitignore` | `tests/e2e/.gitignore` 已忽略 `test-results/`、`playwright-report/`、`.auth/` |

### 派發模式

- 純 sub-agent 鏈式委派。**所有 Phase 一律序列執行**（同一工作目錄 + 同一測試 DB；R14 並行 phpunit 會造成 InnoDB 死結假紅）。計劃「P1–P7 可平行」在本環境不採用。
- 每 Phase 交給**單一** `wordpress-master` 一次跑完 Red→Green→Refactor 並 commit。PHP、Vue 3（設定頁 / router / external TS）、Blocks `.tsx` 皆由同一位 `wordpress-master` 處理（Vue 不屬 react-master 專長；`.tsx` 為照樣板複製）。**例外**：Blocks `.tsx` 連續 2 次 `pnpm build:blocks` 失敗且 wordpress-master 無法定位 → 主窗口另派 `@zenbu-powers:react-master` 只修該 `.tsx`。
- P1 體量最大（PHP + SDK TS + Vue + tsx），允許拆成 P1-A（後端）/ P1-B（前端）兩次序列 spawn，皆為 wordpress-master。
- P9 交 `@zenbu-powers:doc-updater`。

---

## §2 全域規則（每個 Phase 都套用；派發 prompt 必須附上本節連結）

### 2.1 測試執行命令（git bash；唯一被認可的 Gate 命令）

```bash
# (0) 起測試 DB（容器 Exited 時；冪等）
cd "C:/Users/User/.wp-env/d5a5492d5679ff98cc7c025012f4fdfb" && docker compose -p d5a5492d5679ff98cc7c025012f4fdfb up -d tests-mysql

# (1) 開跑前確認沒有其他 phpunit 在跑（兩行都必須輸出 0；第二行在常駐容器沒啟動時會報錯，可忽略）
docker ps --format '{{.Names}}' | grep -c 'd5a5492d5679ff98cc7c025012f4fdfb-tests-cli-run' || true
docker exec d5a5492d5679ff98cc7c025012f4fdfb-tests-cli-1 ps aux 2>/dev/null | grep -c '[p]hpunit' || true

# (2) 單一 Phase 的新測試（目錄 + --filter；不可用非白名單 --group）
cd "C:/Users/User/.wp-env/d5a5492d5679ff98cc7c025012f4fdfb" && MSYS_NO_PATHCONV=1 docker compose -p d5a5492d5679ff98cc7c025012f4fdfb run --rm --no-deps -e API_MODE=mock -w /var/www/html/wp-content/plugins/power-checkout tests-cli vendor/bin/phpunit tests/Integration/Payment/ --filter '<FilterRegex>' --no-coverage > "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-checkout/.tmp/gate/<phase>-<step>.log" 2>&1; echo "EXIT_CODE=$?"

# (3) 全套回歸（Phase 結束必跑）
cd "C:/Users/User/.wp-env/d5a5492d5679ff98cc7c025012f4fdfb" && MSYS_NO_PATHCONV=1 docker compose -p d5a5492d5679ff98cc7c025012f4fdfb run --rm --no-deps -e API_MODE=mock -w /var/www/html/wp-content/plugins/power-checkout tests-cli vendor/bin/phpunit --no-coverage > "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-checkout/.tmp/gate/<phase>-full.log" 2>&1; echo "EXIT_CODE=$?"
grep -E "^(OK \(|Tests: )" ".tmp/gate/<phase>-full.log"; grep -E "^[0-9]+\) " ".tmp/gate/<phase>-full.log"
```

- 替代命令（容器若已常駐）：`MSYS_NO_PATHCONV=1 docker exec -e API_MODE=mock -w /var/www/html/wp-content/plugins/power-checkout d5a5492d5679ff98cc7c025012f4fdfb-tests-cli-1 vendor/bin/phpunit <path> --filter <X> --no-coverage`（**不要**用 `docker exec ... bash -c "..."`，會被安全檢查擋）。
- `vendor/bin/phpunit A.php B.php` 只吃第一個檔 → 多檔用「目錄 + `--filter 'A|B|C'`」。
- 判定用 `EXIT_CODE` 與 `grep "^OK ("` / `Tests: N, ... Failures: M`，**不要**只看 `tail`（WP DB log 會洗掉摘要）。
- 紅燈若呈 `on false` / `找不到訂單` 等隨機型錯誤 → 先查是否有並行 phpunit（命令 1），排除後再定性。
- log 目錄 `.tmp/gate/` 為 untracked，**永不 commit**。

### 2.2 PHPStan（本機直跑，不需 DB）

```bash
cd "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-checkout" && php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --error-format=raw > .tmp/gate/<phase>-phpstan.log 2>&1; echo "EXIT_CODE=$?"; cat .tmp/gate/<phase>-phpstan.log
```
- 只跑 full tree（scoped 子目錄會冒假 error）。
- 允許的唯一 error：`RedirectSettingsDTO.php:148 isset.property`（R15，不得修）。其他任何 `inc/...:line` 行 = 不通過。

### 2.3 PHPCS（變更檔判定；全樹本來就 420 errors）

```bash
cd "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-checkout"
# 新增檔：必須 0 error
NEW=$(git diff --name-only --diff-filter=A master -- '*.php'); [ -n "$NEW" ] && php -d memory_limit=2G vendor/bin/phpcs --report=summary $NEW; echo "EXIT_CODE=$?"
# 修改的既有檔：error 數不得高於 master 版本
for f in $(git diff --name-only --diff-filter=M master -- 'inc/*.php'); do
  A=$(git show master:"$f" | php vendor/bin/phpcs --report=summary --stdin-path="$f" - | grep -oE '[0-9]+ ERRORS?' | head -1)
  B=$(php vendor/bin/phpcs --report=summary "$f" | grep -oE '[0-9]+ ERRORS?' | head -1)
  echo "$f master=[$A] now=[$B]"
done
```
- 可對**本 Phase 新增/修改的檔案**用 `vendor/bin/phpcbf <files>` 自動修；**禁止**對全樹跑 `phpcbf` 或 `pnpm lint` / `pnpm lint:fix`（它們內含全樹 phpcbf，會改到上百個無關檔）。

### 2.4 前端（僅該 Phase 有前端變更時）

```bash
cd "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-checkout"
pnpm build;        echo "EXIT_CODE=$?"   # js/src 有變更
pnpm build:blocks; echo "EXIT_CODE=$?"   # inc/assets/blocks 有變更
CH=$(git diff --name-only --diff-filter=AM master -- 'js/src/**/*.ts' 'js/src/**/*.vue' 'inc/assets/blocks/*.tsx'); [ -n "$CH" ] && pnpm exec eslint $CH; echo "EXIT_CODE=$?"
```
- 計劃寫 `pnpm lint`，但其 eslint glob 只涵蓋 `js/src/*.{ts,tsx}` 頂層且會跑全樹 phpcbf → 改為上方「變更檔 eslint」。新檔 0 error；修改既有檔不得新增 error。
- 若 eslint 設定無法解析 `.vue`（parser 報錯而非規則錯），記錄於回報並以 `pnpm build` 成功作為 `.vue` 的替代 Gate。
- build 產物（`js/dist/`、`inc/assets/dist/`）已被 gitignore，不 commit。

### 2.5 基線與回歸規則

- 第零步量測 master 全套基線，寫入 `specs/plan/.progress.json` 的 `baseline`（tests 數、failures 數、失敗測試名稱）。
- 已知 pre-existing 失敗（以第零步實測為準）：`EzpayAesCryptoTest::test_edge_標準AES16blockPadding...`、`LinePayTest` 3 支（SLP 代收 LINE Pay；memory 記載自 v1.3.2 起即紅）。**不得修、不得刪、不得改名。**
- 回歸判定：全套 failures 集合 ⊆ 基線 failures 集合；tests 數只增不減。

### 2.6 Evidence over Claims（每個 Gate 的回報格式）

```
✅ <Phase>-<Gate 名稱> 通過
執行命令：
$ <完整命令>
輸出（節錄）：
<摘要行（OK (...) 或 Tests: N, Assertions: M, Failures: K）+ 失敗清單 + EXIT_CODE=...>
```
sub-agent 的回報必須含 Red、Green、Full、PHPStan、PHPCS、（前端）四段證據。**主窗口必須親自重跑 Green Gate（§2.1 命令 2 + 3）與 PHPStan**，貼自己的輸出；sub-agent 的證據只作為參考。

### 2.7 Commit 規範（每 Phase）

1. `test(<scope>): <描述>（Red）` — 只含新測試 + fixtures；body 寫「Red：預期失敗，原因：類別/方法未實作」。
2. `feat(<scope>): <描述>` — 實作（可多個 commit，依 git-commit skill 拆分：後端 / 前端分開）。
3. `refactor(<scope>): ...`（若有）。
4. `chore(plan): 更新 .progress.json <Phase> done`。
- **只 stage 明確路徑**，禁止 `git add .` / `git add -A`（工作樹有 untracked `.tmp/`）。
- commit 前必跑憑證掃描（兩條都必須無輸出）：
  ```bash
  git diff --cached --name-only | grep -Ei '(^|/)\.env|\.webm$|\.zip$|trace\.zip|acceptance-videos|\.tmp/'
  git diff --cached | grep -nE "^\+.*(KEY|SECRET|TOKEN|PASSWORD|apicode|CustomerToken|appSecret)['\"]?\s*[=:=>]+\s*['\"][A-Za-z0-9+/=_-]{12,}"
  ```
  第二條若命中的是**官方公開測試向量**（skill 文件中的 sample key、PAYUNi/SLP 既有公開 sandbox 常數），需在 commit body 註明出處；其他一律移除。
- commit 結尾依各 agent 自身收到的 attribution 規定附尾。不 push（本地模式）；不 `--no-verify`。

### 2.8 共通實作守則（來自計劃 + CLAUDE.md，派發時一併附上）

- `declare(strict_types=1)`、`final class`、static hook callback、text domain `power_checkout`、`Plugin::logger()`、HPOS `$order->get_meta()`。
- 每個測試 method 掛白名單 group（`smoke/happy/error/edge/security`）其一 + class 級 `@group integration` + `payment|logistics` + provider id。
- Mock：`pre_http_request` filter（參考 `tests/Integration/Payment/PaynowRestClientTest.php`）；golden 回應放 `tests/Integration/{Payment|Logistics}/Fixtures/{provider_id}/*.json`（目錄目前不存在，由各 Phase 建立）。
- 新 gateway 測試 `set_up()` 顯式 `update_option('woocommerce_currency','TWD')`（R18）。
- 新 Settings DTO `mode` 預設 `test`（R17）；必填在 `create()` 內顯式驗並拋 `\InvalidArgumentException`（R16），**不碰舊 DTO**。
- 不碰：`final process_payment()` / `before_page_render()`、`RedirectSettingsDTO`、20 個 DTO 的 `required_properties`、`LinePayTest.php` 及 `tests/e2e/record-linepay-success.ts`、`linepay-checkout-flow.spec.ts`、`inc/tests/`。
- 每 Phase 新增+修改檔案數若超過下表「預估檔數」150% → 停下回報主窗口（計劃原則）。

### 2.9 派發 prompt 樣板（主窗口照填）

```
用繁體中文。Repo：C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-checkout，分支 feat/unified-gateway-providers（已存在，勿切換）。
你負責 <Phase 名稱>，一次完成 Red→Green→Refactor 並 commit。
必讀：
 1. specs/plan/2026-11-20-tdd-blueprint.md §2（全域規則）與 §<Phase 節號>（本 Phase 規格與本藍圖的修正）
 2. specs/plan/2026-11-20-unified-gateway-and-7-providers.md 的 <Phase> 章與「已知風險」相關 R 編號
 3. skill 參考：.claude/skills/<skill>/SKILL.md 與 references/<…>；zenbu 參考碼：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\commerce\<…>（只讀，勿修改）
流程：
 (R) 先寫全部失敗測試 → `php -l` 每個測試檔 → 以 §2.1 命令 2 跑，貼 EXIT_CODE≠0 證據 → commit test(...)（Red）
 (G) 最小實作至新測試全綠 → §2.1 命令 3 全套 → §2.2 PHPStan → §2.3 PHPCS →（前端）§2.4
 (F) Refactor（不改行為）後重跑 G 的全部 Gate
 (C) 依 §2.7 commit；更新 specs/plan/.progress.json 本 Phase=done、last_commit
限制：同一時間只能有你一個 phpunit；不得 push；不得讀出或寫入任何憑證值；遇到 ASM 與程式現況衝突時採本藍圖標註的保守方案，無標註者停下回報。
回報：每個 Gate 依 §2.6 格式貼證據；列出新增/修改檔案清單與檔數；列出偏離藍圖之處與理由。
```

---

## §3 第零步：分支、基線、檢查點（主窗口親自做，約 0.5–1 小時）

| # | 動作 | 命令 / 內容 |
|---|---|---|
| 0.1 | 開分支 | `git fetch && git checkout master && git pull && git checkout -b feat/unified-gateway-providers`（untracked 的 `specs/plan/` 會跟過來） |
| 0.2 | 帶入 skill | **不要整個 cherry-pick `cc30605`**（含 `.serena/project.yml` 變更）。改：`git checkout cc30605 -- .claude/skills/` → `git add .claude/skills/` → `git commit -m "chore(skills): 帶入第三方 API skill 參考資料（自 cc30605）"`。完成後確認 `.claude/skills/{tcat-pay,linepay,tcat-logistics,hct-logistics,sf-express,kerrytj-logistics}/SKILL.md` 存在 |
| 0.3 | 加 ignore | `.gitignore` 追加 `.env.*`、`!.env.example`、`.tmp/`、`tests/e2e/acceptance-videos/` → `git commit -m "chore(gitignore): 忽略 .env.* 、.tmp 與驗收影片"`（計劃 P8 才加影片 ignore，本藍圖提前到第零步以防誤 commit） |
| 0.4 | 起 DB + 基線 | §2.1 命令 0、1、3 在**未改任何程式**時跑全套；PHPStan §2.2 跑一次。結果寫入 `.progress.json.baseline` |
| 0.5 | 進度檔 | `specs/plan/.progress.json`：`{"plan":"2026-11-20-unified-gateway-and-7-providers","blueprint":"2026-11-20-tdd-blueprint","baseline":{"tests":N,"failures":[...],"phpstan_errors":["RedirectSettingsDTO.php:148"]},"phases":{"P0":"pending",...,"P9":"pending"},"last_commit":null}`；計劃檔、藍圖檔、進度檔一起 `git add specs/plan/` → `chore(plan): 新增統一介面計劃、TDD 藍圖與進度檔` |

**第零步 Gate**：主窗口貼 0.4 的全套摘要（含 EXIT_CODE 與 failures 名稱）與 PHPStan 輸出。基線 failures 若多於 §2.5 所列 → 先回報用戶，不進 P0。

---

## §4 Phase 一覽

| Phase | 交給 | 預估（計劃工時 / agent 實跑） | 預估檔數 | 前端 |
|---|---|---|---|---|
| P0 統一介面 | wordpress-master | 8h / 1.5–2.5h | 約 28 | 無（僅 SettingApiService 回 meta） |
| P1 SLP 嵌入式 | wordpress-master（可拆 P1-A 後端 / P1-B 前端） | 16h / 3–4.5h | 約 24 | Vue + external TS + blocks tsx |
| P2 黑貓PAY | wordpress-master | 14h / 3–4h | 約 28 | Vue + blocks tsx |
| P3 LINE Pay | wordpress-master | 12h / 2.5–3.5h | 約 24 | Vue + blocks tsx |
| P4 黑貓宅急便 | wordpress-master | 12h / 2–3h | 約 20 | Vue |
| P5 新竹物流 + Allocator | wordpress-master | 14h / 3–4h | 約 22 | Vue |
| P6 順豐 | wordpress-master | 14h / 3–4h | 約 22 | Vue |
| P7 嘉里大榮 | wordpress-master | 10h / 2–3h | 約 16 | Vue |
| P8 E2E 錄影驗收 | wordpress-master（Playwright TS） | 10h + 人工 1h / 2–4h + 人工 | 約 12 | e2e only |
| P9 文件同步 | doc-updater | 4h / 0.5–1h | 約 6 | — |

序列順序：第零步 → P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P9（P7 依賴 P5 的 `WaybillNumberAllocator`）。

---

## §5 P0：統一介面（只加不改）

**交給**：wordpress-master　**Filter**：`ProviderMetaContract|WebhookPipeline|PaymentDispatchApiService|LogisticsProviderMetaContract|ShippingState|LogisticsApiServiceCapability|PaymentProviderContract`

### 5.1 Red 前置（agent 必做，寫進 Red commit body）

以讀碼確認每家能力，**能力定義採「IPaymentProvider 方法層級」**（見 §9 修正 1）：只有該 gateway 類別**自己覆寫** `query_trade()` / `capture()` / `void_auth()` 才標 true；僅以 admin order action（`woocommerce_order_actions`）提供者標 false，並在該 gateway `get_provider_meta()` docblock 註明「另有 admin action：…」。實測現況（agent 須複核）：

| Gateway | 自行覆寫的方法 | 推定 capture / void / query |
|---|---|---|
| `shopline_payment_redirect`（`RedirectGateway` → `ShoplinePayment\Shared\Abstracts\PaymentGateway`） | process_refund（在 SLP 基底）| false / false / false（ReturnSync 不是 `query_trade()`） |
| `ecpay_aio` | process_refund | false / false / false（請款/取消授權為 order action） |
| `ecpay_ecpg` | process_refund | false / false / false |
| `newebpay_mpg` | process_refund | false / false / false（order action） |
| `payuni_upp` | process_refund, query_trade, capture, void_auth | true / true / true |
| `payuni_uni_embed` | 同上 | true / true / true |
| `paynow` | process_refund, query_trade | false / false / true |

`refund`/`partial_refund` 照計劃表（全 true）；`payment_mode` 照計劃表；`recurring` 全 false。

### 5.2 Red — 測試（計劃 P0 表全數照寫）

| 測試檔 | Group | 斷言要點 |
|---|---|---|
| `tests/Integration/Payment/ProviderMetaContractTest.php` | smoke / happy / edge | data provider 逐家：instanceof `IPaymentProvider`、回 `ProviderMetaDTO`、`id === ID`、6 個 capability 皆 bool、值 = §5.1 表；anonymous 子類（extends AbstractPaymentGateway，不覆寫）回保守預設 `form-redirect` / `pre-create-pending` / capture·void·query·recurring=false；`verify_callback()` 預設 false |
| `tests/Integration/Payment/WebhookPipelineTest.php` | happy / edge / security / error | handler 呼叫 1 次 + key 寫入 `_pc_callback_processed` + 200；同 key 重送不呼叫；>50 筆只留最新 50；verify=false → 不呼叫、200、log 含 `SIGNATURE`（用 logger capture）；handler 拋 `\RuntimeException` → 200 + key **不寫入**；找不到單 → 200 + `NOT_FOUND`；`callback_ack_response()` 覆寫為 `text/plain OK` 原樣回傳；log **不含** body 原文 |
| `tests/Integration/Payment/PaymentDispatchApiServiceTest.php` | smoke / security / error / edge / happy | 路由 `power-checkout/v1/pay/(?P<order_id>\d+)/dispatch` 已註冊；order_key 錯 / 缺 → 403；訂單不存在 → 404；gateway 非 `IEmbeddedPaymentProvider` → 400 + `UNSUPPORTED`；`!needs_payment()` → 409；test double 回傳原樣包進 `data` |
| `tests/Integration/Logistics/LogisticsProviderMetaContractTest.php` | smoke / happy | 既有 **3 家**（Ecpay / Payuni / Paynow）回 `LogisticsProviderMetaDTO`；capability 與現行一致（ecpay `can_return=true`、paynow `can_return=false`、payuni 依讀碼） |
| `tests/Integration/Logistics/ShippingStateTest.php` | happy / edge | 11 case 值對齊 `C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\commerce\shipping\interfaces\shipping-gateway.types.ts`；`is_terminal()` 6 個 true |
| `tests/Integration/Logistics/LogisticsApiServiceCapabilityTest.php` | error / happy / security | test double 宣告 `can_print=false` → print 回 501 + `UNSUPPORTED`；`GET /logistics/providers/meta` admin 可讀、未登入 401/403；**既有 3 家**所有既有端點回應碼不變（回歸） |

回歸守門：`PaymentProviderContractTest`、`tests/Integration/Logistics/*` 全綠。

### 5.3 Green — 實作檔案

照計劃「架構變更 P0」表（DTO×4、Enum×3、Interface 修改×2 + 新增 `IEmbeddedPaymentProvider`、`AbstractPaymentGateway` 只加方法、`WebhookPipeline`、`PaymentDispatchApiService`、7 gateway 各加 `get_provider_meta()`、3 物流 provider 各加 `get_provider_meta()`、`LogisticsApiService`、`SettingApiService`、`Payment\ProviderRegister`）。

藍圖補充：
- `LogisticsApiService` 的 capability 檢查**只在 provider meta 宣告 false 時**回 501；既有 3 家宣告須與現行行為一致，`LogisticsApiServiceTest` 全綠為證。
- `GET logistics/providers/meta` 與既有 `logistics/(?P<id>\d+)` 不衝突（`\d+`），但測試要覆蓋。
- `WebhookPipeline` 每方法 <50 行；`AbstractPaymentGateway` 新增 <60 行。

### 5.4 Gate 與完成標準

§2.1(2)(3)、§2.2、§2.3；無前端 build（`SettingApiService` 只改 PHP）。完成標準：計劃共通 5 項（PHPCS/lint 依 §2.3/§2.4 修正版）+ Payment 測試數只增不減。

### 5.5 Commit 建議

```
test(payment): 新增 provider meta、WebhookPipeline、dispatch REST 契約測試（Red）
test(logistics): 新增物流 provider meta、ShippingState、capability 501 測試（Red）
feat(payment): 新增 ProviderMeta 能力宣告與統一 webhook 外框
feat(payment): 新增嵌入式金流 dispatch REST 端點
feat(logistics): 新增物流 provider meta、ShippingState 與 capability 檢查
chore(plan): 更新 .progress.json P0 done
```

---

## §6 P1–P7：新 provider

> 每節只寫「本藍圖的執行規格 + 修正」；檔案清單與完整測試案例以計劃對應章為準，agent 必須**全數**實作計劃表列的測試案例。所有新 provider 另須：加入 `ProviderMetaContractTest` 或 `LogisticsProviderMetaContractTest` 的 data provider（契約 matrix）。

### §6.1 P1：SHOPLINE Payments 嵌入式 `shopline_payment_embedded`

**交給**：wordpress-master（P1-A：PHP + 測試；P1-B：`js/src/external/ShoplineEmbedded/`、`js/src/index.ts`、Vue 設定頁、router、`global.d.ts`/`env.ts`、`inc/assets/blocks/shopline_payment_embedded.tsx`）
**Filter**：`SlpEmbedded|SlpOrderResolver|SlpWebhookResponse|SlpReturnSync|SlpSessionIdentity|ProviderMetaContract`
**參考**：skill `shopline-payments-v1/references/{embedded-sdk,api-reference,webhook-events,sandbox-resources}.md`；zenbu `payments/shopline/shopline-embedded.ts`

**本藍圖修正（必讀，詳見 §9 修正 2、3）**
1. **Webhook 註冊**：既有 `/power-checkout/slp/webhook` 只在 `RedirectGateway::init()` 呼叫 `WebHook::instance()`，而 `ProviderRegister` 只對**已啟用** gateway 呼叫 `init()` → 只開嵌入式時 webhook 路由不存在。`EmbeddedGateway::init()` 必須也呼叫 `WebHook::instance()`（singleton，冪等）。
2. **憑證來源**：既有 `WebHook` 驗簽固定讀 `RedirectSettingsDTO::instance()->signKey`，且 `RedirectSettingsDTO` 已有 `clientKey` 欄位。保守方案：嵌入式**共用 SLP 商店憑證**（merchantId / apiKey / signKey / clientKey / mode 一律讀 `RedirectSettingsDTO::instance()`，唯讀，不修改該檔），`EmbeddedSettingsDTO` 只含 `enabled` / `title` / `description` / `order_button_text`（mode 不重複存）。Vue 設定頁顯示「商店憑證共用 SHOPLINE Payments 導轉式設定」並連到該頁。`is_available()`：TWD + `RedirectSettingsDTO` 的 clientKey/apiKey/merchantId 非空。
3. **SDK 版本**：skill 官方 CDN 為 `https://cdn.shoplinepayments.com/sdk/v1/payment-web.js`（僅 major 版本路徑），無法鎖精確版本；採官方 CDN v1，於 `external/ShoplineEmbedded/index.ts` 常數註明。不改用 npm 打包。

**Red 額外案例（計劃表之外，必加）**

| 測試檔 | Group | 斷言 |
|---|---|---|
| `SlpEmbeddedWebhookTest.php` | smoke | redirect 停用、embedded 啟用並 `ProviderRegister` 初始化後，`rest_get_server()->get_routes()` 含 `/power-checkout/slp/webhook` |
| 同上 | happy | redirect 停用時 embedded 訂單 `trade.succeeded`（以 RedirectSettingsDTO signKey 簽）→ processing |
| `SlpEmbeddedSettingsDTOTest.php` | edge | `EmbeddedSettingsDTO` 不含任何憑證欄位；`is_available()` 隨 RedirectSettingsDTO 憑證是否齊全而變 |

其餘依計劃 P1 表（`SlpEmbeddedSettingsDTOTest`、`SlpEmbeddedGatewayTest`、`SlpEmbeddedDispatchTest`、`SlpEmbeddedWebhookTest`、`SlpEmbeddedRefundTest`、`SlpOrderResolverTest` 新增 1 案）。`SlpEmbeddedSettingsDTOTest` 的「client_key 空時 `is_available()=false`」改以 RedirectSettingsDTO 的 clientKey 為準。

**回歸守門**：`SlpOrderResolverTest`、`SlpWebhookResponseTest`、`SlpReturnSyncTest`、`SlpSessionIdentityTest`、`WebhookSignatureTest`、`RedirectGatewayValidationTest` 全綠不變。

**Gate**：§2.1–§2.4 全部（`pnpm build` + `pnpm build:blocks`）。P1-B 另需 `MountShoplineEmbedded()` 在非嵌入式頁面不執行（以 build 後 grep localize 物件判斷為準，不需 JS 單元測試）。

**Commit 建議**
```
test(slp): 新增 SLP 嵌入式 gateway / dispatch / webhook / 退款測試（Red）
feat(slp): 新增 SHOPLINE Payments 嵌入式 gateway 與 SDK 兩段式 dispatch
fix(slp): OrderResolver 白名單納入嵌入式 gateway，嵌入式啟用時註冊 webhook
feat(slp): 新增嵌入式前端 SDK 掛載、設定頁與區塊結帳註冊
```

### §6.2 P2：黑貓PAY `tcat_pay`

**交給**：wordpress-master　**Filter**：`TcatPay|ProviderMetaContract|WebhookPipeline`
**參考**：skill `tcat-pay/SKILL.md`、`references/{api-reference,callbacks-apn,codes-reference,examples}.md`；zenbu `payments/kolet/{kolet-crypto,kolet-apn-verify,kolet-client,kolet-amount-limits,kolet-reconcile.cron}.ts`

Red 依計劃 P2 表全數（`TcatPayChecksumTest`、`TcatPayApiClientTest`、`TcatPayGatewayTest`、`TcatPayApnTest`、`TcatPayReturnTest`、`TcatPayRefundTest`、`TcatPaySettingsDTOTest`），重點斷言：
- `TcatPayChecksumTest`：golden 向量**只取** `references/examples.md` 文件中的數值，測試 docblock 註明章節。
- `TcatPayApnTest`：APN 只是觸發，狀態只依平台查詢（R1）；平台查詢逾時回**非 `OK`**（R2 刻意例外，與 WebhookPipeline「永遠 ack」並存：由 tcat_pay 覆寫 `callback_ack_response()` 依 process 結果決定）；10 秒節流 transient；`pay_amount ≠ total` 不完成 + note。
- `TcatPayCallback` 路由 `power-checkout/tcat-pay/apn` 無條件註冊（smoke）。

藍圖補充：WebhookPipeline 的「handler 拋例外 → key 不寫」與「平台逾時回非 OK」需一致：平台逾時以專用例外/結果值回報，key 不寫、ack 回非 OK。

**Gate**：§2.1–§2.4（`pnpm build` + `pnpm build:blocks`）。
**Commit**：`test(tcat-pay): …（Red）` / `feat(tcat-pay): 新增黑貓PAY COCS 刷卡與 CVS 取號 gateway` / `feat(tcat-pay): 新增 APN 回呼（平台查詢為準）與退款請款` / `feat(tcat-pay): 新增設定頁與區塊結帳子方式選擇`

### §6.3 P3：LINE Pay 直連 `linepay`

**交給**：wordpress-master　**Filter**：`LinepayDirect|ProviderMetaContract`（**不得**用 `LinePay` 這種會同時匹配既有 `LinePayTest` 的 regex；既有 3 支 pre-existing 紅燈與本 Phase 無關）
**參考**：skill `linepay/SKILL.md`、`references/{authentication,flows-and-status,error-codes,sandbox-and-onboarding,api-reference}.md`；zenbu `payments/linepay/{linepay.service,linepay-signature,linepay-reconcile.cron}.ts`

Red 依計劃 P3 表全數。重點：
- 19 位 `transactionId` golden（`2026111900000000001`），斷言 meta 為 string 且 `===`（R4）。
- confirmUrl 自帶 `order_key`；302 回應以 `WP_REST_Response` status 302 + `Location` header 斷言。
- AS 任務：斷言 `as_next_scheduled_action('pc_linepay_reconcile', [ 'order_id' => $id ])` 有值（約 +1500s）。
- `LinepayDirectSignatureTest` golden 只取 `references/authentication.md` 中的向量。

**Gate**：§2.1–§2.4。
**Commit**：`test(linepay): 新增 LINE Pay 直連簽章 / 請款 / confirm / 補單測試（Red）` / `feat(linepay): 新增 LINE Pay v4 直連 gateway 與 confirm/cancel 回呼` / `feat(linepay): 新增 Action Scheduler 補單與後台查詢補單` / `feat(linepay): 新增設定頁與區塊結帳註冊`

### §6.4 P4：黑貓宅急便 `tcat_logistics`

**交給**：wordpress-master　**Filter**：`TcatLogistics|WC_TcatLogisticsShipping|LogisticsProviderMetaContract|LogisticsApiService`
**參考**：skill `tcat-logistics/SKILL.md`、`references/{api-reference,cargo-status,label-spec,code-examples}.md`；zenbu `shipping/tcat/*`

Red 依計劃 P4 表全數，另加：
- `TcatLogisticsPingTest`（`security` / `happy`）：`POST power-checkout/v1/logistics/tcat_logistics/ping` 需 admin nonce；mock `ParsingAddress` 成功回 200（計劃 P8 說「ping 在 P4–P7 各自實作」，此處補上測試）。
- `TcatLogisticsProviderTest` query 2h 快取：斷言第二次不觸發 `pre_http_request`。

**本藍圖修正（§9 修正 4）**：repo 沒有任何物流訂單頁 UI（`js/src` 無 create-shipment 呼叫、無物流 MetaBox），計劃「MetaBox 按鈕依 capability 顯示」**不適用，不新增 MetaBox**（避免範圍外擴）。物流操作維持只有 REST；P8 以 REST 驅動並錄後台畫面（見 §7）。

**Gate**：§2.1–§2.3、§2.4（`pnpm build`）。
**Commit**：`test(tcat-logistics): …（Red）` / `feat(tcat-logistics): 新增黑貓宅急便直串建單、列印、查詢與逆物流` / `feat(logistics): 新增物流測試連線 ping 端點` / `feat(tcat-logistics): 新增運送方式與設定頁`

### §6.5 P5：新竹物流 `hct_logistics` + `WaybillNumberAllocator`

**交給**：wordpress-master　**Filter**：`WaybillNumberAllocator|Hct|LogisticsProviderMetaContract`
**參考**：skill `hct-logistics/SKILL.md`、`references/{api-reference,code-examples,cargo-tracking-decrypt,version-notes,label-spec}.md`；zenbu `shipping/hct/{hct-soap-client,hct-logistics.service,hct-track-crypto}.ts`

Red 前置：SOAP namespace / SOAPAction **以 `references/version-notes.md` 記載的實打值為準寫 golden fixture**；若需 `curl {asmx}?wsdl` 確認，只做唯讀 GET，不帶任何帳密。
Red 依計劃 P5 表全數，另加：
- `WaybillNumberAllocatorTest`（edge）：用罄邊界後 option `current` 不超過 `end`；Allocator 不依賴 provider，可注入 checksum 策略（以 test double 策略驗證）。
- `HctTrackCryptoTest`：若容器 OpenSSL 3 無 DES-CBC（`openssl_get_cipher_methods()` 不含 `des-cbc`）→ `markTestSkipped` 並在回報註明；**skip 不算 Green 失敗，但必須在 P9 文件與設定頁警示中呈現**。
- `HctLogisticsPingTest`（`addrCompare`）。

**Gate**：§2.1–§2.4（`pnpm build`）。
**Commit**：`test(logistics): 新增號段配號器測試（Red）` / `test(hct): …（Red）` / `feat(logistics): 新增通用託運單號段原子配號器` / `feat(hct): 新增新竹物流 SOAP 建單、標籤、查詢、取消與退貨` / `feat(hct): 新增運送方式與設定頁（號段與剩餘量）`

### §6.6 P6：順豐 `sf_express`

**交給**：wordpress-master　**Filter**：`Sf(Crypto|AccessTokenStore|Express|TrackPush|Region)|LogisticsProviderMetaContract`
**參考**：skill `sf-express/SKILL.md`、`references/{auth-and-crypto（§0.8 驗證紀錄）,iuop-create-order,iuop-order-ops,tracking,taiwan-rules,address-region,php-client,onboarding-and-faq}.md`；zenbu `shipping/sf-express/*`

Red 依計劃 P6 表全數，另加 `SfExpressPingTest`（唯讀接口，依 `onboarding-and-faq.md` §8 公開憑證所能打的端點）。`SfCryptoTest` 向量只取 `auth-and-crypto.md` §0.8。軌跡推送路由 `power-checkout/sf-express/track-push` 無條件註冊，回應格式依 `references/tracking.md`。
Vue 設定頁含 TW→TW 警示（R12）。

**Gate**：§2.1–§2.4（`pnpm build`）。
**Commit**：`test(sf-express): …（Red）` / `feat(sf-express): 新增順豐 IUOP 加解密、token 快取與 API client` / `feat(sf-express): 新增建單、列印、查詢、取消與軌跡推送` / `feat(sf-express): 新增運送方式與設定頁`

### §6.7 P7：嘉里大榮 `kerrytj_logistics`

**交給**：wordpress-master　**Filter**：`Kerrytj|WaybillNumberAllocator|LogisticsProviderMetaContract`
**參考**：skill `kerrytj-logistics/SKILL.md`、`references/{api-reference,tracking,php-client,onboarding-and-business-rules}.md`；zenbu `shipping/kerrytj/{kerrytj-api-client,kerrytj-bln,kerrytj-bln-allocator,kerrytj-payload,kerrytj-tracking}.ts` 與其 `*.spec.ts`（golden 向量來源）

Red 依計劃 P7 表全數；`cancel` capability 由 agent 讀 skill 判定，無法確認 → **false**（保守）並於 Red commit body 註明依據。`ping` 回 `UNSUPPORTED`（計劃明定）→ `KerrytjLogisticsPingTest` 斷言 501。

**Gate**：§2.1–§2.4（`pnpm build`）。
**Commit**：`test(kerrytj): …（Red）` / `feat(kerrytj): 新增嘉里大榮 BLN 配號、建單、列印、查詢與退貨` / `feat(kerrytj): 新增運送方式與設定頁`

---

## §7 P8：E2E 錄影驗收

**交給**：wordpress-master（Playwright TS）。LINE Pay 人工段需用戶在場。
**前置（主窗口）**：P0–P7 全 done；`curl -sI https://local-turbo.powerhouse.tw/wp-json/ -o /dev/null -w '%{http_code}'` 判定 tunnel；回 200 → 站點用 tunnel；否則 `turbo.local` + `route` 改寫網域（server-to-server 回呼收不到，改用後台查詢補單完成狀態並註明）。

### 7.1 設定檔（`tests/e2e/playwright.config.ts`）

```ts
projects: [
  { name: 'api-tests', testDir: '.', testMatch: ['**/*.spec.ts'], testIgnore: ['04-live/**'] },
  {
    name: 'live-acceptance',
    testDir: '.',
    testMatch: ['04-live/**/*.spec.ts'],
    timeout: 180_000,
    outputDir: 'test-results/live-acceptance',
    use: {
      headless: false,             // headless 易被 WAF 擋
      video: 'on',
      trace: 'on',
      screenshot: 'on',
      viewport: { width: 1440, height: 900 },
      launchOptions: { slowMo: 150 },
    },
  },
],
```
- 頂層 `testMatch` 不動；`api-tests` 加 `testIgnore` 為唯一既有行為改動。驗證：`npx playwright test --project=api-tests --list | grep -c 04-live` 必須為 0。
- 新 helper：`tests/e2e/helpers/live-env.ts`（只從 `process.env` 讀；缺值 `test.skip(true, '缺 <VAR> 憑證')`）、`tests/e2e/helpers/live-setup.ts`（REST/wp-cli 寫 provider test 模式設定、`woocommerce_currency=TWD`、建測試商品，避開商品 72）、`tests/e2e/helpers/live-logistics.ts`（以 admin nonce 呼叫物流 REST 並導到訂單編輯頁顯示 order note / 列印輸出）。
- 環境變數**名稱**：agent 只可用 `grep -oE '^[A-Z0-9_]+=' <env 檔>` 取得鍵名，**禁止**輸出值；`live-env.ts` 以鍵名映射。

### 7.2 憑證載入（只寫來源，不寫值；不複製進 repo）

| Provider | 來源 | 載入 |
|---|---|---|
| SLP 嵌入式 | repo 根 `.env` 的 `SHOPLINE_SANDBOX_*`（含 `SHOPLINE_SANDBOX_CLIENT_KEY`） | `set -a; source .env; set +a` |
| 黑貓PAY | `C:\Users\User\DEV\zenbu\features\.tmp\[金流] 黑貓 PAY\.env.local`（**禁用**同資料夾 `.env.production`） | `set -a; source "<該路徑>"; set +a` |
| LINE Pay | `C:\Users\User\DEV\zenbu\features\.tmp\[金流] LINE Pay\.env.local` | 同上 |
| 黑貓宅急便 | `C:\Users\User\DEV\zenbu\features\.tmp\[物流]黑貓宅急便_自有系統串接_標準印單API參考文件_v2.0.0\.env.local`（`_TEST` 組） | 同上 |
| 新竹物流 | `C:\Users\User\DEV\zenbu\features\.tmp\[物流]新竹物流\.env.local` + skill 公開測試帳號說明 | 同上 |
| 順豐 | skill `sf-express/references/onboarding-and-faq.md` §8 公開唯讀憑證說明 | 由 helper 依文件設定（不寫入 repo 以外的位置） |
| 嘉里大榮 | 無 | — |

執行：`cd tests/e2e && set -a && source "<來源>" && set +a && E2E_BASE_URL=<站點> npx playwright test --project=live-acceptance 04-live/<spec>`。**一次只跑一支 spec**（workers=1 既有設定）。

### 7.3 Spec 清單與標準

| Spec 檔 | 完整驗收（錄影必備畫面） | 降級標準 |
|---|---|---|
| `tests/e2e/04-live/slp-embedded.spec.ts` | 結帳選嵌入式 → order-received SDK 卡片表單 → `keyboard.type` 輸入 sandbox 測試卡（skill `sandbox-resources.md`）→ 3DS（若有，OTP 依 sandbox 文件）→ 導回 → 後台訂單 processing + transaction id → RefundDialog 部分退款成功 | tunnel 離線：導回後 ReturnSync / 後台查詢補單仍須轉 processing；做不到 = **不通過**（SLP 不允許降級） |
| `tests/e2e/04-live/tcat-pay.spec.ts` | (a) COCS 刷卡短網址 → 測試卡授權 → 導回 → processing；(b) CVS ibon 代碼顯示於 order-received | 無測試卡號：(b) 完整 + (a) 進到刷卡頁截圖；狀態轉換以 `TcatPayApnTest` mock 綠燈佐證 |
| `tests/e2e/04-live/linepay.spec.ts` | 取得 `paymentUrl` → LINE Pay 頁載入；`LIVE_LINEPAY_MANUAL=1` 時在登入頁 `page.pause()` 由用戶手動登入付款 → 續跑驗 processing | **預設降級**：request 成功 + LINE Pay 頁載入錄影 + `LinepayDirect*` mock 全綠 |
| `tests/e2e/04-live/tcat-logistics.spec.ts` | 建 TWD 測試訂單 → REST create-shipment → 訂單頁顯示託運單號 note → print 輸出在瀏覽器顯示 PDF → query（顯示狀態或 `cached`） | 測試站不回貨態：建單 + PDF 即通過 |
| `tests/e2e/04-live/hct.spec.ts` | 建單取得 edelno + 標籤 PNG 顯示 → 取消 | **號段缺**：只錄設定頁「測試連線」(`addrCompare`) 成功 + mock 全綠，註明「待新竹核發測試號段」 |
| `tests/e2e/04-live/sf-express-readonly.spec.ts` | — | **預設降級**：設定頁「測試連線」以公開憑證打唯讀接口成功的錄影 + mock 全綠 |
| `tests/e2e/04-live/kerrytj-settings.spec.ts` | — | **預設降級**：設定頁存設定 + 號段顯示的錄影（不打 API）+ mock 全綠（§9 修正 5：計劃說不建 spec 卻要錄影，故建只錄設定頁的 spec） |

### 7.4 影片輸出與整理

- Playwright 輸出：`tests/e2e/test-results/live-acceptance/<test-title-slug>/video.webm`（+ `trace.zip`）。
- 整理到：`tests/e2e/acceptance-videos/2026-11-<DD>/<provider_id>-<full|degraded>.webm`（目錄已於第零步 ignore）。
- 驗收結果表（provider / 完整或降級 / 影片路徑 / 阻塞原因）**不寫報告檔**，回報給主窗口，供收尾摘要與日後 PR 描述使用。

### 7.5 Gate 與 commit

- Gate：每支 spec `EXIT_CODE=0`（降級 spec 的 `test.skip` 視為通過但必須列出 skip 原因）；`npx playwright test --project=api-tests --list` 不含 `04-live`；§2.7 憑證掃描（特別確認無 `.webm` / `trace.zip` / `.env*` 被 stage）。
- Commit：`test(e2e): 新增 live-acceptance 錄影驗收 project 與 7 家 provider spec`、`chore(plan): 更新 .progress.json P8 done`。

---

## §8 P9 與收尾（Refactor / opt-in / 收尾藍圖）

### 8.1 Refactor 後品質把關

各 Phase Green Gate 通過即進下一 Phase，不強制派 reviewer。建議用戶 opt-in：
- `@wordpress-reviewer`：P0（介面設計）、P1（動到既有 SLP 的唯一行為修改）結束後各一次。
- `@zenbu-powers:security-reviewer`（**強烈建議**，WordPress 專案 + payment / external-api / 回呼驗簽）：P1–P3 金流與 P6 順豐推送完成後一次總審，重點：dispatch REST order_key、tcat_pay APN 偽造、LINE Pay confirmUrl 偽造、SLP webhook 白名單、號段 SQL 原子性。

### 8.2 P9：文件同步

**交給**：`@zenbu-powers:doc-updater`。內容照計劃 P9 表（`.claude/CLAUDE.md`、`provider-guide` / `react-blocks` / `vue-frontend` / `testing` 四個 rule、memory 新增 `unified-gateway-providers-status.md`）。藍圖補充寫入：
- CLAUDE.md「Testing Infrastructure」補 pre-existing 失敗 `LinePayTest` 3 支。
- SLP 嵌入式「共用導轉式憑證 + 嵌入式啟用時也註冊 webhook」。
- 能力宣告為方法層級、admin order action 另列。
- `HctTrackCrypto` 若 skip：寫明 OpenSSL legacy provider 需求。
- 物流目前無訂單頁 UI，操作只有 REST。
- Commit：`docs: 同步統一金流物流介面與 7 家新 provider 文件`。

### 8.3 收尾（主窗口）

0. Milestone 同步：`specs/milestones/` 目前不存在 → 跳過。
1. 主窗口最終 Green Gate：§2.1(3) 全套 + §2.2 PHPStan，貼輸出；failures ⊆ 基線。
2. `.progress.json` 全部 done。
3. 本地模式：不 push、不開 PR；保留 `feat/unified-gateway-providers` 等用戶驗收。
4. 回報用戶：各 Phase commit 清單、測試數增量、7 家 live 驗收表（完整/降級/阻塞）、影片路徑、建議補派的 opt-in reviewer、GAP 清單（SLP 外各家正式憑證、新竹號段、順豐建單憑證、嘉里大榮全無憑證、LINE Pay 人工段）。

---

## §9 計劃修正點（本藍圖已採保守方案，主窗口可轉告 planner/用戶）

| # | 計劃原文 | 程式現況（實測） | 本藍圖採用 |
|---|---|---|---|
| 1 | 既有 7 家 capability 表：`ecpay_aio`/`newebpay_mpg` capture/void/query=true、SLP query=true、`paynow` 等 | `ecpay_aio`、`newebpay_mpg` 的請款/取消授權只以 admin order action 實作，未覆寫 `capture()`/`void_auth()`/`query_trade()`；SLP 的 ReturnSync 不是 `query_trade()`；只有 payuni_upp / payuni_uni_embed 覆寫三者、paynow 覆寫 query_trade | capability = 方法層級（避免 meta 宣告 true 但呼叫方法是 no-op）；admin action 能力寫在 docblock。§5.1 表 |
| 2 | SLP 嵌入式 webhook 共用既有端點（ASM-3） | `WebHook::instance()` 只在 `RedirectGateway::init()` 呼叫，且只對**已啟用** gateway 呼叫 init → 只開嵌入式時端點不存在 | `EmbeddedGateway::init()` 也呼叫 `WebHook::instance()`，並加 smoke 測試 |
| 3 | `EmbeddedSettingsDTO` 自帶 merchant_id/api_key/sign_key/client_key | 既有 webhook 驗簽固定讀 `RedirectSettingsDTO->signKey`；該 DTO 已有 `clientKey` | 嵌入式共用 `RedirectSettingsDTO` 憑證（唯讀），EmbeddedSettingsDTO 只放顯示欄位；不改 WebHook、不改 RedirectSettingsDTO |
| 4 | P4「物流 MetaBox 按鈕依 capability 顯示」、P8「後台訂單 → 建立託運單」 | repo 無任何物流訂單頁 UI（只有 REST） | 不新增 MetaBox；P8 以 REST 驅動 + 錄訂單頁 note / 列印輸出 |
| 5 | P8 嘉里大榮「不建立 spec」但要「後台設定頁錄影」 | 錄影需要 spec | 建 `04-live/kerrytj-settings.spec.ts`（只錄設定頁） |
| 6 | 完成標準「`composer lint` 0 error」「`pnpm lint` 0 error」 | 全樹 PHPCS 現有 420 errors；`pnpm lint` 會跑全樹 phpcbf 且 eslint 只掃 `js/src` 頂層 | §2.3 / §2.4 變更檔判定；禁跑全樹 phpcbf |
| 7 | R15 pre-existing 只列 ezpay + PHPStan | memory 記載 `LinePayTest` 3 支自 v1.3.2 起即紅 | 第零步實測基線，納入允許清單；P3 filter 避開 |
| 8 | 第零步「cherry-pick `cc30605`」 | `cc30605` 另含 `.serena/project.yml` 變更 | `git checkout cc30605 -- .claude/skills/` 只帶 skill |
| 9 | `.env*` 已在 `.gitignore` | 只忽略 `.env` 與 `*.local`；`.env.production` 等未忽略；影片目錄未忽略 | 第零步即加 `.env.*`、`.tmp/`、`tests/e2e/acceptance-videos/` |
| 10 | P1「SDK 鎖定精確版本」 | 官方 CDN 只有 `/sdk/v1/` major 路徑 | 用官方 CDN v1，常數註明 |
| 11 | 「P1–P7 可平行」 | 共用工作目錄與測試 DB（R14 死結） | 全部序列 |
| 12 | 測試指令首選 `npx wp-env run tests-cli … bash -c` | 容器目前 Exited；memory 記載 `bash -c` 會被安全檢查擋、wp-env start 會撞 8893 port | §2.1 docker compose `run --rm --no-deps` + `MSYS_NO_PATHCONV=1` |
