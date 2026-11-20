---
name: kerrytj-logistics
description: >
  嘉里大榮物流（Kerry TJ Logistics，kerrytj.com，舊稱大榮貨運、KTJ）自有系統直接串接技術參考，
  對應官方《客戶 API 串接規格書》v1.1.0（2025-03-19）+ EDIEntrance Help Page。涵蓋 Basic Auth、
  **測試環境 api.kerrytj.com/EDIEntranceTest**、上傳託運資料 ShipmentDelivery（34 欄含合法值）、
  修改 UpdateShipmentDelivery、常溫取件 PickupV2、地址標籤 Report/GetLabelPdf / GetLabelPdfA4（3×2）、
  託運明細表 GetDetailPdf、常溫貨況 BLNListTracing 與低溫貨況 ColdBLNListTracing、常溫貨態表 1/2 與
  低溫貨態表完整代碼、託運單號自行配號（常溫 11 碼流水號 / 低溫 9+1 碼 mod 7 檢查碼）、
  TypeScript 與 PHP / WordPress client 範例。任務涉及嘉里大榮出貨、託運單、標籤、貨態、
  退貨取件或號段時必須使用，不要再翻 PDF 或搜尋 web。觸發詞：嘉里大榮、大榮、大榮貨運、KTJ、
  Kerry TJ、kerrytj、api.kerrytj.com、EDIEntrance、EDIEntranceTest、KerryTracingEDI、
  BLNListTracing、ColdBLNListTracing、CargoTracing、StatusCodeType、ShipmentDelivery、
  UpdateShipmentDelivery、PickupV2、GetLabelPdf、GetLabelPdfA4、GetDetailPdf、AreaData、BLN、
  託運單號、出貨單號、CON、客編、號段、代收貨款、報值、指送日期、ETP、站止。不適用：蝦皮嘉里快遞、
  泰國 / 香港 Kerry Express、黑貓（tcat-logistics）、新竹（hct-logistics）、順豐（sf-express）。
---

# 嘉里大榮物流（Kerry TJ Logistics）直接串接

> **分級：A**（規格書收錄的 9 支 API）／ B（其餘 Help Page 端點） | **規格書**：《客戶 API 串接規格書》v1.1.0（2025-03-19） | **改版日**：2026-10-29
> 原檔：`.tmp/[物流]嘉里大榮/客戶API串接規格書_20250319_v1.1.0.pdf`（全文文字版在同目錄 `spec-text/`）。完整出處見 `references/sources.md`。

## 讀這份 skill 前必須知道

1. **有測試環境**：EDIEntrance 每支 API 都有測試 URL `https://api.kerrytj.com/EDIEntranceTest/...`（路徑與正式機相同），**測試帳密 / 客編 / 號段與正式分開**。開發一律先打測試機。
2. **貨況查詢沒有測試網址**：`KerryTracingEDI.kerrytj.com` 只有一組。
3. **拿到的規格書帳號表是空白**：第 2 頁「API 帳號詳細資訊」（正式 / 測試 × 客編 / 帳號 / 密碼 / 常溫區間）未填，要向業務索取。範本：`.tmp/[物流]嘉里大榮/.env.local`。
4. **託運單號（BLN）由你配發**：嘉里大榮核發號段，系統依序取號帶入；**不回傳新單號**。
5. **規格書只收 9 支 API**：`V2/Shipment`、`BLN`、`AreaTransfer`、`ShipmentTracking` 等 Help Page 端點規格書沒有，**不要用**。

### 可信度標記

| 標記 | 意義 |
|---|---|
| 官方規格 | 《客戶 API 串接規格書》v1.1.0 |
| 官方 schema | EDIEntrance Help Page（伺服器自動產生，Description 全空） |
| 官方實測 | 2026-10-13 / 10-29 無憑證探測 |
| 推得 | 由範例 / 欄位名推斷，未證實 |

---

## 環境與認證

| 用途 | 正式 | 測試 | 認證 |
|---|---|---|---|
| 建單 / 修改 / 取件 / 標籤 / 明細表 | `https://api.kerrytj.com/EDIEntrance/` | `https://api.kerrytj.com/EDIEntranceTest/` | **Basic Auth**（`realm="api.kerrytj.com"`） |
| 貨況查詢 | `https://KerryTracingEDI.kerrytj.com/api/Tracing/` | （無） | **Basic Auth**（`realm="localhost"`），同一組帳密 |

- `Authorization: Basic base64(帳號:密碼)`；失敗 → `401` + `{"Message":"The requested authorization has been rejected."}`。
- `POST` + `Content-Type: application/json`（UTF-8）；body 是 **JSON 陣列**（一筆也包 `[ ]`），**例外：`Report/GetLabelPdf` 是單一物件**。
- 欄位一律 `STRING(n)`（件數、金額也送字串）。
- `Report/*` 規格書沒列驗證、實測免帳密也回 PDF——**照樣帶 Basic Auth**。

