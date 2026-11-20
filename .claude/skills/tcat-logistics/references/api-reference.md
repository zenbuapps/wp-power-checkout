# 印單 API 平台 — 完整 API Reference（v2.1.2）

主來源：《印單API平台_API規格書_契客 v2.1.2》（2026-03-25，81 頁）。全部 **12 個端點**逐一詳列。
v2.0.0 → v2.1.2 的逐項增量見 `version-notes.md` §2A。

## 0. 共通規格

### 0.1 連線

| 項目 | 值 |
|---|---|
| 正式環境-1 | `https://api.suda.com.tw/api/Egs/{服務名稱}` |
| 正式環境-2 | `https://api.suda.net.tw:9443/api/Egs/{服務名稱}`（**v2.1.2 新增**）|
| 測試環境 | `https://egs.suda.com.tw:8443/api/Egs/{服務名稱}` |
| Method | `POST`（全部 12 支）|
| 請求格式 | JSON（`Content-Type: application/json`）|
| 回應格式 | JSON（**例外**：`DownloadOBT` 成功時為 Binary）|
| 編碼 | UTF-8 |
| TLS | **必須 1.2 以上**（官方明列）|
| 連線逾時 | **≥ 120 秒**（`ParsingAddress`/`PrintOBT`/`PrintOBTByPickingList`/`PrintOBTByB2S` 官方明列「當服務繁忙時，連線逾時時間請設定最少 120 秒」）|

### 0.2 共通認證欄位（每支 API 的 body 最上層）

| 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|
| `CustomerId` | String (12) | Y | 契約客戶代號。**限填入 10 碼或 12 碼客戶代號** |
| `CustomerToken` | String (50) | Y | 契約客戶授權碼 |

**無簽章、無加密、無 hash、無 header token。** 認證資訊直接放 JSON body。

### 0.3 共通回應外殼

| 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|
| `SrvTranId` | String (20) | Y | 平台交易序號（部分章節寫「平台電文交易序號」，同一欄位）|
| `IsOK` | String (1) | Y | 是否執行成功。`Y`：成功／`N`：失敗 |
| `Message` | String (1000) | Y | 成功或失敗訊息。**v2.1.2 起附有 91 組錯誤代碼（E001–E091），見 §16** |
| `Data` | Object | N | 回傳資料。**若 `IsOK` 為 `N`，則 `Data` 為 `null`** |

> ⚠️ **HTTP 200 不代表業務成功**，必須判 `IsOK === 'Y'`。

### 0.4 呼叫頻率限制（v2.1.2 新增）

| 限制 | 適用 | 值 | 觸發時的錯誤碼 |
|---|---|---|---|
| 每日查詢總量 | `OBTStatus` | **3,000 次／契客／日** | `E086` 請求次數已達上限 XXX 次 |
| 併發查詢數 | `OBTStatus` | **同時最多 3 個**，超過直接拒絕 | `E084` 已有多次請求正在處理此客戶資料 |
| 同單號查詢冷卻 | `OBTStatus` | **同一託運單號每 2 小時 1 次** | `E087` 查詢貨態的時間太密集 |
| 一般呼叫密集度 | 全部端點 | 未公布閾值 | `E085` 呼叫 API 的時間太密集 |
| `FileNo` 有效期 | `DownloadOBT` | **24 小時**（自 `PrintOBT*` 成功起算）| `E070` 超過列印截止時間 |
| 叫車次數 | `Call` | **每個收貨點每日 1 次**，且無法指定收貨時間 | — |

> 官方原文（§2.11.1）：「為確保系統穩定運行並維持公平使用……建議使用緩存機制，減少重複查詢……建議使用請求佇列機制管理 API 呼叫。」
> **沒有快取層 + 佇列的輪詢實作會被系統擋掉。**

---

## 1. ParsingAddress — 查詢郵號

**服務名稱**：`ParsingAddress`　**用途**：地址字串 → 速達郵號（供 `SenderZipCode` 使用）

### 1.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PostType` | String (2) | N | **郵號格式（v2.1.2 新增）**。`01` 預設 `12-017-73-C`／`02` 有 `+` 號、BASE 和郵號、無理貨碼 `+1201773`／`03` 郵號和理貨碼 `01773C`／`04` 只有 BASE `12`／`05` 只有郵號、無理貨碼 `01773` |
| 4 | `Addresses` | Object [] | Y | 查詢地址資料。**限資料數量上限為 100 筆** |
| 5 | └ `Search` | String (100) | Y | 查詢地址 |

> **`PostType=03` 直接回 `01773C`（6 碼含理貨碼）= `SenderZipCode` 要的格式**，可省掉 §1.4 的手動截取。舊 code 沿用預設 `01` 時仍必須自己截。

### 1.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | 回傳資料。`IsOK=N` 時為 `null` |
| 5 | └ `Addresses` | Object [] | — | 查詢地址資料 |
| 6 | 　└ `Search` | String (100) | Y | 查詢地址（回傳原輸入值，供對位）|
| 7 | 　└ `PostNumber` | String (15) | Y | **郵號**。三種情境見下方 |

**`PostNumber` 三種情境（v2.1.2 新增說明，判「地址可否配送」的唯一依據）**：

| # | 條件 | 意義 | 處理 |
|---|---|---|---|
| 1 | `IsOK=Y` 且 `PostNumber` **非 `X`** | 此地址**可配送** | 正常建單 |
| 2 | `IsOK=Y` 且 `PostNumber` **為 `X`** | 此地址**不可配送**（如部分離島）| 擋單，提示改地址 |
| 3 | `IsOK=N`，`Data` 為 `null` | 取不到郵號。(1) 地址輸入錯誤、不存在（**佔多數**），修正後可再試；(2) 業務未維護配送郵號（少數），可回報速達修正 | 視為地址錯誤，請使用者重填 |

**多筆查詢時**：`IsOK` 一律為 `Y`，查無郵號的那筆 `PostNumber` 為**空字串**（不是 `X`、不是 `null`）。逐筆檢查，別只看外層 `IsOK`。

### 1.3 範例

```jsonc
// Request（多筆）
{
  "CustomerId": "契約客戶代號",
  "CustomerToken": "契約客戶授權碼",
  "Addresses": [
    { "Search": "查詢地址-第1 筆" },
    { "Search": "查詢地址-第2 筆" }
  ]
}
// Response
{
  "SrvTranId": "平台交易序號",
  "IsOK": "是否執行成功",
  "Message": "成功或失敗訊息",
  "Data": {
    "Addresses": [
      { "Search": "查詢地址-第1 筆", "PostNumber": "郵號" },
      { "Search": "查詢地址-第2 筆", "PostNumber": "郵號" }
    ]
  }
}
```

### 1.4 ★ 郵號截取規則（官方明列，最易踩雷）

> 「假設回傳的郵號為 `71-802-24-B`，則所需的六碼郵號為 `80224B`（**取後六碼**）。」

`SenderZipCode` 要的是**六碼郵號（含理貨碼）**，不是完整 `PostNumber`：

```typescript
const senderZip = postNumber.replace(/-/g, '').slice(-6);  // '71-802-24-B' → '7180224B' → '80224B'
```

實際樣張佐證（熱轉印單）：郵號印為 `12-114-06-S`，對應六碼 = `11406S`；同張條碼印 `*+1211406*`（Code39，`+` 前綴 + 7 位純數字）。

---

## 2. PrintOBT — 列印託運單（宅配）

**服務名稱**：`PrintOBT`　**用途**：一般宅配配號 + 產生託運單檔案，回 `FileNo` 供 `DownloadOBT`

### 2.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PrintType` | String (2) | Y | 列印類別。`01`：使用速達標準版型（由系統分配託運單號）|
| 4 | `PrintOBTType` | String (2) | Y | 列印託運單類別。`01`：A4 二模宅配／`02`：A4 三模宅配／`03`：熱轉印宅配 |
| 5 | `Orders` | Object [] | Y | 訂單資料。**限資料數量上限為 100 筆** |

