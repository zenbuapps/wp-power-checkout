# 跨境運輸（IFTP）接口：空 / 海 / 陸 / 鐵運整車整櫃

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：B2B 大貨（整機、包板、散銷、空運、海運、陸運、鐵運、倉配、醫藥跨境、國際大件）以 `IFTP_*` 建單 / 查詢 / 面單 / 取消 / 上傳報關資料，或作為供應商回傳 GPS 軌跡與運費明細時。

邊界：這是貨代型業務（範例為深圳→廣州陸運、「中越智達」陸運產品），**不是台灣電商宅配件**。台灣電商寄件走 IUOP。
- `IFTP_*` 的 `interProductCode`（`INT0687`…`INT0576`）與快遞產品碼是不同集合。
- 面單下載 URL 需在 header 帶 `X-Auth-Token`（回應中的 `token`）。
- `SF_IFTP_RECEIVE_GPS_TRACK` / `SF_IFTP_RECEIVE_COST_DETAILS` 是「供應商 → 順豐」方向。
- 注意官方表格筆誤：CargoInfo 的 `grossWeight` 與 `packagingType` 說明互換（grossWeight 寫包裝類型、packagingType 寫毛重）。

## 目錄

- [1. 創建訂單 — `IFTP_CREATE_ORDER`](#1-創建訂單--iftp_create_order)
- [2. 查詢訂單 — `IFTP_QUERY_ORDER`](#2-查詢訂單--iftp_query_order)
- [3. 獲取面單 — `IFTP_PRINT_ORDER`](#3-獲取面單--iftp_print_order)
- [4. 取消訂單 — `IFTP_CANCEL_ORDER`](#4-取消訂單--iftp_cancel_order)
- [5. 上傳清關資料 — `IFTP_UPLOAD_CERTIFY`](#5-上傳清關資料--iftp_upload_certify)
- [6. GPS軌跡接收 — `SF_IFTP_RECEIVE_GPS_TRACK`](#6-gps軌跡接收--sf_iftp_receive_gps_track)
- [7. 運費明細接收 — `SF_IFTP_RECEIVE_COST_DETAILS`](#7-運費明細接收--sf_iftp_receive_cost_details)

---

## 1. 創建訂單 — `IFTP_CREATE_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=232（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 創建訂單；官方更新 2026-06-26；爬取 2026-09-23）

### 功能描述

```
創建跨境運輸訂單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IFTP_CREATE_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | billingWeight | DECIMAL(15,2) | N | 計費重量，單位KG |
| 2 | cargoOtherInfo | String(500) | N | 貨物其他信息 |
| 3 | customerOrderNo | String(40) | Y | 客戶訂單號 |
| 4 | customsClearanceMethod | Integer | N | 進口清關 1：順豐清關 2：自行清關 |
| 5 | customsDeclarationMethod | Integer | N | 出口申報方式1：保稅申報，2：一般貿易 |
| 6 | departureCity | String(50) | Y | 始發城市 深圳市 |
| 7 | departurePort | String(30) | N | 始發港/站/地 SZX |
| 8 | destinationCity | String(50) | Y | 目的城市 北寧市 |
| 9 | destinationPort | String(30) | N | 目的港/站/地 LAX |
| 10 | expectedDepartureTime | Date | N | 期望發車時間 yyyy-MM-dd HH:MM:SS |
| 11 | exportDeclareMethod | Integer | N | 出口申報方式1：保稅申報，2：一般貿易 |
| 12 | goodsReadyTime | Date | Y | 預計貨好時間 yyyy-MM-dd HH:MM:SS |
| 13 | goodsValue | DECIMAL(15,2) | Y | 貨物價值（行業類型為【本地】時選填） |
| 14 | goodsValueCurrency | String(3) | Y | 貨物價值幣種（行業類型為【本地】時選填） |
| 15 | importDeclareMethod | Integer | N | 進口申報方式1：屬地申報，2：口岸申報 |
| 16 | interProductCode | String(10) | Y | 國際產品映射碼，即順豐物流服務產品代碼‌：<br>INT0687:國際整機SE0167<br>INT0688:國際包板SE0168<br>INT0689:國際散銷SE0169<br>INT0702:國際運輸-空運SE007920<br>INT0704:國際運輸-海運SE007922<br>INT0705:國際運輸-陸運SE007923<br>INT0703:國際運輸-鐵運SE007921<br>INT0087:倉配SE0111<br>INT0723:醫藥跨境SE0172<br>INT0576:國際大件SE0037 |
| 17 | monthlyCardNo | String(20) | Y | 客戶順豐月結卡號 |
| 18 | productLimitType | Integer | N | 產品類型，僅限陸運產品選擇：<br>1：中越智達-標快<br>2：中越智達-限時 |
| 19 | remark | String(100) | N | 備註 |
| 20 | totalPiece | Integer | Y | 總件數 |
| 21 | totalVolume | DECIMAL(15,2) | Y | 總體積，單位CBM |
| 22 | totalWeight | DECIMAL(15,2) | Y | 總毛重，單位KG |
| 23 | tradeClause | String(3) | Y | 貿易條款：<br>CIF<br>CIP<br>CPT<br>CFR<br>DAP<br>DDP<br>EXW<br>FOB<br>FCA<br>DDU<br>DAT<br>FAS |
| 24 | transportMode | Integer | Y | 運輸模式(1.空運、2.海運、3.陸運、4.鐵運） |
| 25 | transportType | Integer | Y | 裝載類型<br>陸運1：整車，2：零擔<br>海運1：整櫃，2：拼箱<br>空運1：整板，2：散艙<br>鐵運1：整櫃，2：散貨 |
| 26 | volumeWeight | DECIMAL(15,2) | N | 體積重，單位KG |
| 27 | cargoInfoList | List\<CargoInfo> | N | 貨物信息 |
| 28 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 29 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 30 | industryType | Integer | N | 行業類型: 0-跨境 2-本地 （默認0-跨境） |
| 31 | needPacking | Integer | N | 是否需要包裝服務：0 否 1是 (默認 0) |
| 32 | notStacked | Integer | N | 是否可堆疊：0=否 1=是 2=不清楚 (默認 0) |
| 33 | truckType | String(50) | N | 車輛類型 ：Mini Van ; 10FT Lorry ; 14FT Lorry ; 24FT Lorry（默認 Mini Van） |
| 34 | driverType | Integer | N | 助手類型：0-司機;1-司機+1名助手;2-司機+2名助手 (默認 0-司機) |

#### 元素<請求>CargoInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | cargoName | String(100) | Y | 貨物名稱 |
| 2 | cargoType | Integer | Y | 貨物類型 1-普貨，2-帶電貨物，3-帶磁貨物，4-帶磁帶電貨物<br>（行業類型為【本地】時選填） |
| 3 | grossWeight | Integer | Y | 包裝類型(1:紙箱 4:托盤 5:木箱 8:袋裝 9:裸裝)<br>（行業類型為【本地】時選填） |
| 4 | height | DECIMAL(15,2) | Y | 客戶訂單貨物高（單位：cm）<br>（行業類型為【本地】時選填） |
| 5 | hsCode | String | N | 海關編碼（備註：[歐盟官網海關編碼查詢網址](https://trade.ec.europa.eu/access-to-markets/en/my-trade-assistant)） |
| 6 | length | DECIMAL(15,2) | Y | 客戶訂單貨物長（單位：cm）<br>（行業類型為【本地】時選填） |
| 7 | number | Integer | Y | 件數 |
| 8 | packagingType | Integer | Y | 毛重/KG<br>（行業類型為【本地】時選填） |
| 9 | width | DECIMAL(15,2) | Y | 客戶訂單貨物寬（單位：cm） <br>（行業類型為【本地】時選填） |
| 10 | remark | String(200) | N | 備註 |
| 11 | unit | Integer | N | 單位:1-件;2-箱;3-托;4-包;5-套 （行業類型為【本地】時必填） |

#### 元素<請求>ReceiverInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | address | String(200) | Y | 詳細地址 |
| 2 | company | String(100) | N | 公司 |
| 3 | contact | String(32) | Y | 聯繫人 |
| 4 | country | String(2) | Y | 國家二字碼如：中國CN，美國US |
| 5 | email | String(100) | N | 郵箱 |
| 6 | postCode | String(20) | N | 郵編 |
| 7 | regionFirst | String(20) | Y | 收件方地址一級區劃（州/省） |
| 8 | regionSecond | String(20) | Y | 收件方地址二級區劃（城市） |
| 9 | regionThird | String(20) | N | 收件方地址三級區劃（區/縣） |
| 10 | telAreaCode | String(10) | Y | 電話區號 |
| 11 | telNo | String(20) | Y | 固定電話 |
| 12 | pointName | String(32) | N | [整車]派送點名稱(整車必傳) |

#### 元素<請求>SenderInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | address | String(200) | Y | 詳細地址 |
| 2 | company | String(100) | N | 公司 |
| 3 | contact | String(32) | Y | 聯繫人 |
| 4 | country | String(2) | Y | 國家二字碼如：中國CN，美國US |
| 5 | email | String(100) | N | 郵箱 |
| 6 | postCode | String(20) | N | 郵編 |
| 7 | regionFirst | String(20) | Y | 寄件方地址一級區劃（州/省） |
| 8 | regionSecond | String(20) | Y | 寄件方地址二級區劃（城市） |
| 9 | regionThird | String(20) | N | 寄件方地址三級區劃（區/縣） |
| 10 | telAreaCode | String(10) | Y | 電話區號 |
| 11 | telNo | String(20) | Y | 固定電話 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Order | N |  |

#### 元素Order

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 順豐運單號 |
| 2 | param2 | String | 定義字段2 |
| 3 | param3 | String | 定義字段3 |

#### 明文請求示例

```
{
    "monthlyCardNo": "0270001104",
    "customerOrderNo": "TEST251124000001",
    "transportMode": 3,
    "transportType": 1,
    "interProductCode": "INT0087",
    "productLimitType": 1,
    "truckType": "5T",
    "goodsReadyTime": "2025-11-18 15:26:27",
    "expectedDepartureTime": "2025-11-18 19:26:27",
    "departureCity": "深圳市",
    "destinationCity": "广州市",
    "departurePort": "SZX",
    "destinationPort": "BJX",
    "tradeClause": "CPT",
    "remark": "订单备注",
    "totalPiece": 1,
    "totalVolume": 0.02,
    "volumeWeight": 8.21,
    "totalWeight": 10.5,
    "billingWeight": 10.5,
    "goodsValue": "7314",
    "goodsValueCurrency": "CNY",
    "cargoOtherInfo": "商品其它信息",
    "customsDeclarationMethod": "1",
    "exportDeclareMethod": "1",
    "customsClearanceMethod": "2",
    "importDeclareMethod": "2",
    "cargoInfoList": [
        {
            "cargoName": "测试商品01",
            "cargoType": 1,
            "packagingType": 1,
            "length": 100,
            "width": 50,
            "height": 20,
            "hsCode": "hscode01",
            "number": 1,
            "grossWeight": 10
        }
    ],
    "senderInfo": {
        "postCode": "518000",
        "country": "CN",
        "regionFirst": "广东省",
        "regionSecond": "深圳市",
        "regionThird": "宝安区",
        "address": "南山街道顺丰前海总部大厦",
        "contact": "张三三",
        "company": "寄件公司",
        "email": "13266660001@sf.com",
        "telAreaCode": "86",
        "telNo": "1326666001"
    },
    "receiverInfo": {
        "postCode": "518000",
        "country": "CN",
        "regionFirst": "广东省",
        "regionSecond": "广州市",
        "regionThird": "白云区",
        "address": "白云湖街道唐阁路268号",
        "contact": "王五五",
        "company": "收货公司",
        "email": "13266660002@sf.com",
        "telAreaCode": "86",
        "telNo": "1326666002"
    }
}
```

#### 明文響應示例

```
{
    "msg": "成功",
    "fail": false,
    "code": "0",
    "data": {
        "sfWaybillNo": "SF1303340902998"
    },
    "success": true
}
```

---

## 2. 查詢訂單 — `IFTP_QUERY_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=233（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 查詢訂單；官方更新 2025-11-24；爬取 2026-09-23）

### 功能描述

```
查詢跨境運輸訂單基礎信息，貨物信息，收寄方信息以及運費明細。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IFTP_QUERY_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(40)|  Y |  順豐單號 |
| 2 | transportMode | String(30)| Y  |運輸模式(1：空運、2：海運、3：陸運、4：鐵運）   |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Order| N | 訂單信息 |

#### 元素Order

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | ------ |
|1| sfWaybillNo| String(30) | 貨物其他信息 |
|2| billingWeight | DECIMAL(15,2) | 計費重量，單位KG |
|3| cargoOtherInfo | String(500) | 貨物其他信息 |
|4| customerOrderNo | String(40) | 客戶訂單號 |
|5| departureCity | String(50) | 始發城市 深圳市 |
|6| departurePort | String(30) | 始發港/站/地 SZX |
|7| destinationCity | String(50) | 目的城市 北寧市 |
|8| destinationPort | String(30) | 目的港/站/地 LAX |
|9| expectedDepartureTime | Date | 期望發車時間 yyyy-MM-dd HH:MM:SS |
|10| exportCustomsDeclaration| Integer | 出口報關 1：順豐報關 2：自行報關 |
|11| exportDeclareMethod | Integer | 出口申報方式1：保稅申報，2：一般貿易 |
|12| goodsReadyTime | Date | 預計貨好時間 yyyy-MM-dd HH:MM:SS |
|13| goodsValue | DECIMAL(15,2) | 貨物價值 |
|14| goodsValueCurrency | String(3) | 貨物價值幣種 |
|15| importDeclareMethod | Integer | 進口申報方式1：屬地申報，2：口岸申報 |
|16| interProductCode | String(10) | 國際產品映射碼，即順豐物流服務產品代碼‌：<br>INT0687:國際整機SE0167<br>INT0688:國際包板SE0168<br>INT0689:國際散銷SE0169<br>INT0702:國際運輸-空運SE007920<br>INT0704:國際運輸-海運SE007922<br>INT0705:國際運輸-陸運SE007923<br>INT0703:國際運輸-鐵運SE007921<br>INT0087:倉配SE0111<br>INT0723:醫藥跨境SE0172<br>INT0576:國際大件SE0037 |
|17| monthlyCardNo | String(20)|客戶順豐月結卡號  |
|18| productLimitType | Integer |產品類型，僅限陸運產品選擇：<br>1：中越智達-標快<br>2：中越智達-限時<br>  |
|19| remark | String(100)|備註  |
|20| totalPiece | Integer |總件數  |
|21| totalVolume | DECIMAL(15,2)|總體積，單位CBM  |
|22| totalWeight | DECIMAL(15,2)|總毛重，單位KG  |
|23| tradeClause | String(3)| 貿易條款：<br>CIF<br>CIP<br>CPT<br>CFR<br>DAP<br>DDP<br>EXW<br>FOB<br>FCA<br>DDU<br>DAT<br>FAS |
|24| transportMode | Integer |運輸模式(1.空運、2.海運、3.陸運、4.鐵運）  |
|25| transportType | Integer | 裝載類型<br>陸運1：整車，2：零擔<br>海運1：整櫃，2：拼箱<br>空運1：整板，2：散艙<br>鐵運1：整櫃，2：散貨 |
|26| volumeWeight | DECIMAL(15,2)| 體積重，單位KG |
|27| cargoInfoList | List\<CargoInfo> |貨物信息  |
|28| receiverInfo | ReceiverInfo | 收件人信息 |
|29| senderInfo | SenderInfo |  寄件人信息|
|30| truckType| String(20)|  車型|
| 31 | freightChargeDetails| FreightChargeInfo| 運費明細 |

#### 元素<請求>CargoInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
|1| cargoName | String(100)|貨物名稱  |
|2| cargoType | Integer |貨物類型 1-普貨，2-帶電貨物，3-帶磁貨物，4-帶磁帶電貨物  |
|3| grossWeight | Integer |包裝類型(1:紙箱 4:托盤 5:木箱 8:袋裝 9:裸裝)  |
|4| height | DECIMAL(15,2)| 客戶訂單貨物高（單位：cm） |
|5| hsCode | String |商品編碼  |
|6| length | DECIMAL(15,2)|客戶訂單貨物長（單位：cm）  |
|7| number | Integer |件數  |
|8| packagingType | Integer |毛重/KG  |
|9| width | DECIMAL(15,2)|客戶訂單貨物寬（單位：cm）  |

#### 元素ReceiverInfo
| **#** | **屬性名** | **類型（約束）**  | **描述** |
| --- | --- | ------ | --- | 
|1| address | String(200)|詳細地址  |
|2| company | String(100)|公司  |
|3| contact | String(32)| 聯繫人 |
|4| country | String(2)|國家二字碼如：中國CN，美國US |
|5| email | String(100)| 郵箱 |
|6| regionFirst | String(20)|  收件方地址一級區劃（州/省）|
|7| regionSecond | String(20)| 收件方地址二級區劃（城市） |
|8| regionThird |  String(20)| 收件方地址三級區劃（區/縣） |
|9| telAreaCode | String(10)| 電話區號 |
|10| telNo | String(20)| 固定電話 |

#### 元素SenderInfo
| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- | 
|1| address | String(200)|詳細地址  |
|2| company | String(100)|公司  |
|3| contact | String(32)| 聯繫人 |
|4| country | String(2)|國家二字碼如：中國CN，美國US |
|5| email | String(100)| 郵箱 |
|6| regionFirst | String(20)|  寄件方地址一級區劃（州/省）|
|7| regionSecond | String(20)| 寄件方地址二級區劃（城市） |
|8| regionThird |  String(20)| 寄件方地址三級區劃（區/縣） |
|9| telAreaCode | String(10)| 電話區號 |
|10| telNo | String(20)| 固定電話 |

#### 元素FreightChargeInfo
| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | freightItem| String | 費用項| 
| 2 | freightAmount| DECIMAL| 金額（含稅） |
| 3 | currency| String | 幣種 | 

#### 明文請求示例

```
{    
    "sfWaybillNo": "SF3160006466801",
    "transportMode": 3
}
```

#### 明文響應示例

```
{
    "msg": "成功",
    "fail": false,
    "code": "0",
    "data": {
        "goodsReadyTime": "2025-11-18 15:26:27",
        "totalVolume": 0.02,
        "freightChargeDetails": [],
        "monthlyCardNo": "0270001104",
        "receiverInfo": {
            "country": "CN",
            "address": "白云湖街道唐阁路268号",
            "regionFirst": "广东省",
            "contact": "王五五",
            "company": "收货公司",
            "regionSecond": "广州市",
            "email": "13266660002@sf.com",
            "telNo": "1326666002"
        },
        "remark": "订单备注",
        "goodsValue": 7314,
        "volumeWeight": 3.34,
        "importDeclareMethod": 2,
        "cargoOtherInfo": "商品其它信息",
        "productLimitType": 1,
        "totalPiece": 1,
        "goodsValueCurrency": "CNY",
        "transportType": 1,
        "sfWaybillNo": "SF3160006466801",
        "departureCity": "深圳市",
        "destinationCity": "广州市",
        "tradeClause": "CPT",
        "exportDeclareMethod": 1,
        "senderInfo": {
            "country": "CN",
            "address": "南山街道顺丰前海总部大厦",
            "regionFirst": "广东省",
            "contact": "张三三",
            "company": "寄件公司",
            "regionSecond": "深圳市",
            "email": "13266660001@sf.com",
            "telNo": "1326666001"
        },
        "billingWeight": 10.5,
        "cargoInfoList": [],
        "totalWeight": 10.5,
        "customerOrderNo": "TEST25111801763444325806"
    },
    "success": true
}
```

---

## 3. 獲取面單 — `IFTP_PRINT_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=234（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 獲取面單；官方更新 2025-11-24；爬取 2026-09-23）

### 功能描述

```
客戶可以通過順豐運單號獲取發貨面單PDF打印文件
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IFTP_PRINT_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | onePdf | String |  Y | 是否全成一個PDF文件，默認：Y  |
| 2 | sfWaybillNoList | List\<String> | Y   |  順豐單號列表，限制為20個 |
| 3 | transportMode | String| Y  | 運輸模式(1：空運、2：海運、3：陸運、4：鐵運）  |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Label| N | 面單信息 |

#### 元素Label

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo| String | 順豐單號 |
| 2 | token| String | 認證token，需要加在下載URL的請求頭X-Auth-Token |
| 3 | url| String | 合併面單URL地址，onePdf=Y時有值 |
| 4 | list| List\<SubLabel>| 未合併的面單URL列表，onePdf=N時有值 |

#### 元素SubLabel

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo| String | 順豐單號 |
| 2 | token| String | 認證token，需要加在下載URL的請求頭X-Auth-Token |
| 3 | url| String | 合併面單URL地址，onePdf=Y時有值 |

#### 明文請求示例

```
{
    "sfWaybillNoList": [
        "SF3160006466949"
    ],
    "transportMode": 3,
    "onePdf": "Y"
}
```

#### 明文響應示例

```
```

---

## 4. 取消訂單 — `IFTP_CANCEL_ORDER`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=235（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 取消訂單；官方更新 2025-11-24；爬取 2026-09-23）

### 功能描述

```
取消訂單
注意：順豐未正式提貨發車前可取消訂單，說明如已發車前往客戶提貨處禁止取消訂單
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IFTP_CANCEL_ORDER`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | cancelReason | String(100) | Y  | 取消原因  |
| 2 | sfWaybillNo | String(15)  |  Y | 順豐單號  |
| 3 | transportMode | String(30) | Y  |  運輸模式(1：空運、2：海運、3：陸運、4：鐵運） |

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
    "sfWaybillNo": "SF1303340902980",
    "transportMode": 3,
    "cancelReason": "客户取消"
}
```

#### 明文響應示例

```
{
    "success": true,
    "code": 200,
    "msg": "成功"
}
```

---

## 5. 上傳清關資料 — `IFTP_UPLOAD_CERTIFY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=236（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 上傳清關資料；官方更新 2025-11-24；爬取 2026-09-23）

### 功能描述

```
順豐接收客戶接口上傳報關、清關文件，支持xls、xlsx、pdf、doc、docx、zip和rar格式。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IFTP_UPLOAD_CERTIFY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | operateType | Integer | Y  | 操作類型：1-新增、修改 2刪除- 默認1  |
| 2 | sfWaybillNo | String(20)| Y  |  順豐單號 |
| 3 | transportMode | String(30)| Y  |  運輸模式(1：空運、2：海運、3：陸運、4：鐵運） |
| 4 | processFormFileList | List\<FileInfo> | Y  |附件列表   |

#### 元素<請求>FileInfo
| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | binary | String | N | 不傳或傳空值  |
| 2 | fileFormat | String | Y  | 文件後綴  |
| 3 | fileName | String | Y |  文件名，刪除時必傳 |
| 4 | fileType | Integer | Y | 文件類型，1-出口報關資料;2-進口清關資料  |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | FileInfo| N | 響應的上傳結果信息  |

#### 元素FileInfo
| **#** | **屬性名** | **類型（約束）**| **描述** |
| --- | --- | ------ | --- |
| 1 | fileId | String |文件名  | 
| 2 | fileName | Long| 文件id.貨代系統文件身份唯一標識. | 

#### 明文請求示例

```
{
    "sfWaybillNo": "SF3160006467268",
    "transportMode": 3,
    "operateType": 1,
    "processFormFileList": [
        {
            "fileName": "测试xlsx文件",
            "fileType": 1,
            "fileFormat": "xlsx",
            "binary": ""
        }
    ]
}
```

#### 明文響應示例

```
{
    "msg": "成功",
    "fail": false,
    "code": "0",
    "data": [
        {
            "fileName": "测试xlsx文件",
            "fileId": "817461831916294145"
        }
    ],
    "success": true
}
```

---

## 6. GPS軌跡接收 — `SF_IFTP_RECEIVE_GPS_TRACK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=237（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > GPS軌跡接收；官方更新 2026-01-15；爬取 2026-09-23）

### 功能描述

接收GPS軌跡數據

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `SF_IFTP_RECEIVE_GPS_TRACK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | transportMode | String(30) | Y | 運輸模式 |
| 2 | dataSource | String(20) | Y | 車牌號/集裝箱號/設備號 |
| 3 | sfWaybillNo | List\<String> | N | 順豐單號 |
| 4 | measureTime | DateTime | Y | 測量時間 |
| 5 | timeZone | String(30) | Y | 時區,格式為±HH:MM示例:GMT+08:00 |
| 6 | longitude | BigDecimal | Y | 經度 |
| 7 | latitude | BigDecimal | Y | 維度 |
| 8 | direction | Int(11) | Y | 車頭方向0-360 (0為正北順時針遞加) |
| 9 | speed | Int(11) | N | 速度 |
| 10 | locationType | String(1) | Y | 定位方式 |
| 11 | gpsDataSource | String(100) | Y | 軌跡數據來源 |
| 12 | province | String(20) | Y | 洲/省 |
| 13 | city | String(20) | Y | 城市 |
| 14 | currentAddress | String(1000) | Y | 當前位置 |
| 15 | supplier | String(100) | Y | 設備廠家 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼;常見錯誤代碼 200成功 500失敗 |
| 3 | msg | String | 是 | 響應描述 |
| 4 | data | Object | 否 | 響應的上傳結果信息 |

#### 明文請求示例

```
{
    "transportMode": "3",
    "dataSource": "渝A12345",
    "sfWaybillNo": [
        "SF3239598989538"
    ],
    "measureTime": "2023-01-01 12:00:00",
    "timeZone": "GMT+08:00",
    "longitude": 114.0588,
    "latitude": 22.5429,
    "direction": 270,
    "speed": 60,
    "locationType": "1",
    "gpsDataSource": "1",
    "province": "广东省",
    "city": "深圳市",
    "currentAddress": "南山区科技园",
    "supplier": "路歌"
}
```

#### 明文響應示例

```
{
    "success": true,
    "code": 200,
    "msg": "成功"
}
```

---

## 7. 運費明細接收 — `SF_IFTP_RECEIVE_COST_DETAILS`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=238（文檔中心 > API文檔 > 國際件API > 跨境運輸業務場景接入 > 運費明細接收；官方更新 2026-01-15；爬取 2026-09-23）

### 功能描述

接收運費明細數據

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `https://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `SF_IFTP_RECEIVE_COST_DETAILS`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** | 
| --- | --- | ------ | --- | --- | 
| 1 | transportMode | String(30) | Y | 運輸模式（1.空運、2.海運、3.陸運、4.鐵運） |
| 2 | agentCode | String(20) | Y | 供應商編碼，順豐側提供 | 
| 3 | sfWaybillNo | String(15) | Y | 順豐單號 | 
| 4 | totalCostAmount | Decimal(14,2) | Y | 總運費(含稅) | 
| 5 | costList | List\<String\> | Y | 費用列表 | 
| 5.1 | costItem | String(20) | Y | 費用項（見IFTP 附錄 2） | 
| 5.2 | unit | String(10) | Y | 計量單位（單、公斤、立方米、次、車型、人力、托、箱、車、天、分段固定、W/M、票、月） | 
| 5.3 | costAmount | Decimal(14,2) | Y | 金額(含稅) | 
| 5.4 | currency | String(3) | Y | 幣種（見IFTP 附錄 1） | 

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | Boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼；常見錯誤代碼 200成功 500失敗 |
| 3 | msg | String | 是 | 響應描述 |
| 4 | data | Object | 否 | 響應的上傳結果信息 |

#### 明文請求示例
```
{
	"transportMode": "3",
	"agentCode": "OS20254215145206",
	"sfWaybillNo": "SF3260001530546",
	"costList": [{
		"costItem": "RFF",
		"costAmount": "2025.41",
		"currency": "USD",
		"unit": "单"
	}]
}
```

#### 明文響應示例
```
{
    "success": true,
    "code": 200,
    "msg": "成功"
}
```

### IFTP 附錄

### IFTP 附錄 1：幣種代碼

| 幣種 | 說明 |
| --- | --- |
|AED|阿聯酋迪拉姆|
|AFN|阿富汗尼|
|ALL|阿爾巴尼亞列克|
|AMD|亞美尼亞德拉姆|
|ANG|荷屬安的列斯盾|
|AOA|安哥拉寬扎|
|ARS|阿根廷比索|
|AUD|澳大利亞元|
|AWG|阿魯巴弗羅林|
|AZN|阿塞拜疆馬納特|
|BAM|波斯尼亞和黑塞哥維那可兌換馬克|
|BBD|巴巴多斯元|
|BDT|孟加拉塔卡|
|BHD|巴林第納爾|
|BIF|布隆迪法郎|
|BMD|百慕大元|
|BND|文萊元|
|BOB|玻利維亞諾|
|BRL|巴西雷亞爾|
|BSD|巴哈馬元|
|BTN|不丹努爾特魯姆|
|BWP|博茨瓦納普拉|
|BZD|伯利茲元|
|CAD|加拿大元|
|CDF|剛果法郎|
|CHF|瑞士法郎|
|CLP|智利比索|
|CNY|人民幣元|
|COP|哥倫比亞比索|
|CRC|哥斯達黎加科朗|
|CUP|古巴比索|
|CVE|佛得角埃斯庫多|
|CZK|捷克克朗|
|DJF|吉布提法郎|
|DKK|丹麥克朗|
|DOP|多米尼加比索|
|DZD|阿爾及利亞第納爾|
|EGP|埃及鎊|
|ERN|厄立特里亞納克法|
|ETB|埃塞俄比亞比爾|
|EUR|歐元|
|FJD|斐濟元|
|FKP|福克蘭群島鎊|
|GBP|英鎊|
|GEL|格魯吉亞拉里|
|GIP|直布羅陀鎊|
|GMD|岡比亞達拉西|
|GNF|幾內亞法郎|
|GTQ|危地馬拉格查爾|
|GYD|圭亞那元|
|HKD|港元|
|HNL|洪都拉斯倫皮拉|
|HRK|克羅地亞庫納|
|HTG|海地古德|
|HUF|匈牙利福林|
|IDR|印尼盾|
|ILS|以色列新謝克爾|
|INR|印度盧比|
|IQD|伊拉克第納爾|
|IRR|伊朗里亞爾|
|ISK|冰島克朗|
|JMD|牙買加元|
|JOD|約旦第納爾|
|JPY|日圓|
|KES|肯尼亞先令|
|KGS|吉爾吉斯斯坦索姆|
|KHR|柬埔寨瑞爾|
|KMF|科摩羅法郎|
|KPW|朝鮮圓|
|KRW|韓圓|
|KWD|科威特第納爾|
|KYD|開曼群島元|
|KZT|哈薩克斯坦堅戈|
|LAK|老撾基普|
|LBP|黎巴嫩鎊|
|LKR|斯里蘭卡盧比|
|LRD|利比里亞元|
|LSL|萊索托洛蒂|
|LYD|利比亞第納爾|
|MAD|摩洛哥迪爾汗|
|MDL|摩爾多瓦列伊|
|MGA|馬達加斯加阿里亞里|
|MKD|馬其頓代納爾|
|MMK|緬元|
|MNT|蒙古圖格里克|
|MOP|澳門幣|
|MUR|毛里求斯盧比|
|MVR|馬爾代夫拉菲亞|
|MWK|馬拉維克瓦查|
|MXN|墨西哥比索|
|MYR|馬來西亞令吉|
|MZN|莫桑比克梅蒂卡爾|
|NAD|納米比亞元|
|NGN|尼日利亞奈拉|
|NIO|尼加拉瓜科多巴|
|NOK|挪威克朗|
|NPR|尼泊爾盧比|
|NTD|新台幣|
|NZD|新西蘭元|
|OMR|阿曼里亞爾|
|PAB|巴拿馬巴波亞|
|PEN|秘魯索爾|
|PGK|巴布亞新幾內亞基那|
|PHP|菲律賓比索|
|PKR|巴基斯坦盧比|
|PLN|波蘭茲羅提|
|PYG|巴拉圭瓜拉尼|
|QAR|卡塔爾里亞爾|
|RSD|塞爾維亞第納爾|
|RUB|俄羅斯盧布|
|RWF|盧旺達法郎|
|SAR|沙特里亞爾|
|SBD|所羅門群島元|
|SCR|塞舌爾盧比|
|SDG|蘇丹鎊|
|SEK|瑞典克朗|
|SGD|新加坡元|
|SHP|聖赫勒拿鎊|
|SLL|塞拉利昂利昂|
|SOS|索馬里先令|
|SRD|蘇里南元|
|SSP|南蘇丹鎊|
|SYP|敘利亞鎊|
|SZL|斯威士蘭裡蘭吉尼|
|THB|泰銖|
|TJS|塔吉克斯坦索莫尼|
|TMT|土庫曼斯坦馬納特|
|TND|突尼斯第納爾|
|TOP|湯加潘加|
|TRY|土耳其里拉|
|TTD|特立尼達和多巴哥元|
|TZS|坦桑尼亞先令|
|UAH|烏克蘭格里夫納|
|UGX|烏干達先令|
|USD|美元|
|UYU|烏拉圭比索|
|UZS|烏茲別克斯坦索姆|
|VND|越南盾|
|VUV|瓦努阿圖瓦圖|
|WST|薩摩亞塔拉|
|XAF|中非法郎|
|XCD|東加勒比元|
|XOF|西非法郎|
|XPF|太平洋法郎（franc Pacifique）|
|YER|也門裡亞爾|
|ZAR|南非蘭特|
|ZMW|贊比亞克瓦查|

### IFTP 附錄 2：費用項明細（陸運部分）

| 費用項 | 費用名稱 | 運輸模式 |
| --- | --- | --- |
|HL001|陸運費|陸運|
|HL002|追加陸運費|陸運|
|HL003|報關費|陸運|
|HL004|轉關費|陸運|
|HL005|查驗費|陸運|
|HL006|提貨費|陸運|
|HL007|異提費|陸運|
|HL008|提空費|陸運|
|HL009|壓車費|陸運|
|HL010|派送費|陸運|
|HL011|操作類|陸運|
|HL012|拖車費|陸運|
|HL013|其他費用|陸運|
|HL014|保險費|陸運|
|HL015|中國代理費|陸運|
|HL016|國外代理費|陸運|
|HL017|貨場過夜費|陸運|
|HL018|貨場超時費|陸運|
|HL019|單據費|陸運|
|HL020|代駕費|陸運|
|HL021|改單費|陸運|
|HL022|操作費|陸運|
|HL023|過磅費|陸運|
|HL024|保價費|陸運|
|HL025|集港費|陸運|
|HL027|目的清關費|陸運|
|DPF|數據處理費|陸運|
|ISPSR|港口安全設施費|陸運|
|CHC|集裝箱操作費|陸運|
|FWDF|代理費|陸運|
|RFF|陸運費|陸運|
|ELF|買單費|陸運|
|CDRF|報關續頁費|陸運|
|FPA|保險費|陸運|
|BDF|打包打板費|陸運|
|DHFR|操作服務費|陸運|
|RCDF|錄單費|陸運|
|LABF|標籤費|陸運|
|CSC|提箱費|陸運|
|PCFR|集港費|陸運|
|DCF|落箱吊櫃費|陸運|
|MHC|機械裝卸費|陸運|
|WHSF|入倉費|陸運|
|CAF|證書代辦費|陸運|
|COML|賠償費|陸運|
|INVF|開票手續費|陸運|
|MANP|人力費|陸運|
|LTF|本地拖車費提貨費|陸運|
|OHF|始發地場站操作費|陸運|
|ASC|安檢費|陸運|
|OCDC|起運地報關費|陸運|
|OEC|起運地雜費|陸運|
|DEC|目的地雜費|陸運|
|THC|碼頭操作費|陸運|
|WBF|提單費|陸運|
|DHF|目的地操作費|陸運|
|DOCF|文件費|陸運|
|DCCC|目的地清關費|陸運|
|BROKF|報檢費|陸運|
|PSSL|旺季附加費|陸運|
|AMSL|申報費|陸運|
|SEALL|封箱費|陸運|
|STORL|倉儲費|陸運|
|LAUF|裝卸費|陸運|
|DTF|目的地拖車費|陸運|
|DELF|派送費|陸運|
|DUT|關稅增值稅|陸運|
|DTAHC|代墊稅金操作費|陸運|
|DTC|超期堆存費|陸運|
|CIC|查驗費|陸運|
|BAC|改單費|陸運|
|WAIT|等候費|陸運|
|OVN|押夜費|陸運|
|SCA|稱重費|陸運|
|ADDR|異地提貨提櫃費|陸運|
|OVWL|超重費|陸運|
|EMP|返空|陸運|
|TOLL|過路費|陸運|
|PTC|場站費|陸運|
|ORHC|啟運港碼頭費|陸運|
|MST|艙單費|陸運|
|BKFL|訂艙費|陸運|
|TELE|電放費|陸運|
|SPPL|保價費|陸運|
|DVF|代駕費|陸運|
|陸運|放空費|陸運|
|COOF|產地證費用|陸運|
|ECF|換單費|陸運|
|DDC|目的港手續費|陸運|
|GASF|地面服務費|陸運|
|WOF|倉儲超期費|陸運|
|OWC|超重費|陸運|
|CBF|吊櫃費|陸運|
|WTF|超時費|陸運|
|PDF|過磅費|陸運|
|OTF|加班費|陸運|
|FZC|製冷費|陸運|
|AMC|改單費|陸運|
|CNDTF_Truck|境內運輸費 (CN)|陸運|
|CNECDF_TRUCK|出口報關費 (CN)|陸運|
|CNDTF_TRUCK|境內運輸費 (CN)|陸運|
|CNECDF_TRUCK|出口報關費 (CN)|陸運|
|FZC|製冷費|陸運|
|OTF|加班費|陸運|
|PDF|過磅費|陸運|
|WTF|超時費|陸運|
|CBF|吊櫃費|陸運|
|GASF|地面服務費|陸運|
|DDC|目的港手續費|陸運|
|COOF|產地證費用|陸運|
|EPC|放空費|陸運|
|DVF|代駕費|陸運|
|ORHC|啟運港碼頭費|陸運|
|ADDR|異地提貨提櫃費|陸運|
|SCA|稱重費|陸運|
|OVN|押夜費|陸運|
|WAIT|等候費|陸運|
|DTC|超期堆存費|陸運|
|LAUF|裝卸費|陸運|
|AMSL|申報費|陸運|
|ASC|安檢費|陸運|
|MANP|人力費|陸運|
|INVF|開票手續費|陸運|
|COML|賠償費|陸運|
|CAF|證書代辦費|陸運|
|WHSF|入倉費|陸運|
|MHC|機械裝卸費|陸運|
|DCF|落箱吊櫃費|陸運|
|PCFR|集港費|陸運|
|CSC|提箱費|陸運|
|LABF|標籤費|陸運|
|RCDF|錄單費|陸運|
|DHFR|操作服務費|陸運|
|BDF|打包打板費|陸運|
|CDRF|報關續頁費|陸運|
|ELF|買單費|陸運|
|RFF|陸運費|陸運|
|FWDF|代理費|陸運|
|CHC|集裝箱操作費|陸運|
|ISPSR|港口安全設施費|陸運|
|DPF|數據處理費|陸運|
|OWC|超重費|陸運|
|WOF|倉儲超期費|陸運|
|ECF|換單費|陸運|
|TELE|電放費|陸運|
|BKFL|訂艙費|陸運|
|MST|艙單費|陸運|
|TOLL|過路費|陸運|
|EMP|返空|陸運|
|STORL|倉儲費|陸運|
|SEALL|封箱費|陸運|
|PSSL|旺季附加費|陸運|
|WBF|提單費|陸運|
|THC|碼頭操作費|陸運|
|ORSC|起運地場站費用|陸運|
|DRSC|目的地場站費用|陸運|
|BLAC|改單費|陸運|
|OPUP|提貨費|陸運|
|JWF|聯單費|陸運|
|PF|停車費|陸運|
|HTF|高速費|陸運|
|TYF|碼頭堆場費|陸運|
|EYRF|外堆場預約費|陸運|
|CDFP|壓車費|陸運|
|OCUF|超期用箱費|陸運|
|CDF|落箱費|陸運|
|PEF|進港費|陸運|
|APF|預提費|陸運|
|CD|關稅|陸運|
|JWF|聯單費|陸運|
|PF|停車費|陸運|
|HTF|高速費|陸運|
|TYF|碼頭堆場費|陸運|
|EYRF|外堆場預約費|陸運|
|CDFP|壓車費|陸運|
|OCUF|超期用箱費|陸運|
|CDF|落箱費|陸運|
|PEF|進港費|陸運|
|APF|預提費|陸運|
|CD|關稅|陸運|
|GZ CN|境內港雜費（CN/OS）|陸運|