---

## 端點速查（規格書收錄的 9 支）

| 端點 | Body | 回應 | 重點 |
|---|---|---|---|
| `POST ShipmentDelivery` | 託運資料[]（34 欄，必填 13） | `{Result, Count, Data, AreaData}` | **建單**；`AreaData` 回到著站 / 配區 |
| `POST UpdateShipmentDelivery` | 同上 | `{Result, Count, Data}` | **已交站所則無法修改** |
| `POST PickupV2` | 取件資料[]（17 欄，必填 11） | `{Result, Count, Data}` | **僅常溫**；中午 12 點後 → D+1 派遣 |
| `POST Report/GetLabelPdf` | **單一**物件（16 欄） | PDF / ModelState JSON | 一次 **1 筆** |
| `POST Report/GetLabelPdfA4` | 陣列（16 欄） | PDF / ModelState JSON | 3×2，一次 **6 筆** |
| `POST Report/GetDetailPdf` | 陣列（26 欄） | PDF / ModelState JSON | 託運明細表 |
| `POST KerryTracingEDI/api/Tracing/BLNListTracing` | `[{BLN}]`（11 碼） | `{Status, Error, Result[{BLN, CargoTracing[]}]}` | 常溫；`StatusCodeType` 1 配送 / 2 取件 |
| `POST KerryTracingEDI/api/Tracing/ColdBLNListTracing` | `[{BLN}]`（10 碼） | 同上（無 `StatusCodeType`） | 低溫 |

**`Result`：`01` 全部成功 / `02` 部分成功（`Data` 列出失敗的 BLN + Error）/ `03` 錯誤（不給原因）。** 貨況 `Status`：`1` 成功 / `0` 失敗。

---

## 最常用：建單 → 標籤 → 追蹤

```typescript
const EDI = process.env.KERRYTJ_EDI_BASE_URL;   // 測試：https://api.kerrytj.com/EDIEntranceTest
const shipment = {
  ShipDate: '20261029', CustomerNo: '<客編>', BLN: '<號段內 11 碼>',
  Consignee: '王小明', ConsigneePost: '407', ConsigneeAdd: '台中市西屯區某路2號', ConsigneePhone: '0912345678',
  Shipper: '某某商行', ShipperPost: '104', ShipperAdd: '台北市中山區某路1號', ShipperTel: '0223456789',
  Temperature: '1', Piece: '1',
  CON: 'ORD20261029001',   // 出貨單號（自家訂單號）
  AC: '',                  // 代收貨款 ≤ 50000
  ETA: '4',                // 配送時段 1 上午 / 2 下午 / 4 不指定
};
// 1) POST {EDI}/ShipmentDelivery          body: [shipment]  → Result '01'（'02' 逐筆看 Data）
// 2) POST {EDI}/Report/GetLabelPdfA4      body: [shipment]  → application/pdf（同一份資料、≤ 6 筆）
// 3) POST https://KerryTracingEDI.kerrytj.com/api/Tracing/BLNListTracing  body: [{ BLN }]
//    → 依 StatusCode + StatusCodeType 查表（不要比對 Status 文字）
```

完整 client：TypeScript 見 `references/code-examples.md`，PHP / WordPress 見 `references/php-client.md`。

---

## 必填欄位（規格書橘色）

| 端點 | 必填 |
|---|---|
| `ShipmentDelivery` / `UpdateShipmentDelivery` | `ShipDate` `CustomerNo` `Consignee` `ConsigneePost` `ConsigneeAdd` `ConsigneePhone` `BLN` `Shipper` `ShipperPost` `ShipperAdd` `ShipperTel` `Temperature` `Piece` |
| `PickupV2` | `BLN` `CustomerNo` `Consignee` `ConsigneeTel1` `ConsigneePost` `ConsigneeAdd` `Shipper` `ShipperTel1` `ShipperPost` `ShipperAdd` `Piece` |
| `Report/*` | 同 `ShipmentDelivery` 的 13 欄 |

常用選填：`CON` 出貨單號、`AC` 代收貨款、`DeclaredValue` 報值、`ETA` 配送時段、`ETP` 指送日期、`StationEnable` 站止、`PaymentType` 0 到付 / 1 現收、`HolidayId` 6 週六。完整 34 欄見 `references/api-reference.md` §3。

---

## 陷阱（依嚴重度）

