# 版本註記：V15 vs 舊版 + 實打 WSDL 一致性

本檔記錄萃取來源版本、V15 相對舊文件的逐項變更、舊「待澄清」項目在 V15 的解決狀態，以及「實打 live WSDL 的觀察」vs「V15 文件」的一致性比對。

## 1. 文件來源版本

| 文件 | 版本 | 日期 | 權威性 | 提供的內容 |
|---|---|---|---|---|
| 新竹物流出貨串接 WebServices 文件 | **V15** | **2026-04-28** | **最高（主來源）** | 15 個 SOAP 操作完整欄位 / 回傳 / JSON+XML 範例、錯誤表、標籤參數對應、C# image function |
| API 服務說明 | V2.0 | 2022/12/30 | 補充（V15 已涵蓋出貨；獨有貨況查詢）| 貨況查詢 searchGoods + 貨況對應表（V15 已移除）|
| 標籤規格說明 | V2.42 | 2023/04/14 | 補充（條碼尺寸 + 獨立 addrCompare）| Codabar/Code128/Code39 + QRcode 尺寸（V15 以圖片呈現）、集貨註區 addrCompare API、24 欄位標籤表 |

---

## 2. V15 相對舊版（API 服務說明 V2.0）的逐項變更

### 新增

1. **DEV / PROD URL 正式分離**
   - PROD：`https://hctrt.hct.com.tw/edi_webservice2/service1.asmx`
   - DEV：`https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx`
   - 舊版只給單一 URL（`https://Hctrt.hct.com.tw/EDI_WebService2/Service1.asmx`）。V15 路徑全小寫並新增 `_test` DEV 變體。
2. **新增 2 個 SOAP 方法**（舊版只有 5 個方法家族）：
   - `TransDataCancel_Json()`（§14）— 取消收貨資料 / 取消託運單（傳貨號 + 訂單編號）。
   - `TransDispatchOrder_Json()`（§15）— 上傳需派遣收貨資料（30 天內同 company；回傳結構特殊為 `rtn_code`/`rtn_msg`/`rtn_data`）。
3. **TransData 回傳由 R1–R8 擴充為 R1–R20**：新增 `NewOutArea`、`eqmny`、`CODE1`–`CODE5`、`CODE7`(QRcode)、`AREAS`、`MDCODE1`–`MDCODE3`。**標籤所需的到著碼 / QRcode 內容現可直接由 TransData 取得，不必再呼叫獨立 addrCompare API。**
4. **TransData request 新增欄位**：
   - `erenum`（回單張數，String(2)，預設 0）。
   - 跨境業務四欄：`HAWB`（小提單號，必須）、`boxNo`（報關箱號）、`MAWB`（大提單號）、`Declare`（報單號碼）。
5. **`eprdct`（傳票類別）補進 JSON/XML 欄位表**：String(2)，值 `11` 月結 / `21` 到付 / `31` 現收 / **`41` 代收（COD）**。舊版僅 DataSet 版欄位表列出，JSON/XML 表缺漏。

### 修正

6. **`erstno`（到著站）正名**：TransData Return R5 在 V15 正式命名 `erstno`（舊版 DataSet 欄位表筆誤寫成「第二個 `epino`」）。
7. **R_TransData_Json Return 增列 `erstno`**：退貨人到著站號碼（逆物流跟誰收的站所代號），舊版本未明列。

### 變更

8. **`ertel1` 長度**：TransData 的 `ertel1` 在 V15 標 String(20)（舊版 api-reference 標 Char(15)）。
9. **QRcode 欄位名**：V15 TransData inline 回傳的 QRcode 欄位名為 `CODE7`（R16）；獨立 addrCompare API 仍用 `CODE6`。兩處語意相同、名稱不同。

### 移除

10. **貨況查詢服務（searchGoods）整段移除**：V15 完全不含貨況查詢 URL、加解密說明、貨況對應表。此功能僅存於舊 API PDF V2.0（見 `cargo-status-and-addr-compare.md` Part B）。

---

## 3. 舊「待澄清」項目在 V15 的解決狀態

