---
name: ezpay-invoice
description: >
  ezPay 電子發票（藍新金流 NewebPay 旗下，簡單行動支付）API 技術參考，對應三份官方
  手冊：EZP_INVI_1.2.1 標準版（開立 invoice_issue、觸發開立 invoice_touch_issue、
  作廢 invoice_invalid、開立折讓 allowance_issue、確認/取消折讓 allowance_touch_issue、
  作廢折讓 allowanceInvalid、查詢 invoice_search）、BDV_1.0.0 手機條碼/捐贈碼驗證
  （checkBarCode、checkLoveCode）、EZP_Track_1.0.0 字軌管理（createNumber、
  manageNumber、searchNumber）。含 AES-256-CBC PostData_（PKCS#7 blocksize 32）、
  CheckCode SHA256 與適用範圍、重送冪等（LIB10003）、B2C 含稅 / B2B 未稅金額與
  整數化、載具 CarrierType、捐贈碼 LoveCode、課稅別 TaxType、錯誤碼、PHP 與
  TypeScript 參考實作。Use when code or tasks involve ezPay、ezpay、藍新電子發票、
  cinv.ezpay.com.tw、inv.ezpay.com.tw、PostData_、MerchantID_、CheckCode、HashKey、
  HashIV、開立發票、作廢發票、折讓、字軌、手機條碼驗證、捐贈碼驗證、台灣電子發票串接。
  僅限 ezPay；光貿發票走 amego-invoice、綠界發票走 ecpay-api。
---

# ezPay 電子發票 API

ezPay 電子發票加值服務平台由**簡單行動支付股份有限公司**（藍新金流 NewebPay
集團旗下品牌）提供，串接台灣財政部電子發票。所有 API 採 **HTTP POST + 標準
Form Post**（`application/x-www-form-urlencoded`），編碼 UTF-8，回應 JSON 或 String。
本 SKILL 為語言中立的 API 參考，附兩份參考實作：`references/php-client.md`
（PHP，本 repo 規劃的 WordPress 外掛技術棧）與 `references/integration.md`（TypeScript）。

## 文件範圍

| 官方手冊 | 版本 | 本 SKILL 對應檔 |
|----------|------|-----------------|
| 電子發票技術串接手冊（標準版）| EZP_INVI_1.2.1（2021-04-01）| 本檔 + `api-reference.md` / `concepts.md` / `error-codes.md` |
| 手機條碼 / 捐贈碼驗證 | BDV_1.0.0 | `carrier-validation.md` |
| 電子發票字軌管理 | EZP_Track_1.0.0 | `track-management.md` |

- 官方手冊下載：`https://inv.ezpay.com.tw/dw_files/info_api/`（檔名 `EZP_INVI_1_2_1.pdf`、
  `BDV_1_0_0.pdf`、`EZP__Track__1_0_0.pdf`）。本 SKILL 未涵蓋的功能（境外電商版
  `EZP_CES`、發票列表、中獎查詢、發票通知、檔案下載、熱感應機列印）請到同一處找官方手冊，
  **不要自行臆造端點**。
- 原文沒寫清楚、需要沙箱實測的項目集中在本檔最後的「未確認事項」。

## 環境與認證

```
測試平台：https://cinv.ezpay.com.tw/   （申請測試會員、建立測試商店、取得 Hash Key/IV）
正式平台：https://inv.ezpay.com.tw/    （申請正式會員、建立商店、取得 Hash Key/IV）
```

- **測試與正式是不同網域**。切換環境需同時換「網域 + 商店代號 + HashKey + HashIV」；
  測試憑證要在測試平台另外申請，正式憑證不能拿來測。
