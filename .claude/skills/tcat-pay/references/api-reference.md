# API Reference — 統一客樂得多元支付平台 V1.28.2

完整請求 / 回應參數表。所有需驗證的 API：`POST`、`Content-Type: application/json`、Header `Authorization: Bearer {token}`。
CVS / COCS / 主檔操作共用端點 `/api/Collect`，以 body 的 `cmd` 分派。續期收款見 `recurring.md`。錯誤訊息表見 `codes-reference.md`。

> **共通錯誤（幾乎每個 cmd 都有 1~6 項）**：1 `cmd 資料不正確.`／2 `契約客戶資料不正確.`／3 `契約客戶資料不存在.`／4 `使用者密碼不可超過 40 碼.`／5 `cust_id(XXXX)與 token(XXXX)不匹配`／6 `使用者登入失敗, 使用者已經停用.`。各 cmd 專屬錯誤見 `codes-reference.md`。
> **表頭「加密」欄位**：Token 表頭列有「加密」欄，但所有欄位皆空——本平台不對欄位內容做加解密。

## 目錄

- [1. 取得 Token `POST /Token`](#1-取得-token-post-token)
- [2. CVS 超商銀行繳款（`/api/Collect`）](#2-cvs-超商銀行繳款apicollect)
  - [2.1 CvsOrderAppend 契客新增訂單](#21-cvsorderappend-契客新增訂單)
  - [2.2 CVS 共用回覆欄位](#22-cvs-共用回覆欄位cvsorderquery--新增--區間查詢)
  - [2.3 CvsOrderQuery 訂單查詢](#23-cvsorderquery-訂單查詢)
  - [2.4 CvsOrderListQuery 訂單日期區間查詢](#24-cvsorderlistquery-訂單日期區間查詢)
  - [2.5 CvsIbonUpdate 變更 ibon 繳款金額](#25-cvsibonupdate-變更-ibon-繳款金額)
  - [2.6 CvsIbonUpdateDate 變更 ibon 繳款到期日](#26-cvsibonupdatedate-變更-ibon-繳款到期日)
- [3. COCS 線上刷卡（`/api/Collect`）](#3-cocs-線上刷卡apicollect)
  - [3.1 CocsOrderAppend 契客新增刷卡訂單](#31-cocsorderappend-契客新增刷卡訂單)
  - [3.2 CocsOrderAppend2 新增刷卡訂單-加值通知服務](#32-cocsorderappend2-新增刷卡訂單-加值通知服務)
  - [3.3 CocsOrderQuery 訂單查詢 + COCS 共用回覆欄位](#33-cocsorderquery-訂單查詢--cocs-共用回覆欄位)
  - [3.4 CocsOrderListQuery 訂單日期區間查詢](#34-cocsorderlistquery-訂單日期區間查詢)
  - [3.5 CocsOrderCancel 訂單取消授權](#35-cocsordercancel-訂單取消授權)
  - [3.6 CocsCashRequest 指定請款金額](#36-cocscashrequest-指定請款金額全額或部分請款)
  - [3.7 CocsOrderRefund 訂單取消交易（退款）](#37-cocsorderrefund-訂單取消交易全額或部分退貨退款)
- [4. 主檔管理 — SmsShortNameUpdate](#4-主檔管理--smsshortnameupdate-變更金流服務簡訊簡稱)
- [5. 共用電子發票欄位](#5-共用電子發票欄位b2c1-時)

---

## 1. 取得 Token `POST /Token`

`Content-Type: application/x-www-form-urlencoded`（**非 JSON**）。此端點不需 Bearer。

**請求**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `grant_type` | string | 20 | Y | 授權方式，固定 `password` |
| `username` | string | 20 | Y | 帳號（客戶金流代號，= 後續 API 的 `cust_id`）|
| `password` | string | 20 | Y | API 密碼 |

**回覆**

| 欄位 | 型態 | 說明 |
|------|------|------|
| `access_token` | string | 存取憑證 |
| `token_type` | string | 憑證種類（`bearer`）|
| `expires_in` | string | 有效期（單位秒，欄位說明「預設 1 天」；範例值 `86399`）|
| `userName` | string | 會員帳號 |
| `.issued` | datetime | 建立時間（GMT）|
| `.expires` | datetime | 到期時間（GMT）——**以此為 Token 實際到期依據** |

> ⚠️ 有效期敘述矛盾：內文「3 小時」vs 欄位/範例「1 天」。以回傳 `.expires` 為準。

**錯誤回覆**：`{ "error": "invalid_grant", "error_description": "使用者名稱或密碼不正確。" }`

```json
// Send (form body): grant_type=password&username=12656354001&password=1q2w
// Response 成功：
{ "access_token":"nII7I64yQLVIY8OBeKAn1K…", "token_type":"bearer", "expires_in":86399,
  "userName":"12656354001", ".issued":"Tue, 18 Jul 2017 02:53:12 GMT", ".expires":"Wed, 19 Jul 2017 02:53:12 GMT" }
```

---

## 2. CVS 超商銀行繳款（`/api/Collect`）

收單行：`payment_acquirerType=2` 安源（ibon 超商，上限 20,000）／`=3` 統一金流（ATM 虛擬帳號，上限 49,999）。

### 2.1 CvsOrderAppend 契客新增訂單

`cmd = CvsOrderAppend`。**回覆欄位同「訂單查詢」（§2.2），用不到的欄位帶 NULL/0。**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CvsOrderAppend` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 25 | Y | 契客訂單號碼；同一契客代號下必須唯一（統一金流上限 25；安源上限 30；若開重複繳款自動新增訂單功能，請保留 2~3 碼給系統）|
| `order_amount` | number | — | Y | 訂單金額 |
| `expire_date` | string | 10 | Y | 繳費到期日 `YYYY-MM-DD` |
| `payer_name` | string | 50 | Y | 繳款人姓名（收單行為統一金流時必填；b2c=1 時必填）|
| `payer_postcode` | string | 10 | Y | 繳款人郵遞區號 |
| `payer_address` | string | 240 | Y | 繳款人地址 |
| `payer_mobile` | string | 20 | Y | 繳款人手機（b2c 發票中獎後簡訊通知；僅能一組，長度限 20）|
| `payer_email` | string | 240 | Y | 繳款人電子郵件（收單行為統一金流時必填；b2c 開立發票及中獎 EMAIL 通知）|
| `payment_type` | string | 2 | Y | 繳款方式：`0` ibon 繳款／`1` ATM 銀行轉帳 |
| `payment_acquirerType` | string | 2 | Y | 收單行：`2` 安源／`3` 統一金流 |
| `bank_id` | string | 3 | — | 指定銀行代碼（統一金流 ATM 用）：`822` 中國信託／`013` 國泰世華。可留空；未填時幕後取號隨機帶入，非幕後則由消費者於短網址自行選擇 |
| `apn_url` | string | 250 | — | APN 指定傳送網址；未填則用黑貓 PAY 平台後台設定的預設值 |
| `order_detail` | string | 500 | Y | 繳款單/商品明細；若金流服務開啟 PDF 綜合繳款單功能則限 150 字（避免跑版）。收單行為統一金流時必填；為符合政府法規務必填品名 |

其後為電子發票欄位（`b2c` `product_name` `print_invoice` `vehicle_type` `vehicle_barcode` `donate_invoice` `love_code` `buyer_bill_no` `buyer_invoice_title` `buyer_invoice_zip` `buyer_invoice_addr`），見 §5。

```json
// Response 失敗範例： { "status":"ERROR", "msg":"新增訂單失敗!!" }
```

### 2.2 CVS 共用回覆欄位（CvsOrderQuery / 新增 / 區間查詢）

| 欄位 | 型態 | 說明 |
|------|------|------|
| `msg` | string | 異常訊息，如無異常則帶空值 |
| `status` | string | 回應狀態：`OK`=成功／`ERROR`=異常 |
| `cust_order_no` | string | 契客訂單號碼 |
| `order_amount` | number | 訂單金額 |
| `expire_date` | string | 繳費到期日 `yyyy-MM-dd` |
| `ibon_code` | string | ibon 超商代碼 |
| `ibon_shopid` | string | 安源 ibon 廠商代碼 |
| `virtual_account` | string | ATM 轉帳虛擬帳號 |
| `st_barcode1` | string | 超商條碼 1 |
| `st_barcode2` | string | 超商條碼 2 |
| `st_barcode3` | string | 超商條碼 3 |
| `bill_amount` | number | 帳單金額（需列印於帳單上，含外加手續費）|
| `cs_fee` | number | 超商手續費 |
| `create_time` | string | 代繳資訊建立時間 `yyyy-MM-dd HH:mm:ss` |
| `process_code` | number | 訂單程序狀態代碼（附件 1，見 codes-reference）|
| `process_code_update_time` | string | 訂單程序狀態變更日期 `yyyy-MM-dd HH:mm:ss` |
| `pay_amount` | number | 繳款金額，無值帶 0 或 NULL |
| `pay_date` | string | 繳款日期 `yyyy-MM-dd HH:mm:ss` |
| `grant_amount` | number | 預計撥款金額，無值帶 0/NULL；不含退款；撥款後更新為實際 |
| `grant_date` | string | 預計撥款日期 `yyyy-MM-dd`；撥款後更新為實際 |
| `cvs_acquirer_type` | string | 收單行 `2` 安源／`3` 統一金流（⚠️ 見下方不一致註記）|
| `short_url` | string | 短網址 |
| `pay_route` | string | 繳款通路（附件 3：`Bank`／`IbonPay`）|
| `storeId` | string | 繳款門市店號（ibon 繳款）|
| `bankno` | string | ATM 繳款轉入帳號（遮碼，僅顯示末 5 碼）|
| `bank_id` | string | 銀行代號 |
| 電子發票 | — | `print_invoice` `vehicle_type` `vehicle_barcode` `donate_invoice` `love_code` `invoice_no` `invoice_date` `random_number`（見 §5）|

> ⚠️ `cvs_acquirer_type` 值域不一致：訂單查詢定義為 `2:安源 / 3:統一金流`；但 CvsIbonUpdateDate 回覆定義為 `0:玉山、1:中信、2:ibon(安源)`（舊制殘留；玉山/中信已於 1.22.1、1.23 移除為 CVS 收單行）。判斷收單行請以送單時的 `payment_acquirerType` 為準。
> 範例 JSON 另出現 `ibon_designate_storeid`（指定 ibon 繳款門市店號），未列於欄位表但實際會回傳。

### 2.3 CvsOrderQuery 訂單查詢

`cmd = CvsOrderQuery`。請求：`cmd` `cust_id`(20,Y) `cust_order_no`(30,Y)。回覆 = §2.2。

```json
// Send: { "cmd":"CvsOrderQuery", "cust_id":"12656354001", "cust_order_no":"20170718010712" }
```
專屬錯誤：7 `必須要有參數+cust_order_no`／8 `找不到此筆代繳資訊`／9 `取得查詢代繳資訊失敗`。

### 2.4 CvsOrderListQuery 訂單日期區間查詢

`cmd = CvsOrderListQuery`。**每次最多 800 筆。**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CvsOrderListQuery` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `order_start_date` | string | 20 | Y | 訂單日期起日 `yyyy-MM-dd HH:mm:ss` |
| `order_end_date` | string | 20 | Y | 訂單日期迄日 `yyyy-MM-dd HH:mm:ss` |

回覆：`msg`（`Count:N`）／`status`／`order_list`（Array，每筆內容同 §2.2）。無資料回 `{"msg":"Count:0","status":"OK","order_list":[]}`。

### 2.5 CvsIbonUpdate 變更 ibon 繳款金額

`cmd = CvsIbonUpdate`。**僅支援收單行「安源」的 ibon 訂單，統一金流不支援。** 請勿在消費者列印繳款單後才變更（實收恐不符）。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CvsIbonUpdate` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 契客訂單編號（須為已建立的繳款單）|
| `order_amount` | number | — | Y | 異動後的繳款單金額 |
| `ibon_shopid` | string | 14 | Y | 廠商代碼（`CCAT`、`BCAT`）|
| `ibon_code` | string | 20 | Y | ibon 代碼 |

回覆：`status` `cust_order_no` `order_amount` `expire_date` `ibon_code` `ibon_shopid` `virtual_account` `st_barcode1~3` `bill_amount` `cs_fee`。

### 2.6 CvsIbonUpdateDate 變更 ibon 繳款到期日

`cmd = CvsIbonUpdateDate`。**僅安源支援，需 `checksum` 驗章。**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CvsIbonUpdateDate` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 契客訂單編號（須為已建立的繳款單）|
| `order_amount` | number | — | Y | 異動後的繳款單金額 |
| `expire_date` | string | — | Y | 繳費到期日 `YYYY-MM-DD` |
| `ibon_shopid` | string | 14 | Y | 廠商代碼（`CCAT`、`BCAT`）|
| `ibon_code` | string | 20 | Y | ibon 代碼 |
| `nonce` | string | 10 | Y | 隨機數 `HHNNSSRRRR`（時分秒 6 位 + 4 位亂數）|
| `checksum` | string | 32 | Y | `MD5(cust_order_no + ":" + order_amount + ":" + nonce)` |

回覆：§2.2 的欄位子集（含 `create_time` `process_code` `process_code_update_time` `pay_date` `grant_amount` `grant_date` `cvs_acquirer_type` `short_url`）。

```json
// Send: { "cmd":"CvsIbonUpdateDate","cust_id":"12656354001","cust_order_no":"20190402000001",
//         "order_amount":250,"expire_date":"2019-04-07","ibon_shopid":"CCAT","ibon_code":"909606553021",
//         "nonce":"21","checksum":"e309160d46bcefaa7dd8db18a23f179f" }
// checksum = MD5("20190402000001:250:21") = e309160d46bcefaa7dd8db18a23f179f  ✔ 已驗證
```
專屬錯誤含 7 `檢核驗證碼不正確.`（其餘見 codes-reference）。

---

## 3. COCS 線上刷卡（`/api/Collect`）

收單行 `acquirer_type`：`esun` 玉山／`chinatrust` 中國信託／`payuni` 統一金流。回覆 `url` = 消費者線上刷卡短網址。

### 3.1 CocsOrderAppend 契客新增刷卡訂單

`cmd = CocsOrderAppend`。不主動發簡訊/郵件。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CocsOrderAppend` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 契客訂單號碼；同一契客代號下唯一。可留空，系統自動產生 `YYYYMMDD######`（前 8 碼西元年月日，後 6 碼當日流水號自 1 起）|
| `order_amount` | number | — | Y | 訂單/交易金額；上限依合約（預設 100,000）|
| `order_detail` | string | 500 | Y | 訂單/商品明細；為符合政府法規必須填寫（不可含 HTML tag）|
| `acquirer_type` | string | 20 | Y | 指定收單銀行：`esun`／`chinatrust`／`payuni` |
| `limit_product_id` | string | 50 | — | 限定產品別；可留空=允許所有；多個以 `|` 分隔（附件 2），例 `esun.normal|esun.m3` |
| `send_time` | string | 10 | Y | 傳送時間（須為當下），`yyyy-MM-dd HH:mm:ss`，例 `2017-07-18 07:17:25` |
| `success_url` | string | 250 | — | 授權成功指定回傳 URL；未填則用客樂得金流服務設定值 |
| `apn_url` | string | 250 | — | APN 指定傳送網址；未填用後台設定 |

電子發票欄位見 §5。**啟用記憶卡號**（需開通且收單行為 `payuni`，卡號存於統一金流平台）：`is_memory`(1，`0` 不開啟(預設)/`1` 開啟)、`payer_mobile`(20，記憶卡號=1 時必填，作為 PAYUNi 平台系統識別碼)。

回覆：`msg` `status` `cust_order_no` `url`（刷卡網址）。

```json
// Send: { "cmd":"CocsOrderAppend","cust_id":"12656354001","cust_order_no":"20170718010723",
//         "order_amount":50,"order_detail":"測試訂單備註","acquirer_type":"esun",
//         "limit_product_id":"esun.normal|esun.m3","send_time":"2017-07-18 01:07:23" }
// Response 成功： { "status":"OK","cust_order_no":"20170718010723","url":"https://goo.gl/K616G6" }
```

### 3.2 CocsOrderAppend2 新增刷卡訂單-加值通知服務

`cmd = CocsOrderAppend2`。系統依設定主動以簡訊/EMAIL 發送刷卡短網址給繳款人。欄位同 §3.1，差異：

| 欄位 | 型態 | 長度 | 必填 | 說明（與 CocsOrderAppend 差異）|
|------|------|------|:--:|------|
| `cust_order_no` | string | 30 | — | 可留空自動編號 |
| `payer_name` | string | 50 | Y | 繳款人姓名 |
| `payer_mobile` | string | 20 | — | 加值服務啟用或開記憶卡號時必填；b2c 發票中獎簡訊 |
| `payer_email` | string | 50 | — | 加值服務啟用時必填；會帶入統一金流收件人 EMAIL；b2c 開發票/中獎通知 |
| `limit_product_id` | string | 50 | Y | （此 API 為必填）|

其餘（`order_amount` `order_detail` `acquirer_type` `send_time` `success_url` `apn_url` `is_memory` + 發票欄位）同 §3.1。回覆同 §3.1。

### 3.3 CocsOrderQuery 訂單查詢 + COCS 共用回覆欄位

`cmd = CocsOrderQuery`。請求：`cmd` `cust_id`(20,Y) `cust_order_no`(30,Y)。**回覆（COCS 共用，`*ListQuery` 的 order_list 內容亦同）：**

| 欄位 | 型態 | 說明 |
|------|------|------|
| `msg` | string | 異常訊息，無異常帶空值 |
| `status` | string | `OK`／`ERROR` |
| `cust_order_no` | string | 契客訂單號碼 |
| `order_amount` | number | 代繳金額 |
| `expire_date` | string | 繳費到期日 `yyyy-MM-dd HH:mm:ss` |
| `acquirer_type` | string | 授權銀行名稱（`玉山銀行`／`中國信託`／`統一金流`）— 注意回覆是**中文行名**，非請求時的 esun/chinatrust/payuni |
| `period_type` | string | 授權產品（附件 2，如「一次付清」「分期三期」）|
| `request_date` | string | 預計請求請款日期 `yyyy-MM-dd` |
| `request_amount` | number | 預計請款金額 |
| `grant_date` | string | 預計撥款日期 `yyyy-MM-dd` |
| `grant_amount` | number | 預計撥款金額（系統預估，實際以銀行為準）|
| `process_code` | number | 訂單程序狀態代碼（附件 1）|
| `process_code_update_time` | string | 狀態變更日期 `yyyy-MM-dd HH:mm:ss` |
| `create_time` | string | 訂單建立時間 `yyyy-MM-dd HH:mm:ss` |
| `card_no` | string | 信用卡號前 6 後 4 碼（例 `552199******1864`）|
| `auth_code` | string | 授權碼 |
| 電子發票 | — | `print_invoice` `vehicle_type` `vehicle_barcode` `donate_invoice` `love_code` `invoice_no` `invoice_date` `random_number`（見 §5）|

專屬錯誤：7 `必須要有參數+cust_order_no`／8 `找不到此筆刷卡資訊`／9 `取得查詢刷卡資訊失敗`。

### 3.4 CocsOrderListQuery 訂單日期區間查詢

`cmd = CocsOrderListQuery`。請求：`cmd` `cust_id`(20,Y) `order_start_date`(20,Y) `order_end_date`(20,Y)，格式 `yyyy-MM-dd HH:mm:ss`。回覆：`msg`(`Count:N`) `status` `order_list`(Array，內容同 §3.3)。無資料回 `order_list:[]`。

### 3.5 CocsOrderCancel 訂單取消授權

`cmd = CocsOrderCancel`。**請款前使用；已請款請改用取消交易（§3.7）。銀行僅支援全額取消授權**（要部分請改用部分請款，或請款後部分取消交易）。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CocsOrderCancel` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 原契客訂單編號 |
| `order_amount` | number | — | Y | 訂單金額 |
| `acquirer_type` | string | 20 | Y | `esun`／`chinatrust`／`payuni` |
| `send_time` | string | 10 | Y | 傳送時間 `yyyy-MM-dd HH:mm:ss`（須為當下）|

回覆：`status` `cust_order_no`。

### 3.6 CocsCashRequest 指定請款金額（全額或部分請款）

`cmd = CocsCashRequest`。指定金額請款，未請款金額退還消費者（例：訂單 2000、請款 1700，退 300）。**信用卡分期與統一金流銀聯卡僅能全額請/退款。**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CocsCashRequest` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 契客訂單編號 |
| `order_amount` | number | — | Y | 訂單金額 |
| `cr_amount` | number | — | Y | 指定請款金額（不可大於訂單金額）|
| `send_time` | string | 10 | Y | 傳送時間 `yyyy-MM-dd HH:mm:ss` |

回覆：`status` `cust_order_no`。

> 請款排程（依收單行）：玉山每 30 分請款一次，2~3 小時內回覆；中國信託每日 20:00 截止、隔日 05:00 起回覆（系統每日 15:55~19:55 每 30 分送檔）；統一金流每日 20:00 截止、隔日中午 12:00 後完成（系統每日 16:55~19:55 每 30 分送檔）。

### 3.7 CocsOrderRefund 訂單取消交易（全額或部分退貨退款）

`cmd = CocsOrderRefund`。**限訂單已完成請款後使用。**

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `CocsOrderRefund` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `cust_order_no` | string | 30 | Y | 契客訂單編號 |
| `order_amount` | number | — | Y | 訂單金額 |
| `refund_amount` | number | — | Y | 取消交易金額（必填，≤ 訂單/交易金額，>0）|
| `acquirer_type` | string | 20 | Y | `esun`／`chinatrust`／`payuni` |
| `send_time` | string | 10 | Y | 傳送時間 `yyyy-MM-dd HH:mm:ss` |

回覆：`status` `cust_order_no` `refund_amount`。

---

## 4. 主檔管理 — SmsShortNameUpdate 變更金流服務簡訊簡稱

`cmd = SmsShortNameUpdate`，`POST /api/Collect`。契客啟用簡訊發送時，簡訊內會帶入此簡稱以辨識來源店家。

| 欄位 | 型態 | 長度 | 必填 | 說明 |
|------|------|------|:--:|------|
| `cmd` | string | 20 | Y | 固定 `SmsShortNameUpdate` |
| `cust_id` | string | 20 | Y | 契客代號 |
| `service_type` | string | 1 | Y | 金流服務類型：`0` CVS 代收代付／`1` COCS 線上刷卡 |
| `sms_short_name` | string | 20 | Y | 調整後簡訊簡稱 |

回覆：`status` `msg`。

---

## 5. 共用電子發票欄位（b2c=1 時）

未開通發票功能請忽略；`b2c=1`（開立）時下列欄位生效，多數在 b2c=1 時為必填。

| 欄位 | 型態 | 長度 | 說明 |
|------|------|------|------|
| `b2c` | string | 1 | 此訂單是否開立電子發票：`0` 不開立(預設)／`1` 開立 |
| `product_name` | string | 60 | 商品名稱（b2c=1 必填）|
| `print_invoice` | string | 1 | 是否列印紙本發票（b2c=1 必填）：`0` 不列印／`1` 列印 |
| `vehicle_type` | string | 1 | 載具類別：`1` 會員（發票平台）載具／`2` 手機條碼／`3` 自然人憑證 |
| `vehicle_barcode` | string | 20 | 載具條碼 |
| `donate_invoice` | string | 1 | 是否捐贈發票（b2c=1 必填）：`0` 不捐贈／`1` 捐贈 |
| `love_code` | string | 20 | 愛心碼（捐贈時填；留空預設創世基金會 `919`）|
| `buyer_bill_no` | string | 8 | 買方統一編號 |
| `buyer_invoice_title` | string | 161 | 發票抬頭 |
| `buyer_invoice_zip` | string | 5 | 買方統編發票寄送郵遞區號（CVS 新增訂單另有）|
| `buyer_invoice_addr` | string | 240 | 買方統編發票寄送地址（CVS 新增訂單另有）|

> 回覆時另有發票結果欄位：`invoice_no`（發票號碼）、`invoice_date`（發票日期）、`random_number`（隨機碼）；APN 另含 `invoice_discount_no`（折讓單號）。
> 發票邏輯：捐贈發票、統編(公司戶)發票、印製紙本發票、共通載具「只能選一種」。
