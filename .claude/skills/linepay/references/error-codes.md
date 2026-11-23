# LINE Pay Online API — 結果碼（returnCode）全表與處理策略

> 來源：https://developers-pay.line.me/zh/online-api-v4#result-code （中文）、https://developers-pay.line.me/online-api-v4#result-code （英文）、
> 各端點頁的 Related errors 清單、FAQ。擷取日 2026-11-18。

## 基本規則

- HTTP status **一律 200**；成敗只看 body `returnCode`（**字串**，4 碼，例如 `"0000"`、`"1172"`）。
- 失敗時 body 只有 `returnCode` + `returnMessage`，**沒有 `info`**。
- 沒有專屬訊息的結果碼，`returnMessage` 會是 `-`（連字號）。
- 官方總覽頁「錯誤回應」範例寫成 `{"resultCode": 1104, "statusMessage": "..."}`，與實測不符；實測為 `{"returnCode":"1104","returnMessage":"Merchant not found."}`（見 `.tmp/[金流] LINE Pay/sandbox-無憑證探測.txt`）。

## 全表（v4；v3 相同但沒有 `2042`）

| returnCode | 意義 | 處理建議 |
|---|---|---|
| `0000` | 成功。若為「查詢付款請求狀態」API，代表顧客**尚未完成** LINE Pay 認證（實測訊息 `reserved transaction.`） | — |
| `0110` | （狀態查詢）顧客已完成 LINE Pay 認證，可呼叫 confirm（實測訊息 `authentication is done.`） | 立刻 confirm（約 20 分鐘內，見 `1180`） |
| `0121` | （狀態查詢）顧客取消付款，或超過 LINE Pay 認證等待時間（實測訊息 `canceled transaction`；2026-11-19 sandbox：建單後 19 分 17 秒仍 `0000`，21 分 45 秒變 `0121`） | 訂單標為取消/逾時 |
| `0122` | （狀態查詢）付款失敗（實測訊息 `failed transaction`；提前 confirm 也會導致此狀態） | 訂單失敗 |
| `0123` | （狀態查詢）付款完成（實測訊息 `completed transaction`；confirm 成功後查狀態即回此碼） | 已付款（勿重複 confirm） |
| `1101` | 該用戶不是 LINE Pay 用戶 | 提示改用其他付款方式 |
| `1102` | 該用戶目前無法使用 LINE Pay 交易 | 同上 |
| `1104` | 商店未在合作商店中心註冊；**credentials 錯誤**（channelId 不存在、sandbox/production 混用） | 檢查 Channel ID 與 host 是否同一環境 |
| `1105` | 該合作商店目前無法使用 LINE Pay | 聯絡 LINE Pay |
| `1106` | 請求標頭有錯（缺 header 或 **MAC 簽章不符**） | 見 `authentication.md` §4 |
| `1110` | 信用卡無法正常使用 | 提示換卡 |
| `1124` | 金額訊息有誤（官方僅此一句）。注意：付款**請求**時 `amount` ≠ packages 加總，2026-11-19 sandbox 實測回的是 **`2101`**，不是 1124；1124 的實際觸發點未驗 | 檢查金額 |
| `1141` | 帳戶狀態有問題。EPI 交易：商家可能未開通 EPI 支付方式；Preapproved：用戶可能已刪除該支付方式，需重新取得 regKey | Preapproved 時 regKey 作廢，重新綁定 |
| `1142` | 餘額不足 | 提示 |
| `1145` | 付款進行中 | 稍後查狀態，勿重送 |
| `1150` | 無交易歷史（實測訊息 `Transaction record not found.`；**尚未 confirm 的付款請求查明細也回 1150**） | 檢查 transactionId；未授權交易改用狀態查詢 API |
| `1152` | 有相同交易歷史 | 視為已處理，查 payment details 確認。注意：**重複 confirm 實測回的是 `1172`，不是 1152** |
| `1153` | 付款請求金額與請款金額不同 | confirm 時帶與 request 相同的 amount |
| `1154` | 無法使用設定為預先授權付款的付款方式 | 用戶需重新綁定 |
| `1155` | 交易 ID 有誤 | 檢查是否 BigInt 失真 |
| `1159` | 無付款請求訊息 | transactionId 錯或已過期 |
| `1163` | 無法退款（超過可退款期限） | 線下處理 |
| `1164` | 超出可退款金額 | 檢查已退金額。實測：已全數退完後再退（不論帶金額或全額）回的是 `1165`，不是 1164 |
| `1165` | 已退款的交易（實測訊息 `The transaction has already been refunded.`；2026-11-19 sandbox：部分退 + 退剩餘後，再退 1 元或全額都回 1165） | 視為已完成 |
| `1169` | 須在 LINE Pay 中選擇付款方式並驗證密碼（顧客未完成認證就呼叫 confirm） | 等 confirmUrl 被呼叫或狀態 `0110` 後再 confirm。**2026-11-19 sandbox 實測：提前 confirm 得 1169 後，該付款請求狀態由 `0000` 變 `0122 failed transaction`，整筆作廢，顧客必須重新付款** |
| `1170` | 會員帳戶餘額發生變化 | 重試或請顧客重新付款 |
| `1172` | 已存在相同訂單號碼（`orderId`）的交易 | 2026-11-19 sandbox 實測：(1) **重複 confirm 同一筆** → `1172 Already confirmed transaction`；(2) 該 orderId **已 confirm 後**再用它建付款請求 → `1172 Existing same orderId.`；(3) 尚未 confirm 前，同 orderId 連建兩次請求都 `0000`（不擋）。confirmUrl handler 遇 1172 應視為「已付款」並查明細確認 |
| `1177` | 超出可查看的最多交易數量（100 筆；2026-11-19 實測 101 筆即回此碼） | 查詢明細分批（≤100） |
| `1178` | 合作商店不支援該貨幣 | 台灣商店用 `TWD` |
| `1179` | 無法處理該狀態（實測訊息 `Status can not be processed.`；2026-11-19 sandbox：對自動請款完成的交易 void 回 1179） | 依 payStatus 選 void / refund |
| `1180` | 已超過付款期限（實測訊息 `Expired the payment date`）。**2026-11-19 sandbox 實測**：顧客已完成認證、狀態查詢仍回 `0110`，但距建立請求約 20 分 26 秒才 confirm → `1180`，交易隨即變 `0122` 作廢。推論有效期約 20 分鐘（官方未寫精確值） | 重新發起付款請求；confirmUrl handler 要立即 confirm，輪詢補單要在約 20 分鐘內完成 |
| `1183` | 付款金額必須大於設定的最低金額 | — |
| `1184` | 付款金額必須小於設定的最高金額 | — |
| `1190` | 無預先授權付款密鑰（regKey 不存在；實測訊息 `The regKey does not exist.`） | 重新綁定 |
| `1193` | 預先授權付款密鑰已逾期（實測訊息 `The regKey expired.`；2026-11-19 sandbox：expire 之後 Check RegKey、Pay Preapproved、再 expire 都回 1193，不是 1190） | 重新綁定 |
| `1194` | 合作商店不支援預先授權付款 | 台灣需事先申請 Preapproved |
| `1198` | API 呼叫請求重複 | 冪等處理：查明細確認結果 |
| `1199` | 內部請求發生錯誤 | 查明細後再決定是否重試 |
| `1280` | 信用卡付款時發生臨時錯誤 | 可重試 |
| `1281` | 信用卡付款時發生錯誤 | — |
| `1282` | 信用卡授權時發生錯誤 | Preapproved：regKey 作廢 |
| `1283` | 有不當使用疑慮，付款被拒絕 | Preapproved：regKey 作廢 |
| `1284` | 信用卡付款暫時暫停 | Preapproved：regKey 作廢 |
| `1285` | 信用卡付款訊息缺失 | Preapproved：regKey 作廢 |
| `1286` | 信用卡付款訊息中有錯誤訊息 | Preapproved：regKey 作廢 |
| `1287` | 信用卡已過期 | Preapproved：regKey 作廢 |
| `1288` | 信用卡帳戶餘額不足 | — |
| `1289` | 超出信用卡額度 | — |
| `1290` | 超出信用卡單筆付款額度 | Preapproved：regKey 作廢 |
| `1291` | 該卡已被通報失竊 | Preapproved：regKey 作廢 |
| `1292` | 該卡已停用 | Preapproved：regKey 作廢 |
| `1293` | CVN 輸入錯誤 | Preapproved：regKey 作廢 |
| `1294` | 該卡已被列入黑名單 | Preapproved：regKey 作廢 |
| `1295` | 信用卡號碼錯誤 | Preapproved：regKey 作廢 |
| `1296` | 無法處理此金額 | — |
| `1298` | 該卡被拒絕 | — |
| `190X`（`1900`、`1902`…） | 臨時錯誤，請稍後再試 | void / refund 回 `1900`/`1902`/`1999` 時可重試 |
| `2024` | 超過商家單筆、每日或每月收款上限（**僅英文版表格列出**） | 聯絡 LINE Pay 調整額度 |
| `2042` | EPI 交易退款失敗：商家退款準備金不足（**v4 新增，僅中文版表格列出**；v4 退款 Related errors 有列） | 補足 EPI 退款準備金後重試 |
| `2101` | 參數錯誤。2026-11-19 實測訊息會附原因，例：`Parameter error. [form.amount != sum(packages[].amount) + sum(packages[].userFee) + shippingFee]` | 讀 `returnMessage` 中括號內的原因 |
| `2102` | JSON 資料格式錯誤。2026-11-19 實測：列舉欄位給非法值（如 `regPayRequest.recurringPeriod: "DAILY"`）也回 2102 | 檢查 body 與列舉值 |
| `2103` | `` Parameter is not allowed. [capture:`false`] `` 或 `Parameter is not allowed. [creditCardAuth]`（**文件未列，2026-11-19 sandbox 實測**：商店未開通的參數——付款請求或 Pay Preapproved 帶 `capture: false`（v3 / v4 皆同）、Check RegKey 帶 `creditCardAuth=true`） | 向 LINE Pay 申請分開請款，或改用自動請款 |
| `2104` | `Request method 'GET' not supported`（**文件未列，實測**：用錯 HTTP method） | 改用正確 method |
| `9000` | 內部錯誤 | 查明細確認狀態，再決定是否重試 |