- 串接前必須在對應平台：① 申請會員並建立商店、取得商店 **API 串接金鑰 HashKey 與
  HashIV**；② 等平台審核啟用電子發票，**並有有效合約**（測試平台要請 ezPay 客服開通，
  沒有合約時開立回 `INV90005`「期間內無可用合約」，合約狀態在【管理設定／使用狀況】查看）；
  ③ 在【管理設定／發票字軌號碼設定】新增「線上字軌」並啟用（或用字軌管理 API 新增），才能開始開立。
- 發票資料可登入平台於【銷項發票作業／銷項發票查詢】查看。

### 串接認證的兩個層級

| 層級 | 機制 |
|------|------|
| 商店識別 | POST body 帶 `MerchantID_`（商店代號，明文，不加密） |
| 資料加密 | POST body 帶 `PostData_`（業務參數用 AES-256-CBC 加密後的 hex 字串） |

> **注意底線**：`MerchantID_` 與 `PostData_` 兩個傳輸參數名稱**結尾都有底線 `_`**。
> **字軌管理 API 例外**：識別欄位是 `CompanyID_`（會員編號）、金鑰是「會員」API 金鑰，
> 見 `track-management.md`。

### PostData_ 加密規則（AES-256-CBC，最容易出錯）

除 `MerchantID_` 外，所有業務欄位先組成 query string，再用 **AES 加密**：

```
演算法：AES-256-CBC
金鑰  ：HashKey（商店專屬，長度 32 bytes）
IV    ：HashIV（商店專屬，長度 16 bytes）
Padding：PKCS#7，blocksize = 32（自行補；加密函式要關掉自動 padding）
輸出  ：加密結果轉小寫 hex 字串
```

加密三步驟：

1. 業務參數組成 query string（`http_build_query` 風格，值需 url encode）。
2. 對該字串補 **PKCS#7 padding**（blocksize = 32）。
3. 用 HashKey + HashIV 做 AES-256-CBC 加密（**關閉自動 padding**，因 padding 已自行
   補好），結果 `bin2hex` 轉小寫 hex → 即 `PostData_` 的值。

> **關鍵陷阱**：官方 PHP 範例 `openssl_encrypt(addpadding($str), 'AES-256-CBC',
> $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)` —— 先自行 `addpadding`
> 補 PKCS#7，再用 `OPENSSL_ZERO_PADDING`（告訴 openssl 不要再補）。若直接用
> openssl 預設 padding 又自行補一次，會多補一個 block 導致解密錯誤（KEY10002）。
> Node 要 `cipher.setAutoPadding(false)`。

### CheckCode 回應驗證（SHA256）

`PostData_` 是 request 端加密；**CheckCode 是 response 端驗證**。計算規則：

```
1. 取回應 5 個欄位 InvoiceTransNo / MerchantID / MerchantOrderNo / RandomNum / TotalAmt
   （缺值以空字串代入）
2. 依參數名稱 A~Z 排序後用 & 串聯成 query string
3. 前後加上 HashIV 與 HashKey：
   "HashIV=<IV>&" + <排序後字串> + "&HashKey=<Key>"
4. 整串做 SHA256，轉大寫 → 與回應的 CheckCode 常數時間比對
```

**只有 3 支 API 能驗**：`invoice_issue`、`invoice_touch_issue`、`invoice_search`。
作廢、折讓、確認折讓、作廢折讓 4 支的回應沒有這 5 個欄位——官方原文仍寫「參考附件二」，
是原文本身的矛盾。這 4 支**不驗**，只看 `Status` 並原樣保存 `CheckCode`
（詳見 `concepts.md`「哪些 API 能驗 CheckCode」）。

## API 端點總覽

測試 / 正式網域不同，路徑相同。

### 發票（EZP_INVI_1.2.1，POST body = `MerchantID_` + `PostData_`）

