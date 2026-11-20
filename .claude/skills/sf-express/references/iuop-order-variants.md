# IUOP 建單變體：預創建（校驗）與信任重量下單

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：
- 想在正式建單前**只校驗**訂單資料（`IUOP_PRE_ORDER`，`validateModule=1` 可一次回傳全部校驗錯誤明細）。
- 已與順豐簽「信任重量 / 二次信任重量」模式（需網點代碼 `senderDeptCode`、操作員工號 `operEmpCode`），用 `IUOP_CREATE_ORDER` 並帶 `orderExtendInfo.businessMode`。
一般台灣電商寄件不需要信任重量模式，照 `iuop-create-order.md` 即可。

與標準建單的差異重點：
- 預創建頁：收件方 `certType/certCardNo` 寫「跨境件收件國家/地區為 **ID,TW** 時必填」（標準建單頁寫 ID|TW|KR）；收件方 email 必填國家清單較短；`productNameElement` 多要素分隔符寫「;」（標準建單頁寫「,」）——**兩頁不一致，以實測為準**。
- 信任重量頁：`customerOrderNo` 非必填、`declaredValue` 必填、`ParcelInfo.name` 長度 30、無 `erpCode/erpName`、響應多了 `directionCode` / `agentCode`（IECS 特有）等欄位。

## 目錄

