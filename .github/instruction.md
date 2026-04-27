# `.github/` 目錄架構指引（Power Checkout）

> **用途**：本文件為 Power Checkout 專案的 `.github/` 目錄使用說明，並記錄從 **power-course 範本** adapt 過來的差異項。
>
> **範本來源**：完整設計哲學、目錄結構原則、Cookbook、Gotchas 請參考：
>
> `../../../power-course/.github/instruction.md`
>
> **核心哲學**：CI-driven AI Agent Pipeline — 透過 workflow 層級串接多個 agent（clarifier → planner → tdd-coordinator → browser-tester），每個 agent 獨立 step 執行，agent 之間以 **Git commit**、**GitHub Issue comment**、**step outputs** 為橋樑，不依賴 sub-agent 交接。

---

## 一、目錄結構

```
.github/
├── workflows/
│   ├── pipe.yml          # 核心 pipeline（claude + integration-tests 兩 job）
│   ├── pipe.md           # pipe.yml 的中文規格書（已 adapt）
│   ├── issue.yml         # Issue 需求展開（issue-creator，通用檔）
│   └── release.yml       # 推 v* tag 時自動打包並建立 GitHub Release
├── act/
│   └── test.yml          # 本機 act 測試用（驗證多 job 結構；刻意放在 workflows/ 之外避免線上誤觸發）
├── actions/
│   └── claude-retry/
│       └── action.yml    # 3 次重試 + 指數退避（通用檔）
├── prompts/              # 通用檔，直接來自範本
│   ├── clarifier-pipeline.md
│   ├── clarifier-interactive.md
│   ├── planner.md
│   └── tdd-coordinator.md
├── templates/            # 通用檔，直接來自範本
│   ├── test-result-comment.md
│   ├── acceptance-comment.md
│   └── pipeline-upgrade-comment.md
├── scripts/
│   └── upload-to-bunny.sh  # 通用檔
├── ISSUE_TEMPLATE/       # GitHub Issue 模板（由用戶維護）
└── instruction.md        # 本文件
```

---

## 二、Plugin 特性（影響 adapt 策略）

| 項目 | 值 | 備註 |
|------|-----|------|
| Plugin slug / text domain | `power_checkout`（底線） | `.wp-env.json` 掛載為 `wp-content/plugins/power-checkout` |
| PHP namespace | `J7\PowerCheckout` | PSR-4 → `inc/classes/` |
| 主檔 | `plugin.php`（Singleton + PluginTrait） | 使用 `'lc' => 'ZmFsc2'` hardcoded 授權碼（**非** capability-based） |
| 前端架構 | 雙 bundle：Vue 3（主 app）+ React（WC Blocks） | `vite.config.ts` / `vite.config.block.ts` |
| 前端 build 指令 | `pnpm run build && pnpm run build:blocks` | 本 CI 已對齊 |
| 後端結構 | DDD：`inc/classes/Domains/{Payment,Invoice,Settings}/` | Payment 走 WC_Payment_Gateway，Invoice 走 IInvoiceService |
| 管理介面 | WC Settings Tab `admin.php?page=wc-settings&tab=power_checkout_wc_settings` | Vue HashRouter：`#/payments`、`#/invoices`、`#/logistics`、`#/settings` |
| 測試 | PHPUnit（`tests/Integration/`，API_MODE=mock） + Playwright E2E（`tests/e2e/`） | 整合測試入口命令：`composer test` |
| Specs | `specs/` 目錄（含 activities、features、api.yml、erm.dbml、actors、ui、clarify、open-issue）走 aibdd | 與範本一致 |
| LC Bypass 機制 | **無 `'capability' => 'manage_woocommerce'`** | `tests/e2e/helpers/lc-bypass.ts` 用容忍多空格 regex 注入 `'lc' => false` 到 plugin.php，CI K 段直接執行該 helper |
| wp-env port | 8891（frontend）/ 8893（tests） | `.wp-env.json` 顯式設 `"port": 8891, "testsPort": 8893` |

---

## 三、與 power-course 範本的差異（modified / removed）

### Removed

| Step | 原因 |
|------|------|
| 範本 inline 的 capability-based plugin.php 注入 | 本專案 `plugin.php` 無 `'capability' => 'manage_woocommerce'`，無法直接套用；改為呼叫 `tests/e2e/helpers/lc-bypass.ts` 的 `applyLcBypass()`，以容忍多空格的 regex 找 `'callback' => [ Bootstrap::class, 'instance' ],` 並注入 `'lc' => false` |