| 功能 | 路徑 | Version | 驗 CheckCode | 說明 |
|------|------|---------|--------------|------|
| 開立發票 | `/Api/invoice_issue` | `1.5` | 是 | 即時 / 等待觸發 / 預約自動開立 |
| 觸發開立發票 | `/Api/invoice_touch_issue` | `1.0` | 是 | 觸發「等待觸發 / 預約自動」的發票立即開出 |
| 作廢發票 | `/Api/invoice_invalid` | `1.0` | 否 | 作廢已開立發票 |
| 開立折讓 | `/Api/allowance_issue` | `1.3` | 否 | 對已開立發票開折讓單 |
| 觸發確認 / 取消折讓 | `/Api/allowance_touch_issue` | `1.0` | 否 | 確認（C）或取消（D）未確認的折讓 |
| 作廢折讓 | `/Api/allowanceInvalid` | `1.0` | 否 | 作廢已確認的折讓 |
| 查詢發票 | `/Api/invoice_search` | `1.3` | 是 | 查單張發票完整內容（含明細、上傳狀態）|

> **Version 欄位不可亂填**：每支 API 的 `PostData_` 內含 `Version` 欄位有**固定值**
> （見上表）。官方 PHP 範例帶的是舊版 `1.4`，以本表為準。

### 手機條碼 / 捐贈碼驗證（BDV_1.0.0）

| 功能 | 路徑 | 用途 |
|------|------|------|
| 手機條碼驗證 | `/Api_inv_application/checkBarCode` | 結帳時確認手機條碼載具真實存在 |
| 捐贈碼驗證 | `/Api_inv_application/checkLoveCode` | 結帳時確認愛心碼真實存在 |

和發票 API 的差異（細節與實算對照見 `carrier-validation.md`）：

- POST body **五欄**：`MerchantID_`、`Version`（`1.0`，明文）、`RespondType`（明文）、`PostData_`、`CheckValue`。
- Request 要簽章：`CheckValue = UPPER(SHA256("HashKey={key}&{PostData_}&HashIV={iv}"))`。
- 回應 `Result` 是 **AES 密文**（同一組金鑰解），解出來是 query string（例：`Lovecode=01234&IsExist=N`）。
- 回應 `CheckCode = UPPER(SHA256("HashIV={iv}&{Result 密文}&HashKey={key}"))`——**HashIV 在前**，
  和 `CheckValue` 順序相反；原文沒寫這條，已在沙箱用真實回應確認。
- 測試環境的捐贈碼驗證不可靠（不存在的碼也回 `IsExist=Y`），驗證正確性以正式環境為準。
- 驗證 API 失敗或逾時時只做格式檢查，**不可**阻擋結帳。

### 字軌管理（EZP_Track_1.0.0，POST body = `CompanyID_` + `PostData_`）

| 功能 | 路徑 | Version | 用途 |
|------|------|---------|------|
| 新增字軌 | `/Api_number_management/createNumber` | `1.0` | 新增某年某期的發票字軌號碼區間 |
| 管理字軌 | `/Api_number_management/manageNumber` | `1.0` | 變更字軌狀態 |
| 查詢字軌 | `/Api_number_management/searchNumber` | `1.0` | 查某年某期的字軌與使用量 |

字軌 API 的 CheckCode 欄位、錯誤碼都和發票 API 不同（有同碼不同義），見 `track-management.md`。

完整逐欄位參數表見 `references/api-reference.md`。

## 三種開立發票方式

開立發票 `invoice_issue` 的 `Status` 欄位決定開立時機：

| Status | 方式 | 行為 |
|--------|------|------|
| `1` | 即時開立發票 | 傳參數後立即開立，回應帶 `InvoiceNumber` 發票號碼 |
| `0` | 等待觸發開立發票 | 平台暫存發票資料，回應**不帶** `InvoiceNumber`；需另呼叫 `invoice_touch_issue` 才開出 |
| `3` | 預約自動開立發票 | 須帶 `CreateStatusTime` 預計開立日期，平台於該日自動開立；提前開立可呼叫 `invoice_touch_issue` |

