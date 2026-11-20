---
name: tcat-logistics
description: >
  黑貓宅急便 / 統一速達（T-cat、tcat、Takkyubin、suda）**標準印單 API 平台**完整技術參考，
  對應官方《印單API平台_API規格書_契客 v2.1.2》（2026-03-25）。**自有系統直接串接**黑貓，
  走統一速達契約客戶授權碼（CustomerId + CustomerToken），不經任何第三方金流；經 PAYUNi
  代叫宅配請改用 payuni-logistics-v3，黑貓 PAY 金流請改用 tcat-pay。涵蓋全部 12 個 JSON
  API 端點：查詢郵號（ParsingAddress）、列印託運單-宅配（PrintOBT）、列印託運單-撿貨明細
  （PrintOBTByPickingList）、列印託運單-快速到店（PrintOBTByB2S）、下載託運單
  （DownloadOBT）、呼叫黑貓（Call）、逆物流新增 / 修改 / 刪除 / 單筆查詢（ReserveAdd /
  ReserveEdit / ReserveDelete / ReserveQuery）、查詢託運單貨態（OBTStatus）、查詢醫藥郵號
  及據點（ParsingMedicalLocation）；以及 91 組錯誤代碼 E001–E091、OBTStatus 查詢限流
  （每日 3000 次 / 併發 3 / 同單號 2 小時）、FileNo 24 小時下載期限、貨態查詢三路（官網單筆 /
  OBTStatus 即時 / FTP 批次回檔）、FTP 檔案格式（eod 上拋、sod 貨態回檔、nod 未集貨原因、
  客樂得代收 nom / adj / ret）、託運單版面規格（A4 二模 / A4 三模 / 熱轉印 / 撿貨單）、
  7-11 快速到店倉到店電子地圖（Map.aspx / MobileMap.aspx）、速達營業站所自取地圖
  （CAT0001MM1）。當需要串接黑貓宅配、建立託運單、取得託運單 PDF、補印託運單、線上叫車收貨、
  逆物流退貨預約、查貨態、解析黑貓回檔檔案，或繪製黑貓託運單標籤時，必須使用此 skill，不要
  再去翻官方 PDF 或搜尋 web。觸發詞：黑貓、黑貓宅急便、宅急便、統一速達、速達、T-cat、tcat、
  takkyubin、suda、印單API、標準印單、印單API平台、託運單、託運單號、OBTNumber、CustomerId、
  CustomerToken、授權碼、契約客戶代號、ParsingAddress、PrintOBT、PrintOBTByPickingList、
  PrintOBTByB2S、DownloadOBT、Call、ReserveAdd、ReserveEdit、ReserveDelete、ReserveQuery、
  OBTStatus、ParsingMedicalLocation、醫藥郵號、PostCodeName、PostType、FileNo、
  api.suda.com.tw、api.suda.net.tw、egs.suda.com.tw、查詢郵號、寄件人郵碼、SenderZipCode、
  RecipientZipCode、溫層、Thermosphere、收付地點、希望配達時間、代收貨款、CollectionAmount、
  報值、商品類別、貨態查詢、貨態代碼、StatusId、錯誤代碼、E001、E070、E084、E086、E087、
  7-11快速到店、倉到店、B2S、電子地圖、ReceiveStoreId、自取站地圖、客樂得代收回檔、
  FTP回檔、eod、sod、nod、nom、adj、ret、熱轉印、A4三模、A4二模、撿貨單、Code39、
  逆物流、退貨預約、呼叫黑貓、叫車收貨。
---

# 黑貓宅急便（統一速達 T-cat）標準印單 API 整合

統一速達提供 **印單 API 平台**：HTTPS + JSON 的 Web API（**非 SOAP、非 XML**），讓契約客戶用自有系統直接配號、產生託運單 PDF、叫車、退貨預約與查貨態。整合涵蓋四塊：

