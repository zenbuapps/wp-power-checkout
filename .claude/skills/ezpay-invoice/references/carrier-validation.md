# ezPay 手機條碼 / 捐贈碼驗證 API Reference

對應《手機條碼/捐贈碼驗證 電子發票技術串接手冊》標準版 **EZP_BDV_1.0.0**（版本異動：
異動序號 1、初版、2021/02/21）。原文只有兩支 API：手機條碼驗證 `checkBarCode`、捐贈碼驗證
`checkLoveCode`。用途是查詢手機條碼 / 捐贈碼**是否存在於財政部電子發票整合服務平台**。

> **這份手冊的傳輸格式、簽章與回應解密方式都和 EZP_INVI_1.2.1（開立發票等 API，見
> api-reference.md / concepts.md）不同**，不能直接沿用開立發票的程式碼。差異見下方
> [與開立發票 API 的差異](#與開立發票-apiezp_invi_121的差異)。
>
> 本檔標示規則：「原文」是手冊文字；「以範例值實算」是用手冊自己的範例金鑰與範例密文，
> 以 AES / SHA256 實際計算得到的結果（不是手冊文字）；「實作建議」不是原文。

## 目錄

- [與開立發票 API（EZP_INVI_1.2.1）的差異](#與開立發票-apiezp_invi_121的差異)
- [串接環境與資料交換方式](#串接環境與資料交換方式)
- [加密 / 簽章 / 解密](#加密--簽章--解密)
- [1. 手機條碼驗證 checkBarCode](#1-手機條碼驗證-checkbarcode)
- [2. 捐贈碼驗證 checkLoveCode](#2-捐贈碼驗證-checklovecode)
- [錯誤代碼](#錯誤代碼)
- [原文 PHP 範例重點](#原文-php-範例重點)
- [原文不一致與疑點](#原文不一致與疑點)
- [實作建議（非原文）](#實作建議非原文)

---

## 與開立發票 API（EZP_INVI_1.2.1）的差異

| 項目 | EZP_INVI_1.2.1（開立 / 作廢 / 折讓 / 查詢） | EZP_BDV_1.0.0（本檔） |
|------|-----------------------------------------------|------------------------|
| 路徑 | `/Api/{api}` | `/Api_inv_application/checkBarCode`、`/Api_inv_application/checkLoveCode` |
| 網域 | 測試 `cinv.ezpay.com.tw`、正式 `inv.ezpay.com.tw` | **相同** |
| POST body 欄位 | 兩欄：`MerchantID_`、`PostData_` | **五欄**：`MerchantID_`、`Version`、`RespondType`、`PostData_`、`CheckValue` |
| `Version` / `RespondType` 位置 | 放在 `PostData_` 加密前的內容裡 | **放在 POST body 明文**；`PostData_` 只含 `TimeStamp` + 業務欄位 |
| Request 簽章 | 無 | **有**：`CheckValue` = SHA256(`HashKey={key}&{PostData_}&HashIV={iv}`) 轉大寫（附件三）|
| 回應 `Result` | JSON 模式為**明文** JSON 字串（再 parse 即可）；String 模式欄位平鋪 | **兩種 RespondType 都是 AES-256-CBC 加密的 hex 字串**，用同一組 HashKey / HashIV 解密；解出來是 url-encoded query string（以範例值實算）|
| 回應 `CheckCode` 位置 | 在 `Result` 內 | 在 **`Result` 外、最外層** |
| 回應 `CheckCode` 算法 | 5 欄位（`InvoiceTransNo` 等）A~Z 排序串接，前後加 `HashIV=` / `HashKey=` | 原文附件四沿用開立發票的 5 欄位範例（這兩支 API 的回應沒有那些欄位）。**以範例值實算**：SHA256(`HashIV={iv}&{Result 密文 hex}&HashKey={key}`) 轉大寫 |
| 回應最外層欄位 | `Status`、`Message`、`Result` | `Status`、`Message`、`APIID`、`Version`、`MerchantID`、`Result`、`CheckCode`（String 模式另加 `EndStr=##`）|
| `TimeStamp` 型態 | Varchar(30) | Varchar(10) |
| AES 參數 | AES-256-CBC、自補 PKCS#7（blocksize 32）、`OPENSSL_ZERO_PADDING`、小寫 hex | **相同**（附件一 PHP 寫法一致；以範例密文解密確認 padding 為 PKCS#7 blocksize 32，request 與 response 皆是）|
| 錯誤代碼 | `KEY` / `INV` / `LIB` 等系列 | `API1000x`（共用）、`CBC1000x`（手機條碼）、`CLC1000x`（捐贈碼）；**原文未列 `KEY` 系列** |

> **兩個 SHA256 的字串順序相反**：request 的 `CheckValue` 是 **HashKey 在前**；response 的
> `CheckCode`（以範例值實算）是 **HashIV 在前**。寫反了驗章就不會過。

---

## 串接環境與資料交換方式

原文「三、串接環境與作業流程」：

| 環境 | 申請 / 登入網址 | 金鑰 |
|------|-----------------|------|
| 測試 | `https://cinv.ezpay.com.tw/` 申請會員並建立測試商店，平台審核後啟用電子發票測試平台 | 測試商店 API 串接金鑰 Hash Key、Hash IV |
| 正式 | `https://inv.ezpay.com.tw/` 申請會員並建立商店，平台審核後啟用電子發票加值服務平台 | 商店 API 串接金鑰 Hash Key、Hash IV |

資料交換方式（原文）：

1. 商店以「HTTP POST」方式傳送資料至本平台（原文字樣為「傳送發票資料至本平台進行開立」，沿用開立發票手冊的寫法）。
2. 傳送方式須以標準 Form Post 進行。
3. 本平台以 Web Service 方式回應。
4. 編碼格式為 UTF-8。

附件一另寫明須「透過**幕後方式**送至電子發票查詢/驗證閘道，可參考 CURL 模組」，也就是由伺服器端送出。

流程（原文「二、流程說明」）：營業人 → ezPay 電子發票加值服務平台 → 財政部電子發票平台 →
ezPay → 營業人。也就是 ezPay 收到請求後**即時向財政部查詢**，再把結果回給商店。

原文未說明的事項：逾時秒數、呼叫頻率限制、HTTP 狀態碼語意、測試環境是否查詢財政部真實資料、有無測試用條碼 / 捐贈碼。

---

## 加密 / 簽章 / 解密

### A. Request：`PostData_` 加密（原文附件一）

原文：「傳送驗證資料中，商店代號(MerchantID_)、串接程式版本(Version)、回傳格式(RespondType)、
檢查碼(CheckValue)之外，其餘欄位均須採用 AES-256 CBC 加密後放到 PostData_ 欄位」。

步驟（依附件一 PHP）：

1. `TimeStamp` + 業務欄位（`CellphoneBarcode` 或 `LoveCode`）用 `http_build_query` 組成 query string。
   例：`TimeStamp=1589261733&CellphoneBarcode=%2FAAA.CCC`（`/` 會被編成 `%2F`）。
2. `addpadding()` 補 PKCS#7。本手冊沒有定義 `addpadding`，寫法與開立發票手冊相同（blocksize 32，見 concepts.md）。
3. `openssl_encrypt(..., 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)` → `bin2hex` → `trim`，得到小寫 hex，就是 `PostData_`。

### B. Request：`CheckValue` 簽章（原文附件三）

原文：

1. 將 PostData_ 加密字串前後加上商店串接專屬 Hash Key 值與商店串接專屬 Hash IV 值。
2. 將串聯後的字串用 SHA256 壓碼後轉大寫。

原文 PHP：

```php
function checkValue($string = '', $key = '', $iv = '')
{
    return strtoupper(hash('sha256', "HashKey={$key}&{$string}&HashIV={$iv}"));
}
// 附件一呼叫方式：
$checkValue = checkValue($post_data, $key, $iv);   // $post_data 為加密後的 PostData_ hex
```

也就是 `CheckValue = UPPER(SHA256("HashKey={HashKey}&{PostData_}&HashIV={HashIV}"))`。

> 以範例值實算：原文三組 request 範例（附件一、四-(一) 表單、五-(一) 表單）用附件一的範例金鑰
> 以上式計算，**全部吻合**。
>
> 注意：附件三的「範例參數」拿的是回應的 `Result` 而不是 `PostData_`，算出的
> `052E06C3…E85F7` 其實是四-(二) 範例回應的 `CheckCode`。而且附件三寫出的串接字串
> `HashKey=…&{Result}&HashIV=…` 實算結果是 `CFE0CC02…C5FD46`，**不等於**它宣稱的
> `052E06C3…`。附件三的範例不能拿來驗證實作，請以上面的 PHP 函式為準。

### C. Response：`Result` 解密（原文附件二）

原文：「……其餘欄位均採用 AES-256 CBC 加密後放到 Result 欄位。」範例參數為 `Result`、
`Hash Key`、`Hash IV`（與加密 `PostData_` 用的是**同一組商店金鑰**）。原文的解密步驟只有一行：

```
1. Result 做 AES 256 CBC 解密： CellphoneBarcode=%2FAAA.CCC&IsExist=N
```

- 原文**沒有附解密程式碼**。標題寫「以 PHP、.net c# 為例」，但內文只有步驟，也沒有提到怎麼移除 padding。
- 以範例值實算：解密後尾端是 PKCS#7 padding，blocksize 32。手機條碼範例尾端有 27 個 `0x1B`；
  捐贈碼範例尾端有 8 個 `0x08`。移除 padding 的方式和開立發票相同。
- 解出來是 **url-encoded query string，不是 JSON**。即使 `RespondType=JSON` 也一樣，需要 url decode 後再依 `&` / `=` 解析。
  - 手機條碼範例：`CellphoneBarcode=%2FAAA.CCC&IsExist=N`
  - 捐贈碼範例（以範例值實算）：`Lovecode=01234&IsExist=N`

### D. Response：`CheckCode` 驗證（原文附件四）

原文文字與附件三一樣（「將 PostData_ 加密字串前後加上 Hash Key 值與 Hash IV 值 → SHA256 轉大寫」）。
但原文的「範例參數」與 PHP 是**直接沿用開立發票手冊**的內容：`InvoiceTransNo`、`MerchantID`、
`MerchantOrderNo`、`RandomNum`、`TotalAmt` 五欄位 `ksort` 後串接，前後加 `HashIV=` 與
`&HashKey=`，範例結果為 `303AB800…7EECB`。這兩支 API 的回應**沒有**這五個欄位，所以這個範例用不上。

**以範例值實算**（非原文文字）：

```
CheckCode = UPPER(SHA256("HashIV={HashIV}&{Result 密文 hex 原字串}&HashKey={HashKey}"))
```

- 對四-(二)（`052E06C3…E85F7`）與五-(二)（`D41DEA8D…DFC669`）兩個回應範例都吻合。
- 若改成 HashKey 在前（附件三那種順序），兩個範例都**不**吻合。
- 被雜湊的是 `Result` 的**密文 hex**，不是解密後的明文。

### 範例值實算對照（附件一範例金鑰 `rsBjnIvMG3VMMWsEynTK3PWiGccHuYiV` / `SdsatSdXm1vH7N3T`，僅供驗算）

| 範例出處 | 密文 | 解密結果 | 簽章 / 檢查碼 | 吻合的字串順序 |
|----------|------|----------|---------------|----------------|
| 附件一 `$post_data` | `ed2d2219…4db5e9bf` | `TimeStamp=1589261733&CellphoneBarcode=%2FAAA.CCC` | CheckValue `5D7A25D0…9820B` | HashKey 在前、HashIV 在後 |
| 四-(一) 表單範例 | `5e6541f6…b0df90dd` | `TimeStamp=1589511288&CellphoneBarcode=%2FAAA.CCC` | CheckValue `C6551F67…E04A5` | HashKey 在前、HashIV 在後 |
| 五-(一) 表單範例 | `5e6541f6…ffd46c422` | `TimeStamp=1589511440&LoveCode=01234` | CheckValue `DA5016ED…C57A7` | HashKey 在前、HashIV 在後 |
| 四-(二) 回應 `Result` | `0628256c…4f8aefbb` | `CellphoneBarcode=%2FAAA.CCC&IsExist=N` | CheckCode `052E06C3…E85F7` | **HashIV 在前、HashKey 在後** |
| 五-(二) 回應 `Result` | `f2d66e82…3f3ec2d5` | `Lovecode=01234&IsExist=N` | CheckCode `D41DEA8D…DFC669` | **HashIV 在前、HashKey 在後** |

---

## 1. 手機條碼驗證 checkBarCode

- 測試：`https://cinv.ezpay.com.tw/Api_inv_application/checkBarCode`
- 正式：`https://inv.ezpay.com.tw/Api_inv_application/checkBarCode`

**適用情境**（原文「一、串接目的」）：驗證手機條碼是否存在於財政部電子發票整合服務平台。

### 1.1 POST 參數（POST body）

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `MerchantID_` | 商店代號 | V | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或是 `String` |
| `PostData_` | 加密資料 | V | text | 加密方法見附件一（上方 [A](#a-requestpostdata_-加密原文附件一)）|
| `CheckValue` | 檢查碼 | V | Varchar(64) | 原文備註：「用來檢查此次資料回傳的合法性，串接時可以比對此參數資料，來檢核是否為本平台所回傳，檢核方法請參考"附件三"」（這段文字沿用回應檢查碼的描述；實際上是 request 端簽章，產生方式見上方 [B](#b-requestcheckvalue-簽章原文附件三)）|

原文註：參數 `MerchantID_` 及 `PostData_` 後方有底線 `_`。

### 1.2 PostData_ 內含欄位

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `TimeStamp` | 時間戳記 | V | Varchar(10) | 自 Unix 紀元（格林威治時間 1970-01-01 00:00:00）到當前時間的秒數，PHP 即 `time()` 的回傳值。例：2014-05-15 15:00:00 → `1400137200` |
| `CellphoneBarcode` | 手機條碼 | V | Varchar(8) | 帶入欲驗證的手機條碼。例：`/ABC.122`。※手機條碼載具檢核：第 1 碼為 `/`，後接 7 碼英、數字；除第 1 碼外，只能是 `0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ+-.` 這 39 個字元，英文限大寫 |

Request 範例（原文，Form Post）：

```html
<form method="POST" action="https://inv.ezpay.com.tw/Api_inv_application/checkBarCode">
<p><input type="text" name="MerchantID_" value="3883991"></p>
<p><input type="text" name="Version" value="1.0"></p>
<p><input type="text" name="RespondType" value="JSON"></p>
<p><input type="text" name="PostData_" value="5e6541f62e8fcc195e876d55417cdc175c837dfda910c9b84da28dade2cc7ee8df67a1cdec51adb720771c7a315e3861b2f57024840bf7a2f1c01173b0df90dd"></p>
<p><input type="text" name="CheckValue" value="C6551F6705CF6F0B9218D4DE97B95648005438B9BDF61E3E67B38EB7AEEE04A5"></p>
<input type="submit" value="Submit">
</form>
```

### 1.3 Response（JSON 模式）

回應格式依 `RespondType` 而定。原文：「本平台會於接收商戶資訊完成回應下列訊息」。

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 1. 手機條碼驗證成功回 `SUCCESS`；2. 驗證失敗回錯誤代碼（見 [錯誤代碼](#錯誤代碼)）|
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態的說明文字 |
| `APIID` | 串接程式代號 | Varchar(50) | 此次串接的 API 功能代號，值為 `barCodeCheck`（原文備註寫「查詢中獎發票為 barCodeCheck」，「查詢中獎發票」為沿用字樣）|
| `Version` | 串接程式版本 | Varchar(5) | 此次串接 API 功能版本 |
| `MerchantID` | 商店代號 | Varchar(15) | ezPay 電子發票加值服務平台商店代號（**無底線**）|
| `Result` | 回傳資料 | JSON | 原文型態與備註寫「JSON 格式」，但範例值是 **AES 密文 hex 字串**，需依附件二解密（見上方 [C](#c-responseresult-解密原文附件二)）|
| `CheckCode` | 檢查碼 | Varchar(64) | 用來檢查此次資料回傳的合法性，檢核方法原文指向附件四（實際算法見上方 [D](#d-responsecheckcode-驗證原文附件四)）|

`Result`（解密後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `CellphoneBarcode` | 手機條碼 | Varchar(8) | 回傳此次驗證的手機條碼（解密後為 url-encoded，`/` 呈現為 `%2F`）|
| `IsExist` | 驗證結果 | Varchar(1) | 手機條碼是否存在於財政部電子發票整合服務平台。`Y`=存在、`N`=不存在 |

> `Status=SUCCESS` 只代表查詢成功，**條碼是否存在要看 `IsExist`**。原文範例就是
> `Status=SUCCESS`、`Message=查詢成功`，而 `Result` 解密後是 `IsExist=N`。

JSON 回應範例（原文；`Message` 解碼後為「查詢成功」）：

```json
{"Status":"SUCCESS","Message":"查詢成功","APIID":"barCodeCheck","Version":"1.0","MerchantID":"3883991","Result":"0628256c389824ce257c6e8796707aa3bc803d2245f4894261dbae64ff9a51f34f519b79c3fd8892157a8703779ab7639c62c3d078e7c8a09006a27f4f8aefbb","CheckCode":"052E06C3720642DE306C1C33F921713EA4BA6DB9970C78F6C98952D4E69E85F7"}
```

### 1.4 Response（String 模式）

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 同 JSON 模式 |
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態的說明文字 |
| `MerchantID` | 商店代號 | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `Result` | 回傳資料 | String | 原文寫「String 格式」；範例值與 JSON 範例是**同一段密文**，同樣需要解密 |
| `CheckCode` | 檢查碼 | Varchar(64) | 同 JSON 模式 |

- 原文的 String 表**沒有列 `APIID`、`Version`**，但範例有回傳這兩個欄位。
- 原文 PS：String 格式回傳時會多回 `EndStr=##`，代表資料結尾，用來確保資料完整。

`Result` 內含（原文 String 表）：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `PhoneBarcode` | 手機條碼 | Varchar(8) | 回傳此次驗證的手機條碼。**原文不一致**：String 範例的 `Result` 密文和 JSON 範例一模一樣，附件二解出來的欄位名是 `CellphoneBarcode` |
| `IsExist` | 驗證結果 | Varchar(1) | `Y`=存在、`N`=不存在 |

String 回應範例（原文）：

```
Status=SUCCESS&Message=%E6%9F%A5%E8%A9%A2%E6%88%90%E5%8A%9F&APIID=barCodeCheck&Version=1.0&MerchantID=3883991&Result=0628256c389824ce257c6e8796707aa3bc803d2245f4894261dbae64ff9a51f34f519b79c3fd8892157a8703779ab7639c62c3d078e7c8a09006a27f4f8aefbb&CheckCode=052E06C3720642DE306C1C33F921713EA4BA6DB9970C78F6C98952D4E69E85F7&EndStr=%23%23
```

`urldecode()` 後：

```
Status=SUCCESS&Message=查詢成功&APIID=barCodeCheck&Version=1.0&MerchantID=3883991&Result=0628256c389824ce257c6e8796707aa3bc803d2245f4894261dbae64ff9a51f34f519b79c3fd8892157a8703779ab7639c62c3d078e7c8a09006a27f4f8aefbb&CheckCode=052E06C3720642DE306C1C33F921713EA4BA6DB9970C78F6C98952D4E69E85F7&EndStr=##
```

---

## 2. 捐贈碼驗證 checkLoveCode

- 測試：`https://cinv.ezpay.com.tw/Api_inv_application/checkLoveCode`
- 正式：`https://inv.ezpay.com.tw/Api_inv_application/checkLoveCode`

**適用情境**（原文「一、串接目的」）：驗證捐贈碼是否存在於財政部電子發票整合服務平台。

### 2.1 POST 參數（POST body）

與 [1.1](#11-post-參數post-body) 相同：

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `MerchantID_` | 商店代號 | V | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `Version` | 串接程式版本 | V | Varchar(5) | **固定帶 `1.0`** |
| `RespondType` | 回傳格式 | V | Varchar(5) | `JSON` 或是 `String` |
| `PostData_` | 加密資料 | V | text | 加密方法見附件一 |
| `CheckValue` | 檢查碼 | V | Varchar(64) | 產生方式見附件三（原文備註文字同 1.1）|

原文註：參數 `MerchantID_` 及 `PostData_` 後方有底線 `_`。

### 2.2 PostData_ 內含欄位

| 參數名稱 | 中文名稱 | 必填 | 型態 | 備註 |
|----------|----------|------|------|------|
| `TimeStamp` | 時間戳記 | V | Varchar(10) | Unix 時間戳（秒），同 1.2 |
| `LoveCode` | 捐贈碼 | V | Int(7) | 1. 帶入欲驗證的捐贈碼；2. 限 3~7 碼純正整數 |

Request 範例（原文，Form Post）：

```html
<form method="POST" action="https://inv.ezpay.com.tw/Api_inv_application/checkLoveCode">
<p><input type="text" name="MerchantID_" value="3883991"></p>
<p><input type="text" name="Version" value="1.0"></p>
<p><input type="text" name="RespondType" value="JSON"></p>
<p><input type="text" name="PostData_" value="5e6541f62e8fcc195e876d55417cdc176dc621559fff78b096cae352ccb546e42e102c63db5e586eb87713aaa13323385eb1e9006fc8cbbb6d00458ffd46c422"></p>
<p><input type="text" name="CheckValue" value="DA5016ED7D5A6641A7FF3CB01CDF5E9DCD8C913261D81D7BC8EC9BBDDE9C57A7"></p>
<input type="submit" value="Submit">
</form>
```

（以範例值實算：此 `PostData_` 解密為 `TimeStamp=1589511440&LoveCode=01234`。注意範例捐贈碼開頭是 `0`。）

### 2.3 Response（JSON 模式）

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 成功回 `SUCCESS`；失敗回錯誤代碼（原文此表備註寫「手機條碼驗證成功」，是沿用字樣；String 表則寫「捐贈碼驗證成功」）|
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態的說明文字 |
| `APIID` | 串接程式代號 | Varchar(50) | 值為 `LoveCodeCheck`（原文備註同樣寫「查詢中獎發票為」）|
| `Version` | 串接程式版本 | Varchar(5) | 此次串接 API 功能版本 |
| `MerchantID` | 商店代號 | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `Result` | 回傳資料 | JSON | 同 1.3，實為 AES 密文 hex，需解密 |
| `CheckCode` | 檢查碼 | Varchar(64) | 同 1.3 |

`Result`（解密後）內含：

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Lovecode` | 捐贈碼 | Int(7) | 回傳此次驗證的捐贈碼。**欄位名是小寫 c 的 `Lovecode`**，與 request 的 `LoveCode` 不同（原文表格與範例密文解密結果一致）|
| `IsExist` | 驗證結果 | Varchar(1) | 捐贈碼是否存在於財政部電子發票整合服務平台。`Y`=存在、`N`=不存在 |

JSON 回應範例（原文）：

```json
{"Status":"SUCCESS","Message":"查詢成功","APIID":"LoveCodeCheck","Version":"1.0","MerchantID":"3883991","Result":"f2d66e82840fc9c831f4fae144c083f0ea59a99a4f726ddfe93430b23f3ec2d5","CheckCode":"D41DEA8D3F20A483DA523F954AEE6FE1807FCEB3A163ECA67A942C9890DFC669"}
```

（以範例值實算：`Result` 解密為 `Lovecode=01234&IsExist=N`。）

### 2.4 Response（String 模式）

| 參數名稱 | 中文名稱 | 型態 | 備註 |
|----------|----------|------|------|
| `Status` | 回傳狀態 | Varchar(10) | 1. 捐贈碼驗證成功回 `SUCCESS`；2. 驗證失敗回錯誤代碼 |
| `Message` | 回傳訊息 | Varchar(30) | 此次回傳狀態的說明文字 |
| `MerchantID` | 商店代號 | Varchar(15) | ezPay 電子發票加值服務平台商店代號 |
| `Result` | 回傳資料 | String | 同 JSON 模式的密文，需解密 |
| `CheckCode` | 檢查碼 | Varchar(64) | 同 JSON 模式 |

`Result` 內含欄位與 2.3 相同（`Lovecode`、`IsExist`）。和 1.4 一樣，原文的 String 表沒有列
`APIID`、`Version`，但範例有回傳；結尾有 `EndStr=##`。

String 回應範例（原文）：

```
Status=SUCCESS&Message=%E6%9F%A5%E8%A9%A2%E6%88%90%E5%8A%9F&APIID=LoveCodeCheck&Version=1.0&MerchantID=3883991&Result=f2d66e82840fc9c831f4fae144c083f0ea59a99a4f726ddfe93430b23f3ec2d5&CheckCode=D41DEA8D3F20A483DA523F954AEE6FE1807FCEB3A163ECA67A942C9890DFC669&EndStr=%23%23
```

`urldecode()` 後：

```
Status=SUCCESS&Message=查詢成功&APIID=LoveCodeCheck&Version=1.0&MerchantID=3883991&Result=f2d66e82840fc9c831f4fae144c083f0ea59a99a4f726ddfe93430b23f3ec2d5&CheckCode=D41DEA8D3F20A483DA523F954AEE6FE1807FCEB3A163ECA67A942C9890DFC669&EndStr=##
```

---

## 錯誤代碼

原文「六、錯誤代碼」。兩張表的「備註」欄**原文皆空白**。

### 手機條碼驗證（checkBarCode）

| 錯誤代碼 | 錯誤原因 |
|----------|----------|
| `API10001` | 缺少參數 |
| `API10002` | 查詢失敗 |
| `API10004` | 參數錯誤 |
| `CBC10001` | 欄位資料空白 |
| `CBC10002` | 欄位資料格式錯誤 |
| `CBC10003` | 異常終止 |
| `CBC10004` | 財政部大平台網路連線異常 |

### 捐贈碼驗證（checkLoveCode）

| 錯誤代碼 | 錯誤原因 |
|----------|----------|
| `API10001` | 缺少參數 |
| `API10002` | 查詢失敗 |
| `API10004` | 參數錯誤 |
| `CLC10001` | 欄位資料空白 |
| `CLC10002` | 欄位資料格式錯誤 |
| `CLC10003` | 異常終止 |
| `CLC10004` | 財政部大平台網路連線異常 |

前綴歸納：`API` 為兩支共用；`CBC` 屬 checkBarCode；`CLC` 屬 checkLoveCode。原文沒有 `API10003`。

原文未說明的事項：

- `PostData_` 解密失敗、`CheckValue` 不符、`MerchantID_` 錯誤、商店未啟用時分別回哪個代碼。原文**沒有列**開立發票手冊的 `KEY10002`（資料解密錯誤）等 `KEY` 系列，是否改回 `API10004` 或其他代碼，原文未說明。
- 失敗回應是否仍帶 `Result` / `CheckCode`。

---

## 原文 PHP 範例重點

### 附件一：PostData_ 加密 + 送出

```php
$MerchantID_ = '3883991';
$key = 'rsBjnIvMG3VMMWsEynTK3PWiGccHuYiV';   // 範例 Hash Key（32 字元）
$iv  = 'SdsatSdXm1vH7N3T';                   // 範例 Hash IV（16 字元）
$temp_data = Array(
    'TimeStamp'        => time(),
    'CellphoneBarcode' => '/AAA.CCC'
);
$post_data_str = http_build_query($temp_data); // TimeStamp=1589261733&CellphoneBarcode=%2FAAA.CCC
$post_data  = trim(bin2hex(openssl_encrypt(addpadding($post_data_str), 'aes-256-cbc',
                  $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)));
$checkValue = checkValue($post_data, $key, $iv);
$transaction_data_array = array(   // 送出欄位
    'MerchantID_' => $MerchantID_,
    'Version'     => '1.0',
    'RespondType' => 'JSON',
    'PostData_'   => $post_data,
    'CheckValue'  => $checkValue
);
$transaction_data_str = http_build_query($transaction_data_array);
$result = curl_work($url, "POST", $transaction_data_str); // 背景送出
```

重點：

- `Version`、`RespondType` 放在**送出欄位**，不在 `$temp_data`（加密內容）裡，這點和開立發票不同。
- `addpadding()`、`curl_work()` 本手冊都沒有定義。`addpadding` 同開立發票手冊（PKCS#7、blocksize 32）。
- 範例印出的 `[url]` 是 `https://linv.ezpay.com.tw/Api_inv_application/checkBarCode`。這個 `linv` 網域原文沒有說明，既不是測試的 `cinv`，也不是正式的 `inv`。串接時請用 §1、§2 列出的網址。
- 範例印出的 `[http_status] => 200`、`[web_info]` 就是 §1.3 的 JSON 回應。
- 附件一、二的標題寫「以 PHP、.net c# 為例」，但原文**只提供 PHP**，沒有 .NET C# 範例。

### 附件三：CheckValue

見上方 [B](#b-requestcheckvalue-簽章原文附件三)。原文 PHP 的 docblock 缺少 `*/` 與結尾 `}`，照抄會有語法錯誤，只要取函式本體即可。

### 附件四：CheckCode

PHP 為開立發票手冊的 5 欄位範例（`ksort` → `http_build_query` → `'HashIV=…&' . $check_str . '&HashKey=…'` → SHA256 → 大寫），不適用這兩支 API。實際可用的算法見上方 [D](#d-responsecheckcode-驗證原文附件四)。

---

## 原文不一致與疑點

| # | 位置 | 原文內容 | 問題 / 實算結果 |
|---|------|----------|-----------------|
| 1 | 附件三範例 | 以 `Result` 和 `HashKey=…&{Result}&HashIV=…` 算出 `052E06C3…` | 輸入應為 `PostData_`；且此字串實算為 `CFE0CC02…`，不是 `052E06C3…`。`052E06C3…` 是回應 CheckCode，順序為 HashIV 在前 |
| 2 | 附件四 | 文字寫「PostData_ 加密字串前後加 Hash Key / Hash IV」，範例卻是開立發票的 5 欄位 | 兩者都不是回應 CheckCode 的實際算法；以範例值實算為 `HashIV=…&{Result 密文}&HashKey=…` |
| 3 | 四-(二) String 表 | `Result` 欄位名為 `PhoneBarcode` | 同一段密文解出來是 `CellphoneBarcode` |
| 4 | 四-(二)、五-(二) | `Result` 型態寫「JSON / String 格式」 | 實為 AES 密文 hex；解密後是 url-encoded query string |
| 5 | 四-(二)、五-(二) String 表 | 未列 `APIID`、`Version` | 範例有回傳 |
| 6 | APIID 備註 | 「查詢中獎發票為 barCodeCheck / LoveCodeCheck」 | 「查詢中獎發票」是沿用字樣 |
| 7 | 五-(二) JSON 表 | `Status` 備註寫「手機條碼驗證成功」 | 沿用字樣（String 表寫「捐贈碼驗證成功」）|
| 8 | 五-(二) | Result 欄位 `Lovecode`（小寫 c） | 與 request 的 `LoveCode` 大小寫不同；範例密文解出來也是 `Lovecode` |
| 9 | 五-(一) | `LoveCode` 型態 `Int(7)` | 範例值解密後為 `01234`（前導 0），用整數型別處理會把 0 弄丟 |
| 10 | 四-(一)、五-(一) | `CheckValue` 備註寫「檢查此次資料回傳的合法性」 | 實為 request 端簽章 |
| 11 | 附件一 | 印出 url 為 `linv.ezpay.com.tw` | 原文未說明此網域 |
| 12 | 三-(三) | 「傳送發票資料至本平台進行開立」 | 沿用開立發票手冊字樣 |

---

## 沙箱實測紀錄（2026-09-29，測試平台 cinv）

| 請求 | 結果 |
|------|------|
| `checkBarCode`，`/ABC1234`（格式正確、隨機）| `SUCCESS`，解密 `CellphoneBarcode=%2FABC1234&IsExist=N` |
| `checkBarCode`，`ABC`（格式錯誤）| `CBC10002`「欄位資料格式錯誤-CellphoneBarcode」，無 `Result` / `CheckCode` |
| `checkLoveCode`，`919`（真實愛心碼）| `SUCCESS`，解密 `Lovecode=919&IsExist=Y` |
| `checkLoveCode`，`1234567`（應不存在）| `SUCCESS`，解密 `Lovecode=1234567&IsExist=Y` ⚠️ |

- **回應 CheckCode 算法已實測確認**：3 筆成功回應全部符合
  `UPPER(SHA256("HashIV={iv}&{Result 密文}&HashKey={key}"))`（HashIV 在前）；HashKey 在前的寫法全部不符。
- **request 的 `CheckValue` 算法已實測確認**：照 `HashKey={key}&{PostData_}&HashIV={iv}` 送出，平台接受。
- **測試環境的捐贈碼驗證不可靠**：明顯不存在的 `1234567` 也回 `IsExist=Y`。
  自動化測試不要依賴測試環境的 `IsExist` 結果判斷邏輯，驗證正確性以正式環境為準。
- 不需要啟用電子發票合約就能呼叫（同一商店開立發票時回 `INV90005`，這兩支仍正常）。

## 實作建議（非原文）

> 以下是依原文規格整理的串接建議，**不是 ezPay 手冊內容**。

### 何時呼叫

- **結帳時由顧客觸發**：顧客選「手機條碼載具」並填完條碼，或選「捐贈」並填完愛心碼時（欄位 blur 或按「驗證」時），呼叫自家後端，再由後端呼叫 ezPay。
  - HashKey / HashIV 不能放在前端。原文附件一也要求用「幕後方式」送出。
- **先做本地格式檢查**，通過後才打 API，避免把格式錯誤的值送去 ezPay：
  - 手機條碼：`^\/[0-9A-Z+\-.]{7}$`（依原文：第 1 碼 `/`，後接 7 碼 39 字元集，限大寫）。可先把輸入轉成大寫再檢查。
  - 捐贈碼：`^[0-9]{3,7}$`（依原文：3~7 碼純數字）。**整個流程都用字串處理**，避免前導 0 被吃掉。
- **送出訂單時由後端再確認一次**：可以重用剛才的驗證結果（例如同一 session、同一個值的短期快取），但不要只相信前端傳上來的「已驗證」旗標。
- 已儲存在會員資料中、之前驗證過的條碼，可以不必每次結帳都重打（原文未說明頻率限制，要不要重驗由產品決定）。

### 結果判讀與呈現

| 情況 | 判讀 | 呈現 / 處理 |
|------|------|-------------|
| `Status=SUCCESS`、CheckCode 驗證通過、`IsExist=Y` | 有效 | 欄位顯示「已驗證」，可結帳 |
| `Status=SUCCESS`、CheckCode 驗證通過、`IsExist=N` | 財政部查無此碼 | **阻擋**：在欄位下方顯示「查無此手機條碼，請確認後重新輸入」／「查無此捐贈碼」，保留輸入內容，並讓顧客可改選其他發票類型 |
| `CBC10001` / `CLC10001`（欄位空白）、`CBC10002` / `CLC10002`（格式錯誤） | 輸入問題 | 在欄位下方顯示格式錯誤提示（通常本地格式檢查就會先擋下）|
| `CBC10003` / `CLC10003`、`CBC10004` / `CLC10004`、`API10002` | ezPay 或財政部端異常 | **降級**（見下）|
| `API10001` / `API10004`、其他未列代碼 | 多半是我方實作 / 參數問題 | 記錄 log 並告警，對顧客**降級** |
| CheckCode 不符、`Result` 解不開、回應格式非預期 | 回應不可信 | 視同 API 失敗，**降級** |

### API 逾時或失敗時要降級，不能阻擋結帳

- 這兩支 API 要由 ezPay 即時向財政部查詢（原文流程圖），而且原文有「財政部大平台網路連線異常」這種錯誤碼，所以它的可用性不在我方掌控內。
- 建議設定較短的逾時（例如 3–5 秒，原文未規定）。逾時、網路錯誤、HTTP 非 200、上表中「降級」的情況，一律：
  1. **只做本地格式檢查**。格式正確就放行，**不要阻擋結帳**。
  2. 在訂單上標記「載具 / 捐贈碼未經線上驗證」並記錄原因，方便事後追查。
  3. 對顧客不顯示錯誤，或只給中性提示（例如「暫時無法線上驗證，將以您輸入的號碼開立」）。
- 只有在**確定查到 `IsExist=N`** 時才阻擋。
- 後續開立發票若因載具 / 捐贈碼出問題而失敗，走既有的開立失敗處理流程（見 error-codes.md）。

### 實作細節提醒

- **兩個簽章的順序相反**：`CheckValue` = `HashKey=…&{PostData_}&HashIV=…`；`CheckCode` = `HashIV=…&{Result 密文}&HashKey=…`（以範例值實算）。上線前請用測試環境的真實回應再驗一次 CheckCode 的順序。
- 驗證 CheckCode 時要用 **Result 的密文 hex 原字串**，不是解密後的明文；比對請用 timing-safe compare。
- 解密後用 query string parser 解析（例如 `URLSearchParams`），`%2F` 會自動還原成 `/`。欄位名稱請容錯：`CellphoneBarcode` / `PhoneBarcode`、`Lovecode` / `LoveCode`。
- 原文未說明失敗回應是否帶 `Result` / `CheckCode`，所以只在 `Status=SUCCESS` 時驗章、解密。
- `RespondType` 建議固定用 `JSON`，比較好解析。String 模式要處理 `EndStr=##`，否則 `EndStr` 會混進欄位。
- AES 加解密可沿用開立發票的實作（PKCS#7 blocksize 32、關閉自動 padding、小寫 hex，見 integration.md），但**請求組裝與驗章都要另外寫**。

TypeScript 草稿（沿用 integration.md 的 `encryptPostData` / `decryptPostData`；兩個 SHA256 函式已用原文範例值驗算吻合）：

```typescript
import * as crypto from 'crypto';

const sha256Upper = (s: string) =>
  crypto.createHash('sha256').update(s, 'utf8').digest('hex').toUpperCase();

/** Request 簽章（附件三 PHP 原式）：HashKey 在前 */
export const computeCheckValue = (postData: string, key: string, iv: string) =>
  sha256Upper(`HashKey=${key}&${postData}&HashIV=${iv}`);

/** Response 檢查碼（以原文範例值實算）：HashIV 在前，雜湊 Result 密文 hex */
export const computeBdvCheckCode = (resultHex: string, key: string, iv: string) =>
  sha256Upper(`HashIV=${iv}&${resultHex}&HashKey=${key}`);

export function buildCheckBarCodeBody(merchantId: string, barcode: string, key: string, iv: string) {
  const postData = encryptPostData(
    { TimeStamp: Math.floor(Date.now() / 1000), CellphoneBarcode: barcode },
    key, iv,
  );
  return new URLSearchParams({
    MerchantID_: merchantId,
    Version: '1.0',
    RespondType: 'JSON',
    PostData_: postData,
    CheckValue: computeCheckValue(postData, key, iv),
  }).toString(); // application/x-www-form-urlencoded
}

export function parseBdvResponse(json: { Status: string; Result?: string; CheckCode?: string }, key: string, iv: string) {
  if (json.Status !== 'SUCCESS') return { ok: false as const, code: json.Status };
  const expected = Buffer.from(computeBdvCheckCode(json.Result ?? '', key, iv));
  const actual = Buffer.from(json.CheckCode ?? '');
  if (expected.length !== actual.length || !crypto.timingSafeEqual(expected, actual)) {
    return { ok: false as const, code: 'CHECKCODE_MISMATCH' };
  }
  const fields = new URLSearchParams(decryptPostData(json.Result!, key, iv));
  return { ok: true as const, isExist: fields.get('IsExist') === 'Y' };
}
```
