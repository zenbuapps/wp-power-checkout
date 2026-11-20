# 續期收款（定期定額）— 統一客樂得多元支付平台 V1.28.2

週期性自動扣款。採「一次付清」方式進行每期授權，故可對單期做查詢/取消授權/請款/取消交易（用 COCS 對應 cmd，見 api-reference.md）。
每期扣款完成後 PAYUNi 會寄系統通知信給消費者，務必填正確 `payer_email`。訂單號碼系統會依期別於尾碼編號，故僅能用英數、勿用符號。

四個獨立端點（**不使用 `cmd` 欄位**），皆 `POST` + `Content-Type: application/json` + `Authorization: Bearer {token}`：

| 端點 | 功能 |
|------|------|
| `/api/Period` | 新增續期收款訂單 |
| `/api/Period/MdfStatus` | 狀態修改（啟用/暫停/終止/重新授權）|
| `/api/Period/Exchange` | 授權卡號修改（換卡）|
| `/api/Period/Modify` | 訂單內容修改（PDF 原文印為 `/api/Period/ Modify`，含空格研判排版誤植）|

> **訂單號碼體系**：`cust_order_no`（送單，續期收款單號）→ 系統回傳 `mer_trade_no`（商店訂單編號，**後續整批操作用**）與 `cust_order_no`（續期訂單號碼，首期為 `_1` 結尾，其後 `_2`、`_3`…）。MdfStatus/Exchange/Modify 都以 `mer_trade_no` 定位訂單。

---

## 1. 新增續期收款訂單 `POST /api/Period`

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cust_id` | string | 20 | Y | 契客金流代號 |
| `cust_order_no` | string | 25 | — | 續期收款單號；僅英數；未輸入則系統自動編號。續期訂單號碼會依期別於尾碼加 `_1`、`_2`、`_3`… |
| `period_amount` | number | — | Y | 每期金額；限制依合約（預設 100~100,000）|
| `order_detail` | string | 500 | Y | 商品明細（防詐騙法規必填）|
| `send_time` | string | 20 | Y | 傳送時間（當下），`yyyy-MM-dd HH:mm:ss` |
| `payer_name` | string | 50 | Y | 繳款人姓名 |
| `payer_mobile` | string | 10 | Y | 繳款人手機（手機或市話擇一；都填則手機優先）|
| `payer_tel` | string | 20 | Y | 繳款人市話（手機或市話擇一）；不可用括號，可用 `-#`，例 `02-2222-2222#222` |
| `payer_email` | string | 240 | Y | 繳款人電子郵件 |
| `period_type` | string | — | Y | 扣款週期：`week` 每週／`month` 每月／`year` 每年 |
| `period_date` | number | — | Y* | 扣款日期（`week`/`month` 使用）：week 帶 `1~7`（週一~日）；month 帶 `1~31`（當月無該日則以該月最後一天扣款）|
| `period_date_for_year` | string | — | Y* | 扣款日期（`year` 使用）：`yyyy-MM-dd`，須大於今天；例 `2023-09-22` 表每年 9/22 扣款 |
| `period_times` | number | — | Y | 扣款期數，請介於 `2~900` |
| `first_amount` | number | — | — | 首期金額；限制依合約（預設 100~100,000）；未填則等同每期金額 |
| `first_type` | string | — | — | 首期扣款設定：`build` 訂單建立當天扣款／`date` 指定日期扣款（未填預設 `build`）|
| `first_date` | string | — | —† | 首期扣款日期 `yyyy-MM-dd`，須大於今天；`first_type=date` 時必填。填寫則消費者須先做 1 元授權綁卡，待指定日期系統才自動扣款 |
| `return_url` | string | 250 | — | 首次授權成功指定回傳 URL；授權成功會轉址並帶入「首次授權完成回報」參數；未填顯示 PAYUNi 付款結果頁 |
| `back_url` | string | 250 | — | 返回商店按鈕網址；填寫則 PAYUNi 結果頁顯示返回按鈕 |
| `apn_url` | string | 250 | — | APN 指定傳送網址；未填用黑貓 PAY 後台設定 |

> `Y*` = `period_date` 與 `period_date_for_year` 依 `period_type` 二擇一（week/month 用前者、year 用後者）。`†` = `first_type=date` 時 `first_date` 必填。

**回覆**

| 欄位 | 型態 | 說明 |
|------|------|------|
| `status` | string | `OK`／`ERROR` |
| `msg` | string | 異常訊息，無異常帶空值 |
| `cust_order_no` | string | 續期訂單號碼（原單號 + 尾碼，首期 `_1` 結尾）|
| `mer_trade_no` | string | 商店訂單編號（後續整批操作用）|
| `url` | string | 對應的線上刷卡網址 |