- [1. 預創建物流訂單 — `IUOP_PRE_ORDER`](#1-預創建物流訂單--iuop_pre_order)
- [2. 創建物流訂單【信任重量】 — `IUOP_CREATE_ORDER`](#2-創建物流訂單信任重量--iuop_create_order)

---

## 1. 預創建物流訂單 — `IUOP_PRE_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=210（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 預創建物流訂單；官方更新 2026-01-12；爬取 2026-09-23）

### 功能描述

```
預創建快遞訂單,校驗訂單信息
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_PRE_ORDER`。

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
| 7 | interProductCode | String(32) | Y | 國際產品映射碼，即順豐物流服務產品代碼<br>參考附錄國際產品映射碼聲明（見 `dictionaries.md`） |
| 8 | parcelQuantity | Int(8) | Y | 包裹總件數(大於1表示是子母件，最大值300) |
| 9 | parcelTotalWeight | BigDecimal(16,2) | N | 包裹總重量。精確到小數點後2位，默認為KG |
| 10 | parcelWeightUnit | String(16) | N | 包裹總重量單位，默認KG |
| 11 | parcelTotalLength | BigDecimal(16,2) | N | 客戶訂單貨物總長。精確到小數點後2位，默認單位CM |
| 12 | parcelTotalWidth | BigDecimal(16,2) | N | 客戶訂單貨物總寬。精確到小數點後2位，默認單位CM |
| 13 | parcelTotalHeight | BigDecimal(16,2) | N | 客戶訂單貨物總高。精確到小數點後2位，默認單位CM |
| 14 | declaredValue | BigDecimal (16,2) | N | 總商品申報價值，數值應等於ParcelInfo中所有物品的價值之和 |
| 15 | declaredCurrency | String(10) | Y | 申報價值幣種 ，參考附錄幣種聲明（見 `dictionaries.md`） |
| 16 | parcelVolumeUnit | String(16) | N | 客戶訂單貨物長度單位 <br>可選項：**CM**、**INCH** <br>原寄國為CA和US時，長度單位為INCH，其餘國家/地區為CM |
| 17 | pickupType | String(2) | N | 寄件方式：<br> **0**-服務點自寄或自行聯繫快遞員<br> **1**-上門收件<br>( 寄件地址相同的批量訂單如無特殊要求一般請選擇自行聯繫快遞員，若選擇上門收件則順豐小哥會收到上門請求後在約定時間上門取件) |
| 18 | pickupAppointTime | String(20) | N | 上門取件預約時間。格式：yyyy-MM-dd HH:mm 若寄件方式選擇了上門收件，則預約方式必填 |
| 19 | pickupAppointTimeZone | String(100) | N | 上門預約時間時區 詳細參考附錄字典 |
| 20 | remark | String(200) | N | 運單備註 |
| 21 | isBbd | String(8) | N | 是否BBD 0-不是 1-BBD單（需提前開通BBD權限）； |
| 22 | orderExtendInfo | OrderExtendInfo | N | 訂單額外信息 |
| 23 | paymentInfo | PaymentInfo | Y | 付款方式信息 |
| 24 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 25 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 26 | customsInfo | CustomsInfo | N | 報關信息 |
| 27 | parcelInfoList | List\<ParcelInfo> | Y | 包裹信息 |
| 28 | addServiceInfoList | List\<AddServiceInfo> | N | 增值服務信息 |
| 29 | childInfoList | List\<ChildInfo> | N | 子單信息 |
| 30 | extendAttributeList | List\<OrderExtendAttribute> | N | 擴展屬性，具體字段見附錄 擴展屬性（見 `dictionaries.md`） |
| 31 | ecommerceInfo | OrderEcommerceInfo | N | 電子商務信息 |
| 32 | agentWaybillNo | String(40) | N | 代理運單號 |
| 33 | labelModeSize | Integer | N | 面單打印樣式；默認為1<br>0：面單類100\*210；<br>1：面單類100\*150；<br>2：面單類100\*45（BBD合單包裹條形碼）<br>5：面單類，100\*45(參考編號面單)<br>7：面單類100\*45(BBD合單包裹二維碼)<br>8：面單類100\*45(BBD預合單大包裹條形碼) |
| 34 | isReturnRouteLabel | Integer | N | 是否返回路由標籤：<br> 1：返回路由標籤<br> 0：不返回 |
| 35 | validateModule | Integer | N | 校驗模式：<br> 0：通用校驗（默認）；<br> 1：並行校驗 |

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
| 10 | importCompTaxNo | String(256) | N | 進口商稅號 |
| 11 | importCompContacts | String(256) | N | 進口商聯繫人 |
| 12 | importCompEmail | String(256) | N | 進口商郵箱 |

#### 元素<請求>PaymentInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | payMethod | String(8) | Y | 運費付款方式：<br>**1**-寄方付<br>**2**-收方付<br>**3**-第三方付 |
| 2 | payMonthCard | String(20) | N | 月結卡號（月結支付時填寫，現金支付時留空） |
| 3 | taxPayMethod | String(8) | Y | 稅金付款方式：<br>**1**-寄方付<br>**2**-收方付<br>**3**-第三方付<br>（稅金無特殊情況通常為**收方現金支付**，如需寄方支付或第三方支付，稅金月結卡號**必填**且需**簽署額外的稅金結算協議**，詳情可諮詢順豐客戶經理。非跨境訂單或無稅金產生時選擇收方現金支付即可） |
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
| 11 | email | String(128) | N | 收件方郵箱<br>（若收件國家/地區為GB\|IE\|NL\|MT\|DE\|FR\|ES\|IT\|BE\|AT\|HU\|PT\|DK\|CZ\|HR\|GR\|BG\|LU\|SI\|SK\|RO\|CY\|SE\|FI\|LT\|LV\|PL\|EE\|IN\|CH\|VN 時必填） |
| 12 | telAreaCode | String(10) | N | 收件方固定電話區號 |
| 13 | telNo | String(20) | N | 收件方固定電話。收件方手機號和收件方固定電話至少填寫一個 |
| 14 | phoneAreaCode | String(10) | N | 收件方移動電話區號 |
| 15 | phoneNo | String(20) | N | 收件方移動電話。收件方手機號和收件方固定電話至少填寫一個 |
| 16 | cargoType | INT(1) | N | 收件類型：<br>**1**-個人件<br>**2**-公司件 |
| 17 | certType | String(10) | N | 收件方證件類型 參考附錄證件類型聲明（見 `dictionaries.md`） （跨境件收件國家/地區為ID,TW時必填） |
| 18 | certCardNo | String(100) | N | 收件方證件號 （跨境件收件國家/地區為ID,TW時必填） |
| 19 | vat | String(18) | N | 收件方VAT號 |
| 20 | eori | String(100) | N | 收件方EORI號 |
| 21 | contactI18n | String(100) | N | 收件方姓名（多語言） |
| 22 | addressI18n | String(200) | N | 收件方詳細地址（多語言） |
| 23 | receiverTaxAccount| String(50) | N | 收方稅金帳號 |
| 24 | receiverTaxAccountCountry| String(5) | N | 稅金帳號所屬國家 |
| 25 | receiverTaxAccountPostCode| String(20) | N | 稅金帳號所屬郵編 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | tradeCondition | String(30) | N | 貿易條件，參考附錄字典 |
| 2 | senderReason | Int(2) | N | 寄件原因：<br>**1**-商業<br>**2**-非商業 |
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
| 2 | psuCompanyCertificateType | String(10) | N | 生產銷售單位證件類型 |
| 3 | psuCompanyCertificateNo | String(300) | N | 生產銷售單位證件號碼 |

#### 元素<請求>ParcelInfo 海關申報信息

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | name | String(100) | Y | 商品名稱 |
| 2 | eName | String(100) | N | 商品英文名稱 |
| 3 | unit | String(32) | Y | 貨物單位，如：個，台 |
| 4 | amount | DECIMAL(24,10) | Y | 單件商品申報價值 |
| 5 | quantity | DECIMAL(16,6) | Y | 數量 |
| 6 | originCountry | String(5) | Y | 原產國家，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 7 | hsCode | String(100) | N | 海關編碼 |
| 8 | brand | String(16) | N | 品牌 |
| 9 | stateBarCode | String(64) | N | 國條碼 |
| 10 | productCustomsNo | String(100) | N | 海關備案號 |
| 11 | productRecordNo | String(18) | N | 貨物產品國檢備案編號 |
| 12 | goodsCode | String(60) | N | 商品編號 |
| 13 | goodsUrl | String(2000) | N | 商品鏈接 |
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
| 26 | productNameElement | String(1024) | N | 品名申報要素，支持多個要素，要素名使用“[]”標記，多要素使用“;”分隔，<br>填寫規範："[{"brand":"test "},{"specifications":"test2"},{"mode":"L3"}]" |
| 27 | sellerVatNumber | String(100) | N | 稅號 |
| 28 | sellerName | String(100) | N | 賣家名稱 |
| 29 | sellerAddress | String(250) | N | 賣家地址 |

#### 元素<請求>AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | serviceCode | String(32) | N | 增值服務編碼（參考附錄增值服務聲明（見 `dictionaries.md`）） |
| 2 | value | String(256) | N | 增值服務擴展屬性 |
| 3 | valueOne | String(256) | N | 增值服務擴展屬性1 |

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
| 1 | attributeName | String(200) | N | 屬性名 參考附錄字典 |
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
| 31 | platformName | String(100) | N | 電商平台的海關注冊登記名稱 |
| 32 | platformCustomsCode | String(20) | N | 電商平台的海關注冊登記編號 |
| 33 | platformCheckCode | String(20) | N | 電商平台的國檢註冊登記編號 |
| 34 | partZoneCode | String(32) | N | 分區碼 |
| 35 | portCode | String(32) | N | 口岸 |
| 36 | importCustomsType | String(30) | N | 進口報關方式 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 | 返回對象結果，當code 不為0時，<br>validateModule = 0返回為空<br>validateModule = 1返回data，包含校驗錯誤明細 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | errorDtoList | List\<ErrorInfo> | 錯誤信息（當validateModule = 1時） |

#### 元素ErrorInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | code | String | 業務錯誤碼 |
| 2 | message | String | 錯誤描述 |

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
    "agentWaybillNo": "AG202007060053",
    "cargoTypeCode": "C223",
    "childInfoList": [
        {
            "height": "3.21",
            "length": "1.31",
            "sfWaybillNo": "",
            "weight": "4.21",
            "width": "2.31"
        },
        {
            "height": "3.21",
            "length": "1.31",
            "sfWaybillNo": "",
            "weight": "4.21",
            "width": "2.31"
        }
    ],
    "customerCode": "ICRME000SRN93",
    "customerOrderNo": "lhtest1660790984776",
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
    "interProductCode": "INT0014",
    "isBbd": 1,
    "orderExtendInfo": {
        "businessMode": "0",
        "isSelfPick": "",
        "isSignBack": "",
        "operEmpCode": "000212",
        "senderDeptCode": "755A",
        "signBackWaybillNo": ""
    },
    "orderNo": "kts_api20210701238401805604",
    "orderNoTwo": "IUOP-test2-20102019117023144",
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
        "city": "106A",
        "company": "",
        "contact": "李想",
        "country": "CN",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "86",
        "phoneNo": "18888800000",
        "postCode": "536727",
        "province": "Ah Soo Walk",
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
        "city": "11A",
        "company": "",
        "contact": "宏宏宏少",
        "country": "US",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "65",
        "phoneNo": "54875485",
        "postCode": "759973",
        "province": "Admiralty Road East",
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
    "code": "0",
    "msg": "执行成功",
    "success": true
}
```

---

## 2. 創建物流訂單【信任重量】 — `IUOP_CREATE_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=87（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 創建物流訂單【信任重量】；官方更新 2026-01-19；爬取 2026-09-23）

### 功能描述

```
創建快遞訂單
支持【信任重量】【二次信任重量】模式下單
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
| 3 | orderOperateType | Integer | N | 訂單操作類型：1-新增；5-改單； 默認1<br>【二次相信重量下單】：傳遞1 <br>【二次相信重量改單】：傳遞5 |
| 4 | customerOrderNo | String(64) | N | 客戶訂單號 |
| 5 | customerOrderNoTwo | String(64) | N | 客戶訂單號2 |
| 6 | sfWaybillNo | String(32) | N | 順豐運單號 |
| 7 | interProductCode | String(32) | Y | 國際產品映射碼 |
| 8 | parcelQuantity | Int(8) | Y | 包裹總件數(大於1表示是子母件，最大值300) <br>【相信重量下單】：會校驗包裹數和子單數量是否匹配 <br>【二次相信重量下單】：一票多件會校驗包裹數和子單數量是否匹配 <br>【二次相信重量改單】：一票多件會校驗包裹數和子單數量是否匹配 |
| 9 | parcelTotalWeight | BigDecimal(16,2) | N | 包裹總重量（不能小於等於0）。精確到小數點後2位，默認為KG <br>【相信重量下單】：必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：必填 |
| 10 | parcelWeightUnit | String(16) | N | 包裹總重量單位，默認KG -KG; -LB; <br>【相信重量下單】：必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：必填 |
| 11 | parcelTotalLength | BigDecimal(16,2) | N | 客戶訂單貨物總長。精確到小數點後2位，默認單位CM <br>【相信重量下單】 ：一票一件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：一票一件時必填 |
| 12 | parcelTotalWidth | BigDecimal(16,2) | N | 客戶訂單貨物總寬。精確到小數點後2位，默認單位CM <br>【相信重量下單】 ：一票一件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：一票一件時必填 |
| 13 | parcelTotalHeight | BigDecimal(16,2) | N | 客戶訂單貨物總高。精確到小數點後2位，默認單位CM <br>【相信重量下單】 ：一票一件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：一票一件時必填 |
| 14 | declaredValue | BigDecimal(16,2) | Y | 申明價值 |
| 15 | declaredCurrency | String(10) | Y | 包裹總計聲明價值幣種 |
| 16 | parcelVolumeUnit | String(16) | N | 客戶訂單貨物長度單位 1.可選項：CM、INCH 2.默認單位為CM 3.原寄國為CA和US，長度單位為英寸 <br>【相信重量下單】：僅支持CM <br>【二次相信重量改單】：僅支持CM |
| 17 | pickupType | String(2) | N | 寄件方式： 0-服務點自寄或自行聯繫快遞員； 1-上門收件； |
| 18 | pickupAppointTime | String(20) | N | 上門取件預約時間。格式：yyyy-MM-dd HH:mm 若寄件方式選擇了上門收件，則預約方式必填 |
| 19 | pickupAppointTimeZone | String(100) | N | 上門預約時間時區 詳細參考附錄字典 |
| 20 | remark | String(200) | N | 運單備註 |
| 21 | isBbd | String(8) | N | 是否BBD 0-不是 1-BBD單（需提前開通BBD權限）； |
| 22 | orderExtendInfo | OrderExtendInfo | N | 訂單額外信息 |
| 23 | paymentInfo | PaymentInfo | Y | 付款方式信息 |
| 24 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 25 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 26 | customsInfo | CustomsInfo | N | 報關信息 |
| 27 | parcelInfoList | List\<ParcelInfo> | Y | 包裹信息 |
| 28 | addServiceInfoList | List\<AddServiceInfo> | N | 增值服務信息 |
| 29 | childInfoList | List\<ChildInfo> | N | 子單信息 |
| 30 | extendAttributeList | List\<OrderExtendAttribute> | N | 擴展屬性 |
| 31 | ecommerceInfo | OrderEcommerceInfo | N | 電子商務信息 |
| 32 | agentWaybillNo | String(40) | N | 代理運單號 |

#### 元素<請求>OrderExtendInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | isSignBack | String(8) | N | 是否要求簽回單; 0.否 1.是（簽回原寄地） 2.是（簽回第三方地址） |
| 2 | isSelfPick | String(8) | N | 是否自取件：0-否；1-是 |
| 3 | signBackWaybillNo | String(32) | N | 簽回回單號 |
| 4 | businessMode | String(30) | N | 業務模式：默認0 <br>0-通用下單 <br>1-信任重量 <br>2-二次信任重量 |
| 5 | senderDeptCode | String(30) | N | 寄件網點代碼 <br>【信任重量下單】：必填 <br>【二次信任重量下單】：非必填 <br>【二次信任重量改單】：必填 |
| 6 | operEmpCode | String(30) | N | 操作員工號 <br>【信任重量下單】：必填 <br>【二次信任重量下單】：非必填 <br>【二次信任重量改單】：必填 |
| 7 | preMergeSfWaybillNo | String(32) | N | BBD預合單號 |

#### 元素<請求>PaymentInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | payMethod | String(8) | Y | 付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 2 | payMonthCard | String(20) | N | 月結卡號 |
| 3 | taxPayMethod | String(8) | Y | 稅金付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 4 | taxPayMonthCard | String(20) | N | 稅金結算帳號 （稅金付款方式taxPayMethod選擇寄付時，此稅金月結帳號必填） |

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
| 14 | cargoType | INT(1) | N | 寄件方貨物類型：1-個人件；2-公司件 |
| 15 | certType | String(10) | N | 寄件方證件類型 詳細參考附錄字典 |
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
| 9 | regionFifth | String(200) | N | 收件方地址五級區劃（道路名，收件方國家為韓國，則必填，長度100） |
| 10 | regionSixth | String(100) | N | 收件方地址六級區劃（建築編號，收件方國家為韓國，則必填） |
| 11 | email | String(128) | N | 收件方郵箱 |
| 12 | telAreaCode | String(10) | N | 收件方固定電話區號 |
| 13 | telNo | String(20) | N | 收件方固定電話。收件方手機號和收件方固定電話至少填寫一個 |
| 14 | phoneAreaCode | String(10) | N | 收件方移動電話區號 |
| 15 | phoneNo | String(20) | N | 收件方移動電話。收件方手機號和收件方固定電話至少填寫一個 |
| 16 | cargoType | INT(1) | N | 收件方貨物類型：1-個人件；2-公司件 |
| 17 | certType | String(10) | N | 收件方證件類型 詳細參考附錄字典 |
| 18 | certCardNo | String(100) | N | 收件方證件號 |
| 19 | vat | String(18) | N | 收件方VAT號 |
| 20 | eori | String(100) | N | 收件方EORI號 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | tradeCondition | String(30) | N | 貿易條件，參考附錄字典 |
| 2 | senderReason | Int(2) | N | 寄件原因：1-商業；2-非商業 |
| 3 | senderReasonContent | String(256) | N | 寄件原因內容 |
| 4 | businessRemark | String(256) | N | 商業發票備註 |
| 5 | customsBatch | String(20) | N | 報關批次 |
| 6 | importCustomsType | String(30) | N | 進口報關方式 參考附錄字典 |
| 7 | exportCustomsType | String(30) | N | 出口報關方式 參考附錄字典 |
| 8 | harmonizedCode | String(100) | N | Harmonized Code美國寄出的可填寫 |
| 9 | aesNo | String(100) | N | AES No.美國寄出可填寫 |

#### 元素<請求>ParcelInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | name | String(30) | Y | 商品名稱（默認使用，可以是各個國家的語言名稱） （備註：有效長度30字符；超出30字符，請聯繫對接服務支持人員確認是否可行） |
| 2 | eName | String(128) | N | 商品英文名稱 |
| 3 | unit | String(32) | Y | 貨物單位，如：個，台 |
| 4 | amount | DECIMAL(24,10) | Y | 單價 |
| 5 | quantity | DECIMAL(16,6) | Y | 數量 |
| 6 | originCountry | String(5) | Y | 原產國家 |
| 7 | hsCode | String(100) | N | 海關編碼 |
| 8 | brand | String(16) | N | 品牌 |
| 9 | stateBarCode | String(64) | N | 國條碼 |
| 10 | productCustomsNo | String(100) | N | 海關備案號 |
| 11 | productRecordNo | String(18) | N | 貨物產品國檢備案編號 |
| 12 | goodsCode | String(60) | N | 商品編號 |
| 13 | goodsUrl | String(2000) | N | 商品鏈接 |
| 14 | weight | DECIMAL(16,2) | N | 托寄物重量，單件商品SKU重量（不能小於等於0, 單位KG）。 |
| 15 | goodsDesc | String(200) | N | 商品描述 |
| 16 | material | String(600) | N | 材質 |
| 17 | specifications | String(64) | N | 型號 |

#### 元素<請求>AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | serviceCode | String(32) | Y | 增值服務編碼 |
| 2 | value | String(256) | N | 增值服務擴展屬性 |
| 3 | valueOne | String(256) | N | 增值服務擴展屬性1 |

#### 元素<請求>ChildInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | N | 子單編號 |
| 2 | weight | DECIMAL(16,3) | N | 托寄物重量 （不能小於等於0, 單位KG）<br>【相信重量下單】：為一票多件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：為一票多件時必填 |
| 3 | length | DECIMAL(17,3) | N | 長，單位釐米 <br>【相信重量下單】：為一票多件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：為一票多件時必填 |
| 4 | width | DECIMAL(17,3) | N | 寬，單位釐米 <br>【相信重量下單】：為一票多件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：為一票多件時必填 |
| 5 | height | DECIMAL(17,3) | N | 高，單位釐米 <br>【相信重量下單】：為一票多件時必填 <br>【二次相信重量下單】：非必填 <br>【二次相信重量改單】：為一票多件時必填 |

#### 元素<請求>OrderExtendAttribute

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | attributeName | String(200) | N | 屬性名 參考附錄字典 |
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
| 31 | platformName | String(100) | N | 電商平台的海關注冊登記名稱 |
| 32 | platformCustomsCode | String(20) | N | 電商平台的海關注冊登記編號 |
| 33 | platformCheckCode | String(20) | N | 電商平台的國檢註冊登記編號 |
| 34 | partZoneCode | String(32) | N | 分區碼 |
| 35 | portCode | String(32) | N | 口岸 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | OrderRespBody | 否 |  |

#### 元素OrderRespBody

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerOrderNo | String | 訂單號 |
| 2 | sfWaybillNo | String | 運單號 |
| 3 | agentWaybillNo | String | 代理/服務商運單號 |
| 4 | directionCode | String | 流向代碼（IECS電商業務特有字段） |
| 5 | agentCode | String | 代理編號（IECS電商業務特有字段） |
| 6 | childWaybillNoList | List\<String> | 子運單號（IUOP快遞業務特有字段） |
| 7 | identityUploadUrl | String | 身份證上傳地址（IUOP快遞業務特有字段） |
| 8 | invoiceUrl | String | 發票打印地址（IUOP快遞業務特有字段） |
| 9 | labelUrl | String | 運單打印地址（IUOP快遞業務特有字段） |

#### 明文請求示例

```
{
    "addServiceInfoList": [],
    "customerCode": "ICRMXXXXXXXX",
    "customerOrderNo": "kts_api20210701840182504",
    "customerOrderNoTwo": "IUOP-test2-20102911702344",
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
    "interProductCode": "INT0007",
    "orderExtendInfo": {
        "isSelfPick": "",
        "isSignBack": "",
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
    "parcelQuantity": 1,
    "parcelTotalWeight": 0.5,
    "parcelVolumeUnit": "",
    "parcelWeightUnit": "KG",
    "paymentInfo": {
        "payMethod": "1",
        "payMonthCard": "",
        "taxPayMethod": "2",
        "taxPayMonthCard": ""
    },
    "pickupAppointTime": "",
    "pickupAppointTimeZone": "",
    "pickupType": "0",
    "receiverInfo": {
        "address": "新加坡详细地址",
        "cargoType": 1,
        "certCardNo": "",
        "certType": "",
        "company": "顺丰速运",
        "contact": "李想",
        "country": "SG",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "65",
        "phoneNo": "54875485",
        "postCode": "536727",
        "regionFirst": "AhSooWalk",
        "regionSecond": "106A",
        "regionThird": "",
        "telAreaCode": "86",
        "telNo": "9516168888",
        "vat": ""
    },
    "remark": "",
    "senderInfo": {
        "address": "306SBRYNMAWRAVE",
        "cargoType": 1,
        "certCardNo": "",
        "certType": "",
        "company": "",
        "contact": "姓名测试",
        "country": "SG",
        "email": "iuop@test.com",
        "eori": "",
        "phoneAreaCode": "65",
        "phoneNo": "54875485",
        "postCode": "759973",
        "regionFirst": "AdmiraltyRoadEast",
        "regionSecond": "11A",
        "regionThird": "",
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
    "code": "0",
    "data": {
        "childWaybillNo": [],
        "customerOrderNo": "kts_api2021070123840182504",
        "identityUploadUrl": "",
        "invoiceUrl": "http://iuop-uat.sit.sf-express.com:9000/iuop-iuop-uat/api/print/printInvoice?sfWaybillNos=vwUPpzzE4oo0zZ9GDqsROA%3D%3D",
        "labelUrl": "http://iuop-uat.sit.sf-express.com:9000/iuop-iuop-uat/api/print/printLabel?sfWaybillNos=vwUPpzzE4oo0zZ9GDqsROA%3D%3D",
        "sfWaybillNo": "SF1324481256950"
    },
    "msg": "Executedsuccessfully",
    "success": true
}
```
