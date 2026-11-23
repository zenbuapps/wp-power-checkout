---
name: hct-logistics
description: >
  新竹物流（HCT Logistics）出貨串接 EDI WebServices 完整 API 參考，對應官方文件 V15
  （2026-04-28）。涵蓋 SOAP `.asmx` 服務的全部 15 個操作：上傳託運資料 / 列印託運單
  （TransData）、修改重量（UpdData）、確認當日出貨 / 列印總表（TransReport）、查詢貨號
  （QueryEDELNO）、逆物流退貨（R_TransData_Json）、取消收貨 / 取消託運單
  （TransDataCancel_Json）、上傳需派遣收貨（TransDispatchOrder_Json），以及標籤 image
  hex 還原、Codabar/Code128/Code39 一維條碼與 QRcode 二維條碼規格、檢查碼演算法、集貨註區
  / 地址比對 API（addrCompare）、貨況查詢（searchGoods，僅舊版補充）。當需要串接新竹物流
  出貨、託運單建立 / 列印、貨號查詢、修改重量、逆物流退貨、取消託運單、派遣收貨、貨況查詢、
  集貨註區、地址比對，或繪製新竹物流出貨標籤時，必須使用此 skill，不要再去翻官方 PDF 或搜尋
  web。觸發詞：新竹物流、HCT、HCT Logistics、物流 API、EDI、WebServices、TransData、
  UpdData、TransReport、QueryEDELNO、R_TransData、TransDataCancel、TransDispatchOrder、
  出貨託運、託運單、標籤列印、貨號查詢、修改重量、逆物流退貨、取消託運單、派遣收貨、貨況查詢、
  集貨註區、addrCompare、edelno、erstno、image hex、Codabar、檢查碼、貨況代碼、status_code、
  searchGoods、貨物配送狀況、DES CBC、加解密、查貨。
---

# 新竹物流（HCT Logistics）EDI WebServices API 整合

新竹物流提供 **SOAP-based EDI WebService**（ASP.NET `.asmx` 端點；雖有 `_Json` / `_XML` 變體方法，但底層仍是 SOAP 呼叫——把 JSON/XML 字串塞進 SOAP body 參數）。整合涵蓋：

1. **出貨 / 託運 / 列印 EDI**（`edi_webservice2/service1.asmx`）— 上傳託運資料、取得新竹貨號 + 到著站 + 標籤圖、修改重量、確認出貨列印總表、查詢貨號、逆物流退貨、取消收貨、派遣收貨。**共 15 個 SOAP 操作。**
2. **出貨標籤規格** — 標籤欄位、Codabar/Code128/Code39 一維條碼、QRcode 二維條碼、檢查碼演算法、尺寸與列印設備。
3. **集貨註區 / 地址比對 API**（`WS_addrCompare1.asmx`）— 用地址比對到著站 / 註區 / 疊區 / QRcode 內容（**自繪標籤的補充來源；V15 已可由 TransData 回傳直接取得**）。
4. **貨況查詢服務**（`searchGoods_*`）— DES CBC 加解密規格 + zenbu 實際參數（offset `-33` / IV `<HCT_TRACK_IV>` / v）+ 貨況代碼（`status_code`）對應表**已補齊**，見 `references/cargo-tracking-decrypt.md`（V15 主文件未收錄；取自查貨串接說明 Ver3.1 2026/06/11 + zenbu 申請後文件）。

> **文件版本**：主來源 = 新竹物流出貨串接 WebServices 文件 **V15（2026-04-28）**。補充來源 = API 服務說明 V2.0（2022/12/30，提供 V15 已移除的「貨況查詢」）、標籤規格說明 V2.42（2023/04/14，提供 V15 以圖片呈現的「條碼尺寸規格」與獨立的「集貨註區 addrCompare API」）。**凡 V15 有講到的，一律以 V15 為準。**

---

## V15 相對舊版的關鍵變更（diff 視角）