**訂單資料（`Orders`）**

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 7 | `OBTNumber` | String (12) | Y | 託運單號。**若 `PrintType` 為 `01`，則填入空白** |
| 8 | `OrderId` | String (35) | Y | 契約客戶端之訂單編號。**不允許含有空白字元** |
| 9 | `Thermosphere` | String (4) | Y | 溫層。`0001` 常溫／`0002` 冷藏／`0003` 冷凍 |
| 10 | `Spec` | String (4) | Y | 規格。`0001` 60cm／`0002` 90cm／`0003` 120cm／`0004` 150cm |
| 11 | `ReceiptLocation` | String (2) | Y | 收付地點。`01` 到宅／`02` 到所 |
| 12 | `ReceiptStationNo` | String (20) | N | 收付處編號。(1) 若收付地點為 `02`，則填入收付站所編號，**收件人地址填入空白**；(2) 若收付地點非 `02`，則填入空白，**收件人地址必須填入正確資訊** |
| 13 | `RecipientName` | String (30) | Y | 收件人姓名（**黏貼聯遮蔽**）。限中文（不含生僻字）、英文、數字及部分常用符號（見 §13.9）|
| 14 | `RecipientTel` | String (30) | Y | 收件人電話（**黏貼聯遮蔽**）。(1) 允許空白；非空白時僅允許數字及「-」、「#」、「,」；(2) **收件人電話、手機擇一必填** |
| 15 | `RecipientMobile` | String (10) | Y | 收件人手機（**黏貼聯遮蔽**）。(1) 允許空白；非空白時僅允許 `09` 開頭的手機號碼格式；(2) 電話、手機擇一必填 |
| 16 | `RecipientAddress` | String (120) | Y | 收件人地址 |
| 17 | `SenderName` | String (30) | Y | 寄件人姓名。限中文（不含生僻字）、英文、數字及部分常用符號 |
| 18 | `SenderTel` | String (30) | Y | 寄件人電話。(1) 允許空白；非空白時僅允許數字及「-」、「#」、「,」；(2) 寄件人電話、手機擇一必填 |
| 19 | `SenderMobile` | String (10) | Y | 寄件人手機。(1) 允許空白；非空白時僅允許 `09` 開頭；(2) 電話、手機擇一必填 |
| 20 | `SenderZipCode` | String (6) | Y | 寄件人郵碼。**速達前往集貨地址的六碼郵號（含理貨碼）**。取得方式：(1) 依 `ParsingAddress` 傳入地址取得郵號；(2) 假設回傳郵號為 `71-802-24-B`，所需六碼郵號為 `80224B`（取後六碼）|
| 21 | `SenderAddress` | String (120) | Y | 寄件人地址（速達前往集貨地址）|
| 22 | `ShipmentDate` | String (8) | Y | 出貨日期。(1) 格式 `yyyyMMdd`；(2) **國定假日、周日不配送** |
| 23 | `DeliveryDate` | String (8) | Y | 希望配達日期。(1) 格式 `yyyyMMdd`；(2) 國定假日、周日不配送；**v2.1.2 新增** (3) 不可為過去日期；(4) **不可與出貨日同一天**（須 > `ShipmentDate`）；(5) 不可超過出貨日起 7 天 |
| 24 | `DeliveryTime` | String (2) | Y | 希望配達時間。`01`：13 時前／`02`：14-18 時／`04`：不指定 |
| 25 | `IsFreight` | String (1) | Y | 是否到付。`Y`／`N`。**若為 `Y`，則「是否代收貨款」不得為 `Y`，且代收金額欄位不得有 0 以上的數值** |
| 26 | `IsCollection` | String (1) | Y | 是否代收貨款。`Y`／`N`。**若為 `Y`，則「是否到付」不得為 `Y`** |
| 27 | `CollectionAmount` | **Int** | Y | 代收金額。(1) 整數；(2) 若代收貨款為 `Y`，填入代收金額（**0 元 < 代收金額 ≤ 10 萬元**）；(3) 若為 `N`，填 `0` |
| 28 | `IsSwipe` | String (1) | Y | 是否允許刷卡。`Y`／`N` |
| 29 | `IsMobilePay` | String (1) | **N** | 是否允許手機支付。`Y`／`N`　**★ v2.0.0 新增欄位** |
| 30 | `IsDeclare` | String (1) | Y | 是否報值。`Y`／`N`。(1) **代收金額 ≤ 2 萬，不提供報值服務**；(2) **代收金額 > 2 萬時，代收金額 = 報值金額** |
| 31 | `DeclareAmount` | **Int** | Y | 報值金額。(1) 整數；(2) 若報值為 `Y`，填入報值金額（**2 萬元 < 報值金額 ≤ 10 萬元**）；(3) 若為 `N`，填 `0` |
| 32 | `ProductTypeId` | String (4) | Y | 商品類別，`0001`～`0015`，見 §13.7 |
| 33 | `ProductName` | String (20) | Y | 商品名稱。**不允許含有 19 個禁用字串**，見 §13.8 |
| 34 | `Memo` | String (100) | N | 備註 |

### 2.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | 回傳資料。`IsOK=N` 時為 `null` |
| 6 | └ `PrintDateTime` | String (14) | Y | 列印日期時間，格式 `yyyyMMddHHmmss` |
| 7 | └ `Orders` | Object [] | Y | 訂單資料 |
| 9 | 　└ `OBTNumber` | String (12) | Y | **託運單號（系統配號結果）** |
| 10 | 　└ `OrderId` | String (35) | Y | 契約客戶端之訂單編號 |
| 11 | └ `FileNo` | String (100) | Y | **託運單下載檔案編號**（供 `DownloadOBT`）|

> ⚠️ **`FileNo` 的位置**：欄位表把 `FileNo` 列為項次 11（在 `Orders` 之後），而官方 JSON 範例中 `FileNo` 是 **`Data` 的直接子欄位**（與 `PrintDateTime`、`Orders` 同層），不在 `Orders` 陣列內。**以範例的結構為準**（三支 `PrintOBT*` 皆同）。

### 2.3 範例

```jsonc
// Request（單筆）
{
  "CustomerId": "契約客戶代號",
  "CustomerToken": "契約客戶授權碼",
  "PrintType": "列印類別(代碼)",
  "PrintOBTType": "列印託運單類別(代碼)",
  "Orders": [{
    "OBTNumber": "託運單號",
    "OrderId": "契約客戶端之訂單編號",
    "Thermosphere": "溫層(代碼)",
    "Spec": "規格(代碼)",
    "ReceiptLocation": "收付地點(代碼)",
    "ReceiptStationNo": "收付處編號",
    "RecipientName": "收件人姓名",
    "RecipientTel": "收件人電話",
    "RecipientMobile": "收件人手機",
    "RecipientAddress": "收件人地址",
    "SenderName": "寄件人姓名",
    "SenderTel": "寄件人電話",
    "SenderMobile": "寄件人手機",
    "SenderZipCode": "寄件人郵碼",
    "SenderAddress": "寄件人地址",
    "ShipmentDate": "出貨日期",
    "DeliveryDate": "希望配達日期",
    "DeliveryTime": "希望配達時間(代碼)",
    "IsFreight": "是否到付",
    "IsCollection": "是否代收貨款",
    "CollectionAmount": 0,
    "IsSwipe": "是否允許刷卡",
    "IsMobilePay": "是否允許手機支付",
    "IsDeclare": "是否報值",
    "DeclareAmount": 0,
    "ProductTypeId": "商品類別(代碼)",
    "ProductName": "商品名稱",
    "Memo": "備註"
  }]
}
// Response
{
  "SrvTranId": "平台交易序號",
  "IsOK": "是否執行成功",
  "Message": "成功或失敗訊息",
  "Data": {
    "PrintDateTime": "列印日期時間",
    "Orders": [
      { "OBTNumber": "託運單號", "OrderId": "契約客戶端之訂單編號" }
    ],
    "FileNo": "託運單下載檔案編號"
  }
}
```

---

## 3. PrintOBTByPickingList — 列印託運單（撿貨明細）

**服務名稱**：`PrintOBTByPickingList`　**用途**：託運單 + 商品撿貨明細表（自訂欄位 / 頁尾）

### 3.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PrintType` | String (2) | Y | 列印類別。`01`：使用速達標準版型（由系統分配託運單號）|
| 4 | `PrintOBTType` | String (2) | Y | **`01`：A4 三模撿貨單／`02`：A4 二模撿貨單**（★ 與 `PrintOBT` 的模數順序相反）|
| 5 | `Orders` | Object [] | Y | 訂單資料。**限資料數量上限為 100 筆** |

**訂單資料（`Orders`）** — 與 `PrintOBT` 幾乎相同，**差異已標示**：

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 7 | `OBTNumber` | String (12) | Y | 託運單號。若 `PrintType`=`01` 填空白 |
| — | *(無 `OrderId`)* | — | — | **★ 差異：本層沒有 `OrderId`**，訂單編號在 `Detail.Body[].OrderId` |
| 8 | `Thermosphere` | String (4) | Y | 溫層。`0001` 常溫／`0002` 冷藏／`0003` 冷凍 |
| 9 | `Spec` | String (4) | Y | 規格。`0001` 60cm／`0002` 90cm／`0003` 120cm／`0004` 150cm |
| 10 | `ReceiptLocation` | String (2) | Y | 收付地點。`01` 到宅／`02` 到所 |
| 11 | `ReceiptStationNo` | String (20) | N | 收付處編號（規則同 `PrintOBT`）|
| 12 | `RecipientName` | String (30) | Y | 收件人姓名（黏貼聯遮蔽）|
| 13 | `RecipientTel` | String (30) | Y | 收件人電話（黏貼聯遮蔽）；允許數字及「-」、「#」、「,」；電話 / 手機擇一必填 |
| 14 | `RecipientMobile` | String (10) | Y | 收件人手機（黏貼聯遮蔽）；`09` 開頭；電話 / 手機擇一必填 |
| 15 | `RecipientAddress` | String (120) | Y | 收件人地址 |
| 16 | `SenderName` | String (30) | Y | 寄件人姓名 |
| 17 | `SenderTel` | String (30) | Y | 寄件人電話；允許數字及「-」、「#」、「,」 |
| 18 | `SenderMobile` | String (10) | Y | 寄件人手機；`09` 開頭 |
| 19 | `SenderZipCode` | String (6) | Y | 寄件人郵碼（六碼郵號，取後六碼）|
| 20 | `SenderAddress` | String (120) | Y | 寄件人地址 |
| 21 | `ShipmentDate` | String (8) | Y | 出貨日期 `yyyyMMdd` |
| 22 | `DeliveryDate` | String (8) | Y | 希望配達日期 `yyyyMMdd`。**v2.1.2 起同 `PrintOBT`**：不可過去日期、不可與出貨日同天、不可超過出貨日 7 天 |
| 23 | `DeliveryTime` | String (2) | Y | 希望配達時間。`01`／`02`／`04` |
| 24 | `IsFreight` | String (1) | Y | 是否到付 |
| 25 | `IsCollection` | String (1) | Y | 是否代收貨款 |
| 26 | `CollectionAmount` | **Int** | Y | 代收金額（0 < x ≤ 10 萬）|
| 27 | `IsSwipe` | String (1) | Y | 是否允許刷卡 |
| 28 | `IsMobilePay` | String (1) | **N** | 是否允許手機支付　**★ v2.0.0 新增** |
| 29 | `IsDeclare` | String (1) | Y | 是否報值 |
| 30 | `DeclareAmount` | **Int** | Y | 報值金額（2 萬 < x ≤ 10 萬）|
| 31 | `ProductTypeId` | String (4) | **N** | 商品類別（★ 差異：此處**非必填**，`PrintOBT` 為必填）|
| 32 | `ProductName` | String (20) | **N** | 商品名稱（★ 差異：此處**非必填**）|
| 33 | `Memo` | String (100) | N | 備註 |
| 34 | `Detail` | Object | Y | **撿貨明細**（★ 本端點專屬）|