```json
// Send:
{ "cust_id":"CV0100000008","period_amount":1000,"order_detail":"測試續期收款","payer_name":"王大明",
  "payer_mobile":"0900123123","payer_email":"test@gg.com.tw","send_time":"yyyy-MM-dd HH:mm:ss",
  "period_type":"month","period_date":31,"period_times":3,"first_type":"build",
  "return_url":"https://test.gg.com.tw/api_receiver.php" }
// Response 成功：
{ "status":"OK","msg":"","cust_order_no":"P241213061050753_1",
  "mer_trade_no":"P241213061050753","url":"https://test.4128888card.com.tw/test/To8LZfA6" }
```

**異常訊息**：1 續期收款-取得金流服務資訊失敗／2 契約客戶資料不正確／3 契約客戶資料不存在／4 使用者金流代號登入失敗,使用者已經停用／5 每期金額必填／6 手機號碼及市話,請擇一填寫／7 繳款人姓名必填／8 繳款人 E-MAIL 必填／9 繳款人 E-MAIL 格式不正確／10 商品說明必填／11 扣款週期必填／12 扣款日期必填／13 扣款期數必填／14 每週扣款:扣款日期請輸入 1~7 的數字／15 每週扣款:扣款期數請輸入 2~104 的數字／16 每月扣款:扣款日期請輸入 1~31 的數字／17 每月扣款:扣款期數請輸入 2~24 的數字／18 扣款週期格式不正確／19 首期金額請輸入大於 10 的數字／20 有填寫首期金額,但首期扣款設定參數不正確／21 首期扣款設定為 date,首期扣款日期必填／22 首期扣款日期,格式不正確／23 首期扣款設定為 date,首期扣款日期,必須大於今天日期／24 續期收款的訂單號碼僅能使用英文及數字／25 續期收款的訂單號碼長度需小於 25。

> ⚠️ **期數上限不一致**：參數表 `period_times` 標 `2~900`，但錯誤 15/17 仍寫 week `2~104`、month `2~24`。版本 1.28.2 changelog 明載「移除 week、month 上限三年扣款期數」，故 2~104/2~24 為**舊制殘留**，現行以 `2~900` 為準（以實際 API 回應為最終依據）。

---

## 2. 首次授權完成回報（前景導轉）

消費者首期授權完成時，若有填 `return_url` 則瀏覽器 **redirect（GET query string）** 到該網址；否則停留在統一金流付款結果頁。
**若續期收款首期非當日授權**（`first_type=date`），須先做 1 元授權綁卡，故 `order_amount` 會帶 `1`，非實際交易完成。

| 欄位 | 型態 | 說明 |
|------|------|------|
| `ret` | string | 授權成功為 `OK` |
| `cust_order_no` | string | 續期訂單號碼（首期 `_1` 結尾）|
| `order_amount` | string | 授權金額（若非建立當天扣款，會收到金額 `1`）|
| `send_time` | string | 原訂單傳送時間 `yyyy-MM-dd HH:mm:ss` |
| `acquire_time` | string | 收單交易時間（取得授權時間）|
| `auth_code` | string | 授權碼 |
| `card_no` | string | 信用卡號碼後四碼 |
| `notify_time` | string | 通報時間（每次傳輸更新）|
| `chk` | string | 驗章碼（公式同 COCS 授權完成回報，見 callbacks-apn.md）|

`chk = MD5(hash_base+'$'+order_amount+'$'+send_time+'$'+ret+'$'+acquire_time+'$'+auth_code+'$'+card_no+'$'+notify_time+'$'+cust_order_no)`

```
// Redirect 範例：
https://test.gg.com.tw/api_receiver.php?ret=OK&cust_order_no=P241213061050753_1&order_amount=3063
&send_time=2024-12-13 14:10:49&acquire_time=2024-12-13 14:12:02&auth_code=000000&card_no=0001
&notify_time=2024-12-13 14:12:06&chk=88e7b6961a2cd12e1156f89e59b89abe
```

> 注意：若 1 元綁卡已授權完成、但首期尚未正式扣款，消費者再進短網址輸卡號送出會出現「授權失敗，已存在相同商店訂單編號」——表示已授權過。

---

## 3. 狀態修改 `POST /api/Period/MdfStatus`

尚未扣款的續期收款訂單可變更狀態，可改「整批訂單」或「指定某一期」。