| 變更 | 說明 |
|---|---|
| **DEV / PROD URL 正式分離** | V15 明列 PROD `https://hctrt.hct.com.tw/edi_webservice2/service1.asmx`、DEV `https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx`（舊版只給單一 URL）。 |
| **新增 2 個方法** | `TransDataCancel_Json()`（取消收貨 / 取消託運單）、`TransDispatchOrder_Json()`（上傳需派遣收貨）。舊版只有 5 個方法家族。 |
| **TransData 回傳擴充為 R1–R20** | 新增 `NewOutArea`、`eqmny`、`CODE1`–`CODE5`、`CODE7`(QRcode)、`AREAS`、`MDCODE1`–`MDCODE3`——**標籤所需的到著碼 / QRcode 內容現可直接由 TransData 取得，不必再呼叫獨立 addrCompare API**。 |
| **`eprdct` 補進 JSON/XML 欄位表** | 傳票類別（field 18，String(2)）：`11` 月結 / `21` 到付 / `31` 現收 / **`41` 代收（COD）**。舊版僅 DataSet 版列出，JSON/XML 欄位表缺漏——V15 補上，**解掉舊「待澄清」**。 |
| **新增跨境業務欄位** | TransData request 加 `HAWB`(小提單號,必須) / `boxNo`(報關箱號) / `MAWB`(大提單號) / `Declare`(報單號碼)。 |
| **新增回單張數欄位** | TransData request 加 `erenum`（回單張數，String(2)，預設 0）。 |
| **`erstno` 正名** | TransData Return 的到著站欄位 V15 正式命名 `erstno`（舊版 DataSet 欄位表筆誤寫成第二個 `epino`）——**解掉舊筆誤**。 |
| **QRcode 欄位改名** | TransData inline 回傳的 QRcode 欄位在 V15 是 `CODE7`（R16）；獨立 addrCompare API 仍用 `CODE6`。**留意兩處名稱不同。** |
| **貨況查詢（searchGoods）移除** | V15 完全不含貨況查詢 / 加解密 / 貨況對應表。**加解密 DES CBC 規格 + zenbu 參數 + `status_code` 對應表（62 碼）見 `references/cargo-tracking-decrypt.md`**（Ver3.1 + zenbu 申請後文件）；舊版結構補充見 `references/cargo-status-and-addr-compare.md`（V2.0）。 |

> **仍未由 V15 解決的待澄清**：SOAP **WSDL / namespace / SOAPAction** V15 仍未明載（只給 Content-Type=`text/xml; charset=utf-8`、POST、HTTPS、UTF-8）。貨況查詢**加解密金鑰**原「申請後提供」**已補齊**（DES CBC + zenbu 參數 + 已驗證 test vector，見 `references/cargo-tracking-decrypt.md`）。詳見最底「待澄清 / 待向新竹物流確認」+ `references/version-notes.md`（含實打 WSDL 的觀察）。

---

## 重要前提（不可遺漏）

- **認證 = 公司名稱 + 密碼**，以 SOAP 方法的**前兩個參數**傳入（測試帳號：公司名稱 `test`、密碼 `test1`）。**無 API key / token / OAuth / 簽章。**
- **參數命名大小寫敏感**：多數方法第 1、2 參數命名為 `company` / `password`，第 3 參數為 `json` / `xml`。**例外**：`TransReport` 系列第 3 參數為 `dsCusJson` / `dsCusXML`（DataSet 版為 `dsCus`）。詳見 `references/api-reference.md`。
- **批次上限**：一批請 **≤ 30 筆**；若使用回傳圖檔（`image`），**一次上限 5 筆**。
- **`image` 是 hex 字串**：`TransData` 回傳的 `image` 是新竹算好的**完整標籤圖**（1bit 黑白 Bitmap，hex 編碼）。1 張圖約 2 萬字元，需調大 SOAP/JSON config 的 `maxReceivedMessageSize` / `maxStringContentLength`。
- **標籤上的到著碼 / QRcode 來源（V15 起有二）**：
  1. **直接用 TransData 回傳**：`image`（完整標籤圖）或 `CODE2`–`CODE5` / `CODE7` / `MDCODE1`–`MDCODE3`（自繪用，V15 新增 inline 回傳）。
  2. **獨立 addrCompare API**（舊機制，仍可用）：呼叫集貨註區 `WS_addrCompare1.asmx` 取 `CODE2`–`CODE6` / `MDCODE1`–`MDCODE3`。
- **逆物流回傳 JSON 用大寫 key**：`R_TransData_Json` 回傳的 key 為 `Num` / `Success` / `Edelno` / `Epino` / `Erstno` / `Eqamt` / `ErrMsg`（首字大寫），與正物流 `TransData_Json`（小寫 `success` / `edelno`…）不同，解析需留意。
- **URL/GET 模式傳中文需 Big5 編碼**（僅 addrCompare 的 `Addr_Compare.aspx`）；SOAP/JSON/XML 模式用 UTF-8。

---

## 服務端點速查

| 服務 | 環境 | 端點 URL | 協定 |
|---|---|---|---|
| 出貨 / 託運 / 列印 EDI | **PROD** | `https://hctrt.hct.com.tw/edi_webservice2/service1.asmx` | SOAP（含 `_Json` / `_XML` 變體）|
| 出貨 / 託運 / 列印 EDI | **DEV** | `https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx` | SOAP |
| 集貨註區比對（SOAP）| — | `http://is1fax.hct.com.tw/Webedi_Erstno_NEW2/WS_addrCompare1.asmx` | SOAP（`addrCompare_string` / `addrCompare_Json`）|
| 集貨註區比對（URL/GET）| — | `http://is1fax.hct.com.tw/Webedi_Erstno_New2/Addr_Compare.aspx` | GET（中文 Big5）|
| 貨況查詢（單筆網頁，舊版）| — | `https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no={加密字串}&v={ver}` | GET |
| 貨況查詢（多筆 XML，舊版）| — | `https://hctapiweb.hct.com.tw/phone/searchGoods_Main_Xml.ashx?no={加密XML}&v={ver}` | POST |

