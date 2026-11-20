# 順豐國際開放平台：認證、加解密與簽名（Access Token / dispatch / AES / signature）

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：要實作「取 token → 加密 body → 算 signature → POST dispatch → 解密回應」任一步、排查 `签名验证错误` / `Access denied, token invalid` / `system error`、或要接 ISV（第三方 ERP authCode）時。完整可執行 PHP / TypeScript 程式碼在 `php-client.md`。

## 目錄

- [0.1 環境與端點總表](#01-環境與端點總表)
- [0.2 憑證與 token 生命週期](#02-憑證與-token-生命週期)
- [0.3 dispatch 請求封包](#03-dispatch-請求封包)
- [0.4 AES 加密規格（BizMsgCrypt，微信公眾平台同構）](#04-aes-加密規格bizmsgcrypt微信公眾平台同構)
- [0.5 signature 計算](#05-signature-計算)
- [0.6 回應外殼與解密](#06-回應外殼與解密)
- [0.7 加解密 SDK 錯誤碼（官方 SampleCode ErrorCode）](#07-加解密-sdk-錯誤碼官方-samplecode-errorcode)
- [0.8 驗證紀錄（2026-09-23）](#08-驗證紀錄2026-09-23)
- [1. Access Token接口](#1-access-token接口)
- [2. 業務接口](#2-業務接口)
- [3. 加解密樣例代碼](#3-加解密樣例代碼)
- [4. 第三方ERP平台(ISV)接入](#4-第三方erp平台isv接入)

---

## 0.1 環境與端點總表

| 用途 | 沙箱（SIT） | 正式（PROD） | Method |
|---|---|---|---|
| 取 Access Token | `http://api-ifsp-sit.sf.global/openapi/api/token` | `https://api-ifsp.sf.global/openapi/api/token` | GET（querystring `appKey` / `appSecret`） |
| 所有業務接口（單一入口，以 header `msgType` 分派） | `http://api-ifsp-sit.sf.global/openapi/api/dispatch` | `https://api-ifsp.sf.global/openapi/api/dispatch` | POST |

- 官方文件多數頁面寫沙箱為 **http**；少數頁（跨境運輸 IFTP、大包揽收、官方 Skill 的 PHP SampleCode）寫 **https**。2026-09-23 實測 `http://` 與 `https://api-ifsp-sit.sf.global` **兩者皆可用**（token 與 dispatch 均回 200）。
- 文件筆誤：批量派送證明頁與 JS 範例出現 `api-ifsp.sit.sf.global`（點號）——正確主機是 `api-ifsp-sit.sf.global`（連字號）。
- 正式環境只有 https。
- 控制台（需登入）：`https://openapi.sf.global/`；商家後台 IUOP：`https://iuop.sf-express.com`（港澳台 / 海外客戶也可用 `https://iuop.sf.global/`）。

---

## 0.2 憑證與 token 生命週期

| 憑證 | 來源 | 用途 |
|---|---|---|
| `appKey` | 控制台 › 應用列表 › 詳情 › 生產 Key | token 查詢參數、dispatch header、**AES 明文尾碼（appId）** |
| `appSecret` | 控制台 › 應用列表 › 詳情 › 生產密鑰 | 只用來換 token |
| `encodingAesKey`（AES 密鑰，43 字元） | 控制台 › 應用列表 › 詳情 › AES 密鑰 | body 加解密 |
| `customerCode`（客戶編碼，例 `ICRME000SRN93`） | IUOP › 管理中心 › 基本信息 › 客戶編號 | 放在 **body**（IUOP 類接口必填） |
| `apiUsername` | IUOP › 管理中心 › 基本信息 › 用戶名 | IECS 出口電商接口 body 必填 |
| 月結卡號（`payMonthCard` / `monthlyCard`） | IUOP 首頁 › 月結帳號（需由地區銷售在 ICRM 綁定） | 付款、POD、計費查詢 |

token 規則（官方 Access Token 頁 + FAQ）：
- 有效期 **7200 秒（2 小時）**，可提前幾分鐘刷新；**取得新 token 後舊 token 立即失效**。
- 不要每次請求都取新 token；多套系統共用同一組 appKey 時必須共享 token（例：Redis / WP transient），或在「API 授權」為每套系統申請獨立金鑰。
- **沙箱 token 是固定值**（FAQ：「測試環境為了方便起見，token 都是固定的」）；2026-09-23 實測沙箱回 `auth_fba5c391-a751-4f47-a394-c6f0bb3c4130_1638339241848`。所以「沙箱正常、上正式後 `Access denied, token invalid`」通常是正式環境 token 被另一個程序刷新導致。

---

## 0.3 dispatch 請求封包

HTTP header（全部業務接口共用；官方「業務接口」頁）：

| # | Header | 必填 | 說明 |
|---|---|---|---|
| 1 | `appKey` | 是 | App key |
| 2 | `token` | 是 | Access Token 接口取得的 `accessToken` |
| 3 | `timestamp` | 是 | Unix 時間戳（**毫秒**），字串 |
| 4 | `nonce` | 是 | 請求隨機數（可用 UUID；**簽名時要用同一個值**） |
| 5 | `signature` | 是 | 見 §0.5 |
| 6 | `msgType` | 是 | 接口編碼，例 `IUOP_CREATE_ORDER` |
| 7 | `lang` | 否（多數 API 頁寫 Y） | `en` / `zh-CN` / `zh-HK`（繁體）。**沒有 `zh-TW`**（僅「國際收寄標準」body 內的 `lang` 欄位用 `zh-TW`） |
| 8 | `authCode` | 否 | **僅第三方 ERP（ISV）應用必填**：ERP 用戶在控制台產生、交給 ISV 的授權碼 |
| — | `Content-Type` | 是 | 固定 `application/json`（FAQ：不是 application/json 會回 `system error`） |

HTTP body：**整個 body 就是一串 base64 密文字串**（不是 JSON、不是 key-value、不要自己加換行；FAQ 第 5 點：組成 key-value 會回「签名验证错误」）。

---

## 0.4 AES 加密規格（BizMsgCrypt，微信公眾平台同構）

依官方 SampleCode（Java / PHP / JS / Python / Go / C#，下載點見本檔 §3「加解密樣例代碼」）逐行比對整理：

| 項目 | 規格 |
|---|---|
| 演算法 | **AES-256-CBC**，**NoPadding**（自行做 PKCS7） |
| Key | `base64_decode(encodingAesKey + "=")` → 32 bytes（encodingAesKey 必須 43 字元，否則 -40004） |
| IV | **Key 的前 16 bytes**（不是隨機 IV） |
| 明文組裝 | `random16 + pack("N", strlen(json)) + json + appKey`：16 字元隨機英數 + 4 bytes 大端序長度（UTF-8 byte 長度）+ JSON 明文 + appKey |
| Padding | **PKCS7，block size = 32**（不是 16）；pad 值 1..32，剛好整除時補 32 個 `chr(32)` |
| 輸出 | base64(ciphertext) |
| 解密 | base64 decode → AES-256-CBC NoPadding → 去 PKCS7（pad 值不在 1..32 視為 0）→ 跳過前 16 bytes → 讀 4 bytes 長度 N → 取 N bytes 為 JSON → 剩餘必須等於 appKey（不等 = -40005） |

⚠️ PHP 官方樣例在 `openssl_encrypt` 傳的是 `OPENSSL_NO_PADDING` 常數（值 3，恰好等於 `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING`）；自行實作請明確寫 `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING`。
⚠️ 長度欄位必須是 **UTF-8 byte 長度**（PHP `strlen`，不是 `mb_strlen`；JS 要用 Buffer.byteLength），body 含中文時才不會壞。

---

## 0.5 signature 計算

```
signature = lowercase_hex( SHA256( join("", sort_as_strings([token, timestamp, nonce, encrypt])) ) )
```

- 四個值：token（Access Token）、timestamp（header 同值）、nonce（header 同值）、encrypt（本次 body 密文）。
- **字串字典序排序**（PHP `sort($a, SORT_STRING)`、Java `Arrays.sort`、JS `Array.prototype.sort()` 預設）後直接串接，無分隔符。
- 官方文字寫「快速排序」，指的就是字串排序；**不是**按固定順序串接。
- Java 樣例類別名叫 `SHA1`，但 dispatch 用的是其中的 `getSHA2()`（SHA-256），別誤用 `getSHA1()`。
- 回應**沒有**簽名需要驗；推送（webhook）用的是另一套 MD5 簽名，見 `tracking.md` 運單軌跡推送。

---

## 0.6 回應外殼與解密

```json
{ "apiResultCode": 0, "apiErrorMsg": null, "apiResultData": "<base64 密文>", "apiTimestamp": 1638258404495 }
```

| 欄位 | 說明 |
|---|---|
| `apiResultCode` | 平台層響應碼（int），**0 = 平台處理成功**；非 0 見 `error-codes.md` §響應碼（如 500 token invalid、504 系統繁忙） |
| `apiErrorMsg` | 平台層錯誤描述（明文） |
| `apiResultData` | `apiResultCode = 0` 時為 **AES 密文字串**，需用 §0.4 解密；解出來才是業務 JSON `{ "success": bool, "code": "0", "msg": "...", "data": {...} }` |
| `apiTimestamp` | 響應時間戳（ms） |

- **兩層判斷**：先判 `apiResultCode === 0`，再解密後判業務層 `success === true`（以及 `code`）。HTTP 200 不代表成功。
- 業務層 `code` 型別不一致：IUOP 多為字串 `"0"`；POD / 計費類為 `200000`（數字或字串）；IECS 取消/攔截為 `"200"`；代理類為 `SUC_ADES-MANAGE_00_0000`；關務上傳為 `"ok"`。**以 `success` 布林值為主判斷**。
- 官方各 API 頁的「明文響應示例」多數只列解密後的業務 JSON（少數頁連外殼一起列）。

---

## 0.7 加解密 SDK 錯誤碼（官方 SampleCode ErrorCode）

| 代碼 | 意義 |
|---|---|
| 0 | OK |
| -40001 | 簽名驗證錯誤（ValidateSignatureError） |
| -40002 | xml 解析失敗（ParseXmlError，沿用自微信 SDK，本平台不用 XML） |
| -40003 | sha 加密生成簽名失敗（ComputeSignatureError） |
| -40004 | encodingAesKey 非法（IllegalAesKey，長度不是 43） |
| -40005 | appid 校驗錯誤（ValidateAppidError，解密後尾碼 ≠ appKey） |
| -40006 | aes 加密失敗（EncryptAESError） |
| -40007 | aes 解密失敗（DecryptAESError） |
| -40008 | 解密後得到的 buffer 非法（IllegalBuffer） |
| -40009 | base64 加密失敗（EncodeBase64Error） |
| -40010 | base64 解密失敗（DecodeBase64Error） |
| -40011 | 生成 xml 失敗（GenReturnXmlError） |

這些是**本地 SDK** 的回傳值，不會出現在 API 回應中。

---

## 0.8 驗證紀錄（2026-09-23）

- 離線：以官方 Php.zip `Demo.php` 與 Python `Sample.py` 的密文 / 金鑰（`encodingAesKey=dcLutUPXvxDjzVDgLV0SsAI5UAxmEloNHEZVtgxnvuA`、`appKey=76a5c52b9027505f4fcc4c358fb58211`）驗證 `php-client.md` 的實作：兩組官方密文解出 `sdfsdafafasdfadfad发生的发生地方`、`1231231`；與官方 `BizMsgCrypt` 互相加解密一致；signature 與官方 `getSHA2` 完全相同。
- 線上（公開沙箱憑證，**僅唯讀查詢**）：`IPE_QUERY_PRODUCT_LIST`、`ICMS_QUERY_COUNTRY_LIST`、`ICMS_QUERY_POST_CODE_ATTRIBUTE`、`ICMS_PHONE_VALID_CHECK`、`IUOP_QUERY_FREIGHT`、`ICMS_QUERY_REGION_BY_POST_CODE`、`ICMS_REGION_CASCADE_QUERY` 均回 `apiResultCode: 0` 且 `apiResultData` 為密文、可正確解密。`ICMS_QUERY_PROVINCE_LIST` 曾回 `{"apiResultCode":504,"apiErrorMsg":"系统繁忙，请稍后再试"}`（平台層錯誤時**沒有** `apiResultData`）。
- 未做任何建單 / 取消 / 上傳等寫入型呼叫。

---

## 1. Access Token接口

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=75（文檔中心 > 開發文檔 > Access Token接口；官方更新 2023-05-17；爬取 2026-09-23）

根據appKey和appSecret獲取訪問接口的token，有效時間為2小時（可以提前幾分鐘定時刷新），獲取新token後舊token會失效。
**注意：不是每次請求都需要獲取新的token，token在有效時間內可以重複使用如果一個客戶有多套不同的系統應用需要用到順豐國際openapi的接口，解決方案有兩個：1.客戶僅申請一套秘鑰，需自行在不同的系統中同步最新的token，比如通過Redis緩存並定時更新；2.客戶在“API授權” 界面為不同的系統申請多套授權秘鑰**

|  |  |
| --- | --- |
| 沙盒環境 | http://api-ifsp-sit.sf.global/openapi/api/token |
| 生產環境 | https://api-ifsp.sf.global/openapi/api/token |
| 接口方式 | GET |

### 元素<請求頭>

| 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | ------ | --- | --- |
| lang | String | 否 | 語言編碼：<br>en：英文<br>zh-CN：簡體中文<br>zh-HK：繁體中文 |

### 元素<請求Param>

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | appKey | String | 是 |  |
| 2 | appSecret | String | 是 |  |

### 元素<響應>Response

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | apiErrorMsg | String | 否 | 響應描述 |
| 2 | apiResultData | TokenInfo | 否 | 業務接口響應數據 |
| 3 | apiResultCode | int | 是 | 響應碼（0為成功） |
| 4 | apiTimestamp | long | 是 | 響應時間戳 |

### TokenInfo

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | expireIn | int | 否 | Token存活時間（秒） |
| 2 | accessToken | String | 否 |  |

### 請求報文示例

/openapi/api/token?appKey=015a2ffa139ce123d17f3c0c2c06da60&appSecret=e9846bece1b4299d456e65e56fbff7b3

### 響應示例

```
{
    "apiResultCode": 0,
    "apiErrorMsg": null,
    "apiResultData": {
        "expireIn": 7200,
        "accessToken": "auth_85c182d8-cefd-4823-bca5-eeeb0808b82e_1638187917344"
    },
    "apiTimestamp": 1638187917355
}
```

---

## 2. 業務接口

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=76（文檔中心 > 開發文檔 > 業務接口；官方更新 2024-12-03；爬取 2026-09-23）

因業務報文可能涉及敏感數據，所以需要對業務報文做加解密處理。

### 請求body加密

首先根據各個接口的要求組裝請求JSON報文，例如：
請求報文：

```
{
"customerCode":"ICRMXXXXXXX",
"sfWaybillNo":"SF13244819XXXX"
}
```

然後把整個JSON報文通過AES算法進行加密。最後把密文放在請求Body裡面
（原文此處為截圖，未轉錄）
各種編程語言（Java、PHP、JS、C#、Go、Python）的加解密樣例代碼：http://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=106（見 `auth-and-crypto.md`）
這個是postman生成的curl，可以參考下：

```
curl --location --request POST 'http://api-ifsp-sit.sf.global/openapi/api/dispatch' \
--header 'appKey: 9f088b8c1df4af08e883e522bf8a15eb' \
--header 'token: auth_6f3345bb-d39d-46d7-af6c-0ee8a42f1d91_1668591130543' \
--header 'timestamp: 1634558565497' \
--header 'nonce: 123' \
--header 'signature: 6a7959616a98bf3bf15befe75aea1b7e8d68969934c545013c127f1ac169a834' \
--header 'msgType: IECS_PRINT_ORDER' \
--header 'Content-Type: application/json' \
--data 'G4gbT/IMlYBQweoJVc7vCenxEYJx80fne4Pwil7G6ZtG5XLk7ZIR/izB8Bgq7Sk4dWH6qPrs5EetanNRRPblzhveoPO8nabpYYq3pNN1F8+pcfDS1fCtoX6vTH2ytVF4pStQiCoi9iUIOznSqbsvmeFcDhq7jNn/txOvjXdFlzZCxJF9Ussqh1vXymJkL7xIZlPzNq6sDb5p7w5wrzPy+h1Ryx3/FrtUwVCkQimJ6S4/fZaEFVzNT1/2NLWY8U'
```

### 生成簽名

根據token,timestamp,nonce和encrypt(加密後的請求報文)的值進行快速排序，然後按排好的序拼接字符串再進行sha256編碼獲取簽名(signature)，具體邏輯參考加解密代碼樣例

### 請求返回解密

請求返回的報文如果apiResultCode=0，可以通過AES算法對data進行解密，獲取接口的JSON報文
（原文此處為截圖，未轉錄）

```
{
    "apiResultCode": 0,
    "apiErrorMsg": null,
    "apiResultData": "B/cEsPrO9C9vqYWiUl/KcShCsUF3/1Xu3QNLxGTF/zMDW75Q5+95WW",
    "apiTimestamp": 1638258404495
}
```

### 接口信息

**下面各個業務接口都是使用這個地址進行調用，openapi會根據頭參數的msgType來分發到具體的業務接口。**

|  |  |
| --- | --- |
| 沙盒環境 | http://api-ifsp-sit.sf.global/openapi/api/dispatch |
| 生產環境 | https://api-ifsp.sf.global/openapi/api/dispatch |
| 接口方式 | POST |

### 元素<請求頭>

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | appKey | String | 是 | App key |
| 2 | token | String | 是 | Access Token接口獲取的token |
| 3 | timestamp | String | 是 | Unix時間戳（ms格式） |
| 4 | nonce | String | 是 | 請求隨機數（可使用UUID，需要注意生成簽名時候需要用到這個字段） |
| 5 | signature | String | 是 | 根據token,timestamp,nonce和encrypt(加密後的請求報文)的值進行快速排序，然後按排好的序拼接字符串再進行sha256編碼獲取簽名(signature)，具體邏輯參考加解密代碼樣例 |
| 6 | msgType | String | 是 | 接口編碼 |
| 7 | lang | String | 否 | 語言編碼：<br>en：英文<br>zh-CN：簡體中文<br>zh-HK：繁體中文 |

### 元素<請求Param>

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 |  | String | 是 | 通過AES Key加密的業務接口請求報文 |

### 元素<響應>Response

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | apiErrorMsg | String | 否 | 響應描述 |
| 2 | apiResultData | Object | 否 | 業務接口響應數據<br>常見業務接口錯誤響應信息請參考<br>出口電商常見異常響應（見 `error-codes.md`） <br>國際快遞常見異常響應（見 `error-codes.md`） |
| 3 | apiResultCode | int | 是 | 響應碼 |
| 4 | apiTimestamp | long | 是 | 響應時間戳 |

### 請求報文示例

（原文此處為截圖，未轉錄）
（原文此處為截圖，未轉錄）

```
curl --location 'http://api-ifsp-sit.sf.global/openapi/api/dispatch' \
--header 'appKey: 9f088b8c1df4af08e883e522bf8a15eb' \
--header 'token: auth_6f3345bb-d39d-46d7-af6c-0ee8a42f1d91_1668591130543' \
--header 'timestamp: 1634558565497' \
--header 'nonce: 123' \
--header 'signature: 6a7959616a98bf3bf15befe75aea1b7e8d68969934c545013c127f1ac169a834' \
--header 'msgType: IECS_PRINT_ORDER' \
--header 'Content-Type: application/json' \
--data 'G4gbT/IMlYBQweoJVc7vCenxEYJx80fne4Pwil7G6ZtG5XLk7ZIR/izB8Bgq7Sk4dWH6qPrs5EetanNRRPblzhveoPO8nabpYYq3pNN1F8+pcfDS1fCtoX6vTH2ytVF4pStQiCoi9iUIOznSqbsvmeFcDhq7jNn/txOvjXdFlzZCxJF9Ussqh1vXymJkL7xIZlPzNq6sDb5p7w5wrzPy+h1Ryx3/FrtUwVCkQimJ6S4/fZaEFVzNT1/2NLWY8U'
```

### 響應示例

（原文此處為截圖，未轉錄）

---

## 3. 加解密樣例代碼

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=106（文檔中心 > 開發文檔 > 加解密樣例代碼；官方更新 2022-11-28；爬取 2026-09-23）

### 樣例代碼

| 編程語言 | 加解密樣例代碼下載地址 |
| ---- | ----------- |
| Java | [https://storage.googleapis.com/ifsp-public/SampleCode/Java.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Java.zip) |
| PHP | [https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip) |
| JS | [https://storage.googleapis.com/ifsp-public/SampleCode/JS.zip](https://storage.googleapis.com/ifsp-public/SampleCode/JS.zip) |
| C# | [https://storage.googleapis.com/ifsp-public/SampleCode/C%23.zip](https://storage.googleapis.com/ifsp-public/SampleCode/C%23.zip) |
| Go | [https://storage.googleapis.com/ifsp-public/SampleCode/Go.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Go.zip) |
| Python | [https://storage.googleapis.com/ifsp-public/SampleCode/Python.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Python.zip) |

### 生成代碼

登錄控制台後，可以進入菜單沙箱工具-在線調試報文工具-生成代碼，這個功能會根據當前的報文和鑑權信息生成請求代碼或加密代碼
沙箱工具地址：[http://openapi.sf.global/SandboxModule/OnlineDebugApi](http://openapi.sf.global/SandboxModule/OnlineDebugApi)
（原文此處為截圖，未轉錄）

#### 請求代碼

生成加密後請求接口的代碼
（原文此處為截圖，未轉錄）

#### 加密代碼

結合上面的加解密樣例代碼和請求代碼，以供開發者參考
（原文此處為截圖，未轉錄）

---

## 4. 第三方ERP平台(ISV)接入

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=144（文檔中心 > 開發文檔 > 第三方ERP平台(ISV)接入；官方更新 2024-03-29；爬取 2026-09-23）

### 1.創建應用

對接API，需在[[控制台-應用列表](https://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList)]創建應用獲取對接信息和關聯對接的API，參考文檔：第三方ERP對接指引（見 `onboarding-and-faq.md`）

### 2.對接完成後申請審批

沙箱環境對接完成後聯繫對接人進行審批

### 3.與客戶自研應用的區別

| # | 屬性名 | 類型（約束） | 必填 | 描述 |
| --- | --- | ------ | --- | --- |
| 1 | appKey | String | 是 | App key |
| 2 | token | String | 是 | Access Token接口獲取的token<br>獲取方法查考：Access Token接口（見 `auth-and-crypto.md`） |
| 3 | timestamp | String | 是 | 時間戳 |
| 4 | nonce | String | 是 | 請求隨機數（可使用UUID，需要注意生成簽名時候需要用到這個字段） |
| 5 | signature | String | 是 | 根據token,timestamp,nonce和encrypt(加密後的請求報文)的值進行快速排序，然後按排好的序拼接字符串再進行sha256編碼獲取簽名(signature)，具體邏輯參考：加解密樣例代碼（見 `auth-and-crypto.md`） |
| 6 | msgType | String | 是 | 接口編碼 |
| 7 | lang | String | 否 | 語言編碼：<br>en：英文<br>zh-CN：簡體中文<br>zh-HK：繁體中文 |
| 8 | authCode | String | 否 | 授權碼，第三方應用時必填 |

### 4.ERP用戶接入

創建應用，需在[[控制台-應用列表](https://openapi.sf.global/IsvModule/IsvUserAppList)]創建應用獲取授權信息和選擇授權第三方平台(ERP)，提供得到的”授權碼“給第三方平台，參考文檔：ERP用戶對接指引（見 `onboarding-and-faq.md`）