- **預設用 `Status=1`**，在付款確認後才呼叫。原文**沒有**取消暫存發票的 API——
  `Status=0/3` 的發票在觸發前若訂單取消，只能本地標記不觸發；`Status=3` 到期仍會自動開出。
- 觸發開立用 `InvoiceTransNo`（開立時回傳的 ezPay 電子發票開立序號）來指定要觸發哪張。

## 課稅別、零稅率與金額計算

### TaxType 課稅別

| 值 | 意義 |
|----|------|
| `1` | 應稅 |
| `2` | 零稅率（必帶 `CustomsClearance`：`1`=非經海關出口、`2`=經海關出口）|
| `3` | 免稅 |
| `9` | 混合應稅與免稅或零稅率（**限 `Category=B2C`**；商品層級用 `ItemTaxType`）|

`TaxRate`：應稅一般稅率帶 `5`；特種稅率帶實際數字（不含 %）；零稅率 / 免稅帶 `0`。

### 含稅 / 未稅金額（順序敏感）

| Category | ItemPrice / ItemAmt 金額性質 | 算法 |
|----------|------------------------------|------|
| `B2C` | **含稅** | `TotalAmt = Σ ItemAmt`；`Amt = round(TotalAmt / 1.05)`；`TaxAmt = TotalAmt − Amt` |
| `B2B` | **未稅** | 電商結帳（顧客付含稅價）：`TotalAmt` = 實付、`Amt = round(TotalAmt / 1.05)`、`TaxAmt = TotalAmt − Amt`，各列未稅小計反推後把尾差補到最大列，使 `Σ ItemAmt = Amt` |

- 平台**只檢核** `ItemAmt = ItemCount × ItemPrice` 與 `TotalAmt = Amt + TaxAmt`
  （折讓則檢核 `TotalAmt = Σ ItemAmt + Σ ItemTaxAmt`）。其他正確性由營業人負責，
  **稅額算法務必與財會確認**。
- `ItemCount` / `ItemPrice` / `ItemAmt` 是 **Int**：單價除不盡、折扣、運費都要先整數化
  （除不盡的列合成 `ItemCount=1`；折扣按比例攤進商品列；運費獨立一列）。
  **負數折扣列與小數單價原文未說明**，沙箱實測前不要送。
- 完整演算法與範例（B2C、B2B 兩種情境、TaxType=9、整數化規則、送出前檢核）見
  `api-reference.md`「金額計算」；PHP 實作見 `php-client.md` 的 `EzpayAmounts`。

## 載具與捐贈

開立 B2C 發票（`Category=B2C`）時，買受人須擇一：存載具、捐贈、或索取紙本。

### 載具 CarrierType / CarrierNum

| CarrierType | 載具種類 | CarrierNum 規則 |
|-------------|----------|------------------|
| `0` | 手機條碼載具 | 第 1 碼 `/` + 7 碼大寫英數（39 字元集 `0-9A-Z+-.`） |
| `1` | 自然人憑證條碼載具 | 2 碼大寫英字 + 14 碼數字 |
| `2` | ezPay 電子發票載具 | 任意可識別買受人代號（e-mail / 手機 / 會員編號）；`BuyerEmail` 變必填 |

- `CarrierType` 有值時 `CarrierNum` 必填，且 `LoveCode` 必為空（**載具與捐贈互斥**）。
- `CarrierNum` 須用 `rawurlencode()` encode，且值前後不得含空白。
- 開立 API **不會**檢查手機條碼 / 愛心碼是否真實存在——格式對但不存在的號碼，
  要到隔日上傳財政部才失敗。結帳時先呼叫 `checkBarCode` / `checkLoveCode` 驗證。

### 捐贈碼 LoveCode

- `LoveCode`：3~7 碼純數字，受贈單位的愛心碼。僅 `Category=B2C` 適用；有值時 `CarrierType` 必為空。

### 索取紙本 PrintFlag

