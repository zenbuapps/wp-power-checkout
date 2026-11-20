# IUOP 訂單操作：查詢 / 取消 / 面單 / 運費預估 / 清關資料 / BBD / 快遞櫃

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：建單後的所有 IUOP 操作——查單（`IUOP_QUERY_ORDER`）、取消（`IUOP_CANCEL_ORDER`）、取面單 PDF/ZPL（`IUOP_PRINT_ORDER`，同步；`IUOP_ASYN_PRINT_ORDER` + `IUOP_ASYN_PRINT_RESULT`，非同步）、運費試算（`IUOP_QUERY_FREIGHT` 只算基本運費；`IUOP_ESTIMATE_FEE` 含附加費但需額外開通）、清關證件上傳/查詢（`IUOP_UPLOAD_CERTIFY` / `IUOP_VALIDITY_CERTIFY` / `IUOP_REQUIRE_CERTIFY`）、BBD 集運合單、新加坡快遞櫃查詢。

重點：
- 建單回應已含 `labelUrl` / `invoiceUrl`，**通常不需要另外呼叫面單接口**（官方原文）。
- 非同步列印結果建議延遲約 2 分鐘再查、每分鐘不超過 1 次。
- `IOMS_QUERY_LOCKER`：官方頁標明**只支援新加坡**，台灣不適用。
- BBD（集運散派）官方 FAQ：一般客戶不需理會。

## 目錄

