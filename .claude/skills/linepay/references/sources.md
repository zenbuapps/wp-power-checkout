# 來源與查證紀錄

擷取日：2026-11-18。擷取方式：developers-pay.line.me 為 Docusaurus 站，巢狀參數表（Ant Design 可展開列）與 Tabs 只在瀏覽器端渲染，
因此以 agent-browser 開啟每頁、點開所有 Tab 與巢狀列後序列化成 markdown。原始存檔（zh-TW + en 各 39 頁）：
`.tmp/[金流] LINE Pay/官方文件/`；完整 URL 清單：`.tmp/[金流] LINE Pay/來源清單.md`。

## 官方文件（主要依據）

| 範圍 | URL |
|---|---|
| 文件首頁 / sitemap | https://developers-pay.line.me/ 、https://developers-pay.line.me/sitemap.xml |
| Online API v4（總覽 + 10 端點 + 重定向頁） | https://developers-pay.line.me/zh/online-api-v4 |
| Online API v3（總覽 + 10 端點 + 重定向頁） | https://developers-pay.line.me/zh/online-api-v3 |
| 線上付款指南（7 頁） | https://developers-pay.line.me/zh/online |
| Sandbox | https://developers-pay.line.me/zh/sandbox |
| 註冊合作商店 | https://developers-pay.line.me/zh/join-as-merchant |
| FAQ / Glossary / API change log / Contact / Logo | https://developers-pay.line.me/zh/faq 等 |
| Offline API v2（僅核對 header 名稱） | https://developers-pay.line.me/zh/offline-api-v2 |

## 官方 AI 工具 / Claude Skill / MCP 查證結果：查無

| 查過的地方 | 結果 |
|---|---|
| GitHub `line` org 全部 repo（`gh api orgs/line/repos`）過濾 pay / mcp / skill | 只有 `line-bot-mcp-server`（Messaging API 用，非 LINE Pay）、`mcp-abc-user-feedback`；**無 LINE Pay MCP / skill** |
| GitHub 帳號 `linepay`（User，0 public repo）、`line-pay`、`linepay-tw`、`LINEPayTW`、`line-pay-tw`（皆不存在） | 無 |
| `gh search repos "line pay" --owner line`、`"linepay mcp"`、`"line pay mcp"`、`"linepay skill"` | 無官方結果 |
| `gh search code "X-LINE-Authorization" --filename SKILL.md` | 只有第三方：`zenbuapps/zenbu-powers` 的 `skill-sets/line-pay`（本團隊外掛的通用版）、`aibot88/sec_skill_store`、`Moksa1123/taiwan-ecommerce-toolkit`——**皆非 LINE 官方** |
| developers-pay.line.me 的 `/llms.txt`、`/llms-full.txt`、`/.well-known/mcp.json` | 403 / 不存在 |
| 文件站導覽列（Guides / References / Resources / Help） | 無 AI / MCP 頁面 |
| Web 搜尋 "LINE Pay MCP server official" | 無；僅第三方 paymentproviders.io 目錄的 MCP |

## 官方 SDK

官方文件未提供任何 SDK（全部以 curl / fetch 範例呈現）。GitHub 上的 `yidas/line-pay-sdk-php`、`nkjm/line-pay`、`enylin/line-pay-merchant` 等皆為社群套件；本 skill 只在「無 body POST 的簽章字串」一點引用 yidas 的實作作為旁證。

## 實測（無憑證）

`.tmp/[金流] LINE Pay/sandbox-無憑證探測.txt`：header 檢查順序、`1104` / `1106` / `2104` 實際訊息、錯誤 body 格式。

## 已知的官方文件不一致

- v4 結果碼表：英文版有 `2024`、中文版有 `2042`（各缺一個）。
- 總覽頁錯誤回應範例 `resultCode`/`statusMessage` ≠ 實測 `returnCode`/`returnMessage`。
- 指南範例仍用 `/v3/` 路徑；`requestLINEPayAPI()` query 前綴寫成 `&`；無 confirmURL 指南把欄位寫成 `options.redirectUrls.confirmUrlType`。
- 預先授權付款 curl 範例 header 拼成 `X-LINE-Authorize-Nonce`；重定向頁範例 URL 拼成 `trasactionId`。
- 合作商店中心使用指南 `https://pay.line.me/file/guidebook` 與舊版整合指南 PDF（`LINE_Pay_Integration_Guide_for_Merchant-v1.1.2-TW`）皆已 404，無可下載 PDF。

## 實測（真 sandbox 憑證，2026-11-19）

`.tmp/[金流] LINE Pay/sandbox-實測紀錄-2026-11-19.md`：只打 `sandbox-api-pay.line.me`。簽章（POST / GET query / 無 body POST）三語通過；v3 / v4 付款請求 `0000`；`capture: false` 回 `2103`；提前 confirm 使交易變 `0122`；付款頁需真人 LINE 登入，confirm / refund 成功路徑未驗。