**撿貨明細（`Detail`）**

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 36 | `Title` | Object | Y | 標題 |
| 38 | └ `Column01` | String (5) | Y | 自訂欄位 1 標題。若無需自訂則填空白 |
| 39 | └ `Column02` | String (5) | Y | 自訂欄位 2 標題 |
| 40 | └ `Column03` | String (5) | Y | 自訂欄位 3 標題 |
| 41 | └ `Column04` | String (5) | Y | 自訂欄位 4 標題 |
| 42 | `Body` | Object [] | Y | 明細資料 |
| 44 | └ `OrderId` | String (35) | Y | 契約客戶端之訂單編號。**不允許含有空白字元** |
| 45 | └ `ProductTypeId` | String (4) | Y | 商品類別，`0001`～`0015` |
| 46 | └ `ProductName` | String (32) | Y | 商品名稱（同 19 個禁用字串限制）。**v2.1.2：長度 20 → 32，且「若輸入字串超過長度，系統將自動截斷超出部分，且不會回報錯誤」**（★ 靜默截斷，送出前自己驗長度）|
| 47 | └ `Quantity` | **Int** | Y | 數量。**資料範圍 1～99（2 位數）** |
| 48 | └ `Price` | **Int** | Y | 單價。**資料範圍 0～999999（6 位數）** |
| 49 | └ `Amount` | **Int** | Y | 合計 = 數量 × 單價。**資料範圍 0～99999999（8 位數）** |
| 50 | └ `Column01` | String (20) | Y | 自訂欄位 1 內容。若無需則填空白 |
| 51 | └ `Column02` | String (20) | Y | 自訂欄位 2 內容 |
| 52 | └ `Column03` | String (20) | Y | 自訂欄位 3 內容 |
| 53 | └ `Column04` | String (20) | Y | 自訂欄位 4 內容 |
| 54 | `TotalQuantity` | **Int** | N | 加總數量。**範圍 1～9999（4 位數）** |
| 55 | `TotalAmount` | **Int** | N | 加總合計。**範圍 0～9999999999（10 位數）** |
| 56 | `Footer` | Object | Y | 頁尾 |
| 58 | └ `Row01` | String (50) | Y | 第 1 行。若無需自訂頁尾則填空白 |
| 59 | └ `Row02` | String (50) | Y | 第 2 行 |
| 60 | └ `Row03` | String (50) | Y | 第 3 行 |
| 61 | └ `Row04` | String (50) | Y | 第 4 行 |

### 3.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | `IsOK=N` 時為 `null` |
| 6 | └ `PrintDateTime` | String (14) | Y | 列印日期時間 `yyyyMMddHHmmss` |
| 7 | └ `Orders` | Object [] | Y | 訂單資料 |
| 9 | 　└ `OBTNumber` | String (12) | Y | 託運單號 |
| 10 | 　└ `Picking` | Object | Y | 撿貨明細 |
| 12 | 　　└ `Details` | Object [] | Y | 明細資料 |
| 14 | 　　　└ `OrderId` | String (35) | Y | 契約客戶端之訂單編號 |
| 15 | └ `FileNo` | String (100) | Y | 託運單下載檔案編號 |

### 3.3 範例（節錄 `Detail` 結構）

```jsonc
{
  "OBTNumber": "託運單號",
  "Thermosphere": "0001", "Spec": "0001",
  "ReceiptLocation": "01", "ReceiptStationNo": "",
  "RecipientName": "…", "SenderName": "…",   // 其餘同 PrintOBT
  "Detail": {
    "Title":  { "Column01": "自訂欄位1 標題", "Column02": "…", "Column03": "…", "Column04": "…" },
    "Body": [{
      "OrderId": "契約客戶端之訂單編號",
      "ProductTypeId": "商品類別(代碼)", "ProductName": "商品名稱",
      "Quantity": 1, "Price": 100, "Amount": 100,
      "Column01": "自訂欄位1 內容", "Column02": "…", "Column03": "…", "Column04": "…"
    }],
    "TotalQuantity": 1, "TotalAmount": 100,
    "Footer": { "Row01": "第1 行文字", "Row02": "第2 行文字", "Row03": "第3 行文字", "Row04": "第4 行文字" }
  }
}
// Response Data
{ "PrintDateTime": "…",
  "Orders": [{ "OBTNumber": "託運單號", "Picking": { "Details": [{ "OrderId": "…-第1 筆" }, { "OrderId": "…-第2 筆" }] } }],
  "FileNo": "託運單下載檔案編號" }
```

> **一張託運單可掛多筆 `Body` 明細**（多個 `OrderId` 併單）。Response 的 `Picking.Details[]` 會列出該單號涵蓋的所有 `OrderId`。

---

## 4. PrintOBTByB2S — 列印託運單（7-11 快速到店）

**服務名稱**：`PrintOBTByB2S`　**用途**：倉到店（Business to Store）配號 + 產單，另回 `DeliveryId`

### 4.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PrintType` | String (2) | Y | 列印類別。`01`：使用速達標準版型（由系統分配託運單號）|
| 4 | `PrintOBTType` | String (2) | Y | **v2.1.2 擴為 4 值**：`01` A4 三模 B2S (QR Code 版面)／`02` 熱轉印 B2S (QR Code 版面)／`03` A4 三模 B2S_QR Code 版面／`04` 熱轉印 B2S_QR Code 版面 |
| 5 | `Orders` | Object [] | Y | 訂單資料。**限資料數量上限為 100 筆** |

**訂單資料（`Orders`）**

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 7 | `OBTNumber` | String (12) | Y | 託運單號。若 `PrintType`=`01` 填空白 |
| 8 | `OrderId` | String (35) | Y | 契約客戶端之訂單編號。**不允許含有空白字元或以下特殊符號：`/`、`_`、`@`、`\|`、`\`**（★ 比宅配更嚴）|
| 9 | `Thermosphere` | String (4) | Y | 溫層。`0001` 常溫／`0002` 冷藏／`0003` 冷凍 |
| 10 | `Spec` | String (4) | Y | 規格。**`0001` 60cm／`0002` 90cm／`0003` 120cm**（★ **無 150cm**）|
| 11 | `ReceiveStoreId` | String (50) | Y | **收件門市代碼**（由 7-11 電子地圖回傳的 `storeid` 取得，見 `cvs-map.md`）|
| 12 | `RecipientName` | String (30) | Y | 收件人姓名。**限填入中文、英文及數字（允許難字）**（★ 與宅配不同：允許難字、不收符號）|
| 13 | `RecipientTel` | String (30) | Y | 收件人電話。允許空白；非空白時僅允許數字及「-」、「#」、「,」 |
| 14 | `RecipientMobile` | String (10) | Y | 收件人手機。**限填入 `09` 開頭的手機號碼格式**（★ 快速到店手機為硬性必填格式）|
| 15 | `SenderName` | String (30) | Y | 寄件人姓名。限中文、英文及數字（允許難字）|
| 16 | `SenderTel` | String (30) | Y | 寄件人電話。允許空白；非空白時僅允許數字及「-」、「#」、「,」；電話 / 手機擇一必填 |
| 17 | `SenderMobile` | String (10) | Y | 寄件人手機。`09` 開頭；電話 / 手機擇一必填 |
| 18 | `SenderZipCode` | String (6) | Y | 寄件人郵碼（六碼郵號，取後六碼）|
| 19 | `SenderAddress` | String (120) | Y | 寄件人地址（速達前往集貨地址）|
| 20 | `IsCollection` | String (1) | Y | 是否代收貨款。`Y`／`N` |
| 21 | `CollectionAmount` | **Int** | Y | 代收金額。(1) 整數；(2) 若代收為 `Y`，**0 元 < 代收金額 ≤ 2 萬元**（★ 上限比宅配低）；(3) 若為 `N`，填 `0` |
| 22 | `FBName` | String (6) | N | **FB 名稱**。限填入中文、英文及數字 |
| 23 | `Memo` | String (100) | N | 備註 |

> **★ 快速到店沒有的欄位**（相對宅配）：`ReceiptLocation`、`ReceiptStationNo`、`RecipientAddress`（送到門市，不需收件人地址）、`ShipmentDate`、`DeliveryDate`、`DeliveryTime`、`IsFreight`、`IsSwipe`、`IsMobilePay`、`IsDeclare`、`DeclareAmount`、`ProductTypeId`、`ProductName`。

### 4.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | `IsOK=N` 時為 `null` |
| 6 | └ `PrintDateTime` | String (14) | Y | 列印日期時間 `yyyyMMddHHmmss` |
| 7 | └ `Orders` | Object [] | Y | 訂單資料 |
| 9 | 　└ `OBTNumber` | String (12) | Y | 託運單號 |
| 10 | 　└ `OrderId` | String (35) | Y | 契約客戶端之訂單編號 |
| 11 | 　└ `DeliveryId` | String (50) | Y | **門市配送編號**（★ B2S 專屬，門市驗收 / 取件用）|
| 12 | └ `FileNo` | String (100) | Y | 託運單下載檔案編號 |

---

## 5. DownloadOBT — 下載託運單

**服務名稱**：`DownloadOBT`　**用途**：以 `FileNo` 取回託運單 binary（PDF）；亦用於**補印**

> **本端點無「連線逾時 ≥120 秒」註記**（規格書僅對 §2.1–2.4 四支標註）。

### 5.0 ★ `FileNo` 24 小時下載期限（v2.1.2 新增，官方原文）

> 在使用「列印託運單(宅配) / (撿貨明細) / (快速到店)」API **成功建立資料並取得 `FileNo` 後，必須在 24 小時內透過本 API 完成檔案下載**。計時起點為前述 API 呼叫成功的時間點。若超過 24 小時的下載期限，**該檔案編號將會失效，無法再進行下載**。屆時您需要重新提交一筆新的託運單資料，以取得新的檔案編號。

實作要點：

- `FileNo` 落庫時一併記 `created_at`，超過 24h 標記 expired，不要再送 `DownloadOBT`。
- 逾期只能**重新建單**（會配到新的託運單號），不是重新下載——已印出的舊單號等同作廢，需要對帳處理。
- 逾期呼叫的回應為 `E070 超過列印截止時間`。

### 5.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `FileNo` | String (100) | Y | 託運單下載檔案編號（由 `PrintOBT*` 回傳）|
| 4 | `Orders` | Object [] | **N** | 訂單資料。**主要提供補印託運單使用，僅提供該筆託運單下載檔案編號內指定的託運單號** |
| 6 | └ `OBTNumber` | String (12) | Y | 託運單號 |

### 5.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | *(無欄位名)* | **Binary** | Y | **二進位檔案資料流** |

### 5.3 Response（HTTP 400）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗。**當 HTTP 狀態碼 = 400 時，`IsOK` = `N`** |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |

> **這是唯一用 HTTP 狀態碼區分成敗的端點**：200 → binary 串流（直接寫檔／回傳給前端）；400 → JSON 錯誤。client 必須先看 status code 再決定如何解析 body，不能無腦 `.json()`。

### 5.4 範例

```jsonc
// Request：整批下載
{ "CustomerId": "…", "CustomerToken": "…", "FileNo": "託運單下載檔案編號" }