- [1. 查詢物流訂單 — `IUOP_QUERY_ORDER`](#1-查詢物流訂單--iuop_query_order)
- [2. 取消物流訂單 — `IUOP_CANCEL_ORDER`](#2-取消物流訂單--iuop_cancel_order)
- [3. 獲取發貨面單 — `IUOP_PRINT_ORDER`](#3-獲取發貨面單--iuop_print_order)
- [4. 異步打印面單 — `IUOP_ASYN_PRINT_ORDER`](#4-異步打印面單--iuop_asyn_print_order)
- [5. 異步打印面單結果查詢 — `IUOP_ASYN_PRINT_RESULT`](#5-異步打印面單結果查詢--iuop_asyn_print_result)
- [6. 預估運費 — `IUOP_QUERY_FREIGHT`](#6-預估運費--iuop_query_freight)
- [7. 預估總費用 — `IUOP_ESTIMATE_FEE`](#7-預估總費用--iuop_estimate_fee)
- [8. 上傳清關資料 — `IUOP_UPLOAD_CERTIFY`](#8-上傳清關資料--iuop_upload_certify)
- [9. 清關資料是否有效查詢 — `IUOP_VALIDITY_CERTIFY`](#9-清關資料是否有效查詢--iuop_validity_certify)
- [10. 上傳清關資料要求查詢 — `IUOP_REQUIRE_CERTIFY`](#10-上傳清關資料要求查詢--iuop_require_certify)
- [11. BBD預合單 — `IUOP_BBD_PRE_MERGE`](#11-bbd預合單--iuop_bbd_pre_merge)
- [12. BBD合單結果查詢 — `IUOP_BBD_MERGE_RESULT`](#12-bbd合單結果查詢--iuop_bbd_merge_result)
- [13. 取消BBD預合單 — `IUOP_CANCEL_BBD_MERGE`](#13-取消bbd預合單--iuop_cancel_bbd_merge)
- [14. 快遞櫃查詢 — `IOMS_QUERY_LOCKER`](#14-快遞櫃查詢--ioms_query_locker)

---

## 1. 查詢物流訂單 — `IUOP_QUERY_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=78（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 查詢物流訂單；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
根據客戶編碼及訂單詳情請求對象查詢訂單信息
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_QUERY_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | version | String | 否 | 版本號（默認為空即可） |
| 3 | sfWaybillNo | String | 否 | 順豐運單號(母單號)，客戶訂單號customerOrderNo、 順豐運單號sfWayBillNo至少填一個，如果兩個都填寫，校驗是否為同一訂單 |
| 4 | customerOrderNo | String | 否 | 客戶訂單號，客戶訂單號customerOrderNo、 順豐運單號sfWayBillNo至少填一個，如果兩個都填寫，校驗是否為同一訂單 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | OrderInfo | 否 | 業務接口響應數據 |

#### 元素OrderInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerOrderNo | String(64) | 客戶訂單號 |
| 2 | customerOrderNoTwo | String(64) | 客戶訂單號2 |
| 3 | sfWaybillNo | String(32) | 順豐運單號 |
| 4 | interProductCode | String(32) | 國際產品映射碼 |
| 5 | parcelQuantity | Int(8) | 包裹總件數(大於1表示是子母件，最大值300) |
| 6 | parcelTotalWeight | BigDecimal(16,2) | 包裹總重量。精確到小數點後2位 |
| 7 | parcelWeightUnit | String(16) | 包裹總重量單位 |
| 8 | parcelTotalLength | BigDecimal(16,2) | 客戶訂單貨物總長。精確到小數點後2位 |
| 9 | parcelTotalWidth | BigDecimal(16,2) | 客戶訂單貨物總寬。精確到小數點後2位 |
| 10 | parcelTotalHeight | BigDecimal(16,2) | 客戶訂單貨物總高。精確到小數點後2位 |
| 11 | declaredValue | String(20) | 申明價值 |
| 12 | declaredCurrency | String(10) | 包裹總計聲明價值幣種 |
| 13 | parcelVolumeUnit | String(16) | 客戶訂單貨物長度單位 1.可選項：CM、INCH 2.默認單位為CM 3.原寄國為CA和US，長度單位為英寸 |
| 14 | pickupType | String(2) | 寄件方式 0-服務點自寄或自行聯繫快遞員； 1-上門收件； |
| 15 | pickupAppointTime | String(20) | 上門取件預約時間。格式：yyyy-MM-dd HH:mm 若寄件方式選擇了上門收件，則預約方式必填 |
| 16 | pickupAppointTimeZone | String(100) | 上門預約時間時區 詳細參考附錄字典 |
| 17 | remark | String(200) | 運單備註 |
| 18 | orderExtendInfo | OrderExtendInfo | 訂單額外信息 |
| 19 | paymentInfo | PaymentInfo | 付款方式信息 |
| 20 | senderInfo | SenderInfo | 寄件人信息 |
| 21 | receiverInfo | ReceiverInfo | 收件人信息 |
| 22 | customsInfo | CustomsInfo | 報關信息 |
| 23 | parcelInfoList | ParcelInfo | 包裹信息 |
| 24 | addServiceInfoList | AddServiceInfo | 增值服務信息 |
| 25 | childInfoList | ChildInfo | 子單信息 |
| 26 | agentWaybillNo | String(40) | 代理運單號 |

#### 元素OrderExtendInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | isSignBack | String(8) | 是否要求簽回單; 0.否 1.是（簽回原寄地） 2.是（簽回第三方地址） |
| 2 | isSelfPick | String(8) | 是否自取件：0-否；1-是 |
| 3 | signBackWaybillNo | String(32) | 簽回回單號 |
| 4 | preMergeSfWaybillNo | String(32) | BBD預合單號 |

#### 元素PaymentInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | payMethod | String(8) | 付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 2 | payMonthCard | String(20) | 月結卡號 |
| 3 | taxPayMethod | String(8) | 稅金付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 4 | taxPayMonthCard | String(20) | 稅金結算帳號 |

#### 元素SenderInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | company | String(100) | 寄件方公司 |
| 2 | contact | String(100) | 寄件方姓名 |
| 3 | country | String(5) | 寄件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | 郵編 |
| 5 | regionFirst | String(128) | 寄件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | 寄件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | 寄件方地址三級區劃（區/縣） |
| 8 | address | String(200) | 寄件方詳細地址 |
| 9 | email | String(128) | 寄件方郵箱 |
| 10 | telAreaCode | String(10) | 寄件方固定電話區號 |
| 11 | telNo | String(20) | 寄件方固定電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 12 | phoneAreaCode | String(10) | 寄件方移動電話區號 |
| 13 | phoneNo | String(20) | 寄件方移動電話。寄件方手機號和寄件方固定電話至少填寫一個 |
| 14 | cargoType | INT(1) | 寄件方貨物類型：1-個人件；2-公司件 |
| 15 | certType | String(10) | 寄件方證件類型 詳細參考附錄字典 |
| 16 | certCardNo | String(100) | 寄件方證件號 |
| 17 | vat | String(18) | 寄件方VAT號 |
| 18 | eori | String(100) | 寄件方EORI號 |
| 19 | iossNo | String(100) | 寄件方IOSS號 |

#### 元素ReceiverInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | company | String(100) | 收件方公司 |
| 2 | contact | String(100) | 收件方姓名 |
| 3 | country | String(5) | 收件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 4 | postCode | String(20) | 郵編 |
| 5 | regionFirst | String(128) | 收件方地址一級區劃（州/省） |
| 6 | regionSecond | String(128) | 收件方地址二級區劃（城市） |
| 7 | regionThird | String(128) | 收件方地址三級區劃（區/縣） |
| 8 | address | String(200) | 收件方詳細地址 |
| 9 | regionFifth | String(200) | 收件方地址五級區劃（道路名，收件方國家為韓國，則必填，長度100） |
| 10 | regionSixth | String(100) | 收件方地址六級區劃（建築編號，收件方國家為韓國，則必填） |
| 11 | email | String(128) | 收件方郵箱 |
| 12 | telAreaCode | String(10) | 收件方固定電話區號 |
| 13 | telNo | String(20) | 收件方固定電話。收件方手機號和收件方固定電話至少填寫一個 |
| 14 | phoneAreaCode | String(10) | 收件方移動電話區號 |
| 15 | phoneNo | String(20) | 收件方移動電話。收件方手機號和收件方固定電話至少填寫一個 |
| 16 | cargoType | INT(1) | 收件方貨物類型：1-個人件；2-公司件 |
| 17 | certType | String(10) | 收件方證件類型 詳細參考附錄字典 |
| 18 | certCardNo | String(100) | 收件方證件號 |
| 19 | vat | String(18) | 收件方VAT號 |
| 20 | eori | String(100) | 收件方EORI號 |

#### 元素CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | tradeCondition | String(30) | 貿易條件，參考附錄字典 |
| 2 | senderReason | Int(2) | 寄件原因：1-商業；2-非商業 |
| 3 | senderReasonContent | String(256) | 寄件原因內容 |
| 4 | businessRemark | String(256) | 商業發票備註 |
| 5 | customsBatch | String(20) | 報關批次 |
| 6 | importCustomsType | String(30) | 進口報關方式 參考附錄字典 |
| 7 | exportCustomsType | String(30) | 出口報關方式 參考附錄字典 |
| 8 | harmonizedCode | String(100) | Harmonized Code美國寄出的可填寫 |
| 9 | aesNo | String(100) | AES No.美國寄出可填寫 |

#### 元素ParcelInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | name | String(128) | 商品名稱 |
| 2 | eName | String(128) | 商品英文名稱 |
| 3 | unit | String(32) | 貨物單位 |
| 4 | amount | DECIMAL(24,10) | 單價 |
| 5 | quantity | DECIMAL(16,6) | 數量 |
| 6 | originCountry | String(5) | 原產國家 |
| 7 | hsCode | String(100) | 海關編碼 |
| 8 | brand | String(16) | 品牌 |
| 9 | stateBarCode | String(64) | 國條碼 |
| 10 | productCustomsNo | String(100) | 海關備案號 |
| 11 | productRecordNo | String(18) | 貨物產品國檢備案編號 |
| 12 | goodsCode | String(60) | 商品編號 |
| 13 | goodsUrl | String(2000) | 商品鏈接 |
| 14 | weight | DECIMAL(16,3) | 托寄物重量 |
| 15 | goodsDesc | String(200) | 商品描述 |
| 16 | material | String(600) | 材質 |
| 17 | specifications | String(64) | 型號 |

#### 元素AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | serviceCode | String(32) | 增值服務編碼 |
| 2 | value | String(256) | 增值服務擴展屬性 |
| 3 | valueOne | String(256) | 增值服務擴展屬性1 |

#### 元素ChildInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String(32) | 子單編號 |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "sfWaybillNo": "SF1334456901862"
}
```

#### 明文響應示例

```
{
    "apiErrorMsg": "",
    "apiResultCode": 0,
    "apiResultData": {
        "code": "0",
        "data": {
            "addServiceInfoList": [
                {
                    "serviceCode": "IN15",
                    "value": "",
                    "valueOne": ""
                }
            ],
            "agentWaybillNo": "",
            "childInfoList": [],
            "customerOrderNo": "1658385192825149",
            "customerOrderNoTwo": "",
            "customsInfo": {
                "aesNo": "",
                "businessRemark": "",
                "customsBatch": "",
                "exportCustomsType": "",
                "harmonizedCode": "",
                "importCustomsType": "",
                "senderReasonContent": "",
                "tradeCondition": ""
            },
            "declaredCurrency": "USD",
            "declaredValue": 0.1,
            "interProductCode": "INT0007",
            "orderExtendInfo": {
                "isSelfPick": "",
                "isSignBack": "",
                "signBackWaybillNo": ""
            },
            "parcelInfoList": [
                {
                    "amount": 0.1,
                    "brand": "",
                    "eName": "",
                    "goodsCode": "",
                    "goodsDesc": "",
                    "goodsUrl": "",
                    "hsCode": "",
                    "material": "",
                    "name": "iPhone",
                    "originCountry": "SG",
                    "productCustomsNo": "",
                    "productRecordNo": "",
                    "quantity": 1,
                    "specifications": "",
                    "stateBarCode": "",
                    "unit": "个"
                }
            ],
            "parcelQuantity": 1,
            "parcelTotalWeight": 0.5,
            "parcelVolumeUnit": "CM",
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
                "address": "新加坡 详细地址",
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
                "regionFifth": "regionFifth11",
                "regionFirst": "Ah Soo Walk",
                "regionSecond": "106A",
                "regionSixth": "regionSixth11",
                "regionThird": "regionThird11",
                "telAreaCode": "86",
                "telNo": "9516168888",
                "vat": ""
            },
            "remark": "",
            "senderInfo": {
                "address": "306 S BRYN MAWR AVE",
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
                "regionFifth": "",
                "regionFirst": "Admiralty Road East",
                "regionSecond": "11A",
                "regionSixth": "",
                "regionThird": "regionThird11",
                "telAreaCode": "86",
                "telNo": "9516168888",
                "vat": ""
            },
            "sfWaybillNo": "SF1334456901862"
        },
        "msg": "执行成功",
        "success": true
    },
    "apiTimestamp": 1660803085729
}
```

---

## 2. 取消物流訂單 — `IUOP_CANCEL_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=80（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 取消物流訂單；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
取消已創建的訂單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_CANCEL_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | sfWaybillNo | String(32) | 否 | 順豐運單號(母單號),順豐運單號與客戶訂單號二者填其一 |
| 3 | customerOrderNo | String(64) | 否 | 客戶訂單號,順豐運單號與客戶訂單號二者填其一 |

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
"customerCode":"ICRME000SRN93",
"sfWaybillNo":"SF1324476054891"
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

## 3. 獲取發貨面單 — `IUOP_PRINT_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=79（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 獲取發貨面單；官方更新 2026-03-10；爬取 2026-09-23）

### 功能描述

```
客戶可以通過運單號獲取運單和發票的打印文件
注意：目前創建物流訂單接口返回信息中已包含面單的下載鏈接，通常無需專門對接此接口獲取面單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_PRINT_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | Y | 客戶編碼 |
| 2 | requestId | String(64) | Y | 請求id（可使用UUID，確保請求唯一性字段，只要確保每個請求的requestId唯一即可） |
| 3 | version | String(10) | 否 | 版本號（默認為空即可） |
| 4 | labelModeSize | Integer | 否 | 面單打印樣式；<br>0：面單類100\*210；<br>1：面單類100\*150；<br>2：面單類100\*45（BBD合單包裹條形碼）<br>5：面單類，100\*45(參考編號面單)<br>7：面單類100\*45(BBD合單包裹二維碼)<br>8：面單類100\*45(BBD預合單大包裹條形碼)<br>printType=1時默認值為1，printType=2時默認值為0 |
| 5 | printType | String(8) | Y | 打印類型。1-運單 2-發票 |
| 6 | printWaybillNoDtoList | List\<PrintWaybillNoDto> | 是 | 支持批量打印 母單相同的子單（含母單） 打印參數對象 |
| 7 | fileFormat | String(8) | Y | 打印文件格式：PDF或者ZPL；默認為PDF |
| 8 | resolution | String(5) | Y | ZPL文件分辨率，支持203dpi和300dpi，僅在fileFormat為ZPL時有效 |

#### 元素<請求>PrintWaybillNoDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | 是 | 順豐運單號 |
| 2 | isPrintSubParent | Integer | 否 | 是否打印全部的子母單<br> 0：否 <br> 1：是 <br>非子母單傳1即可 |
| 3 | singlePrintWaybillNoList | List\<String> | 否 | 單獨打印的運單號集合(含母單，子單)；**若isPrintSubParent 為0，則必填** |

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

#### 明文請求示例

```
{
  "customerCode": "ICRME000SRN93",
  "labelModeSize": 0,
  "printType": "1",
  "printWaybillNoDtoList": [
    {
      "isPrintSubParent": 1,
      "sfWaybillNo": "SF1030358177716"
    }
  ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
      "url": "http://iuop-uat.sit.sf-express.com:9000/iuop-iuop-uat/api/print/printLabelByToken?token=e5a7b5d4f76045a392b4d3702795a3b2"
    },
    "msg": "执行成功",
    "success": true
  }
```

---

## 4. 異步打印面單 — `IUOP_ASYN_PRINT_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=89（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 異步打印面單；官方更新 2026-04-10；爬取 2026-09-23）

### 功能描述

```
客戶可以通過運單號獲取運單和發票的打印文件。
異步打印會返回打印請求id，需要調用方根據打印請求id查詢打印結果url。
注意：目前創建物流訂單接口返回信息中已包含面單的下載鏈接，通常無需專門對接此接口獲取面單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_ASYN_PRINT_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | requestId | String(64) | 是 | 請求id（可使用UUID，確保請求唯一性字段，只要確保每個請求的requestId唯一即可） |
| 3 | version | String(10) | 否 | 版本號（默認為空即可） |
| 4 | labelModeSize | Integer | 否 | 面單打印樣式；<br>0：面單類100\*210；<br>1：面單類100\*150；<br>2：面單類100\*45（BBD合單包裹條形碼）<br>5：面單類，100\*45(參考編號面單)<br>7：面單類100\*45(BBD合單包裹二維碼)<br>8：面單類100\*45(BBD預合單大包裹條形碼)<br>printType=1時默認值為1，printType=2時默認值為0 |
| 5 | printType | String(8) | 是 | 打印類型。1-運單 2-發票 |
| 6 | printWaybillNoDtoList | List\<PrintWaybillNoDto> | 是 | 支持批量打印 母單相同的子單（含母單） 打印參數對象 |
| 7 | fileFormat | String(8) | 否 | 打印文件格式：只支持PDF;默認為PDF |

#### 元素<請求>PrintWaybillNoDto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | 是 | 順豐運單號 |
| 2 | isPrintSubParent | Integer | 否 | 是否打印全部的子母單 0：否 1：是 默認值：0 |
| 3 | singlePrintWaybillNoList | List\<String> | 否 | 單獨打印的運單號集合(含母單，子單)；**若isPrintSubParent 為0，則必填** |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | AsyncPrintOrderResp | 否 |  |

#### 元素PrintOrderData

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | printRequestId | String | 打印請求id |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "requestId": "fef366337d324561b4ed2b61a3199642",
    "labelModeSize": 0,
    "printType": "1",
    "printWaybillNoDtoList": [
        {
            "isPrintSubParent": 1,
            "sfWaybillNo": "SF1030358177716"
        }
    ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "printRequestId": "bcb43e8de88c4a93bc8a28e4e195da8f"
    },
    "msg": "执行成功",
    "success": true
}
```

---

## 5. 異步打印面單結果查詢 — `IUOP_ASYN_PRINT_RESULT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=90（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 異步打印面單結果查詢；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
使用異步打印返回的打印請求id查詢打印結果url。
建議打印請求發出後延時一定時間（例如2分鐘）再調用本接口，並且查詢頻率不超過每分鐘一次。
注意：目前創建物流訂單接口返回信息中已包含面單的下載鏈接，通常無需專門對接此接口獲取面單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_ASYN_PRINT_RESULT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | requestId | String(64) | 是 | 請求id（可使用UUID，確保請求唯一性字段，只要確保每個請求的requestId唯一即可） |
| 3 | version | String(10) | 否 | 版本號（默認為空即可） |
| 4 | printRequestId | String | 是 | 打印請求id |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | AsyncPrintResultResp | 否 |  |

#### 元素AsyncPrintResultResp

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | url | String | 打印的URL |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "printRequestId":"bcb43e8de88c4a93bc8a28e4e195da8f"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "url": "http://ibu-ioms-tool.sit.sf-express.com/file/print/async/pdf/20220729/bcb43e8de88c4a93bc8a28e4e195da8f.pdf"
    },
    "msg": "执行成功",
    "success": true
}
```

---

## 6. 預估運費 — `IUOP_QUERY_FREIGHT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=85（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 預估運費；官方更新 2026-06-09；爬取 2026-09-23）

### 功能描述

```
根據客戶編碼及費用查詢對象預估運費信息
注意：未包含其他附加費用,iuop有權限控制，查詢原價無控制
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_QUERY_FREIGHT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | version | String(10) | 否 | 版本號（默認為空即可） |
| 3 | senderCountry | String | 是 | 寄件國家/地區的二字碼 採用ISO 3166 標準 Alpha-2 code 如：CN,US,JP,KR,SG,MY |
| 4 | receiverCountry | String | 是 | 收件國家/地區的二字碼 採用ISO 3166 標準 Alpha-2 code 如：CN,US,JP,KR,SG,MY |
| 5 | senderPostCode | String | 是 | 寄方郵編 |
| 6 | receiverPostCode | String | 是 | 到方郵編 |
| 7 | interProductCode | String | 是 | 產品統一映射碼 |
| 8 | payMonthCard | String | 否 | 月結帳號（如果客戶有最好填寫，如果有白名單限制不填月結卡號將無法查到結果） |
| 9 | consignedTm | Date | 否 | 寄件時間,YYYY-MM-DD HH:mm:ss（默認當前時間） |
| 10 | weight | String | 是 | 實際重量（KG） |
| 11 | currency | String | 否 | 幣種，傳入則按該幣種返回費用 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 | 業務接口響應數據 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | currency | String | 幣種 |
| 2 | totalFreight | String | 總費用 |
| 3 | weight | String | 重量 |
| 4 | discountedRate | String | 折扣運費 |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "discountedRate": "100.0",	
    "interProductCode": "INT0007",
    "receiverCountry": "SG",
    "receiverPostCode": "499938",
    "senderCountry": "CN",
    "senderPostCode": "518052",
    "weight": "12.01"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "currency": "CNY",
        "discountedRate": "100.0",
        "totalFreight": "100.0",
        "weight": "12.5"
    },
    "msg": "执行成功",
    "success": true
}
```

---

## 7. 預估總費用 — `IUOP_ESTIMATE_FEE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=129（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 預估總費用；官方更新 2024-07-03；爬取 2026-09-23）

### 功能描述

```
預估運費以及其他附加費用。
此接口功能需額外申請開通，請聯繫順豐銷售協助開通，嚴格權限控制
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_ESTIMATE_FEE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | Y | 客戶編碼 |
| 2 | version | String(10) | N | 版本號（默認為空即可） |
| 3 | interProductCode | String(32) | Y | 國際產品映射碼 |
| 4 | parcelQuantity | Int(8) | N | 包裹總件數(大於1表示是子母件，最大值300) |
| 5 | parcelTotalWeight | BigDecimal(16,2) | N | 包裹總重量。精確到小數點後2位<br>重量單位<br>-LBS (US & CA);-KG(else); |
| 6 | parcelTotalLength | BigDecimal(16,2) | N | 客戶訂單貨物總長。精確到小數點後2位，尺寸長度單位<br>原寄國為CA和US，長度單位為INCH；其他為CM |
| 7 | parcelTotalWidth | BigDecimal(16,2) | N | 客戶訂單貨物總寬。精確到小數點後2位 |
| 8 | parcelTotalHeight | BigDecimal(16,2) | N | 客戶訂單貨物總高。精確到小數點後2位 |
| 9 | declaredValue | BigDecimal (16,2) | N | 總商品申報價值，單件商品申報價值\*數量獲取 |
| 10 | declaredCurrency | String(10) | N | 申報價值幣種 |
| 11 | paymentInfo | PaymentInfo | N | 付款方式信息 |
| 12 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 13 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 14 | customsInfo | CustomsInfo | N | 報關信息 |
| 15 | addServiceInfoList | List\<AddServiceInfo> | N | 增值服務信息 |

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
| 1 | country | String(5) | Y | 寄件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 2 | postCode | String(20) | Y | 郵編 |
| 3 | regionFirst | String(128) | N | 寄件方地址一級區劃（州/省） |
| 4 | regionSecond | String(128) | N | 寄件方地址二級區劃（城市） |
| 5 | regionThird | String(128) | N | 寄件方地址三級區劃（區/縣） |
| 6 | address | String(200) | N | 寄件方詳細地址 |
| 7 | cargoType | INT(1) | N | 寄件方貨物類型：1-個人件；2-公司件 |

#### 元素<請求>ReceiverInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | country | String(5) | Y | 收件方國家/地區，如：CN,US,JP,KR,SG,MY 更多參考附錄字典 |
| 2 | postCode | String(20) | Y | 郵編 |
| 3 | regionFirst | String(128) | N | 收件方地址一級區劃（州/省） |
| 4 | regionSecond | String(128) | N | 收件方地址二級區劃（城市） |
| 5 | regionThird | String(128) | N | 收件方地址三級區劃（區/縣） |
| 6 | address | String(200) | N | 收件方詳細地址 |
| 7 | cargoType | INT(1) | N | 收件方貨物類型：1-個人件；2-公司件 |

#### 元素<請求>CustomsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | importCustomsType | String(30) | N | 進口報關方式 參考附錄字典 |
| 2 | exportCustomsType | String(30) | N | 出口報關方式 參考附錄字典 |

#### 元素<請求>AddServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | serviceCode | String(32) | Y | 增值服務代碼 <br>SPP——保價 <br>COD——代收貨款 |
| 2 | value | String(256) | Y | 保價申報價值；<br>此項未填寫，則取值申報價值declaredValue；<br>value和declaredValue均未填寫，則報錯提示“請填寫保價申報價值”。<br>保價申報價值必須大於等於1CNY且小於等於500萬CNY |
| 3 | valueOne | String(256) | N | 增值服務擴展屬性1 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | RespDto | 否 |  |

#### 元素RespDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | currency | String | 幣種 |
| 2 | totalFee | BigDecimal | 總費用 |
| 3 | weight | BigDecimal | 重量 |
| 4 | interProductCode | String | 國際產品映射代碼 |
| 5 | feeInfoList | List\<Dto> | 費用信息列表 |

#### 元素RespDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | serviceName | String | 費用類型：<br>運費<br>高峰資源調節費<br>國際偏遠附加服務費<br>保價<br>代收貨款<br>進口報關增值服務費<br>出口報關增值服務費<br>等相關費用<br>僅返回已有費用類型，如無則不返回 |
| 2 | estimateFee | BigDecimal | 預估費用金額 |
| 3 | estimatePayFee | BigDecimal | 折後費用金額 |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "version": "",
    "interProductCode": "INT0014",
    "parcelQuantity": 1,
    "parcelTotalWeight": 5,
    "parcelTotalLength": 10,
    "parcelTotalWidth": 20,
    "parcelTotalHeight": 30,
    "declaredValue": 1000,
    "declaredCurrency": "USD",
    "paymentInfo": {
        "payMethod": "1",
        "payMonthCard": "7551234567",
        "taxPayMethod": "1",
        "taxPayMonthCard": "7551234567"
    },
    "senderInfo": {
        "country": "US",
        "postCode": "10000",
        "regionFirst": "NewYork",
        "regionSecond": "Abbeville",
        "regionThird": "",
        "address": "306SBRYNMAWRAVE",
        "cargoType": 1
    },
    "receiverInfo": {
        "country": "CN",
        "postCode": "518000",
        "regionFirst": "Guangdong",
        "regionSecond": "Shenzhen",
        "regionThird": "Luohu",
        "address": "软件产业基地1栋B栋",
        "cargoType": 1
    },
    "customsInfo": {
        "importCustomsType": "1",
        "exportCustomsType": ""
    },
    "addServiceInfoList": [
        {
            "serviceCode": "SPP",
            "value": "1000",
            "valueOne": ""
        },
        {
            "serviceCode": "COD",
            "value": "100",
            "valueOne": "0010002117"
        }
    ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "currency": "USD",
        "feeInfoList": [
            {
                "estimateFee": 12,
                "estimatePayFee": 12,
                "serviceName": "运费"
            },
            {
                "estimateFee": 13,
                "estimatePayFee": 13,
                "serviceName": "保价"
            },
            {
                "estimateFee": 1.56,
                "estimatePayFee": 1.56,
                "serviceName": "燃油附加费"
            },
            {
                "estimateFee": 2,
                "estimatePayFee": 2,
                "serviceName": "进口报关增值服务"
            },
            {
                "estimateFee": 8,
                "estimatePayFee": 8,
                "serviceName": "资源调节费"
            }
        ],
        "interProductCode": "INT0014",
        "totalFee": 36.56,
        "weight": 5
    },
    "msg": "执行成功",
    "success": true
}
```

---

## 8. 上傳清關資料 — `IUOP_UPLOAD_CERTIFY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=81（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 上傳清關資料；官方更新 2025-05-19；爬取 2026-09-23）

### 功能描述

```
清關證件或資料上傳接口，
支持上傳的文件格式為PNG，JPG，PDF，單個文件/照片必須小於3M。
除身份證、護照和其他證件外，其餘的證件類型均支持pdf格式。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_UPLOAD_CERTIFY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | version | String(10) | 否 | 版本號（默認為空即可） |
| 3 | sfWaybillNo | String(32) | 否 | 順豐運單號(母單號),順豐運單號與客戶訂單號二者填其一 |
| 4 | customerOrderNo | String(64) | 否 | 客戶訂單號,順豐運單號與客戶訂單號二者填其一 |
| 5 | certType | String | 是 | 參考附錄 “證件類型聲明” 以及 “報關資料類型”<br>身份證類型需上傳正反面2張圖片 |
| 6 | fileFormat | String | 否 | 文件格式（JPG、PNG、PDF） |
| 7 | fileContent | String | 否 | 文件內容（Base64編碼） 若證件類型是身份證，此字段建議為身份證正面 |
| 8 | fileContentTwo | String | 否 | 文件內容（Base64編碼） 若證件類型是身份證，此字段建議為身份證反面 |
| 9 | certName | String(100) | 否 | 證件姓名 |
| 10 | certCardNo | String(100) | 否 | 證件號碼 |
| 11 | cretsFlag | String | 否 | 證件歸屬：0-收方，1-寄方 |
| 12 | customerInvoiceNo| String | 否 | 客戶發票號 |
| 13 | customerInvoiceDate| String | 否 | 客戶發票時間 |

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
    "certCardNo": "330381199408080924",
    "certName": "邱洁",
    "certType": "001",
    "customerCode": "ICRM-CN002WSQ78",
    "customerOrderNo": "",
    "fileContent": "/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDACodICUgGiolIiUvLSoyP2lEPzo6P4FcYUxpmYagnpaGk5...",
    "fileContentTwo": "/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAD0qLjYuJj02MjZFQT1JXJpkXFRUXLyGjm+a38Tq5tv...",
    "fileFormat": "jpg",
    "sfWaybillNo": "SF1334451527920",
    "version": "1"
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

## 9. 清關資料是否有效查詢 — `IUOP_VALIDITY_CERTIFY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=82（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 清關資料是否有效查詢；官方更新 2024-06-17；爬取 2026-09-23）

### 功能描述

```
查詢證件在順豐關務系統中是否存在
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_VALIDITY_CERTIFY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | version | String | 否 | 版本號（默認為空即可） |
| 2 | userName | String | 是 | 客戶姓名 |
| 3 | cardNo | String | 否 | 證件號 |
| 4 | telPhone | String | 否 | 客戶電話 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Boolean | 否 | true:有效，false:無效 |

#### 明文請求示例

```
{
    "cardNo": "330381199408080924",
    "telPhone": "95338",
    "userName": "邱洁"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": true,
    "msg": "执行成功",
    "success": true
}
```

---

## 10. 上傳清關資料要求查詢 — `IUOP_REQUIRE_CERTIFY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=83（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 上傳清關資料要求查詢；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
清關證件或資料上傳要求查詢接口；
詳細說明已上傳和需要上傳的證件的資料信息，以及上傳資料的提示信息
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_REQUIRE_CERTIFY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | version | String(10) | 否 | 版本號（默認為空即可） |
| 3 | requestId | String(64) | 是 | 請求id（可使用UUID，確保請求唯一性字段，只要確保每個請求的requestId唯一即可） |
| 4 | sfWaybillNo | String(32) | 否 | 順豐運單號，客戶訂單號和順豐運單號不能同時為空 |
| 5 | customerOrderNo | String(64) | 否 | 客戶訂單號，客戶訂單號和順豐運單號不能同時為空 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | IuopRequireCertifyRespBody | 否 | 業務接口響應數據 |

#### 元素IuopRequireCertifyRespBody

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | cusCertsInfoList | List\<CusCertsInfoDto> | 需上傳證件的要求 |
| 2 | uploadedCertsInfoList | List\<UploadStatisticsCertDto> | 已上傳證件 |
| 3 | tips | String | 溫馨提示 |

#### 元素CusCertsInfoDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | ieFlag | String | 進出口標識 |
| 2 | certType | String | 單證類型（報關資料(I) 報關證件(C)） |
| 3 | cretsFlag | String | 單證歸屬, 0-收方,1-寄方 |
| 4 | cusCertGroupInfoDtoList | List\<CusCertGroupInfoDto> | 需上傳證件詳細規則 |

#### 元素CusCertGroupInfoDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | certState | String | 單證提示說明（需要根據語言） |
| 2 | needNums | Integer | 所需單證名稱數量 (needNums=-1，表示僅提示；needNums=0，表示選填；needNums=2，表示這幾個裡面必須上傳2項；) |
| 3 | cusCertsDetailDtolist | List\<CusCertsDetailDto> | 單證名稱 |

#### 元素CusCertsDetailDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | certNameId | String | 單證名稱ID |
| 2 | certName | String | 單證名稱(需要根據語言) |
| 3 | certDescription | String | 單證描述(需要根據語言) |
| 4 | telUpload | String | 是否上傳號碼（1：上傳，0：無需上傳） |
| 5 | imageUpload | String | 是否上傳圖片（0：無需上傳，1：需上傳，2：已上傳需審核， 3：已上傳無需審核） |
| 6 | certAmount | Integer | 上傳單證圖片的最小數量(上傳圖片時必填) |
| 7 | certMaxAmount | Integer | 上傳單證圖片的最大數量(上傳圖片時必填) |

#### 元素UploadStatisticsCertDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | certNameId | String | 單證名稱ID |
| 2 | certName | String | 單證名稱(需要根據語言) |
| 3 | postFlag | String | 單證歸屬, 0-收方,1-寄方 |
| 4 | certAmount | Integer | 單證數量 |
| 5 | certNo | String | 上傳的證件號 |
| 6 | otherCertNameList | List\<String> | 其他報關資料上傳的文件名集合 |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "requestId": "16232272226916",
    "sfWaybillNo": "SF1342602425144"
}
```

#### 明文響應示例

```
 {
        "msg": "执行成功",
        "code": "0",
        "data": {
            "uploadedCertsInfoList": [],
            "cusCertsInfoList": [],
            "tips": "订单无需上传图片"
        },
        "success": true
    }
```

---

## 11. BBD預合單 — `IUOP_BBD_PRE_MERGE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=88（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > BBD預合單；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
目前僅企業月結客戶可以預合自己的BBD單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_BBD_PRE_MERGE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | version | String(10) | 否 | 版本號（默認為空即可） |
| 3 | sfWayBillNos | List\<String> | 是 | 待合運單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 業務接口響應數據 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | preMergeSfWaybillNo | String | 預合單號 |
| 2 | printUrl | String | 運單打印地址 |

#### 明文請求示例

```
{
	"version": "1",
	"customerCode": "ICRME000SRN93",
	"sfWayBillNos": [
		"SF1342822426747",
		"SF1334452633802"
	]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "preMergeSfWaybillNo": "SFXXXXXXXXXXXX"
    },
    "msg": "successfulexecution",
    "success": true
}
```

---

## 12. BBD合單結果查詢 — `IUOP_BBD_MERGE_RESULT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=185（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > BBD合單結果查詢；官方更新 2024-06-14；爬取 2026-09-23）

### 功能描述

```
BBD真實合單結果查詢接口
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_BBD_MERGE_RESULT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(32) | 是 | 客戶編碼 |
| 2 | sfWaybillNo | String(32) | 是 | 順豐運單號（母單或子單） |
| 3 | mergeSfWaybillNo | String(32) | 是 | 合單單號（母單或子單） |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 |  |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | mergeSfWaybillNos | String | 合單單號（可包含多個，格式：母單號,子單號,子單號...） |
| 2 | mergeRelationList | List\<DtoInfo> | 合單關係 |

#### 元素DtoInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | mergeSfWaybillNo | String | 合單號（大單母單號或子單號） |
| 2 | sfWaybillNos | String | 順豐運單號（可包含多個，格式：運單號,運單號,運單號,...） |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "mergeSfWaybillNo": "SF1040458769540",
    "sfWaybillNo": "SF7000500391343"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": [
        {
            "mergeRelationList": [
                {
                    "mergeSfWaybillNo": "SF7000600582129",
                    "sfWaybillNos": "SF1040459004227,SF2010298492600"
                },
                {
                    "mergeSfWaybillNo": "SF7000502313216",
                    "sfWaybillNos": "SF1040458769540,SF2010302952714"
                }
            ],
            "mergeSfWaybillNos": "SF7000502313216,SF7000600582129"
        }
    ],
    "msg": "执行成功",
    "success": true
}
```

---

## 13. 取消BBD預合單 — `IUOP_CANCEL_BBD_MERGE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=192（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 取消BBD預合單；官方更新 2024-06-14；爬取 2026-09-23）

### 功能描述

```
取消BBD預合單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_CANCEL_BBD_MERGE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | sfWayBillNos | List\<String> | 是 | 預合單號列表 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Boolean | 否 |  |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "sfWayBillNos": [
        "SF1342822426747",
        "SF1334452633802"
    ]
}
```

#### 明文響應示例

```
{
    "code": "0",
    "msg": "success",
    "success": true,
    "data": true
}
```

---

## 14. 快遞櫃查詢 — `IOMS_QUERY_LOCKER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=217（文檔中心 > API文檔 > 國際件API > 國際快遞業務場景接入 > 快遞櫃查詢；官方更新 2025-02-28；爬取 2026-09-23）

### 功能描述

```
目前只支持新加坡的快遞櫃查詢
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_QUERY_LOCKER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | Y | 國家代碼 |
| 2 | parcelHeight | String | N | 包裹高 |
| 3 | parcelLength | String | N | 包裹長度 |
| 4 | parcelWeight | String | N | 包裹寬 |
| 5 | parcelWidth | String | N | 包裹重 |
| 6 | postalCode | String | Y | 郵政編碼 |
| 7 | streetName | String | N | 街道名稱 |
| 8 | waybillno | String | N | 運單號 |
| 9 | blockNumber | String | N | 街區編碼 |
| 10 | buildingName | String | N | 建築物名稱 |
| 11 | lockerStationId | String | N | 快遞櫃id |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | CabinetData | 否 |  |

#### \*\*元素CabinetData \*\*

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | errorMessage | String | 錯誤信息 |
| 2 | responseDateTime | Date | 響應時間 |
| 3 | success | Boolean | 是否成功 |
| 4 | transactionId | String | 事務id |
| 5 | transactionStatus | String | 事務狀態 |
| 6 | warning | String | 警告信息 |
| 7 | waybillNo | String | 運單號 |
| 8 | lockerStationDetails | List\<LockerStationDetail> | 快遞櫃詳情 |
| 9 | paginationDetails | List\<PaginationDetail> | 分頁信息 |

#### 元素<請求>LockerStationDetails

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | blockNumber | String | 街區編碼 |
| 2 | buildingName | String | 建築物名稱 |
| 3 | countryCode | String | 國家代碼 |
| 4 | deliveryFeatureAvailable | List | 預留 |
| 5 | isReturnEligible | Boolean | 是否支持消費者退貨 |
| 6 | latitude | Bigdecimal | 緯度 |
| 7 | lockerOperatorId | String | 唯一的操作id |
| 8 | lockerStationDescription | String | 快遞櫃描述 |
| 9 | lockerStationDistance | Bigdecimal | 快遞櫃距離 |
| 10 | lockerStationId | String | 快遞櫃id |
| 11 | lockerStationName | String | 快遞櫃名稱 |
| 12 | lockerUsagePopularity | String | 快遞櫃受歡迎程度 |
| 13 | longitude | Bigdecimal | 經度 |
| 14 | openingHours | String | 快遞櫃開放時間 |
| 15 | paymentOptionsSupported | List | 預留 |
| 16 | postalCode | String | 郵政編碼 |
| 17 | state | String | 狀態 |
| 18 | streetName | String | 街道名稱 |
| 19 | validPostalCodes | List\<String> | 郵政編碼列表 |

#### 元素<請求>PaginationDetails

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | pageNumber | Integer | 數量 |
| 2 | totalResults | Integer | 查詢結果 |

#### 明文請求示例

```
{
    "waybillno":"SF123456789012345",
    "streetName": "Cantonment Road",
    "countryCode":"sg",
    "postalCode":"079718",
    "parcelLength":"18.0",
    "parcelWidth":"12.0",
    "parcelHeight":"30.0",
    "parcelWeight":"2.0"
}
```

#### 明文響應示例

```
{
  "code": "0",
  "data": {
    "waybillNo": null,
    "transactionId": null,
    "transactionStatus": null,
    "success": true,
    "errorMessage": null,
    "warning": null,
    "responseDateTime": "2025-01-09T18:18:20.000+08:00",
    "paginationDetails": [
      {
        "pageNumber": 1,
        "totalResults": 6
      }
    ],
    "lockerStationDetails": [
      {
        "lockerOperatorId": "799a8c99-2e2e-4b1c-b45b-40013f4045b6",
        "lockerStationId": "d876bb22-9dd2-4e6a-ab12-9def3a9850e9",
        "lockerStationName": "ParcelSanta",
        "lockerStationDescription": "TheLine@TanjongRhu,Tower2Lobby",
        "openingHours": "24/7",
        "blockNumber": "-",
        "buildingName": "-",
        "streetName": "6TanjongRhuRoad,Singapore436883",
        "state": "-",
        "countryCode": "65",
        "postalCode": "436883",
        "latitude": 1.290179967880249,
        "longitude": 103.85198974609375,
        "lockerStationDistance": 307,
        "lockerUsagePopularity": null,
        "isReturnEligible": true,
        "deliveryFeatureAvailable": [],
        "paymentOptionsSupported": [],
        "validPostalCodes": [
          "436883"
        ]
      }
    ]
  },
  "msg": "执行成功",
  "success": true
}
```