> 英文版與中文版 v4 結果碼表不一致：英文有 `2024`、無 `2042`；中文有 `2042`、無 `2024`。兩者都可能出現，程式應同時處理。

## 官方明載的特殊語意

| 情境 | 規則 | 出處 |
|---|---|---|
| **Capture** 回 `1199` 或 `1280`–`1298` | 「付款將自動取消」——授權已被作廢，**不可再 capture**，需重新發起付款 | capture 頁 returnCode 說明 |
| **Void / Refund** 回 `1900`、`1902`、`1999` | 可以重試呼叫同一 API | void / refund 頁 returnCode 說明 |
| **預先授權付款請求**（`POST …/preapprovedPay/{regKey}/payment`）回 `1141`、`1282`–`1287`、`1290`–`1295` | 該 regKey 被註銷，需重新走 PREAPPROVED 付款請求取得新 regKey（expire 端點的 Related errors 只有 1104 1105 1106 1190 1193，不適用此規則；expire 頁 returnCode 說明雖照抄同一句，視為文件複製殘留） | request-preapproved-payment 頁 + implement-preapproved-payment 指南 |
| 狀態查詢 API | `0000` 不代表付款成功，而是「尚未認證」 | check-payment-request-status 頁 |

## 各端點可能回的錯誤（Related errors，v4）