// Request：補印指定單號（單筆 / 多筆）
{ "CustomerId": "…", "CustomerToken": "…", "FileNo": "託運單下載檔案編號",
  "Orders": [{ "OBTNumber": "託運單號-第1 筆" }, { "OBTNumber": "託運單號-第2 筆" }] }

// Response 200 → 二進位檔案資料流
// Response 400 → { "SrvTranId": "…", "IsOK": "…", "Message": "…" }
```

---

## 6. Call — 呼叫黑貓

**服務名稱**：`Call`　**用途**：線上叫車，請速達到指定地址收貨

> **★ v2.1.2 使用限制（官方原文）**：「此 API **每個收貨點每日僅能使用一次**，使用後 SD（司機）會依當日路線安排收貨，**無法預約收貨時間**。」
> 也就是說：同一收貨點當日第二次呼叫會被拒絕；UI 上不要提供「指定收貨時段」選項。相關錯誤碼見 §16 的 `E063`～`E066`、`E072`。

### 6.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `CustomerName` | String (30) | Y | 契約客戶名稱。**允許填入空白** |
| 4 | `ContactName` | String (30) | Y | 聯絡人姓名 |
| 5 | `ContactGender` | String (2) | **N** | 聯絡人性別。`01`：男性／`02`：女性 |
| 6 | `ContactTelArea` | String (4) | Y | 聯絡人電話-區碼。(1) 允許空白；非空白時僅允許 **1~4 碼長度的數字**（例：`02`）；(2) 聯絡人電話、手機擇一必填 |
| 7 | `ContactTelNumber` | String (8) | Y | 聯絡人電話-號碼。(1) 允許空白；非空白時僅允許 **1~8 碼長度的數字**（例：`123456`）；(2) 電話、手機擇一必填 |
| 8 | `ContactTelExt` | String (8) | Y | 聯絡人電話-分機。(1) 允許空白；非空白時僅允許 **1~8 碼長度的數字**（例：`001`）；(2) 電話、手機擇一必填 |
| 9 | `ContactMobile` | String (10) | Y | 聯絡人手機。(1) 允許空白；非空白時僅允許 `09` 開頭的手機號碼格式；(2) 電話、手機擇一必填 |
| 10 | `ContactAddress` | String (120) | Y | 聯絡人地址（收貨地址）|
| 11 | `NormalQuantity` | **Int** | Y | 常溫出貨件數 |
| 12 | `ColdQuantity` | **Int** | Y | 冷藏出貨件數 |
| 13 | `FreezeQuantity` | **Int** | Y | 冷凍出貨件數 |
| 14 | `IsContact` | String (1) | Y | 是否需要先電聯。`Y`／`N` |
| 15 | `IsTrolley` | String (1) | Y | 是否需要帶推車。`Y`／`N` |
| 16 | `Memo` | String (100) | N | 備註 |

> **電話拆三欄**（區碼 / 號碼 / 分機），不是單一字串。三欄都標「必要 Y」但都「允許填入空白」——實際規則是**電話（三欄）與手機擇一必填**。

### 6.2 Response

僅共通外殼三欄，**無 `Data`**：

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |

### 6.3 範例

```jsonc
{
  "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼",
  "CustomerName": "契約客戶名稱", "ContactName": "聯絡人姓名",
  "ContactGender": "01",
  "ContactTelArea": "02", "ContactTelNumber": "123456", "ContactTelExt": "001",
  "ContactMobile": "0912345678", "ContactAddress": "聯絡人地址",
  "NormalQuantity": 1, "ColdQuantity": 0, "FreezeQuantity": 0,
  "IsContact": "N", "IsTrolley": "N", "Memo": "備註"
}
```

---

## 7. ReserveAdd — 逆物流 · 新增

**服務名稱**：`ReserveAdd`　**用途**：退貨 / 逆物流取件預約（**單筆**）

### 7.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PrintType` | String (2) | Y | 列印類別。**`01`：速達協助列印（由系統分配託運單號）**（★ 文字與宅配的「使用速達標準版型」不同，值同為 `01`）|
| 4 | `Order` | **Object** | Y | 訂單資料（**單一物件，非陣列**）|

**訂單資料（`Order`）**

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 6 | `OBTNumber` | String (12) | Y | 託運單號。若 `PrintType`=`01` 填空白 |
| 7 | `OrderId` | String (35) | **N** | 契約客戶端之訂單編號。不允許含有空白字元 |
| 8 | `Thermosphere` | String (4) | Y | 溫層。`0001` 常溫／`0002` 冷藏／`0003` 冷凍 |
| 9 | `Spec` | String (4) | Y | 規格。`0001` 60cm／`0002` 90cm／`0003` 120cm／`0004` 150cm |
| 10 | `RecipientName` | String (30) | Y | 收件人姓名。限中文（不含生僻字）、英文、數字及部分常用符號 |
| 11 | `RecipientTel` | String (30) | Y | 收件人電話。允許空白；非空白時僅允許數字及「-」、「#」、「,」；電話 / 手機擇一必填 |
| 12 | `RecipientMobile` | String (10) | Y | 收件人手機。`09` 開頭；電話 / 手機擇一必填 |
| 13 | `RecipientAddress` | String (120) | Y | 收件人地址（**速達前往配送地址**）|
| 14 | `SenderName` | String (30) | Y | 寄件人姓名。限中文（不含生僻字）、英文、數字及部分常用符號 |
| 15 | `SenderTel` | String (30) | Y | 寄件人電話。允許數字及「-」、「#」、「,」；電話 / 手機擇一必填 |
| 16 | `SenderMobile` | String (10) | Y | 寄件人手機。`09` 開頭；電話 / 手機擇一必填 |
| 17 | `SenderAddress` | String (120) | Y | 寄件人地址（速達前往集貨地址）|
| 18 | `TakeDate` | String (8) | Y | **取件日期**，格式 `yyyyMMdd`。(1) 取件日期當天若為統一速達休假日，不提供服務；(2) **v2.1.2 新增：隔日取件需於統一速達前一個上班日 16:30 前完成呼叫**；(3) 限填入 D+1 ~ D+7 區間（D 日為 01/01 → 允許 01/02 ~ 01/08）|
| 19 | `DeliveryTime` | String (2) | Y | 希望配達時間。`01`：13 時前／`02`：14-18 時／`04`：不指定 |
| 20 | `IsFreight` | String (1) | Y | 是否到付。`Y`／`N` |
| 21 | `ProductName` | String (20) | N | 商品名稱。不允許含有 19 個禁用字串 |
| 22 | `Memo` | String (100) | N | 備註 |
| 23 | `RecipientZipCode` | String (6) | **N** | **收件人郵碼（v2.1.2 新增）**。速達前往**配送**地址的六碼郵號（含理貨碼），例 `01773C` |
| 24 | `SenderZipCode` | String (6) | **N** | **寄件人郵碼（v2.1.2 新增）**。速達前往**集貨**地址的六碼郵號（含理貨碼），例 `12334L` |

> **★ 逆物流沒有的欄位**：`ReceiptLocation` / `ReceiptStationNo`、`ShipmentDate` / `DeliveryDate`（改用 `TakeDate`）、代收 / 報值 / 刷卡 / 手機支付全組、`ProductTypeId`。
> **v2.1.2 起逆物流「有」郵碼欄位**（`RecipientZipCode` / `SenderZipCode`，選填，項次 23/24，排在 `Memo` 之後）——舊 skill 記載的「逆物流不需郵號」已失效。兩者皆非必填，不填仍可建單。

### 7.2 Response

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | `IsOK=N` 時為 `null` |
| 6 | └ `Order` | Object | Y | 訂單資料 |
| 8 | 　└ `OBTNumber` | String (12) | Y | **託運單號（配號結果）** |
| 9 | 　└ `OrderId` | String (35) | N | 契約客戶端之訂單編號 |

> **逆物流不回 `FileNo`**——`ReserveAdd` 只做預約與配號，**不產生可下載的託運單檔案**（由速達協助列印，A4 二模）。

### 7.3 範例

```jsonc
// Request
{
  "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼",
  "PrintType": "列印類別(代碼)",
  "Order": {
    "OBTNumber": "託運單號", "OrderId": "契約客戶端之訂單編號",
    "Thermosphere": "溫層(代碼)", "Spec": "規格(代碼)",
    "RecipientName": "收件人姓名", "RecipientTel": "收件人電話", "RecipientMobile": "收件人手機",
    "RecipientAddress": "收件人地址",
    "SenderName": "寄件人姓名", "SenderTel": "寄件人電話", "SenderMobile": "寄件人手機",
    "SenderAddress": "寄件人地址",
    "TakeDate": "取件日期", "DeliveryTime": "希望配達時間(代碼)",
    "IsFreight": "是否到付", "ProductName": "商品名稱", "Memo": "備註"
  }
}
// Response
{ "SrvTranId": "…", "IsOK": "…", "Message": "…",
  "Data": { "Order": { "OBTNumber": "託運單號", "OrderId": "契約客戶端之訂單編號" } } }
```

---