| 舊待澄清項 | V15 狀態 | 結論 |
|---|---|---|
| **① SOAP WSDL / namespace / SOAPAction** | ❌ **未解決** | V15 仍只給 Content-Type=`text/xml; charset=utf-8`、POST、HTTPS、UTF-8，未附 WSDL / namespace / SOAPAction。實作前須抓 `?wsdl`（見 §4 實打觀察）。|
| **② `eprdct`（傳票類別）在 JSON/XML 模式如何帶入** | ✅ **已解決** | V15 把 `eprdct` 補進 TransData 欄位表（field 18, String(2), `11`/`21`/`31`/`41`）。直接以普通欄位帶入 JSON/XML payload。|
| **③ 貨況查詢加解密演算法與金鑰** | ✅ **已解決**（V15 移除整段，另由查貨串接文件補齊）| 演算法 = **DES CBC**（KEY=當日±n天 yyyyMMdd、IV=8位固定、PKCS7、Base64）；zenbu 參數（n=`-33`/IV=`<HCT_TRACK_IV>`/v=`<HCT_TRACK_V>`）+ 已驗證 test vector + `status_code` 對應表全見 `cargo-tracking-decrypt.md`（Ver3.1 2026/06/11 + zenbu 申請後文件）。|
| **④ 正式 vs 測試環境 URL 區分 / 測試帳號是否打正式** | ✅ **部分解決** | V15 明確分離 DEV（`_test`）/ PROD URL。測試帳號（`test`/`test1`、addrCompare `TEST2`）是否打到正式系統 V15 未明說，E2E 前建議向新竹確認。|
| **⑤ 回傳欄位筆誤（epino → erstno；addrCompare PUTDATANAME/PUTDATA_S）** | ✅ **TransData 部分已解決** | V15 TransData Return 正式用 `erstno`（不再筆誤為第二個 epino）。addrCompare 的 `PUTDATANAME`/`PUTDATA_S` 筆誤屬舊標籤 PDF，V15 不含此 API，未受影響——以 XML 版語意為準（`*_S`=簡碼、`*Name`=名稱）。|
| **⑥ 是否有新方法 / 取消託運單 / 貨況查詢替代** | ✅ **部分解決** | V15 收錄 `TransDataCancel_Json`（取消託運單）、`TransDispatchOrder_Json`（派遣收貨）。但 `GetImage_Json` / `onlyImage`（取圖）/ `FrightQuery`（疑似貨況查詢替代）**仍未在 V15 文件中**（見 §4）。|

---

## 4. 實打 live WSDL 觀察 vs V15 文件一致性

> 來源：對 live `Service1.asmx?wsdl` 的實際抓取觀察（非 V15 文件原文）。供整合除錯與「V15 未提供 WSDL/SOAPAction」的補充。

### 與 V15 文件一致的部分

- **方法名一致**：`TransData_Json` / `UpdData_Json` / `R_TransData_Json` / `QueryEDELNO_Json` / `TransReport_Json` 等在 WSDL 上皆存在，命名與 V15 §1.2 服務項目清單一致。
- **response wrapper**：WSDL 顯示回傳包在 `{method}Result`（如 `TransData_JsonResult`），與 SOAP `.asmx` 慣例一致。
- **TransReport 特殊參數名**：WSDL 證實 `TransReport_Json` 第三參數為 `dsCusJson`（其他方法為 `json`），與 V15 §4 方法簽章一致。

### V15 文件未提供、需靠 WSDL 補的部分

- **target namespace**：WSDL 觀察為 `http://tempuri.org/`（ASP.NET 預設）。**V15 文件未載此值。**
- **SOAPAction**：WSDL 觀察為 `http://tempuri.org/{method}`（**無尾斜線**）。**V15 文件未載此值。**
- 即：手組 SOAP envelope 時，`xmlns` 用 `http://tempuri.org/`、`SOAPAction` 用 `"http://tempuri.org/TransData_Json"`（依方法替換）。

### WSDL 上存在、但 V15 文件未收錄的方法（需向新竹確認語意 / 參數 schema）

| WSDL 方法 | 推測用途 | V15 文件狀態 |
|---|---|---|
| `GetImage_Json(company, password, json)` | 專門取標籤圖（比「重打 TransData 取 image」乾淨）| **未收錄** |
| `onlyImage(json, comjpg, PicRotate)` | 專門取圖（含旋轉參數）| **未收錄** |
| `TransDataCancel_Json` | 取消託運單 | ✅ V15 已收錄（§14）|
| `TransDispatchOrder_Json` | 派遣收貨 | ✅ V15 已收錄（§15）|
| `FrightQuery` | 疑似貨況 / 運費查詢替代 | **未收錄** |

> **結論給整合者**：若需要「正規取標籤圖 API」，WSDL 上有 `GetImage_Json` / `onlyImage`，但**參數 schema（json 內容）V15 與所有官方 PDF 皆未文件化**，須向新竹物流配合站所索取。在取得 schema 前，列印取圖仍以「`TransData` 回傳的 `image` 欄位」為唯一文件化來源。

---

## 5. 給整合者的版本決策摘要

- **以 V15 為單一事實來源**做欄位 / 回傳 / 範例對齊。
- **WSDL / namespace / SOAPAction**：V15 缺，用 `http://tempuri.org/` + `http://tempuri.org/{method}`（無尾斜線），但務必對自己的環境再抓一次 `?wsdl` 驗證。
- **貨況查詢**：V15 無；加解密規格 + zenbu 參數 + `status_code` 對應表已補齊於 `cargo-tracking-decrypt.md`（DES CBC，Ver3.1 + zenbu 文件）；舊版結構補充見 `cargo-status-and-addr-compare.md` Part B（V2.0）。
- **條碼尺寸**：V15 以圖片呈現，沿用 `label-spec.md`（舊標籤 V2.42）的精確 mm 值。
- **集貨註區 addrCompare**：V15 起多數情境不必呼叫（TransData 已 inline 回 CODE/MDCODE）；僅「不建單只比對地址」時用獨立 API。