1. **印單 API 平台**（`https://api.suda.com.tw/api/Egs/{服務名稱}`）— **12 個 JSON 端點**：查郵號、三種列印託運單、下載託運單 PDF、呼叫黑貓、逆物流 CRUD、查貨態、查醫藥郵號。
2. **貨態查詢三路** — 官網 URL（單筆人工）/ `OBTStatus` API（即時，≤10 筆）/ FTP `.sod` 回檔（批次，每日兩次）。**API 用二代代碼、FTP 用一代代碼，兩套不同**，見 `references/cargo-status.md`。
3. **FTP 資料交換**（`103.234.81.11`）— 上拋 `.eod` 電子訂單；下載 `.sod` 貨態 / `.nod` 未集貨原因 / 客樂得代收 `.nom`/`.adj`/`.ret`。**全部是 `|` 分隔的變動長度文字檔，不是定長欄位。**
4. **地圖 / 門市選擇** — 7-11 快速到店倉到店電子地圖（POST form 導轉）+ 速達營業站所自取地圖（querystring 導轉 + 廠商 callback API）。

> **文件版本**：主來源 = **《印單API平台_API規格書_契客 v2.1.2》（2026-03-25，81 頁）**。對照來源 = v2.0.0（58 頁）、v1.9.3（2023-03-27，60 頁）用於 diff。補充來源 = 倉到店電子地圖串接文件 V1.4（2022-11-09）、黑貓自取站地圖查詢操作手冊 C# 版 v1.0.1（2020-12-17）、統一速達契約客戶電子資料交換說明（2023-08-22）、貨態 / 客樂得回檔格式文件（2017–2021）。**凡 v2.1.2 有講到的，一律以 v2.1.2 為準。**
>
> **v2.0.0 → v2.1.2 五個必看變更**（完整增量見 `references/version-notes.md` §2A）：
> 1. **新增 91 組錯誤代碼 E001–E091**（附錄三）— 舊版「無錯誤碼表」的結論已作廢
> 2. **`OBTStatus` 硬性限流**：每日 3,000 次 / 併發 3 / **同一單號 2 小時只能查 1 次**
> 3. **`FileNo` 24 小時失效**，逾期只能重新建單（配新單號）
> 4. **新增第 12 支端點 `ParsingMedicalLocation`**（醫藥專用郵號 + 據點名稱）
> 5. **`Call` 每個收貨點每日限 1 次**、無法指定收貨時間

---

## 與既有 skill 的邊界（先讀這段，選對路）

專案內有三個「黑貓相關」路徑，**互斥、不可混用**：

| Skill | 物流商 | 路徑 | 何時該用 |
|---|---|---|---|
| `hct-logistics` | **新竹物流** | SOAP EDI WebServices（`.asmx`）| 完全不同物流商，**與黑貓無關** |
| `payuni-logistics-v3` | 黑貓（**代開**）| 透過 **PAYUNi 統一金流**：`create_shipping` / `call_cat` / 託運單 PDF | 已用 PAYUNi 收金流，想順便讓 PAYUNi 幫你開黑貓宅配單。**契約掛在 PAYUNi 名下**，你不需要自己的速達契約 |
| **`tcat-logistics`（本 skill）** | 黑貓（**直串**）| 自有系統 → 統一速達印單 API，`CustomerId` + `CustomerToken` | **你自己與統一速達簽約**、拿到契約客戶代號與授權碼，要直接控制配號 / 版型 / 逆物流 / FTP 回檔。不經任何第三方 |

| `tcat-pay` | 黑貓（**金流平台代理**）| `cocs.4128888card.com.tw/api/Logistics/*`，認證用金流 Bearer token + `ServiceId` | 已用黑貓 PAY 收金流，且**已把速達契客代號＋授權碼綁進 ccat.com.tw「會員專區 › 連線設定 › 物流服務」**。只有「建單 + 印單 + 7-11 選店」四支，沒有逆物流 / 貨態 / 郵號查詢 |

> ⚠ **`tcat-pay` 那條路仍然要先與統一速達簽約**——授權碼是「寄放在黑貓 PAY 後台」而非「免除」。
> 差別只在憑證由誰保管：本 skill 放在你的程式碼，`tcat-pay` 放在黑貓 PAY 後台。
> 兩條路共用同一組速達契客代號與授權碼（本專案：`9050786601` / 印單 API 授權碼）。

**判斷法**：先看 domain。`api.suda.com.tw` / `egs.suda.com.tw` + body 有 `CustomerToken` → 本 skill。`4128888card.com.tw/api/Logistics/` + header `Bearer` + body `ServiceId` → `tcat-pay`（同名端點 `PrintOBT` / `PrintOBTByB2S` / `DownloadOBT` 兩邊都有，**光看端點名分不出來，必須看認證欄位**）。出現 `ShipTradeNo`、`call_cat`、`payuni` → `payuni-logistics-v3`。出現 `edelno`、`TransData` → `hct-logistics`。

