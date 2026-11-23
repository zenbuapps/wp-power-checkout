# 回報與通知（Callbacks / APN）— 統一客樂得多元支付平台 V1.28.2

兩類回傳，驗章機制不同，**勿混用**：

| 類型 | 通道 | 方向 | 驗章欄位 | 公式 |
|------|------|------|----------|------|
| COCS 銀行授權導轉回報 | 瀏覽器 redirect（GET query string）| 前景 | `chk` | `MD5(hash_base + '$' + 欄位…)` |
| APN 主動通知（CVS / COCS）| HTTP POST JSON（server-to-server）| 背景 | `checksum` | `MD5(api_id:trans_id:amount:status:nonce)` |

---

## 1. COCS 銀行授權導轉回報（前景 redirect，`chk`）

消費者於銀行端刷卡後，瀏覽器被導回契客在後台設定的網址（帶 query string）。**這是給前景 UX 用；最終狀態仍以 APN / 主動查詢為準。**

### 1.1 授權完成回報

導向「授權完成時重新導向契客網址」。

| 欄位 | 型態 | 說明 |
|------|------|------|
| `ret` | string | 授權成功為 `OK` |
| `cust_order_no` | string | 契客訂單號碼 |
| `order_amount` | string | 訂單/交易金額 |
| `send_time` | string | 原訂單傳送時間（新增訂單時輸入的 send_time）`yyyy-MM-dd HH:mm:ss` |
| `acquire_time` | string | 收單交易時間（取得授權時間）`yyyy-MM-dd HH:mm:ss` |
| `auth_code` | string | 授權碼 |
| `card_no` | string | 信用卡號碼後四碼 |
| `notify_time` | string | 通報時間（每次傳輸更新）`yyyy-MM-dd HH:mm:ss` |
| `chk` | string | 驗章碼（見下）|

```
chk = MD5(hash_base +'$'+ order_amount +'$'+ send_time +'$'+ ret +'$'+ acquire_time
          +'$'+ auth_code +'$'+ card_no +'$'+ notify_time +'$'+ cust_order_no)
```
各欄位以 `$` 串接後取 MD5。`hash_base` 為申請服務時系統配發的密鑰。

```
// Redirect 範例（導向 http://10.10.10.100/cocs/authSuccess.php）：
?ret=OK&cust_order_no=C201709141001&order_amount=2&send_time=2017-09-14 10:31:25
&acquire_time=2017-09-14 10:36:38&auth_code=951294&card_no=1849
&notify_time=2017-09-14 10:37:08&chk=9ea4928734f4099b387c75a344fe3e50
```

### 1.2 授權失敗回報

導向「授權失敗時重新導向契客網址」。**僅玉山銀、中信銀可用；統一金流授權失敗不會轉址，停留在失敗結果頁。**

| 欄位 | 型態 | 說明 |
|------|------|------|
| `ret` | string | 授權失敗為 `FAIL` |
| `cust_order_no` | string | 契客訂單號碼 |
| `order_amount` | string | 訂單/交易金額 |
| `send_time` | string | 原訂單傳送時間 |
| `notify_time` | string | 通報時間 |
| `chk` | string | 驗章碼（少了 `acquire_time`/`auth_code`/`card_no`）|

```
chk = MD5(hash_base +'$'+ order_amount +'$'+ send_time +'$'+ ret +'$'+ notify_time +'$'+ cust_order_no)
```

```
// Redirect 範例（導向 http://10.10.10.100/cocs/authFail.php）：
?ret=FAIL&cust_order_no=C201709141001&order_amount=2&send_time=2017-09-14 10:31:25
&notify_time=2017-09-14 10:37:08&chk=3aa9a501ae2c8e58f8dfdcb07af4b7c3
```

> 續期收款「首次授權完成回報」的 `chk` 公式與 1.1 相同（見 recurring.md §2）。

---

## 2. APN 主動通知（背景 server-to-server，`checksum`）

訂單狀態變更時，平台以 **HTTP POST JSON** 送到契客後台設定的「APN-主動發送訊息網址」。

**傳輸與重送規則（CVS、COCS 相同）**
- Content-Type = `application/json`，以 JSON 封裝訂單資料。
- 用 `checksum` 檢核碼確保資料安全。
- 繳款/交易完成後**即時傳送一次**；之後**每 15 分鐘重送，同一狀態碼最多送 3 次**。
- **契客回覆純文字 `OK` 即停止重送**（務必回 OK 並做冪等去重）。
- 後台「收款作業 > 訂單查詢 > 明細」有「APN 補發」按鈕，可反覆測試或補送。

