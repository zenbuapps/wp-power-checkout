# 來源清單、調查方式與可信度（2026-10-13 初版；2026-10-29 依官方規格書改版）

> **何時讀這份**：要回溯某項資訊的出處與可信度、確認調查搜過哪些地方、或嘉里大榮給了新版規格後要逐項比對更新本 skill 時。本檔不含 API 規格本身。

## 目錄

- [1. 結論與分級](#1-結論與分級)
- [2. 官方來源](#2-官方來源)
- [3. 官方主機實測紀錄](#3-官方主機實測紀錄)
- [4. 第三方來源](#4-第三方來源)
- [5. 搜過但無結果的來源](#5-搜過但無結果的來源)
- [6. 原始檔案保存位置](#6-原始檔案保存位置)

---

## 1. 結論與分級

**分級：A（規格書收錄的 9 支 API）／ B（其餘 Help Page 端點）**。

- 2026-10-29 取得嘉里大榮提供的**《客戶 API 串接規格書》v1.1.0**（2025-03-19，37 頁，Word 轉 PDF，作者欄：黃浚彰）。涵蓋：帳號資訊表、Basic Auth、上傳 / 修改託運資料、常溫取件、常溫 / 低溫貨況、三張貨態表、地址標籤（單張 / 3×2）、託運明細表——**含測試環境 URL**。
- 規格書**沒收錄**的 EDIEntrance 端點（`V2/Shipment`、`BLN`、`AreaTransfer`、`ShipmentTracking`…）仍只有 Help Page schema（B 級）。

| 層級 | 涵蓋內容 |
|---|---|
| **官方規格（規格書 v1.1.0）** | 9 支 API 的正式 / 測試 URL、欄位中文名、STRING 長度、合法值、必填（橘色）、範例、`Result` 01/02/03、`AreaData` 欄位中文、Report 錯誤格式與一次筆數上限、貨況結構、常溫表 1 / 表 2 / 低溫表、號段規則與低溫檢查碼 |
| 官方 schema（Help Page） | 36 個端點路徑、HTTP method、request / response 模型欄位名與型別、`ShipmentDeliveryEntity` 的 Required / Length / Range、`TemperatureEnum` |
| 官方實測 | 正式 / 測試主機存在、端點清單一致、Basic Auth realm、Report 免驗證回 PDF、`ColdBLNListTracing` 存在 |
| 官方營運（官網） | 查貨保留 75 天、代收 / 報值上限、運費 / 才積 / 溫層規則、聯絡窗口 |
| 第三方 | 號段配號實作、申請流程、鼎新 A1 業務經驗 |
| 推得 / 未知 | `Count` 語意、姓名長度計算方式、`PickupV2` 方向的實際派車驗證、測試機資料隔離、錯誤訊息全表 |

### 1.1 規格書 v1.1.0 vs 舊版 skill（Help Page + 第三方）差異

| 項目 | 舊版 skill（推得） | 規格書 v1.1.0（官方） |
|---|---|---|
| 測試環境 | 未發現，只能打正式機 | **有：`/EDIEntranceTest/`**，帳密另發 |
| 建單端點 | `ShipmentDelivery` / `V2/Shipment` 不確定 | 只有 `ShipmentDelivery` |
| `CON` | 未知 | 出貨單號（低溫 ≤ 10 字元） |
| `ETP` | 推得：預計取件日 | **指送日期**（指定配達日） |
| `ETA` | 推得：配達日 / 時段 | 配送時段代碼 1/2/4 |
| `Receipt` / `HolidayId` / `CityType` / `Specification` / `DailyDelyId` / `PaymentType` | 未知 / 推得 | 全有合法值 |
| 姓名 / 郵遞區號長度 | 無 Max length | Consignee / Shipper **STRING(6)**、Post **STRING(3)** |
| 件數 | 鼎新稱一件一號，衝突 | `Piece` 1–999，一號多件 |
| `Result` | 只知 `01` 成功 | 01 全部 / 02 部分 / 03 錯誤 |
| `PickupV2` 方向 | 第三方：消費者 = Consignee | 欄位名：**Shipper = 取件人**、Consignee = 收貨人（第三方相反） |
| 貨況 | 只知 `BLNListTracing`，`CargoTracing` 結構未知 | 常溫 / 低溫兩支，完整結構 + 三張代碼表 |
| 低溫單號 | 檢查碼未知 | 9 碼 + (前 9 碼 mod 7) |
| 標籤筆數 | 未知 | 單張 1 筆、A4 3×2 6 筆 |

---

## 2. 官方來源

| 來源 | URL | 取得方式 | 收錄位置 |
|---|---|---|---|
| **《客戶 API 串接規格書》v1.1.0**（2025-03-19） | 非公開；嘉里大榮提供 | 用戶提供 PDF：`.tmp/[物流]嘉里大榮/客戶API串接規格書_20250319_v1.1.0.pdf`；以 PyMuPDF 抽文字（pdftotext 會掉中文）+ 逐頁轉圖確認橘色必填 | 全 skill（主來源） |
| EDIEntrance Help Page（索引） | https://api.kerrytj.com/EDIEntrance/Help | curl（公開、無需登入） | `api-reference.md`、`other-endpoints.md`、`tracking.md` |
| EDIEntrance 各端點說明頁（36 頁） | `https://api.kerrytj.com/EDIEntrance/Help/Api/{METHOD}-{Path}`（如 `POST-ShipmentDelivery`、`POST-V2-Shipment`、`GET-api-GAP-GetList_bolNo`） | curl，全部 36 頁皆已下載閱讀 | 同上 |
| EDIEntrance 資料模型頁（45 頁） | `https://api.kerrytj.com/EDIEntrance/Help/ResourceModel?modelName={Model}` | curl，全部已下載 | 同上 |
| 官網首頁 / 選單樹 | https://www.kerrytj.com/zh/ ；選單資料 `GET /api/MenuMain/GetList?type=100`、`/api/MenuSub/GetList?type=100&mainId=-1`、`/api/MenuItem/GetList?subId=-1` | curl + 官網前端 JS | 確認官網無 API / EDI 說明頁 |
| 常見問題 | https://www.kerrytj.com/zh/faq（資料 `GET /api/Faq/GetList`） | curl | `onboarding-and-business-rules.md` §4、`tracking.md` §5 |
| 線上託運 / 託運條款 | https://www.kerrytj.com/zh/order | curl | `onboarding-and-business-rules.md` §4 |
| 快速查貨 | https://www.kerrytj.com/zh/checking（前端 `checking.js`、`checking_result.js`、`/js/ApiDataServer/Tracking.js`） | curl | `tracking.md` §5 |
| 契約會員專區 / 線上服務申請 | https://www.kerrytj.com/zh/contractmember 、https://www.kerrytj.com/zh/register | curl | `onboarding-and-business-rules.md` §1 |
| 聯絡我們 | https://www.kerrytj.com/zh/contacts | curl | `onboarding-and-business-rules.md` §5 |
| 營業據點 / 服務頁 | https://www.kerrytj.com/zh/menu/item2/20（常溫配送）、/21（低溫配送）、/zh/menu/sub/29 | 選單 API 內容 | 業務規則、溫層 |

官網選單共 8 大類 / 37 子類 / 59 項，**沒有任何**「API / EDI / 系統串接 / 開發者」頁面；常溫配送頁僅提到「網路提供即時託運系統」「契約客戶網路帳務查詢系統」。

---

## 3. 官方主機實測紀錄

2026-10-13，**無憑證、空 body 或明顯無效值**，僅確認存在性與認證行為，未送出任何真實託運資料。

| 請求 | 結果 |
|---|---|
| `GET https://api.kerrytj.com/EDIEntrance/` | 200，空白「KERRY TJ」首頁（ASP.NET MVC） |
| `GET .../EDIEntrance/{V2/Shipment, ShipmentDelivery, PickupV2, Report/GetLabelPdfA4, Report/GetDetailPdf}` | 405，`Allow: POST`，`{"Message":"要求的資源不支援 http 方法 'GET'。"}` |
| `GET .../EDIEntrance/NotExistXYZ` | 404（IIS HTML） |
| `POST .../EDIEntrance/{V2/Shipment, ShipmentDelivery, PickupV2, BLN, DeliveryArea/AreaTransfer, UpdateShipmentDelivery}` body `[]` | 401，`WWW-Authenticate: Basic realm="api.kerrytj.com"`，`{"Message":"The requested authorization has been rejected."}` |
| `POST .../EDIEntrance/Report/GetLabelPdfA4` body `[]` | 200，`application/pdf`，`Content-Disposition: attachment; filename=report.pdf`，1027 bytes |
| `POST .../EDIEntrance/Report/GetDetailPdf` body `[]` | 200 |
| `POST .../EDIEntrance/ShipmentTracking` body `{}` | 200，`{"IsSuccess":false,"Error":"客戶編號為必填","Data":[]}` |
| `POST .../EDIEntrance/ShipmentTracking` body `{"CustomerCode":"00000000"}` | 200，`{"IsSuccess":false,"Error":"驗證碼為必填","Data":[]}` |
| `POST .../EDIEntrance/PickupTracking` body `{}` | 200，`{"IsSuccess":false,"Error":"客戶編號為必填","Data":[]}` |
| `POST https://KerryTracingEDI.kerrytj.com/api/Tracing/BLNListTracing` body `[{"BLN":"0000000000"}]` | 401，`WWW-Authenticate: Basic realm="localhost"`，body 空 |
| `GET https://KerryTracingEDI.kerrytj.com/{Help, swagger, swagger/index.html}` | 404（無文件頁） |
| `GET https://api.kerrytj.com/CommunityPickup/...` | 503（已下線） |
| `GET https://www.kerrytj.com/ZH/search/search.aspx?qry=...`、`/zh/search/search_track.aspx` | 400（舊查貨頁已失效） |
| 子網域探測：`edi.kerrytj.com` 404、`webedi.kerrytj.com` 403（IIS 預設）、`api.kerrytj.com` 根 403；其餘常見子網域（openapi / ws / tms / ec / b2b / tracking…）無回應 | — |

**2026-10-29 補測（依規格書）**，同樣無憑證、空 body：

| 請求 | 結果 |
|---|---|
| `GET https://api.kerrytj.com/EDIEntranceTest/Help` | 200（14,380 bytes）；端點清單 36 支，與 `EDIEntrance/Help` **完全相同** |
| `POST .../EDIEntranceTest/{ShipmentDelivery, UpdateShipmentDelivery, PickupV2}` body `[]` | 401，`WWW-Authenticate: Basic realm="api.kerrytj.com"`，`{"Message":"The requested authorization has been rejected."}` |
| `POST .../EDIEntranceTest/Report/GetLabelPdf` body `[]` | 400，`{"Message":"要求無效。","ModelState":{"request":["發生錯誤。"]}}`（body 應為單一物件；**未要求認證**） |
| `POST .../EDIEntranceTest/Report/{GetLabelPdfA4, GetDetailPdf}` body `[]` | 200 `application/pdf`（1027 bytes 空白 PDF），與正式機相同 |
| `POST https://KerryTracingEDI.kerrytj.com/api/Tracing/ColdBLNListTracing` body `[]` | 401，`WWW-Authenticate: Basic realm="localhost"`（端點存在） |

---

## 4. 第三方來源

| 來源 | URL | 時間 | 提供的資訊 | 可信度 |
|---|---|---|---|---|
| matrix-platform/shopping `Kerry.php` | https://github.com/matrix-platform/shopping/blob/d07022ceaa07e8c200941ea7ff70823d9954e06b/class/matrix/shopping/shipping/Kerry.php | 2023-08~10（8 個 commit） | EDIEntrance base URL、Basic Auth、`ShipmentDelivery` / `V2/Shipment` / `PickupV2` / `Report/GetLabelPdfA4` / `Report/GetDetailPdf` 實際欄位、`KerryTracingEDI` 主機與 `BLNListTracing`、`Result==='01'`、`Status==='1'`、`CargoTracing`、郵遞區號改 3 碼、V2 補 `Piece` | 中高（實際上線程式，但無授權條款，本 skill 只描述行為不複製程式碼） |
| matrix-platform/shopping `cfg/kerry.php` + i18n | https://github.com/matrix-platform/shopping/blob/d07022ceaa07e8c200941ea7ff70823d9954e06b/cfg/kerry.php | 2023-08 | 設定項：客戶編號 / 帳號 / 密碼 / 託運單號前綴字 / 序號池 / 寄貨人 / 溫層 / API URL | 中高 |
| BryanYen0619/MyCommunityPickUpServiceProject | https://github.com/BryanYen0619/MyCommunityPickUpServiceProject/blob/a35c33d63679211c47ed6d292ed95da0290fd95a/app/Http/Controllers/api/KerryPickUpController.php | 2019 | 已下線的 `CommunityPickup` API；`Flag = md5(CustomerNo + yyyyMMdd)`；`ETP`=transport_date、`ETA`=delivery_period 對應 | 低（舊、不同服務） |
| asgard-ai-platform/mcp-kerry-tj | https://github.com/asgard-ai-platform/mcp-kerry-tj | 2026-04~05 | 同樣使用 `V2/Shipment` + `BLNListTracing` + Basic Auth；**自述尚未取得測試密鑰、未端到端測試**；README 回應範例為示意 | 低（未驗證，疑似參考上列 matrix 實作） |
| Moksa1123/taiwan-ecommerce-toolkit | https://github.com/Moksa1123/taiwan-ecommerce-toolkit/blob/5fde04410fbf91f7a091f5d9dbfa0734abf35a93/taiwan-logistics/references/carrier-direct-access.md | 2026-08 查證 | 結論「嘉里大榮無公開規格、走聚合商或簽約洽業務」（未發現 Help Page） | 參考 |
| 鼎新 A1 產品手冊：物流商/API設定 | https://a1.digiwin.com/noviceNew/novice-content.php?detail=460 | — | API 欄位「帳號 / 密碼 / 客戶代號」、向大榮窗口申請、回信內容（客編 / 帳號 / 密碼 / 一年號段）、標籤 10cm×10cm、不支援蝦皮嘉里快遞單 | 中（ERP 廠商實作經驗） |
| 鼎新 A1：託運單號設定(嘉里大榮) | https://a1.digiwin.com/noviceNew/novice-content.php?detail=550 | — | 號段起迄、常溫 11 / 低溫 10 碼、常溫一年後可重用 / 低溫不可、多設定拆號段 | 中 |
| 鼎新 A1：託運單 | https://a1.digiwin.com/noviceNew/novice-content.php?detail=462 | — | 「大榮 API 不接受件數輸入，多件需複製」、代收只記第一筆、刪單號碼遺失、託運總表列印 | 中 |
| 鼎新 A1：物流好幫手說明 | https://a1.digiwin.com/noviceNew/novice-content.php?detail=175 | — | 申請 API 流程、多營業所分開申請共用帳密 | 中 |
| 部落格：KTJ 大榮貨運託運單列印及軟體操作 | https://www.celiasu.com/2021/08/kerrytj-logistics-software-operation-online-query.html | 2021（後續更新） | 舊桌機託運軟體 + FTP 傳送；2023/5/5 起改網頁版線上 EDI 系統 | 低（使用者經驗） |
| My Design：大榮物流 WooCommerce 串接 | https://my-design.works/ktj_woocommerce/ | — | 證實有外包商做過 WooCommerce 串接（開單 + 查詢），無技術細節 | 低 |
| 正航 ERP 部落格 | https://www.chi.com.tw/blog/chiplod | — | 列出可整合嘉里大榮，無技術細節 | 低 |
| TrackingMore kerrytj | https://www.trackingmore.com/kerrytj-tracking-api | — | 第三方聚合追蹤服務（非官方 API） | 參考 |

---

## 5. 搜過但無結果的來源

- Web 搜尋（中英）：「嘉里大榮 API 串接」「嘉里大榮 EDI 託運單 電子 串接 企業客戶」「kerrytj api webservice github」「kerrytj.com api OR edi OR webservice」「嘉里大榮 線上EDI 系統 託運 登錄 網址」「嘉里大榮 API 帳號 申請 客戶編號 託運單號 區間 ERP」「SHOPLINE / CYBERBIZ / 91APP / WACA 嘉里大榮 串接」「EDIEntrance / KerryTracingEDI / BLNListTracing」——**均無官方規格書或 PDF**。
- GitHub code search：`kerrytj.com`、`api.kerrytj.com`、`edi.kerrytj.com`、`webedi.kerrytj.com`、`EDIEntrance`、`KerryTracingEDI`、`BLNListTracing`、`GetLabelPdfA4`、欄位組合（`ConsigneePost BLN Temperature` 等）——只有 §4 列出的 matrix-platform / BryanYen / asgard / Moksa1123。
- GitHub repo search：`kerrytj`、`kerry-tj`、`ktj-logistics`、`嘉里大榮`、`kerry-tracking`——其餘為無關作業 / 爬蟲 / 泰國 Kerry Express。
- 綠界 ECPay、PAYUNi、Shopline、91APP、CYBERBIZ：未發現任何「嘉里大榮」物流子類型或串接文件（主流聚合商不支援嘉里大榮宅配）。

---

## 6. 原始檔案保存位置

`.tmp/[物流]嘉里大榮/`（視為不可信資料，只讀不執行）：

| 路徑 | 內容 |
|---|---|
| `客戶API串接規格書_20250319_v1.1.0.pdf` | **官方規格書原檔**（第 2 頁帳號表為空白模板） |
| `spec-text/客戶API串接規格書_20250319_v1.1.0.txt` | 規格書 PyMuPDF 抽出的全文（含貨態表英文欄） |
| `.env.local` | 環境變數範本（URL 已填、帳密待填；gitignore） |
| `edi-help/` | EDIEntrance Help Page 原始 HTML：`Help.html` + 36 個 `api_*.html` + 45 個 `model_*.html` |
| `edi-help-txt/` | 上述 HTML 轉成的純文字（欄位表 + JSON 樣本） |
| `tracing-edi/root.html` | KerryTracingEDI 首頁 |
| `official-site/` | 官網 FAQ 文字、選單樹、查貨頁前端 JS（`.txt` 副檔名，避免被執行） |
| `third-party/` | matrix-platform `Kerry.php` / `cfg` / commit 歷史、BryanYen controller、asgard MCP README / settings、Moksa1123 盤點文 |