---

## 重要前提（不可遺漏）

- **認證 = `CustomerId` + `CustomerToken`，放在每支 API 的 JSON body 最上層**（不是 header、不是 OAuth、**無簽章 / 無加密 / 無 hash**）。與 ECPay / NewebPay / PAYUNi 的 CheckMacValue 機制完全不同——**不要去找加密函式，沒有這東西**。
- **`CustomerId` 限 10 碼或 12 碼**（`String(12)`）；`CustomerToken` `String(50)`。授權碼**分正式站台 / 測試站台兩組**，到契客專區自行發送取得，見 `references/version-notes.md`。
- **必須支援 TLS 1.2 以上**（官方明確要求）。編碼一律 **UTF-8**。
- **連線逾時請設 ≥ 120 秒**（`ParsingAddress` / `PrintOBT` / `PrintOBTByPickingList` / `PrintOBTByB2S` 四支官方明列；服務繁忙時會慢）。
- **回應外殼固定三欄 + Data**：`SrvTranId`(20) / `IsOK`(`Y`|`N`) / `Message`(1000) / `Data`(失敗時為 `null`)。**HTTP 200 不代表成功——必須判 `IsOK === 'Y'`。**
- **v2.1.2 起有 91 組錯誤代碼 `E001`–`E091`**（附錄三）。`E001` 是欄位驗證集合碼，其餘一碼一義。**限流碼 `E084`/`E085`/`E086`/`E087` 與期限碼 `E070` 必須特別處理**。完整表 + 重試分類見 `references/api-reference.md` §16。
  ⚠️ 回應外殼**沒有獨立錯誤碼欄位**，官方未說明代碼是否隨 `Message` 一起回傳——上線前實測，見最底「待澄清」。
- **列印與下載是兩段式**：`PrintOBT*` 先配號並回 `FileNo`（託運單下載檔案編號）→ 再呼叫 `DownloadOBT` 拿 **binary PDF 資料流**。`FileNo` 是一次列印批次的 handle，補印時可帶 `Orders[].OBTNumber` 只抽其中幾張。
- **`FileNo` 有 24 小時期限（v2.1.2）**：自 `PrintOBT*` 成功時點起算，逾期 `FileNo` 失效（`E070`），**只能重新建單取得新單號**，不是重新下載。
- **`OBTStatus` 有硬性限流（v2.1.2）**：每契客每日 **3,000 次**、同時併發 **3 個**、**同一託運單號每 2 小時 1 次**。沒有快取層 + 佇列的輪詢會被擋。細節見 `references/api-reference.md` §11.0。
- **批次上限不一致**：`ParsingAddress` / `ParsingMedicalLocation` ≤ **100** 筆地址；`PrintOBT` / `PrintOBTByPickingList` / `PrintOBTByB2S` ≤ **100** 筆訂單；**`OBTStatus` ≤ 10 筆**（★ 但 `E078` 子訊息寫後台上限 500 筆，以規格書的 10 為準）；逆物流四支**皆為單筆**（`Order` 是物件不是陣列）。
- **`SenderZipCode` 是「六碼郵號」，要自己截**：`ParsingAddress` 回的 `PostNumber` 形如 `71-802-24-B`，**去掉連字號後取末六碼** → `80224B`。直接把 `PostNumber` 塞進 `SenderZipCode` 是最常見的整合錯誤。
- **到付與代收互斥**：`IsFreight='Y'` 時 `IsCollection` 不得為 `Y` 且 `CollectionAmount` 必須為 0。
- **報值門檻**：代收 ≤ 2 萬不提供報值；代收 > 2 萬時**代收金額 = 報值金額**。`DeclareAmount` 有效區間 `20000 < x ≤ 100000`。
- **代收上限依通路不同**：宅配 / 撿貨明細 `0 < CollectionAmount ≤ 100000`；**快速到店（B2S）`0 < CollectionAmount ≤ 20000`**。
- **`ProductName` 有 19 個禁用字串**（文件、發票、訂單…），命中直接被擋，見 `references/api-reference.md` §代碼表。
- **姓名欄位限用中文（不含生僻字）+ 英數 + 29 個允許符號**（附錄二）。**例外**：快速到店 `PrintOBTByB2S` 的姓名欄位**允許難字**、但只收中英數（不吃符號）。
- **電話欄位允許符號（v2.1.2 統一）**：數字 + 「-」「#」「,」，長度統一 `String(30)`（`RecipientTel` / `SenderTel` 四支端點一致）。手機一律 `String(10)` 且限 `09` 開頭。
- **國定假日與週日不配送**：`ShipmentDate` / `DeliveryDate` 需自行避開。
- **`DeliveryDate` 四條硬規則（v2.1.2 新增後三條）**：不可過去日期（`E088`）、**不可小於出貨日**（`E089`）、**不可與出貨日同一天**（`E090`）、**不可超過出貨日 7 天**（`E091`）。
- **逆物流 `TakeDate` 限 D+1 ~ D+7**，取件日當天遇速達休假日不提供服務；**v2.1.2 新增：隔日取件需於前一個上班日 16:30 前完成呼叫**。
- **逆物流 v2.1.2 起有郵碼欄位**：`ReserveAdd` / `ReserveEdit` 新增 `RecipientZipCode` / `SenderZipCode`（皆 `String(6)`、**選填**），`ReserveQuery` 回傳同兩欄。舊記載的「逆物流不需郵號」已失效。