- **須完成首期授權或 1 元授權**才能修改狀態。
- 每日早上 **09:30 之前執行自動扣款**；為免干擾，**不允許單期訂單於扣款當天修改狀態**，請於扣款日前一天完成。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cust_id` | string | 20 | Y | 契客金流代號 |
| `mer_trade_no` | string | 30 | Y | 商店訂單編號 |
| `revise_trade_status` | string | — | Y | 修改訂單狀態：`suspend` 暫停／`restart` 啟用／`end` 終止／`reauth` 重新授權 |
| `period_no` | int | — | — | 要修改的期數（每次只能一期），例修改第 6 期帶 `6`。**帶入=單期修改；不帶=整批修改** |

**可用狀態規則**
- **整批**（不帶 `period_no`）：`suspend`／`restart`／`end`。暫停後可【啟用】【終止】（再次啟用於最近一期開始授權）；啟用後可【暫停】【終止】；終止後整筆無法再啟用/暫停。
- **單期**（帶 `period_no`）：`suspend`／`restart`／`reauth`。（`reauth`：因卡號到期、額度不足等授權失敗，問題解決後重新要求授權）某期暫停後可【啟用】；啟用後可【暫停】；重新授權僅可改【重新授權】；已逾期則無法修改。

**回覆**：`status` `msg`（成功則空白）`mer_trade_no` `period_no`（無則空白）。

```json
// Send: { "cust_id":"CV0100000008","mer_trade_no":"P241224111116508","revise_trade_status":"end" }
// Response 成功： { "status":"OK","msg":"","mer_trade_no":"P241224111116508" }
// 失敗： { "status":"ERROR","msg":"不可調整已終止訂單狀態" }
```

---

## 4. 授權卡號修改（換卡）`POST /api/Period/Exchange`

消費者卡號過期/額度不足需更換卡號時使用，產生短網址讓消費者於 PAYUNi 支付頁重新做 1 元授權。**須完成首期授權或 1 元授權**才能修改卡號。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cust_id` | string | 20 | Y | 契客金流代號 |
| `mer_trade_no` | string | 30 | Y | 商店訂單編號 |

**回覆**：`status` `msg`（成功空白）`mer_trade_no` `url`（重新授權卡號用的短網址）。

```json
// Response 成功： { "status":"OK","msg":"","mer_trade_no":"P241223102129334",
//   "url":"https://test.4128888card.com.tw/period/cocs/PayuniPeriodExchange/0hBxwHRhQ" }
// 失敗： { "status":"ERROR","msg":"mer_trade_no 不存在" }
```

---

## 5. 訂單內容修改 `POST /api/Period/Modify`

修改**尚未授權**的續期收款訂單內容（金額、扣款週期與期數等）。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cust_id` | string | 20 | Y | 契客金流代號 |
| `mer_trade_no` | string | 25 | Y | 商店訂單號碼 |
| `payer_name` | string | 50 | — | 繳款人姓名 |
| `payer_mobile` | string | 10 | — | 繳款人手機（手機或市話都填則手機優先）|
| `payer_tel` | string | 20 | — | 繳款人市話；不可用括號，可用 `-#` |
| `payer_email` | string | 240 | — | 繳款人電子郵件 |
| `period_type` | string | — | — | 扣款週期 `week`／`month`／`year` |
| `period_date` | number | — | — | 扣款日期（week 1~7／month 1~31）|
| `period_date_for_year` | string | — | — | 扣款日期（year 使用，`yyyy-MM-dd`）|
| `period_times` | number | — | — | 扣款期數，`2~900` |
| `period_amount` | number | — | — | 每期金額（範例含此欄）|
| `first_amount` | number | — | — | 首期金額（範例含此欄）|

**回覆**：`status` `msg`（成功空白）`mer_trade_no`。

```json
// Send:
{ "cust_id":"CV0100000008","mer_trade_no":"P250108142823888","period_amount":1234,"payer_name":"MrTest",
  "payer_tel":"02-88888888","payer_email":"mrtest@test.com.tw","period_type":"week","period_date":2,
  "period_times":8,"first_amount":2345 }
// Response 成功： { "status":"OK","msg":"","mer_trade_no":"P250108142823888" }
// 失敗： { "status":"ERROR","msg":"mer_trade_no 不存在" }
```

> **續期收款 APN**：非當日扣款、只有 1 元授權綁卡（非實際交易）**不會有 APN 通知**。實際每期扣款成功/失敗才發 APN（COCS APN，`order_no` 帶有尾碼的續期訂單編號、`trans_id` 帶商店訂單編號無尾碼）。詳見 callbacks-apn.md。