**HTTP 規格（V15 明載）**：Method `POST`、Protocol `HTTPS`、Encoding `UTF-8`、Content-Type `text/xml; charset=utf-8`。

---

## 15 個 SOAP 操作速查（EDI WebService）

| No | SOAP 方法 | 用途 | 格式 |
|---|---|---|---|
| 1–3 | `TransData()` / `TransData_Json()` / `TransData_XML()` | 上傳託運資料（列印託運單）→ 回貨號 + 到著站 + 標籤圖 + 到著碼 | DataSet / JSON / XML |
| 4–6 | `UpdData()` / `UpdData_Json()` / `UpdData_Xml()` | 修改重量（傳新竹貨號 + 訂單編號）| DataSet / JSON / XML |
| 7–9 | `TransReport()` / `TransReport_Json()` / `TransReport_XML()` | 確定出貨資料（當日確認出貨；立即上傳）| DataSet / JSON / XML |
| 10–12 | `QueryEDELNO()` / `QueryEDELNO_Json()` / `QueryEDELNO_Xml()` | 查詢已取得貨號（以訂單編號查是否曾上傳）| DataSet / JSON / XML |
| 13 | `R_TransData_Json()` | 上傳逆物流資料（退貨）| **JSON only** |
| 14 | `TransDataCancel_Json()` | 上傳取消收貨資料（正物流，傳貨號 + 訂單編號取消收貨）| **JSON only** |
| 15 | `TransDispatchOrder_Json()` | 上傳需派遣收貨資料 | **JSON only** |

**貨號唯一性規則**：
- 客戶可選擇上傳時一併帶新竹貨號（貨號區間及規則需洽新竹資訊人員），或由回傳值取得。
- **訂單編號 `epino`**：只帶訂單編號時，同一出貨日期 `esdate` 內不可重複；帶「新竹貨號 + 訂單編號」時，同一 `esdate` 可使用相同訂單編號。
- **新竹貨號 `edelno`**：通常循環使用，**100 天內不可重複**（逆物流為 **3 個月**）。
- **資料修改**：相同「新竹貨號 + 訂單編號」再上傳 → 視同更正資料內容。新竹背景每小時上傳一次；需即時上傳可呼叫 `TransReport()`（執行後出貨資料不可再改）。
- **資料刪除**：物流士未收到貨件，資料 30 天後自動視為無效，不須刪除。
- **欄位內容避免**：`&`、`"`（雙引號）、`<`、`>`、`'`（單引號）。

---

## 最高頻用法：建立託運單並取得標籤（TransData_Json）

```typescript
// 最小必填欄位：epino, ercsig, ertel1, eraddr, ejamt, eqamt
const payload = [{
  epino:  'A00001',          // 訂單編號（必填，String(30)）
  ercsig: '王小明',           // 收貨人名稱（必填，String(40)）
  ertel1: '0911123456',      // 收貨人電話1（必填，String(20)；IVR 隱碼格式 10碼#隨機碼）
  eraddr: '台中市大雅區中清路三段513號', // 收貨人地址（必填，String(100)）
  ejamt:  '1',               // 件數（必填，最小1；總長運費件數只能1）
  eqamt:  '10',              // 重量 kg（必填，依客戶運價單位；小數進位整數）
  eprdct: '11',              // 傳票類別 11月結/21到付/31現收/41代收(COD)（預設11）
  eprdcl2:'001',             // 商品種類 001一般/003冷凍/008冷藏（預設001）
}];

// 透過 SOAP 呼叫 TransData_Json(company, password, json)
// 回傳每筆含：success(Y新增/R修改/N失敗)、edelno(新竹貨號10碼)、erstno(到著站號)、
//   image(標籤hex字串)、以及自繪標籤用的 CODE1~CODE5/CODE7(QRcode)/MDCODE1~3
```

> **`image` 是 hex 編碼的 1bit 黑白 Bitmap**，需 hex→byte→Bitmap 還原（見 `references/code-examples.md` 的 TypeScript `hctImageHexToBuffer()` 與官方 C# `GetBytes/HexToByte/image`）。**帶 `image` 一次上限 5 筆**；一般一批 ≤ 30 筆。

---

## 出貨標籤規格速查

