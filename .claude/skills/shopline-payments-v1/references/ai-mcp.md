# SHOPLINE Payments — AI 輔助串接工具（MCP）

> 來源：https://docs.shoplinepayments.com/overview/intergrationGuide/AiToolMcp/ （串接指南 → 串接流程（必讀）→ AI 輔助串接工具 (MCP)）
> 頁內無其他技術連結（僅外部 AI 工具名稱）。npm registry 資訊為 2026-11-16 查詢，標註「非官方文件」。
> 爬取日期：2026-11-16

## 是什麼

- SHOPLINE Payments 提供的 **Model Context Protocol (MCP) 橋接服務**，讓 AI 工具直接搜尋 / 取得 SHOPLINE Payments 官方文件與 API 資訊。
- 型態：**STDIO ↔ HTTP 橋接器**。在本機啟動 MCP STDIO Server（供 Cursor、Claude Desktop、Cline 等 client 連線），把收到的 MCP JSON-RPC request 轉發到設定的 HTTP MCP Endpoint。
- **只提供文件查詢**，不能建立交易、查詢訂單或操作後台（目前唯一 tool 為 `get_slpayment_docs`）。

## 基本資訊

| 項目 | 值 |
|---|---|
| npm 套件 | `@shoplinepayments/slpayment-developer-mcp`（官方範例鎖 `@1.0.1`） |
| 啟動方式 | `npx -y @shoplinepayments/slpayment-developer-mcp@1.0.1` |
| 遠端 HTTP MCP Endpoint | `https://ai.shoplinepayments.com/mcp`（預設值） |
| 傳輸 | 本機 STDIO（client ↔ 橋接器），橋接器 → HTTP（Endpoint） |
| 先決條件 | Node.js **v18.0.0 以上**；任一支援 MCP 的 AI 工具 |
| 官方範例的 server 名稱 | `slpayment-mcp` |
| npm 版本（非官方文件） | `1.0.0`、`1.0.1`（latest）；bin 名 `slpayment-mcp-bridge`；`engines.node >= 18` |

## 認證

- 官方：「此橋接器透過 MCP STDIO 在本機執行，**本身不包含驗證機制**（身分驗證由你設定的 HTTP MCP Endpoint 負責處理）」。
- 官方設定範例**不需要任何 key**（不使用 merchantId / apiKey / clientKey / signKey）。
- 是否區分 sandbox / production：**官方文件未載明**（只有單一 Endpoint，查詢的是公開文件，與交易環境無關）。
- Endpoint 端是否有存取限制 / 速率限制：官方文件未載明。

## 環境參數

| 變數 | 預設 | 說明 |
|---|---|---|
| `SLPAYMENT_MCP_HTTP_URL` | `https://ai.shoplinepayments.com/mcp` | HTTP MCP Endpoint 網址 |
| `SLPAYMENT_MCP_HTTP_TIMEOUT_MS` | `30000` | 每次 request 逾時（毫秒） |

## 提供的 Tools

| Tool 名稱 | 說明（官方原文） |
|---|---|
| `get_slpayment_docs` | 取得 SHOPLINE Payments 的相關開發文件與資訊。 |

輸入參數 schema：官方文件未載明（由 MCP `tools/list` 動態提供）。

在 Claude Code 中，tool 的完整名稱為 `mcp__<你註冊的 server 名>__get_slpayment_docs`；
若依官方範例命名 `slpayment-mcp` → `mcp__slpayment-mcp__get_slpayment_docs`。
（舊版 skill 提到的 `mcp__shopline-payments-mcp__get_slpayment_docs` 是以 `shopline-payments-mcp` 為 server 名註冊時的名稱。）

## 各 AI 工具設定（官方原文照抄）

### Cursor

路徑：Cursor Settings > Tools & MCP > MCP > New MCP Server

```json
{
  "mcpServers": {
    "slpayment-mcp": {
      "command": "npx",
      "args": [
        "-y",
        "@shoplinepayments/slpayment-developer-mcp@1.0.1"
      ],
      "env": {
        "SLPAYMENT_MCP_HTTP_URL": "https://ai.shoplinepayments.com/mcp",
        "SLPAYMENT_MCP_HTTP_TIMEOUT_MS": "30000"
      }
    }
  }
}
```