| 嚴重度 | 陷阱 | 說明 |
|---|---|---|
| 高 | 測試 / 正式帳密混用 | 兩套帳密、客編、號段都不同；`EDIEntranceTest` 用測試帳號 |
| 高 | 姓名只有 6 字元 | `Consignee` / `Shipper` STRING(6)；超長要截斷（全名移到 `Remark`） |
| 高 | 單號由客戶配發 | 取號要交易鎖；建單失敗**沿用同一 BLN 重送** |
| 高 | 溫層決定單號格式 | 常溫 11 碼流水號、每年可重用；低溫 10 碼 = 9 碼 + (前 9 碼 mod 7)、**不可重用** |
| 高 | `Result 02` 部分成功 | 逐筆比對 `Data[].BLN`，只重送失敗的；整批重送會重複上傳 |
| 中 | 郵遞區號只收 3 碼 | `*Post` STRING(3)，5 / 6 碼先截前 3 碼 |
| 中 | `PickupV2` 方向 | 官方欄位名：**`Shipper` = 取件人（消費者）**、`Consignee` = 收貨人（商家）；第三方實作相反，別抄 |
| 中 | 貨況端點分溫層 | 常溫 `BLNListTracing`、低溫 `ColdBLNListTracing`，不可混送 |
| 中 | 代碼表分三張 | 同一代碼在常溫表 1 / 表 2 / 低溫表意義不同（例 `91`）；`Status` 文字與表不一定一致 |
| 中 | `GetLabelPdf` 是單一物件 | 其他 Report 是陣列；A4 一次 ≤ 6 筆；空陣列會回空白 PDF 不報錯 |
| 中 | 無取消 API | 規格書沒有取消託運單；已交站所也不能修改 |
| 中 | 取件 D+1 | `PickupV2` 中午 12 點後送出 → 隔天派遣 |
| 低 | `ETA` ≠ `ETP` | `ETA` 時段代碼（1/2/4，無 3）；`ETP` 指送日期 yyyyMMdd |
| 低 | 不送假日 | 107/1/1 起停止週六日配送（官網）；`HolidayId` 需另約 |
| 低 | 官網查貨無 deep link | 給消費者 `https://www.kerrytj.com/zh/checking` + 單號 |
| 嚴禁 | 規格書外端點 | `V2/Shipment`、`BLN`、`ShipmentTracking`、`NoticeDrive`、店到店 / 客戶專屬 / `DeliveryStation/Remove*Cache` 都不要呼叫 |

---

## 業務規則速查（官網）

- 代收貨款：僅契約客戶；每筆上限 **5 萬**；手續費 1%（最低 30）+ 匯款費 30 元。
- 報值：報值費 1%；上限 **5 萬**；未報值賠償上限每件 3,000 元。
- 才積：長×寬×高(cm) / 27000；1 才 = 10 kg；重量與才積取大者；單件建議 ≤ 30 kg、≤ 7 才。
- 付款：現付 / 到付（寄收任一方需為契約客戶）/ 月結。
- 溫層：冷藏 0~10°C、冷凍 -12°C 以下；低溫件需預冷。
- 客服 **412-8999**（手機加 02）、**callcenter@kerrytj.com**；API 開通找**所屬營業所業務**。

詳見 `references/onboarding-and-business-rules.md`。

---

## References 導引

| 需求 | 參閱檔案 |
|---|---|
| 每支 API 完整欄位表（長度、合法值、必填）、官方範例、回應碼、Report 錯誤格式、規格書未收錄端點清單 | `references/api-reference.md` |
| 常溫 / 低溫貨況 API、**常溫表 1 / 表 2 / 低溫表完整代碼** + 建議映射、官網查貨限制 | `references/tracking.md` |
| curl（測試環境逐支打）、TypeScript 型別 / client / 正規化 / 單號檢查碼 / 貨態映射 / 號段配號 | `references/code-examples.md` |
| PHP / WordPress（`wp_remote_post`）client、`$wpdb` 號段配號、WooCommerce 建單 + WP-Cron 輪詢 | `references/php-client.md` |
| 申請開通、**測試環境說明**、憑證、號段規則、運費 / 代收 / 報值 / 溫層、**待索取清單（11 項）** | `references/onboarding-and-business-rules.md` |
| 店到店 / 客戶專屬 / 內部端點辨識（皆不使用） | `references/other-endpoints.md` |
| 來源、規格書 vs 舊版差異表、實測紀錄、原檔位置 | `references/sources.md` |

---

## 待向嘉里大榮索取（摘要）

帳號表填值（正式 / 測試的客編、帳號、密碼、常溫號段）、低溫號段、測試機行為（是否派車 / 能否查貨況）、錯誤訊息全表、姓名長度以字元或 byte 計、取消託運單方式、貨況批次上限與主動推播。完整 11 項見 `references/onboarding-and-business-rules.md` §6.1。
