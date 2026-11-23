# 出口電商（IECS）接口：電商小包下單 / 面單 / 取消 / 攔截 / 服務商單號 / 重量運費

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：使用「電商IECS」類產品（`INT0255` 國際電商專遞-標準、`INT0257` 快速、`INT0263` 國際電商專遞-CD、`INT0054` 國際小包等，見 `dictionaries.md` 國際件產品映射碼）時。

⚠️ 邊界：官方明寫「**IECS 出口電商業務通常僅有 CN、HK 始發出口流向**」。台灣寄出的包裹一般走快遞 IUOP（`iuop-create-order.md`）；IECS 只在「大陸 / 香港 → 台灣」跨境電商小包時才可能用到，實際可用產品要問客戶經理。

與 IUOP 的關鍵差異：
- body 用 `apiUsername`（IUOP 用戶名，沙箱 `popsandBox`）而非 `customerCode`；沒帶或未綁客戶編碼會回「没有此用户 / 用户越权」。
- **建單後不能改單**，要改只能先 `IECS_CANCEL_ORDER`（僅攬收前）再重建。
- `parcelQuantity` 固定 1；`isBat`（是否帶電）必填；寄/收件人地址、姓名多數流向**禁止中文**（見 `error-codes.md` IECS 表）。
- 推送路由描述只有英文。

## 目錄