- `Category=B2B` → `PrintFlag` 必填 `Y`。
- `Category=B2C` 且 `CarrierType`、`LoveCode` 皆空 → `PrintFlag` 必填 `Y`。
- `PrintFlag=Y` 時回應才會帶 `BarCode` / `QRcodeL` / `QRcodeR`。

## 回應格式

| RespondType | 回應形態 |
|-------------|----------|
| `JSON` | 最外層 `{ Status, Message, Result }`，`Result` 為 JSON 字串需再 parse |
| `String` | url-encoded query string，**結尾固定 `EndStr=##`**（用於判斷資料完整） |

- `Status=SUCCESS` 代表成功；失敗時 `Status` 為錯誤代碼（見 `references/error-codes.md`）。
- **String 模式的錯誤回應沒有 `EndStr=##`**（沙箱實測：`Status=INV20006&Message=...` 就結束）。
  先看 `Status`，只有 `SUCCESS` 時才要求 `EndStr=##`，否則會把正常的錯誤回應誤判成傳輸不完整。
- 網路錯誤、HTTP 非 2xx、非 JSON 回應都當**暫時性錯誤**處理（見下方「重送與冪等」）。

> **重要**：開立發票回 `Status=SUCCESS` 只代表「ezPay 平台收件並處理成功」，
> **不代表已上傳財政部成功**。平台每日 01:00 起上傳前一日資料，06:00 起更新上傳結果。
> 要用 `invoice_search` 查 `UploadStatus`（`0` 未上傳 / `1` 成功 / `2` 上傳中 /
> `3` 失敗 / `4` 逾時）。失敗的發票不能作廢，也沒有重新上傳的 API，需通知管理員處理。

### 重送與冪等

- `MerchantOrderNo` 同一商店內**不可重覆**；重覆且內容不同 → `LIB10003`。
- 用**完全相同的 `PostData_`** 重送 → 回原發票的 `Result`，不重複開立。
- **陷阱**：`PostData_` 內含 `TimeStamp`，重送時若重新組參數，`PostData_` 就不同 →
  撞 `LIB10003`，但第一次其實已開出。

正確做法：

```
1. 組參數 + 加密 → 送出「之前」把 PostData_ 存進資料庫（狀態：開立中）
2. 逾時 / 網路錯誤 → 用存下的同一份 PostData_ 重送
3. 收到 LIB10003 → 用 invoice_search（SearchType=1，MerchantOrderNo + TotalAmt）取回原發票
```

詳見 `concepts.md`「重送與冪等」。作廢後重開必須用**新的** `MerchantOrderNo`
（建議 `{訂單編號}_{序號}`）。

## 作廢與折讓的業務規則

### 作廢發票（invoice_invalid）

- **作廢期限**：於奇數月 14 日前，可作廢前兩個月開立的發票（例：7/14 前可作廢
  5/1–6/30 開立的發票）。超期回 `LIB10008`，只能改開折讓。
- **不可作廢的情況**：已開過折讓（`LIB10007`，須先作廢折讓）、尚未上傳財政部
  （`LIB10009`，隔日再試）、上傳失敗（`INV70002`）、已作廢過（`LIB10005`，視為成功）。

### 折讓流程（兩段式）

| allowance_issue 的 Status | 意義 |
|---------------------------|------|
| `0` | 開立後**不立即確認**——僅記錄於平台，需再呼叫 `allowance_touch_issue` |
| `1` | 開立後**立即確認**——平台隔日上傳財政部 |

- `allowance_touch_issue`：`AllowanceStatus=C` 確認（隔日上傳）、`D` 取消。
  **已確認的折讓無法再取消**，只能用 `allowanceInvalid` 作廢。
- 折讓單價可為未稅（`ItemTaxAmt` = 小計 × 稅率）或含稅（`ItemTaxAmt=0`，申報無法扣抵），
  **請與會計確認**。回應的 `RemainAmt` 是折讓後剩餘發票金額，多次部分退款時用它追蹤可折餘額。