## 8. ReserveEdit — 逆物流 · 修改

**服務名稱**：`ReserveEdit`　**用途**：修改尚未送出集貨的逆物流預約

### 8.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `OBTNumber` | String (12) | Y | **託運單號（放在最上層，非 `Order` 內）** |
| 4 | `Order` | Object | Y | 訂單資料 |

**訂單資料（`Order`）** — 欄位與 `ReserveAdd` 相同，但**移除 `OBTNumber`**（已提到上層）：

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 6 | `OrderId` | String (35) | N | 契約客戶端之訂單編號 |
| 7 | `Thermosphere` | String (4) | Y | 溫層。`0001`／`0002`／`0003` |
| 8 | `Spec` | String (4) | Y | 規格。`0001`／`0002`／`0003`／`0004` |
| 9 | `RecipientName` | String (30) | Y | 收件人姓名 |
| 10 | `RecipientTel` | String (30) | Y | 收件人電話；允許數字及「-」、「#」、「,」；電話 / 手機擇一必填 |
| 11 | `RecipientMobile` | String (10) | Y | 收件人手機；`09` 開頭 |
| 12 | `RecipientAddress` | String (120) | Y | 收件人地址（**速達前往配送地址**）|
| 13 | `SenderName` | String (30) | Y | 寄件人姓名 |
| 14 | `SenderTel` | String (30) | Y | 寄件人電話；允許數字及「-」、「#」、「,」 |
| 15 | `SenderMobile` | String (10) | Y | 寄件人手機 |
| 16 | `SenderAddress` | String (120) | Y | 寄件人地址（速達前往集貨地址）|
| 17 | `TakeDate` | String (8) | Y | 取件日期 `yyyyMMdd`，限 D+1 ~ D+7；取件日當天遇速達休假日不提供服務；**v2.1.2：隔日取件需前一上班日 16:30 前完成呼叫** |
| 18 | `DeliveryTime` | String (2) | Y | 希望配達時間。`01`／`02`／`04` |
| 19 | `IsFreight` | String (1) | Y | 是否到付 |
| 20 | `ProductName` | String (20) | N | 商品名稱（19 個禁用字串）|
| 21 | `Memo` | String (100) | N | 備註 |
| 22 | `RecipientZipCode` | String (6) | **N** | **收件人郵碼（v2.1.2 新增）**，六碼含理貨碼，例 `01773C` |
| 23 | `SenderZipCode` | String (6) | **N** | **寄件人郵碼（v2.1.2 新增）**，六碼含理貨碼，例 `12334L` |

### 8.2 Response

僅共通外殼三欄（`SrvTranId` / `IsOK` / `Message`），**無 `Data`**。

### 8.3 範例

```jsonc
{
  "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼",
  "OBTNumber": "託運單號",
  "Order": {
    "OrderId": "契約客戶端之訂單編號", "Thermosphere": "溫層(代碼)", "Spec": "規格(代碼)",
    "RecipientName": "…", "RecipientTel": "…", "RecipientMobile": "…", "RecipientAddress": "…",
    "SenderName": "…", "SenderTel": "…", "SenderMobile": "…", "SenderAddress": "…",
    "TakeDate": "取件日期", "DeliveryTime": "希望配達時間(代碼)",
    "IsFreight": "是否到付", "ProductName": "商品名稱", "Memo": "備註"
  }
}
```

> **只有 `Status`=`01`（未送出集貨）可修改**，見 §10。

---

## 9. ReserveDelete — 逆物流 · 刪除

**服務名稱**：`ReserveDelete`

### 9.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `OBTNumber` | String (12) | Y | 託運單號 |

### 9.2 Response

僅共通外殼三欄（`SrvTranId` / `IsOK` / `Message`），**無 `Data`**。

### 9.3 範例

```jsonc
// Request
{ "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼", "OBTNumber": "託運單號" }
// Response
{ "SrvTranId": "平台交易序號", "IsOK": "是否執行成功", "Message": "成功或失敗訊息" }
```

> **只有 `Status`=`01`（未送出集貨）可刪除**。

---

## 10. ReserveQuery — 逆物流 · 單筆查詢

**服務名稱**：`ReserveQuery`

### 10.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `OBTNumber` | String (12) | Y | 託運單號 |

### 10.2 Response

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | `IsOK=N` 時為 `null` |
| 6 | └ `Order` | Object | Y | 訂單資料 |
| 8 | 　└ `OBTNumber` | String (12) | Y | 託運單號 |
| 9 | 　└ `OrderId` | String (35) | N | 契約客戶端之訂單編號 |
| 10 | 　└ `Thermosphere` | String (4) | Y | 溫層。`0001` 常溫／`0002` 冷藏／`0003` 冷凍 |
| 11 | 　└ `Spec` | String (4) | Y | 規格。`0001` 60cm／`0002` 90cm／`0003` 120cm／`0004` 150cm |
| 12 | 　└ `RecipientName` | String (30) | Y | 收件人姓名 |
| 13 | 　└ `RecipientTel` | String (30) | Y | 收件人電話 |
| 14 | 　└ `RecipientMobile` | String (10) | Y | 收件人手機（v2.1.2 起與 request 一致，不再是 20）|
| 15 | 　└ `RecipientAddress` | String (120) | Y | 收件人地址 |
| 16 | 　└ `SenderName` | String (30) | Y | 寄件人姓名 |
| 17 | 　└ `SenderTel` | String (30) | Y | 寄件人電話 |
| 18 | 　└ `SenderMobile` | String (10) | Y | 寄件人手機（v2.1.2 起與 request 一致）|
| 19 | 　└ `SenderAddress` | String (120) | Y | 寄件人地址 |
| 20 | 　└ `TakeDate` | String (8) | Y | 取件日期 `yyyyMMdd` |
| 21 | 　└ `DeliveryTime` | String (2) | Y | 希望配達時間。`01`／`02`／`04` |
| 22 | 　└ `IsFreight` | String (1) | Y | 是否到付。`Y`／`N` |
| 23 | 　└ `ProductName` | String (20) | N | 商品名稱 |
| 24 | 　└ `Memo` | String (100) | N | 備註 |
| 25 | 　└ `Status` | String (2) | Y | **狀態**。`01`：未送出集貨（**可修改及刪除**）／`99`：已送出集貨（**不可修改及刪除**）|
| 26 | 　└ `RecipientZipCode` | String (6) | N | **收件人郵碼（v2.1.2 新增）**，六碼含理貨碼 |
| 27 | 　└ `SenderZipCode` | String (6) | N | **寄件人郵碼（v2.1.2 新增）**，六碼含理貨碼 |

### 10.3 範例

```jsonc
// Request
{ "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼", "OBTNumber": "託運單號" }
// Response
{ "SrvTranId": "…", "IsOK": "…", "Message": "…",
  "Data": { "Order": {
    "OBTNumber": "…", "OrderId": "…", "Thermosphere": "…", "Spec": "…",
    "RecipientName": "…", "RecipientTel": "…", "RecipientMobile": "…", "RecipientAddress": "…",
    "SenderName": "…", "SenderTel": "…", "SenderMobile": "…", "SenderAddress": "…",
    "TakeDate": "…", "DeliveryTime": "…", "IsFreight": "…",
    "ProductName": "…", "Memo": "…", "Status": "狀態" } } }
```

> **建議流程**：`ReserveEdit` / `ReserveDelete` 之前先 `ReserveQuery` 確認 `Status === '01'`，避免對已集貨單操作而拿到自由文字錯誤。

---

## 11. OBTStatus — 查詢託運單貨態

**服務名稱**：`OBTStatus`　**用途**：即時查貨態（最新 + 完整歷程）。v2.1.2 起明載可查 **正物流 / 逆物流**兩種託運單。

### 11.0 ★ API 查詢限制（v2.1.2 §2.11.1 新增，硬性）

| 限制 | 值 | 超過時 |
|---|---|---|
| 每日查詢總量 | **每契約客戶每日最多 3,000 次** | 系統拒絕請求並回傳錯誤訊息（`E086`）|
| 活動中的 API 數 | **同時最多 3 個查詢執行** | **直接拒絕新請求**（`E084`）|
| 相同託運單號頻率 | **同一單號每 2 小時內僅能查詢一次** | 拒絕（`E087` 查詢貨態的時間太密集，請稍等 XXX 分鐘後再試）|

官方建議：**使用緩存機制減少重複查詢**、**使用請求佇列機制管理 API 呼叫**（避免非同步排程造成負載不均）。

實作底線（沒做就會被擋）：

1. 貨態結果**落庫快取 ≥ 2 小時**，同單號 2 小時內一律讀快取，不打 API。
2. 併發**限流到 3**（semaphore / queue），不要用 `Promise.all` 展開整批訂單。
3. 每日計數器 + 熔斷：接近 3,000 次時停止主動輪詢，只保留使用者觸發的查詢。
4. 善用 `OBTNumbers` **一次 10 筆**的批次額度——批次查 10 筆算 1 次呼叫，比逐筆查省 10 倍額度。
5. 大量對帳改走 **FTP `.sod` 批次回檔**（每日 05:00 / 18:00），不要用 `OBTStatus` 掃全量。

### 11.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `OBTNumbers` | **String []** | Y | 託運單號。**限資料數量上限為 10 筆**（★ 是**字串陣列**，不是物件陣列）|

### 11.2 Response

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台電文交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | `IsOK=N` 時為 `null` |
| 6 | └ `OBTs` | Object [] | Y | 託運單資料。**僅回傳已有貨態的託運單** |
| 8 | 　└ `OBTNumber` | String (12) | Y | 託運單號 |
| 9 | 　└ `OrderId` | String (35) | Y | 契約客戶端之訂單編號 |
| 10 | 　└ `StationName` | String (50) | Y | **最新**貨態的負責營業站所 |
| 11 | 　└ `CreateDateTime` | String (14) | Y | **最新**貨態的建立時間，格式 `yyyyMMddHHmmss` |
| 12 | 　└ `CustomerId` | String (12) | Y | 契約客戶代號 |
| 13 | 　└ `StatusId` | String (5) | Y | **最新**貨態的 Id |
| 14 | 　└ `StatusName` | String (100) | Y | **最新**貨態的說明 |
| 15 | 　└ `StatusList` | Object [] | Y | **貨態歷程資料。由新到舊排序** |
| 17 | 　　└ `StatusId` | String (5) | Y | 貨態 Id（**詳附錄的貨態代碼表**，見 `cargo-status.md`）|
| 18 | 　　└ `StatusName` | String (100) | Y | 貨態說明 |
| 19 | 　　└ `CreateDateTime` | String (14) | Y | 貨態建立時間 `yyyyMMddHHmmss` |
| 20 | 　　└ `StationName` | String (50) | Y | 負責營業站所 |