---

## 服務端點速查

| 用途 | 環境 | 端點 URL |
|---|---|---|
| 印單 API（全部 12 支）| **正式-1** | `https://api.suda.com.tw/api/Egs/{服務名稱}` |
| 印單 API（全部 12 支）| **正式-2**（v2.1.2 新增）| `https://api.suda.net.tw:9443/api/Egs/{服務名稱}` |
| 印單 API（全部 12 支）| **測試** | `https://egs.suda.com.tw:8443/api/Egs/{服務名稱}` |
| 授權碼取得（契客專區）| — | `https://www.takkyubin.com.tw/YMTContract/aspx/Login.aspx` |
| 7-11 倉到店電子地圖（PC）| 正式 / 測試 | `https://appservice.ezcat.com.tw/Map.aspx` / `http://egs.suda.com.tw:8080/Map.aspx` |
| 7-11 倉到店電子地圖（手機）| 正式 / 測試 | `https://appservice.ezcat.com.tw/MobileMap.aspx` / `http://egs.suda.com.tw:8080/MobileMap.aspx` |
| 速達自取站地圖 | 正式 / 測試 | `http://103.234.81.15/TCAT/CAT0001/CAT0001MM1` / `http://61.57.232.216/TCAT/CAT0001/CAT0001MM1` |
| 官網貨態查詢（有單號）| — | `http://www.t-cat.com.tw/Inquire/TraceDetail.aspx?BillID={單號}` |
| 官網貨態查詢（只有訂單編號）| — | `https://www.takkyubin.com.tw/ymtcontract/Status/Check.aspx?CustID={客代}&CustPW={密碼}&OrderID={訂單編號}` |
| FTP 資料交換 | — | `103.234.81.11`（支援 FTP / FTPS 隱式 / SFTP，皆 PASV）|

**HTTP 規格**：Method `POST`、Protocol `HTTPS`、Encoding `UTF-8`、Content-Type `application/json`、TLS ≥ 1.2。

> ⚠️ **v1.9.3 的測試環境是 `http://apitestsuda.southeastasia.cloudapp.azure.com:8081`（Azure 臨時網域，且是 http）。v2.0.0 已換成 `https://egs.suda.com.tw:8443`。舊 code 若還指向 Azure 網域必須改。**
> ⚠️ **v2.1.2 新增「正式環境-2」`api.suda.net.tw:9443`**，官方未說明兩組正式環境的關係（負載平衡？異地備援？是否共用同一組授權碼與單號池）。**未確認前不要在兩者間隨機分流**，建議固定用正式-1，把正式-2 當手動 failover。

---

## 12 個 API 端點速查