| 項目 | 規格 |
|---|---|
| 物流單號條碼 | **Codabar**，純數字 10 碼，前後用 `D` 包起來（`D{10碼}D`）；高 15mm 寬 54mm 密度 0.4mm 粗細比 2.5 倍 |
| 物流單號 | 10 碼 = 物流單號本體 9 碼 + 檢查碼 1 碼；**檢查碼 = MOD(前9碼, 7)**（前 9 碼當整數除以 7 的餘數，0~6）|
| QRcode | Version 1、21×21 modules、ECC Level M、密度 0.8mm、16.8×16.8mm、淨空區 3.2mm；內容 = `CODE7`(TransData) 或 `CODE6`(addrCompare)|
| 到著碼 | CODE2 到著簡碼(3碼,靠右) / CODE3 衛星區(1碼) / CODE4 註區(3碼,黑底白字) / CODE5 疊區(1碼)|
| MD 到著碼 | MDCODE1 衛星區(1碼) / MDCODE2 註區(3碼,黑底白字) / MDCODE3 疊區(1碼)|
| 列印設備 | 雷射印表機或熱感標籤機 |

備援一維條碼：**Code128** 高15mm 寬38mm 密度0.43mm；**Code39** 高15mm 寬70mm 密度0.4mm 粗細比2.5。完整 24 欄位標籤規格見 `references/label-spec.md`。

---

## References 導引

| 需求 | 參閱檔案 |
|------|---------|
| 查 15 個 SOAP 方法的完整欄位（名稱 / 型別長度 / 必填 / 說明）、回傳結構、JSON/XML 請求回應範例、錯誤明細表 | `references/api-reference.md` |
| 出貨標籤完整規格（24 欄位、一維 / 二維條碼尺寸、檢查碼演算法、列印設備、自繪檢核清單）| `references/label-spec.md` |
| 集貨註區 / 地址比對 API（SOAP + URL）完整欄位 + CODE1~6 / MDCODE 對照、貨況查詢（searchGoods）+ 貨況對應表（V15 已移除，取自舊版）| `references/cargo-status-and-addr-compare.md` |
| **貨況查詢（searchGoods）DES CBC 加解密**：演算法規格、zenbu 實際參數（offset `-33` / IV `<HCT_TRACK_IV>` / v）、官方 C# + 已驗證 Node 範例、OpenSSL 3 legacy 陷阱、已對拍 test vector、**`status_code` 貨況代碼對應表（62 碼）+ `ShippingState` 建議映射（FU-4）**| `references/cargo-tracking-decrypt.md` |
| TypeScript / NestJS 整合範例（SOAP client 手組 envelope、TransData service、image hex 還原、Codabar 檢查碼）+ 官方 C# image function | `references/code-examples.md` |
| V15 vs 舊版逐項變更、舊「待澄清」解決狀態、實打 WSDL（`Service1.asmx?wsdl`）觀察 vs V15 文件一致性 | `references/version-notes.md` |

---

## 待澄清 / 待向新竹物流確認

V15 已解掉舊版多數缺口（`eprdct` JSON 欄位、DEV/PROD URL、`erstno` 正名、取消 / 派遣方法）。**仍懸而未決**：

- **SOAP WSDL / namespace / SOAPAction**：V15 仍未明載 target namespace、SOAPAction、完整 WSDL，只給 Content-Type=`text/xml; charset=utf-8`。實作前應抓 `?wsdl`（`service1.asmx?wsdl`）確認。**實打 WSDL 的觀察（namespace、SOAPAction、未文件化方法）見 `references/version-notes.md`。**
- ~~**貨況查詢加解密演算法與金鑰**~~ **已解決**：`searchGoods_*` 的 `no` = **DES CBC**（KEY = 查詢當日 ± n 天的 `yyyyMMdd`、IV = 8 位固定、PKCS7、輸出 Base64）、`v` = 32 位固定識別碼。zenbu 實際參數（n=`-33` / IV=`<HCT_TRACK_IV>` / v=`<HCT_TRACK_V>`）+ 官方 C# / 已驗證 Node 範例 + 對拍 test vector + `status_code` 對應表，全見 `references/cargo-tracking-decrypt.md`。⚠️ Node 22/OpenSSL 3 跑 `des-cbc` 需注意 legacy provider（同檔 §2）。剩餘僅「以真實回傳資料校準 `status_code`→`ShippingState` 映射」上線前 follow-up。
- **GetImage_Json / onlyImage / FrightQuery / TransDispatchOrder 細節**：V15 **未收錄** `GetImage_Json` / `onlyImage`（專門取圖）/ `FrightQuery`（疑似貨況查詢替代）；但**已收錄** `TransDataCancel_Json` / `TransDispatchOrder_Json`。實打 live WSDL 另有這些未文件化方法（見 `references/version-notes.md`）。
- **正式 vs 測試環境帳號**：V15 明確分離 DEV/PROD URL，但測試帳號（出貨 `test`/`test1`、集貨註區 `TEST2`）是否打到正式系統未說明，整合 E2E 前建議向新竹確認。