### 11.3 範例

```jsonc
// Request
{ "CustomerId": "契約客戶代號", "CustomerToken": "契約客戶授權碼",
  "OBTNumbers": ["第1 筆託運單號", "第2 筆託運單號"] }

// Response 情境1：有貨態
{ "SrvTranId": "平台交易序號", "IsOK": "執行成功", "Message": "成功訊息",
  "Data": { "OBTs": [{
      "OBTNumber": "第1 筆託運單號", "OrderId": "契約客戶端之訂單編號",
      "StationName": "負責營業站所", "CreateDateTime": "貨態建立時間",
      "CustomerId": "契約客戶代號", "StatusId": "貨態Id", "StatusName": "貨態說明",
      "StatusList": [
        { "StatusId": "…", "StatusName": "…", "CreateDateTime": "…", "StationName": "…" },
        { "StatusId": "…", "StatusName": "…", "CreateDateTime": "…", "StationName": "…" }
      ] }] } }

// Response 情境2：無貨態
{ "SrvTranId": "平台交易序號", "IsOK": "執行失敗", "Message": "失敗訊息", "Data": null }
```

> ⚠️ **「查無貨態」被當成失敗**（`IsOK`=`N`、`Data`=`null`），不是回空陣列。剛配號尚未集貨的單號查詢會走這條路——**不要把它當成系統錯誤而告警**。
> ⚠️ **`OBTs` 僅回傳已有貨態的託運單**：送 10 筆可能只回 3 筆，要自行對位補「無貨態」狀態。

---

## 12. ParsingMedicalLocation — 查詢醫藥郵號及據點

**服務名稱**：`ParsingMedicalLocation`　**用途**：確認地址對應的**醫藥專門**黑貓郵碼與據點名稱
**版本**：v2.0.9（2025-10-21）新增，v1.9.3 / v2.0.0 皆無此端點
**連線設定**：當服務繁忙時，連線逾時時間請設定最少 120 秒

> 使用說明（官方原文）：「確認地址對應的醫藥專門的黑貓郵碼，若地址有對應黑貓郵號表示此地址可以進行收貨與配送。」
> **這是獨立於 `ParsingAddress` 的醫藥專用通路**——一般宅配請用 `ParsingAddress`，醫藥客代才用本端點。非醫藥客代呼叫會得到 `E012 本服務尚未提供醫藥客代使用`。

### 12.1 Request

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `CustomerId` | String (12) | Y | 契約客戶代號，限 10 碼或 12 碼 |
| 2 | `CustomerToken` | String (50) | Y | 契約客戶授權碼 |
| 3 | `PostType` | String (2) | N | 郵號格式。**只有 3 種**：`01` 預設 `12-017-73-C`／`02` `+1201773`／`03` `01773C`（★ 比 `ParsingAddress` 少了 `04`、`05`）|
| 4 | `Addresses` | Object [] | Y | 查詢地址資料。**限資料數量上限為 100 筆** |
| 5 | └ `Search` | String (100) | Y | 查詢地址 |

### 12.2 Response（HTTP 200）

| 項次 | 參數名稱 | 型態 | 必要 | 說明 |
|---|---|---|---|---|
| 1 | `SrvTranId` | String (20) | Y | 平台交易序號 |
| 2 | `IsOK` | String (1) | Y | `Y` 成功／`N` 失敗 |
| 3 | `Message` | String (1000) | Y | 成功或失敗訊息 |
| 4 | `Data` | Object | N | 回傳資料。`IsOK=N` 時為 `null` |
| 5 | └ `Addresses` | Object [] | — | 查詢地址資料 |
| 6 | 　└ `Search` | String (100) | Y | 查詢地址（原輸入值）|
| 7 | 　└ `PostNumber` | String (15) | Y | 郵號。單筆查不到 → `IsOK=N` 且 `Data=null`；**多筆查不到 → `IsOK=Y` 但該筆為空字串** |
| 8 | 　└ `PostCodeName` | String (15) | Y | **據點名稱**（★ `ParsingAddress` 沒有這欄），例 `林口-4`、`三鶯 2`。查不到時同 `PostNumber` 規則 |

### 12.3 範例

```jsonc
// Request（多筆）
{
  "CustomerId": "1265635401",
  "CustomerToken": "abcd1234",
  "PostType": "01",
  "Addresses": [
    { "Search": "台北市重陽路200 號" },
    { "Search": "新北市新店區中興路三段1 號" }
  ]
}
// Response
{
  "SrvTranId": "TN20241202000002",
  "IsOK": "Y",
  "Message": "執行成功",
  "Data": {
    "Addresses": [
      { "Search": "台北市重陽路200 號",         "PostNumber": "15-123-34-L", "PostCodeName": "林口-4" },
      { "Search": "新北市新店區中興路三段1 號", "PostNumber": "33-039-12-F", "PostCodeName": "三鶯 2" }
    ]
  }
}
```

> `PostCodeName` 官方未說明用途，實務上是**負責該郵號的營業據點名稱**，可用於顯示或分流。與 `OBTStatus` 回傳的 `StationName`（最新貨態的負責營業站所）是否同一套命名，**官方未說明**。

---

## 13. 參數代碼表（全部逐條列出）

### 13.1 `PrintType`（列印類別）

| 代碼 | 說明 | 適用端點 |
|---|---|---|
| `01` | 使用速達標準版型（由系統分配託運單號）| `PrintOBT`、`PrintOBTByPickingList`、`PrintOBTByB2S` |
| `01` | 速達協助列印（由系統分配託運單號）| `ReserveAdd`（文字敘述不同，值相同）|

> 官方僅定義 `01` 一種值。選 `01` 時 `OBTNumber` 一律填空白。

### 13.2 `PrintOBTType`（列印託運單類別）— 三端點值域不同

| 端點 | `01` | `02` | `03` | `04` |
|---|---|---|---|---|
| `PrintOBT`（宅配）| A4 **二模**宅配 | A4 **三模**宅配 | 熱轉印宅配 | — |
| `PrintOBTByPickingList`（撿貨明細）| A4 **三模**撿貨單 | A4 **二模**撿貨單 | — | — |
| `PrintOBTByB2S`（快速到店）| A4 三模 B2S (QR Code 版面) | 熱轉印 B2S (QR Code 版面) | A4 三模 B2S_QR Code 版面 | 熱轉印 B2S_QR Code 版面 |

> **v2.1.2 把 B2S 從 2 值擴為 4 值**，且四個值的描述都帶「QR Code 版面」——`01`/`02` 與 `03`/`04` 的字面差異只有括號 vs 底線，**官方未說明兩組的實際版面差異**。已在生產使用 `01`/`02` 的整合維持原值即可（值未被移除）；要用 `03`/`04` 前應向速達確認差異，見 SKILL.md「待澄清」。
> `PrintOBT` 與 `PrintOBTByPickingList` 的 `01`/`02` **模數順序相反**，這是 v2.0.0 起就有的坑，v2.1.2 未修正。

### 13.3 `Thermosphere`（溫層）

| 代碼 | 說明 |
|---|---|
| `0001` | 常溫 |
| `0002` | 冷藏 |
| `0003` | 冷凍 |

### 13.4 `Spec`（規格）

| 代碼 | 說明 | `PrintOBT` | `PrintOBTByPickingList` | `PrintOBTByB2S` | `ReserveAdd`/`Edit` |
|---|---|---|---|---|---|
| `0001` | 60cm | ✅ | ✅ | ✅ | ✅ |
| `0002` | 90cm | ✅ | ✅ | ✅ | ✅ |
| `0003` | 120cm | ✅ | ✅ | ✅ | ✅ |
| `0004` | 150cm | ✅ | ✅ | ❌ **無** | ✅ |

### 13.5 `ReceiptLocation`（收付地點）

| 代碼 | 說明 | 配套規則 |
|---|---|---|
| `01` | 到宅 | `ReceiptStationNo` 填空白，`RecipientAddress` 必須正確 |
| `02` | 到所 | `ReceiptStationNo` 填收付站所編號，`RecipientAddress` 填空白 |

### 13.6 `DeliveryTime`（希望配達時間）

| 代碼 | 說明 |
|---|---|
| `01` | 13 時前 |
| `02` | 14-18 時 |
| `04` | 不指定 |

> **無 `03`**。官方值域只有 `01` / `02` / `04` 三個。

### 13.7 `ProductTypeId`（商品類別）

| 代碼 | 說明 |
|---|---|
| `0001` | 一般食品 |
| `0002` | 名特產/甜產 |
| `0003` | 酒/油/醋/醬 |
| `0004` | 穀物蔬果 |
| `0005` | 水產/肉品 |
| `0006` | 3C |
| `0007` | 家電 |
| `0008` | 服飾配件 |
| `0009` | 生活用品 |
| `0010` | 美容彩妝 |
| `0011` | 保健食品 |
| `0012` | 醫療相關用品 |
| `0013` | 寵物用品飼料 |
| `0014` | 印刷品 |
| `0015` | 其他 |

### 13.8 `ProductName` 禁用字串（19 個，全列）

商品名稱**不允許含有**以下特殊字串：