| No | 服務名稱 | 用途 | 批次上限 | 回傳 |
|---|---|---|---|---|
| 1 | `ParsingAddress` | 查詢郵號（地址 → 六碼郵號）| 100 筆地址 | JSON |
| 2 | `PrintOBT` | 列印託運單（**宅配**）→ 配號 + `FileNo` | 100 筆訂單 | JSON |
| 3 | `PrintOBTByPickingList` | 列印託運單（**撿貨明細**，單上帶商品明細表）| 100 筆訂單 | JSON |
| 4 | `PrintOBTByB2S` | 列印託運單（**7-11 快速到店**）→ 另回 `DeliveryId` | 100 筆訂單 | JSON |
| 5 | `DownloadOBT` | 下載託運單（**binary PDF**）／補印 | 依 `FileNo` | **Binary** / JSON(400) |
| 6 | `Call` | 呼叫黑貓（線上叫車收貨）| 單次 | JSON |
| 7 | `ReserveAdd` | 逆物流-新增（退貨預約）| **單筆** | JSON |
| 8 | `ReserveEdit` | 逆物流-修改 | **單筆** | JSON |
| 9 | `ReserveDelete` | 逆物流-刪除 | **單筆** | JSON |
| 10 | `ReserveQuery` | 逆物流-單筆查詢（回 `Status` 01/99）| **單筆** | JSON |
| 11 | `OBTStatus` | 查詢託運單貨態（含歷程 `StatusList`）| **10 筆單號**＋**限流** | JSON |
| 12 | `ParsingMedicalLocation` | **查詢醫藥郵號及據點**（v2.0.9 新增，回 `PostCodeName`）| 100 筆地址 | JSON |

**三種列印端點的 `PrintOBTType` 值域各不相同**（極易混淆）：

| 端點 | `PrintOBTType` 可用值 |
|---|---|
| `PrintOBT`（宅配）| `01` A4 二模宅配 / `02` A4 三模宅配 / `03` 熱轉印宅配 |
| `PrintOBTByPickingList`（撿貨明細）| `01` A4 **三模**撿貨單 / `02` A4 **二模**撿貨單 ← **01/02 與宅配的模數相反** |
| `PrintOBTByB2S`（快速到店）| **v2.1.2 擴為 4 值**：`01` A4 三模 B2S (QR Code 版面) / `02` 熱轉印 B2S (QR Code 版面) / `03` A4 三模 B2S_QR Code 版面 / `04` 熱轉印 B2S_QR Code 版面 |

`PrintType`（列印類別）三支皆只有 `01`＝使用速達標準版型（**由系統分配託運單號**）。選 `01` 時 `OBTNumber` 一律填空字串。

---

## 最高頻用法：建單 → 取 PDF

```typescript
const BASE = 'https://api.suda.com.tw/api/Egs';   // 測試：https://egs.suda.com.tw:8443/api/Egs
const auth = { CustomerId: '1234567890', CustomerToken: '<授權碼>' };

// ── Step 1：地址轉郵號（六碼郵號要自己截末六碼）
const pa = await post('ParsingAddress', { ...auth, Addresses: [{ Search: '台北市重陽路200號' }] });
const raw = pa.Data.Addresses[0].PostNumber;          // 例：'71-802-24-B'
const senderZip = raw.replace(/-/g, '').slice(-6);    // → '80224B'  ★必做

// ── Step 2：配號 + 產單
const print = await post('PrintOBT', {
  ...auth,
  PrintType: '01',            // 速達標準版型，由系統配號
  PrintOBTType: '02',         // A4 三模宅配
  Orders: [{
    OBTNumber: '',            // PrintType=01 時留空，由系統配號
    OrderId: 'SN202012010001',// 不可含空白
    Thermosphere: '0001',     // 常溫
    Spec: '0001',             // 60cm
    ReceiptLocation: '01',    // 到宅
    ReceiptStationNo: '',     // 到宅時留空
    RecipientName: '王小明', RecipientTel: '02-87654321', RecipientMobile: '0912345678',
    RecipientAddress: '台北市中山區南京東路三段261號2樓',
    SenderName: '黑貓探險隊',  SenderTel: '02-87121298', SenderMobile: '0987654321',
    SenderZipCode: senderZip, SenderAddress: '台北市重陽路200號',
    ShipmentDate: '20260720', DeliveryDate: '20260721', DeliveryTime: '04',  // 04=不指定
    IsFreight: 'N', IsCollection: 'N', CollectionAmount: 0,
    IsSwipe: 'N', IsMobilePay: 'N', IsDeclare: 'N', DeclareAmount: 0,
    ProductTypeId: '0001',    // 一般食品
    ProductName: '澎湖XO干貝醬', // ★不可含「發票 / 訂單 / 文件」等 19 個禁用字串
    Memo: '易碎品，請小心平放',
  }],
});
if (print.IsOK !== 'Y') throw new Error(print.Message);   // ★HTTP 200 ≠ 成功
const { FileNo, Orders } = print.Data;                    // Orders[i].OBTNumber = 配到的 12 碼單號

// ── Step 3：下載 PDF（binary，不是 JSON）
const pdf = await postBinary('DownloadOBT', { ...auth, FileNo });
// 補印單張：{ ...auth, FileNo, Orders: [{ OBTNumber: '930005203251' }] }
```