- 混合稅率發票需依應稅 / 零稅率 / 免稅**個別開折讓單**（`TaxTypeForMixed`）。

## 整合工作流程（典型電商）

```
1. 結帳：顧客選載具 / 捐贈 / 統編 → 呼叫 checkBarCode / checkLoveCode 驗證（API 失敗時只做格式檢查，不擋結帳）
2. 付款確認後：品項整數化 → 算 Amt / TaxAmt / TotalAmt → 送出前本地檢核
3. 組參數 → 加密成 PostData_ → 存檔（開立中）→ POST /Api/invoice_issue
4. SUCCESS → 驗 CheckCode → 存 InvoiceNumber / RandomNum / InvoiceTransNo
   暫時性錯誤 → 同一份 PostData_ 重送；LIB10003 → invoice_search 取回
5. 每日 06:00 後排程查 UploadStatus，失敗 / 逾時通知管理員
6. 退貨 → allowance_issue 開折讓；整張取消 → invoice_invalid 作廢（期限內、未開過折讓、已上傳）
7. 字軌：每期開始前用 searchNumber 檢查剩餘張數，不足時 createNumber 或通知管理員（避免 INV90006）
```

開立失敗**不可**連帶中斷訂單狀態變更——記錄錯誤、交給排程重試或人工處理。

## 禁止事項與常見陷阱

- ❌ 漏掉傳輸參數的底線——是 `MerchantID_` / `PostData_`（字軌 API 是 `CompanyID_`）。
- ❌ 弄錯 AES padding——自行補 PKCS#7（blocksize 32），加密時關閉自動 padding；重複補或不補都會 `KEY10002`。
- ❌ 填錯 `Version`（issue 1.5 / touch 1.0 / invalid 1.0 / allowance_issue 1.3 / allowance_touch 1.0 / allowanceInvalid 1.0 / search 1.3）。
- ❌ 對作廢 / 折讓類回應套用 5 欄位 CheckCode 規則——會永遠驗不過。
- ❌ 重送時重新組參數（`TimeStamp` 改變 → `LIB10003`）；收到 `LIB10003` 就換編號重開（會重複開票）。
- ❌ 照抄官方 B2B 範例數字（`Amt=490 / TaxAmt=10` 的稅額不是 5%）。
- ❌ 送小數單價或負數折扣列（原文未說明，未實測前不送）；品名含 `|` 或超過 30 字。
- ❌ B2C 與 B2B 的 `ItemPrice` / `ItemAmt` 金額性質弄反——B2C 含稅、B2B 未稅。
- ❌ 載具與捐贈同時有值；B2B 或 B2C 無載具無捐贈時 `PrintFlag` 不是 `Y`。
- ❌ 假設 `Status=SUCCESS` = 財政部上傳成功——要查 `UploadStatus`。
- ❌ 只用錯誤碼判斷處置——三份手冊有同碼不同義（如 `LIB10007`），要以「API + 錯誤碼」判斷。
- ❌ 把 HashKey / HashIV 寫進程式或版控；把 `PostData_` 全文或買受人個資寫進 log。

## 沒有合約時怎麼開發

測試商店的合約要請 ezPay 客服開通。等待期間**不必停工**，合約只擋 `invoice_issue` 與
`invoice_touch_issue` 真正開出發票（回 `INV90005`）：

| 層次 | 沒有合約能做到的 |
|------|------------------|
| 加密、欄位、Version | 對真實測試平台驗證：格式錯會回 `INV100xx`，格式對的開立請求回 `INV90005` |
| 查詢、作廢、折讓、確認折讓、作廢折讓 | 打得通，用不存在的號碼會回「查無資料」，可驗證整條請求路徑 |
| 載具 / 捐贈碼驗證 | 完全可用 |
| 金額計算、整數化、重送狀態機、錯誤碼分流 | 本機單元測試，ezPay 回應用本 SKILL 的範例做 fake / mock |

