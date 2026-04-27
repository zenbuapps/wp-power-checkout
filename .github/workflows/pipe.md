# `pipe.yml` 結構速查（Power Checkout 專用）

> 對應檔案：`.github/workflows/pipe.yml`
> **兩個 Job**：`claude`（釐清 → 規劃 → 實作）→ `integration-tests`（測試 → 修復 → AI 驗收 → PR）
> 基底範本：`power-course/.github/workflows/pipe.yml`（已 adapt 給 Power Checkout）

---

## 一、觸發方式與模式對照

**觸發事件**：`issue_comment` / `pull_request_review_comment` / `pull_request_review`，body 須含 `@claude`。
**Concurrency**：同一 issue/PR 的新 `@claude` 會取消舊的。

### 關鍵字 → 模式對照

| 留言 | 開工（clarifier → tdd） | 整合測試 + AI 驗收 |
|------|------------------------|-------------------|
| `@claude`（需求還需釐清） | ❌ 僅提澄清問題 | ❌ |
| `@claude`（需求已清楚） | ✅ 由 clarifier 自動升級 pipeline 並一路跑到 tdd | ❌ 需再打 `@claude PR` |
| `@claude 開工`（含 確認/OK/沒問題/開始/go/start） | ✅ | ❌ 需再打 `@claude PR` |
| `@claude 全自動` | ✅ | ✅ 自動 |
| `@claude PR` | ❌ 跳過 | ✅ 於現有分支直接跑 |

**解析優先序**：`全自動` > `PR` > `開工等` > 互動。

---

## 二、Job 1：`claude`

**Runner** `ubuntu-latest` / **Timeout** 180 min / **Permissions**：`contents`/`pull-requests`/`issues: write`、`id-token: write`、`actions: read`

### Job Outputs

| output | 意義 |
|--------|------|
| `branch_name` / `issue_num` | 本輪 `issue/{N}-{timestamp}` 分支與 issue 編號 |
| `initial_sha` | 進入 workflow 時的 HEAD（用於偵測變更） |
| `claude_ok` | clarifier + (planner/tdd) 整體成敗；skipped 視為 OK |
| `has_changes` | 是否有 commit 或 working tree 變動 |
| `agent_name` | `clarifier` / `clarifier+planner` / `...+tdd-coordinator` / `pr-only` |
| `pipeline_mode` / `full_auto_mode` / `pr_mode` | 模式旗標 |
| `run_integration_tests` | `full_auto_mode OR pr_mode` → 控制 Job 2 觸發 |

### Steps 流程

| 段 | 核心動作 |
|----|---------|
| **A** 前置 | eyes reaction → checkout → `resolve_branch`（找或建 `issue/{N}-*`）→ HTTPS → `save_sha` |
| **B** 模式解析 | `parse_agent` 設 `PIPELINE_MODE`/`FULL_AUTO_MODE`/`PR_MODE` → `fetch_context`（issue 上下文）→ 組 clarifier prompt（`PR_MODE=true` 則跳過） |
| **C** Clarifier | `claude-retry` composite action，agent=`zenbu-powers:clarifier`，`max_turns=200`(pipeline)/`120`(interactive)；`PR_MODE=true` 跳過 |
| **D** 橋接 | `detect_specs`（比對 `specs/` diff）→ `dynamic_upgrade`（interactive + 生成 specs → 升級 pipeline_mode）→ 通知留言 |
| **E** Planner | `specs_available && pipeline_mode` 才跑；agent=`zenbu-powers:planner`，`max_turns=120` |
| **F** TDD | `planner_ok=true` 才跑；agent=`zenbu-powers:tdd-coordinator`，`max_turns=200` |
| **G** 收尾 | `check_result` 匯整 outputs → 若有變更 `git push --force-with-lease` 兜底推送 |

---

## 三、Job 2：`integration-tests`

**依賴** `needs: claude` / **Timeout** 150 min

### 啟動條件

```yaml
run_integration_tests == 'true' &&
(
  pr_mode == 'true'                           # PR 模式旁路 claude_ok/has_changes
  OR
  (claude_ok == 'true' && has_changes == 'true')
)
```

### Steps 流程

| 段 | 核心動作 |
|----|---------|
| **H** 環境 | checkout(branch_name) → Node 20 / pnpm / composer → 建 uploads → wp-env start（3 次重試，delay 15/45/90s） |
| **I** PHPUnit 3 循環 | `test_cycle_1` 失敗 → `claude_fix_1` → `test_cycle_2` 失敗 → `claude_fix_2` → `test_cycle_3`（final，無修復）。所有步驟 `continue-on-error: true`，fix 走 `anthropics/claude-code-action@v1`。執行命令：`npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout vendor/bin/phpunit` |
| **J** 彙整 | `final_result` parse PHPUnit summary（`OK (...)` 或 `Tests: ...`）→ 發測試結果留言 |
| **K** AI 驗收 | `detect_smoke` 檢查 diff 有無動到 `js/src/`、`inc/templates/`、`inc/assets/`、`inc/classes/` → **執行 `tests/e2e/helpers/lc-bypass.ts` 注入 `'lc' => false` 到 plugin.php**（容忍多空格的 regex）並更新 `.e2e-progress.json` flag → 建置前端（`pnpm run build && pnpm run build:blocks`，雙 bundle：Vue 主 app + React WC Blocks）→ Playwright 裝 chromium → 預先以 wp-env 預設帳號（admin/password）登入產生 `.auth/admin.json` → `run_ai_acceptance`（agent=`zenbu-powers:browser-tester`） |
| **L** 媒體 | `collect_smoke_media` 集中到 `/tmp/smoke-media` → 上傳 Bunny CDN（`ci/{branch}/smoke-test`）→ Artifact 備份 7 天 → 發 Smoke Test 報告留言 |
| **M** PR 守門 | `run_ai_acceptance.outcome != 'failure'` → `自動建立 PR`（gh pr create，body 含測試 badge + AI 驗收 badge + `Closes #N`）；反之發「驗收失敗不自動開 PR」通知 |

