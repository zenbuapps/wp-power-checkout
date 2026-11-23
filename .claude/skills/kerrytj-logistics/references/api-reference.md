# EDIEntrance 端點完整欄位參考

> **何時讀這份**：要組 `ShipmentDelivery` / `UpdateShipmentDelivery` / `PickupV2` / `Report/*` 的 request body、解析回應、查欄位長度 / 合法值 / 必填，或判斷某端點「規格書有沒有收錄」時。
> 貨況查詢（`BLNListTracing` / `ColdBLNListTracing`）與貨態代碼表見 `tracking.md`；店到店 / 客戶專屬 / 內部端點見 `other-endpoints.md`。

## 目錄

- [0. 來源與可信度標記](#0-來源與可信度標記)
- [1. 共通傳輸規格](#1-共通傳輸規格)
- [2. 共用回應模型](#2-共用回應模型)
- [3. 託運資料欄位（ShipmentDelivery / UpdateShipmentDelivery）](#3-託運資料欄位)
- [4. POST ShipmentDelivery（上傳託運資料）](#4-post-shipmentdelivery上傳託運資料)
- [5. POST UpdateShipmentDelivery（修改託運資料）](#5-post-updateshipmentdelivery修改託運資料)
- [6. POST PickupV2（常溫取件）](#6-post-pickupv2常溫取件)
- [7. Report：GetLabelPdf / GetLabelPdfA4 / GetDetailPdf](#7-reportgetlabelpdf--getlabelpdfa4--getdetailpdf)
- [8. 規格書未收錄的端點（只有 Help Page schema）](#8-規格書未收錄的端點只有-help-page-schema)

---

## 0. 來源與可信度標記

| 標記 | 意義 |
|---|---|
| **【官方規格】** | 《客戶 API 串接規格書》v1.1.0（2025-03-19，嘉里大榮提供）。欄位中文名、型別長度、合法值、必填（橘色）、範例、回應碼。**最高可信度。** |
| **【官方 schema】** | `https://api.kerrytj.com/EDIEntrance/Help`（ASP.NET Web API 自動產生）。欄位名 / 型別 / 部分驗證，**Description 全空**。用於規格書沒收錄的端點。 |
| **【官方實測】** | 無憑證探測得到的 HTTP 行為（2026-10-13 正式機、2026-10-29 測試機）。 |
| **【第三方】** | GitHub `matrix-platform/shopping`（2023）、鼎新 A1 手冊等。 |
| **【推得】** | 由欄位名、範例、業務規則交叉推斷，未經證實。 |

規格書收錄 **9 支 API**：本檔 §4–§7 的 6 支 + `tracking.md` 的 2 支貨況 + 3 張貨態表。其餘 EDIEntrance 端點（`V2/Shipment`、`BLN`、`AreaTransfer`…）**規格書沒有**，只能靠 Help Page，見 §8。

---

## 1. 共通傳輸規格

| 項目 | 內容 | 可信度 |
|---|---|---|
| 正式 Base URL | `https://api.kerrytj.com/EDIEntrance/` | 官方規格 |
| **測試 Base URL** | **`https://api.kerrytj.com/EDIEntranceTest/`**（同主機、不同應用路徑；端點路徑完全相同） | 官方規格；2026-10-29 實測存在、36 支端點與正式機一致 |
| Method | `POST` | 官方規格 |
| 格式 | `Content-Type: application/json`，**UTF-8** | 官方規格 |
| Body 形狀 | **JSON 陣列**（一筆也要包 `[ ]`）。**例外**：`Report/GetLabelPdf` 是**單一物件** | 官方規格 |
| 欄位型別 | 全部 `STRING(n)`（含件數、金額、重量）。`Report/*` 範例中 `Piece` / `AC` / `Weight` / `Volume` / `DeclaredValue` 寫成數字，伺服器兩者皆接受（ASP.NET 自動轉型，推得）；**一律送字串最安全** | 官方規格 |
| 認證 | **HTTP Basic Auth**：`Authorization: Basic base64(帳號:密碼)`。`ShipmentDelivery` / `UpdateShipmentDelivery` / `PickupV2` 規格書明列「驗證：Basic Auth」 | 官方規格 |
| 認證失敗 | `401 Unauthorized` + `WWW-Authenticate: Basic realm="api.kerrytj.com"` + `{"Message":"The requested authorization has been rejected."}`（規格書：帳密錯誤或 Header 無驗證屬性） | 官方規格 + 官方實測 |
| `Report/*` 認證 | 規格書三支 Report **沒有「驗證」列**；實測無憑證送 `[]` 回 200 PDF（正式 / 測試機皆同）。**仍建議帶 Basic Auth**（多帶不會錯，且日後可能收緊） | 官方規格 + 官方實測 |
| 其他 HTTP 錯誤 | GET 打 POST 端點 → `405`；路徑錯 → `404`（IIS HTML） | 官方實測 |

### 1.1 測試環境要點

- 規格書第 2 頁「API 帳號詳細資訊」把**正式 / 測試**分兩欄，各有：客戶編號、帳號、密碼、常溫區間起 / 迄 → **測試環境有獨立的帳密、客編與號段**，由嘉里大榮填表提供（拿到的規格書這張表是空白，要另外索取）。
- 測試主機只涵蓋 **EDIEntrance**（建單 / 修改 / 取件 / 標籤 / 明細表）。**貨況查詢 `KerryTracingEDI` 規格書只給一組網址**，沒有測試網址。
- 測試機資料是否與正式機隔離、測試單是否會派車：規格書未說明 → 第一次聯調前向業務確認。

---

## 2. 共用回應模型

### 2.1 寫入類回應（ShipmentDelivery / UpdateShipmentDelivery / PickupV2）

| No | 欄位 | 中文 | 型別 | 說明 | 可信度 |
|---|---|---|---|---|---|
| 1 | `Result` | 狀態 | string | **`01` = 全部成功、`02` = 部分成功、`03` = 錯誤** | 官方規格 |
| 2 | `Count` | 筆數 | int | 範例：2 筆全成功 → `2`；部分成功 → `1`；錯誤 → `0`。推得為**成功筆數** | 型別：官方；語意：推得（由範例） |
| 3 | `Data` | — | array | 失敗明細：`[{ "BLN": "託運單號", "Error": "異常" }]`；全部成功時為 `[]` | 官方規格 |
| 4 | `AreaData` | — | array | **只有 `ShipmentDelivery` 有**：成功託運單的到著站 / 配區資訊（§4.2） | 官方規格 |

**處理原則**：

- `Result === '01'`：全部成功。
- `Result === '02'`：**逐筆比對 `Data[].BLN`**——在 `Data` 裡的那幾筆失敗、其餘成功。不要整批重送（會讓已成功的單重複上傳）。
- `Result === '03'`：整批失敗（`Count: 0`、`Data: []`，**不會告訴你原因**）→ 先檢查必填 / 長度 / 認證。
- 規格書範例 `Error` 文字：`"託運單號不得為空"`、`"客戶編號輸入錯誤"`。**完整錯誤訊息表未提供**——原樣落庫。

### 2.2 Report 類錯誤回應（GetLabelPdf / GetLabelPdfA4 / GetDetailPdf）

成功：回 **PDF 檔案串流**（`Content-Type: application/pdf`、`Content-Disposition: attachment; filename=report.pdf`）。

失敗：JSON（ASP.NET ModelState 格式）：

```json
{
  "Message": "要求無效。",
  "ModelState": {
    "request[0].ConsigneePost": ["收貨人郵區必填"],
    "request[0].ShipperTel":    ["寄貨人電話必填"]
  }
}
```

- `GetLabelPdf`（單一物件）的鍵是 `request.BLN`；陣列端點是 `request[索引].欄位`。
- 判斷方式：看 `Content-Type`——`application/pdf` = 成功，其他 = 錯誤，解析 JSON 取 `ModelState`。
- 規格書範例訊息：`託運單號必填`、`出貨單號必填`、`收貨人郵區必填`、`寄貨人電話必填`（文字略有出入，以實際回應為準）。

> ⚠️ 規格書 Report 錯誤範例出現 `"request.CON": ["出貨單號必填"]`，但 `CON` 在欄位表**不是橘色必填**。可能只是範例示意，也可能 Report 會檢查 `CON`——**測試環境第一次印標籤時留空 `CON` 驗證一次**。

---

## 3. 託運資料欄位

`ShipmentDelivery` 與 `UpdateShipmentDelivery` 共用 34 欄。**必填 = 規格書橘色列**（與 Help Page 的 `Required` 完全一致）。

| No | 欄位 | 中文 | 型別 | 必填 | 說明 / 合法值（官方規格） |
|---|---|---|---|---|---|
| 1 | `ShipDate` | 出貨日期 | STRING(8) | ✅ | `yyyyMMdd` |
| 2 | `CustomerNo` | 客戶編號 | STRING(8) | ✅ | 向嘉里大榮申請 |
| 3 | `Consignee` | 收貨人 | STRING(**6**) | ✅ | ⚠️ 只有 6 字元，見 §3.1 |
| 4 | `ConsigneePost` | 收貨人郵區 | STRING(**3**) | ✅ | 3 碼郵遞區號 |
| 5 | `ConsigneeAdd` | 收貨人地址 | STRING(60) | ✅ | |
| 6 | `ConsigneePhone` | 收貨人電話 | STRING(15) | ✅ | |
| 7 | `BLN` | 託運單號 | STRING(11) | ✅ | 嘉里大榮提供號段，**客戶自行管理配號**；常溫 11 碼 / 低溫 10 碼（Help Page：Min 10 / Max 11） |
| 8 | `Weight` | 重量 | STRING(4) | | 預設空值，0–9999 |
| 9 | `Volume` | 才積 | STRING(4) | | 預設空值，0–9999 |
| 10 | `Shipper` | 寄貨人 | STRING(**6**) | ✅ | ⚠️ 只有 6 字元 |
| 11 | `ShipperPost` | 寄貨人郵寄區號 | STRING(3) | ✅ | |
| 12 | `ShipperAdd` | 寄貨人地址 | STRING(60) | ✅ | |
| 13 | `ShipperTel` | 寄貨人電話 | STRING(15) | ✅ | |
| 14 | `CON` | **出貨單號** | STRING(12) | | 客戶自己的訂單 / 出貨單號。**低溫出貨單號僅到 10 字元** |
| 15 | `AC` | **代收貨款** | STRING(6) | | 預設空值，**≤ 50000** |
| 16 | `PickupTime` | 預定取件時段 | STRING(1) | | `1` 上午 / `2` 下午 / `3` 晚上 / `4` 不指定 |
| 17 | `ETA` | 配送時段 | STRING(1) | | `1` 上午(08–12) / `2` 下午(14–19) / `4` 不指定（預設）。**沒有 `3`** |
| 18 | `Remark` | 備註 | STRING(60) | | |
| 19 | `Temperature` | 溫層 | STRING(1) | ✅ | `1` 常溫 / `2` 冷藏 / `3` 冷凍 |
| 20 | `Piece` | 件數 | STRING(3) | ✅ | **不可為 0，不可超過 999** |
| 21 | `DeclaredValue` | 報值金額 | STRING(6) | | 預設空值，**≤ 50000** |
| 22 | `SpecialService` | 特服 | STRING(1) | | 預設空值，`1` 特服 |
| 23 | `StationEnable` | 站止 | STRING(1) | | 預設空值，`1` 到站（收件人到營業所自領） |
| 24 | `Receipt` | 回單 | STRING(1) | | 預設空值，`0`–`9`（推得：回單張數） |
| 25 | `HolidayId` | 假日配送 | STRING(1) | | 預設空值，`6` 週六（`UpdateShipmentDelivery` / `GetDetailPdf` 另列 `7` 週日） |
| 26 | `MemberId` | 會員編號 | STRING(30) | | |
| 27 | `BoxNumber` | 包裝箱號 | STRING(30) | | |
| 28 | `IsValuable` | 貴重物 | STRING(1) | | 預設空值，`1` 貴重品 |
| 29 | `CityType` | 縣市類別 | STRING(2) | | `00` 同縣市 / `01` 外縣市 / `02` 離島 |
| 30 | `Specification` | 規格 | STRING(4) | | `0001` 60CM / `0002` 90CM / `0003` 120CM / `0004` 150CM |
| 31 | `BoxSize` | 箱號大小 | STRING(3) | | 長+寬+高，單位 cm |
| 32 | `DailyDelyId` | 當日配 | STRING(1) | | `Y` 當日配 / `N` 無當日配 |
| 33 | `PaymentType` | 付款類別 | STRING(1) | | 預設空值，`0` 到付 / `1` 現收（空值推得 = 月結 / 依合約） |
| 34 | `ETP` | **指送日期** | STRING(8) | | `yyyyMMdd`（指定配達日） |

### 3.1 欄位陷阱

| 陷阱 | 說明 |
|---|---|
| **姓名只有 6 字元** | `Consignee` / `Shipper` 都是 STRING(6)。公司名、長姓名、外文名會超長——**送出前截斷**（建議截到 6 個字元並把全名放 `Remark`）。超長時伺服器回什麼錯誤未知。 |
| **郵遞區號只收 3 碼** | `ConsigneePost` / `ShipperPost` STRING(3)：5 碼 / 6 碼郵遞區號要先取前 3 碼。 |
| **選填欄位送空字串** | 官方範例把所有選填欄位都送 `""`。不送該鍵也可（Help Page 未標 Required），但**照範例送 `""` 最貼近官方行為**。 |
| **`ETA` vs `ETP`** | `ETA` = 時段代碼（1/2/4），`ETP` = 指定日期（yyyyMMdd）。別搞反。 |
| **`AC` / `DeclaredValue` 上限 50000** | 與官網業務規則一致（代收 / 報值每筆上限 NT$5 萬）。超過要拆單或改其他方式。 |
| **溫層與單號長度** | 常溫 `BLN` 11 碼；冷藏 / 冷凍 `BLN` 10 碼（低溫 `CON` 也只能 10 字元）。 |
| **一號多件** | 規格書 `Piece` 1–999，**一張託運單可多件**。鼎新 A1 手冊「大榮 API 不接受件數，多件要複製多筆」與官方規格衝突，以規格書為準。 |

---

## 4. POST ShipmentDelivery（上傳託運資料）

| 項目 | 內容 |
|---|---|
| 正式 URL | `https://api.kerrytj.com/EDIEntrance/ShipmentDelivery` |
| 測試 URL | `https://api.kerrytj.com/EDIEntranceTest/ShipmentDelivery` |
| 驗證 | Basic Auth |
| Body | §3 欄位物件的**陣列** |
| Response | §2.1 + `AreaData` |

### 4.1 官方 Request 範例（v1.1.0 原文）

```json
[
  {
    "ShipDate": "20200424",
    "CustomerNo": "11111111",
    "Consignee": "Test",
    "ConsigneePost": "100",
    "ConsigneeAdd": "台北市中正區新生南路一段XX 號",
    "ConsigneePhone": "0912345678",
    "BLN": "12345678901",
    "Weight": "",
    "Volume": "",
    "Shipper": "TEST01",
    "ShipperPost": "702",
    "ShipperAdd": "台南市南區南路一段XX 號",
    "ShipperTel": "0987654321",
    "CON": "",
    "AC": "",
    "PickupTime": "",
    "ETA": "",
    "Remark": "",
    "Temperature": "1",
    "Piece": "1",
    "DeclaredValue": "",
    "SpecialService": "",
    "StationEnable": "",
    "Receipt": "",
    "HolidayId": "",
    "MemberId": "",
    "BoxNumber": "",
    "IsValuable": "",
    "CityType": "",
    "Specification": "",
    "BoxSize": "",
    "DailyDelyId": "",
    "PaymentType": "",
    "ETP": ""
  }
]
```

### 4.2 AreaData（到著站 / 配區資訊）

| No | 欄位 | 中文 | 型別 |
|---|---|---|---|
| 1 | `Id` | 編號（範例值 `62290000002`，形似託運單號；推得 = 對應的 `BLN`） | string |
| 2 | `Address` | 地址（範例為正規化後的完整地址） | string |
| 3 | `StationCode` | 站所代碼 | string |
| 4 | `StationShotCode` | 站所簡碼（拼字為 Shot） | string |
| 5 | `StationName` | 站所名稱 | string |
| 6 | `ZipCode` | 郵遞區號 | string |
| 7 | `DestDelyArea` | 到著配區 | string |
| 8 | `HomeDelyArea` | 宅配配區 | string |
| 9 | `BikeDelyArea` | 機車配區 | string |
| 10 | `DelyFeeArea` | 區外送費碼 | string |
| 11 | `SpecialServiceFeeAreaOne` | 特服 1 | string |
| 12 | `SpecialServiceFeeAreaTwo` | 特服 2 | string |

### 4.3 官方 Response 範例

```json
// 1. 全部成功
{
  "Result": "01", "Count": 2, "Data": [],
  "AreaData": [{
    "Id": "62290000002", "Address": "臺北市中正區新生南路一段160號",
    "StationCode": "6137", "StationShotCode": "10", "StationName": "重新", "ZipCode": "100",
    "DestDelyArea": "252", "HomeDelyArea": "820", "BikeDelyArea": "820",
    "DelyFeeArea": "", "SpecialServiceFeeAreaOne": "", "SpecialServiceFeeAreaTwo": ""
  }]
}
// 2. 部分成功
{ "Result": "02", "Count": 1, "Data": [{ "BLN": "12345678902", "Error": "託運單號不得為空" }], "AreaData": [] }
// 3. 錯誤
{ "Result": "03", "Count": 0, "Data": [], "AreaData": [] }
```

- **嘉里大榮不回傳新單號**：成功後以你帶入的 `BLN` 作為託運單號存檔（官方規格「客戶自行管理」）。
- 建議把 `AreaData` 整包存起來（`StationName` / `StationShotCode` 可用於揀貨分區或自繪標籤）。

---

## 5. POST UpdateShipmentDelivery（修改託運資料）

| 項目 | 內容 |
|---|---|
| 正式 URL | `https://api.kerrytj.com/EDIEntrance/UpdateShipmentDelivery` |
| 測試 URL | `https://api.kerrytj.com/EDIEntranceTest/UpdateShipmentDelivery` |
| 驗證 | Basic Auth |
| Body | 與 §3 完全相同的 34 欄、相同必填（以 `BLN` 為鍵，推得）。**送完整資料，不是只送異動欄位** |
| Response | §2.1（**沒有** `AreaData`） |
| **限制** | **若已將貨交給站所則無法修改**（官方規格標題註記） |

```json
// 官方 Response 範例
{ "Result": "01", "Count": 2, "Data": [] }
{ "Result": "02", "Count": 1, "Data": [{ "BLN": "12345678901", "Error": "客戶編號輸入錯誤" }] }
{ "Result": "03", "Count": 0, "Data": [] }
```

> **沒有取消 / 刪除託運單的 API**（規格書與 Help Page 都沒有通用的 Cancel 端點）。未交寄的單要作廢：不交貨即可（號段會被消耗），或洽營業所。貨態表中的 `04 訂正取消` / `68 更正後訂正取消` 是嘉里大榮內部作業產生的狀態。

---

## 6. POST PickupV2（常溫取件）

| 項目 | 內容 |
|---|---|
| 正式 URL | `https://api.kerrytj.com/EDIEntrance/PickupV2` |
| 測試 URL | `https://api.kerrytj.com/EDIEntranceTest/PickupV2` |
| 驗證 | Basic Auth |
| 適用 | **常溫**（規格書標題「常溫取件 API」；沒有低溫取件 API） |
| Body | 下表物件的陣列 |
| Response | §2.1（沒有 `AreaData`） |
| **派遣規則** | **取件日期以進系統日期為主，超過中午 12 點改為 D+1 天派遣** |

| No | 欄位 | 中文 | 型別 | 必填 | 說明 |
|---|---|---|---|---|---|
| 1 | `BLN` | 託運單號 | STRING(11) | ✅ | 嘉里大榮提供的託運單號（從號段取） |
| 2 | `PickupNo` | 取件編號 | STRING(30) | | 客戶自己的取件 / 退貨單號（推得） |
| 3 | `CustomerNo` | 客戶編號 | STRING(8) | ✅ | |
| 4 | `Consignee` | 收貨人 | STRING(6) | ✅ | 貨要送回的一方 |
| 5 | `ConsigneeTel1` | 收貨人電話 1 | STRING(20) | ✅ | |
| 6 | `ConsigneeTel2` | 收貨人電話 2 | STRING(20) | | |
| 7 | `ConsigneePost` | 收貨人郵區 | STRING(3) | ✅ | |
| 8 | `ConsigneeAdd` | 收貨人地址 | STRING(100) | ✅ | |
| 9 | `Shipper` | **取件人** | STRING(20) | ✅ | **去哪裡取件** |
| 10 | `ShipperTel1` | 取件人電話 1 | STRING(20) | ✅ | |
| 11 | `ShipperTel2` | 取件人電話 2 | STRING(20) | | |
| 12 | `ShipperPost` | 取件人郵區 | STRING(3) | ✅ | |
| 13 | `ShipperAdd` | 取件人地址 | STRING(120) | ✅ | |
| 14 | `Volume` | 才數 | STRING(7) | | 整數 |
| 15 | `Weight` | 重量 | STRING(7) | | 整數 |
| 16 | `Remark` | 備註 | STRING(100) | | （規格書誤寫「整數」） |
| 17 | `Piece` | 件數 | STRING(7) | ✅ | 整數 |

**方向（依官方欄位中文名）**：電商退貨時 → **`Shipper*` = 消費者（取件人 / 取件地址）**、**`Consignee*` = 商家（收貨人 / 退貨收件地址）**。
⚠️ 第三方 `matrix-platform` 實作把消費者放 `Consignee`、商家放 `Shipper`，**與官方欄位名相反**——不要照抄。官方範例兩方同地址無法佐證，第一次在測試環境呼叫時順便確認派車地址。

> 欄位差異：`PickupV2` 的電話是 `ConsigneeTel1` / `ShipperTel1`（不是 `ConsigneePhone` / `ShipperTel`），姓名 `Shipper` 放寬到 20 字元、地址放寬到 100 / 120。Help Page 的 `PickupEntity` 另有 `PickupDate`、`Temperature`、`CodFlag`… 等欄位，**規格書沒列，不要送**。

```json
// 官方 Request 範例
[
  {
    "BLN": "40350718602",
    "PickupNo": "",
    "CustomerNo": "17564725",
    "Consignee": "王小明",
    "ConsigneeTel1": "0912345678",
    "ConsigneeAdd": "台北市中正區新生南路一段50 號",
    "ConsigneePost": "100",
    "Shipper": "陳小美",
    "ShipperTel1": "0912345678",
    "ShipperAdd": "台北市中正區新生南路一段50 號",
    "ShipperPost": "100",
    "Remark": "",
    "Piece": "1"
  }
]
```

---

## 7. Report：GetLabelPdf / GetLabelPdfA4 / GetDetailPdf

三支都**用你送進去的資料即時產生 PDF**（不是查已上傳的單），body 要帶完整託運資料。成功回 PDF 串流、失敗回 §2.2 JSON。

| 端點 | 用途 | Body | 一次上限 |
|---|---|---|---|
| `Report/GetLabelPdf` | 地址標籤（單張） | **單一物件**（規格書寫 `Data= { ... }`） | **1 筆** |
| `Report/GetLabelPdfA4` | 地址標籤 **3 × 2**（一張 A4 六格） | 陣列 | **6 筆** |
| `Report/GetDetailPdf` | **託運明細表**（交司機核對） | 陣列 | 規格書未寫 |

正式 / 測試 URL：`https://api.kerrytj.com/EDIEntrance/Report/...` / `https://api.kerrytj.com/EDIEntranceTest/Report/...`。

### 7.1 GetLabelPdf / GetLabelPdfA4 欄位（16 欄，兩支相同）

| No | 欄位 | 中文 | 型別 | 必填 | 說明 |
|---|---|---|---|---|---|
| 1 | `ShipDate` | 出貨日期 | STRING(8) | ✅ | yyyyMMdd |
| 2 | `CustomerNo` | 客戶編號 | STRING(8) | ✅ | |
| 3 | `Consignee` | 收貨人 | STRING(6) | ✅ | |
| 4 | `ConsigneePost` | 收貨人郵區 | STRING(3) | ✅ | |
| 5 | `ConsigneeAdd` | 收貨人地址 | STRING(60) | ✅ | |
| 6 | `ConsigneePhone` | 收貨人電話 | STRING(**20**) | ✅ | 比建單的 15 寬 |
| 7 | `BLN` | 託運單號 | STRING(11) | ✅ | |
| 8 | `Shipper` | 寄貨人 | STRING(6) | ✅ | |
| 9 | `ShipperPost` | 寄貨人郵寄區號 | STRING(3) | ✅ | |
| 10 | `ShipperAdd` | 寄貨人地址 | STRING(60) | ✅ | |
| 11 | `ShipperTel` | 寄貨人電話 | STRING(15) | ✅ | |
| 12 | `Piece` | 件數 | STRING(3) | ✅ | 1–999 |
| 13 | `CON` | 出貨單號 | STRING(12) | | （見 §2.2 警告） |
| 14 | `AC` | 代收貨款 | STRING(6) | | **預設 0**，≤ 50000 |
| 15 | `Remark` | 備註 | STRING(60) | | |
| 16 | `Temperature` | 溫層 | STRING(1) | ✅ | 1 / 2 / 3 |

```json
// GetLabelPdf 官方範例（單一物件；注意 Piece / AC 寫成數字）
{
  "ShipDate": "20200424", "CustomerNo": "11111111",
  "Consignee": "Test", "ConsigneePost": "100", "ConsigneeAdd": "台北市中正區新生南路一段XX 號", "ConsigneePhone": "0912345678",
  "BLN": "12345678901",
  "Shipper": "TEST01", "ShipperPost": "702", "ShipperAdd": "台南市南區南路一段XX 號", "ShipperTel": "0987654321",
  "Piece": 1, "CON": "", "AC": 0, "Remark": "", "Temperature": "1"
}
```

### 7.2 GetDetailPdf 欄位（26 欄）

§7.1 的 16 欄 **加上**：`Weight`（STRING(4)，預設 0，0–9999）、`Volume`（同）、`ETA`、`DeclaredValue`（預設 0，≤ 50000）、`SpecialService`、`StationEnable`、`Receipt`、`HolidayId`（6 週六 / 7 週日）、`PaymentType`（0 到付 / 1 現收）、`ETP`（yyyyMMdd）。必填同 §7.1（橘色 13 欄）。合法值與 §3 相同。

### 7.3 實作重點

- **標籤資料要與建單資料一致**：用建單時同一份物件產標籤，避免標籤與系統資料不符。
- `GetLabelPdfA4` 超過 6 筆要**分批**呼叫再合併 PDF（或分多張列印）。
- 官方實測：無憑證送 `[]` 給 `GetLabelPdfA4` / `GetDetailPdf` 會回 1027 bytes 的空白 PDF（200）——**空陣列不報錯**，呼叫前自行檢查筆數 > 0。
- 鼎新 A1：「託運總表（嘉里大榮）不需上傳即可列印」——`GetDetailPdf` 只是產 PDF，不會改變任何託運狀態。

---

## 8. 規格書未收錄的端點（只有 Help Page schema）

以下端點存在於 `EDIEntrance/Help`（正式 / 測試機都有），但**《客戶 API 串接規格書》v1.1.0 沒有收錄**，代表嘉里大榮沒有提供給一般客戶。**一般電商出貨不要用**；業務明確指定時再向嘉里大榮索取規格。

| 端點 | Body 模型（Help Page） | 推得用途 | 備註 |
|---|---|---|---|
| `POST V2/Shipment`、`POST Shipment` | `ShipmentRequestEntity[]`（`ShipDate`、`CustomerNo`、`Shipper*`、`BLN`、`Consignee*`、`Piece`、`Volume`、`Weight`、`AC`、`Remark`、`ETA`、`ETP`、`CON`、`Temperature`、`GreenFlag`、`PaymentType`；全未標驗證） | 精簡版建單 | 第三方 matrix-platform / asgard MCP 用過 `V2/Shipment`；**官方規格指定的建單端點是 `ShipmentDelivery`** |
| `POST BLN` | `[{ CustomerNo, Temperature: int }]` | 取號？ | 規格書明說號段由嘉里大榮提供、客戶自行管理，**不需呼叫** |
| `POST PickupV2CustomPickupDate` | `PickupEntity[]` | 自訂取件日的取件 | 規格書的 `PickupV2` 以進系統時間決定取件日 |
| `POST PickupMatching` | `PickupEntity[]` → `{Result, Count, Data: [{Bln, Con, Sno, Error}]}` | 取件配對 | |
| `POST Pickup` | `PickupShipmentRequestEntity[]`（全大寫欄位 `BOL_NO`、`PICKUP_CONTACT_*`…） | 舊版取件 | |
| `POST DeliveryArea/AreaTransfer` | `[{ ID, CustomerNo, Address }]` → `{Status, Error: [{Id, Message}], Result: AreaData[]}` | 地址 → 到著站 / 配區（同 §4.2 結構） | 建單回應的 `AreaData` 已涵蓋此資訊 |
| `POST NoticeDispatch/NoticeDrive` | `NoticeDispatchEntity[]`（`Id`:int、`SerialNumber`、`ShipDate`、`CustomerNo`、`Shipper*`、`Piece`:int、`SourceType`、`CreatedUser`、`UpdatedUser`、`BLN`、`Remark`）→ `{Status, Data, ErrorMessage}` | 通知派車收件 | 規格書建單已含 `PickupTime` 預定取件時段 |
| `POST ShipmentTracking` / `POST PickupTracking` | `{ CustomerCode, Flag }`（單一物件，Flag = 驗證碼） | 依客編拉正 / 逆物流貨態 | 見 `tracking.md` §5；規格書改用 `BLNListTracing` |

店到店（`StoreShipment/*`、`TurnToStore/*`、`ConvenienceStore/*`）、客戶專屬（家樂福 / 特力 / 永豐餘 / GAP / 盟立）、內部維運（`DeliveryStation/Remove*Cache`）見 `other-endpoints.md`。