> **`CollectionAmount` / `DeclareAmount` / `Quantity` / `Price` / `Amount` 是 `Int` 不是字串**——JSON 裡不要加引號（官方範例也是不加引號的）。其餘欄位皆為 String。

---

## 貨態查詢：三條路，代碼表兩套

| 路徑 | 適用 | 即時性 | 代碼系統 |
|---|---|---|---|
| 官網 URL（`TraceDetail.aspx` / `Check.aspx`）| 單筆、人工 | 即時 | 顯示文字，無代碼 |
| **`OBTStatus` API** | 單筆～10 筆 | **即時** | **二代**（`111`/`151`/`301`…，3 碼）|
| **FTP `.sod` 回檔** | 全量批次 | 每日 05:00 / 18:00 | **一代**（`00001`/`00006`/`00003`…，5 碼）|

⚠️ **二代與一代代碼不通用**，必須自建對照表才能把兩條路的貨態統一。完整 31 條二代 + 40 條一代 + 逐條對照，見 `references/cargo-status.md`。

⚠️ **v2.0.0 的附錄一從 v1.9.3 砍掉了 8 條異常碼**（`181` 送錯BASE / `182` 送錯CENTER / `241` 超大 / `242` 超重 / `243` 損壞 / `244` 遺失 / `245` 客樂得損毀 / `246` 客樂得遺失）。這些代碼**仍可能出現在真實回應中**——**不要把附錄當白名單**，未知代碼要 fallback 而非丟例外。
**v2.1.2 的附錄一與 v2.0.0 逐字相同**（已比對），8 條仍未回歸；附錄二 29 個允許符號亦未變。

---

## References 導引

| 需求 | 參閱檔案 |
|---|---|
| 查 12 個端點的完整 request / response 欄位表（名稱 / 型別長度 / 必填 / 說明）、JSON 範例、全部 12 張參數代碼表、19 個禁用字串、29 個允許符號 | `references/api-reference.md` |
| **91 組錯誤代碼 `E001`–`E091` 全表**、`E072`/`E078`/`E081` 後台子訊息、可重試 vs 不可重試分類 | `references/api-reference.md` §16 |
| **`OBTStatus` 限流規則與實作底線**（快取 / 佇列 / 每日計數 / 批次省額度）| `references/api-reference.md` §11.0 |
| **`FileNo` 24 小時期限**處理 | `references/api-reference.md` §5.0 |
| **`ParsingMedicalLocation`**（醫藥郵號 + 據點名稱）完整規格 | `references/api-reference.md` §12 |
| 貨態三路串接、**二代 31 條 + 一代 40 條貨態代碼表 + 逐條對照**、NOD 未集貨原因 14 條、建議的 `ShippingState` 映射 | `references/cargo-status.md` |
| FTP 連線（IP / port / 目錄 / 保留天數）、`.eod` 上拋 30 欄位、`.sod` / `.nod` / `.nom` / `.adj` / `.ret` 欄位表 + 真實範例逐行拆解、檔名規則、編碼陷阱 | `references/ftp-file-formats.md` |
| A4 二模 / A4 三模 / 熱轉印 / 撿貨單各聯版面尺寸（cm）、條碼型別與內容組成、樣張實測欄位 | `references/label-spec.md` |
| 7-11 快速到店倉到店電子地圖（PC / 手機，POST form 導轉）、速達營業站所自取地圖（querystring + 廠商 callback API）完整參數 | `references/cvs-map.md` |
| 可執行 TypeScript / NestJS 範例（client、建單、PDF 下載、地圖導轉、回檔 parser）+ 官方 MapSample.html 原文 | `references/code-examples.md` |
| **v2.0.0 → v2.1.2 逐項增量**、v1.9.3 → v2.0.0 變更、v1.0～v2.1.2 完整版次履歷、授權碼申請流程、環境 endpoint 遷移 | `references/version-notes.md` |