| # | 字串 | # | 字串 | # | 字串 | # | 字串 |
|---|---|---|---|---|---|---|---|
| 1 | 文件 | 6 | 資料 | 11 | 報名表 | 16 | 繳費單 |
| 2 | 標單 | 7 | 發票 | 12 | 申請書 | 17 | 通知單 |
| 3 | 明信片 | 8 | 訴訟文件 | 13 | 帳單 | 18 | 保險單 |
| 4 | 郵簡 | 9 | 公文 | 14 | 訂單 | 19 | 文書 |
| 5 | 信函 | 10 | 身分證明文件 | 15 | 報價單 | | |

> 是**子字串比對**（「不允許含有」），所以「訂單編號」「電子發票」「產品資料」都會被擋。適用於 `PrintOBT.ProductName`、`PrintOBTByPickingList` 的 `ProductName`（外層與 `Detail.Body[]`）、`ReserveAdd`/`ReserveEdit.ProductName`。

### 13.9 收寄件人姓名允許符號（附錄二，29 個，全列）

姓名欄位限填入**中文（不含生僻字）、英文、數字**，加上以下符號：

| # | 符號 | # | 符號 | # | 符號 | # | 符號 | # | 符號 |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 空格 | 7 | `$` | 13 | `)` | 19 | `]` | 25 | `"` |
| 2 | `` ` `` | 8 | `%` | 14 | `_` | 20 | `{` | 26 | `,` |
| 3 | `~` | 9 | `^` | 15 | `+` | 21 | `}` | 27 | `.` |
| 4 | `!` | 10 | `&` | 16 | `=` | 22 | `;` | 28 | `?` |
| 5 | `@` | 11 | `*` | 17 | `-` | 23 | `:` | 29 | `/` |
| 6 | `#` | 12 | `(` | 18 | `[` | 24 | `'` | | |

> 適用 `RecipientName` / `SenderName`（`PrintOBT`、`PrintOBTByPickingList`、`ReserveAdd`、`ReserveEdit`）。
> **例外**：`PrintOBTByB2S` 的姓名「限填入中文、英文及數字（**允許難字**）」——**允許生僻字但不吃符號**，與其他端點相反。

### 13.10 `ContactGender`（聯絡人性別，`Call` 專用）

| 代碼 | 說明 |
|---|---|
| `01` | 男性 |
| `02` | 女性 |

### 13.11 `Status`（逆物流狀態，`ReserveQuery` 回傳）

| 代碼 | 說明 | 可否修改 / 刪除 |
|---|---|---|
| `01` | 未送出集貨 | ✅ 可修改及刪除 |
| `99` | 已送出集貨 | ❌ 不可修改及刪除 |

### 13.12 `IsXxx` 布林欄位一覽

| 欄位 | 值 | 出現端點 |
|---|---|---|
| `IsOK` | `Y` 成功／`N` 失敗 | 全部（response）|
| `IsFreight` | `Y` 是／`N` 否（到付）| `PrintOBT`、`PrintOBTByPickingList`、`ReserveAdd`、`ReserveEdit`、`ReserveQuery` |
| `IsCollection` | `Y` 是／`N` 否（代收貨款）| `PrintOBT`、`PrintOBTByPickingList`、`PrintOBTByB2S` |
| `IsSwipe` | `Y` 是／`N` 否（允許刷卡）| `PrintOBT`、`PrintOBTByPickingList` |
| `IsMobilePay` | `Y` 是／`N` 否（允許手機支付）| `PrintOBT`、`PrintOBTByPickingList`（**v2.0.0 新增**）|
| `IsDeclare` | `Y` 是／`N` 否（報值）| `PrintOBT`、`PrintOBTByPickingList` |
| `IsContact` | `Y` 是／`N` 否（需先電聯）| `Call` |
| `IsTrolley` | `Y` 是／`N` 否（需帶推車）| `Call` |

---

## 14. 金額欄位商業規則彙整

| 規則 | 內容 |
|---|---|
| 到付 vs 代收互斥 | `IsFreight='Y'` → `IsCollection` 不得為 `Y`，且 `CollectionAmount` 不得有 0 以上的數值 |
| 代收上限（宅配 / 撿貨）| `IsCollection='Y'` → `0 < CollectionAmount ≤ 100000`；`IsCollection='N'` → `CollectionAmount = 0` |
| 代收上限（快速到店）| `IsCollection='Y'` → `0 < CollectionAmount ≤ 20000`；`IsCollection='N'` → `CollectionAmount = 0` |
| 報值門檻 | 代收金額 ≤ 20000 → **不提供報值服務**；代收金額 > 20000 → **代收金額 = 報值金額** |
| 報值區間 | `IsDeclare='Y'` → `20000 < DeclareAmount ≤ 100000`；`IsDeclare='N'` → `DeclareAmount = 0` |
| 撿貨明細數量 | `1 ≤ Quantity ≤ 99`（2 位數）|
| 撿貨明細單價 | `0 ≤ Price ≤ 999999`（6 位數）|
| 撿貨明細合計 | `0 ≤ Amount ≤ 99999999`（8 位數），`Amount = Quantity × Price` |
| 撿貨明細加總數量 | `1 ≤ TotalQuantity ≤ 9999`（4 位數）|
| 撿貨明細加總合計 | `0 ≤ TotalAmount ≤ 9999999999`（10 位數）|

**所有金額 / 數量欄位型態為 `Int`**（`CollectionAmount`、`DeclareAmount`、`Quantity`、`Price`、`Amount`、`TotalQuantity`、`TotalAmount`、`NormalQuantity`、`ColdQuantity`、`FreezeQuantity`）——JSON 序列化時不可加引號。**單位為「元」，無分位 / 無小數。**

---

## 15. 端點欄位差異速查（防混用）

| 欄位 | `PrintOBT` | `PrintOBTByPickingList` | `PrintOBTByB2S` | `ReserveAdd` |
|---|---|---|---|---|
| `Orders` 型態 | Object[] (≤100) | Object[] (≤100) | Object[] (≤100) | **`Order` Object（單筆）** |
| `OrderId` 位置 | `Orders[]` | **`Detail.Body[]`** | `Orders[]` | `Order` |
| `OrderId` 必填 | Y | Y（在 Body）| Y | **N** |
| `RecipientAddress` | Y | Y | **無** | Y |
| `ReceiptLocation` / `ReceiptStationNo` | ✅ | ✅ | **無** | **無** |
| `SenderZipCode` | Y（6）| Y（6）| Y（6）| **N 選填（6，v2.1.2 新增）** |
| `RecipientZipCode` | 無 | 無 | 無 | **N 選填（6，v2.1.2 新增）** |
| `ShipmentDate` / `DeliveryDate` | Y | Y | **無** | **無（用 `TakeDate`）** |
| `DeliveryTime` | Y | Y | **無** | Y |
| `IsFreight` | Y | Y | **無** | Y |
| `IsCollection` / `CollectionAmount` | Y（≤10萬）| Y（≤10萬）| Y（**≤2萬**）| **無** |
| `IsSwipe` / `IsMobilePay` | Y / N | Y / N | **無** | **無** |
| `IsDeclare` / `DeclareAmount` | Y | Y | **無** | **無** |
| `ProductTypeId` | **Y 必填** | **N 選填** | **無** | **無** |
| `ProductName` | **Y 必填** | **N 選填** | **無** | N 選填 |
| `Spec` 上限 | 150cm | 150cm | **120cm** | 150cm |
| `RecipientTel` 長度 | 30 | 30 | 30 | 30 |
| 回傳 `FileNo` | ✅ | ✅ | ✅ | **❌** |
| 回傳 `DeliveryId` | ❌ | ❌ | **✅** | ❌ |

> v2.1.2 起 `RecipientTel` / `SenderTel` 四支端點**統一為 30**（v2.0.0 時列印三支是 25、逆物流是 20），允許符號也統一為數字 + 「-」「#」「,」。舊 skill 記載的「逆物流 Tel 較短且不吃 #」已失效。

---

## 16. 錯誤代碼表（附錄三，v2.1.2 新增，91 組）

> **這是 v2.1.2 最重要的新增**：v1.9.3 / v2.0.0 都只有自由文字 `Message`，無法程式化分支。v2.1.2 起 `Message` 對應 `E001`–`E091`。
> ⚠️ 官方**未說明錯誤碼是否隨 `Message` 一起回傳、還是需自行對照文字**——規格書的 `Message` 欄位型態仍是 `String(1000)`，回應外殼**沒有獨立的錯誤碼欄位**。上線前務必實測回應內容，見 SKILL.md「待澄清」。

### 16.1 `E001` — 欄位格式驗證（單一碼涵蓋多條訊息）

`E001` 是所有欄位長度 / 格式驗證的集合碼，訊息文字才是真正的分支依據：

| 類別 | 訊息 |
|---|---|
| 認證 | `CustomerId 欄位長度須為10 或12。` |
| 代碼類 | `PostType`／`PrintType`／`PrintOBTType`／`CancelType` 各有「欄位資料不正確」+「欄位長度須為2」兩式；`OBTKind 欄位長度須為1。` |
| 單號 / 訂單 | `OrderId 欄位不允許以下符號「/」、「_」、「@」、「\|」、「\」。`／`OBTNumber 欄位資料不正確。` |
| 貨態屬性 | `Thermosphere`（長度須為4）／`Spec`（長度須為4）／`ReceiptLocation`（長度須為2）各有「資料不正確」式 |
| 聯絡資訊 | `RecipientTel 欄位不允許特殊符號。`／`RecipientMobile 欄位資料不正確。`／`SenderName 欄位不允許特殊符號。`／`SenderTel 欄位不允許特殊符號。`／`SenderMobile 欄位資料不正確。`／`SenderZipCode 欄位長度最少為6。` |
| 日期 | `ShipmentDate 欄位長度須為8。`／`DeliveryDate 欄位長度須為8。`／`DeliveryTime`（資料不正確 / 長度須為2）／`TakeDate 欄位長度須為8。` |
| 布林 | `IsFreight`／`IsCollection`／`IsSwipe`／`IsDeclare`／`IsContact`／`IsTrolley` 各有「資料不正確」+「長度須為1」；`IsMobilePay 欄位資料不正確。`（**無長度式**）|
| 商品 | `ProductTypeId 欄位長度須為4。`／`ProductName 欄位不能輸入「文件\|標單\|明信片\|郵簡\|信函\|資料\|發票\|訴訟文件\|公文\|身分證明文件\|報名表\|申請書\|帳單\|訂單\|報價單\|繳費單\|通知單\|保險單\|文書」。` |
| 撿貨明細 | `FBName 欄位不允許特殊符號。`／`TotalQuantity 欄位須為1~9999。`／`TotalAmount 欄位須為0~9999999999。`／`Quantity 欄位須為1~99。`／`Price 欄位須為0~999999。`／`Amount 欄位須為0~99999999。` |
| 叫車 | `ContactGender`／`ContactTelArea`／`ContactTelNumber`／`ContactTelExt`／`ContactMobile` 各「欄位資料不正確」|