**checksum 公式（CVS、COCS 通用）**
```
checksum = MD5( api_id + ":" + trans_id + ":" + amount + ":" + status + ":" + nonce )
```
- 冒號 `:` 無空格。`nonce` = `HHNNSSRRRR`（時分秒 6 位 + 4 位亂數）。
- 已驗證：`MD5("CV0000000000:550e8400e29b41d4a716446655440000:1250:D:1234569999")` = `3579609ba3914a49441e98cb7e8a55de`；`MD5("CC0000000001:550e8400e29b41d4a716446655440000:1250:B:1234569999")` = `d09d5532767453ad4c6ba9b649034187`。

### 2.1 CVS APN（`payment_code = 2` 客樂得代收代繳服務）

| 欄位 | 型態 | 長度 | 說明 |
|------|------|------|------|
| `api_id` | string | 32 | 契客代號（申請本服務配發的金流代號）|
| `trans_id` | string | 32 | 每個繳款單唯一的交易識別碼 |
| `order_no` | string | 20 | 契客訂單編號 |
| `amount` | number | — | 繳款單金額 |
| `expire_time` | string | 25 | 繳款逾期時間（CVS 到期日以天為單位），格式 `yyyy-MM-dd` |
| `status` | string | 1 | 狀態碼（見下）|
| `payment_code` | number | — | `2`（客樂得代收代繳服務）|
| `payment_detail` | Object | — | 繳款明細物件（見下）|
| `memo` | Object | — | 保留使用 |
| `create_time` | string | 25 | 繳款單建立時間 `yyyy-MM-dd HH:mm:ss` |
| `modify_time` | string | 25 | 狀態更新時間 `yyyy-MM-dd HH:mm:ss` |
| `nonce` | string | 10 | 隨機數 `HHNNSSRRRR` |
| `checksum` | string | 32 | 驗章碼（見上公式）|
| `pay_date` | string | 25 | 繳費時間 |
| `pay_amount` | number | — | 實際繳費金額（**以此判別實收是否相符後才可出貨**）|
| 電子發票 | — | — | `print_invoice` `vehicle_type` `vehicle_barcode` `donate_invoice` `love_code` `invoice_no` `invoice_date` `random_number` `invoice_discount_no`（折讓單號）|

**CVS `status` 狀態碼**：`A` 等待繳款人繳納／`B` 繳款人已繳納／`C` 契客註銷／`D` 已過期繳款單／`E` 已預約撥款給契客／`I` 開立發票通知／`J` 開立發票折讓單號通知。

**CVS `payment_detail` 物件**
```json
{
  "pay_route": "繳款通路",          // 附件 3：Bank / IbonPay
  "st_barcode1": "超商條碼 1",
  "st_barcode2": "超商條碼 2",
  "st_barcode3": "超商條碼 3",
  "st_barcode_note": "超商條碼備註", // 如「外加」
  "bank_id": "銀行代號",
  "virtual_account": "代繳帳號",
  "atm_note": "銀行備註",            // 如「內含」
  "ibon_shopid": "ibon 廠商代碼",
  "ibon_code": "ibon 代碼",
  "ibon_note": "ibon 備註",
  "storeId": "繳款門市店號",         // 安源 ibon
  "ibon_barcode1": "ibon 第一段條碼",
  "ibon_barcode2": "ibon 第二段條碼",
  "ibon_barcode3": "ibon 第三段條碼",
  "bankno": "ATM 繳款轉入帳號(部分遮碼)"
}
```

```json
// CVS APN 完整範例：
{
  "api_id":"CV0000000000","trans_id":"550e8400e29b41d4a716446655440000","order_no":"PO5488277",
  "amount":1250,"expire_time":"2013-09-28","status":"D","payment_code":2,
  "payment_detail":{
    "pay_route":"IbonPay","st_barcode1":"030222619","st_barcode2":"9821400000096500",
    "st_barcode3":"030258000000050","st_barcode_note":"外加","bank_id":"808",
    "virtual_account":"98214000000965","atm_note":"內含","ibon_shopid":"CCAT",
    "ibon_code":"405300000960","ibon_note":"外加","storeId":"123456",
    "ibon_barcode1":"0810025G5","ibon_barcode2":"091002QP13137602","ibon_barcode3":"171338770000671","bankno":""
  },
  "memo":"","create_time":"2016-04-03 08:00:00","modify_time":"2016-04-08 08:30:00",
  "nonce":"1234569999","checksum":"3579609ba3914a49441e98cb7e8a55de",
  "pay_date":"2016-04-08 08:35:00","pay_amount":"1250",
  "print_invoice":"0","vehicle_type":"2","vehicle_barcode":"/1234567","donate_invoice":"",
  "love_code":"","invoice_no":"","invoice_date":"","random_number":"","invoice_discount_no":""
}
```

