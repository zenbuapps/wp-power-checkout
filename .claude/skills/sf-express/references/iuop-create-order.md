# 國際快遞（IUOP）建立物流訂單 — `IUOP_CREATE_ORDER`

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：台灣寄出 / 寄到台灣 / 兩岸快遞件要建單或改單（`orderOperateType=5`）時，查 body 每個物件（OrderExtendInfo、PaymentInfo、SenderInfo、ReceiverInfo、CustomsInfo、ParcelInfo、AddServiceInfo、ChildInfo、OrderEcommerceInfo…）的完整欄位 / 型別 / 長度 / 必填。

台灣相關欄位速記（細節見 `taiwan-rules.md`）：
- `senderInfo.cargoType`：**CN / TW / KR 始發必填**（1 個人件 / 2 公司件）。
- `receiverInfo.certType` + `certCardNo`：**跨境件收件國家/地區為 ID / TW / KR 時必填**（證件類型見 `dictionaries.md`：`001` 身分證、`002` 護照、`006` 台灣通行證、`009` 統編號…）。
- `declaredCurrency` 用 SF 幣種表代碼，新台幣是 **`NTD`**（不是 ISO 的 TWD）。
- 台灣地址：`regionFirst` = 縣市英文（例 `Kaohsiung City`）、`regionSecond` = 區（例 `Qianzhen District`）、`postCode` 5 或 6 碼。
- `declaredValue` 已下線，申報價值以 `parcelInfoList[].amount × quantity` 為準。
- 改單只允許在順豐收件前；`customerOrderNo` 不可與歷史重複（重複錯誤 `123052` 的處理見 `error-codes.md`）。

## 目錄