### 16.2 `E002`–`E091` — 一碼一義

| 代碼 | 訊息 |
|---|---|
| `E002` | 地址資料不能空白。 |
| `E003` | 訂單資料不能空白。 |
| `E004` | 【Body 參數】明細資料不能空白。 |
| `E005` | 託運單號不能空白。 |
| `E006` | 託運單號不能重複。 |
| `E007` | 契客代號長度須為 10 碼或 12 碼。 |
| `E008` | 資料筆數大於 XXX 筆。 |
| `E009` | 契客資料不正確。 |
| `E010` | 目前暫時無法驗證契客資料，請重試。 |
| `E011` | 客代 XXX 合約失效(yyyy-MM-dd～yyyy-MM-dd)，請洽簽約站所。 |
| `E012` | 本服務尚未提供醫藥客代使用。 |
| `E013` | 您的 IP 位址不允許使用本服務。 |
| `E014` | 契客未簽倉到店服務。 |
| `E015` | 授權碼資料不正確。 |
| `E016` | 授權碼資料格式錯誤。 |
| `E017` | 託運單號(XXX)無效。 |
| `E018` | 託運單號(XXX)沒有記錄在取號區段內不能使用。 |
| `E019` | 訂單編號不允許含有空白字元。 |
| `E020` | 溫層為低溫時，包裹尺寸不能是 150cm。 |
| `E021` | 收付處編號不能空白。 |
| `E022` | 收付處編號資料不正確。 |
| `E023` | 收件人地址須為空白。 |
| `E024` | 收付處編號須為空白。 |
| `E025` | 收件人地址不能空白。 |
| `E026` | 收件人姓名不允許特殊符號及 ASCII 0~31 & 127。 |
| `E027` | 收件人電話、手機須擇一填寫。 |
| `E028` | 寄件人電話、手機須擇一填寫。 |
| `E029` | 此地區未提供服務。 |
| `E030` | 出貨日期格式不正確。 |
| `E031` | 希望配達日期格式不正確。 |
| `E032` | 希望配達日期為非上班日不配送。 |
| `E033` | 是否到付為 Y 時，則是否代收貨款不能為 Y。 |
| `E034` | 是否代收貨款不能為 Y。 |
| `E035` | 代收金額須為 1~100000 元。 |
| `E036` | 代收金額須為 1~20000 元。（快速到店）|
| `E037` | 代收金額須為 0。 |
| `E038` | 是否允許刷卡不能為 Y。 |
| `E039` | 是否允許手機支付不能為 Y。 |
| `E040` | 代收金額小於或等於 2 萬，不提供報值服務。 |
| `E041` | 代收金額須等於報值金額。 |
| `E042` | 報值金額須為 20001~100000 元。 |
| `E043` | 報值金額須為 0。 |
| `E044` | 商品類別資料不正確。 |
| `E045` | 收件地區已達配送限制，請選擇其他配達日再寄件！ |
| `E046` | 收件門市代碼資料不正確。 |
| `E047` | 收件門市不提供常溫服務。 |
| `E048` | 收件門市不提供冷藏服務。 |
| `E049` | 收件門市不提供冷凍服務。 |
| `E050` | 聯絡人電話區碼不能空白。 |
| `E051` | 聯絡人電話號碼不能空白。 |
| `E052` | 聯絡人電話、手機須擇一填寫。 |
| `E053` | 收件人地址資料不正確。 |
| `E054` | 收件人地址查到的郵號和收件人郵碼不相同。 |
| `E055` | 收件人地址_此地區未提供服務。 |
| `E056` | 寄件人地址資料不正確。 |
| `E057` | 寄件人地址查到的郵號和寄件人郵碼不相同。 |
| `E058` | 寄件人地址_此地區未提供服務。 |
| `E059` | 取件起始日期格式不正確。 |
| `E060` | 取件結束日期格式不正確。 |
| `E061` | 取件日期格式不正確。 |
| `E062` | 取件日期限填入 D+1～D+7 區間的日期，D 日為 yyyyMMdd。 |
| `E063` | 黑貓宅急便於休假日不營業，遇休假日則無法進行到府收貨呼叫黑貓的服務。 |
| `E064` | 因今日為黑貓宅急便休假日不營業，隔日無法進行到府收貨呼叫黑貓的服務。 |
| `E065` | 若取貨時間在隔日，申請預約集貨需在今日 **17:30** 之前。 |
| `E066` | 因隔日為黑貓宅急便休假日不營業，申請預約集貨需在今日 **17:30** 之前。 |
| `E067` | 系統發生錯誤。 |
| `E068` | 【XXX】查無郵號。 |
| `E069` | 檔案編號資料格式錯誤。 |
| `E070` | **超過列印截止時間**（`FileNo` 逾 24 小時失效）|
| `E071` | 查無託運單列印主檔資料。 |
| `E072` | 向 EZCat 後台取契客是否可派工資料失敗（**含 9 條子訊息**，見 §16.3）|
| `E073` | 查無預約集貨資料。 |
| `E074` | 已上傳 EOD，不允許修改資料。 |
| `E075` | 不允許修改「是否到付」欄位。 |
| `E076` | 已上傳 EOD，不允許刪除資料。 |
| `E077` | 查無貨態。 |
| `E078` | 向 SmartAPP 後台取託運單貨態資料失敗（**含 5 條子訊息**，見 §16.3）|
| `E079` | 託運單號已列印，不可以註銷。 |
| `E080` | 查無宅配託運單訂單資料，無法註銷。 |
| `E081` | 向 EZCat 後台取託運單號碼失敗（**含 7 條子訊息**，見 §16.3）|
| `E082` | 列印類別(代碼)資料不正確。 |
| `E083` | 託運單號重複。 |
| `E084` | **已有多次請求正在處理此客戶資料 (CustomerId: XXX)，請稍後再試。**（併發 > 3）|
| `E085` | **呼叫 API 的時間太密集，請稍等 XXX 分鐘後再試。** |
| `E086` | **請求次數已達上限 XXX 次。**（每日 3,000）|
| `E087` | **查詢貨態的時間太密集，請稍等 XXX 分鐘後再試。**（同單號 2 小時）|
| `E088` | 希望配達日期不可為過去日期。 |
| `E089` | 希望配達日期不可小於出貨日期。 |
| `E090` | 希望配達日期不可與出貨日期同一天。 |
| `E091` | 希望配達日期不可超過出貨日期 7 天。 |

### 16.3 三個「後台轉拋」錯誤碼的子訊息

`E072` / `E078` / `E081` 是**後端系統轉拋**，同一代碼對應多條不同訊息：

**`E072`（叫車 / 派工，EZCat 後台）**
- 契客資料錯誤
- 已過使用派工時間, 請電洽 412-8888。（服務時間為 **16:30 以前**；16:30 以後請隔日來電）
- 黑貓宅急便於週日不營業，遇週日則無法進行到府收貨呼叫黑貓的服務
- 發送集貨通知(派工)到 EZCat 後台失敗
- 取得授權碼失敗: 合約過期！
- 請確認契客代號及驗證碼是否正確，或洽詢您的專屬 SD
- 寄件地址查無黑貓對應郵號，請確認是否正確，或連繫 (02)2627-5861
- 寄件地址不在簽約可收貨範圍，請聯繫簽約站所
- 該地區無低溫配送服務！／集貨通知發送失敗！

**`E078`（貨態查詢，SmartAPP 後台）**
- 貨態查詢，失敗：契客代號驗證失敗
- 貨態查詢，失敗：**託運單號上限 500 筆**（★ 與規格書 request 的「上限 10 筆」不一致，後台實際限制為 500）
- 貨態查詢，失敗：託運單號不能為空值
- 貨態查詢，失敗：Token 驗證失敗
- 貨態查詢，失敗。原因：無貨態明細資訊！

**`E081`（配號，EZCat 後台）**
- 配 XX 單 失敗, 取得授權碼失敗: 合約過期！
- 配 XX 單 失敗, 請確認契客代號及驗證碼是否正確，或洽詢您的專屬 SD
- 配 XX 單 失敗, 契客 token 驗證失敗！
- 您無法自動取得單號！／您已經有足夠的託運單號。
- 配 XX 單 失敗！／託運單配號失敗！

### 16.4 建議的錯誤分類（給重試邏輯用）

| 類別 | 代碼 | 處理 |
|---|---|---|
| **可重試（暫時性）** | `E010`、`E067`、`E072`、`E078`、`E081`、`E084` | 指數退避重試 |
| **限流（等待後重試）** | `E085`、`E086`、`E087` | **依訊息中的分鐘數等待**，`E086` 當日不再重試 |
| **資料錯誤（不可重試）** | `E001`–`E006`、`E019`–`E062`、`E082`、`E083`、`E088`–`E091` | 回報使用者修正欄位 |
| **契約 / 權限（不可重試）** | `E007`、`E009`、`E011`–`E016` | 通知營運聯繫速達 |
| **狀態衝突（不可重試）** | `E070`、`E071`、`E073`–`E077`、`E079`、`E080` | 依業務流程處理（重新建單 / 放棄）|

> `E065` / `E066` 寫 **17:30**，但 `E072` 子訊息與 §2.7 `TakeDate` 說明寫 **16:30**。**官方文件內部不一致**，實作請以較早的 16:30 為安全值，並向速達確認。