- [1. 創建物流訂單 — `IECS_CREATE_ORDER`](#1-創建物流訂單--iecs_create_order)
- [2. 獲取發貨面單 — `IECS_PRINT_ORDER`](#2-獲取發貨面單--iecs_print_order)
- [3. 取消電商訂單 — `IECS_CANCEL_ORDER`](#3-取消電商訂單--iecs_cancel_order)
- [4. 訂單攔截 — `IECS_ORDER_INTERCEPT`](#4-訂單攔截--iecs_order_intercept)
- [5. 服務商單號查詢 — `IECS_QUERY_AGENTNO`](#5-服務商單號查詢--iecs_query_agentno)
- [6. 重量及運費查詢 — `IECS_QUERY_WEIGHT_FREIGHT`](#6-重量及運費查詢--iecs_query_weight_freight)
- [7. 小包上傳重量 — `IECS_UPLOAD_WEIGHT`](#7-小包上傳重量--iecs_upload_weight)
- [8. 導入包票關係 — `IECS_IMPORT_PACKAGE`](#8-導入包票關係--iecs_import_package)
- [9. 電商發貨/簽收證明 — `IECS_QUERY_POD`](#9-電商發貨簽收證明--iecs_query_pod)
- [10. 尾程代理傳輸 — `IECS_AGENT_TRANS`](#10-尾程代理傳輸--iecs_agent_trans)
- [11. 大包攬收-大小包關係綁定 — `IECS_BIG_PACKAGE_BIND`](#11-大包攬收-大小包關係綁定--iecs_big_package_bind)

---

## 1. 創建物流訂單 — `IECS_CREATE_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=97（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 創建物流訂單；官方更新 2026-09-21；爬取 2026-09-23）

### 功能描述

```
創建電商訂單
IECS出口電商業務通常僅有CN、HK始發出口流向
注意：創建物流訂單之後，不支持修改訂單。如果需要修改訂單，需先調用"取消電商訂單"接口，再重新創建物訂單。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_CREATE_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | apiUsername | String(30) | Y | 客戶名稱（小包用戶名，可登錄IUOP管理中心獲取） |
| 2 | customerOrderNo | String(64) | Y | 客戶訂單號，要求唯一值，不能重複 |
| 3 | platformOrderId | String(50) | Y | 平台訂單號，如果是第三方ERP平台，可採用平台的訂單號，如果沒有或不是平台，可同客戶訂單號 |
| 4 | platformCode | String(30) | Y | 平台編碼，電商平台簡稱必須為英文，非電商平台直接用公司英文名 |
| 5 | erpCode | String(50) | Y | ERP平台code：<br>(1)如果是第三方ERP，請聯繫技術提供<br>(2)如果是自建系統，可傳固定值：0000 |
| 6 | erpName | String(200) | N | ERP平台的名稱:<br>（1）如果是第三方ERP，可使用英文名或者拼音，如mabang,dianxiaomi等<br>（2）如果是自建系統，可傳固定值：0000 |
| 7 | platformMerchantId | String(100) | N | 用戶在電商平台的ID |
| 8 | sfWaybillNo | String(32) | N | 順豐運單號 |
| 9 | interProductCode | String(32) | Y | 國際產品映射碼，即順豐物流服務產品代碼<br>參考附錄國際產品映射碼聲明（見 `dictionaries.md`） |
| 10 | parcelQuantity | Int(8) | Y | 包裹總件數(固定為1) |
| 11 | parcelTotalWeight | BigDecimal(16,3) | Y | 包裹總重量。精確到小數點後3位，默認為KG |
| 12 | parcelTotalLength | BigDecimal(16,2) | N | 客戶訂單貨物總長。精確到小數點後2位，默認單位CM |
| 13 | parcelTotalWidth | BigDecimal(16,2) | N | 客戶訂單貨物總寬。精確到小數點後2位，默認單位CM |
| 14 | parcelTotalHeight | BigDecimal(16,2) | N | 客戶訂單貨物總高。精確到小數點後2位，默認單位CM |
| 15 | declaredValue | BigDecimal (16,2) | Y | 總商品申報價值，單件商品申報價值\*數量獲取 |
| 16 | declaredCurrency | String(10) | Y | 申報價值幣種 |
| 17 | orderInsurance | BigDecimal | N | 保價服務費（單票不能超過5000人民幣） |
| 18 | taxNo | String(12) | N | 稅號 |
| 19 | abn | String(11) | N | 1.在澳洲有註冊公司的企業，基於其ABN號走標準流程註冊的號碼，11位純數字。 2.歐盟國家流向，請將IOSS填充於此字段。格式為：2位字母開頭+數字。3.馬來西亞進口LVG銷售稅號填寫 4.EORI適用英國 5.VOEC適用挪威 6.UID適用瑞士 7.PRC適用於巴西 |
| 20 | gstExemptionCode | String(12) | N | 澳洲商業編號 |
| 21 | isBat | Integer | Y | 是否帶電 0：不帶電 ;1 帶電 |
| 22 | isLiquid | Integer | N | 是否液體 0非液體 1是液體 |
| 23 | createOrderType | String(2) | Y | 1：小包專遞 |
| 24 | orderExtendInfo | OrderExtendInfo | N | 訂單額外信息 |
| 25 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 26 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 27 | customsInfo | CustomsInfo | N | 報關信息 |
| 28 | parcelInfoList | List\<ParcelInfo> | Y | 包裹信息 |
| 29 | agentWaybillNo | String(40) | N | 代理運單號 |
| 30 | inputDirectionCode | String | N | 部分產品指定流向代碼,如 美向UNIUNI產品要求必填，具體流向代碼可諮詢對接銷售獲取 |
| 31 | agentCode | String | N | 代理名稱/代理code 枚舉值為：<br>USPS,Ground Advantage<br>USPS,Ground Advantage Available via Logistics Provider<br>UniUni,Standard<br>GOFO EXPRESS,Standard <br>GOFO,Standard |
| 32 | portInfo | String | N | 口岸信息：倉庫地址，Y2模式必填。枚舉值為：<br>JFK: 148-08 Guy R Brewer Blvd Queens, NY 11434<br>LAX: 1351 Charles Willard Street Carson CA 90746<br>ORD: 475 Supreme Dr, Bensenville, IL.60106 |
| 33 | pickupType | String | N | 寄件方式：<br> **0**-服務點自寄或自行聯繫快遞員<br> **1**-上門收件<br>( 寄件地址相同的批量訂單如無特殊要求一般請選擇自行聯繫快遞員，若選擇上門收件則順豐小哥會收到上門請求後在約定時間上門取件) |
| 34 | pickupAppointTime | String | N | 上門取件預約時間。格式：yyyy-MM-dd HH:mm:ss 若寄件方式選擇了上門收件，則預約方式必填 |
| 35 | ecommerceInfo | OrderEcommerceInfo | N | 電子商務信息 |
| 36 | localizedInfo | Map\<String,LocalizedDetail> | N | 本地化信息配置，key為語言，value為對應的語言本地化信息，參考附錄：本地化參數（見 `dictionaries.md`） |
| 37 | addServiceInfoList | List\<AddServiceInfo> | N | 增值服務信息 |
| 38 | purchaseTotal | BigDecimal (16,2) | N | 成交價(總價) |
| 39 | purchaseCurrency | String | N | 成交價幣別 |
| 40 | receiverVerification | String | N | 進口商信息是否與收件人一致 1:是 0:否 |
| 41 | pieceOrderImporterInfo | PieceOrderImporterInfo | N | 進口商信息 |
| 42 | ebcCode | String | N | 電商企業代碼 |
| 43 | ebcName | String | N | 電商企業名稱 |

#### 元素<請求>SenderInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | company | String(100) | Y | 寄件方公司 |
| 2 | contact | String(100) | Y | 寄件方姓名 |
| 3 | country | String(5) | Y | 寄件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | Y | 郵編 |
| 5 | regionFirst | String(128) | Y | 寄件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | Y | 寄件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | N | 寄件方地址三級區劃（區/縣） |
| 8 | address | String(200) | Y | 寄件方詳細地址 |
| 9 | telNo | String(20) | Y | 寄件方固定電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 10 | phoneNo | String(20) | Y | 寄件方移動電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 11 | email | String(128) | N | 寄件方郵箱 |

#### 元素<請求>ReceiverInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | company | String(100) | Y | 收件方公司 |
| 2 | contact | String(100) | Y | 收件方姓名 |
| 3 | country | String(5) | Y | 收件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | Y | 郵編 |
| 5 | regionFirst | String(128) | Y | 收件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | Y | 收件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | N | 收件方地址三級區劃（區/縣） |
| 8 | address | String(200) | Y | 收件方詳細地址 |
| 9 | email | String(128) | Y | 收件方郵箱 |
| 10 | telNo | String(20) | Y | 收件方固定電話。收件方手機號和收件方固定電話至少填寫一個 |
| 11 | phoneAreaCode | String(10) | N | 收件方移動電話區號 |
| 12 | phoneNo | String(20) | Y | 收件方移動電話。收件方手機號和收件方固定電話至少填寫一個 |
| 13 | destDoorplate | String | N | 到方門牌號，德國必填 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | passportId | String(100) | N | 韓國個人清關代碼，如P561140689980，只接受英文和數字 專線小包韓國流向為必填 |
| 2 | category | String(50) | N | 訂單所屬品類，用於海關清關 |
| 3 | customsExtInfoMap | CustomsExtInfo | N | 報關擴展信息（09/18生效） |

#### 元素<請求>CustomsExtInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | psuCompanyName | String(100) | N | 生產銷售單位名稱 |
| 2 | psuCompanyCertificateType | String(10) | N | 生產銷售單位證件類型，可填固定值：007 |
| 3 | psuCompanyCertificateNo | String(300) | N | 生產銷售單位證件號碼 （需填企業統一社會信用代碼） |

#### 元素<請求>ParcelInfo 海關申報信息

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | name | String(30) | Y | 商品名稱（默認使用，可以是各個國家的語言名稱） （備註：有效長度30字符；超出30字符，請聯繫對接服務支持人員確認是否可行） |
| 2 | cName | String(30) | Y | 商品中文名稱 （備註：有效長度30字符；超出30字符，請聯繫對接服務支持人員確認是否可行） |
| 3 | unit | String(32) | Y | 貨物單位，如：個，台 |
| 4 | amount | DECIMAL(24,10) | Y | 單件商品申報價值 |
| 5 | currency | String(5) | Y | 單價幣種 |
| 6 | quantity | DECIMAL(16,6) | Y | 數量 |
| 7 | originCountry | String(5) | N | 原產國家 |
| 8 | hsCode | String(100) | N | 海關編碼 （備註：[歐盟官網海關編碼查詢網址](https://trade.ec.europa.eu/access-to-markets/en/my-trade-assistant)） |
| 9 | goodsUrl | String(2000) | N | 商品鏈接 |
| 10 | weight | DECIMAL | Y | 托寄物重量，單件商品SKU重量（不能小於等於0, 單位KG）。支持小數點後3位 |
| 11 | goodsDesc | String(200) | N | 商品描述/揀貨信息 |
| 12 | skuID | String | N | 商品編碼SKU |
| 13 | skuType | String | N | 商品類型：<br>0:未知。<br>1:普貨。<br>2:敏貨。<br>3:帶電貨。 |
| 14 | productNameElement | String(1000) | N | 品名申報要素，支持多個要素，要素名使用“[]”標記，多要素使用“,”分隔。 <br>brand：品牌<br>specifications：規格 <br>mode：型號 <br>material：材質 <br>fabricationApproach：製造方式 <br>import\_goods\_value: 進口貨品價值<br>import\_goods\_tax: 進口貨品稅金 <br>Order completion time：訂單完成時間 <br>otpCode： 一次性驗證碼<br>Order NO：客戶訂單號 <br>category\_level\_1：平台商品一級分類 <br>category\_level\_2：平台商品二級分類 <br>Sensitive attributes：敏感屬性<br> Sensitive categories：敏感品類<br>美國CPSC必傳字段： <br>Processing Code：必填，處理代碼(枚舉值：REF，FCP，FGC) <br>1、若為“簡易申報”(Processing Code="REF")以下字段必填： <br>     Product ID：產品註冊號<br>     Version ID：版本號<br>     Certifier ID：進口商識別號 <br>2、若為“兒童產品CPC全量申報”或“其他產品GCC全量申報”(Processing Code="FCP" 或 Processing Code="FGC")以下字段必填：<br>     GTIN UPC：全球唯一產品號碼 <br>     Code Number：產品編號 <br>     Lot Number：生產批次號<br>     Production Start Date：生產開始日期 <br>     Production End Date：生產結束日期 <br>     brand：品牌 <br>     specifications：規格 <br>     Manufacture Month Year：製造月份年份<br>     Identity Number：產品標識型號 <br>     Lab Test Date：實驗室檢測日期 <br>     Certifying Entity Name：認證機構名稱 <br>     Certifying Entity Country：認證機構國家 <br>     Certifying EntityTelephone：認證機構電話 <br>     Certifying Entity Email：認證機構郵箱 <br>     Certify Lab Name：檢測實驗室名稱<br>     Lab CPSC Number：CPSC認證的實驗室編號 <br>     Citation：適用的CPSC法規代碼<br>     Test Report Number：實驗室報告編號 <br> Test Report URL：實驗室報告鏈接<br> Test Report Access Key：實驗室報告密碼 <br> Certifying Entity Address：認證機構地址 <br> Certifying Entity City：認證機構城市 <br> Certifying Entity State：認證機構州省 <br> Certifying Entity Postal Code：認證機構郵編<br> Lab Name：檢測機構名稱 <br> Lab Address：檢測機構地址 <br> Lab City：檢測機構城市 <br> Lab State：檢測機構州省 <br> Lab Postal Code：檢測機構郵編<br> Lab Country：檢測機構國家 <br> LabTelephone：檢測機構電話 <br> Lab Email：檢測機構郵箱 <br>填寫規範："[{"brand":"test "},{"specifications":"test2"},{"mode":"L3"},{"material":"test"},{"fabricationApproach":"梭織"},{"import\_goods\_value":123},{"import\_goods\_tax":456}]" |
| 15 | qtyOne | Decimal(19,5) | N | 法定第一數量 |
| 16 | unitOne | String(30) | N | 法定第一計量單位 |
| 17 | qtyTwo | Decimal(19,5) | N | 法定第二數量 |
| 18 | unitTwo | String(30) | N | 法定第二計量單位 |
| 19 | purchasePrice | Decimal(19,5) | N | 成交價(單價) |
| 20 | productRecordNo | String(20) | N | 商家產品識別碼,亞馬遜ASIN值 |
| 21 | itemNo | String(64) | N | 商品序號 |
| 22 | manufacturerSKU | String(100) | N | 製造商產品識別碼 |
| 23 | gtin | String(100) | N | 國際標準商品編碼 |

#### 元素<請求>OrderEcommerceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | platformName | String(100) | N | 電商平台名稱，填寫如shopify,temu等 |
| 2 | buyerNickname | String(64) | N | 買家暱稱 |
| 3 | importPlatformCode | String(64) | N | 進口電商平台代碼 |
| 4 | importPlatformName | String(64) | N | 進口電商平台名稱 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | passportId | String(100) | N | 韓國個人清關代碼，如P561140689980，只接受英文和數字 專線小包韓國流向為必填 |
| 2 | category | String(50) | N | 訂單所屬品類，用於海關清關 |
| 3 | customsExtInfoMap | CustomsExtInfo | N | 報關擴展信息（09/18生效） |

#### 元素<請求>AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | serviceCode | String(32) | N | 增值服務編碼（參考附錄增值服務聲明（見 `dictionaries.md`）） |
| 2 | value | String(256) | N | 增值服務擴展屬性 |

#### 元素<請求>PieceOrderImporterInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | importerCompanyName | String | N | 進口商名稱 |
| 2 | importCompContacts | String | N | 進口商聯繫人 |
| 3 | importerPhoneNumber | String | N | 進口商電話 |
| 4 | importerAddress | String | N | 進口商地址 |
| 5 | importerPostCode | String | N | 進口商郵編 |
| 6 | importerEntityNumber | String | N | 進口商法人番號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼<br>常見錯誤代碼請參考<br>出口電商常見異常響應（見 `error-codes.md`） |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | OrderRespBody | 否 |  |

#### 元素OrderRespBody

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerOrderNo | String | 訂單號 |
| 2 | sfWaybillNo | String | 運單號 |
| 3 | agentWaybillNo | String | 代理/服務商運單號 |
| 4 | directionCode | String | 流向代碼 |
| 5 | agentCode | String | 代理編號 |
| 5 | agentLabelBase64 | String | 代理面單 |

#### 明文請求示例

```
{
    "apiUsername": "popsandBox",
    "customerOrderNo": "1660726923472",
    "customsInfo": {},
    "declaredCurrency": "USD",
    "interProductCode": "INT0255",
    "isBat": false,
    "parcelInfoList": [
        {
            "amount": 1.1,
            "cName": "孕妇上衣",
            "currency": "USD",
            "goodsUrl": "baidu.com",
            "hsCode": "620432",
            "name": "Maternity Shirt",
            "quantity": 1,
            "unit": "piece",
            "weight": 0.31
        }
    ],
    "parcelTotalWeight": 0.31,
    "receiverInfo": {
        "address": "928 MADISON ST",
        "company": "Kacey Russell",
        "contact": "Kacey Russell",
        "country": "US",
        "email": "sss297wcnwxhrln@marketplace.amazon.com",
        "phoneNo": "3463079643",
        "postCode": "53188-3543",
        "regionFirst": "WI",
        "regionSecond": "WAUKESHA",
        "regionThird": "",
        "telNo": "3463079643"
    },
    "senderInfo": {
        "address": "Zhengxin Industrial 3rd floor,Huating Road 7#,DaLang Street",
        "company": "1",
        "contact": "tianmeng",
        "country": "CN",
        "phoneNo": "17608455622",
        "postCode": "518009",
        "regionFirst": "GuangDong",
        "regionSecond": "ShenZhen",
        "regionThird": "baoan",
        "telNo": "17608455622"
    }
}
```

#### 明文響應示例

```
{
    "data": {
        "agentCode": "UPSMG",
        "customerOrderNo": "1660726923472",
        "directionCode": "22K-ORD",
        "sfWaybillNo": "SF6043012317979"
    },
    "success": true
}
```

---

## 2. 獲取發貨面單 — `IECS_PRINT_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=98（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 獲取發貨面單；官方更新 2025-10-20；爬取 2026-09-23）

### 功能描述

```
客戶可以通過運單號獲取發貨面單的打印文件
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_PRINT_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | version | String(10) | N | 版本號（默認為空即可） |
| 2 | printWaybillNoDtoList | List\<PrintWaybillNoDto> | Y | 打印參數對象 |
| 3 | apiUsername | String(50) | Y | 客戶名稱（customerCode對應的用戶名） |
| 4 | printPicking | boolean | N | 是否打印揀貨單，默認：false |
| 5 | onePdf | boolean | N | 是否合成一個PDF，默認：true |
| 6 | fileFormat | String(8) | N | 打印文件格式：PDF或者ZPL；默認為PDF |

#### 元素<請求>PrintWaybillNoDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | 是 | 順豐運單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | PrintOrderData | 否 |  |

#### 元素PrintOrderData

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | url | String | 打印的URL |
| 2 | sfWaybillNo | String | 順豐單號 |
| 3 | customerOrderNo | String | 用戶訂單號 |
| 4 | printWaybillNoDtoList | List\<PrintDto> | 打印對象list（批量打印且請求參數onePdf為false時存在） |

#### 元素PrintDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | url | String | 打印的URL |
| 2 | sfWaybillNo | String | 順豐單號 |
| 3 | customerOrderNo | String | 用戶訂單號 |

#### 明文請求示例

```
{
    "printWaybillNoDtoList": [
        {
            "sfWaybillNo": "SF6043037082700"
        }
    ],
    "apiUsername": "popsandBox",
    "printPicking":true
}
```

#### 明文響應示例

```
{
  "code": "200",
  "data": {
    "customerOrderNo": "1758632026538",
    "sfWaybillNo": "SF6043037082700",
    "url": "http://ibu-lomp-core-er-shenzhen-xili1-oss.sit.sf-express.com:8080/v1.2/AUTH_IBU-LOMP-CORE-ER/sfosspublic001/kts-label%2Fefcabcc01dc8a2f4f97d07db87a92303.pdf"
  },
  "success": true
}
```

---

## 3. 取消電商訂單 — `IECS_CANCEL_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=191（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 取消電商訂單；官方更新 2024-10-21；爬取 2026-09-23）

### 功能描述

```
取消電商訂單
注意：訂單攬收前方可取消訂單，取消訂單之後實物禁止發運
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_CANCEL_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | 是 | 順豐運單號 |
| 1 | customerOrderNo | String(32) | 是 | 客戶訂單號 |
| 1 | cancelReason | String(32) | 是 | 取消原因 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |

#### 明文請求示例

```
{
    "cancelReason": "取消",
    "customerOrderNo": "BG-0001700731258926102",
    "sfWaybillNo": "SF6043024942140"
}
```

#### 明文響應示例

```
{
    "code": "200",
    "msg": "success",
    "success": true
}
```

---

## 4. 訂單攔截 — `IECS_ORDER_INTERCEPT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=179（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 訂單攔截；官方更新 2024-10-21；爬取 2026-09-23）

### 功能描述

```
攔截在途運單接口
注意：攔截或者取消攔截在途運單接口
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_ORDER_INTERCEPT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | interceptType | String | 是 | 攔截類型：1-攔截、2-取消攔截 |
| 2 | remark | String | 否 | 攔截原因/取消攔截原因 |
| 3 | customerOrderNo | String | 是 | 客戶訂單號 |
| 4 | sfWaybillNo | String | 是 | 順豐單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |

#### 明文請求示例

```
{
    "sfWaybillNo":"SF6043021249744",
    "customerOrderNo":"aa1685953693156",
    "interceptType":"1",
    "remark":"测试"
}
```

#### 明文響應示例

```
{
    "code": "200",
    "msg": "success",
    "success": true
}
```

---

## 5. 服務商單號查詢 — `IECS_QUERY_AGENTNO`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=112（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 服務商單號查詢；官方更新 2026-04-16；爬取 2026-09-23）

### 功能描述

```
客戶系統根據訂單號和順豐單號獲取對應的服務商單號，支持批量查詢，每次100。同一個單號限制500ms每次。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_QUERY_AGENTNO`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | service | String | 是 | 請使用默認值：WaybillService |
| 2 | waybillList | ReqDto | 是 | 運單信息集合 |
| 3 | agentLabelCtrl | String | 否 | 是否需要返回代理面單<br>“1”為需要代理面單<br>“0”及其他都為不需要 |

#### 元素<請求>ReqDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 是 | 順豐運單號 |
| 2 | orderNo | String | 是 | 客戶訂單號（下單時使用的customerOrderNo） |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | service | String | 否 | 默認值：WaybillService |
| 5 | successData | List\<SuccessData> | 否 | 查詢成功列表 |
| 6 | wrongData | List\<WrongData> | 否 | 查詢失敗列表 |

#### 元素SuccessData

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 順豐運單號 |
| 2 | orderNo | String | 客戶訂單號（下單時使用的customerOrderNo） |
| 3 | agentWaybillNo | String | 代理單號，部分平郵無代理單號 |
| 4 | agentcode | String | 代理編號 |
| 5 | agentName | String | 代理真實名稱 |
| 6 | agentLabelUrl | String | 代理面單URL |

#### 元素WrongData

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 順豐運單號 |
| 2 | orderNo | String | 客戶訂單號（下單時使用的customerOrderNo） |
| 3 | description | String | 錯誤信息描述 |
| 4 | statusagentcode | String | 錯誤信息編碼<br>4001:順豐單號或者訂單號錯誤，未找到對應的代理單號<br>4002:代理單號未生成，請稍後再試 |

#### 明文請求示例

```
{
    "service": "WaybillService",
    "agentLabelCtrl": "1",
    "waybillList": [
        {
            "orderNo": "24731707110000032",
            "sfWaybillNo": "SF6043011576628"
        },
        {
            "orderNo": "24731707110000125",
            "sfWaybillNo": "SF6043011577738"
        }
    ]

}
```

#### 明文響應示例

```
{
    "code": "1",
    "service": "WaybillService",
    "success": true,
    "successData": [
        {
            "agentWaybillNo": "A23560430118",
            "agentcode": "UPSGG",
            "orderNo": "24731707110000032",
            "sfWaybillNo": "SF6043011576628",
            "agentLabelUrl":"http://test/SF6043011576628_1949.pdf"
        }
    ],
    "wrongData": [
        {
            "description": "顺丰单号或者订单号错误，未找到对应的代理单号",
            "orderNo": "24731707110000125",
            "sfWaybillNo": "SF6043011577738",
            "status": "4001"
        },
        {
            "description": "顺丰单号或者订单号错误，未找到对应的代理单号",
            "orderNo": "24717557965422NOV20170621079",
            "sfWaybillNo": "555000352897",
            "status": "4001"
        }
    ]
}
```

---

## 6. 重量及運費查詢 — `IECS_QUERY_WEIGHT_FREIGHT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=110（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 重量及運費查詢；官方更新 2026-01-08；爬取 2026-09-23）

### 功能描述

```
重量及運費查詢接口。
運費查詢僅支持預付帳號查詢，月結帳號返回運費為空，調用頻率限制 500ms/次，最大查詢量不超過2000
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_QUERY_WEIGHT_FREIGHT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | weightAndFreightList | String | 是 | 運單信息集合 |

#### 元素<請求>ReqDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 是 | 順豐運單號 |
| 2 | agentWaybillNo | String | 否| 代理運單號 |

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<RespDto> | 否 | 運單信息集合 |

#### 元素RespDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | agentWaybillNo | String | 代理運單單號 |
| 2 | message | String | 提示信息 |
| 3 | sfWaybillNo | String | 順豐運單號 |
| 4 | freight | BigDecimal | 運費（單位：RMB） |
| 5 | tariff | BigDecimal | 關稅費 （單位：RMB） |
| 6 | clearance | BigDecimal | 清關費 （單位：RMB） |
| 7 | clearanceService | BigDecimal | 清關服務費 （單位：RMB） |
| 8 | weight | BigDecimal | 計費重量 （單位：KG） |
| 9 | realWeight | BigDecimal | 真實重量 （單位：KG） |
| 10 | weightTime | Date | 稱重時間 |
| 11 | debitTime | Date | 計費時間 |
| 12 | agentCode | String | 渠道代碼 |
| 13 | cargoHeight | BigDecimal | 貨物高度cm |
| 14 | cargoLength | BigDecimal | 貨物長度cm |
| 15 | cargoWidth | BigDecimal | 貨物寬度cm |
| 16 | destCountryCode | String | 目的地國家 |
| 17 | destPostCode | String | 目的地郵編 |
| 18 | monthCardNo | String | 月結卡號 |
| 19 | orderTime | String | 下單時間,格式:yyyy-MM-dd HH:mm:ss |
| 20 | portCode | String | 目的地口岸 |
| 21 | productName | String | 產品名稱 |
| 22 | productType | String | 產品類型 |

#### 明文請求示例

```
{
    "weightAndFreightList": [
        {
            "agentWaybillNo": 111,
            "sfWaybillNo": "SF6043011576628"
        }
    ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": [
        {
            "agentWaybillNo": "111",
            "clearance": 0.0000,
            "clearanceService": 0.0000,
            "debitTime": 1639442671000,
            "freight": 52.2000,
            "message": "success",
            "realWeight": 1.000,
            "sfWaybillNo": "SF6043011576628",
            "tariff": 0.0000,
            "weight": 1.000,
            "weightTime": 1639442659000
        }
    ],
    "msg": "success",
    "orderUser": "popsandBox",
    "success": true
}
```

---

## 7. 小包上傳重量 — `IECS_UPLOAD_WEIGHT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=111（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 小包上傳重量；官方更新 2025-01-14；爬取 2026-09-23）

### 功能描述

```
上傳客戶小包重量信息。每次查詢限制500票。QPS:20。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_UPLOAD_WEIGHT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | waybillList | List\<ReqDto> | 是 | 運單信息集合 |

#### 元素<請求>ReqDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 是 | 順豐運單號 |
| 2 | weight | String | 是 | 包裹重量(kg)（限制0\~50） |
| 3 | length | BigDecimal | 否 | 長度，單位cm |
| 4 | width | BigDecimal | 否 | 寬度，單位cm |
| 5 | height | BigDecimal | 否 | 高度，單位cm |
| 6 | number | string | 否 | 序號 |
| 7 | imgUrlList | List\<string> | 否 | 圖片url集合 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |

#### 明文請求示例

```
{
    "waybillList": [
        {
            "sfWaybillNo": "SF6043012321536",
            "weight": "11345"
        }
    ]
}
```

#### 明文響應示例

```
{
    "code": "200",
    "msg": "success",
    "success": true
}
```

---

## 8. 導入包票關係 — `IECS_IMPORT_PACKAGE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=109（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 導入包票關係；官方更新 2024-10-21；爬取 2026-09-23）

當前僅支持單次最多導入500個運單，且系統已稱重的包無法導入

### 功能描述

```
適用於客戶把貨整包交接給順豐時把票與包進行關聯的場景，具體規則如下：
1、該功能適用於部分定製客戶，需要客戶提前聯繫相應的客戶經理與順豐技術溝通，開通權限
2、對於1個包的數據系統暫只支持傳1次，不要把1個包的數據分多次傳輸
3、系統如返回失敗的信息，會整包數據失敗，請按提示優化數據後再整包重傳
4、暫不支持包中刪除等功能
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_IMPORT_PACKAGE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | apiUsername | String | 是 | 客戶名稱（customerCode對應的用戶名） |
| 2 | packageCode | String | 否 | 順豐包號（由業務人員提供），順豐包號和海外代理包號，客戶包號必須填寫其中一個，且不能同時填寫；<br>D段業務：非必填 |
| 3 | packageCode2 | String | 否 | 海外代理包號（由業務人員提供），順豐包號和海外代理包號，客戶包號必須填寫其中一個，且不能同時填寫；<br>D段業務：非必填 |
|
| 4 | packageCode3 | String | 否 | 客戶包號（使用該字段請聯繫業務人員），順豐包號和海外代理包號，客戶包號必須填寫其中一個，且不能同時填寫； |
| 5 | waybillList | List\<ReqDto> | 是 | 運單集合 |
| 6 | returnPackageCode | Boolean | 否 | 適用客戶手上沒有順豐包牌的場景， 默認值false 不需要返回包號，true：需要返回包號<br> D段業務：必填 且為true |
|

#### 元素<請求>ReqDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 否 | 順豐單號 順豐單號或海外代理單號必須且只能填寫其中一個 |
| 2 | agentWaybillNo | String | 否 | 海外代理單號 順豐單號或海外代理單號必須且只能填寫其中一個 |
| 3 | weight | String | 否 | 重量（kg），僅支持小數後3位，超過3位小數，系統自動四捨五入 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<RespDto> | 否 |  |

#### 元素RespDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | apiUsername | String | 客戶名稱（customerCode對應的用戶名） |
| 2 | packageCode | String | 順豐包號 |
| 3 | packageCode2 | String | 海外代理包號 |
| 4 | packageCode3 | String | 客戶包號 |
| 5 | waybillList | List\<RespDtoInfo> | 運單集合 |

#### 元素RespDtoInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 順豐單號 |
| 2 | agentWaybillNo | String | 海外代理單號 |
| 3 | weight | String | 重量（kg），僅支持小數後3位，超過3位小數，系統自動四捨五入 |
| 4 | failReason | String | 失敗原因（空時表示成功） |

#### 明文請求示例

```
{
    "apiUserName": "test031701",
    "packageCode": "GPKG000990006051",
    "packageCode2": "",
    "packageCode3": "",
    "waybillList": [
        {
            "agentWaybillNo": "GL001000865857",
            "weight": 0.1
        },
        {
            "agentWaybillNo": "GL001000865857",
            "weight": 0.2
        },
        {
            "agentWaybillNo": "GL001000865857",
            "weight": 0.3
        }
    ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "packageCode": "GPKG000990006051",
        "packageCode2": "",
        "packageCode3": "",
        "waybillList": [
            {
                "agentWaybillNo": "GL001000865857",
                "weight": "0.1"
            },
            {
                "agentWaybillNo": "GL001000865857",
                "weight": "0.2"
            },
            {
                "agentWaybillNo": "GL001000865857",
                "weight": "0.3"
            },
            {
                "agentWaybillNo": "GL001000865857",
                "weight": "0.1"
            }
        ]
    },
    "success": true
}
```

---

## 9. 電商發貨/簽收證明 — `IECS_QUERY_POD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=206（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 電商發貨/簽收證明；官方更新 2024-05-30；爬取 2026-09-23）

### 功能描述

```
使用場景：僅IECS電商客戶可用
使用說明：該接口用於獲取電商發貨/簽收證明
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_QUERY_POD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | Y | 順豐單號 |
| 2 | signType | String | Y | 0-發貨證明,1-簽收證明 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼：0-成功、其他-失敗 |
| 3 | msg | String | N | 響應描述 |
| 4 | smartSignatureMessage | String |  | 文件狀態描述 |
| 5 | smartSignatureStatus | String |  | 文件狀態：1生成成功，2文件生成中，3順豐內部審核失敗，請聯繫順豐客服 （對應ECP申請失敗）4其他錯誤，請聯繫順豐客戶 |
| 6 | smartSignatureType | String |  | 文件類型：0-發貨證明,1-簽收證明 |
| 7 | smartSignatureUrl | String |  | 文件鏈接：已蓋章的文件鏈接 |
| 8 | userName | String |  | 用戶名 |

#### 明文請求示例

```
{
    "sfWaybillNo": "SF6043024900687",
    "signType": "1"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "msg": "success",
    "smartSignatureMessage": "生成成功",
    "smartSignatureStatus": "1",
    "smartSignatureType": "0",
    "smartSignatureUrl": "http://orapi.trackmeeasy.com/lomp-ext1",
    "userName": "hbin901",
    "success": true
}
```

---

## 10. 尾程代理傳輸 — `IECS_AGENT_TRANS`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=222（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 尾程代理傳輸；官方更新 2025-06-11；爬取 2026-09-23）

### 功能描述

```
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_AGENT_TRANS`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | Y | 順豐運單號 |
| 1 | agentWaybillNo | String | Y | 代理單號 |
| 1 | agentCode | String | Y | 代理名稱/代理code |
| 1 | agentLabelBase64 | String | Y | 面單PDF文件的base64字符串 |
| 1 | portInfo | String | Y | 口岸信息：倉庫地址 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Object | N |  |

#### 明文請求示例

```
```

#### 明文響應示例

```
```

---

## 11. 大包攬收-大小包關係綁定 — `IECS_BIG_PACKAGE_BIND`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=228（文檔中心 > API文檔 > 國際件API > 出口電商業務場景接入 > 大包攬收-大小包關係綁定；官方更新 2025-10-11；爬取 2026-09-23）

### 功能描述

```
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IECS_BIG_PACKAGE_BIND`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | list | List\<Dto> | Y | 綁定關係列表 |

#### 元素<請求>Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | bigbagNo | String | Y | 大包號 |
| 2 | bigbagWeight | Integer | N | 大包重量 |
| 3 | trackingNoList | List\<String> | Y | 運單號列表 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Object | N |  |

#### 明文請求示例

```
{
   "list": [
        {
           "bigbagNo":"BIGBAG0000000687",
           "bigbagWeight":12,
           "trackingNoList": [
               "504303706884918",
               "504303706884919",
               "504303706884922"
            ]
        },
        {
           "bigbagNo":"BIGBAG0000000688",
           "bigbagWeight":13,
           "trackingNoList": [
               "504303706884943",
               "504303706884944",
               "504303706884945"
            ]
        }
    ]
}
```

#### 明文響應示例

```
{
    "msg": "大包号【BIGBAG0000000687,BIGBAG0000000688】已绑定",
    "code": 500,
    "success": false
}
```