儲存後**關閉所有 Cursor 行程並重新啟動**；可看 MCP server 連線狀態或直接問 AI 確認。

### Claude Desktop

路徑：Settings > Developer > Edit Config，把設定加進 JSON 設定檔，存檔後完全關閉所有 Claude Desktop 行程再重啟。

官方頁面此段的 JSON 原文如下：

```json
{
  "mcpServers": {
    "slpayment-mcp": {
      "command": "npx",
      "args": [
        "-y",
        "@shoplinepayments/slpayment-developer-mcp@1.0.1"
      ],
      "env": {
        "SLPAYMENT_MCP_HTTP_URL": "[https://ai.shoplinepayments.com/mcp](https://ai.shoplinepayments.com/mcp)",
        "SLPAYMENT_MCP_HTTP_TIMEOUT_MS": "30000"
      }
    }
  }
}
```

> **官方文件筆誤**：Claude Desktop 與 Cline 範例的 `SLPAYMENT_MCP_HTTP_URL` 值被寫成 Markdown 連結語法 `[https://...](https://...)`，直接複製會變成無效 URL。
> 請改用 Cursor 範例的純 URL `"https://ai.shoplinepayments.com/mcp"`（或乾脆省略該變數，使用預設值）。

### Cline

路徑：Cline 擴充套件 > MCP Servers > Configure MCP Servers，加入與上方**相同**的 JSON（官方原文同 Claude Desktop 段，含相同筆誤），儲存後重啟 IDE。

### Claude Code

**官方文件未提供 Claude Code 的設定方式。** 以下為依官方 JSON（command / args / env）等價轉換的寫法，非官方內容：

專案層 `.mcp.json`（與官方 JSON 結構相同）：

```json
{
  "mcpServers": {
    "slpayment-mcp": {
      "command": "npx",
      "args": ["-y", "@shoplinepayments/slpayment-developer-mcp@1.0.1"],
      "env": {
        "SLPAYMENT_MCP_HTTP_URL": "https://ai.shoplinepayments.com/mcp",
        "SLPAYMENT_MCP_HTTP_TIMEOUT_MS": "30000"
      }
    }
  }
}
```

或 CLI：

```bash
claude mcp add slpayment-mcp \
  -e SLPAYMENT_MCP_HTTP_URL=https://ai.shoplinepayments.com/mcp \
  -e SLPAYMENT_MCP_HTTP_TIMEOUT_MS=30000 \
  -- npx -y @shoplinepayments/slpayment-developer-mcp@1.0.1
```

Windows 原生（非 WSL）執行 `npx` 時，一般需以 `cmd /c npx ...` 包一層（Claude Code 慣例，非 SHOPLINE 官方內容）。

### 其他 MCP client

官方只說「任何支援 MCP 的 AI 工具」皆可，沿用同一組 command / args / env 即可；其他工具的設定路徑官方文件未載明。

## 官方建議的提問範例

- 「請幫我總結最新文件中的 SHOPLINE Payments API。」
- 「整合 SHOPLINE Payments 需要哪些步驟？」
- 「請說明 API 的認證與簽名（Signature）需求，以及 Callback 的驗證流程。」

## 使用時機（給 AI Agent）

- 本 skill 已涵蓋的參數、範例、錯誤碼、沙盒資料**以本 skill 為準**（本 skill 已處理官方頁面上的「參考」彈出列舉與已知筆誤）。
- 以下情況才用 MCP：確認 2026-11-16 之後的新 API / 欄位、平台專用 API（分帳 / 轉帳 / 結算 / KYC / Connect）、本 skill 標註「官方文件未載明」處是否已補。
- MCP 回傳內容與本 skill 衝突時，以較新的官方內容為準並回報差異。

## 限制與注意

1. 唯一 tool 是文件查詢；**不能**用它呼叫交易 API 或讀取特店資料。
2. 需要本機 Node.js ≥ 18 與 `npx` 可連 npm registry；首次啟動會下載套件。
3. 官方範例鎖版 `@1.0.1`；更新版本請自行評估。
4. 回應延遲受 `SLPAYMENT_MCP_HTTP_TIMEOUT_MS` 限制（預設 30 秒）。
5. Endpoint 服務等級、可用性、隱私政策：官方文件未載明。
