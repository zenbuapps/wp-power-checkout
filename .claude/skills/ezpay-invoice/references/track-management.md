# ezPay 電子發票 字軌管理 API Reference（逐欄位）

對應官方《電子發票字軌管理 技術串接手冊》標準版，文件版本號 **EZP_Track_1.0.0**
（程式版本號 1.0，2018/10/03 初版；修訂紀錄僅此一版）。
所有 API 皆為 HTTP POST + 標準 Form Post（UTF-8），平台以 Web Service 方式回應。
POST body 固定兩欄：`CompanyID_`（會員編號明文）、`PostData_`（業務參數 AES 加密 hex）。

> **與開立發票 API（EZP_INVI，見 api-reference.md）最大差別**：識別欄位是
> **`CompanyID_`（會員編號）**，不是 `MerchantID_`（商店代號）；金鑰取自
> **會員** API 串接金鑰。加密演算法本身完全相同，詳見[加密方式](#加密方式)。

## 目錄

- [用途總覽](#用途總覽)
- [環境與金鑰](#環境與金鑰)
- [傳輸層共用參數](#傳輸層共用參數)
- [加密方式](#加密方式)
- [CheckCode 回應驗證](#checkcode-回應驗證)
- [1. 新增字軌 createNumber](#1-新增字軌-createnumber)
- [2. 字軌資料管理 manageNumber](#2-字軌資料管理-managenumber)
- [3. 字軌資料查詢 searchNumber](#3-字軌資料查詢-searchnumber)
- [錯誤代碼](#錯誤代碼)
- [字軌業務規則](#字軌業務規則)
- [與開立發票 INV90006 的關係](#與開立發票-inv90006-的關係)
- [原文瑕疵與注意事項](#原文瑕疵與注意事項)
- [實作建議（非原文）](#實作建議非原文)

---

## 用途總覽

原文共 **3 支 API**，路徑前綴皆為 `/Api_number_management/`：

| 功能 | 路徑（接在網域後） | PostData_ `Version` | 用途 |
|------|---------------------|---------------------|------|
| 新增字軌 | `/Api_number_management/createNumber` | `1.0` | 新增**單筆**字軌資料 |
| 字軌資料管理 | `/Api_number_management/manageNumber` | `1.0` | 變更**單筆**字軌的使用狀態（啟用 / 暫停 / 停用）|
| 字軌資料查詢 | `/Api_number_management/searchNumber` | `1.0` | 查詢字軌資料（原文寫「單筆」，但回應可含多組，見 §3）|

原文「其他說明」重點：

- **本平台無法查驗營業人上傳的發票字軌是否正確**，以程式串接字軌管理功能時務必自行
  確認字軌資料正確。
- 若誤用非本期別字軌、誤用其他營業人字軌、重覆開立相同字軌等，需由**營業人自行**
  向所屬國稅分局 / 稽徵所、財政部財政資訊中心、財政部印刷廠、國稅局總局報備與後續
  處理；ezPay 加值服務中心僅能協助相關資料修正及上傳。

---

## 環境與金鑰

| 環境 | 平台網址 | 取得金鑰位置 | 查看字軌位置 |
|------|----------|--------------|--------------|
| 測試 | `https://cinv.ezpay.com.tw/` | 【會員管理/基本資料設定/基本資料】→ 測試**會員** API 串接金鑰 Hash Key / Hash IV | 【發票管理/管理設定/發票字軌號碼設定】|
| 正式 | `https://inv.ezpay.com.tw/` | 【會員管理/基本資料設定/基本資料】→ **會員** API 串接金鑰 Hash Key / Hash IV | 【管理設定/發票字軌號碼設定】|

- 原文明確寫「**會員** API 串接金鑰」。開立發票 API 使用的是**商店**的 HashKey /
  HashIV；兩者是否為同一組金鑰，**原文未說明**——實作時應分開設定、分開存放。
- 資料交換方式：會員以 HTTP POST 傳送 → 標準 Form Post → 平台以 Web Service 回應 →
  UTF-8。

---

## 傳輸層共用參數

每支 API 的 POST body 皆為下列兩欄（**參數名稱結尾都有底線 `_`**）：

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `CompanyID_` | 會員編號 | V | Varchar(16) | ezPay 電子發票加值服務平台會員編號 |
| `PostData_` | 加密資料 | V | text | 業務參數經 AES 加密後的 hex 字串（見[加密方式](#加密方式)）|

`PostData_` 內含欄位每支 API 不同，見下方各節。三支 API 共通的前三欄：

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **三支 API 皆固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(10) | Unix 時間戳（秒），PHP 即 `time()`。例：2014-05-15 15:00:00 → `1400137200` |

> 注意欄位名 **`AphabeticLetter`**（原文拼法，少一個 `l`，不是 `AlphabeticLetter`）。
> 送出與解析都必須用原文拼法。

---

## 加密方式

**與開立發票 API（EZP_INVI）完全相同**：AES-256-CBC + 自行補 PKCS#7（blocksize 32）
+ 加密時關閉內建 padding + 輸出小寫 hex。原文附件一的 PHP / .NET C# 範例與 EZP_INVI
逐行一致（`addpadding($string, $blocksize = 32)`、`OPENSSL_RAW_DATA |
OPENSSL_ZERO_PADDING`、C# `AddPKCS7Padding(..., 32)` + `PaddingMode.None` +
`.ToLower()`）。演算法細節、Node.js 注意事項見 concepts.md / integration.md。

| 項目 | 字軌管理 API（EZP_Track） | 開立發票 API（EZP_INVI） |
|------|---------------------------|---------------------------|
| 演算法 | AES-256-CBC | 相同 |
| Padding | PKCS#7 自行補，blocksize **32** | 相同 |
| 輸出 | 小寫 hex | 相同 |
| 明文識別欄位 | **`CompanyID_`**（會員編號，Varchar(16)）| `MerchantID_`（商店代號，Varchar(15)）|
| 金鑰來源 | **會員** API 串接金鑰 | 商店 HashKey / HashIV |
| `TimeStamp` 型態 | Varchar(10) | Varchar(30) |
| CheckCode 組成欄位 | `AphabeticLetter` / `CompanyId` / `EndNumber` / `ManagementNo` / `StartNumber` | `InvoiceTransNo` / `MerchantID` / `MerchantOrderNo` / `RandomNum` / `TotalAmt` |
| CheckCode 前綴字面 | 原文範例 **`HashIv=`**（小寫 v，見下節）| `HashIV=` |
| CheckCode 型態 | Varchar(150) | Varchar(64) |
| JSON `Result` 型態 | **JSON 物件**（searchNumber 為**陣列**），直接取值 | JSON 字串，需再 parse 一次 |

原文附件一 PHP 範例重點（新增字軌）：

```php
$post_data_array = array(
    'RespondType'     => 'JSON',
    'Version'         => '1.0',
    'TimeStamp'       => ' 1444963784',   // 原文此處前面多一個空白（範例瑕疵，勿照抄）
    'Year'            => '107',
    'Term'            => '2',
    'AphabeticLetter' => 'AA',
    'StartNumber'     => '00000001',
    'EndNumber'       => '00000050',
    'Type'            => '07'
);
$post_data_str = http_build_query($post_data_array);
$key = 'abcdefghijklmnopqrstuvwxyzabcdef';   // 會員專屬串接金鑰 HashKey
$iv  = '1234567891234567';                   // 會員專屬串接金鑰 HashIV

if (phpversion() >= 7) {
    $post_data = trim(bin2hex(openssl_encrypt(addpadding($post_data_str),
        'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)));
} else {
    $post_data = trim(bin2hex(mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key,
        addpadding($post_data_str), MCRYPT_MODE_CBC, $iv)));
}

$url = 'https://inv.ezpay.com.tw/Api_number_management/createNumber';
$result = curl_work($url, http_build_query(array(
    'CompanyID_' => 'C54352706',   // 會員編號
    'PostData_'  => $post_data,
)));
```

- 加密前明文（原文）：
  `RespondType=JSON&Version=1.0&TimeStamp=1444963784&Year=107&Term=2&AphabeticLetter=AA&StartNumber=00000001&EndNumber=00000050&Type=07`
  （132 bytes）。原文註明「在 `Type=07` 後面補足 28 個 `0x1c`」——132 mod 32 = 4，
  32 − 4 = 28 = `0x1c`，與 PKCS#7 規則吻合。
- 原文 `curl_work()` 設 `CURLOPT_SSL_VERIFYPEER => FALSE` / `CURLOPT_SSL_VERIFYHOST =>
  FALSE`、UA `Google Bot`——僅為範例，正式環境應開啟 TLS 驗證。
- 原文 PHP 範例只示範 createNumber；manageNumber / searchNumber 只差 URL 與 PostData_
  欄位，原文未另附範例程式。

---

## CheckCode 回應驗證

每筆字軌資料回應都帶 `CheckCode`（Varchar(150)），用來驗證回應確實來自 ezPay。

### 計算規則（原文附件二）

1. 取回傳資料中的**五個欄位**：`AphabeticLetter`（字軌英文代碼）、`CompanyId`
   （會員編號）、`EndNumber`（發票結束號碼）、`ManagementNo`（字軌管理編號）、
   `StartNumber`（發票起始號碼），依參數名 A~Z 排序（首字母相同比次字母），用 `&` 串聯。
2. 字串前後加上會員的 Hash IV 與 Hash Key。
3. 整串 SHA256 後**轉大寫**。

> `CompanyId` **不在**回應欄位裡（回應沒有會員編號），要用自己送出的 `CompanyID_` 值；
> 且在 CheckCode 字串中拼作 **`CompanyId`**（小寫 d），與 POST 參數 `CompanyID_` 不同。

### 原文範例

```
(1) 排列參數欄位並串聯
AphabeticLetter=AA&CompanyId=C54352706&EndNumber=00000001&ManagementNo=0o455ujp8&StartNumber=00000050

(2) 前後加上 Hash IV 及 Hash Key
HashIv=1234567891234567&AphabeticLetter=AA&CompanyId=C000000001&EndNumber=00000001&ManagementNo=0o455ujp8&StartNumber=00000050&HashKey=abcdefghijklmnopqrstuvwxyzabcdef

(3) SHA256 後轉大寫
5F9F7ABDE032F78CCDF3E4FB8A53D80A09190846D906DA84B26A105857AB2490
```

原文 PHP：

```php
$check_code_array = array(
    'AphabeticLetter' => 'AA',          // 發票字軌
    'CompanyId'       => 'C54352706',   // 會員編號
    'EndNumber'       => '00000001',    // 發票結束號碼
    'ManagementNo'    => '0o455ujp8',   // 字軌流水號
    'StartNumber'     => '00000050'     // 發票起始號碼
);
ksort($check_code_array);
$check_code = strtoupper(hash('sha256', 'HashIv=1234567891234567&' .
    http_build_query($check_code_array) .
    '&HashKey=abcdefghijklmnopqrstuvwxyzabcdef'));
// 5F9F7ABDE032F78CCDF3E4FB8A53D80A09190846D906DA84B26A105857AB2490
```

> **已實算驗證（本檔撰寫時以原文測試值計算）**：在 `HashIv=` / `HashIV=` ×
> `CompanyId=C54352706` / `C000000001` × 起訖是否對調的 8 種組合中，只有
> `HashIv=`（小寫 v）+ `CompanyId=C54352706` + 原文值（Start=00000050、End=00000001）
> 能算出原文的 `5F9F7ABD…2490`。
> - 步驟 (2) 文字裡的 `CompanyId=C000000001` 是**原文筆誤**，以 PHP 程式為準。
> - 前綴寫成 `HashIV=`（大寫 V，開立發票 API 的寫法）會算出不同雜湊。原文字軌管理只
>   示範 `HashIv=`；正式環境實際接受哪一種，**原文未說明**，上線前請用測試環境回應實測。
> - 範例值 `StartNumber=00000050`、`EndNumber=00000001` 起訖顛倒，只是示範資料。

**searchNumber 多筆回應**：每一組字軌各自帶一個 `CheckCode`，需逐筆以該組的
`AphabeticLetter` / `EndNumber` / `ManagementNo` / `StartNumber` 計算比對。

---

## 1. 新增字軌 createNumber

- 測試：`https://cinv.ezpay.com.tw/Api_number_management/createNumber`
- 正式：`https://inv.ezpay.com.tw/Api_number_management/createNumber`

**用途**：新增單筆字軌資料，新增結果以回傳參數回覆。讓營業人可在自家網站新增字軌。

### 1.1 POST 參數

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `CompanyID_` | 會員編號 | V | Varchar(16) | ezPay 電子發票加值服務平台會員編號 |
| `PostData_` | 加密資料 | V | text | 加密方法見附件一（本檔[加密方式](#加密方式)）|

### 1.2 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(10) | Unix 時間戳（秒）。例：2014-05-15 15:00:00 → `1400137200` |
| `Year` | 發票年度 | V | Varchar(3) | 民國年，如 `106`。**只可輸入今年與明年** |
| `Term` | 發票期別 | V | Varchar(1) | 純數字，該組字軌的發票期別：`1`=一、二月、`2`=三、四月、`3`=五、六月、`4`=七、八月、`5`=九、十月、`6`=十一、十二月。**不可輸入小於現在期別** |
| `AphabeticLetter` | 字軌英文代碼 | V | Varchar(2) | 兩碼大寫英文 |
| `StartNumber` | 發票起始號碼 | V | Varchar(8) | 起始號碼，如 `00000001` |
| `EndNumber` | 發票結束號碼 | V | Varchar(8) | 結束號碼，如 `00009999` |
| `Type` | 發票類別 | V | Varchar(2) | 純數字，該組字軌的發票類別：`07`=一般稅額、`08`=特種稅額 |

原文 Form 範例（正式網址；`action` 結尾多一個空白為原文瑕疵）：

```html
<form method="POST" action="https://inv.ezpay.com.tw/Api_number_management/createNumber">
  <input type="text" name="CompanyID_" value="C54352706">
  <input type="text" name="PostData_" value="dbb509403f0997...5225a4141">
</form>
```

### 1.3 Response（JSON 模式）

最外層：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 建立成功回 `SUCCESS`；新增字軌失敗回錯誤代碼 |
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態說明文字 |
| `Result` | 回傳資料 | JSON | JSON 格式（範例中為**物件**，非字串）|

`Result` 內含欄位：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `ManagementNo` | 字軌管理編號 | Varchar(15) | 該組字軌於 ezPay 平台的流水編號（後續 manage / search 用）|
| `Year` | 發票年度 | Varchar(3) | 民國年，如 `106` |
| `Term` | 發票期別 | Varchar(1) | 純數字，`1`~`6`（同 Request）|
| `AphabeticLetter` | 字軌英文代碼 | Varchar(2) | 兩碼大寫英文 |
| `StartNumber` | 發票起始號碼 | Varchar(8) | 如 `00000001` |
| `EndNumber` | 發票結束號碼 | Varchar(8) | 如 `00009999` |
| `Type` | 發票類別 | Varchar(2) | `07`=一般稅額、`08`=特種稅額 |
| `CreateDatetime` | 字軌建立日期 | Varchar(17) | 建立該組字軌的日期與時間。原文說明寫「如：20161102 10:30:22」，但範例實際回 `2018-04-20 16:53:27` |
| `LastNumber` | 剩餘張數 | Varchar(8) | 純數字，該組字軌剩餘張數 |
| `Flag` | 字軌狀態 | Varchar(1) | `0`=暫停、`1`=啟用、`2`=停用（語意見[字軌業務規則](#字軌業務規則)）|
| `CheckCode` | 檢查碼 | Varchar(150) | 驗證回應合法性，見 [CheckCode](#checkcode-回應驗證) |

JSON 回應範例（原文；`Message` 解碼為「新增字軌成功」）：

```json
{
  "Status": "SUCCESS",
  "Message": "新增字軌成功",
  "Result": {
    "ManagementNo": "0p7f5h7d3",
    "Year": "107",
    "Term": "2",
    "AphabeticLetter": "AA",
    "StartNumber": "00000001",
    "EndNumber": "00000050",
    "Type": "07",
    "CreateDatetime": "2018-04-20 16:53:27",
    "LastNumber": "50",
    "Flag": "1",
    "CheckCode": "898DBF723ACF7205F566237DC1C77B0B764D99D4082D8FC73D1E230B9FE48B72"
  }
}
```

### 1.4 Response（String 模式）

url-encoded query string，欄位平鋪：`Status`、`Message`、`ManagementNo`、`Year`、
`Term`、`AphabeticLetter`、`StartNumber`、`EndNumber`、`Type`、`CreateDatetime`、
`LastNumber`、`Flag`、`CheckCode`，**結尾固定 `EndStr=##`**（Varchar(2)，須判斷
`EndStr=##` 確保資料傳遞完整）。各欄型態同 JSON `Result`（`ManagementNo` Varchar(15)）。

String 回應範例（urldecode 後）：

```
Status=SUCCESS&Message=新增字軌成功&ManagementNo=0p7f6h7wq&Year=107&Term=2&AphabeticLetter=AA&StartNumber=00000001&EndNumber=00000050&Type=07&CreateDatetime=2018-04-20 17:05:14&LastNumber=50&Flag=1&CheckCode=384262AFF336343CAC40FC65023D31CBF31FD0EE3CBE37985A9BE0A6742E86FB&EndStr=##
```

### 1.5 錯誤代碼

原文錯誤代碼為三支 API **共用一張表**，未標示各碼屬於哪支 API，見[錯誤代碼](#錯誤代碼)。
從錯誤原因字面看，與新增較相關的有 `LIB10004`（字軌已存在或在範圍內）、
`LIB10007`（資料已存在，無法重覆建立）、`LIB10016`（相同的字軌已存在）、
`LIB10013`（輸入數值超過許可範圍）、`INM10005` / `LIB10010` / `MOD10001`（新增失敗）
——此對應為字面推論，非原文明示。

---

## 2. 字軌資料管理 manageNumber

- 測試：`https://cinv.ezpay.com.tw/Api_number_management/manageNumber`
- 正式：`https://inv.ezpay.com.tw/Api_number_management/manageNumber`

**用途**：變更**單筆**字軌資料的使用狀態（啟用 / 暫停 / 停用），變更結果以回傳參數
回覆。讓營業人可在自家網站暫停、啟用、停用字軌。原文未提供修改號碼區間、年度、期別
等其他欄位的 API。

### 2.1 POST 參數

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `CompanyID_` | 會員編號 | V | Varchar(16) | ezPay 電子發票加值服務平台會員編號 |
| `PostData_` | 加密資料 | V | text | 加密方法見[加密方式](#加密方式) |

### 2.2 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(10) | Unix 時間戳（秒）|
| `ManagementNo` | 字軌管理編號 | V | Varchar(15) | 該組字軌於 ezPay 平台的流水編號（新增時回傳）|
| `Year` | 發票年度 | V | Varchar(3) | 民國年，如 `106`。**只可輸入今年與明年** |
| `Flag` | 字軌狀態 | V | Varchar(1) | 純數字，帶入該組字軌**欲變更**的使用狀態：`0`=暫停、`1`=啟用、`2`=停用 |

> 原文 manageNumber 的 PostData_ **沒有 `Term`**（新增 / 查詢都有），但 `Year` 為必填。
> `Year` 在此的作用（例如是否需與該字軌的年度一致）**原文未說明**。
> 由於 `Year` 只可帶今年與明年，**去年的字軌能否透過 API 變更狀態**原文亦未說明。

原文 Form 範例：`action="https://inv.ezpay.com.tw/Api_number_management/manageNumber"`，
欄位同 §1（原文 `name=" CompanyID_"` 前面多一個空白，為範例瑕疵，勿照抄）。

### 2.3 Response（JSON 模式）

最外層：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 修改成功回 `SUCCESS`；字軌管理失敗回錯誤代碼 |
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態說明文字 |
| `Result` | 回傳資料 | JSON | JSON 格式（範例中為物件）|

`Result` 內含欄位（與 createNumber 相同）：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `ManagementNo` | 字軌管理編號 | Varchar(15) | 該組字軌於 ezPay 平台的流水編號 |
| `Year` | 發票年度 | Varchar(3) | 民國年 |
| `Term` | 發票期別 | Varchar(1) | `1`~`6` |
| `AphabeticLetter` | 字軌英文代碼 | Varchar(2) | 兩碼大寫英文 |
| `StartNumber` | 發票起始號碼 | Varchar(8) | |
| `EndNumber` | 發票結束號碼 | Varchar(8) | |
| `Type` | 發票類別 | Varchar(2) | `07`=一般稅額、`08`=特種稅額 |
| `CreateDatetime` | 字軌建立日期 | Varchar(17) | 建立該組字軌的日期與時間 |
| `LastNumber` | 剩餘張數 | Varchar(8) | 純數字，該組字軌剩餘張數 |
| `Flag` | 字軌狀態 | Varchar(1) | 變更後狀態：`0`=暫停、`1`=啟用、`2`=停用 |
| `CheckCode` | 檢查碼 | Varchar(150) | 見 [CheckCode](#checkcode-回應驗證) |

JSON 回應範例（原文；`Message` 解碼為「修改字軌狀態成功」）：

```json
{
  "Status": "SUCCESS",
  "Message": "修改字軌狀態成功",
  "Result": {
    "ManagementNo": "0p7f5h7d3",
    "Year": "107",
    "Term": "2",
    "AphabeticLetter": "AA",
    "StartNumber": "00000001",
    "EndNumber": "00000050",
    "Type": "07",
    "CreateDatetime": "2018-04-20 16:53:27",
    "LastNumber": "50",
    "Flag": "0",
    "CheckCode": "C8755AF3E8C65F6F54AEF899F6E55304CDA1648A1E6E67790E747817CACB7DF6"
  }
}
```

### 2.4 Response（String 模式）

欄位平鋪同 §1.4，**結尾固定 `EndStr=##`**。原文此表 `ManagementNo` 標為
**Varchar(13)**（其他各處皆為 Varchar(15)），`Status` 說明沿用新增字軌的文字
（「建立成功 / 新增字軌失敗」），應為原文複製瑕疵。

String 回應範例（urldecode 後）：

```
Status=SUCCESS&Message=修改字軌狀態成功&ManagementNo=0p7f6h7wq&Year=107&Term=2&AphabeticLetter=AS&StartNumber=00000001&EndNumber=00000001&Type=07&CreateDatetime=2018-04-20 17:05:14&LastNumber=50&Flag=2&CheckCode=F3DABF7C59B3A46C6006BB64F74DE4D46CEE78E536621740FB73A8600D4505EE&EndStr=##
```

### 2.5 錯誤代碼

共用表見[錯誤代碼](#錯誤代碼)。字面上與管理較相關：`SET10006`（無法變更，例如試圖
重新啟用已停用字軌——此情境為推論，原文未明示）、`MOD10003` / `LIB10001`（查無資料）、
`INM10003` / `MOD10006` / `LIB10011`（更新失敗）。

---

## 3. 字軌資料查詢 searchNumber

- 測試：`https://cinv.ezpay.com.tw/Api_number_management/searchNumber`
- 正式：`https://inv.ezpay.com.tw/Api_number_management/searchNumber`

**用途**：查詢字軌資料，查詢結果以回傳參數回覆。讓營業人在自家網站快速查詢字軌。
原文「串接目的」寫「查詢單筆字軌資料」，但回應可含**多組**字軌（JSON 為陣列、String
以逗號分隔）。

### 3.1 POST 參數

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `CompanyID_` | 會員編號 | V | Varchar(16) | ezPay 電子發票加值服務平台會員編號 |
| `PostData_` | 加密資料 | V | text | 加密方法見[加密方式](#加密方式) |

### 3.2 PostData_ 內含欄位（Request）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或 `String` |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `TimeStamp` | 時間戳記 | V | Varchar(10) | Unix 時間戳（秒）|
| `ManagementNo` | 字軌管理編號 |  | Varchar(15) | 該組字軌於 ezPay 平台的流水編號。**未帶值代表不限定** |
| `Year` | 發票年度 | V | Varchar(3) | 民國年，如 `106`。**只可輸入前兩年與明年**，如 `104`~`107` |
| `Term` | 發票期別 | V | Varchar(1) | 純數字，`1`=一、二月 … `6`=十一、十二月 |
| `Flag` | 字軌狀態 |  | Varchar(1) | 純數字：`0`=暫停、`1`=啟用、`2`=停用。**未帶值代表不限定** |

> `Year` + `Term` 皆必填 → 查詢單位是「某一年某一期」；要列出整年需分 6 期查。
> 原文例「104~107」表示今年為 106 時可查 104、105、106、107。

原文 Form 範例：`action="https://inv.ezpay.com.tw/Api_number_management/searchNumber "`
（結尾多空白、`name=" CompanyID_"` 多空白，皆為範例瑕疵）。

### 3.3 Response（JSON 模式）

最外層：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 字軌資料查詢成功回 `SUCCESS`；失敗回錯誤代碼 |
| `Message` | 回傳訊息 | Varchar(30) | 敘述此次狀態 |
| `Result` | 回傳資料 | JSON | **多組字軌資料以 array 形式放在 `Result` 中** |

`Result[]` 每一元素內含欄位：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `ManagementNo` | 字軌管理編號 | Varchar(15) | 該組字軌於 ezPay 平台的流水編號 |
| `Year` | 發票年度 | Varchar(3) | 民國年 |
| `Term` | 發票期別 | Varchar(1) | `1`~`6` |
| `AphabeticLetter` | 字軌英文代碼 | Varchar(2) | 兩碼大寫英文 |
| `StartNumber` | 發票起始號碼 | Varchar(8) | |
| `EndNumber` | 發票結束號碼 | Varchar(8) | |
| `UsedNumber` | 使用中的發票號碼 | Varchar(8) | **查詢獨有欄位**。使用中的發票號碼，例 `00000101` |
| `Type` | 發票類別 | Varchar(2) | `07`=一般稅額、`08`=特種稅額 |
| `CreateDatetime` | 字軌建立日期 | Varchar(17) | 建立該組字軌的日期與時間 |
| `LastNumber` | 剩餘張數 | Varchar(8) | 純數字，該組字軌剩餘張數 |
| `Flag` | 字軌狀態 | Varchar(1) | `0`=暫停、`1`=啟用、`2`=停用 |
| `CheckCode` | 檢查碼 | Varchar(150) | 每組各自一個，見 [CheckCode](#checkcode-回應驗證) |

JSON 回應範例（原文；`Message` 解碼為「查詢字軌成功」）：

```json
{
  "Status": "SUCCESS",
  "Message": "查詢字軌成功",
  "Result": [
    {
      "ManagementNo": "0p1akwsju",
      "Year": "107",
      "Term": "1",
      "AphabeticLetter": "AM",
      "StartNumber": "00000000",
      "EndNumber": "00000697",
      "UsedNumber": "00000697",
      "Type": "07",
      "CreateDatetime": "2018-01-02 11:33:30",
      "LastNumber": "99999302",
      "Flag": "2",
      "CheckCode": "7632850874449254A3FE52821FABBAB8C227813B0EE68AAF718CD98F1315CD50"
    },
    {
      "ManagementNo": "0p4bnkyb0",
      "Year": "107",
      "Term": "1",
      "AphabeticLetter": "AN",
      "StartNumber": "00000000",
      "EndNumber": "00000010",
      "UsedNumber": "00000010",
      "Type": "07",
      "CreateDatetime": "2018-02-23 09:47:24",
      "LastNumber": "0",
      "Flag": "3",
      "CheckCode": "59A736695985DD6C4CC9F8C9AC0B43D379FEF95E7B36EC78F1DB90394CBFD908"
    }
  ]
}
```

> **範例異常，照原文保留**：第二筆 `Flag` 為 **`"3"`**，但原文欄位說明只定義
> `0` / `1` / `2`——`3` 的意義**原文未說明**；第一筆 `LastNumber` 為 `99999302`，
> 與起訖 `00000000`~`00000697` 不符，意義**原文未說明**。解析時不要假設 `Flag` 只有
> 0/1/2，也不要假設 `LastNumber` 必然 ≤ 號碼區間張數。
> 查詢結果只有一筆時 `Result` 是否仍為陣列，**原文未說明**——建議兩種都能處理。

### 3.4 Response（String 模式）

欄位平鋪：`Status`、`Message`、`ManagementNo`、`Year`、`Term`、`AphabeticLetter`、
`StartNumber`、`EndNumber`、`UsedNumber`、`Type`、`CreateDatetime`、`LastNumber`、
`Flag`、`CheckCode`，**結尾固定 `EndStr=##`**。

- **多組字軌資料在同一欄位內以半形逗號 `,` 分隔**（按位置對應同一組）。
- 原文此表 `Term` 中文名寫「發票期數」、`Status` 說明沿用新增字軌文字，皆為原文瑕疵。

String 回應範例（urldecode 後）：

```
Status=SUCCESS&Message=查詢字軌成功&ManagementNo=0p1akwsju,0p4bnkyb0&Year=107,107&Term=1,1&AphabeticLetter=AM,AN&StartNumber=00000000,00000000&EndNumber=00000697,00000010&UsedNumber=00000697,00000010&Type=07,07&CreateDatetime=2018-01-02 11:33:30,2018-02-23 09:47:24&LastNumber=99999302,0&Flag=2,3&CheckCode=7632850874449254A3FE52821FABBAB8C227813B0EE68AAF718CD98F1315CD50,59A736695985DD6C4CC9F8C9AC0B43D379FEF95E7B36EC78F1DB90394CBFD908&EndStr=##
```

### 3.5 錯誤代碼

共用表見[錯誤代碼](#錯誤代碼)。字面上與查詢較相關：`INM10002`（查詢失敗）、
`MOD10003` / `LIB10001`（查無資料）。

---

## 錯誤代碼

對應原文第七章。三支 API **共用此表**，原文**未標示各碼對應哪支 API**，備註欄原文
全為空白。

| 錯誤代碼 | 錯誤原因 |
|----------|----------|
| `KEY10002` | 解密錯誤 |
| `KEY10006` | 取得經銷商資格失敗 |
| `KEY10010` | CompanyID_欄位空白 |
| `KEY10011` | PostData_欄位空白 |
| `NOR10001` | 網路連線異常 |
| `INM10001` | 缺少參數 |
| `INM10002` | 查詢失敗 |
| `INM10003` | 更新失敗 |
| `INM10004` | 參數錯誤 |
| `INM10005` | 新增失敗 |
| `INM10006` | 異常終止 |
| `MOD10001` | 新增資料失敗 |
| `MOD10003` | 查無資料 |
| `MOD10006` | 更新失敗 |
| `LIB10001` | 查無資料 |
| `LIB10004` | 字軌已存在或在範圍內 |
| `LIB10007` | 資料已存在，無法重覆建立 |
| `LIB10010` | 新增失敗 |
| `LIB10011` | 更新失敗 |
| `LIB10013` | 輸入數值超過許可範圍 |
| `LIB10016` | 相同的字軌已存在 |
| `SET10006` | 無法變更 |

### 前綴

| 前綴 | 出現碼 | 本檔歸納（非原文分類）|
|------|--------|------------------------|
| `KEY` | 10002 / 10006 / 10010 / 10011 | 傳輸 / 解密 / 身分 |
| `NOR` | 10001 | 網路 |
| `INM` | 10001~10006 | 字軌管理專屬（開立發票 API 無此前綴）|
| `MOD` | 10001 / 10003 / 10006 | 資料新增 / 查詢 / 更新（開立發票 API 無此前綴）|
| `LIB` | 10001 / 10004 / 10007 / 10010 / 10011 / 10013 / 10016 | 資料存在性 / 範圍檢核 |
| `SET` | 10006 | 狀態變更（開立發票 API 無此前綴）|

### 與開立發票 API 同碼不同義（務必依呼叫的 API 解讀）

| 錯誤代碼 | 字軌管理（EZP_Track） | 開立發票（EZP_INVI，見 error-codes.md）|
|----------|------------------------|------------------------------------------|
| `KEY10006` | 取得經銷商資格失敗 | 商店未申請啟用電子發票 |
| `KEY10010` | CompanyID_欄位空白 | 商店代號空白 |
| `LIB10007` | 資料已存在，無法重覆建立 | 無法作廢（已開過折讓）|

> 共用錯誤處理 mapper 時，**必須以「API 類別 + 錯誤碼」為 key**，不可只用錯誤碼。

---

## 字軌業務規則

### Year（發票年度）

- **民國年**，3 碼字串，如 `106`（民國年 = 西元年 − 1911）。
- 可填範圍依 API 而異：

| API | Year 允許範圍 |
|-----|---------------|
| createNumber | 只可輸入**今年與明年** |
| manageNumber | 只可輸入**今年與明年** |
| searchNumber | 只可輸入**前兩年與明年**（今年 106 → `104`~`107`）|

### Term（發票期別）

電子發票以兩個月為一期，`Term` 為 1 碼數字：

| Term | 月份 |
|------|------|
| `1` | 一、二月 |
| `2` | 三、四月 |
| `3` | 五、六月 |
| `4` | 七、八月 |
| `5` | 九、十月 |
| `6` | 十一、十二月 |

- 由月份換算：`Term = ceil(month / 2)`（由上表推得）。
- createNumber：**不可輸入小於現在期別**。原文未說明跨年情境（例如 12 月新增明年
  第 1 期）的比較方式，推測以「年度 + 期別」整體比較，未經原文證實。

### AphabeticLetter / 號碼區間 / Type

- `AphabeticLetter`：兩碼大寫英文字軌（如 `AA`）。
- `StartNumber` / `EndNumber`：各 8 碼數字字串（保留前導 0）。原文對區間只寫「起始 /
  結束號碼」，**未說明其他檢核**（例如起訖大小關係、張數倍數）。範例中新增的區間
  `00000001`~`00000050` 回 `LastNumber=50`。
- 重覆 / 重疊：錯誤碼 `LIB10004`（字軌已存在或在範圍內）、`LIB10016`（相同的字軌已存在）
  顯示平台會擋重覆與區間重疊。
- `Type`：`07`=一般稅額、`08`=特種稅額（開立發票查詢回應的 `InvoiceType` 同一組代碼）。
- 字軌號碼須為**財政部配發給該營業人**的號碼；平台**不會查驗**正確性（見
  [用途總覽](#用途總覽)），誤用責任在營業人。

### 字軌狀態 Flag

| Flag | 狀態 | 原文語意 |
|------|------|----------|
| `1` | 啟用 | 該組字軌使用中，會員開立發票使用此組字軌。**會員僅能啟用一組未過期別之字軌**。**會員首次新增字軌時，系統自動啟用該組字軌** |
| `0` | 暫停 | 待用中，**可再次啟用**。啟用中字軌張數用畢時，系統依**同期別**字軌**建立時間次序**自動切換到下一組暫停中字軌 |
| `2` | 停用 | 停用該組字軌，**無法再次啟用**（不可逆）|
| `3` | （原文範例出現，未定義）| searchNumber 範例出現 `Flag=3`，意義原文未說明 |

狀態轉換（依原文敘述整理）：

```
新增字軌 ──(首次新增)──▶ 1 啟用
新增字軌 ──(非首次)────▶ ?  （原文未說明非首次新增的初始狀態；範例回應 Flag=1）

0 暫停 ◀──manageNumber──▶ 1 啟用
0 暫停 ──manageNumber──▶ 2 停用（終態，無法再啟用）
1 啟用 ──manageNumber──▶ 2 停用（終態）
1 啟用 ──(張數用畢，系統自動)──▶ 同期別下一組 0 暫停 → 1 啟用
```

原文**未說明**的狀態細節：

- 啟用一組字軌時，原本啟用中的那組會被自動改成什麼狀態。
- 張數用畢的那組字軌事後的 `Flag` 值（searchNumber 範例中 `UsedNumber` 已等於
  `EndNumber` 的兩組字軌，分別為 `Flag=2` 與 `Flag=3`）。
- 跨期（例如 2 月 → 3 月）時，新一期的字軌是否由系統自動啟用。原文的自動切換只講
  「同期別」。

### 剩餘張數 LastNumber / 使用中號碼 UsedNumber

- `LastNumber`：該組字軌剩餘張數（三支 API 都回）。
- `UsedNumber`：使用中的發票號碼（**只有 searchNumber 回**）。
- 兩者的精確定義（`UsedNumber` 是「最後已開出的號碼」還是「下一張將開出的號碼」）
  **原文未說明**。

---

## 與開立發票 INV90006 的關係

- **本手冊（EZP_Track）全文未出現 `INV90006`**。`INV90006`「可開立張數已用罄」出自
  開立發票手冊 EZP_INVI（見 error-codes.md），發生在呼叫 `invoice_issue` 時。
- 本手冊提供的相關機制：啟用中字軌張數用畢時，系統會依**同期別**、**建立時間次序**
  自動切換到下一組**暫停中**字軌。
- **交叉推論（非任一原文明示）**：當期已無可接手的暫停中字軌（全部用罄或已停用），
  開立發票就會回 `INV90006`。因此預防 `INV90006` 的手段是：當期備妥至少一組
  `Flag=0` 暫停中字軌，或在剩餘張數偏低時及早新增字軌。
- 收到 `INV90006` 後的處理：取得財政部配號 → `createNumber` 新增當期字軌（`Year` /
  `Term` 為當期）→ 視需要 `manageNumber` 設 `Flag=1` → 以 `searchNumber` 確認 →
  重新開立。新增後是否需要手動啟用才能立即開立，原文未說明，應以 searchNumber 結果為準。

---

## 原文瑕疵與注意事項

| # | 位置 | 原文內容 | 處理方式 |
|---|------|----------|----------|
| 1 | 全文 | 欄位名 `AphabeticLetter`（少 `l`）| 照原文拼法送出與解析 |
| 2 | 附件二步驟 (2) | `CompanyId=C000000001` | 筆誤，PHP 程式與雜湊結果對應 `C54352706` |
| 3 | 附件二 | 前綴 `HashIv=`（小寫 v）| 與原文雜湊相符；與開立發票 `HashIV=` 不同，上線前實測 |
| 4 | 附件一 PHP | `'TimeStamp' => ' 1444963784'` 前有空白 | 勿照抄 |
| 5 | manage / search Form 範例 | `name=" CompanyID_"` 前有空白 | 勿照抄 |
| 6 | create / search Form 範例 | `action="...Number "` 結尾有空白 | 勿照抄 |
| 7 | 各回應表 `CreateDatetime` | 型態 Varchar(17)、說明「如：20161102 10:30:22」| 範例實際為 `2018-04-20 16:53:27`（19 字元），解析需容忍兩種格式 |
| 8 | manage String 表 | `ManagementNo` Varchar(13) | 其他處皆 Varchar(15)，儲存欄位以 15 為準 |
| 9 | manage / search String 表 | `Status` 說明沿用「建立成功 / 新增字軌失敗」| 複製瑕疵，語意同各自 JSON 表 |
| 10 | search JSON / String 範例 | `Flag=3`、`LastNumber=99999302`、`StartNumber=00000000` | 意義原文未說明，解析不可寫死 |
| 11 | 附件一 PHP 結果範例 | `CheckCode` 為 `5F9F7ABD…`（與附件二範例相同）| 僅供樣式參考，與該筆資料不對應 |
| 12 | 各「系統回應訊息」首句 | 「完成…後，將字軌資料顯示於頁面」| 實際為 Web Service 回應（見資料交換方式），照 `RespondType` 解析回應 body |

---

## 實作建議（非原文）

> 以下為依原文規則推導的**實作建議**，**不是 ezPay 原文內容**。

1. **每期開始前自動檢查字軌**：在每個單數月（1/3/5/7/9/11 月）開始前數天，以排程呼叫
   `searchNumber`（`Year`=下一期民國年、`Term`=下一期期別、`Flag` 不帶），確認下一期
   已有字軌，並確認開期當天有 `Flag=1` 的字軌。沒有就通知管理員。
2. **新增字軌只用管理員登錄的配號**：平台不查驗字軌正確性、誤用責任在營業人，因此
   **不要讓程式自己「生」字軌號碼**。做法：管理員在後台輸入財政部配發的字軌 / 區間
   （可預先登錄下一期），排程只負責在適當時機呼叫 `createNumber`。`createNumber` 允許
   `Year`=今年或明年、`Term`≥現在期別，可提前建立下一期字軌。
3. **剩餘張數門檻告警**：定期（例如每小時或每 N 張開立後）`searchNumber` 當期字軌，
   加總 `Flag` 為 `0` / `1` 的 `LastNumber`；低於門檻（例如依日均開立量 × 安全天數
   計算）即通知管理員補字軌。因 `LastNumber` 範例曾出現異常值，若 `LastNumber` 大於
   `EndNumber − StartNumber + 1`，視為異常並一併告警，不要拿來當作「很充足」。
4. **`INV90006` 的即時處理**：`invoice_issue` 回 `INV90006` 時，立即通知管理員、將該
   筆開立放入重試佇列（勿丟失），待字軌補上並以 `searchNumber` 確認有 `Flag=1` 字軌後
   再重送。
5. **停用前二次確認**：`Flag=2` 停用不可逆，後台 UI 呼叫 `manageNumber` 設 `Flag=2`
   前應要求二次確認。
6. **一律驗證 CheckCode**：每筆（searchNumber 每一組）都以會員 HashKey / HashIV 計算
   比對，使用 timing-safe 比較；驗證失敗視同回應不可信。
7. **優先用 `RespondType=JSON`**：String 模式多筆時需按逗號位置對齊各欄，較易出錯；
   若用 String 模式，務必檢查 `EndStr=##`。
8. **金鑰分開設定**：字軌管理用 `CompanyID_` + 會員金鑰，開立發票用 `MerchantID_` +
   商店金鑰；設定檔中分開兩組，避免混用導致 `KEY10002`。
9. **錯誤碼 mapper 以 API 區分**：`KEY10006` / `KEY10010` / `LIB10007` 在兩份手冊意義
   不同。`createNumber` 回 `LIB10004` / `LIB10007` / `LIB10016` 時可視為「可能已建立」，
   先 `searchNumber` 確認現況再決定是否告警，避免重送造成誤判。