---

## 待澄清 / 待向統一速達確認

- **錯誤碼的回傳形式未定義**（v2.1.2）：附錄三給了 91 組 `E001`–`E091`，但**回應外殼仍只有 `Message`（`String(1000)`），沒有獨立的錯誤碼欄位**。代碼是隨 `Message` 一起回傳（如 `"E086 請求次數已達上限3000 次。"`）還是只是文件側的分類，**官方未說明**。上線前實測；在確認前，程式應同時支援「抓代碼前綴」與「比對訊息文字」兩種解析。
- **文件內部矛盾兩處**：(1) 逆物流隔日取件截止時間，`TakeDate` 說明與 `E072` 子訊息寫 **16:30**，`E065`/`E066` 寫 **17:30**；(2) `OBTStatus` 批次上限，規格書 request 寫 **10 筆**，`E078` 子訊息寫後台上限 **500 筆**。**兩者都取較嚴格值**，並向速達確認。
- **兩組正式環境的關係未說明**：`api.suda.com.tw` 與 `api.suda.net.tw:9443` 是負載平衡、異地備援還是不同機房？是否共用同一組授權碼與單號池？**未確認前不要隨機分流**。
- **B2S `PrintOBTType` `03`/`04` 與 `01`/`02` 的差異未說明**：四個值的描述都含「QR Code 版面」，字面只差括號與底線。已上線的整合維持 `01`/`02`。
- **`SrvTranId` 語意未定義**：僅說明是「平台交易序號 / 平台電文交易序號」，未說明是否可用於對帳、重送去重或客訴追查。冪等性（同一 `OrderId` 重複送出會配新單號還是回舊單號）**官方未說明**——上線前務必實測。
- **`FileNo` 期限已明確為 24 小時**（v2.1.2 解答），但**可重複下載幾次**仍未說明。
- **限流計數口徑未說明**：`OBTStatus` 每日 3,000 次是以「呼叫次數」還是「查詢單號數」計？一次帶 10 個單號算 1 次還是 10 次？**建議先按最壞情況（10 次）估算額度**。「同一單號 2 小時」的起算點是成功查詢還是任何呼叫，亦未說明。
- **v2.0.0 砍掉的 8 條貨態碼是否真的停用**：官方未說明是「不再產生」還是「僅從文件移除」。同期的 FTP 回檔文件（2023-08-22 版）也砍掉對應的 8 條一代碼，但舊版文件（2021-10-26）仍列出。**建議按仍會出現處理。**
- **一代 ↔ 二代貨態代碼對照官方未提供**：`references/cargo-status.md` 的對照表是**依「狀態說明」文字與異動批次比對推導**，非官方文件，上線前應以真實資料校準。
- **`.sod` 範例檔出現文件未列的 `00000 訂單處理中`**：官方代碼表（新舊版皆）無此碼，但範例檔實際存在。未知碼必須 fallback。
- **快速到店條碼組成規則未文件化**：樣張可觀察到多段條碼（門市店號 + 廠商編號 + 配送編號等），但官方無欄位組成說明，`references/label-spec.md` 標示為「樣張觀察」而非規格。
- **測試站台是否隔離**：測試環境 `egs.suda.com.tw:8443` 與正式環境授權碼分開發放，但測試單號是否會進入真實派送流程、`Call`（叫車）在測試站是否真的派車，官方未說明。**E2E 前務必確認。**
- **v2.1.2 §1.1「使用情境說明」（p.7–11）是純圖片**（流程圖），PDF 無可抽取文字，本 skill 未涵蓋其內容。需要看整合流程圖時請直接開 PDF p.7–11。
- **未取得檔案**：無。本資料夾 34 個檔案全數解析完成（PDF 7 / Word 10 / Excel 4 / PPT 1 / zip 1 / 純文字與範例回檔 11）。

---

**本 SKILL 為唯一官方 API reference 來源——不要再去翻 PDF、不要再搜尋 web。** 若確實需要原始文件，來源包位於 `features/.tmp/[物流]黑貓宅急便_自有系統串接_標準印單API參考文件_v2.0.0/`。
