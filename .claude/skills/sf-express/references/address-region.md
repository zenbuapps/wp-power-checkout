# 國際區劃接口：國家 / 州省 / 城市 / 郵編 / 電話校驗

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：要把台灣（或對方國家）地址轉成 SF 需要的 `regionFirst` / `regionSecond` / `postCode`、驗證郵編格式與可達 / 偏遠、驗證電話號碼時。所有區劃接口都走同一個 dispatch 入口與加密流程。

## 目錄

- [1. 查詢國家列表 — `ICMS_QUERY_COUNTRY_LIST`](#1-查詢國家列表--icms_query_country_list)
- [2. 查詢州省列表 — `ICMS_QUERY_PROVINCE_LIST`](#2-查詢州省列表--icms_query_province_list)
- [3. 查詢城市列表 — `ICMS_QUERY_CITY_LIST`](#3-查詢城市列表--icms_query_city_list)
- [4. 查詢郵編屬性 — `ICMS_QUERY_POST_CODE_ATTRIBUTE`](#4-查詢郵編屬性--icms_query_post_code_attribute)
- [5. 根據國際區劃查詢郵編 — `ICMS_QUERY_POST_CODE_BY_REGION`](#5-根據國際區劃查詢郵編--icms_query_post_code_by_region)
- [6. 根據郵編查詢國際區劃 — `ICMS_QUERY_REGION_BY_POST_CODE`](#6-根據郵編查詢國際區劃--icms_query_region_by_post_code)
- [7. 查詢郵編詳細信息 — `ICMS_QUERY_POST_CODE_INFO`](#7-查詢郵編詳細信息--icms_query_post_code_info)
- [8. 郵編模糊查詢 — `ICMS_QUERY_POST_CODE_VAGUE`](#8-郵編模糊查詢--icms_query_post_code_vague)
- [9. 國際區劃級聯查詢 — `ICMS_REGION_CASCADE_QUERY`](#9-國際區劃級聯查詢--icms_region_cascade_query)
- [10. 國際區劃模糊查詢 — `ICMS_REGION_VAGUE_QUERY`](#10-國際區劃模糊查詢--icms_region_vague_query)
- [11. 查詢大洲列表 — `ICMS_GET_CONTINENT_LIST`](#11-查詢大洲列表--icms_get_continent_list)
- [12. 國際電話有效性校驗 — `ICMS_PHONE_VALID_CHECK`](#12-國際電話有效性校驗--icms_phone_valid_check)
- [補充：台灣（TW）沙箱實測資料（2026-09-23，唯讀查詢）](#補充台灣tw沙箱實測資料2026-09-23唯讀查詢)

---

## 1. 查詢國家列表 — `ICMS_QUERY_COUNTRY_LIST`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=149（文檔中心 > API文檔 > 國際區劃 > 查詢國家列表；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
查詢國家列表
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_COUNTRY_LIST`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | continentCode | String | 否 | 大洲代碼 |
| 2 | countryCode | String | 否 | 國家代碼 |
| 3 | phoneCode | String | 否 | 手機區號 |

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
| 1 | continentCode | String | 所屬大洲 |
| 2 | countryCode | String | 國家二字碼 |
| 3 | countryName | String | 國家英文名稱 |
| 4 | countryNameI18n | String | 國家名稱-指定語言 |
| 5 | createBy | String | 創建者帳號 |
| 6 | currencyCode | String | 幣種代碼 |
| 7 | customsCode | String | 中國海關對國家/地區的編碼 |
| 8 | gmtCreate | String | 創建時間 |
| 9 | gmtModified | String | 更新時間 |
| 10 | id | Long | ID |
| 11 | modifiedBy | String | 更新者帳號 |
| 12 | numberCode | String | 國家數字碼 |
| 13 | phoneCode | String | 國際電話區號 |
| 14 | sfCode | String | 大網定義的ID（基本被棄用） |
| 15 | status | Integer | 狀態(0禁用，1啟用) |
| 13 | threeCode | String | 國家三字碼 |
| 17 | timeZone | String | 默認時區，如果一個國家跨多個時區，則通常為首都所在時區 |
| 18 | weightUnit | String | 重量單位 |

#### 明文請求示例

```
{
    "continentCode":"AS",
    "countryCode":"CN",
    "phoneCode":"86"
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "continentCode": "AS",
            "countryCode": "CN",
            "countryName": "China",
            "countryNameI18n": "中国内地",
            "createBy": "01109846",
            "currencyCode": "CNY",
            "customsCode": "142",
            "gmtCreate": "2019-12-25T06:17:05.000+00:00",
            "gmtModified": "2021-05-20T03:20:23.000+00:00",
            "id": 990,
            "lang": "zh-CN",
            "modifiedBy": "01109846",
            "numberCode": "156",
            "phoneCode": "86",
            "sfCode": "",
            "status": 1,
            "threeCode": "CHN",
            "timeZone": "Asia/Shanghai",
            "weightUnit": "KG"
        }
    ],
    "success": true
}
```

---

## 2. 查詢州省列表 — `ICMS_QUERY_PROVINCE_LIST`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=154（文檔中心 > API文檔 > 國際區劃 > 查詢州省列表；官方更新 2023-09-12；爬取 2026-09-23）

### 功能描述

```
查詢州省列表
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_PROVINCE_LIST`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼 |
| 2 | regionFirst | String | 否 | 地址一級區劃名稱（州/省） |

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
| 1 | continentCode | String | 所屬大洲 |
| 2 | countryCode | String | 國家代碼 |
| 3 | createBy | String | 創建者帳號 |
| 4 | currencyCode | String | 幣種代碼 |
| 5 | gmtCreate | String | 創建時間 |
| 6 | gmtModified | String | 更新時間 |
| 7 | id | long | ID |
| 8 | modifiedBy | String | 更新者帳號 |
| 9 | phoneCode | String | 國際電話區號 |
| 10 | regionFirstAbbr | String | 一級區劃簡稱 |
| 11 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 12 | regionFirstI18n | String | 地址一級區劃名稱（州/省）-國際化 |
| 13 | sfCode | String | 大網定義的ID（基本被棄用） |
| 14 | status | Integer | 狀態(0禁用，1啟用) |
| 15 | timeZone | String | 默認時區，如果一個國家跨多個時區，則通常為首都所在時區 |
| 16 | weightUnit | String | 重量單位 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "regionFirst":"guangdong"
}
```

#### 明文響應示例

```
{
        "data": [
            {
                "gmtModified": "2021-06-24T11:52:48.000+00:00",
                "createBy": "system",
                "regionFirst": "Guangdong",
                "regionFirstI18n": "Guangdong",
                "countryCode": "CN",
                "modifiedBy": "system",
                "id": 3323,
                "gmtCreate": "2021-06-24T11:52:48.000+00:00",
                "continentCode": "AS",
                "status": 1
            }
        ],
        "success": true
 }
```

---

## 3. 查詢城市列表 — `ICMS_QUERY_CITY_LIST`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=148（文檔中心 > API文檔 > 國際區劃 > 查詢城市列表；官方更新 2023-09-12；爬取 2026-09-23）

### 功能描述

```
查詢城市列表
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_CITY_LIST`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼 |
| 2 | regionFirst | String | 否 | 地址一級區劃名稱（州/省） |
| 3 | regionSecond | String | 否 | 地址二級區劃名稱（城市） |

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
| 1 | regionSecondAbbr | String | 地址 二級區劃簡稱(城市) |
| 2 | regionSecond | String | 地址二級區劃名稱(城市) |
| 3 | regionSecondI18n | String | 地址二級區劃名稱國際化(城市) |
| 4 | continentCode | String | 所屬大洲 |
| 5 | countryCode | String | 國家編碼 |
| 6 | createBy | String | 創建者帳號 |
| 7 | currencyCode | String | 幣種代碼 |
| 8 | gmtCreate | String | 創建時間 |
| 9 | gmtModified | String | 更新時間 |
| 10 | id | Long | ID |
| 11 | modifiedBy | String | 更新者帳號 |
| 12 | phoneCode | String | 國際電話區號 |
| 13 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 14 | sfCode | String | 大網定義的ID（基本被棄用） |
| 15 | status | Integer | 狀態(0禁用，1啟用) |
| 16 | timeZone | String | 默認時區，如果一個國家跨多個時區，則通常為首都所在時區 |
| 17 | weightUnit | String | 重量單位 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "regionFirst":"guangdong",
    "regionSecond":"Chaozhou"
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "continentCode": "AS",
            "countryCode": "CN",
            "createBy": "01405048",
            "gmtCreate": "2022-04-07T02:35:48.000+00:00",
            "gmtModified": "2022-11-03T02:20:49.000+00:00",
            "id": 233696,
            "modifiedBy": "01405048",
            "regionFirst": "Guangdong",
            "regionSecond": "Chaozhou",
            "regionSecondI18n": "Chaozhou",
            "status": 1
        }
    ],
    "success": true
}
```

---

## 4. 查詢郵編屬性 — `ICMS_QUERY_POST_CODE_ATTRIBUTE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=150（文檔中心 > API文檔 > 國際區劃 > 查詢郵編屬性；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
查詢郵編屬性
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_POST_CODE_ATTRIBUTE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 否 | 國家代碼 |

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
| 1 | basicBillingAreaCode | String | 兜底計費代碼 |
| 2 | basicCityCode | String | 兜底城市代碼 |
| 3 | basicPortCode | String | 兜底口岸代碼 |
| 4 | billingAreaCode | String | 計費代碼 |
| 5 | cityCode | String | 城市代碼 |
| 6 | countryCode | String | 國家代碼 |
| 7 | fillPlan | int | 填寫方案(1-通過郵編查詢區劃、2-通過區劃查詢郵編、3-相互查詢) |
| 8 | portCode | String | 口岸代碼 |
| 9 | postCodeCount | int | 郵編數量 |
| 10 | postCodeDigit | string | 郵編位數，部分國家郵編有多個位數，用英文逗號分隔。如『5,6』表示郵編有5位的，也有6位的。 |
| 11 | postCodeErrorDesc | String | 郵編錯誤說明 |
| 12 | postCodeRegular | String | 郵編規則正則 |
| 13 | regionLevel | String | 區劃級別 |

#### 明文請求示例

```
{
    "countryCode":"CN"
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "basicBillingAreaCode": "010",
            "basicCityCode": "010",
            "basicPortCode": "010",
            "billingAreaCode": "",
            "cityCode": "",
            "countryCode": "CN",
            "fillPlan": 3,
            "portCode": "",
            "postCodeCount": 0,
            "postCodeDigit": "",
            "postCodeErrorDesc": "邮编格式错误，请输入6位数字，请勿输入空格等符号",
            "postCodeRegular": "^[0-9]{6}$",
            "regionLevel": ""
        }
    ],
    "success": true
}
```

---

## 5. 根據國際區劃查詢郵編 — `ICMS_QUERY_POST_CODE_BY_REGION`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=151（文檔中心 > API文檔 > 國際區劃 > 根據國際區劃查詢郵編；官方更新 2023-09-12；爬取 2026-09-23）

### 功能描述

```
根據國際區劃查詢郵編
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_POST_CODE_BY_REGION`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼 |
| 2 | regionFirst | String  | 是 | 地址一級區劃名稱（州/省） |
| 3 | regionSecond | String  | 是 | 地址二級區劃名稱（城市） |
| 4 | regionThird | String | 否 | 三級區劃名稱（區/縣）（如果為空（查詢方式為1）會進行空值查詢） |
| 5 | regionFourth | String | 否 | 四級區劃名稱（街道）（如果為空（查詢方式為1）會進行空值查詢） |
| 6 | queryMode | String | 否 | 查詢方式(0：返回行政區劃下第一個郵編信息；1：返回行政區劃下不超過100條郵編信息；默認為0) |

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
| 1 | billingAreaCode | String | 計費區劃代碼 |
| 2 | cityCode | String | 城市代碼 |
| 3 | countryCode | String | 國家編碼 |
| 4 | isAccessible | String | 是否可達(0：不可達，1：可達) |
| 5 | isRemote | String | 是否偏遠(0：非偏遠，1：偏遠) |
| 6 | portCode | String | 口岸代碼 |
| 7 | postCode | String | 郵編 |
| 8 | regionFirst | String | 一級區劃名稱 |
| 9 | regionFirstCn | String | 一級區劃中文名稱 |
| 10 | regionSecond | String | 二級區劃名稱 |
| 11 | regionSecondCn | String | 二級區劃中文名稱 |
| 12 | regionThird | String | 三級區劃名稱 |
| 13 | regionThirdCn | String | 三級區劃中文名稱 |
| 14 | regionFourth | String | 四級區劃名稱 |
| 15 | regionFourthCn | Integer | 四級區劃中文名稱 |
| 16 | regionFirstAbbr | String | 一級區劃簡稱 |
| 17 | regionSecondAbbr | String | 二級區劃簡稱 |
| 18 | regionThirdAbbr | String | 三級區劃簡稱 |
| 19 | regionFourthAbbr | String | 四級區劃簡稱 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "regionFirst":"guangdong",
    "regionSecond":"shenzhen",
    "regionThird":"futian",
    "regionFourth":"",
    "queryMode":0
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "billingAreaCode": "755",
            "cityCode": "755",
            "countryCode": "CN",
            "portCode": "755",
            "postCode": "518000",
            "regionFirst": "Guangdong",
            "regionSecond": "Shenzhen",
            "regionThird": "Futian"
        }
    ],
    "success": true
}
```

---

## 6. 根據郵編查詢國際區劃 — `ICMS_QUERY_REGION_BY_POST_CODE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=155（文檔中心 > API文檔 > 國際區劃 > 根據郵編查詢國際區劃；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
根據郵編查詢國際區劃
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_REGION_BY_POST_CODE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼 |
| 2 | postCode | String | 是 | 郵編 |
| 3 | queryMode | String | 否 | 查詢方式(0：返回郵編對應的第一個行政區劃,1：返回郵編對應的所有行政區劃,默認為0) |

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
| 1 | billingAreaCode | String | 計費區劃代碼 |
| 2 | cityCode | String | 城市代碼 |
| 3 | countryCode | String | 國家編碼 |
| 4 | isAccessible | String | 是否可達(0：不可達，1：可達) |
| 5 | isRemote | String | 是否偏遠(0：非偏遠，1：偏遠) |
| 6 | portCode | String | 口岸代碼 |
| 7 | postCode | String | 郵編 |
| 8 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 9 | regionFirstCn | String | 地址一級區劃中文名稱（州/省） |
| 10 | regionSecond | String | 地址二級區劃名稱（城市） |
| 11 | regionSecondCn | String | 地址二級區劃中文名稱（城市） |
| 12 | regionThird | String | 地址三級區劃名稱（區/縣） |
| 13 | regionThirdCn | String | 地址三級區劃中文名稱（區/縣） |
| 14 | regionFourth | String | 地址四級區劃名稱（街道） |
| 15 | regionFourthCn | Integer | 地址四級區劃中文名稱（街道） |
| 16 | regionFirstAbbr | String | 地址一級區劃簡稱 |
| 17 | regionSecondAbbr | String | 地址二級區劃簡稱 |
| 18 | regionThirdAbbr | String | 地址三級區劃簡稱 |
| 19 | regionFourthAbbr | String | 地址四級區劃簡稱 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "postCode":"524034",
    "queryMode":1
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "billingAreaCode": "759",
            "cityCode": "759",
            "countryCode": "CN",
            "isAccessible": "1",
            "isRemote": "0",
            "portCode": "759",
            "postCode": "524034",
            "regionFirst": "Guangdong",
            "regionSecond": "Zhanjiang",
            "regionThird": "Chikan"
        },
        {
            "billingAreaCode": "759",
            "cityCode": "759",
            "countryCode": "CN",
            "isAccessible": "1",
            "isRemote": "0",
            "portCode": "759",
            "postCode": "524034",
            "regionFirst": "Guangdong",
            "regionSecond": "Zhanjiang",
            "regionThird": "Xiashan"
        }
    ],
    "success": true
}
```

---

## 7. 查詢郵編詳細信息 — `ICMS_QUERY_POST_CODE_INFO`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=152（文檔中心 > API文檔 > 國際區劃 > 查詢郵編詳細信息；官方更新 2024-07-15；爬取 2026-09-23）

### 功能描述

```
查詢郵編詳細信息
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_POST_CODE_INFO`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼，國家代碼和國家名稱至少傳一個，國家代碼和國家名稱同時存在，以國家代碼為準 |
| 2 | countryName | String | 否 | 國家名稱 |
| 3 | regionFirst | String | 否 | 地址一級區劃名稱（州/省） |
| 4 | regionSecond | String | 否 | 地址二級區劃名稱（城市） |
| 5 | regionThird | String | 否 | 地址三級區劃名稱（區/縣） |
| 6 | regionFourth | String | 否 | 地址四級區劃名稱（街道） |
| 7 | postCode | String | 是 | 郵編 |
| 8 | queryMode | String | 是 | 查詢方式(1：精確匹配,2：模糊匹配,3：兜底查詢) |

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
| 1 | actFlag | int | 動作標識(0-刪除，1-新增，2-更新) |
| 2 | addWorkday | String | 收派時效預計延後時長（工作日） |
| 3 | address1 | String | 地址1 |
| 4 | address2 | String | 地址2 |
| 5 | address3 | String | 地址3 |
| 6 | billingAreaCode | String | 計費區劃代碼 |
| 7 | cityCode | String | 城市代碼 |
| 8 | continent | String | 所屬大洲 |
| 9 | countryCode | String | 國家編碼 |
| 10 | countryName | String | 國家名稱 |
| 11 | coverType | String | 收派範圍，參考附錄15（見 `dictionaries.md`） |
| 12 | createBy | String | 創建者帳號 |
| 13 | deliveryWorkday | String | 派送工作日 |
| 14 | elevation | String | 海拔 |
| 15 | gmtCreate | String | 創建時間 |
| 16 | gmtModified | String | 更新時間 |
| 17 | houseNumberBegin | String | 起始門牌 |
| 18 | houseNumberEnd | String | 結束門牌 |
| 19 | id | long | ID |
| 20 | latitude | String | 緯度 |
| 21 | longitude | String | 經度 |
| 22 | modifiedBy | String | 更新者帳號 |
| 23 | portCode | String | 口岸代碼 |
| 24 | postBegin | String | 起始郵編 |
| 25 | postEnd | String | 結束郵編 |
| 26 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 27 | regionFirstLocal | String | 地址一級區劃名稱（州/省）-當地語言 |
| 28 | regionSecond | String | 地址二級區劃名稱（城市） |
| 29 | regionSecondLocal | String | 地址二級區劃名稱（城市）-當地語言 |
| 30 | regionThird | String | 三級區劃名稱（區/縣） |
| 31 | regionThirdLocal | String | 三級區劃名稱（區/縣）-當地語言 |
| 32 | regionFourth | String | 四級區劃名稱（街道） |
| 33 | regionFourthLocal | String | 四級區劃名稱（街道）-當地語言 |
| 34 | regionFirstAbbr | String | 一級區劃簡稱 |
| 35 | regionSecondAbbr | String | 二級區劃簡稱 |
| 36 | regionThirdAbbr | String | 三級區劃簡稱 |
| 37 | regionFourthAbbr | String | 四級區劃簡稱 |
| 38 | remoteAreaCharge | String | 是否偏遠(0-非偏遠，1-偏遠) |
| 39 | serviceProvider | String | 派送方 |
| 40 | status | int | 狀態(0禁用，1啟用) |
| 41 | timeZone | String | 時區 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "regionFirst":"",
    "regionSecond":"",
    "regionThird":"",
    "postCode":"524034",
    "queryMode":1
}
```

#### 明文響應示例

```
{
    "apiResultCode": 0,
    "apiErrorMsg": null,
    "apiResultData": {
        "data": [
            {
                "continent": "AS",
                "regionSecondLocal": "湛江市",
                "gmtModified": "2021-01-23T14:53:17.000+00:00",
                "regionThirdLocal": "赤坎区",
                "cityCode": "759",
                "coverType": "ALL",
                "deliveryWorkday": "0",
                "regionFirst": "Guangdong",
                "countryCode": "CN",
                "regionFirstLocal": "广东省",
                "modifiedBy": "system",
                "id": 127391,
                "regionThird": "Chikan",
                "billingAreaCode": "759",
                "timeZone": "Asia/Shanghai,+08:00,CST",
                "postBegin": "524034",
                "remoteAreaCharge": "0",
                "gmtCreate": "2021-01-23T13:34:29.000+00:00",
                "portCode": "759",
                "regionSecond": "Zhanjiang",
                "addWorkday": "0",
                "createBy": "system",
                "postEnd": "524034",
                "serviceProvider": "759，五邑区(Wuyi BusinessArea)，华南(South China)",
                "countryName": "China",
                "status": 1
            },
            {
                "continent": "AS",
                "regionSecondLocal": "湛江市",
                "gmtModified": "2021-01-23T14:53:17.000+00:00",
                "regionThirdLocal": "霞山区",
                "cityCode": "759",
                "coverType": "ALL",
                "deliveryWorkday": "0",
                "regionFirst": "Guangdong",
                "countryCode": "CN",
                "regionFirstLocal": "广东省",
                "modifiedBy": "system",
                "id": 128331,
                "regionThird": "Xiashan",
                "billingAreaCode": "759",
                "timeZone": "Asia/Shanghai,+08:00,CST",
                "postBegin": "524034",
                "remoteAreaCharge": "0",
                "gmtCreate": "2021-01-23T13:34:32.000+00:00",
                "portCode": "759",
                "regionSecond": "Zhanjiang",
                "addWorkday": "0",
                "createBy": "system",
                "postEnd": "524034",
                "serviceProvider": "759，五邑区(Wuyi BusinessArea)，华南(South China)",
                "countryName": "China",
                "status": 1
            }
        ],
        "success": true
    },
    "apiTimestamp": 1669282651592
}
```

---

## 8. 郵編模糊查詢 — `ICMS_QUERY_POST_CODE_VAGUE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=153（文檔中心 > API文檔 > 國際區劃 > 郵編模糊查詢；官方更新 2023-09-12；爬取 2026-09-23）

### 功能描述

```
郵編模糊查詢
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_QUERY_POST_CODE_VAGUE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 否 | 國家代碼，國家代碼和國家名稱至少傳一個，國家代碼和國家名稱同時存在，以國家代碼為準 |
| 2 | countryName | String | 否 | 國家名稱 |
| 7 | postCode | String | 是 | 郵編 |

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
| 1 | actFlag | int | 動作標識(0-刪除，1-新增，2-更新) |
| 2 | addWorkday | String | 收派時效預計延後時長（工作日） |
| 3 | address1 | String | 地址1 |
| 4 | address2 | String | 地址2 |
| 5 | address3 | String | 地址3 |
| 6 | billingAreaCode | String | 計費區劃代碼 |
| 7 | cityCode | String | 城市代碼 |
| 8 | continent | String | 所屬大洲 |
| 9 | countryCode | String | 國家編碼 |
| 10 | countryName | String | 國家名稱 |
| 11 | coverType | String | 收派範圍 |
| 12 | createBy | String | 創建者帳號 |
| 13 | deliveryWorkday | String | 派送工作日 |
| 14 | elevation | String | 海拔 |
| 15 | gmtCreate | String | 創建時間 |
| 16 | gmtModified | String | 更新時間 |
| 17 | houseNumberBegin | String | 起始門牌 |
| 18 | houseNumberEnd | String | 結束門牌 |
| 19 | id | long | ID |
| 20 | latitude | String | 緯度 |
| 21 | longitude | String | 經度 |
| 22 | modifiedBy | String | 更新者帳號 |
| 23 | portCode | String | 口岸代碼 |
| 24 | postCode | String | 郵編 |
| 25 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 26 | regionFirstLocal | String | 地址一級區劃名稱（州/省）-當地語言 |
| 27 | regionFirstCn | String | 地址一級區劃名稱（州/省）-中文 |
| 28 | regionSecond | String | 地址二級區劃名稱（城市） |
| 29 | regionSecondLocal | String | 地址二級區劃名稱（城市）-當地語言 |
| 30 | regionSecondCn | String | 地址二級區劃名稱（城市）-中文 |
| 31 | regionThird | String | 三級區劃名稱（區/縣） |
| 32 | regionThirdLocal | String | 三級區劃名稱（區/縣）-當地語言 |
| 33 | regionThirdCn | String | 三級區劃名稱（區/縣）-中文 |
| 34 | regionFourth | String | 四級區劃名稱（街道） |
| 35 | regionFourthLocal | String | 四級區劃名稱（街道）-當地語言 |
| 36 | regionFourthCn | String | 四級區劃名稱（街道）-中文 |
| 37 | regionFirstAbbr | String | 一級區劃簡稱 |
| 38 | regionSecondAbbr | String | 二級區劃簡稱 |
| 39 | regionThirdAbbr | String | 三級區劃簡稱 |
| 40 | regionFourthAbbr | String | 四級區劃簡稱 |
| 41 | remoteAreaCharge | String | 是否偏遠(0-非偏遠，1-偏遠) |
| 42 | serviceProvider | String | 派送方 |
| 43 | status | int | 狀態(0禁用，1啟用) |
| 44 | timeZone | String | 時區 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "countryName":"CN",
    "regionFirst":"",
    "regionSecond":"",
    "regionThird":"",
    "regionFourth":"",
    "postCode":"524034"
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "addWorkday": "0",
            "billingAreaCode": "759",
            "cityCode": "759",
            "continent": "AS",
            "countryCode": "CN",
            "countryName": "China",
            "coverType": "ALL",
            "createBy": "system",
            "deliveryWorkday": "0",
            "gmtCreate": "2021-01-23T13:34:29.000+00:00",
            "gmtModified": "2021-01-23T14:53:17.000+00:00",
            "id": 127391,
            "modifiedBy": "system",
            "portCode": "759",
            "postCode": "524034",
            "regionFirst": "Guangdong",
            "regionFirstLocal": "广东省",
            "regionSecond": "Zhanjiang",
            "regionSecondLocal": "湛江市",
            "regionThird": "Chikan",
            "regionThirdLocal": "赤坎区",
            "remoteAreaCharge": "0",
            "serviceProvider": "759，五邑区(Wuyi BusinessArea)，华南(South China)",
            "status": 1,
            "timeZone": "Asia/Shanghai,+08:00,CST"
        },
        {
            "addWorkday": "0",
            "billingAreaCode": "759",
            "cityCode": "759",
            "continent": "AS",
            "countryCode": "CN",
            "countryName": "China",
            "coverType": "ALL",
            "createBy": "system",
            "deliveryWorkday": "0",
            "gmtCreate": "2021-01-23T13:34:32.000+00:00",
            "gmtModified": "2021-01-23T14:53:17.000+00:00",
            "id": 128331,
            "modifiedBy": "system",
            "portCode": "759",
            "postCode": "524034",
            "regionFirst": "Guangdong",
            "regionFirstLocal": "广东省",
            "regionSecond": "Zhanjiang",
            "regionSecondLocal": "湛江市",
            "regionThird": "Xiashan",
            "regionThirdLocal": "霞山区",
            "remoteAreaCharge": "0",
            "serviceProvider": "759，五邑区(Wuyi BusinessArea)，华南(South China)",
            "status": 1,
            "timeZone": "Asia/Shanghai,+08:00,CST"
        }
    ],
    "success": true
}
```

---

## 9. 國際區劃級聯查詢 — `ICMS_REGION_CASCADE_QUERY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=156（文檔中心 > API文檔 > 國際區劃 > 國際區劃級聯查詢；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
國際區劃級聯查詢
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_REGION_CASCADE_QUERY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 否 | 國家代碼 |
| 2 | regionFirst | String | 否 | 地址一級區劃名稱（州/省） |
| 3 | regionSecond | String | 否 | 地址二級區劃名稱（城市） |
| 4 | regionThird | String | 否 | 地址三級區劃名稱（區/縣） |
| 5 | keyword | String | 否 | 關鍵詞，可通過關鍵詞查詢匹配的區劃名稱(必須為3個字符以上) |
| 6 | pageNo | String | 否 | 頁碼，正整數，指定需要返回第幾頁的數據，默認1 |
| 7 | pageSize | String | 否 | 每頁數據量，10\~1000之間整數，指定每頁數據量，默認100 |

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
| 1 | level | String | 區劃級別 |
| 2 | regionAbbr | String | 區劃簡稱 |
| 3 | regionName | String | 區劃名稱 |
| 4 | regionNameCn | String | 區劃中文名稱 |
| 5 | regionNameLocal | String | 區劃名稱-當地語言 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "regionFirst":"guangdong",
    "regionSecond":"shenzhen",
    "regionThird":"Futian",
    "keyword":"Futian",
    "pageNo":"1",
    "pageSize":"10"
}
```

#### 明文響應示例

```
{
    "data": [
        {
            "level": "Four",
            "regionName": "Futianbaoshuiqu",
            "regionNameLocal": "福田保税区"
        },
        {
            "level": "Four",
            "regionName": "Futianjiedao",
            "regionNameLocal": "福田街道"
        }
    ],
    "success": true
}
```

---

## 10. 國際區劃模糊查詢 — `ICMS_REGION_VAGUE_QUERY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=157（文檔中心 > API文檔 > 國際區劃 > 國際區劃模糊查詢；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
國際區劃模糊查詢
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_REGION_VAGUE_QUERY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | 是 | 國家代碼 |
| 2 | keyword | String | 是 | 關鍵詞，可通過關鍵詞查詢匹配的區劃名稱(必須為3個字符以上) |
| 3 | pageNo | String | 否 | 頁碼，正整數，指定需要返回第幾頁的數據，默認1 |
| 4 | pageSize | String | 否 | 每頁數據量，10\~1000之間整數，指定每頁數據量，默認100 |

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
| 1 | regionFirst | String | 地址一級區劃名稱（州/省） |
| 2 | regionFirstLocal | String | 地址一級區劃名稱（州/省）-當地語言 |
| 3 | regionFirstCn | String | 地址一級區劃名稱（州/省）-中文 |
| 4 | regionSecond | String | 地址二級區劃名稱（城市） |
| 5 | regionSecondLocal | String | 地址二級區劃名稱（城市）-當地語言 |
| 6 | regionSecondCn | String | 地址二級區劃名稱（城市）-中文 |
| 7 | regionThird | String | 三級區劃名稱（區/縣） |
| 8 | regionThirdLocal | String | 三級區劃名稱（區/縣）-當地語言 |
| 9 | regionThirdCn | String | 三級區劃名稱（區/縣）-中文 |

#### 明文請求示例

```
{
    "countryCode":"CN",
    "keyword":"guangdong"
}
```

#### 明文響應示例

```
{
    "countryCode": "CN",
    "data": [
        {
            "regionFirst": "Guangdong",
            "regionFirstLocal": "广东省"
        }
    ],
    "keyword": "guangdong",
    "pageNo": "1",
    "pageSize": "10",
    "success": true
}
```

---

## 11. 查詢大洲列表 — `ICMS_GET_CONTINENT_LIST`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=147（文檔中心 > API文檔 > 國際區劃 > 查詢大洲列表；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
查詢大洲列表
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_GET_CONTINENT_LIST`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
|  |  |  |  | 無參數，因需保證JSON結構，傳大括號即可：<br>{ } |

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
| 1 | continentCode | String | 大洲代碼 |
| 2 | continentName | String | 大洲名稱 |
| 3 | continentNameI18n | String | 大洲名稱國際化 |

#### 明文請求示例

```
{ }
```

#### 明文響應示例

```
{
    "data": [
        {
            "continentCode": "AS",
            "continentName": "Asia",
            "continentNameI18n": "亚洲"
        },
        {
            "continentCode": "EU",
            "continentName": "Europe",
            "continentNameI18n": "欧洲"
        },
        {
            "continentCode": "AF",
            "continentName": "Africa",
            "continentNameI18n": "非洲"
        },
        {
            "continentCode": "NA",
            "continentName": "North America",
            "continentNameI18n": "北美洲"
        },
        {
            "continentCode": "SA",
            "continentName": "South America",
            "continentNameI18n": "南美洲"
        },
        {
            "continentCode": "OA",
            "continentName": "Oceania",
            "continentNameI18n": "大洋洲"
        },
        {
            "continentCode": "AT",
            "continentName": "Antarctica",
            "continentNameI18n": "南极洲"
        }
    ],
    "success": true
}
```

---

## 12. 國際電話有效性校驗 — `ICMS_PHONE_VALID_CHECK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=205（文檔中心 > API文檔 > 國際區劃 > 國際電話有效性校驗；官方更新 2024-05-08；爬取 2026-09-23）

### 功能描述

```
根據用戶輸入的國家/地區代碼，電話區號，電話號碼，判斷是否符合要求
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICMS_PHONE_VALID_CHECK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | countryCode | String | Y | 原寄地/目的地國家/地區二字碼。如CN、JP等 |
| 2 | phoneCode | String | Y | 國際電話區號，非0開頭的純數字。如+86、-86、#86、0086均不允許，必須使用86 |
| 3 | phoneNumber | String | Y | 電話號碼，純數字，接口可以接收含有符號和空格的數字，但會去掉所有符號和空格，再進行後續判斷。如（123）4567-890會按照1234567890進行判斷 |
| 4 | checkMode | String | N | 校驗方式，默認值為0。0-對比手機號碼&電話號碼規則，任何一個滿足就算通過；1-只對比手機號碼規則；2-只對比電話號碼規則。 |
| 5 | domesticPhoneCode | String | N | 國內電話區號 |
| 6 | extensionNumber | String | N | 分機號 |
| 7 | phoneCodeBelongCountryCode | String | N | 區號歸屬國二字碼。如：在美國用中國電話收件，收件國家/地區代碼是US，但中國國際電話區號是86，區號歸屬中國，代碼是CN。國際電話區號存在重複情況，增加區號歸屬國代碼，可使判斷更準確 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | Y | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | result | String | Y | 驗證結果。ERR:驗證失敗（業務異常）, OK:驗證通過 |

#### 明文請求示例

```
{
    "countryCode": "CN",
    "phoneCode": "86",
    "phoneNumber":"15125637820"
}
```

#### 明文響應示例

```
{
    "apiErrorMsg": "",
    "apiResultCode": 0,
    "apiResultData": {
        "result": "OK",
        "code": 200,
        "success": true
    },
    "apiTimestamp": 1715052034764
}
```

```
```

---

## 補充：台灣（TW）沙箱實測資料（2026-09-23，唯讀查詢）

以下為用官方公開沙箱憑證實際呼叫所得，**非官方文件內容**，正式環境資料可能不同，僅作實作參考。

`ICMS_QUERY_COUNTRY_LIST {"countryCode":"TW"}`：

```json
{"data":[{"threeCode":"TWN","timeZone":"Asia/Taipei","countryNameI18n":"中国台湾","numberCode":"158","countryCode":"TW","phoneCode":"886","countryName":"Taiwan China","id":734,"lang":"zh-TW","currencyCode":"NTD","continentCode":"AS","customsCode":"143","status":1,"weightUnit":"KG"}],"success":true}
```

→ 幣種 **`NTD`**、電話區號 **`886`**、時區 `Asia/Taipei`、中國海關代碼 `143`、重量單位 KG。

`ICMS_QUERY_POST_CODE_ATTRIBUTE {"countryCode":"TW"}`：

```json
{"data":[{"postCodeDigit":"5,6","billingAreaCode":"886,MZ","basicCityCode":"886","fillPlan":3,"cityCode":"886,MZ","portCode":"886,MZ","postCodeRegular":"^[0-9]{5,6}$","regionLevel":"4","basicBillingAreaCode":"886","countryCode":"TW","postCodeCount":18217,"basicPortCode":"886","postCodeErrorDesc":"邮编示例格式12345"}],"success":true}
```

→ **台灣郵編必須 5 或 6 碼數字**（`^[0-9]{5,6}$`，即 3+2 或 3+3 碼郵遞區號）；只給 3 碼會失敗：`ICMS_QUERY_REGION_BY_POST_CODE {"countryCode":"TW","postCode":"100"}` 回 `{"msg":"邮编示例格式12345","success":false}`。計費 / 口岸代碼有 `886` 與 `MZ` 兩區（`MZ` 含義官方未說明）。

`ICMS_QUERY_REGION_BY_POST_CODE {"countryCode":"TW","postCode":"80652","queryMode":"0"}`：

```json
{"data":[{"isAccessible":"1","billingAreaCode":"886","regionFourth":"","regionFirstAbbr":"高雄市","regionFirst":"Kaohsiung City","cityCode":"886","countryCode":"TW","isRemote":"0","postCode":"80652","portCode":"886","regionSecond":"Qianzhen District","regionThird":""}],"success":true}
```

→ 台灣地址對應：`regionFirst` = 縣市英文名、`regionSecond` = 鄉鎮市區英文名、`regionThird` 空。

`ICMS_REGION_CASCADE_QUERY {"countryCode":"TW","pageNo":"1","pageSize":"50"}` 回傳的一級區劃（共 18 筆）：

| regionName | regionNameLocal |
|---|---|
| Changhua County | 彰化縣 |
| Chiayi City | 嘉義市 |
| Chiayi County | 嘉義縣 |
| Hsinchu City | 新竹市 |
| Hsinchu County | 新竹縣 |
| Hualien County | 花蓮縣 |
| Kaohsiung City | 高雄市 |
| Keelung City | 基隆市 |
| Miaoli County | 苗栗縣 |
| Nantou County | 南投縣 |
| New Taipei City | 新北市 |
| Pingtung County | 屏東縣 |
| Taichung City | 台中市 |
| Tainan City | 台南市 |
| Taipei City | 台北市 |
| Taitung County | 台東縣 |
| Taoyuan City | 桃園市 |
| Yilan County | 宜蘭縣 |

⚠️ 沙箱回傳**沒有**澎湖縣、金門縣、連江縣、雲林縣。離島 / 雲林是否可收派、regionFirst 應填什麼，官方未說明，`[待確認]`——請以 `ICMS_QUERY_REGION_BY_POST_CODE` 用實際郵編查詢（正式環境）為準。

`ICMS_PHONE_VALID_CHECK {"countryCode":"TW","phoneCode":"886","phoneNumber":"0912345678"}` → `{"result":"OK","code":"200","success":true}`（手機號含開頭 0 可通過）。

`ICMS_QUERY_PROVINCE_LIST` / `ICMS_QUERY_CITY_LIST`（countryCode=TW）沙箱當次回 504 或空回應，未取得資料。