| 端點 | Related errors |
|---|---|
| `POST /v4/payments/request` | 1104 1105 1106 1124 1145 1172 1178 1183 1194 2101 2102 9000 |
| `GET /v4/payments/requests/{transactionId}/check` | 1104 1105 9000 |
| `POST /v4/payments/{transactionId}/confirm` | 1101 1102 1104 1105 1106 1110 1124 1141 1142 1150 1152 1153 1159 1169 1170 1172 1180 1198 1199 1280–1296 1298 9000 |
| `POST /v4/payments/authorizations/{transactionId}/capture` | 1104 1105 1106 1150 1155 1170 1172 1179 1183 1184 1198 1199 1280–1296 1298 9000 |
| `POST /v4/payments/authorizations/{transactionId}/void` | 1101 1102 1104 1105 1106 1150 1155 1165 1170 1198 1199 1900 1902 1999 9000 |
| `GET /v4/payments` | 1104 1105 1106 1150 1177 9000 |
| `POST /v4/payments/{transactionId}/refund` | 1101 1102 1104 1105 1106 1124 1150 1155 1163 1164 1165 1179 1198 1199 2042 9000（v3 無 2042） |
| `GET /v4/payments/preapprovedPay/{regKey}/check` | 1101 1102 1104 1105 1106 1141 1154 1190 1193 |
| `POST /v4/payments/preapprovedPay/{regKey}/payment` | 1101 1102 1104 1105 1106 1110 1124 1141 1142 1150 1152 1153 1159 1169 1170 1172 1180 1198 1199 1280–1296 1298 9000 |
| `POST /v4/payments/preapprovedPay/{regKey}/expire` | 1104 1105 1106 1190 1193 |

（`1280–1296` 指 1280 到 1296 全部，官方逐一列出。）

## 建議的冪等 / 重試策略（本 skill 建議，非官方規定）

1. confirm / capture / refund 遇 timeout 或 `9000`/`1199`/`1198`：**先 `GET /v4/payments?transactionId=…` 查明細**，依 `payStatus` / `refundList` 判斷是否已生效，再決定要不要重送；不要盲目重送 confirm（實測會得 `1172 Already confirmed transaction`）。
2. `orderId` 對 LINE Pay 是唯一鍵（`1172`，但付款請求階段不擋，見上表）；重新付款請產生新的 orderId（例如 `{訂單號}-{嘗試次數}`），避免 confirm 時才撞號。
3. confirm 只在「confirmUrl 被呼叫」或「狀態查詢回 `0110`」後呼叫；太早呼叫會得 `1169`，**而且該筆付款請求會直接變成 `0122` 失敗**（2026-11-19 sandbox 實測）。不能用 confirm 探測是否已付款，要探測請用查詢付款請求狀態 API。
