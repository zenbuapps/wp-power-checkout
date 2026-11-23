# 新竹物流 集貨註區 / 地址比對 + 貨況查詢（V15 已移除，舊版補充）

> **重要**：本檔兩個區塊**都不在 V15（2026-04-28）出貨串接文件**內：
> - **集貨註區 / 地址比對 API（addrCompare）** — 來源：標籤規格說明 V2.42（2023/04/14）第 2 章。V15 起 TransData 已可 inline 回傳 `CODE2`–`CODE5`/`CODE7`/`MDCODE1`–`MDCODE3`（見 `api-reference.md` §1 R11–R20），多數情境**不再需要**另呼叫此 API；但獨立 addrCompare 仍可用（例如僅做地址比對、不建單時）。
> - **貨況查詢服務（searchGoods）** — 來源：API 服務說明 V2.0（2022/12/30）。V15 完全移除此功能。**加解密金鑰原「申請後提供」已補齊** → DES CBC 規格 + zenbu 實際參數 + `status_code` 對應表（62 碼）見 **`cargo-tracking-decrypt.md`**（Ver3.1 2026/06/11 + zenbu 申請後文件）；本檔 Part B 保留 V2.0 結構補充。

## 目錄（TOC）

- [Part A — 集貨註區 / 地址比對 API（addrCompare）](#part-a--集貨註區--地址比對-apiaddrcompare)
  - [A.1 服務端點](#a1-服務端點)
  - [A.2 傳入欄位](#a2-傳入欄位三種模式共用)
  - [A.3 回傳欄位](#a3-回傳欄位完整對照)
  - [A.4 addrCompare_string（SOAP 單筆）XML 範例](#a4-addrcompare_stringsoap-單筆xml-回傳範例)
  - [A.5 addrCompare_Json（SOAP 多筆）JSON 範例](#a5-addrcompare_jsonsoap-多筆json-範例)
  - [A.6 URL / GET 模式（Addr_Compare.aspx）](#a6-url--get-模式addr_compareaspx)
- [Part B — 貨況查詢服務（searchGoods，舊版）](#part-b--貨況查詢服務searchgoods舊版)
  - [B.1 加 / 解密方式](#b1-加--解密方式)
  - [B.2 網頁串接模式（單筆）](#b2-網頁串接模式單筆)
  - [B.3 多筆貨號查詢（XML，POST）](#b3-多筆貨號查詢xmlpost)
  - [B.4 注意事項](#b4-注意事項查貨服務)
  - [B.5 貨況對應表（status）](#b5-貨況對應表status)

---

# Part A — 集貨註區 / 地址比對 API（addrCompare）

來源：標籤規格說明 V2.42（2023/04/14）第 2 章「集貨註區說明」。

## 用途與時機

用「收貨人地址」比對出**到著站、註區、疊區、QRcode 內容**等標籤所需資訊。

**何時需要呼叫**：
- **僅做地址比對 / 不建立託運單**時（例如試算到著站、預檢地址）。
- 自繪標籤但**走舊機制**取到著碼時。
- **V15 起若已呼叫 TransData**，標籤所需 CODE/MDCODE 已在 TransData 回傳內（`api-reference.md` §1），通常**不必**再呼叫此 API。

> 文件提醒：**標籤製作完成，煩請提供資訊處確認內容**（上線前需新竹資訊處核對標籤）。印標設備建議：雷射印表機、熱感標籤機。

## A.1 服務端點

| 模式 | 端點 URL | 編碼 |
|---|---|---|
| SOAP（單筆 string）| `http://is1fax.hct.com.tw/Webedi_Erstno_NEW2/WS_addrCompare1.asmx` → `addrCompare_string` | — |
| SOAP（多筆 JSON）| 同上 `.asmx` → `addrCompare_Json` | — |
| URL / GET | `http://is1fax.hct.com.tw/Webedi_Erstno_New2/Addr_Compare.aspx` | **中文需 Big5 編碼** |

**測試帳號：`USER=TEST2`**（與出貨 API 的 `test` 不同帳號體系）。

| SOAP 方法 | 用途 | 說明 |
|---|---|---|
| `addrCompare_string` | 地址比對新集貨註區 | 單筆地址比對 |
| `addrCompare_Json` | 地址比對新集貨註區 | 多筆 JSON 地址比對 |

## A.2 傳入欄位（三種模式共用）

| 序 | 欄位名稱 | 參數名 | 長度 | 說明 |
|---|---|---|---|---|
| 1 | 登入帳號 | `USER` | 20 | 測試帳號：`TEST2` |
| 2 | 收件人地址 | `ADDR` | 100 | 如是站止，請填「站所名稱 + 站止」。例：台北站止、大園站止、彰化站止、中工站止 |
| 3 | 發送日 | `ESDATE` | 8 | 西元年 `yyyymmdd`，例 `20210416`。空值預設為當日電腦日期 |
| 4 | 收件人電話 | `TEL` | 20 | 區碼前一定要代 0，不可填分機。例 `0212345***` / `0912123***` |
| 5 | 收件人電話 2 | `TEL2` | 20 | 同上 |
| 6 | 商品種類 | `EPRDCL` | 3 | `001` 一般 / `003` 冷凍 / `004` 蔬果類 / `007` 貨到刷卡 / `008` 冷藏 / `100` GDP 醫藥 / `120` GDP 醫藥來回件 |
| 7 | 備註 | `EMARK` | 100 | 超商店取填關鍵字（任何位置都可）：全家→「全家」；萊爾富→「萊爾富」；OK→「OK超商/ＯＫ超商/ok超商」；7-11→「統一超商/７－１１超商/7-11超商」|
| 8 | 客戶代號 | `ESCSNO` | 11 | 空值預設為 11 個 0 |
| 9 | 件數 | `EJAMT` | 4 | 例：1 件填 `1` |
| 10 | 重量 | `EQAMT` | 5 | 例：5 填 `5` |
| 11 | 重量單位 | `EQAMTTYPE` | 1 | `1`-kg / `2`-才 / `3`-總長；空值預設 `1`-KG |
| 12 | 困聯設定 | `ELAMTTYPE` | 1 | 空值 |

> JSON 模式另有 `NO` 欄位（編號，對應回傳的 `NO`）。

## A.3 回傳欄位（完整對照）

| 序 | XML 欄位 | JSON 欄位 | 說明 |
|---|---|---|---|
| 1 | `address` | `NO`（JSON 為編號）| XML 回地址；JSON 回傳入時帶的 `NO` 編號 |
| 2 | `PutData_m` | `PUTDATA_M` | 站所四碼代號 |
| 3 | `PutData_s` | `PUTDATA_S` | 站所簡碼（**見下方筆誤提醒**）|
| 4 | `PutDataName` | `PUTDATANAME` | 站所名稱（**見下方筆誤提醒**）|
| 5 | `PutDataZip` | `PUTDATAZIP` | 郵遞區號 |
| 6 | `PutOut_Area1` | `PUTOUT_AREA1` | ＳＤ註區 |
| 7 | `PutOut_Area11` | `PUTOUT_AREA11` | ＳＤ疊區 |
| 8 | `PutOut_Area2` | `PUTOUT_AREA2` | ＤＤ註區 |
| 9 | `PutOut_Area21` | `PUTOUT_AREA21` | ＤＤ疊區 |
| 10 | `PutOut_Area4` | `PUTOUT_AREA4` | ＭＤ註區 |
| 11 | `PutOut_Area41` | `PUTOUT_AREA41` | ＭＤ疊區 |
| 12 | `PutOut_Area6` | `PUTOUT_AREA6` | 低溫註區 |
| 13 | `PutOut_Area61` | `PUTOUT_AREA61` | 低溫疊區 |
| 14 | `PutF5star` | `PUTF5STAR` | 聯運費用（起碼）|
| 15 | `PutF5gwet` | `PUTF5GWET` | 聯運費用（百公斤）|
| — | `PutAo_Flag` | `PUTAO_FLAG` | （範例中出現，欄位表未列說明）|
| 16 | `PutNewRkzone` | `PUTNEWRKZONE` | 新集貨註區 |
| — | — | `PUTCODE1`~`PUTCODE6` | （JSON 範例中另出現 PUTCODE 系列，與 CODE1~6 同值）|
| 17 | `CODE1` | `CODE1` | 類別 |
| 18 | `CODE2` | `CODE2` | **到著簡碼**（標籤欄位 1，長度 3 碼）|
| 19 | `CODE3` | `CODE3` | **衛星區**（標籤欄位 2，長度 1 碼）|
| 20 | `CODE4` | `CODE4` | **註區**（標籤欄位 3，長度 3 碼，黑底白字）|
| 21 | `CODE5` | `CODE5` | **疊區**（標籤欄位 4，長度 1 碼）|
| 22 | `CODE6` | `CODE6` | **QRcode 內容**（標籤欄位 21；注意 V15 TransData inline 同欄位名 `CODE7`）|
| 23 | `AREAS` | `AREAS` | 聯運區提醒（`*` 表示有聯運區，空白表示沒有）|
| 24 | `MDCODE1` | `MDCODE1` | MD 到著碼衛星區（標籤欄位 22，長度 1 碼）|
| 25 | `MDCODE2` | `MDCODE2` | MD 到著碼註區（標籤欄位 23，長度 3 碼，黑底白字）|
| 26 | `MDCODE3` | `MDCODE3` | MD 到著碼疊區（標籤欄位 24，長度 1 碼）|
| 27 | `MSG` | `MSG` | 訊息 |

> **文件筆誤提醒**：JSON 版欄位表把 `PUTDATANAME` 標成「站所簡碼」、`PUTDATA_S` 標成「站所名稱」，與 XML 版（`PutData_s`=簡碼、`PutDataName`=名稱）相反。**以 XML 版語意為準**：`*_S`=簡碼、`*Name`=名稱。

## A.4 addrCompare_string（SOAP 單筆）XML 回傳範例

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Info>
  <address>高雄市鳳山區五甲二路478號</address>
  <PutData_m>6200</PutData_m>
  <PutData_s>83</PutData_s>
  <PutDataName>鳳山</PutDataName>
  <PutDataZip>830</PutDataZip>
  <PutOut_Area1>011</PutOut_Area1>
  <PutOut_Area11>1</PutOut_Area11>
  <PutOut_Area2>816</PutOut_Area2>
  <PutOut_Area21>4</PutOut_Area21>
  <PutOut_Area4>816</PutOut_Area4>
  <PutOut_Area41>4</PutOut_Area41>
  <PutOut_Area6>795</PutOut_Area6>
  <PutOut_Area61>5</PutOut_Area61>
  <PutF5star> </PutF5star>
  <PutF5gwet> </PutF5gwet>
  <PutAo_Flag> </PutAo_Flag>
  <PutNewRkzone>H-83--816-4</PutNewRkzone>
  <CODE1>H</CODE1>
  <CODE2>83</CODE2>
  <CODE3> </CODE3>
  <CODE4>816</CODE4>
  <CODE5>4</CODE5>
  <CODE6>^083816816^</CODE6>
  <AREAS> </AREAS>
  <MDCODE1> </MDCODE1>
  <MDCODE2>816</MDCODE2>
  <MDCODE3>4</MDCODE3>
  <MSG> </MSG>
</Info>
```

## A.5 addrCompare_Json（SOAP 多筆）JSON 範例

**傳入：**
```json
[
  {
    "USER": "TEST2",
    "NO": "A0000001",
    "ADDR": "南投縣信義鄉東埔村東埔23號",
    "ESDATE": "20210715",
    "TEL": "",
    "TEL2": "",
    "EPRDCL": "001",
    "EMARK": "",
    "ESCSNO": "00000000000",
    "EJAMT": "1",
    "EQAMT": "5",
    "EQAMTTYPE": "1",
    "ELAMTTYPE": "1"
  },
  {
    "USER": "TEST",
    "NO": " A0000002",
    "ADDR": "新竹縣新豐鄉德昌街228號",
    "ESDATE": "20210715",
    "TEL": "",
    "TEL2": "",
    "EPRDCL": "001",
    "EMARK": "",
    "ESCSNO": "00000000000",
    "EJAMT": "1",
    "EQAMT": "5",
    "EQAMTTYPE": "1",
    "ELAMTTYPE": "1"
  }
]
```

**回傳：**
```json
[
  {
    "NO": "A0000001",
    "PUTDATA_M": "4402",
    "PUTDATANAME": "草屯",
    "PUTDATA_S": "44",
    "PUTDATAZIP": "556",
    "PUTOUT_AREA1": "935",
    "PUTOUT_AREA11": "2",
    "PUTOUT_AREA2": "935",
    "PUTOUT_AREA21": "2",
    "PUTOUT_AREA4": "935",
    "PUTOUT_AREA41": "2",
    "PUTOUT_AREA6": "935",
    "PUTOUT_AREA61": "2",
    "PUTF5STAR": "115",
    "PUTF5GWET": "230",
    "PUTAO_FLAG": "",
    "PUTNEWRKZONE": "-44--935-2",
    "PUTCODE1": " ",
    "PUTCODE2": "44",
    "PUTCODE3": " ",
    "PUTCODE4": "935",
    "PUTCODE5": "2",
    "PUTCODE6": "^044935935^",
    "CODE1": " ",
    "CODE2": "44",
    "CODE3": " ",
    "CODE4": "935",
    "CODE5": "2",
    "CODE6": "^044935935^",
    "AREAS": "*",
    "MDCODE1": "",
    "MDCODE2": "935",
    "MDCODE3": "2",
    "MSG": ""
  },
  {
    "NO": " A0000002",
    "PUTDATA_M": "2060",
    "PUTDATANAME": "新豐",
    "PUTDATA_S": "323",
    "PUTDATAZIP": "304",
    "PUTOUT_AREA1": "707",
    "CODE1": " ",
    "CODE2": "323",
    "CODE3": " ",
    "CODE4": "707",
    "CODE5": " ",
    "CODE6": "^323707   ^",
    "AREAS": "",
    "MDCODE1": "",
    "MDCODE2": "   ",
    "MDCODE3": " ",
    "MSG": ""
  }
]
```

> 觀察：`CODE6`（QRcode 內容）格式為 `^{3碼}{3碼}{3碼}^`（前後 `^` 包夾，例 `^044935935^`）；無值欄位以**空白字元（非 null）**回傳，使用前需 `trim()`。

## A.6 URL / GET 模式（Addr_Compare.aspx）

**注意：傳入中文需使用 Big5 編碼。**

### 端點格式

```
http://is1fax.hct.com.tw/Webedi_Erstno_New2/Addr_Compare.aspx?USER={帳號}&ADDR={收貨人地址}&TEL={收貨人電話1}&TEL2={收貨人電話2}&EPRDCL={商品種類}&EMARK={備註}&ESCSNO={客戶代號}&EJAMT={件數}&EQAMT={重量}&ESDATE={發送日}&EQAMTTYPE={重量單位}&ELAMTTYPE={困聯設定}
```

### 傳入資料範例

```
http://is1fax.hct.com.tw/Webedi_Erstno_NEW2/Addr_Compare.aspx?USER=TEST2&ADDR=新竹縣湖口鄉三元路一段25號&TEL=0339710&TEL2=04123456&EPRDCL=001&EMARK=&ESCSNO=01234567890&EJAMT=1&EQAMT=30&ESDATE=20210423&EQAMTTYPE=1&ELAMTTYPE=1
```

> 實際傳送時 `ADDR` 等中文參數需 Big5 URL-encode。

### URL 模式回傳欄位（中文標頭，對應 CODE 系列）

| 序 | 欄位名稱 | 對應 |
|---|---|---|
| 1 | 送貨地址 | 送貨地址 |
| 2 | 到著站四碼 | 站所四碼代號（`PutData_m`）|
| 3 | 到著站簡碼 | 站所簡碼（`PutData_s`）|
| 4 | 到著站中文 | 站所名稱（`PutDataName`）|
| 5 | 郵遞區號 | 郵遞區號 |
| 6 | 正常配區 | ＳＤ註區 |
| 7 | 正常疊區 | ＳＤ疊區 |
| 8 | DD 配區 | ＤＤ註區 |
| 9 | DD 疊區 | ＤＤ疊區 |
| 10 | MD 配區 | ＭＤ註區 |
| 11 | MD 疊區 | ＭＤ疊區 |
| 12 | 低溫配區 | 低溫註區 |
| 13 | 低溫疊區 | 低溫疊區 |
| 14 | 聯運費用(起碼) | `PutF5star` |
| 15 | 聯運費用(百公斤) | `PutF5gwet` |
| 16 | 新集貨註區 | `PutNewRkzone` |
| 17 | CODE1 | 類別 |
| 18 | CODE2 | 到著簡碼 |
| 19 | CODE3 | 衛星區 |
| 20 | CODE4 | 註區 |
| 21 | CODE5 | 疊區 |
| 22 | CODE6 | QRcode |
| 23 | AREAS | 聯運區提醒（`*` 有 / 空白無）|
| 24 | MDCODE1 | MD 到著碼衛星區 |
| 25 | MDCODE2 | MD 到著碼註區 |
| 26 | MDCODE3 | MD 到著碼疊區 |

---

# Part B — 貨況查詢服務（searchGoods，舊版）

來源：API 服務說明 V2.0（2022/12/30）。**V15（2026-04-28）已完全移除此功能**——若 V15 為唯一可用文件，貨況查詢需另向新竹物流申請最新規格。

## B.1 加 / 解密方式

> ✅ **已補齊（原「申請後提供 / PDF 未公開」）** → 完整規格見 **`cargo-tracking-decrypt.md`**。
>
> 摘要：`no` = **DES CBC**（KEY = 查詢當日 ± n 天的 `yyyyMMdd` UTF-8、IV = 8 位固定、PKCS7、輸出 Base64），`v` = 32 位固定識別碼（不參與加解密）。
> zenbu 實際參數（n=`-33` / IV=`<HCT_TRACK_IV>` / v=`<HCT_TRACK_V>`）、官方 C# + 已驗證 Node 範例、OpenSSL 3 legacy 陷阱、對拍 test vector 全在該檔。

## B.2 網頁串接模式（單筆）

提供單筆訂單編號／貨號查詢，可直接連至新竹物流網頁顯示貨況：

```
https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no={加密字串}&v={xxxxxx}
```

產生的網址可放置於貴司網頁供客戶點擊查詢。

## B.3 多筆貨號查詢（XML，POST）

回傳 XML 加密後字串，解密後得 XML。**請求類型使用 POST**。

```
https://hctapiweb.hct.com.tw/phone/searchGoods_Main_Xml.ashx?no={加密後XML}&v={xx}
```

**加密前傳出格式（XML）：**
```xml
<?xml version="1.0" encoding="utf-8"?>
<qrylist>
  <order orderid="1234567890"></order>
  <order orderid="1234567891"></order>
</qrylist>
```

**解密後返回格式（XML）— a. 有貨況時：**
```xml
<?xml version="1.0" encoding="utf-8"?>
<rlist>
  <orders ordersid="1234567890">
    <order orderid="1234567890" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況" />
    <order orderid="1234567890" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況2" />
  </orders>
  <orders ordersid="1234567891">
    <order orderid="1234567891" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況" />
  </orders>
</rlist>
```

**b. 查詢該筆貨號無貨況時回空值：**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<rlist />
```

## B.4 注意事項（查貨服務）

1. 一般貨況正常僅供查詢 **30 天內**資料，歷史查詢需求請連繫原申請營業所。
2. 訂單編號與十碼貨號目前**只能選擇其一**進行查詢（申請時即選擇）。
3. 若使用訂單編號查詢，需綁定特約客戶帳號及客戶代號。
4. 對所查詢的貨況資訊有疑慮，請連繫原申請營業所。
5. 使用 XML 多筆查詢時，**單次查詢上限 100 筆**，且需等待結果回傳後才可再次提交。
6. 系統每日凌晨 **03:40-04:30** 進行查貨系統維護，不會有貨況更新。
7. 異常請連繫：**02-2837-1122 #5123 許先生**。

## B.5 貨況對應表（status）

> **note**：此為 V2.0 版（38 列，**無** `status_code` / `category` 欄）。Ver3.1 已補 **`status_code` 貨況代碼欄（62 碼、4 欄完整版）** → 以 **`cargo-tracking-decrypt.md` §6** 為準；該檔另附 `status_code → ShippingState` 建議映射（FU-4）。

新竹物流中文貨況對應表（`xx` 代表站所名稱、`N` 代表件數）：

| 貨況 | 說明（status）|
|---|---|
| 集貨 | 已由 xx 取件完成。|
| 卸集貨 | 貨件已達 xx，貨件整理中。貨物件數共 N 件 |
| 發送 | 貨件已抵達 xx，前往配送站途中。|
| 到著 | 貨件已抵達 xx，分貨中。|
| 配達 | 貨件由 xx 人員配送中。|
| 持回 | 貨件由 xx 保管中。|
| 派遣 | 取貨通知處理中。|
| 支援 | xx 取件中。|
| 客戶不在（取件）| 寄貨人外出無法收件。|
| 已取 | xx 已至客戶端取回貨件。|
| 誤派 | xx 取件中。|
| 址誤 | 取件異常地址錯誤，xx 處理中。|
| 同業收取 | 取件異常，貨件由其他同業取走。|
| 無貨可退 | 取件異常，無貨件可收。|
| 其他 | 取件異常，專人處理中。|
| 預計 12 點前到客戶端 | xx 取件中。|
| 預計 15 點前到客戶端 | xx 取件中。|
| 預計 17 點前到客戶端 | xx 取件中。|
| 預計 17 點後到客戶端 | xx 取件中。|
| 查無此人（取件）| 該地址查無此人，確認中。|
| 電聯異常 | 已電話聯繫，無人接聽。|
| 商品保留不退 | 收件人確定保留商品，取消退貨。|
| 約定收件 | 寄貨人外出，另約時間再次取件。|
| 公司行號休息 | 收件地址為公司行號，本日休假。|
| 派遣取消 | 廠商通知取消取件。|
| 派遣確認 | xx 已派遣人員取件中。|
| 正常配交 | 貨件已由 xx 送達。貨物件數共 N 件。|
| 缺件配達 | xx 缺件配交。|
| 破損配達 | xx 破損配交。|
| 客戶不在（配送）| 送達客戶不在，xx 保管中。|
| 地址錯誤 | 收貨人地址異常，xx 處理中。|
| 查無此人（配送）| 該地址查無此人，xx 處理中。|
| 電聯異常（配送）| 已電話聯繫，無人接聽。|
| 到站自領 | 與客戶另約時間到站自領，xx 保管中。|
| 拒收退回 | 貨件已退回，退貨號碼為 xx。|
| 委外配送中（金門）| 貨件由 xx 委外金門協力商人員配送中。|
| 委外配送中（澎湖）| 貨件由 xx 委外澎湖協力商人員配送中。|
| 委外配送中（其他）| 貨件由 xx 委外協力商人員配送中。|
| 轉運中 | 貨件由 xx 轉運中。|

---

## 申請流程

- **查貨服務**：與配合站所電腦負責人申請，提供串接申請表單 & 串接說明文件填寫。
- **出貨 / 託運單列印服務**：同樣與配合站所電腦負責人申請。
- 貨號區間及規則、加解密金鑰、出貨站簡碼等參數均於**申請後由新竹物流提供**。