### Job Outputs

`final_result_*` 系列：`status` / `cycle` / `fix_count` / `test_total/passed/failures/errors/assertions/skipped/incomplete/warnings`

---

## 四、與 power-course 範本的差異（本專案 adapt 項）

| 項目 | power-course 範本 | power-checkout |
|------|-------------------|----------------|
| wp-env mapping | `wp-content/plugins/wp-power-course` | `wp-content/plugins/power-checkout` |
| wp-env port | 8895 | 8891 frontend / 8893 tests（`.wp-env.json` 顯式設定 `"port": 8891, "testsPort": 8893`） |
| text domain / slug | `power-course` | `power_checkout`（底線）|
| Admin 介面 | React Admin SPA `admin.php?page=power-course#/` | WC Settings Tab `admin.php?page=wc-settings&tab=power_checkout_wc_settings#/payments`（Vue 3 HashRouter） |
| 前端 build 指令 | `pnpm run build && pnpm run build:wp` | `pnpm run build && pnpm run build:blocks`（雙 bundle：Vue 主 app + React WC Blocks） |
| LC Bypass 機制 | 注入 `plugin.php` 的 `'capability' => 'manage_woocommerce'` 下方插 `'lc' => false` | 改執行 `tests/e2e/helpers/lc-bypass.ts` 的 `applyLcBypass()`（regex 容忍多空格，找 `'callback' => [ Bootstrap::class, 'instance' ],` 後注入），同步更新 `.e2e-progress.json` flag 作為遙測 |
| 業務描述 | WordPress 線上課程外掛（LMS） | WooCommerce 結帳整合外掛（金流/電子發票/結帳頁） |

---

## 五、外部依賴資產

| 類型 | 路徑 |
|------|------|
| Composite action | `./.github/actions/claude-retry` |
| Prompt 模板 | `.github/prompts/{clarifier-pipeline,clarifier-interactive,planner,tdd-coordinator}.md` |
| 留言模板 | `.github/templates/{pipeline-upgrade-comment,test-result-comment,acceptance-comment}.md` |
| Shell script | `.github/scripts/upload-to-bunny.sh` |
| Marketplace | `https://github.com/zenbuapps/zenbu-powers.git`（提供 4 個 agents） |
| Secrets | `CLAUDE_CODE_OAUTH_TOKEN`、`BUNNY_STORAGE_{HOST,ZONE,PASSWORD}`、`BUNNY_CDN_URL` |

---

## 六、Gotchas（Power Checkout 特有）

1. **雙前端 bundle**：本專案前端有兩套（Vue 3 主 app 走 `vite.config.ts`，React WC Blocks 走 `vite.config.block.ts`）。AI 驗收時必須同時執行 `pnpm run build` 與 `pnpm run build:blocks`，否則結帳頁的區塊付款方式不會出現。
2. **WC Blocks 需要 WooCommerce 先啟用**：`.wp-env.json` 已將 WooCommerce zip 列為 plugin 依賴，但 wp-env 首啟時有時會因順序導致 WC 未啟用，造成整合測試 `is_plugin_active('woocommerce/woocommerce.php')` 失敗。若 test_cycle_1 失敗且錯誤訊息包含 `WC_Payment_Gateway` 找不到，fix agent 應在 test bootstrap 主動 activate。
3. **HPOS 雙軌**：測試中操作訂單必須同時相容 `shop_order`（legacy）與 `woocommerce_page_wc-orders`（HPOS）。
4. **`'capability' => 'manage_woocommerce'` 不存在於 plugin.php**：範本的 LC Bypass 注入機制**在本專案為 no-op**，故已改為只更新 `.e2e-progress.json` flag。若未來重構 plugin.php 結構加入 capability key，LC Bypass 邏輯可重新啟用。
5. **`parse_agent` 英文關鍵字採 word boundary**：`OK|go|start` 以 POSIX 風格 `[^[:alnum:]]` 包住，避免 `going`/`startup`/`Ok...` 誤觸發 pipeline；中文關鍵字（開工/全自動/確認/沒問題/開始）無此問題。
6. **AI 驗收 prompt 寫死 `http://localhost:8891`**：已與 `.wp-env.json` 對齊（`"port": 8891`），改動 port 時兩處要同步。
7. **`build:blocks` 產出目錄**：`inc/assets/dist/blocks/`（若測試環境未打包此目錄，WC Blocks 會 silently 不註冊付款方式）。

---

## 七、修改自查清單

- [ ] 新增 `env.` / `steps.<id>.outputs.` 引用，名稱是否拼對？
- [ ] 跨 job 走 `needs.<job>.outputs.`，Job 1 `outputs:` 區塊同步新增？
- [ ] Stage gating 改動時，B/D/E/F/G 五段一起看
- [ ] Prompt / 留言模板的 `{{ISSUE_NUM}}` placeholder 有對應？
- [ ] Secrets 是否在 repo settings 備齊？
- [ ] 若修改 `.wp-env.json`，同步確認 `env-cwd` 路徑與 AI 驗收 prompt 中的 port
- [ ] 前端 build 指令（Vue + Blocks）是否兩個都有執行？