需要合約才能驗證的：拿到真實發票號碼、成功回應的 CheckCode、同一份 `PostData_` 重送、
`LIB10003` 取回、折讓與作廢完整流程，以及下方「未確認事項」的 #1–#5。

## 未確認事項（待沙箱實測）

原文沒寫清楚、需要用測試平台憑證實測後才能定案。未確認前一律採「本 SKILL 的處置」欄。
2026-09-29 已做第一輪沙箱實測；當時測試商店尚未啟用電子發票合約（開立回 `INV90005`），
所以只能確認「格式檢核」層級。平台的檢核順序是**格式檢核 → 合約檢核**：格式錯誤的請求會先回
`INV10003` / `INV10004` / `INV10012` / `INV10013`，格式通過才輪到 `INV90005`。

| # | 問題 | 實測狀態 | 本 SKILL 的處置 |
|---|------|----------|-----------------|
| 1 | `ItemPrice` 可否為負數（折扣列）| 🟡 通過格式檢核（回 `INV90005`，不是 `INV10003`/`INV10004`）；尚未實際開出、上傳財政部 | 維持：不送負數，折扣攤進商品列 |
| 2 | `ItemPrice` 可否為小數、0 元列可否送 | 🟡 `2 × 52.5 = 105`、0 元列皆通過格式檢核；尚未實際開出 | 維持：只送正整數，0 元列省略 |
| 3 | 作廢 / 折讓 / 確認折讓 / 作廢折讓回應的 CheckCode 算法 | ⏳ 待合約啟用後實開 | 不驗，原樣保存 |
| 4 | `TimeStamp` 新舊是否被檢查 | 🟡 過去 2 天、未來 2 小時都通過格式檢核；非數字回 `INV10013`。同一份 `PostData_` 重送的冪等行為待實開確認 | 重送用同一份 `PostData_`；失敗就改走 `invoice_search` 取回 |
| 5 | `Status=0/3` 暫存發票能否取消 | ⏳ 待合約啟用 | 預設不用 0/3，一律 `Status=1` |
| 6 | 字軌 API CheckCode 前綴 `HashIv`（小寫 v）是否為正式行為 | ⏳ 待會員編號與會員金鑰 | 依原文範例用 `HashIv`，見 `track-management.md` |
| 7 | 載具驗證 API 回應 CheckCode 算法 | ✅ 已確認：3 筆真實回應都是 HashIV 在前 | 依此算法驗；不符時記 log、不擋結帳 |

實測腳本與紀錄不進版控；重跑時沿用 `php-client.md` 的演算法，只打測試網域 `cinv.ezpay.com.tw`。

## Reference 檔案導覽

| 檔案 | 內容 |
|------|------|
| `references/api-reference.md` | 7 支發票 API 逐欄位 request / response、回應範例、**金額計算與整數化**（B2C / B2B / TaxType=9 範例、送出前檢核）|
| `references/concepts.md` | AES 加密細節、CheckCode 規則與**適用範圍**、載具、課稅別、發票生命週期、**重送與冪等**、作廢後重開、暫存發票、財政部上傳失敗處理 |
| `references/error-codes.md` | 發票 API 錯誤碼表（KEY / INV / LIB / IAI / NOR）、依 API 分類、處置建議、作廢冪等判斷 |
| `references/carrier-validation.md` | 手機條碼 / 捐贈碼驗證 API（BDV_1.0.0）|
| `references/track-management.md` | 字軌管理 API（EZP_Track_1.0.0）：新增 / 管理 / 查詢字軌、CheckCode、錯誤碼、年度期別規則 |
| `references/php-client.md` | **PHP 參考實作**（本 repo 技術棧）：加解密、CheckCode、金額整數化、開立 / 重送 / 作廢 / 查詢、`.env` 測試憑證 |
| `references/integration.md` | TypeScript（NestJS）參考實作：同上演算法的 Node 版本 |