**CVS APN 注意事項**：① 若有重複繳款，ibon 三段條碼、繳費日期與金額只提供最後一次；② 務必以 `pay_amount` 實收判別後才出貨；③ 統一金流不提供 ibon 繳款門市、QP 碼及繳款條碼。

### 2.2 COCS APN（`payment_code = 1` 客樂得刷卡代繳服務）

| 欄位 | 型態 | 長度 | 說明 |
|------|------|------|------|
| `api_id` | string | 32 | 契客代號 |
| `trans_id` | string | 32 | 1. 每筆線上刷卡訂單唯一交易識別碼；2. 續期收款：回覆商店訂單編號（**無尾碼**）|
| `order_no` | string | 20 | 1. 契客訂單編號；2. 續期收款：回覆續期訂單編號（**有尾碼** `_1`、`_2`）|
| `amount` | number | — | 訂單/交易金額 |
| `status` | string | 1 | 訂單狀態碼（見下）|
| `payment_code` | number | — | `1`（客樂得刷卡代繳服務，固定值）|
| `payment_detail` | Object | — | 刷卡明細物件（見下）|
| `memo` | Object | — | 保留使用 |
| `expire_time` | string | 25 | 繳款逾期時間 `yyyy-MM-dd HH:mm:ss` |
| `create_time` | string | 25 | 繳款單建立時間 |
| `modify_time` | string | 25 | 狀態更新時間 |
| `nonce` | string | 10 | 隨機數 `HHNNSSRRRR` |
| `checksum` | string | 32 | 驗章碼（見上公式）|
| 電子發票 | — | — | `print_invoice` `vehicle_type` `vehicle_barcode` `donate_invoice` `love_code` `invoice_no` `invoice_date` `random_number` `invoice_discount_no` |

**COCS `status` 狀態碼**：`B` 授權完成／`O` 請款作業中（**請款作業中無法取消授權**）／`E` 請款完成／`F` 授權失敗／`D` 訂單逾期／`P` 請款失敗／`M` 取消交易完成／`N` 取消交易失敗／`Q` 取消授權完成／`R` 取消授權失敗／`I` 開立發票通知／`J` 開立發票折讓單號通知。

**COCS `payment_detail` 物件**
```json
{
  "auth_code": "刷卡授權碼(6 位)",
  "auth_card_no": "信用卡號碼前六後四碼",
  "pay_date": "授權日期",
  "pay_amount": "授權金額",
  "fee": "線上刷卡手續費",     // 1.27 新增；當訂單請款完成 status=E 時帶入手續費
  "mer_trade_no": "銀行訂單號碼" // 1.25.1 新增；只要消費者進入銀行刷卡頁面就有一組號碼(不論有無交易)，實際交易以是否有授權金額為準
}
```
```json
// 刷卡(授權)完成範例：
{ "auth_code":"123456","auth_card_no":"123456******1234","pay_date":"2022-07-07 08:08:08","pay_amount":888,"fee":null,"mer_trade_no":"911025000001572" }
// 刷卡失敗或尚未授權範例：
{ "auth_code":null,"auth_card_no":null,"pay_date":null,"pay_amount":null,"fee":null,"mer_trade_no":"911025000001572" }
```

```json
// COCS APN 完整範例：
{
  "api_id":"CC0000000001","trans_id":"550e8400e29b41d4a716446655440000","order_no":"PO5488277",
  "amount":1250,"status":"B","payment_code":1,
  "payment_detail":{"auth_code":"123456","auth_card_no":"552199******1864"},
  "memo":"","expire_time":"2013-09-28 08:15:00","create_time":"2013-09-28 08:00:00",
  "modify_time":"2013-09-28 08:30:00","nonce":"1234569999",
  "checksum":"d09d5532767453ad4c6ba9b649034187",
  "print_invoice":"0","vehicle_type":"2","vehicle_barcode":"/1234567","donate_invoice":"",
  "love_code":"","invoice_no":"","invoice_date":"","random_number":"","invoice_discount_no":""
}
```

> 續期收款：**非當日扣款、僅 1 元授權綁卡（非實際交易）不會有 APN**；實際每期扣款成功/失敗才發 COCS APN。
