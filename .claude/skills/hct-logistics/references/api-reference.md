# 新竹物流 出貨串接 EDI WebServices 完整 API 參考

主來源：**新竹物流出貨串接 WebServices 文件 V15（2026-04-28）**。
凡 V15 有的以 V15 為準；V15 已移除的「貨況查詢（searchGoods）」見 `cargo-status-and-addr-compare.md`。

## 目錄（TOC）

- [0. 服務總覽](#0-服務總覽)
- [1. TransData — 上傳託運資料（建立 / 列印託運單）](#1-transdata--上傳託運資料建立--列印託運單)
- [2. UpdData — 修改重量](#2-upddata--修改重量)
- [3. TransReport — 立即上傳（確認當日出貨 / 列印總表）](#3-transreport--立即上傳確認當日出貨--列印總表)
- [4. QueryEDELNO — 查詢貨號](#4-queryedelno--查詢貨號)
- [5. R_TransData_Json — 逆物流傳入託運資料（退貨）](#5-r_transdata_json--逆物流傳入託運資料退貨)
- [6. TransDataCancel_Json — 取消收貨資料（取消託運單）](#6-transdatacancel_json--取消收貨資料取消託運單)
- [7. TransDispatchOrder_Json — 上傳需派遣收貨資料](#7-transdispatchorder_json--上傳需派遣收貨資料)
- [8. 出貨總表（列印參數）](#8-出貨總表列印參數)
- [9. 錯誤原因明細（Error Message）](#9-錯誤原因明細error-message)

---

## 0. 服務總覽

**EDI WebService URL（V15）**：
```
PROD: https://hctrt.hct.com.tw/edi_webservice2/service1.asmx
DEV : https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx
```

**HTTP 規格**：Method `POST`、Protocol `HTTPS`、Encoding `UTF-8`、Content-Type `text/xml; charset=utf-8`（必填 header）。

所有方法為 SOAP（ASP.NET `.asmx`），多數方法有三種資料格式變體：原生 `DataSet`（.NET 專用）/ `_Json`（傳入回傳 JSON 字串）/ `_XML`（傳入回傳 XML 字串）。`R_TransData_Json` / `TransDataCancel_Json` / `TransDispatchOrder_Json` 僅 JSON 版。

**認證**：方法**前兩個參數** `company`（公司名稱）+ `password`（密碼）。測試帳號：`company="test"`、`password="test1"`。

**參數命名（大小寫敏感）**：
- 多數方法第 3 參數：`json`（`_Json`）/ `xml`（`_XML`）/ `data`（DataSet）。
- **`TransReport` 例外**：第 3 參數為 `dsCusJson`（`_Json`）/ `dsCusXML`（`_XML`）/ `dsCus`（DataSet）。

**15 個 SOAP 操作清單**：

| No | 方法 | 用途 |
|---|---|---|
| 1 | `TransData()` | 上傳託運資料（列印託運單）|
| 2 | `TransData_Json()` | 同上，JSON |
| 3 | `TransData_XML()` | 同上，XML |
| 4 | `UpdData()` | 修改重量（傳新竹貨號 + 訂單編號）|
| 5 | `UpdData_Json()` | 同上，JSON |
| 6 | `UpdData_Xml()` | 同上，XML |
| 7 | `TransReport()` | 確定出貨資料（當日確認出貨）|
| 8 | `TransReport_Json()` | 同上，JSON |
| 9 | `TransReport_XML()` | 同上，XML |
| 10 | `QueryEDELNO()` | 查詢已取得貨號（用訂單編號查）|
| 11 | `QueryEDELNO_Json()` | 同上，JSON |
| 12 | `QueryEDELNO_Xml()` | 同上，XML |
| 13 | `R_TransData_Json()` | 上傳逆物流資料（退貨，JSON only）|
| 14 | `TransDataCancel_Json()` | 上傳取消收貨資料（正物流，JSON only）|
| 15 | `TransDispatchOrder_Json()` | 上傳需派遣收貨資料（JSON only）|

**貨號唯一性規則**：
- 客戶可選擇上傳時一併帶新竹貨號（貨號區間及規則洽新竹資訊人員），或由回傳值取得。
- 訂單編號 `epino`：只帶訂單編號 → 同一出貨日 `esdate` 內不可重複；帶「新竹貨號 + 訂單編號」→ 同一 `esdate` 可重複訂單編號。
- 新竹貨號 `edelno`：通常循環使用，**100 天內不可重複**（逆物流 **3 個月**）。
- 相同「新竹貨號 + 訂單編號」再上傳 = 視同更正。背景每小時上傳；需即時 → `TransReport()`（之後不可再改）。
- 物流士未收貨件，資料 30 天後自動視為無效，不須刪除。
- 欄位內容避免：`&` `"`（雙引號）`<` `>` `'`（單引號）。

**重量計價說明**：新竹重量計價方式有 **重量(KG) / 材積(才) / 總長**，依各客戶代號簽約運價提供相對應值。**總長**運費的重量只能傳 `60`/`90`/`120`/`150`/`151-999`，且件數只能傳 `1`。

**傳票類別說明（`eprdct`）**：`11` 元付月結（出貨人主付運費）/ `21` 到付（收貨人付運費）/ `31` 現收（出貨人現金付運費）/ `41` 代收（出貨人付運費，需向收貨人收取 COD 金額）。

**超商店取貨件說明**：收貨人地址 `eraddr` 一定要是超商地址；收貨人電話 `ertel1` 一定要是手機；備註 `emark` 要含關鍵字：`全家` / `OK` / `萊爾富` / `7-11超商`。

**來回件說明**：在備註 `emark` 傳入「來回件」三個字即可。

---

## 1. TransData — 上傳託運資料（建立 / 列印託運單）

### 方法簽章

```csharp
DataSet TransData     (string company, string password, DataSet data)  // 使用 DataSet.DataTable[0]
string  TransData_Json(string company, string password, string json)
string  TransData_XML (string company, string password, string xml)
```

> 「預設值」欄位若有傳入值，優先使用 DataTable / 傳入值內資料。
> **注意事項**：一批 ≤ 30 筆；若使用回傳圖檔（`image`），一次上限 **5 筆**。

### 傳入欄位（Data，26 欄 — V15 完整）

| No | 欄位 | 說明 | 長度 | 必填 / 備註 |
|---|---|---|---|---|
| 1 | `epino` | 訂單編號 | String(30) | **必要欄位** |
| 2 | `ercsig` | 收貨人名稱 | String(40) | **必要欄位** |
| 3 | `ertel1` | 收貨人電話 1 | String(20) | **必要欄位**（IVR 隱碼電話請輸入 10 碼隱碼電話 `#` 隨機碼，例 `0900000123#12345678`）|
| 4 | `ertel2` | 收貨人電話 2 | String(20) | 選填 |
| 5 | `eraddr` | 收貨人地址 | String(100) | **必要欄位** |
| 6 | `ejamt` | 件數 | String(4) | **必要欄位**（最小為 1；總長運費件數只能 1）|
| 7 | `eqamt` | 重量 | String(5) | 依客戶運價單位設定，未提供則用帳號預設值（小數進位到整數）；總長重量只能 60/90/120/150/151-999 |
| 8 | `esdate` | 出貨日期 | String(8) | 預設今天（`YYYYMMDD`）|
| 9 | `escsno` | 客戶代號 | String(11) | 預設值 |
| 10 | `esstno` | 出貨站 | String(4) | 預設值 |
| 11 | `edelno` | 新竹貨號 | String(10) | 預設值（如無提供系統自行配號）|
| 12 | `etcsig` | 出貨人名稱 | String(40) | 預設值 |
| 13 | `ettel1` | 出貨人電話 1 | String(15) | 預設值 |
| 14 | `ettel2` | 出貨人電話 2 | String(15) | 預設值 |
| 15 | `etaddr` | 出貨地址 | String(100) | 預設值（影響貨件拒收退回的退回地址）|
| 16 | `eddate` | 指定日期 | String(8) | 預設空白（小於今日代空白）|
| 17 | `eqmny` | 代收貨款 | String(5) | 預設值 0 |
| 18 | `eprdct` | 傳票類別 | String(2) | 預設月結 `11`（到付 `21`、現收 `31`、**代收 `41`**）|
| 19 | `emark` | 備註 | String(100) | 如為來回件請填「來回件」|
| 20 | `eprdcl2` | 商品種類 | String(3) | `001` 一般 / `003` 冷凍 / `008` 冷藏；預設 `001` |
| 21 | `egamt` | 報值金額 | String(5) | 保值貨物應報值，加收所報值金額 1% 為報值費 |
| 22 | `erenum` | 回單張數 | String(2) | 需要回單的張數；使用此欄位的客戶代號需有設定「全部回單」或「部分回單」|
| 23 | `HAWB` | 小提單號 | String(50) | 跨境業務記錄（**必須**）|
| 24 | `boxNo` | 報關箱號 | String(50) | 跨境業務記錄 |
| 25 | `MAWB` | 大提單號 | String(50) | 跨境業務記錄 |
| 26 | `Declare` | 報單號碼 | String(50) | 跨境業務記錄 |

### 回傳欄位（Return，R1–R20 — V15 完整）

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `Num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **Y** 新增 / **R** 修改 / **N** 失敗 |
| R3 | `edelno` | 新竹貨號 | String(10) | 10 碼含檢查碼 |
| R4 | `epino` | 訂單編號 | String(20) | |
| R5 | `erstno` | 到著站號碼 | String(3) | （舊 DataSet 版欄位表筆誤寫成第二個 `epino`，V15 正名 `erstno`）|
| R6 | `eqamt` | 重量 | String(5) | |
| R7 | `image` | 標籤圖片字串 | — | hex 編碼的 1bit 黑白 Bitmap（還原見 `code-examples.md`）|
| R8 | `ErrMsg` | 異常錯誤訊息 | String(40) | |
| R9 | `NewOutArea` | 新集貨註區 | String(10) | |
| R10 | `eqmny` | 代收貨款 | String(5) | |
| R11 | `CODE1` | 類別 | String(1) | 自繪標籤用 |
| R12 | `CODE2` | 到著簡碼 | String(3) | 標籤欄位（靠右）|
| R13 | `CODE3` | 衛星區 | String(1) | |
| R14 | `CODE4` | 註區 | String(3) | 標籤請印黑底白字 |
| R15 | `CODE5` | 疊區 | String(1) | |
| R16 | `CODE7` | QRcode | String(11) | **QRcode 內容**（注意：此處名 `CODE7`，獨立 addrCompare API 名 `CODE6`）|
| R17 | `AREAS` | 聯運區提醒 | String(1) | `*` 表示有聯運區，空白表示沒有 |
| R18 | `MDCODE1` | MD 到著碼衛星區 | String(1) | |
| R19 | `MDCODE2` | MD 到著碼註區 | String(3) | 標籤請印黑底白字 |
| R20 | `MDCODE3` | MD 到著碼疊區 | String(1) | |

> **config 調整（image）**：
> - `TransData`（DataSet/SOAP）一次接收 2 張以上圖片，需把 config `maxBufferSize="65536"`、`maxReceivedMessageSize="65536"` 的 65536 改大（1 張圖轉 string 約 2 萬多字元）。
> - `TransData_JSON`（JSON 接收圖檔字串），需把 config `readerQuotas` → `maxStringContentLength="8192"` 的 8192 改大。

### JSON 範例

**傳入（V15 原文）：**
```json
[
  {
    "epino": "A00001",
    "ercsig": "Mary",
    "ertel1": "0911123456",
    "eraddr": "台中市大雅區中清路三段513號",
    "ejamt": "1",
    "eqamt": "10"
  },
  {
    "epino": "A00002",
    "ercsig": "Mary",
    "ertel1": "0911123456",
    "eraddr": "台中市大雅區中清路三段513號",
    "ejamt": "1",
    "eqamt": "5"
  }
]
```

**回傳（依 V15 範例重建；CJK 類別字見下方註）：**
```json
[
  {
    "Num": "1",
    "success": "Y",
    "edelno": "1001734834",
    "epino": "A00001",
    "erstno": "宅4",
    "eqamt": "10",
    "image": "...",
    "ErrMsg": "",
    "NewOutArea": "宅-4004--900- 900",
    "eqmny": "",
    "CODE1": "宅",
    "CODE2": "宅4",
    "CODE3": "",
    "CODE4": "900",
    "CODE5": "",
    "CODE7": "^004900900^",
    "AREAS": "",
    "MDCODE1": "",
    "MDCODE2": "900",
    "MDCODE3": ""
  },
  {
    "Num": "2",
    "success": "Y",
    "edelno": "1001734845",
    "epino": "A00002",
    "erstno": "宅4",
    "eqamt": "5",
    "image": "...",
    "ErrMsg": "",
    "NewOutArea": "宅-4004--900- 900",
    "eqmny": "",
    "CODE1": "宅",
    "CODE2": "宅4",
    "CODE3": "",
    "CODE4": "900",
    "CODE5": "",
    "CODE7": "^004900900^",
    "AREAS": "",
    "MDCODE1": "",
    "MDCODE2": "900",
    "MDCODE3": ""
  }
]
```

> **CJK 類別字註**：V15 PDF 的範例值含全形類別字（如 `宅` = 宅配），文字萃取常被丟失或顯示為空白。上方 `CODE1`/`CODE2`/`erstno`/`NewOutArea` 的 `宅` 為依語意重建。**實際值以呼叫回傳為準**。同一筆 XML 範例的 `<erstno>` 是純數字站碼 `4004`（4 碼，雖欄位長度標 String(3)）——JSON 與 XML 範例對相同欄位的呈現略有差異（JSON 帶類別字前綴、XML 為純數字站碼），整合時建議兩種皆容錯解析。

### XML 範例

**傳入：**
```xml
<info>
  <DataRow>
    <epino>A00001</epino>
    <ercsig>Mary</ercsig>
    <ertel1>0911123456</ertel1>
    <eraddr>台中市大雅區中清路三段513號</eraddr>
    <ejamt>1</ejamt>
    <eqamt>10</eqamt>
  </DataRow>
  <DataRow>
    <epino>A00002</epino>
    <ercsig>Mary</ercsig>
    <ertel1>0911123456</ertel1>
    <eraddr>台中市大雅區中清路三段513號</eraddr>
    <ejamt>1</ejamt>
    <eqamt>5</eqamt>
  </DataRow>
</info>
```

**回傳（依 V15 範例，含全部 R1–R20；CJK 類別字依語意重建）：**
```xml
<info>
  <DataRow>
    <Num>1</Num>
    <success>Y</success>
    <edelno>0000000001</edelno>
    <epino>A00001</epino>
    <erstno>4004</erstno>
    <eqamt>10</eqamt>
    <image>……</image>
    <ErrMsg></ErrMsg>
    <NewOutArea>宅0049000</NewOutArea>
    <eqmny></eqmny>
    <CODE1>宅</CODE1>
    <CODE2>宅4</CODE2>
    <CODE3></CODE3>
    <CODE4>900</CODE4>
    <CODE5>0</CODE5>
    <CODE7>^004900900^</CODE7>
    <AREAS>*</AREAS>
    <MDCODE1></MDCODE1>
    <MDCODE2>900</MDCODE2>
    <MDCODE3></MDCODE3>
  </DataRow>
  <DataRow>
    <Num>2</Num>
    <success>Y</success>
    <edelno>0000000002</edelno>
    <epino>A00002</epino>
    <erstno>4004</erstno>
    <eqamt>5</eqamt>
    <image>……</image>
    <ErrMsg></ErrMsg>
    <NewOutArea>宅0049000</NewOutArea>
    <eqmny></eqmny>
    <CODE1>宅</CODE1>
    <CODE2>宅4</CODE2>
    <CODE3></CODE3>
    <CODE4>900</CODE4>
    <CODE5>0</CODE5>
    <CODE7>^004900900^</CODE7>
    <AREAS>*</AREAS>
    <MDCODE1></MDCODE1>
    <MDCODE2>900</MDCODE2>
    <MDCODE3></MDCODE3>
  </DataRow>
</info>
```

---

## 2. UpdData — 修改重量

### 方法簽章

```csharp
DataSet UpdData     (string company, string password, DataSet data)
string  UpdData_Json(string company, string password, string json)
string  UpdData_XML (string company, string password, string xml)
```

### 傳入欄位

| No | 欄位 | 說明 | 長度 | 必填 |
|---|---|---|---|---|
| 1 | `epino` | 訂單編號 | String(30) | **必要欄位** |
| 2 | `edelno` | 新竹貨號 | String(10) | **必要欄位** |
| 3 | `eqamt` | 重量 | String(5) | **必要欄位** |

### 回傳欄位

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `Num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **R** 修改 / **N** 失敗 |
| R3 | `edelno` | 新竹貨號 | String(10) | |
| R4 | `epino` | 訂單編號 | String(20) | |
| R5 | `eqamt` | 重量 | String(5) | |
| R6 | `ErrMsg` | 異常錯誤訊息 | String(40) | |

### JSON 範例

**傳入：**
```json
[
  { "epino": "A00001", "edelno": "1234567890", "eqamt": "10" },
  { "epino": "A00002", "edelno": "1234567894", "eqamt": "5" }
]
```

**回傳：**
```json
[
  { "Num": "1", "success": "Y", "edelno": "0000000001", "epino": "A00001", "eqamt": "10", "ErrMsg": null },
  { "Num": "2", "success": "Y", "edelno": "0000000002", "epino": "A00002", "eqamt": "5", "ErrMsg": null }
]
```

### XML 範例

**傳入：**
```xml
<info>
  <DataRow><epino>A00001</epino><edelno>1234567890</edelno><eqamt>10</eqamt></DataRow>
  <DataRow><epino>A00002</epino><edelno>1234567894</edelno><eqamt>5</eqamt></DataRow>
</info>
```

**回傳：**
```xml
<info>
  <DataRow><Num>1</Num><success>R</success><edelno>0000000001</edelno><epino>A00001</epino><eqamt>10</eqamt><ErrMsg></ErrMsg></DataRow>
  <DataRow><Num>2</Num><success>R</success><edelno>0000000002</edelno><epino>A00002</epino><eqamt>5</eqamt><ErrMsg></ErrMsg></DataRow>
</info>
```

---

## 3. TransReport — 立即上傳（確認當日出貨 / 列印總表）

當日確認出貨時呼叫（立即上傳出貨資料，**之後不可再修改**）。除列印標籤外，仍需列印出貨總表（**一式二份**）供現場交接貨件使用。

### 方法簽章（注意第 3 參數命名特殊）

```csharp
DataSet TransReport     (string company, string password, DataSet dsCus)
string  TransReport_Json(string company, string password, string dsCusJson)
string  TransReport_XML (string company, string password, string dsCusXML)
```

### 傳入欄位

| No | 欄位 | 說明 | 長度 | 必填 |
|---|---|---|---|---|
| 1 | `epino` | 訂單編號 | String(30) | **必要欄位** |
| 2 | `edelno` | 新竹貨號 | String(10) | **必要欄位** |

### 回傳欄位

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `Num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **Y** 成功 / **N** 失敗 |
| R3 | `edelno` | 新竹貨號 | String(10) | |
| R4 | `epino` | 訂單編號 | String(20) | |
| R5 | `ErrMsg` | 異常錯誤訊息 | String(40) | |

### JSON 範例

**傳入：**
```json
[
  { "epino": "A00001", "edelno": "1234567890" },
  { "epino": "A00002", "edelno": "1234567894" }
]
```

**回傳：**
```json
[
  { "Num": "1", "success": "Y", "edelno": "0000000001", "epino": "A00001", "ErrMsg": null },
  { "Num": "2", "success": "Y", "edelno": "0000000002", "epino": "A00002", "ErrMsg": null }
]
```

### XML 範例

**傳入：**
```xml
<info>
  <DataRow><epino>A00001</epino><edelno>1234567890</edelno></DataRow>
  <DataRow><epino>A00002</epino><edelno>1234567894</edelno></DataRow>
</info>
```

**回傳：**
```xml
<info>
  <DataRow><Num>1</Num><success>Y</success><edelno>0000000001</edelno><epino>A00001</epino><ErrMsg></ErrMsg></DataRow>
  <DataRow><Num>2</Num><success>Y</success><edelno>0000000002</edelno><epino>A00002</epino><ErrMsg></ErrMsg></DataRow>
</info>
```

---

## 4. QueryEDELNO — 查詢貨號

以訂單編號或貨號查詢是否曾上傳資料（取已配發的新竹貨號）。

### 方法簽章

```csharp
DataSet QueryEDELNO     (string company, string password, DataSet data)
string  QueryEDELNO_Json(string company, string password, string json)
string  QueryEDELNO_Xml (string company, string password, string xml)
```

### 傳入欄位

| No | 欄位 | 說明 | 長度 | 必填 |
|---|---|---|---|---|
| 1 | `epino` | 訂單編號 | String(30) | **必要欄位** |

> 欲查詢貨號的訂單編號多筆可用 `,`（逗號）分隔，**訂單編號中不能有逗號**。可一物件一筆，或單一物件 `epino` 帶逗號分隔字串（如 `"P002,S444"`）。

### 回傳欄位

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `success` | 結果 | String(1) | **Y** 成功 / **N** 失敗 |
| R2 | `edelno` | 新竹貨號 | String(10) | |
| R3 | `epino` | 訂單編號 | String(20) | |
| R4 | `ErrMsg` | 異常錯誤訊息 | String(40) | 查無資料時填「查無資料」|

### JSON 範例

**傳入：**
```json
[
  { "epino": "A00001" },
  { "epino": "A00002" }
]
```

**回傳（傳入 `epino` 值為 `"P002,S444"` 的型態示意）：**
```json
[
  { "success": "Y", "edelno": "2000001054", "epino": "A00001", "ErrMsg": "" },
  { "success": "N", "edelno": "",           "epino": "A00002", "ErrMsg": "查無資料" }
]
```

### XML 範例

**傳入：**
```xml
<info>
  <DataRow><epino>A00001</epino></DataRow>
  <DataRow><epino>A00002</epino></DataRow>
</info>
```

**回傳（傳入 `epino` 值為 `"A001,P003,S444,P001"` 的型態示意）：**
```xml
<info>
  <DataRow><success>Y</success><edelno>2000001054</edelno><epino>A00001</epino><ErrMsg></ErrMsg></DataRow>
  <DataRow><success>N</success><edelno></edelno><epino>A00002</epino><ErrMsg>查無資料</ErrMsg></DataRow>
</info>
```

---

## 5. R_TransData_Json — 逆物流傳入託運資料（退貨）

退貨情境用，欄位語意以「逆物流跟誰收」為退貨人。**JSON only。**

### 方法簽章

```csharp
string R_TransData_Json(string company, string password, string json)
```

> 注意事項：一批 ≤ 30 筆。

### 傳入欄位（16 欄 — V15 完整）

| No | 欄位 | 說明 | 長度 | 必填 / 備註 |
|---|---|---|---|---|
| 1 | `epino` | 訂單編號 | String(20) | **必要欄位，不可重複** |
| 2 | `ercsig` | 退貨人名稱 | String(40) | **必要欄位**（逆物流跟誰收；20 個全形）|
| 3 | `ertel1` | 退貨人電話 1 | String(20) | **必要欄位**（IVR 隱碼電話請輸入 10 碼隱碼電話 `#` 隨機碼，例 `0900000123#12345678`）|
| 4 | `ertel2` | 退貨人電話 2 | String(20) | 選填 |
| 5 | `eraddr` | 退貨人地址 | String(60) | **必要欄位**（逆物流跟誰收的地址；30 個全形）|
| 6 | `ejamt` | 件數 | String(4) | **必要欄位**（總長運費件數只能輸入 1）|
| 7 | `eqamt` | 重量 | String(5) | 依客戶代號重量給值；總長重量只能 60/90/120/150/151-999；如無提供抓系統預設值 |
| 8 | `esdate` | 出貨日期 | String(8) | 預設今天（`YYYYMMDD`）|
| 9 | `escsno` | 客戶代號 | String(11) | 預設值（如無提供系統抓預設值）|
| 10 | `edelno` | 新竹貨號 | String(10) | 預設值（如無提供系統自行配號）|
| 11 | `etcsig` | 收貨人名稱 | String(40) | 預設值（如無提供系統抓預設值；20 個全形）|
| 12 | `ettel1` | 收貨人電話 1 | String(15) | 預設值 |
| 13 | `etaddr` | 收貨地址 | String(60) | 預設值（如無提供系統抓預設值；30 個全形）|
| 14 | `emark` | 備註 | String(100) | 選填 |
| 15 | `eprdcl2` | 商品種類 | String(3) | `001` 一般 / `003` 冷凍 / `008` 冷藏；預設 `001` |
| 16 | `edelno2` | 原查貨號碼 | String(10) | 原正物流出貨查貨號碼 |

### 回傳欄位（key 首字大寫）

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `Num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **Y** 新增 / **N** 失敗（範例 key 為 `Success`）|
| R3 | `edelno` | 新竹貨號 | String(10) | 範例 key 為 `Edelno` |
| R4 | `epino` | 訂單編號 | String(20) | 範例 key 為 `Epino` |
| R5 | `erstno` | 到著站號碼 | String(3) | 退貨人到著站號碼（逆物流跟誰收的站所代號）；範例 key 為 `Erstno` |
| R6 | `eqamt` | 重量 | String(5) | 範例 key 為 `Eqamt` |
| R7 | `ErrMsg` | 異常錯誤訊息 | String(40) | |

> **⚠️ 回傳 JSON key 首字大寫**：`Num` / `Success` / `Edelno` / `Epino` / `Erstno` / `Eqamt` / `ErrMsg`，與正物流 `TransData_Json`（小寫 `success`/`edelno`…）不同，解析需做大小寫正規化。

### JSON 範例

**傳入（V15 原文）：**
```json
[
  {
    "epino": "101000000012345",
    "ercsig": "張三",
    "ertel1": "0255927298",
    "ertel2": "",
    "eraddr": "台北市士林區中山北路六段88號",
    "ejamt": "1",
    "eqamt": "60",
    "esdate": "",
    "escsno": "12345678900",
    "edelno": "",
    "etcsig": "大大消防",
    "ettel1": "0911123456",
    "ettel2": "",
    "etaddr": "台中市大雅區中清路三段513號",
    "emark": "",
    "eprdcl2": "001",
    "edelno2": "8812213345"
  },
  {
    "epino": "101000000012346",
    "ercsig": "李四",
    "ertel1": "035218811",
    "ertel2": "",
    "eraddr": "新竹縣新豐鄉建興路100號",
    "ejamt": "1",
    "eqamt": "",
    "esdate": "",
    "escsno": "12345678900",
    "edelno": "",
    "etcsig": "大大消防",
    "ettel1": "0911123456",
    "ettel2": "",
    "etaddr": "台中市大雅區中清路三段513號",
    "emark": "",
    "eprdcl2": "001"
  }
]
```

**回傳（V15 原文，注意大寫 key）：**
```json
[
  {
    "Num": "1",
    "Success": "N",
    "Edelno": "",
    "Epino": "101000000012345",
    "Erstno": "1247",
    "Eqamt": "60",
    "ErrMsg": "原貨號檢查碼錯誤"
  },
  {
    "Num": "2",
    "Success": "Y",
    "Edelno": "2250446752",
    "Epino": "101000000012346",
    "Erstno": "2060",
    "Eqamt": "9",
    "ErrMsg": ""
  }
]
```

---

## 6. TransDataCancel_Json — 取消收貨資料（取消託運單）

**V15 新增。** 上傳取消收貨資料（正物流，傳新竹貨號 + 訂單編號取消收貨）。**JSON only。**

### 方法簽章

```csharp
string TransDataCancel_Json(string company, string password, string json)
```

### 傳入欄位

| No | 欄位 | 說明 | 長度 | 必填 |
|---|---|---|---|---|
| 1 | `edelno` | 新竹貨號 | String(10) | **必要欄位** |
| 2 | `epino` | 訂單編號 | String(30) | **必要欄位** |

### 回傳欄位

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `Num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **Y** 新增 / **N** 失敗 |
| R3 | `edelno` | 新竹貨號 | String(10) | |
| R4 | `epino` | 訂單編號 | String(20) | |
| R5 | `ErrMsg` | 異常錯誤訊息 | String(40) | |

### JSON 範例

**傳入：**
```json
[
  { "edelno": "1234567891", "epino": "101000000012345" },
  { "edelno": "1234567902", "epino": "101000000012346" }
]
```

**回傳：**
```json
[
  { "Num": "1", "success": "Y", "edelno": "0000000001", "epino": "A00001", "ErrMsg": null },
  { "Num": "2", "success": "Y", "edelno": "0000000002", "epino": "A00002", "ErrMsg": null }
]
```

---

## 7. TransDispatchOrder_Json — 上傳需派遣收貨資料

**V15 新增。** 上傳需派遣收貨資料。**JSON only。**

- **派遣條件**：需為 30 天內使用同一個 `company` 上傳的資料。
- **`emark` 說明**：是讓司機知道取哪個派遣者的貨件。

### 方法簽章

```csharp
string TransDispatchOrder_Json(string company, string password, string json)
```

### 傳入欄位

| No | 欄位 | 說明 | 長度 | 必填 |
|---|---|---|---|---|
| 1 | `edelno` | 新竹貨號 | String(10) | **必要欄位** |
| 2 | `epino` | 訂單編號 | String(30) | **必要欄位** |
| 3 | `emark` | 派遣者 | String(30) | **必要欄位，不可為空白** |

### 回傳欄位（外層）

| No | 欄位 | 說明 | 型別 |
|---|---|---|---|
| R1 | `rtn_code` | 回傳結果代碼 | Integer |
| R2 | `rtn_data` | 回傳結果物件 | Object（陣列，見下）|
| R3 | `rtn_msg` | 回傳結果訊息 | String |

### `rtn_data` 物件參數（每筆）

| No | 欄位 | 說明 | 長度 | 備註 |
|---|---|---|---|---|
| R1 | `num` | 傳送序號 | String(2) | |
| R2 | `success` | 結果 | String(1) | **Y** 新增 / **N** 失敗 |
| R3 | `edelno` | 新竹貨號 | String(10) | |
| R4 | `epino` | 訂單編號 | String(20) | |
| R5 | `errMsg` | 異常錯誤訊息 | String(40) | （範例另含回傳的 `emark`）|

### JSON 範例

**傳入：**
```json
[
  { "edelno": "1234567891", "epino": "101000000012345", "emark": "Test" },
  { "edelno": "1234567902", "epino": "101000000012346", "emark": "Test" }
]
```

**回傳（V15 原文 — 外層 object 包 `rtn_data` 陣列）：**
```json
{
  "rtn_code": 1,
  "rtn_msg": "派遣收貨成功建檔",
  "rtn_data": [
    {
      "num": "1",
      "success": "Y",
      "edelno": "1234567891",
      "epino": "101000000012345",
      "emark": "Test",
      "errMsg": "已建檔"
    },
    {
      "num": "2",
      "success": "N",
      "edelno": "1234567902",
      "epino": "101000000012346",
      "emark": "Test",
      "errMsg": "查無此資料"
    }
  ]
}
```

> **回傳結構與其他方法不同**：`TransDispatchOrder_Json` 回傳為單一 object（`rtn_code` / `rtn_msg` / `rtn_data`），明細在 `rtn_data` 陣列，且明細 key 為小寫 `num` / `errMsg`。其他方法直接回陣列。

---

## 8. 出貨總表（列印參數）

除列印標籤外，仍需列印出貨總表（**一式二份**）供現場交接貨件使用。**總表由客戶自行製作。** 總表參數（對應 TransData 欄位）：

| No | 名稱 | 參數名 |
|---|---|---|
| 1 | 查貨號碼 | `edelno` |
| 2 | 地址 | `eraddr` |
| 3 | 到著站 | `erstno` |
| 4 | 收貨人 | `ercsig` |
| 5 | 件數 | `ejamt` |
| 6 | 重量 | `eqamt` |
| 7 | 收件電話 1 | `ertel1` |
| 8 | 收件電話 2 | `ertel2` |
| 9 | 傳票區分 | `eprdct` |
| 10 | 代收貨款 | `eqmny` |
| 11 | 訂單編號 | `epino` |
| 12 | 報值金額 | `egamt` |
| 13 | 客戶代號 | `escsno` |

---

## 9. 錯誤原因明細（Error Message）

各方法可能回傳的錯誤訊息（`ErrMsg`）與對應原因（V15 第 9 節）。

### TransData（正物流上傳）

| 錯誤訊息 | 錯誤原因 |
|---|---|
| 公司名稱或密碼錯誤 | 帳密驗證失敗 |
| 貨號重複 | 新竹貨號重複 |
| 貨號錯誤 | 沒新竹貨號 or 新竹貨號沒有 10 碼 |
| 貨號檢查碼錯誤 | 貨號最後一碼規則：物流單號共 10 碼 = 物流單號(9碼) + 檢查碼(1碼)；檢查碼規則 = 前 9 碼除以 7 的餘數 = MOD(9位數, 7) |
| 訂單編號錯誤 | 訂單編號空白 |
| 客代錯誤 | 客代沒有 11 碼 |
| 件數錯誤 | 件數 0 |
| 重量錯誤 | 重量 0 |
| 收貨人名稱錯誤 | 收貨人空白 |
| 收貨人電話錯誤 | 收貨人電話空白 |
| 收貨人地址錯誤 | 收貨人地址空白 |
| 出貨站錯誤 | 出貨站沒有 4 碼 |
| 一個月內貨號重複 | — |
| 訂單編號重複 | — |

### UpdData（修改重量）

| 錯誤訊息 | 錯誤原因 |
|---|---|
| 公司名稱或密碼錯誤 | 帳密驗證失敗 |
| 重量錯誤 | 重量值不合法 |
| 查無貨號和訂單編號 | 找不到對應託運單 |

### TransReport（列印總表）

| 錯誤訊息 | 錯誤原因 |
|---|---|
| 查無貨號 | DB 沒貨號 |
| 已經上傳過此筆 | ISMASTER=1（已確認出貨）|

### R_TransData_Json（逆物流）

| 錯誤訊息 | 錯誤原因 |
|---|---|
| 貨號重複 | — |
| 貨號錯誤 | — |
| 貨號檢查碼錯誤 | — |
| 貨號錯誤必須是 2 開頭 | 逆物流貨號規則 |
| 原貨號錯誤 | `edelno2` 原查貨號錯誤 |
| 訂單編號錯誤 | — |
| 客代錯誤 | — |
| 件數錯誤 | — |
| 重量錯誤 | — |
| 退貨人名稱錯誤 | — |
| 退貨人電話錯誤 | — |
| 退貨人地址錯誤 | — |
| 退貨站錯誤 | — |
| 收貨人名稱錯誤 | — |
| 收貨人電話錯誤 | — |
| 收貨人地址錯誤 | — |
| 收貨站錯誤 | — |
| 總長請輸入重量 60、90、120、150、151~999 | 總長件重量規則 |
| 總長件數只能為 1 件 | 總長件數規則 |
| 未建收退貨貨號區間 | 客戶未建立退貨貨號區間 |
| 三個月內貨號重複 | 逆物流貨號 3 個月內不可重複 |
| 訂單編號重複 | — |