### Modified

| 位置 | 範本 | 本專案 |
|------|------|--------|
| `pipe.yml` wp-env mapping | `wp-content/plugins/wp-power-course` | `wp-content/plugins/power-checkout` |
| `pipe.yml` AI 驗收 prompt 環境段 | LMS + `Admin SPA: ?page=power-course#/` | 結帳整合描述 + `page=wc-settings&tab=power_checkout_wc_settings` + HashRouter 路由清單 |
| `pipe.yml` AI 驗收 port | `http://localhost:8895` | `http://localhost:8891`（與 `.wp-env.json` 顯式設定一致） |
| `pipe.yml` 前端 build 指令 | `pnpm run build && pnpm run build:wp` | `pnpm run build && pnpm run build:blocks` |
| `pipe.yml` / `act/test.yml` LC Bypass step | plugin.php 注入（capability-based） | 執行 `tests/e2e/helpers/lc-bypass.ts`（容忍多空格 regex 找 callback line 注入），同步更新 `.e2e-progress.json` 作為遙測 |
| `pipe.md` | （範本對應 power-course LMS） | 已重寫含本專案 adapt 差異對照表 |

### Kept（通用檔，未動）

- `actions/claude-retry/action.yml`
- `prompts/*.md`（4 份）
- `templates/*.md`（3 份）
- `scripts/upload-to-bunny.sh`
- `workflows/issue.yml`

---

## 四、Secrets 備齊清單

| Secret | 必要性 | 用途 |
|--------|--------|------|
| `CLAUDE_CODE_OAUTH_TOKEN` | **必備** | Claude Code Action 授權 |
| `BUNNY_STORAGE_HOST` | AI 驗收用 | CDN 上傳 API host |
| `BUNNY_STORAGE_ZONE` | AI 驗收用 | CDN storage zone |
| `BUNNY_STORAGE_PASSWORD` | AI 驗收用 | CDN API key |
| `BUNNY_CDN_URL` | AI 驗收用 | CDN 公開 URL（回寫 comment 時替換圖片連結） |

若不跑 AI 驗收（僅用 `@claude` 澄清或 `@claude 開工` 而不走 `全自動`），`BUNNY_*` 可暫緩，但 `CLAUDE_CODE_OAUTH_TOKEN` 是必裝。

---

## 五、已知 TODO / 待人工微調

- [x] **wp-env port 對齊**：`.wp-env.json` 已顯式設 `"port": 8891, "testsPort": 8893`，與 CI prompt 中的 `localhost:8891` 一致。
- [ ] **Admin slug 驗證**：WC Settings tab `power_checkout_wc_settings` 是從 `plugin.php::redirect_to_wc_setting()` 與 `inc/classes/Domains/Settings/Services/SettingTabService.php` 確認的。若重構 tab 名稱需同步改 pipe.yml 的 AI 驗收 prompt。
- [x] **`lc-bypass.ts` 機制確認**：helper 直接修改 `plugin.php` 注入 `'lc' => false`（regex 容忍多空格），與 `.e2e-progress.json` 解耦；CI K 段執行該 helper 並 grep 驗證注入結果。
- [ ] **`build:blocks` 產出路徑 `inc/assets/dist/blocks/`**：若 CI 執行完發現付款方式沒註冊到 WC Blocks checkout，請檢查此目錄是否產出檔案。
- [ ] **Marketplace 遷移**：目前 plugin_marketplaces 指向 `j7-dev/wp-workflows`；若遷移到 `p9-cloud/wp-workflows` 等新組織，需同步改 `pipe.yml`、`issue.yml`、`actions/claude-retry/action.yml` 與所有 workflows 中的 URL。

---

## 六、常用指令

```bash
# 本機 act 驗證（Windows 範例）
act workflow_dispatch -W .github/act/test.yml \
  --container-architecture linux/amd64 \
  -P ubuntu-latest=catthehacker/ubuntu:act-latest \
  --container-options "--privileged" \
  --artifact-server-path "C:/Users/$env:USERNAME/AppData/Local/Temp/act-artifacts"

# 觸發 pipeline（在 Issue 留言）
@claude                # 互動澄清
@claude 開工            # clarifier → planner → tdd-coordinator
@claude 全自動          # 上面 + 自動整合測試 + AI 驗收 + PR
@claude PR             # 跳過 claude 實作，直接於現有分支跑測試 + AI 驗收 + PR
```

詳細 step 流程請參考 `workflows/pipe.md`。