- [1. 創建物流訂單 — `IUOP_CREATE_ORDER`](#1-創建物流訂單--iuop_create_order)

---

## 1. 創建物流訂單 — `IUOP_CREATE_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=77（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 創建物流訂單；官方更新 2026-09-19；爬取 2026-09-23）

> 注意：請在閱讀了解【開發文檔】相關內容後，再通過此後文檔瞭解具體各種業務報文信息

### 功能描述

```
創建快遞訂單或修改已創建的訂單
注意：僅允許順豐收件前修改
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_CREATE_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | Y | 客戶編碼 |
| 2 | version | String(10) | N | 版本號（默認為空即可） |
| 3 | orderOperateType | Integer | N | 訂單操作類型：<br>**1**-新增<br>**5**-改單<br> 默認1 |
| 4 | customerOrderNo | String(64) | Y | 客戶訂單號 （不能與歷史訂單號重複） |
| 5 | customerOrderNoTwo | String(64) | N | 客戶訂單號2 |
| 6 | sfWaybillNo | String(32) | N | 順豐運單號 |
| 7 | erpCode | String(50) | Y | ERP平台code<br>（1）如果是第三方ERP，請求聯繫技術提供<br>（2）如果是自建系統，可傳固定值：0000 |
| 8 | erpName | String(200) | Y | ERP平台的名稱<br>（1）如果是第三方ERP，可使用英文名或者拼音，如mabang,dianxiaomi等<br>（2）如果是自建系統，可傳固定值：0000 |
| 9 | interProductCode | String(32) | Y | 國際產品映射碼，即順豐物流服務產品代碼<br>參考附錄國際產品映射碼聲明（見 `dictionaries.md`） |
| 10 | parcelQuantity | Int(8) | Y | 包裹總件數(大於1表示是子母件，最大值300) |
| 11 | parcelTotalWeight | BigDecimal(16,2) | N | 包裹總重量。精確到小數點後2位，默認為KG |
| 12 | parcelWeightUnit | String(16) | N | 包裹總重量單位，默認KG |
| 13 | parcelTotalLength | BigDecimal(16,2) | N | 客戶訂單貨物總長。精確到小數點後2位，默認單位CM |
| 14 | parcelTotalWidth | BigDecimal(16,2) | N | 客戶訂單貨物總寬。精確到小數點後2位，默認單位CM |
| 15 | parcelTotalHeight | BigDecimal(16,2) | N | 客戶訂單貨物總高。精確到小數點後2位，默認單位CM |
| 16 | ~~declaredValue~~ | ~~BigDecimal (16,2)~~ | ~~N~~ | ~~總商品申報價值，數值應等於ParcelInfo中所有物品的價值之和~~<br>~~面單和清關數據以托寄物信息中的amount\*quantity的和為準，此處數據不用於清關~~<br>字段已下線，申報價值已托寄物明細中的申報價值為準 |
| 17 | declaredCurrency | String(10) | Y | 申報價值幣種 ，參考附錄幣種聲明（見 `dictionaries.md`） |
| 18 | parcelVolumeUnit | String(16) | N | 客戶訂單貨物長度單位 <br>可選項：**CM**、**INCH** <br>原寄國為CA和US時，長度單位為INCH，其餘國家/地區為CM |
| 19 | pickupType | String(2) | N | 寄件方式：<br> **0**-服務點自寄或自行聯繫快遞員<br> **1**-上門收件<br>( 寄件地址相同的批量訂單如無特殊要求一般請選擇自行聯繫快遞員，若選擇上門收件則順豐小哥會收到上門請求後在約定時間上門取件) |
| 20 | pickupAppointTime | String(20) | N | 上門取件預約時間。格式：yyyy-MM-dd HH:mm 若寄件方式選擇了上門收件，則預約方式必填 |
| 21 | pickupAppointTimeZone | String(100) | N | 上門預約時間時區 詳細參考附錄字典 |
| 22 | remark | String(200) | N | 運單備註 |
| 23 | isBbd | String(8) | N | 0 - 不是；<br>1 - BBD單（需要開通BBD權限）；<br>2 - BBD大單（需提前開通BBD合單（大單）下單權限）； |
| 24 | orderExtendInfo | OrderExtendInfo | N | 訂單額外信息 |
| 25 | paymentInfo | PaymentInfo | Y | 付款方式信息 |
| 26 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 27 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 28 | customsInfo | CustomsInfo | N | 報關信息 |
| 29 | parcelInfoList | List\<ParcelInfo> | Y | 包裹信息 |
| 30 | addServiceInfoList | List\<AddServiceInfo> | N | 增值服務信息 |
| 31 | childInfoList | List\<ChildInfo> | N | 子單信息 |
| 32 | extendAttributeList | List\<OrderExtendAttribute> | N | 擴展屬性，具體字段見附錄 擴展屬性（見 `dictionaries.md`） |
| 33 | ecommerceInfo | OrderEcommerceInfo | N | 電子商務信息 |
| 34 | agentWaybillNo | String(40) | N | 代理運單號 |
| 35 | labelModeSize | Integer | N | 面單打印樣式；默認為1<br>0：面單類100\*210；<br>1：面單類100\*150；<br>2：面單類100\*45（BBD合單包裹條形碼）<br>5：面單類，100\*45(參考編號面單)<br>7：面單類100\*45(BBD合單包裹二維碼)<br>8：面單類100\*45(BBD預合單大包裹條形碼) |
| 36 | isReturnRouteLabel | Integer | N | 是否返回路由標籤：<br> 1：返回路由標籤<br> 0：不返回 |
| 37 | bbdRelation | String | N | BBD 合單關係（BBD大單（isBbd=2），才能傳合單關係）格式：<br>（一個合箱：BBD:實單號\_實單號\_實單號）<br>（兩個合箱：BBD:實單號\_實單號\_實單號,實單號\_實單號）<br>合單改單時請跟ChildInfoList的運單順序一致 |
| 38 | localizedInfo | Map\<String,LocalizedDetail> | N | 本地化信息配置，key為語言，value為對應的語言本地化信息，參考附錄：本地化參數（見 `dictionaries.md`） |

#### 元素<請求>OrderExtendInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | isSignBack | String(8) | N | 是否要求簽回單; <br>**0**-否 <br>**1**-是（簽回原寄地） <br>**2**-是（簽回第三方地址） |
| 2 | isSelfPick | String(8) | N | 是否自取件：<br>**0**-否<br>**1**-是 |
| 3 | signBackWaybillNo | String(32) | N | 簽回回單號 |
| 4 | preMergeSfWaybillNo | String(32) | N | BBD預合單號 |
| 5 | poNumber | String(256) | N | PO Number |
| 6 | importerCompanyName | String(100) | N | 進口商公司名稱 |
| 7 | importerPhoneCode | String(10) | N | 進口商聯繫電話區號 |
| 8 | importerPhoneNumber | String(256) | N | 進口商聯繫電話（3-20位純數字） |
| 9 | importerAddress | String(200) | N | 進口商地址 |
| 10 | importCompTaxNo | String(256) | N | 進口商稅號（如果填寫多個號碼，則用“\|”分割，例如VAT為“GB123456789” ，EORI為“GB123456789000”，則傳值VAT&EORI為“GB123456789 |
| 11 | importCompContacts | String(256) | N | 進口商聯繫人 |
| 12 | importCompEmail | String(256) | N | 進口商郵箱 |
| 13 | isExpressCabinet | String(1) | N | 是否投遞至智能櫃： 0 - 否；1-是 |
| 14 | expressCabinetCode | String(50) | N | 智能櫃信息（智能櫃編碼） |
| 15 | customerInvoiceNo | String(64) | N | 客戶發票號 |
| 16 | customerInvoiceDate | String(30) | N | 客戶發票時間 |

#### 元素<請求>PaymentInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | payMethod | String(8) | Y | 運費付款方式：<br>**1**-寄方付<br>**2**-收方付<br>**3**-第三方付 |
| 2 | payMonthCard | String(20) | N | 月結卡號（月結支付時填寫，現金支付時留空） |
| 3 | taxPayMethod | String(8) | Y | 稅金付款方式：<br>**1**-寄方付<br>**2**-收方付<br>**3**-第三方付<br>（稅金無特殊情況通常為**收方現金支付**，如需寄方支付或第三方支付，稅金月結卡號**必填**且需**簽署額外的稅金結算協議（除中國大陸始發）**，詳情可諮詢順豐客戶經理。非跨境訂單或無稅金產生時選擇收方現金支付即可） |
| 4 | taxPayMonthCard | String(20) | N | 稅金月結卡號 <br>（稅金付款方式選擇寄付或第三方支付時，稅金月結卡號必填，收方現金支付時留空） |

#### 元素<請求>SenderInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | company | String(100) | N | 寄件方公司 |
| 2 | contact | String(100) | Y | 寄件方姓名 |
| 3 | country | String(5) | Y | 寄件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | Y | 郵編 |
| 5 | regionFirst | String(128) | Y | 寄件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | Y | 寄件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | N | 寄件方地址三級區劃（區/縣） |
| 8 | address | String(200) | Y | 寄件方詳細地址 |
| 9 | email | String(128) | N | 寄件方郵箱 |
| 10 | telAreaCode | String(10) | N | 寄件方固定電話區號 |
| 11 | telNo | String(20) | N | 寄件方固定電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 12 | phoneAreaCode | String(10) | N | 寄件方移動電話區號 |
| 13 | phoneNo | String(20) | N | 寄件方移動電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 14 | cargoType | INT(1) | N | 寄件類型：1-個人件；2-公司件（CN/TW/KR始發必填） |
| 15 | certType | String(10) | N | 寄件方證件類型 參考附錄證件類型聲明（見 `dictionaries.md`） |
| 16 | certCardNo | String(100) | N | 寄件方證件號 |
| 17 | vat | String(18) | N | 寄件方VAT號 |
| 18 | eori | String(100) | N | 寄件方EORI號 |
| 19 | iossNo | String(100) | N | 寄件方IOSS號 |
| 20 | lvgNo | String(100) | N | 寄件方LVG號（馬來西亞進口專用，單票500RM以內，過去12個月銷售額超過50萬RM使用） |

#### 元素<請求>ReceiverInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | company | String(100) | N | 收件方公司 |
| 2 | contact | String(100) | Y | 收件方姓名 |
| 3 | country | String(5) | Y | 收件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | Y | 郵編 |
| 5 | regionFirst | String(128) | Y | 收件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | Y | 收件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | N | 收件方地址三級區劃（區/縣） |
| 8 | address | String(200) | Y | 收件方詳細地址 |
| 9 | regionFifth | String(100) | N | 收件方地址五級區劃（道路名，收件方國家為韓國，則必填，長度100） |
| 10 | regionSixth | String(100) | N | 收件方地址六級區劃（建築編號，收件方國家為韓國，則必填） |
| 11 | email | String(128) | N | 收件方郵箱<br>（若收件國家/地區為 US\|MX\|PA\|GT\|AR\|BR\|BO\|EC\|CO\|PE\|SR\|VE\|UY\|CL\|IE\|EE\|AT\|BG\|BE\|PL\|DK\|DE\|FR\|FI\|NL\|CZ\|HR\|LV\|LT<br>LU\|RO\|NO\|PT\|SE\|CH\|SK\|SI\|TR\|ES\|GR\|HU\|IL\|IT\|GB\|AE\|OM\|QA\|JO\|EG\|TG\|CD\|GH\|CM\|KE\|ML\|MA<br>NG\|TZ\|UG\|JP\|IN\|SA\|CA\|ID\|MT\|CY\|VN時必填） |
| 12 | telAreaCode | String(10) | N | 收件方固定電話區號 |
| 13 | telNo | String(20) | N | 收件方固定電話。收件方手機號和收件方固定電話至少填寫一個 |
| 14 | phoneAreaCode | String(10) | N | 收件方移動電話區號 |
| 15 | phoneNo | String(20) | N | 收件方移動電話。收件方手機號和收件方固定電話至少填寫一個 |
| 16 | cargoType | INT(1) | N | 收件類型：<br>**1**-個人件<br>**2**-公司件 |
| 17 | certType | String(10) | N | 收件方證件類型 參考附錄證件類型聲明（見 `dictionaries.md`） （跨境件收件國家/地區為ID\|TW\|KR時必填） |
| 18 | certCardNo | String(100) | N | 收件方證件號 （跨境件收件國家/地區為ID\|TW\|KR時必填） |
| 19 | vat | String(18) | N | 收件方VAT號 |
| 20 | eori | String(100) | N | 收件方EORI號 |
| 21 | contactI18n | String(100) | N | 收件方姓名（多語言） |
| 22 | addressI18n | String(200) | N | 收件方詳細地址（多語言） |
| 23 | receiverTaxAccount | String(50) | N | 收方稅金帳號 |
| 24 | receiverTaxAccountCountry | String(5) | N | 稅金帳號所屬國家 |
| 25 | receiverTaxAccountPostCode | String(20) | N | 稅金帳號所屬郵編 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | tradeCondition | String(30) | N | 貿易條件，參考附錄字典 |
| 2 | senderReason | Int(2) | N | 寄件原因：<br>**1**-商業<br>**2**-非商業 <br>**5**-禮物<br>**6**-銷售<br>**7**-樣品<br>**8**-維修（需聯繫順豐特殊配置） <br>**12**-其它 |
| 3 | senderReasonContent | String(256) | N | 寄件原因內容 |
| 4 | businessRemark | String(256) | N | 商業發票備註 |
| 5 | customsBatch | String(20) | N | 報關批次 |
| 6 | importCustomsType | String(30) | N | 進口報關方式 參考附錄字典 |
| 7 | exportCustomsType | String(30) | N | 出口報關方式 參考附錄字典 |
| 8 | harmonizedCode | String(100) | N | Harmonized Code美國寄出的可填寫 |
| 9 | aesNo | String(100) | N | AES No.美國寄出可填寫 |
| 10 | customsExtInfoMap | CustomsExtInfo | N | 報關擴展信息（9/18生效） |

#### 元素<請求>customsExtInfo 報關擴展信息

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | psuCompanyName | String(100) | N | 生產銷售單位名稱 |
| 2 | psuCompanyCertificateType | String(10) | N | 生產銷售單位證件類型，傳固定值：007 |
| 3 | psuCompanyCertificateNo | String(300) | N | 生產銷售單位證件號碼（需填企業統一社會信用代碼） |

#### 元素<請求>ParcelInfo 海關申報信息

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | name | String(100) | Y | 商品名稱 |
| 2 | eName | String(100) | N | 商品英文名稱 |
| 3 | unit | String(32) | Y | 貨物單位，如：個，台 |
| 4 | amount | DECIMAL(24,10) | Y | 單件商品申報價值 |
| 5 | quantity | DECIMAL(16,6) | Y | 數量 |
| 6 | originCountry | String(5) | Y | 原產國家，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 7 | hsCode | String(100) | N | 海關編碼 （備註：[歐盟官網海關編碼查詢網址](https://trade.ec.europa.eu/access-to-markets/en/my-trade-assistant)） |
| 8 | brand | String(16) | N | 品牌 |
| 9 | stateBarCode | String(64) | N | 國條碼 |
| 10 | productCustomsNo | String(100) | N | 海關備案號 |
| 11 | productRecordNo | String(18) | N | 貨物產品國檢備案編號，商家產品識別碼 |
| 12 | goodsCode | String(60) | N | 商品編號 |
| 13 | goodsUrl | String(1000) | N | 商品鏈接 |
| 14 | weight | DECIMAL | N | 托寄物重量，單件商品SKU重量（不能小於0, 單位KG）。支持小數點後2位 |
| 15 | goodsDesc | String(200) | N | 商品描述 |
| 16 | material | String(600) | N | 材質 |
| 17 | specifications | String(64) | N | 型號 |
| 18 | itemNo | String(30) | N | 商品序號 |
| 19 | qtyOne | Decimal(19,5) | N | 法定第一數量 |
| 20 | unitOne | String(30) | N | 法定第一計量單位 |
| 21 | qtyTwo | Decimal(19,5) | N | 法定第二數量 |
| 22 | unitTwo | String(30) | N | 法定第二計量單位 |
| 23 | htsCode | String(60) | N | 海關編碼(美國) |
| 24 | htsDesc | String(200) | N | 海關編碼描述 |
| 25 | eccn | String(60) | N | 出口管制號碼 |
| 26 | productNameElement | String(1024) | N | 品名申報要素，支持多個要素，要素名使用“[]”標記，多要素使用“,”分隔。 <br>brand：品牌<br>specifications：規格 <br>mode：型號 <br>material：材質 <br>fabricationApproach：製造方式 <br>import\_goods\_value: 進口貨品價值<br>import\_goods\_tax: 進口貨品稅金 <br>Order completion time：訂單完成時間 <br>otpCode： 一次性驗證碼<br>Order NO：客戶訂單號 <br>category\_level\_1：平台商品一級分類 <br>category\_level\_2：平台商品二級分類 <br>Sensitive attributes：敏感屬性<br> Sensitive categories：敏感品類<br>美國CPSC必傳字段： <br>Processing Code：必填，處理代碼(枚舉值：REF，FCP，FGC) <br>1、若為“簡易申報”(Processing Code="REF")以下字段必填： <br>     Product ID：產品註冊號<br>     Version ID：版本號<br>     Certifier ID：進口商識別號 <br>2、若為“兒童產品CPC全量申報”或“其他產品GCC全量申報”(Processing Code="FCP" 或 Processing Code="FGC")以下字段必填：<br>     GTIN UPC：全球唯一產品號碼 <br>     Code Number：產品編號 <br>     Lot Number：生產批次號<br>     Production Start Date：生產開始日期 <br>     Production End Date：生產結束日期 <br>     brand：品牌 <br>     specifications：規格 <br>     Manufacture Month Year：製造月份年份<br>     Identity Number：產品標識型號 <br>     Lab Test Date：實驗室檢測日期 <br>     Certifying Entity Name：認證機構名稱 <br>     Certifying Entity Country：認證機構國家 <br>     Certifying EntityTelephone：認證機構電話 <br>     Certifying Entity Email：認證機構郵箱 <br>     Certify Lab Name：檢測實驗室名稱<br>     Lab CPSC Number：CPSC認證的實驗室編號 <br>     Citation：適用的CPSC法規代碼<br>     Test Report Number：實驗室報告編號 <br>	 Test Report URL：實驗室報告鏈接<br>	 Test Report Access Key：實驗室報告密碼     <br>	 Certifying Entity Address：認證機構地址    <br>	 Certifying Entity City：認證機構城市       <br>	 Certifying Entity State：認證機構州省      <br>	 Certifying Entity Postal Code：認證機構郵編<br>	 Lab Name：檢測機構名稱       <br>	 Lab Address：檢測機構地址    <br>	 Lab City：檢測機構城市       <br>	 Lab State：檢測機構州省      <br>	 Lab Postal Code：檢測機構郵編<br>	 Lab Country：檢測機構國家    <br>	 LabTelephone：檢測機構電話   <br>	 Lab Email：檢測機構郵箱      <br>填寫規範："[{"brand":"test "},{"specifications":"test2"},{"mode":"L3"},{"material":"test"},{"fabricationApproach":"梭織"},{"import\_goods\_value":123},{"import\_goods\_tax":456}]" |
| 27 | sellerVatNumber | String(100) | N | 稅號 |
| 28 | sellerName | String(100) | N | 賣家名稱 |
| 29 | sellerAddress | String(250) | N | 賣家地址 |
| 30 | manufacturerSKU | String(100) | N | 製造商產品識別碼 |
| 31 | gtin | String(100) | N | 國際標準商品編碼 |

#### 元素<請求>AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | serviceCode | String(32) | N | 增值服務編碼（參考附錄增值服務聲明（見 `dictionaries.md`）） |
| 2 | value | String(256) | N | 增值服務擴展屬性 |
| 3 | valueOne | String(256) | N | 增值服務擴展屬性1 |
| 4 | valueTwo | String(256) | N | 增值服務擴展屬性2 |
| 5 | valueThree | String(256) | N | 增值服務擴展屬性3 |
| 6 | valueFour | String(256) | N | 增值服務擴展屬性4 |
| 7 | valueFive | String(256) | N | 增值服務擴展屬性5 |
| 8 | materialInfoList | List\<MaterialInfo> | N | 包裝服務物料信息 |
| 9 | woodPkgList | List\<WoodPkgInfo> | N | 木質包裝服務物料信息 |
| 10 | assemblyInfoList | List\<AssemblyInfo> | N | 宅配延伸服務信息（本 skill 註：宅配延伸服務代碼的查詢接口為未正式發布的第 239 頁「宅配延伸服務類型查詢」，見 `sources.md` §4） |
#### 元素<請求>MaterialInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | materialCode | String(15) | N | 物料編碼 |
| 2 | materialNum | Int(4) | N | 物料數量 |

#### 元素<請求>WoodPkgInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | boxNo | String(64) | N | 箱號 |
| 2 | woodPkgType | String(2) | N | 木質包裝類型 |
| 2 | woodSource | String(2) | N | 托盤類型 |

#### 元素<請求>AssemblyInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | assemblyCode | String(15) | N | 宅配延伸服務編碼（本 skill 註：代碼查詢接口為未正式發布的第 239 頁，見 `sources.md` §4） |
| 2 | assemblyNum | Int(4) | N | 宅配延伸服務類型數量 |

#### 元素<請求>ChildInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | N | 子單編號 |
| 2 | weight | DECIMAL(16,3) | N | 托寄物重量 |
| 3 | length | DECIMAL(17,3) | N | 長，單位釐米 |
| 4 | width | DECIMAL(17,3) | N | 寬，單位釐米 |
| 5 | height | DECIMAL(17,3) | N | 高 |
| 6 | boxNo | String(64) | N | 箱號；箱號在子單集合中不能重複；如果使用箱號，請把包含母單對應的箱號全部傳入 |

#### 元素<請求>OrderExtendAttribute

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | attributeName | String(200) | N | 屬性名 參考附錄字典 擴展屬性（見 `dictionaries.md`） |
| 2 | attributeValue | String(1024) | N | 屬性值 |

#### 元素<請求>OrderEcommerceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | payerCertType | String(200) | N | 訂購人證件類型 |
| 2 | payerCertNo | String(1000) | N | 訂購人證件號碼 |
| 3 | payerName | String(100) | N | 訂購人姓名 |
| 4 | payerTel | String(300) | N | 訂購人聯絡電話 |
| 5 | buyerEmail | String(300) | N | 買家郵件地址 |
| 6 | buyerNickname | String(100) | N | 買家暱稱 |
| 7 | buyerFreight | decimal(10,2) | N | 買家郵費（電商收取消費者的郵費） |
| 8 | buyerPremium | decimal(10,2) | N | 買家保費（電商收取消費者的保價費用） |
| 9 | tax | String(10) | N | 稅款，企業預先代扣的稅款金額 |
| 10 | preferentialAmount | decimal(10,2) | N | 優惠金額（整個訂單的，電商給消費者的優惠金額） |
| 11 | actPay | decimal(10,2) | N | 成交實際付款金額（消費者向電商支付的金額，商品價格+運雜費+代扣稅款-優惠金額） |
| 12 | currency | String(10) | N | 結算幣種 |
| 13 | payToolType | String(100) | N | 支付工具、方式，如支付寶，財付通 |
| 14 | applicationNo | String(100) | N | 申請單編號 |
| 15 | payNo | String(100) | N | 支付交易號 |
| 16 | platformDomainName | String(100) | N | 電商平台域名 |
| 17 | payTime | datetime | N | 支付交易時間 |
| 18 | promotionType | String(100) | N | 促銷類型 |
| 19 | sellerEmail | String(300) | N | 賣家郵件地址 |
| 20 | sellerNickname | String(100) | N | 賣家暱稱 |
| 21 | sellerAlipayNo | String(300) | N | 賣家支付寶帳號 |
| 22 | payEnterpriseCustomsCode | String(20) | N | 支付企業的海關注冊登記編號 |
| 23 | payEnterpriseCheckCode | String(20) | N | 支付企業的國檢註冊登記編號 |
| 24 | payEnterpriseName | String(100) | N | 支付企業名稱 |
| 25 | storeCode | String(50) | N | 店鋪代碼 |
| 26 | shopWebCode | String(100) | N | 購物網站代碼 |
| 27 | enterpriseName | String(100) | N | 電商企業的海關注冊登記名稱 |
| 28 | enterpriseCustomsCode | String(20) | N | 電商企業的海關注冊登記編號 |
| 29 | enterpriseCheckCode | String(20) | N | 電商企業的國檢註冊登記編號 |
| 30 | enterpriseCrossCode | String(20) | N | 電商企業的跨境平台註冊登記編號 |
| 31 | platformName | String(100) | N | 電商平台的海關注冊登記名稱，填寫如shopify,temu等 |
| 32 | platformCustomsCode | String(20) | N | 電商平台的海關注冊登記編號 |
| 33 | platformCheckCode | String(20) | N | 電商平台的國檢註冊登記編號 |
| 34 | partZoneCode | String(32) | N | 分區碼 |
| 35 | portCode | String(32) | N | 口岸 |
| 36 | importCustomsType | String(30) | N | 進口報關方式 |
| 37 | importPlatformCode | String(64) | N | 進口電商平台代碼 |
| 38 | importPlatformName | String(64) | N | 進口電商平台名稱 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼<br>常見錯誤代碼請參考<br>國際快遞常見異常響應（見 `error-codes.md`） |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | OrderRespBody | 否 |  |

#### 元素OrderRespBody

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerOrderNo | String | 訂單號 |
| 2 | sfWaybillNo | String | 運單號 |
| 3 | agentWaybillNo | String | 代理/服務商運單號 |
| 6 | childWaybillNoList | List\<String> | 子運單號 |
| 7 | identityUploadUrl | String | 身份證上傳地址 |
| 8 | invoiceUrl | String | 發票打印地址 |
| 9 | labelUrl | String | 運單打印地址 |
| 10 | customsDeclarationUrl | String | 在線申報平台地址 |
| 11 | routeLabelInfoList | List\<RouteLabelInfo> | 路由標籤信息 |
| 12 | childInfoList | List\<ChildInfo> | 子件信息 |

#### 元素<請求>ChildInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | N | 子單編號 |
| 2 | weight | DECIMAL(16,3) | N | 托寄物重量 |
| 3 | length | DECIMAL(17,3) | N | 長，單位釐米 |
| 4 | width | DECIMAL(17,3) | N | 寬，單位釐米 |
| 5 | height | DECIMAL(17,3) | N | 高 |
| 6 | boxNo | String(64) | N | 箱號；箱號在子單集合中不能重複；如果使用箱號，請把包含母單對應的箱號全部傳入 |

#### 元素routeLabelInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | destRouteLabel | String | 路由標籤 |
| 2 | twoDimensionCode | String | 面單二維碼信息 |
| 3 | codingMapping | String | 入港映射碼 |

#### 明文請求示例

```
{
    "addServiceInfoList": [
        {
            "serviceCode": "",
            "value": "",
            "valueOne": ""
        }
    ],
    "agentWaybillNo": "",
    "childInfoList": [
        {
            "sfWaybillNo": ""
        },
        {
            "sfWaybillNo": ""
        }
    ],
    "customerCode": "ICRME000SRN93",
    "customerOrderNo": "lhtest02201684831024128",
    "customerOrderNoTwo": "",
    "customsInfo": {
        "aesNo": "",
        "businessRemark": "",
        "customsBatch": "",
        "harmonizedCode": "",
        "senderReasonContent": "",
        "tradeCondition": ""
    },
    "declaredCurrency": "USD",
    "declaredValue": 10,
    "ecommerceInfo": {
        "actPay": 1221,
        "applicationNo": "1231231321",
        "buyerEmail": "12312@dd.com",
        "buyerFreight": 123132,
        "buyerNickname": "zhagnsan21",
        "buyerPremium": 122,
        "currency": "TWD",
        "enterpriseCheckCode": "1232",
        "enterpriseCrossCode": "12342",
        "enterpriseCustomsCode": "电商企业的海关注册登记编号21",
        "enterpriseName": "电商企业的海关注册登记编号2",
        "payEnterpriseCheckCode": "22212",
        "payEnterpriseCustomsCode": "22222",
        "payNo": "123123123",
        "payTime": "2022-03-31 15:01:42",
        "payToolType": "zhifubao",
        "payerCertNo": "3331",
        "payerCertType": "001",
        "payerName": "zhifubao",
        "payerTel": "1231231231",
        "platformCheckCode": "44441",
        "platformCustomsCode": "电商平台的海关注册登记编号",
        "platformDomainName": "ali.com",
        "platformName": "电商平台的海关注册登记名称",
        "preferentialAmount": 12312,
        "promotionType": "1",
        "sellerAlipayNo": "5551",
        "sellerEmail": "1312@ww.com",
        "sellerNickname": "lisi2",
        "shopWebCode": "购物网站代码21",
        "storeCode": "12378",
        "tax": "6666"
    },
    "extendAttributeList": [
        {
            "attributeName": "discountFreight",
            "attributeValue": "200.00"
        },
        {
            "attributeName": "customizeFreightFeeCurrency",
            "attributeValue": "CNY"
        },
        {
            "attributeName": "taxNO",
            "attributeValue": "xxx"
        }
    ],
    "interProductCode": "INT0014",
    "isBbd": 0,
    "orderExtendInfo": {
        "businessMode": "0",
        "isSelfPick": "",
        "isSignBack": "",
        "operEmpCode": "000212",
        "senderDeptCode": "755A",
        "signBackWaybillNo": ""
    },
    "orderOperateType": "1",
    "parcelInfoList": [
        {
            "amount": 0.1,
            "brand": "",
            "currency": "",
            "goodsCode": "",
            "goodsDesc": "",
            "goodsUrl": "",
            "hsCode": "",
            "name": "iPhone",
            "originCountry": "SG",
            "productCustomsNo": "",
            "productRecordNo": "",
            "quantity": 1,
            "stateBarCode": "",
            "unit": "个"
        }
    ],
    "parcelQuantity": 2,
    "parcelTotalHeight": "1",
    "parcelTotalLength": "1",
    "parcelTotalWeight": 0.5,
    "parcelTotalWidth": "1",
    "parcelVolumeUnit": "CM",
    "parcelWeightUnit": "KG",
    "paymentInfo": {
        "payMethod": "1",
        "payMonthCard": "0270000250",
        "taxPayMethod": "2",
        "taxPayMonthCard": ""
    },
    "pickupAppointTime": "",
    "pickupAppointTimeZone": "",
    "pickupType": "0",
    "receiverInfo": {
        "address": "新加坡 详细地址",
        "cargoType": 1,
        "certCardNo": "430527199309204211",
        "certType": "001",
        "company": "",
        "contact": "李想",
        "country": "CN",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "86",
        "phoneNo": "18888800000",
        "postCode": "536727",
        "regionFirst": "guang dong",
        "regionSecond": "shen zhen",
        "regionThird": "bao an",
        "telAreaCode": "86",
        "telNo": "9516168888",
        "vat": ""
    },
    "remark": "",
    "senderInfo": {
        "address": "306 S BRYN MAWR AVE",
        "cargoType": 1,
        "certCardNo": "",
        "certType": "001",
        "company": "",
        "contact": "宏宏宏少",
        "country": "US",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "65",
        "phoneNo": "54875485",
        "postCode": "759973",
        "regionFirst": "Alaska",
        "regionSecond": "Juneau",
        "regionThird": "Borough",
        "telAreaCode": "86",
        "telNo": "9516168888",
        "vat": ""
    },
    "sfWaybillNo": "",
    "version": ""
}
```

#### 明文響應示例

```
{
    "apiErrorMsg": "",
    "apiResultCode": 0,
    "apiResultData": {
        "msg": "执行成功",
        "code": "0",
        "data": {
            "identityUploadUrl": "http://iuop-uat.sit.sf-express.com:9000/#/customsDoc?T3tMAGF5XZEar7dbtxYUaw==",
            "childWaybillNoList": [
                "SF2020293516474"
            ],
            "invoiceUrl": "http://iuop-uat.sit.sf-express.com:9000/iuop-iuop-uat/api/print/printInvoice?sfWaybillNos=T3tMAGF5XZEar7dbtxYUaw%3D%3D",
            "labelUrl": "http://iuop-uat.sit.sf-express.com:9000/iuop-iuop-uat/api/print/printLabel?sfWaybillNos=T3tMAGF5XZEar7dbtxYUaw%3D%3D",
            "customerOrderNo": "lhtest02201684831024128",
            "sfWaybillNo": "SF1334452877497"
        },
        "success": true
    },
    "apiTimestamp": 1684831138165
}
```
