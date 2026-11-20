# Online API v3 與 v4 差異

> 來源：逐頁比對 https://developers-pay.line.me/zh/online-api-v3/* 與 /zh/online-api-v4/*（2026-11-18，原文在 `.tmp/[金流] LINE Pay/官方文件/zh-TW/`），
> 以及 https://developers-pay.line.me/zh/api-change-log （2025-11 條目）。

## 版本定位

- **v4 是目前最新版**（2025-11 加入，change log 說明 2025-05 起 sandbox 可測）。v3 **沒有被標為 deprecated**，官方說「若不需要即時從交易結果得知清算及撥款單位，可繼續使用既有的 API」。
- v4 存在的主因是台灣《電子支付機構管理條例》：交易可能由 **TSP**（第三方支付公司，現行 LINE Pay 台灣）或 **EPI**（電子支付機構）清算與撥款，v4 回應多了 `info.paymentProvider` 讓商家即時得知。
- 認證方式（HMAC-SHA256、三個 X-LINE header）、host、路徑結構 **完全相同**，只差 `/v3/` → `/v4/`。
- Sandbox 不支援模擬 EPI 線上交易：Online API 的 `paymentProvider` 在 sandbox **固定回 `"TSP"`**。

**新串接一律用 v4。** 既有 v3 系統若要接 EPI 資訊才需要升級。

2026-11-19 sandbox 實測：v3 付款請求仍回 `0000`，回應格式與 v4 相同；v3 狀態查詢正常；v3 對 `capture: false` 同樣回 `2103`。

## 端點對照（10 支，路徑結構相同）

| 功能 | v3 | v4 |
|---|---|---|
| 付款請求 | `POST /v3/payments/request` | `POST /v4/payments/request` |
| 查詢付款請求狀態 | `GET /v3/payments/requests/{transactionId}/check` | `GET /v4/payments/requests/{transactionId}/check` |
| 付款授權 | `POST /v3/payments/{transactionId}/confirm` | `POST /v4/payments/{transactionId}/confirm` |
| 請款 | `POST /v3/payments/authorizations/{transactionId}/capture` | `POST /v4/payments/authorizations/{transactionId}/capture` |
| 取消授權 | `POST /v3/payments/authorizations/{transactionId}/void` | `POST /v4/payments/authorizations/{transactionId}/void` |
| 查詢付款明細 | `GET /v3/payments` | `GET /v4/payments` |
| 退款 | `POST /v3/payments/{transactionId}/refund` | `POST /v4/payments/{transactionId}/refund` |
| 檢查 regKey | `GET /v3/payments/preapprovedPay/{regKey}/check` | `GET /v4/payments/preapprovedPay/{regKey}/check` |
| 預先授權付款 | `POST /v3/payments/preapprovedPay/{regKey}/payment` | `POST /v4/payments/preapprovedPay/{regKey}/payment` |
| 刪除 regKey | `POST /v3/payments/preapprovedPay/{regKey}/expire` | `POST /v4/payments/preapprovedPay/{regKey}/expire` |

## 欄位差異（逐頁 diff 結果）

### 付款請求 request body

| 欄位 | v3 | v4 |
|---|---|---|
| `amount` 說明 | = Σ`packages[].amount` **+ 手續費 `packages[].userFee`** | = Σ`packages[].amount`（無手續費） |
| `packages[].userFee` | 有（顧客負擔手續費） | **移除** |
| `packages[].amount` 說明 | Σ price×quantity **+ userFee** | Σ price×quantity |
| `options.familyService.addFriends[].type` / `.idList[]` | 有（付款時加 LINE 官方帳號好友，type 僅 `"lineAt"`） | **移除** |
| `options.regPayRequest.*` | 無 | **新增**（定期定額規則，見 flows-and-status §6） |
| `options.display.locale` | `en/ko/th/zh_TW/zh_CN` | 多列 `ja` |
| `options.events[]` | 標 TW only | 未標 |
| `options.extra.promotionRestriction` | 「點數限制資訊」、`rewardLimit` = 不可回饋點數的金額 | 「促銷限制訊息」、`rewardLimit` = 不適用於促銷的金額（語意相同，措辭更新） |

### 回應

| 端點 | v4 新增 / 差異 |
|---|---|
| confirm | 新增 `info.paymentProvider`（必含）；v3 有 `info.packages[].userFeeAmount`，v4 文件移除（**但 2026-11-19 sandbox 實測 v4 confirm 與明細仍回 `userFeeAmount: 0`**，解析時容忍此欄位）；v3 範例 `authorizationExpireDate` 是 `"Jun 2, 2023 7:49:58 PM"` 格式，v4 範例為 ISO 8601 `"2023-05-08T02:53:43Z"` |
| capture | 新增 `info.paymentProvider` |
| 查詢付款明細 | 新增 `info[].paymentProvider`；移除 `info[].packages[].userFee` |
| 預先授權付款 | 新增 `info.paymentProvider` |
| check regKey | v3 文件寫 `creditCardAuth` 預設 `true`（TW only）；v4 文件未寫預設值 |
| 結果碼 | v4 新增 `2042`（EPI 交易退款準備金不足），退款 Related errors 也只有 v4 列 |
| 重定向頁面 | v3 說 IP 白名單是 `confirmUrlType=SERVER` 情境；v4 改寫成「如果頁面以 IP 控管存取」一般性說法；IP 清單相同 |

其餘端點（查詢付款請求狀態、取消授權、刪除 regKey）兩版完全相同。

## 遷移 v3 → v4 檢查清單

1. 全部 apiPath 的 `/v3/` 改 `/v4/`（簽章 message 內的 apiPath 也要跟著改）。
2. 移除 `packages[].userFee`、`options.familyService`；若曾把手續費加在 `amount`，改為併入商品價格。
3. 解析 confirm / capture / details / preapproved 回應時保存 `paymentProvider`（null → 視為 `TSP`）。
4. 退款流程處理 `2042`（EPI 退款準備金不足）。
5. `authorizationExpireDate` 以 ISO 8601 解析。

## 其他（Online API 範圍外，僅供辨識）

- **Offline API**（實體 POS 掃顧客「我的條碼」，`/v4/payments/oneTimeKeys/pay` 等）：v4 / v2.4 / v2，本 skill 不涵蓋；Offline v2/v2.4 用 IP allowlist 而非 HMAC，Offline v4 改用與 Online 相同的 HMAC 認證。
- `X-LINE-ChannelSecret` 明文 header 是 **Offline API v2 / v2.4** 的認證方式（https://developers-pay.line.me/zh/offline-api-v2 ，已驗證）；網路上舊教學把它套在 Online API 是錯的。Online API v3/v4 只用 HMAC 簽章，secret 不出現在 header。現行文件站已無 Online API v2。
- 2025-05 起移除日本專用的 Deposit API 與日本專用欄位（LINE Pay 日本服務終止）。
