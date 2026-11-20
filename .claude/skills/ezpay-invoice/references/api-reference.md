# ezPay 電子發票 API Reference（逐欄位）

對應 EZP_INVI_1.2.1 標準版。所有 API 皆為 HTTP POST + Form Post（UTF-8）。
POST body 固定兩欄：`MerchantID_`（商店代號明文）、`PostData_`（業務參數 AES 加密 hex）。
業務參數寫在 `PostData_` 加密前的 query string 內。

## 目錄

- [傳輸層共用參數](#傳輸層共用參數)
- [1. 開立發票 invoice_issue](#1-開立發票-invoice_issue)
- [2. 觸發開立發票 invoice_touch_issue](#2-觸發開立發票-invoice_touch_issue)
- [3. 作廢發票 invoice_invalid](#3-作廢發票-invoice_invalid)
- [4. 開立折讓 allowance_issue](#4-開立折讓-allowance_issue)
- [5. 觸發確認/取消折讓 allowance_touch_issue](#5-觸發確認取消折讓-allowance_touch_issue)
- [6. 作廢折讓 allowanceInvalid](#6-作廢折讓-allowanceinvalid)
- [7. 查詢發票 invoice_search](#7-查詢發票-invoice_search)
- [金額計算](#金額計算)

---

## 傳輸層共用參數

每支 API 的 POST body 皆為下列兩欄（**參數名稱結尾都有底線 `_`**）：

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `MerchantID_` | 商店代號 | V | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `PostData_` | 加密資料 | V | text | 業務參數經 AES-256-CBC 加密後的 hex 字串（見 concepts.md）|

`PostData_` 內含欄位（解密前的 query string）每支 API 不同，見下方各節。

測試網域 `https://cinv.ezpay.com.tw`，正式網域 `https://inv.ezpay.com.tw`。

---

## 1. 開立發票 invoice_issue

- 測試：`https://cinv.ezpay.com.tw/Api/invoice_issue`
- 正式：`https://inv.ezpay.com.tw/Api/invoice_issue`

### 1.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.5`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）。例：2014-05-15 15:00:00 → `1400137200` |
| `TransNum` | ezPay 平台交易序號 |  | Varchar(20) | 若同時用 ezPay 簡單付金流，可帶 ezPay 交易序號對應金流交易；未使用則不帶 |
| `MerchantOrderNo` | 自訂編號 | V | Varchar(20) | 商店自訂訂單編號，限英、數字、`_`。同一商店中不可重覆。例：`201406010001` |
| `Status` | 開立發票方式 | V | Varchar(1) | `1`=即時開立、`0`=等待觸發開立、`3`=預約自動開立（須帶 CreateStatusTime）|
| `CreateStatusTime` | 預計開立日期 |  | Date | `Status=3` 時必填。格式 `YYYY-MM-DD`，例 `2014-10-05` |
| `Category` | 發票種類 | V | Varchar(5) | `B2B`=買受人為營業人、`B2C`=買受人為個人 |
| `BuyerName` | 買受人名稱 | V | Varchar(60,30) | B2B 時為買方營業人名稱（限 60 字元，不足則帶買方統編）；B2C 時為個人姓名或商店識別碼如會員編號（限 30 字元）|
| `BuyerUBN` | 買受人統一編號 |  | Varchar(8) | 純數字。`Category=B2B` 時必填；`B2C` 時非必填 |
| `BuyerAddress` | 買受人地址 |  | Varchar(100) | 買受人聯絡地址 |
| `BuyerEmail` | 買受人電子信箱 |  | Varchar(50) | 開立時寄送發票查詢資訊。**`CarrierType=2` 時必填** |
| `CarrierType` | 載具類別 |  | Varchar(2) | 僅 `Category=B2C` 適用。`0`=手機條碼、`1`=自然人憑證條碼、`2`=ezPay 電子發票載具；無載具則空值。有值時 `LoveCode` 必為空 |
| `CarrierNum` | 載具編號 |  | Varchar(50) | `CarrierType` 有值時必填。手機條碼 / 自然人憑證帶買受人載具號碼；ezPay 載具帶可識別買受人代號。須 `rawurlencode()`，前後不得含空白 |
| `LoveCode` | 捐贈碼 |  | Int(7) | 3~7 碼純數字。僅 `Category=B2C` 適用。有值時 `CarrierType` 必為空 |
| `PrintFlag` | 索取紙本發票 | V | Varchar(1) | `Y`=索取、`N`=不索取。B2B 必填 `Y`；B2C 若 CarrierType、LoveCode 皆空則必填 `Y` |
| `KioskPrintFlag` | 是否開放至合作超商 Kiosk 列印 |  | Varchar(1) | 僅 `CarrierType=2` 適用。`1`=發票中獎後開放（中獎可至全家 FamiPort 列印兌獎）；不開放則空值 |
| `TaxType` | 課稅別 | V | Varchar(2) | `1`=應稅、`2`=零稅率、`3`=免稅、`9`=混合應稅與免稅或零稅率（限 `Category=B2C`）|
| `TaxRate` | 稅率 | V | Float(6,4) | 應稅一般稅率帶 `5`，特種稅率帶實際數字（不含 %）；零稅率 / 免稅帶 `0` |
| `CustomsClearance` | 報關標記 |  | Varchar(1) | 課稅別為零稅率時必填。`1`=非經海關出口、`2`=經海關出口 |
| `Amt` | 銷售額合計 | V | Int(10) | 純數字，發票銷售額（未稅）。`TaxType=9` 時為 AmtSales+AmtZero+AmtFree 合計 |
| `AmtSales` | 銷售額（課稅別應稅） |  | Int(10) | 僅 `TaxType=9` 時必填。該發票應稅之銷售額（未稅）|
| `AmtZero` | 銷售額（課稅別零稅率） |  | Int(10) | 僅 `TaxType=9` 時必填。該發票零稅率之銷售額 |
| `AmtFree` | 銷售額（課稅別免稅） |  | Int(10) | 僅 `TaxType=9` 時必填。該發票免稅之銷售額 |
| `TaxAmt` | 稅額 | V | Int(10) | 純數字，發票稅額 |
| `TotalAmt` | 發票金額 | V | Int(10) | 純數字，發票總金額（含稅）。銷售額 + 稅額需等於發票金額 |
| `ItemName` | 商品名稱 | V | Varchar(30) | 單一商品；多項商品以 `|` 分隔。例 `商品一|商品二` |
| `ItemCount` | 商品數量 | V | Int(5) | 純數字；多項以 `|` 分隔。例 `1|2` |
| `ItemUnit` | 商品單位 | V | Varchar(2) | 中文 2 字或英數 6 字（個 / 件 / 本 / 張…）；多項以 `|` 分隔。例 `個|本` |
| `ItemPrice` | 商品單價 | V | Int(10) | 純數字。**B2B 為未稅、B2C 為含稅**；多項以 `|` 分隔。例 `200|100` |
| `ItemAmt` | 商品小計 | V | Int(10) | 純數字。數量 × 單價 = 小計。**B2B 未稅、B2C 含稅**；多項以 `|` 分隔。例 `200|200` |
| `ItemTaxType` | 商品課稅別 |  | Int(2) | 僅 `TaxType=9` 時必填。`1`=應稅、`2`=零稅率、`3`=免稅；多項以 `|` 分隔。例 `1|1` |
| `Comment` | 備註 |  | Varchar(200) | 發票備註，限 200 字，難字請縮短 |

### 1.2 Response（JSON 模式）

最外層：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 成功回 `SUCCESS`；失敗回錯誤代碼。`PostData_` 重覆且資料完全一致時也回 `SUCCESS` |
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態說明文字 |
| `Result` | 回傳資料 | Varchar(10) | JSON 格式字串（需再 parse）。重覆送出時回原發票 Result |

`Result`（parse 後）內含欄位：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | ezPay 商店代號 |
| `InvoiceTransNo` | ezPay 電子發票開立序號 | Varchar(20) | 此次發票開立的開立序號（觸發開立 / 查詢時用）|
| `MerchantOrderNo` | 自訂編號 | Varchar(20) | 開立時提供的自訂編號 |
| `TotalAmt` | 發票金額 | Int(10) | 此次開立發票金額 |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | 此次發票號碼。**只有 `Status=1` 即時開立才回傳** |
| `RandomNum` | 發票防偽隨機碼 | Varchar(4) | 此次開立產生的 4 碼防偽隨機碼 |
| `CreateTime` | 開立發票時間 | DateTime | 例 `2014-09-25 12:12:12` |
| `CheckCode` | 檢查碼 | Varchar(64) | 用於驗證回應合法性（SHA256，見 concepts.md）|
| `BarCode` | 發票條碼 | Varchar(19) | 僅 `PrintFlag=Y` 時提供。含發票期別、字軌號碼、隨機碼，供兌獎輸入 |
| `QRcodeL` | 發票 QRCode（左） | Varchar(140) | 僅 `PrintFlag=Y` 時提供。含字軌、開立日期、隨機碼、銷售額、總計、買賣方統編、加密驗證、品名數量單價等 |
| `QRcodeR` | 發票 QRCode（右） | Varchar(140) | 僅 `PrintFlag=Y` 時提供。接續左方二維條碼之中文編碼後資訊 |

JSON 回應範例：

```json
{
  "Status": "SUCCESS",
  "Message": "電子發票開立成功",
  "Result": "{\"CheckCode\":\"00E108DF7DE8756AF003312206DA77A4C37AE33990EA04A944C414113D512228\",\"MerchantID\":\"3502275\",\"MerchantOrderNo\":\"201511031758110280\",\"InvoiceNumber\":\"DS12223139\",\"TotalAmt\":348,\"InvoiceTransNo\":\"15110317583641325\",\"RandomNum\":\"4253\",\"CreateTime\":\"2015-11-03 17:58:36\",\"BarCode\":\"10412DS122231394253\",\"QRcodeL\":\"DS12223139...\",\"QRcodeR\":\"**商品一:2:99:商品二:3:50\"}"
}
```

### 1.3 Response（String 模式）

回傳為 url-encoded query string，欄位平鋪：`Status`、`Message`、`MerchantID`、
`InvoiceTransNo`、`MerchantOrderNo`、`TotalAmt`、`InvoiceNumber`、`RandomNum`、
`CreateTime`、`CheckCode`、`BarCode`、`QRcodeL`、`QRcodeR`，**結尾固定
`EndStr=##`**（型態 Varchar(2)，用於判斷資料傳遞完整）。

String 回應範例（urldecode 後）：

```
Status=SUCCESS&Message=電子發票開立成功&Result=&CheckCode=2676BC6A...&InvoiceNumber=DS12223164&InvoiceTransNo=15110411233370252&MerchantID=3502275&TotalAmt=365&RandomNum=2909&MerchantOrderNo=201511041123260656&CreateTime=2015-11-04 11:23:33&BarCode=10412DS122231642909&QRcodeL=...&QRcodeR=**商品一:2:99:商品二:3:50&EndStr=##
```

---

## 2. 觸發開立發票 invoice_touch_issue

- 測試：`https://cinv.ezpay.com.tw/Api/invoice_touch_issue`
- 正式：`https://inv.ezpay.com.tw/Api/invoice_touch_issue`

**適用條件**：發票開立方式須為「等待觸發開立」或「預約自動開立」。
觸發後立即開出發票。

### 2.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `TransNum` | ezPay 平台交易序號 |  | Varchar(20) | 同時用 ezPay 簡單付金流時帶交易序號；否則不帶 |
| `InvoiceTransNo` | ezPay 電子發票開立序號 | V | Varchar(20) | 開立發票時回傳的開立序號 |
| `MerchantOrderNo` | 自訂編號 | V | Varchar(20) | 商店自訂訂單編號，限英、數字、`_`，同商店不可重覆 |
| `TotalAmt` | 發票金額 | V | Int(10) | 此次開立發票金額 |

### 2.2 Response

JSON 模式最外層 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `InvoiceTransNo` | ezPay 電子發票開立序號 | Varchar(20) | |
| `MerchantOrderNo` | 自訂編號 | Varchar(20) | |
| `TotalAmt` | 發票金額 | Int(10) | |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | 此次開立的發票號碼 |
| `RandomNum` | 發票防偽隨機碼 | Varchar(4) | 4 碼防偽隨機碼 |
| `CreateTime` | 開立發票時間 | DateTime | |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |

String 模式欄位平鋪同上（`Status`、`Message`、`MerchantID`、`InvoiceTransNo`、
`MerchantOrderNo`、`TotalAmt`、`InvoiceNumber`、`RandomNum`、`CreateTime`、
`CheckCode`）。

JSON 回應範例：

```json
{"Status":"SUCCESS","Message":"電子發票開立成功","Result":"{\"MerchantID\":\"3622183\",\"InvoiceTransNo\":\"14092217121396096\",\"MerchantOrderNo\":\"201409221711472316\",\"TotalAmt\":\"500\",\"RandomNum\":\"0142\",\"CreateTime\":\"2014-09-22 17:12:13\",\"InvoiceNumber\":\"AB10000001\",\"CheckCode\":\"F3BB07F44794AEB98A280F78133AA59B7332EE3DEF470EB837D2BEB4A6196780\"}"}
```

---

## 3. 作廢發票 invoice_invalid

- 測試：`https://cinv.ezpay.com.tw/Api/invoice_invalid`
- 正式：`https://inv.ezpay.com.tw/Api/invoice_invalid`

### 3.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `InvoiceNumber` | 發票號碼 | V | Varchar(10) | 欲執行作廢之發票號碼 |
| `InvalidReason` | 作廢原因 | V | Varchar(6) | 限中文 6 字或英文 20 字 |

### 3.2 Response

JSON 模式 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | 此次作廢的發票號碼 |
| `CreateTime` | 作廢發票時間 | DateTime | 例 `2015-07-16 17:00:33` |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |

String 模式欄位平鋪：`Status`、`Message`、`MerchantID`、`InvoiceNumber`、
`CreateTime`、`CheckCode`。

JSON 回應範例：

```json
{"Status":"SUCCESS","Message":"電子發票作廢開立成功","Result":"{\"CheckCode\":\"01DD7B45A33B9647536D81656C6B3E93B218802480B94EE0674D92D6BDB3204A\",\"MerchantID\":\"3459997\",\"InvoiceNumber\":\"OU00122220\",\"CreateTime\":\"2015-07-16 17:00:33\"}"}
```

---

## 4. 開立折讓 allowance_issue

- 測試：`https://cinv.ezpay.com.tw/Api/allowance_issue`
- 正式：`https://inv.ezpay.com.tw/Api/allowance_issue`

### 4.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.3`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `InvoiceNo` | 發票號碼 | V | Varchar(10) | 此次開立折讓的發票號碼（注意欄位名為 `InvoiceNo`，非 `InvoiceNumber`）|
| `MerchantOrderNo` | 自訂編號 | V | Varchar(20) | 此次折讓發票於開立發票時提供的自訂編號 |
| `ItemName` | 折讓商品名稱 | V | Varchar(30) | 單一商品；多項以 `|` 分隔。例 `商品一|商品二` |
| `ItemCount` | 折讓商品數量 | V | Int(5) | 純數字；多項以 `|` 分隔。例 `1|2` |
| `ItemUnit` | 折讓商品單位 | V | Varchar(2) | 中文 2 字或英數 6 字；多項以 `|` 分隔。例 `個|本` |
| `ItemPrice` | 折讓商品單價 | V | Int(10) | 純數字。可為未稅或含稅（含稅時 `ItemTaxAmt=0`，申報無法扣抵）；多項以 `|` 分隔 |
| `ItemAmt` | 折讓商品小計 | V | Int(10) | 純數字。數量 × 單價 = 小計；多項以 `|` 分隔 |
| `TaxTypeForMixed` | 折讓課稅別 |  | Int(2) | 僅原發票 `TaxType=9` 時必填。混合稅率需依應稅 / 零稅率 / 免稅個別開折讓單。`1`=應稅、`2`=零稅率、`3`=免稅 |
| `ItemTaxAmt` | 折讓商品稅額 | V | Int(10) | 純數字。ItemPrice 未稅 → 小計 × 稅率；ItemPrice 含稅 → `0`；多項以 `|` 分隔 |
| `TotalAmt` | 折讓總金額 | V | Int(10) | 此次開立折讓加總金額 |
| `BuyerEmail` | 買受人電子信箱 |  | Varchar(50) | 折讓開立時寄送折讓查詢資訊至此信箱 |
| `Status` | 確認折讓方式 | V | Varchar(1) | `0`=開立折讓後不立即確認（待買受人確認後再發動 allowance_touch_issue）、`1`=開立後立即確認 |

### 4.2 Response

JSON 模式 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `AllowanceNo` | 折讓號 | Varchar(20) | 此次開立折讓的折讓號 |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | 此次開立折讓的發票號碼 |
| `MerchantOrderNo` | 自訂編號 | Varchar(20) | 此折讓發票於開立發票時的自訂編號 |
| `AllowanceAmt` | 折讓金額 | Int(10) | 此次開立折讓的金額 |
| `RemainAmt` | 折讓後剩餘發票金額 | Int(10) | 確認折讓後此張發票剩餘之發票金額 |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |

String 模式欄位平鋪：`Status`、`Message`、`MerchantID`、`AllowanceNo`、
`InvoiceNumber`、`MerchantOrderNo`、`AllowanceAmt`、`RemainAmt`、`CheckCode`。

JSON 回應範例：

```json
{"Status":"SUCCESS","Message":"電子發票開立成功","Result":"{\"MerchantID\":\"3622183\",\"AllowanceNo\":\"A151015111705007\",\"MerchantOrderNo\":\"202E19\",\"AllowanceAmt\":\"500\",\"RemainAmt\":\"0\",\"CheckCode\":\"F3BB07F44794AEB98A280F78133AA59B7332EE3DEF470EB837D2BEB4A6196780\"}"}
```

---

## 5. 觸發確認/取消折讓 allowance_touch_issue

- 測試：`https://cinv.ezpay.com.tw/Api/allowance_touch_issue`
- 正式：`https://inv.ezpay.com.tw/Api/allowance_touch_issue`

**適用條件**：開立折讓時 `Status=0`（不立即確認），折讓資料僅記錄於平台未上傳財政部。
營業人與買受人確認後執行：
- 確認折讓 → 平台隔日上傳財政部。
- 取消折讓 → 折讓狀態變更為取消。**已確認折讓後無法再取消**。

### 5.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `AllowanceStatus` | 觸發折讓狀態 | V | Varchar(1) | `C`=確認折讓、`D`=取消折讓 |
| `AllowanceNo` | 折讓號 | V | Varchar(20) | 開立折讓時的折讓號 |
| `MerchantOrderNo` | 自訂編號 | V | Varchar(20) | 此折讓發票於開立發票時的自訂編號 |
| `TotalAmt` | 折讓總金額 | V | Int(10) | 此折讓之總金額 |

### 5.2 Response

JSON 模式 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `AllowanceNo` | 折讓號 | Varchar(20) | |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | |
| `MerchantOrderNo` | 自訂編號 | Varchar(20) | |
| `AllowanceAmt` | 折讓金額 | Int(10) | |
| `RemainAmt` | 折讓後剩餘發票金額 | Int(10) | |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |

String 模式欄位平鋪同 §4.2。

JSON 回應範例：

```json
{"Status":"SUCCESS","Message":"電子發票開立成功","Result":"{\"MerchantID\":\"3622183\",\"AllowanceNo\":\"A151015111705007\",\"MerchantOrderNo\":\"202E19\",\"AllowanceAmt\":\"500\",\"RemainAmt\":\"0\",\"CheckCode\":\"F3BB07F44794AEB98A280F78133AA59B7332EE3DEF470EB837D2BEB4A6196780\"}"}
```

---

## 6. 作廢折讓 allowanceInvalid

- 測試：`https://cinv.ezpay.com.tw/Api/allowanceInvalid`
- 正式：`https://inv.ezpay.com.tw/Api/allowanceInvalid`

**適用條件**：只能作廢**已確認**的折讓。

### 6.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `AllowanceNo` | 折讓號 | V | Varchar(25) | 欲執行作廢之折讓號 |
| `InvalidReason` | 作廢原因 | V | Varchar(6) | 限中文 6 字或英文 20 字 |

### 6.2 Response

JSON 模式 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `AllowanceNo` | 折讓號 | Varchar(25) | 此次作廢折讓的折讓號 |
| `CreateTime` | 作廢折讓時間 | DateTime | 例 `2015-07-16 17:00:33` |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |

String 模式欄位平鋪：`Status`、`Message`、`MerchantID`、`AllowanceNo`、
`CreateTime`、`CheckCode`。

JSON 回應範例：

```json
{"Status":"SUCCESS","Message":"作廢折讓成功","Result":"{\"MerchantID\":\"3622183\",\"AllowanceNo\":\"A180528095517632\",\"CreateTime\":\"2018-05-28 09:55:45\",\"CheckCode\":\"1C428B8EF5E89C3CB303567AFF04F71BA3803103D162948F3AEAC55831E7C0AA\"}"}
```

---

## 7. 查詢發票 invoice_search

- 測試：`https://cinv.ezpay.com.tw/Api/invoice_search`
- 正式：`https://inv.ezpay.com.tw/Api/invoice_search`

### 7.1 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.3`** |
| `TimeStamp` | 時間戳記 | V | Varchar(30) | Unix 時間戳（秒）|
| `SearchType` | 查詢方式 |  | Varchar(1) | `0`=用發票號碼 + 隨機碼查詢、`1`=用訂單編號 + 發票金額查詢。不帶則預設 `0` |
| `MerchantOrderNo` | 訂單編號 | 條件 | Varchar(20) | `SearchType=1` 時必填：開立時帶的自訂編號 |
| `TotalAmt` | 發票金額 | 條件 | Varchar(10) | `SearchType=1` 時必填：開立發票的總金額 |
| `InvoiceNumber` | 發票號碼 | 條件 | Varchar(10) | `SearchType=0` 時必填：發票號碼 |
| `RandomNum` | 發票防偽隨機碼 | 條件 | Varchar(4) | `SearchType=0` 時必填：開立時回傳的 4 碼防偽隨機碼 |
| `DisplayFlag` | 是否於本平台網頁顯示發票查詢結果 |  | Varchar(1) | 值為 `1` 時須以 Form Post 將網頁控制權送至平台，由平台網頁顯示結果；不帶則以參數回傳發票資料 |

> 原文表格把四個查詢鍵都標 `V`（必填），但實際依 `SearchType` **二擇一**：
> `0` 用 `InvoiceNumber + RandomNum`；`1` 用 `MerchantOrderNo + TotalAmt`。
>
> **`SearchType=1` 的用途**：本地只知道自訂編號、沒拿到發票號碼時（例如開立請求逾時、
> 或重送撞 `LIB10003`），用它取回平台上已存在的那張發票。見 `concepts.md`「重送與冪等」。
>
> **`DisplayFlag=1` 的用法**：這不是後端 API 呼叫，而是在**瀏覽器**產生一個自動送出的
> `<form method="post" action=".../Api/invoice_search">`，欄位同樣是 `MerchantID_` +
> `PostData_`（`PostData_` 內加 `DisplayFlag=1`），讓顧客直接看到平台的發票頁。
> `PostData_` 必須在後端加密後才輸出到頁面，HashKey / HashIV 不可出現在前端。

### 7.2 Response（JSON 模式）

JSON 模式 `{ Status, Message, Result }`；`Result`（parse 後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `MerchantID` | 商店代號 | Varchar(15) | |
| `InvoiceTransNo` | ezPay 電子發票開立序號 | Varchar(20) | 發票開立時的開立序號 |
| `MerchantOrderNo` | 自訂編號 | Varchar(20) | 開立發票時帶入的自訂編號 |
| `InvoiceNumber` | 發票號碼 | Varchar(10) | 此次查詢的發票號碼 |
| `RandomNum` | 發票防偽隨機碼 | Varchar(4) | 開立時產生的 4 碼防偽隨機碼 |
| `BuyerName` | 買受人名稱 | Varchar(60) | 個人姓名或營業人名稱 |
| `BuyerUBN` | 買受人統一編號 | Varchar(10) | |
| `BuyerAddress` | 買受人地址 | Varchar(150) | |
| `BuyerPhone` | 買受人手機號碼 | Varchar(15) | |
| `BuyerEmail` | 買受人電子信箱 | Varchar(100) | |
| `InvoiceType` | 發票字軌類型 | Varchar(2) | `07`=一般稅額計算、`08`=特種稅額計算 |
| `Category` | 發票種類 | Varchar(5) | `B2B`=買受人為營業人（有統編）、`B2C`=買受人為個人 |
| `TaxType` | 課稅別 | Varchar(2) | `1`=應稅、`2`=零稅率、`3`=免稅、`9`=混合 |
| `TaxRate` | 稅率 | Float(6,4) | 例 `0.05` |
| `Amt` | 銷售額合計 | Int(10) | 發票銷售額（應稅未稅金額）|
| `AmtSales` | 銷售額（應稅） | Int(10) | 課稅別應稅之未稅金額（`TaxType=9` 時提供）|
| `AmtZero` | 銷售額（零稅率） | Int(10) | 課稅別零稅率之未稅金額（`TaxType=9` 時提供）|
| `AmtFree` | 銷售額（免稅） | Int(10) | 課稅別免稅之未稅金額（`TaxType=9` 時提供）|
| `TaxAmt` | 稅額 | Int(10) | 發票稅額 |
| `TotalAmt` | 發票金額 | Int(10) | 發票總金額（含稅）|
| `CarrierType` | 載具類別 | Varchar(2) | `0`=手機條碼、`1`=自然人憑證、`2`=ezPay 載具；無載具空值（僅 B2C 適用）|
| `CarrierNum` | 載具編號 | Varchar(50) | 該張發票儲存的載具編號 |
| `LoveCode` | 捐贈碼 | Varchar(10) | 捐贈的捐贈碼；不捐贈空值（僅 B2C 適用）|
| `PrintFlag` | 索取紙本發票 | Varchar(1) | `Y`=索取、`N`=不索取 |
| `KioskPrintFlag` | 是否開放至合作超商 Kiosk 列印 | Varchar(1) | `1`=發票中獎後開放 |
| `CreateTime` | 開立發票時間 | DateTime | 例 `2014-09-25 12:12:12` |
| `ItemDetail` | 商品明細 | Text | 開立時的商品資訊（JSON 格式）— 欄位：`ItemNum`(品項序號) / `ItemName` / `ItemCount` / `ItemWord`(單位) / `ItemPrice` / `ItemAmount`(小計) / `ItemTaxType` |
| `InvoiceStatus` | 發票狀態 | Varchar(1) | `1`=已開立（有發票號碼）、`2`=已作廢 |
| `UploadStatus` | 發票上傳狀態 | Varchar(1) | `0`=未上傳、`1`=已上傳成功、`2`=上傳中、`3`=上傳失敗、`4`=上傳逾時 |
| `CheckCode` | 檢查碼 | Varchar(64) | SHA256 驗證碼 |
| `BarCode` | 發票條碼 | Varchar(19) | 僅 `PrintFlag=Y` 時提供 |
| `QRcodeL` | 發票 QRCode（左） | Varchar(140) | 僅 `PrintFlag=Y` 時提供 |
| `QRcodeR` | 發票 QRCode（右） | Varchar(140) | 僅 `PrintFlag=Y` 時提供 |

> String 模式回應欄位與 JSON `Result` 相同，平鋪在 query string，**結尾固定
> `EndStr=##`**（Varchar(2)）。

JSON 回應範例（節錄）：

```json
{
  "Status": "SUCCESS",
  "Message": "查詢成功",
  "Result": "{\"MerchantID\":\"3757976\",\"InvoiceTransNo\":\"20020310232543048\",\"MerchantOrderNo\":\"1580696208\",\"InvoiceNumber\":\"BA00000007\",\"RandomNum\":\"4234\",\"BuyerName\":\"許功蓋\",\"InvoiceType\":\"07\",\"Category\":\"B2C\",\"TaxType\":\"1\",\"TaxRate\":\"0.05000\",\"Amt\":\"1333\",\"TaxAmt\":\"67\",\"TotalAmt\":\"1400\",\"PrintFlag\":\"Y\",\"CreateTime\":\"2020-02-03 10:23:25\",\"ItemDetail\":\"[{...}]\",\"InvoiceStatus\":\"1\",\"UploadStatus\":\"1\",\"CheckCode\":\"B50FF4312AAA34812D34683DD94427FC876F5CD4E50BA268BF2271834AEFB6DF\"}"
}
```

`ItemDetail` parse 後範例：

```json
[
  {"ItemNum":"","ItemName":"商品1","ItemCount":1,"ItemWord":"","ItemPrice":100,"ItemAmount":100,"ItemTaxRate":"","ItemRateAmt":"","ItemAmt":""},
  {"ItemNum":"","ItemName":"商品2","ItemCount":2,"ItemWord":"","ItemPrice":200,"ItemAmount":400,"ItemTaxRate":"","ItemRateAmt":"","ItemAmt":""},
  {"ItemNum":"","ItemName":"商品3","ItemCount":3,"ItemWord":"","ItemPrice":300,"ItemAmount":900,"ItemTaxRate":"","ItemRateAmt":"","ItemAmt":""}
]
```

---

## 金額計算

### 平台檢核範圍

ezPay 平台只做兩項計算檢核（其餘金額正確性由營業人 / 財會負責）：

1. **開立發票**：商品小計 = 商品數量 × 商品單價；發票金額 = 銷售額 + 稅額。
   即 `ItemAmt = ItemCount × ItemPrice`、`TotalAmt = Amt + TaxAmt`。
2. **開立折讓**：折讓總金額 = 折讓商品小計 + 折讓商品稅額。
   即 `TotalAmt = Σ ItemAmt + Σ ItemTaxAmt`。

> 以下演算法中的 `round()` 為**四捨五入到整數**。捨入方式與稅額算法屬財會決策，
> ezPay 不檢核——上線前請與財會確認。所有範例皆為一般應稅 5%。

### B2C 範例（單價含稅）

買受人為個人，`Category=B2C`，`ItemPrice` / `ItemAmt` 為**含稅**金額。

```
TotalAmt = Σ ItemAmt                 # 含稅總額 = 顧客實付
Amt      = round(TotalAmt / 1.05)    # 未稅銷售額
TaxAmt   = TotalAmt − Amt            # 稅額用減法得出，保證加總相等
```

兩項商品含稅小計 200 + 200：

```
TotalAmt = 400
Amt      = round(400 / 1.05) = round(380.95) = 381
TaxAmt   = 400 − 381 = 19
# 檢核：Amt + TaxAmt = 381 + 19 = 400 = TotalAmt ✓
```

### B2B 範例（單價未稅）

買受人為營業人，`Category=B2B`，`ItemPrice` / `ItemAmt` 為**未稅**金額。

**情境 A：商品本身以未稅價計價**（報價單式 B2B）

```
Amt      = Σ ItemAmt                 # 未稅銷售額
TaxAmt   = round(Amt × 0.05)
TotalAmt = Amt + TaxAmt
```

未稅 300 + 200：`Amt=500`、`TaxAmt=25`、`TotalAmt=525`。

**情境 B：電商結帳，顧客付的是含稅價、事後要打統編**（最常見）

顧客實付是含稅總額，必須反推各列未稅金額，並讓各列加總剛好等於 `Amt`：

```
TotalAmt   = 顧客實付含稅總額
Amt        = round(TotalAmt / 1.05)
TaxAmt     = TotalAmt − Amt
各列未稅小計 = round(該列含稅小計 / 1.05)
尾差        = Amt − Σ 各列未稅小計      # 通常是 −1、0 或 +1
→ 把尾差加到金額最大的那一列（同額時取第一列），讓 Σ ItemAmt = Amt
```

三件含稅 100 元的商品（三列各 1 件）：

```
TotalAmt = 300
Amt      = round(300 / 1.05) = round(285.71) = 286
TaxAmt   = 300 − 286 = 14
各列未稅  = round(100 / 1.05) = 95 → 95 + 95 + 95 = 285
尾差      = 286 − 285 = +1 → 三列同額，加到第一列 → 96
ItemPrice = 96|95|95、ItemAmt = 96|95|95
# 檢核：Σ ItemAmt = 286 = Amt ✓；Amt + TaxAmt = 300 = TotalAmt ✓
```

> **官方 PHP 範例的 B2B 數字不能照抄**：範例帶 `Amt=490 / TaxAmt=10 / TotalAmt=500`，
> 10 並不是 490 的 5%（應為 25）。那組數字只示範欄位格式，能過平台是因為平台
> **只檢核** `Amt + TaxAmt = TotalAmt`，不檢核稅額是否等於 `Amt × 稅率`。

### TaxType=9 混合稅率（僅 B2C）

`Category=B2C` 才可用 `TaxType=9`。需提供 `AmtSales`（應稅，未稅）/ `AmtZero`（零稅率）/
`AmtFree`（免稅）三個分項銷售額，每項商品以 `ItemTaxType`（`1`/`2`/`3`，多項用 `|`
分隔）標示課稅別，且：

```
Amt = AmtSales + AmtZero + AmtFree
```

範例：一件應稅商品含稅 210 元、一件免稅商品 100 元：

```
ItemName    = 應稅商品|免稅商品
ItemPrice   = 210|100           # B2C → 含稅；免稅品本來就沒有稅
ItemAmt     = 210|100
ItemTaxType = 1|3
TaxType     = 9
TaxRate     = 5
AmtSales    = round(210 / 1.05) = 200
AmtZero     = 0
AmtFree     = 100
Amt         = 200 + 0 + 100 = 300
TaxAmt      = 210 − 200 = 10     # 只有應稅部分有稅
TotalAmt    = 310
# 檢核：Amt + TaxAmt = 310 = TotalAmt ✓
```

> 此範例依原文欄位定義推導（原文沒有 TaxType=9 的完整數字範例），送出前請與財會確認，
> 並在沙箱實測。混合稅率發票開折讓時，需依應稅 / 零稅率 / 免稅**個別開立折讓單**，
> 並用 `TaxTypeForMixed` 指定該折讓單的課稅別。

### 整數化：小數單價、折扣、運費

原文把 `ItemCount` / `ItemPrice` / `ItemAmt` 定義為 `Int` 且「純數字」，**沒有提到小數或負數**。
在沙箱確認前，一律送**正整數**，照下列規則把訂單明細轉成發票品項：

| 情況 | 做法 |
|------|------|
| 該列小計能被數量整除 | `ItemCount=數量`、`ItemPrice=小計÷數量`、`ItemAmt=小計` |
| 該列小計**不能**被數量整除（例：3 件共 100 元）| 合成一列：`ItemCount=1`、`ItemPrice=ItemAmt=小計`，品名後綴 ` x3`（注意 `ItemName` 上限 30 字）|
| 訂單有折扣（折價券、滿額折）| 依各商品列小計**按比例攤進商品列**（折後價），尾差由金額最大的一列吸收；**不要**送負數列 |
| 運費、手續費等費用 | 各自成一列正數（例：`運費`、`1`、`式`、`60`）|
| 0 元列（贈品）| 原文未說明 0 元是否可送；預設**省略**，要保留紀錄可寫進 `Comment` |

送出前的本地檢核（任何一條不成立就不要送出，直接記為本地錯誤）：

```
1. 每列 ItemAmt = ItemCount × ItemPrice，且三者皆為正整數
2. B2C：Σ ItemAmt = TotalAmt；B2B：Σ ItemAmt = Amt
3. Amt + TaxAmt = TotalAmt
4. TotalAmt = 訂單實付總額
5. 品名已移除 `|`（與多品項分隔符衝突），且每個品名 ≤ 30 字
```

> **負數折扣列**（每張折價券一列負數，規格 INV-204 的模型 b）是否被 ezPay 接受，
> **原文未說明**，列入 SKILL.md「未確認事項」。沙箱實測通過前，不要採用。
