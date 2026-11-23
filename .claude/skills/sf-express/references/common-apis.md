# 國際件「通用」接口：產品 / 時效 / 運費推薦、收寄標準、計費重、POD、稅金支付、代理商

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：要做「可寄哪些產品 + 運費 + 時效」試算（`IPE_QUERY_PRODUCT_LIST`、`IPE_QUERY_PRODUCT_FREIGHT`、`IPE_ESTIMATE_FEE`、`IPT_QUERY_PROMITM`）、品名能不能寄（`SOP_COLLECT_SHIP_STANDARD`）、事後對帳（`IOMS_QUERY_CHARGE` 計費重與費用項、`IOMS_WAYBILL_BILL`）、下載派送證明 POD（`IOMS_SEND_PROVE_ONEFILE` / `IOMS_SEND_PROVE_CREATE` + `IOMS_SEND_PROVE_QUERY` / `ADES_AGENT_QUERY_POD`）、稅金支付連結（`IOMS_TAX_PAYMENT` / `IOMS_TAX_PAYMENT_LINK` / `IOMS_PAYMENT_LINK`）。

台灣相關：
- `IPE_QUERY_PRODUCT_FREIGHT` 的 `sourceCountry` / `destCountry`：港澳台用 `HK` / `MO` / `TW`（官方原文註記）。
- `SOP_COLLECT_SHIP_STANDARD`（國際收寄標準）的 `srcCountryAreaCode` / `desCountryAreaCode`：**港澳台要用 3 位數字代碼**（官方例 852 = 香港；台灣依同規則應為 `886`，官方未明列，`[待確認]`），大陸用 `CN`；body `lang` 繁體為 `zh-TW`。
- `IPE_ESTIMATE_FEE` 的報關方式列舉含 `5` 簡報銷售(台灣出口用)、`6` 簡報樣品(台灣出口用)。
- `IOMS_WAYBILL_BILL` 官方說明為「嘉里泰國門店 POS 系統」用途；`ICSM_CN_TAX_QUERY` 僅中國大陸進口稅金；`ADES_*` 為代理商（尾程供應商）接口——一般台灣商家不會用到。
- 多數接口需要月結卡號或特別權限（`IUOP_ESTIMATE_FEE`、`ICSM_CN_TAX_QUERY` 需客戶經理申請）。

## 目錄

- [1. 國際產品查詢 — `IPE_QUERY_PRODUCT_LIST`](#1-國際產品查詢--ipe_query_product_list)
- [2. 產品運費時效推薦接口 — `IPE_QUERY_PRODUCT_FREIGHT`](#2-產品運費時效推薦接口--ipe_query_product_freight)
- [3. 預估總運費查詢 — `IPE_ESTIMATE_FEE`](#3-預估總運費查詢--ipe_estimate_fee)
- [4. 查詢預計派件時間 — `IPT_QUERY_PROMITM`](#4-查詢預計派件時間--ipt_query_promitm)
- [5. 國際收寄標準 — `SOP_COLLECT_SHIP_STANDARD`](#5-國際收寄標準--sop_collect_ship_standard)
- [6. 計費重和費用項查詢 — `IOMS_QUERY_CHARGE`](#6-計費重和費用項查詢--ioms_query_charge)
- [7. 操作運單費用明細查詢 — `IOMS_WAYBILL_BILL`](#7-操作運單費用明細查詢--ioms_waybill_bill)
- [8. 單票派送證明查詢 — `IOMS_SEND_PROVE_ONEFILE`](#8-單票派送證明查詢--ioms_send_prove_onefile)
- [9. 批量派送證明查詢 — `IOMS_SEND_PROVE_CREATE`](#9-批量派送證明查詢--ioms_send_prove_create)
- [10. 尾程供應商派送證明查詢 — `ADES_AGENT_QUERY_POD`](#10-尾程供應商派送證明查詢--ades_agent_query_pod)
- [11. 派送證明上傳 — `ADES_UPLOAD_POD`](#11-派送證明上傳--ades_upload_pod)
- [12. 獲取稅金信息及支付渠道 — `IOMS_TAX_PAYMENT`](#12-獲取稅金信息及支付渠道--ioms_tax_payment)
- [13. 獲取稅金支付鏈接 — `IOMS_TAX_PAYMENT_LINK`](#13-獲取稅金支付鏈接--ioms_tax_payment_link)
- [14. 獲取在線支付短鏈接 — `IOMS_PAYMENT_LINK`](#14-獲取在線支付短鏈接--ioms_payment_link)
- [15. 中國海關稅金信息查詢 — `ICSM_CN_TAX_QUERY`](#15-中國海關稅金信息查詢--icsm_cn_tax_query)
- [16. 代理商更新運單 — `ADES_AGENT_UPDATE_WAYBILL`](#16-代理商更新運單--ades_agent_update_waybill)

---

## 1. 國際產品查詢 — `IPE_QUERY_PRODUCT_LIST`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=243（文檔中心 > API文檔 > 國際件API > 通用 > 國際產品查詢；官方更新 2026-08-24；爬取 2026-09-23）

### 功能描述

國際產品查詢

---

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

---

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IPE_QUERY_PRODUCT_LIST`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | --- | --- | --- |
|  |  |  |  | 由於需要保證JSON結構，因此不需要參數，只需傳遞花括號即可：<br>{ } |

---

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | --- | --- | --- |
| 1 | success | Boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | List<ProductDto> | N | 響應數據 |

#### 元素<ProductDto>

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | --- | --- |
| 1 | interProductCode | String | 國際產品映射碼 |
| 2 | displayProductName | String | 國際產品名稱 |
| 3 | businessType | integer | 業務類型1.電商2.快遞 |

---

#### 明文請求示例

```json
{}
```

---

#### 明文響應示例

```json
{
  "msg": "Executed successfully",
  "data": [
    {
      "interProductCode": "INT0446",
      "displayProductName": "集货转运-DTP",
	  "businessType": 3
    },
    {
      "interProductCode": "INT0457",
      "displayProductName": "Wish平邮",
	  "businessType": 2
    },
    {
      "interProductCode": "INT0468",
      "displayProductName": "WISHEPC带电小包挂号",
	  "businessType": 1
    }
  ],
  "success": true
}
```

---

## 2. 產品運費時效推薦接口 — `IPE_QUERY_PRODUCT_FREIGHT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=213（文檔中心 > API文檔 > 國際件API > 通用 > 產品運費時效推薦接口；官方更新 2025-05-14；爬取 2026-09-23）

### 功能描述

```
國際產品運費時效推薦接口
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IPE_QUERY_PRODUCT_FREIGHT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | actualWeight | BigDecimal | Y | 實際重量 |
| 2 | currency | String | Y | 聲明價值幣種，默認取寄件國的默認幣種 |
| 3 | customerType | Integer | Y | 客戶類型 1-個人用戶 2-企業用戶 |
| 4 | declaredValue | Integer | Y | 保價聲明價值 |
| 5 | destContIdentity | Integer | N | 收方件種屬性代碼 （1-個人 2-公司 默認1） |
| 6 | destAddress | String | N | 收件地址，省市區+詳細地址 |
| 7 | destCityCode | String | Y | 目的地城市編碼 |
| 8 | destCountry | String | Y | 目的國二字碼，注意：港澳台是HK，MO及TW |
| 9 | destPostcode | String | N | 收件郵編 |
| 10 | destRegionFirst | String | N | 一級區劃 |
| 11 | destRegionSecond | String | N | 二級區劃 |
| 12 | destRegionThird | String | N | 三級區劃 |
| 13 | operateType | Integer | Y | 操作環節類型 1：下單；2：攬收 |
| 14 | parcelNum | Integer | Y | 包裹數 |
| 15 | serialNumber | String | Y | 序列號（建議唯一ID，用於後續定位問題） |
| 16 | sourceAddress | String | N | 寄件地址，省市區+詳細地址 |
| 17 | sourceCityCode | String | Y | 原寄遞城市編碼 |
| 18 | sourceCountry | String | Y | 原寄國二字碼，注意：港澳台是HK，MO及TW |
| 19 | sourcePostcode | String | N | 寄件郵編 |
| 20 | cargos | List\<CargoInfo> | N | 貨物集合 |
| 21 | products | List\<ProductInfo> | N | 產品集合 |

#### \*\*元素<請求> CargoInfo

| 序號 | 屬性名 | 類型(約束) | 必填 | 默認值 | 描述 |
| --- | --- | ------ | --- | --- | --- |
| 1 | firstClassify | String | N | 一級分類 |
| 2 | name | String | Y | 品名 |
| 3 | secondClassify | String | N | 二級分類 |
| 4 | thirdClassify | String | N | 三級分類 |

#### \*\*元素<請求> ProductInfo

| 序號 | 屬性名 | 類型(約束) | 必填 | 默認值 | 描述 |
| --- | --- | ------ | --- | --- | --- |
| 1 | interProductCode | String | N | 國際產品代碼 若有值則優先取該值 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 | 信息 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | products | List\<ProductListDto> | 產品信息 |
| 2 | issues | List\<IssuesDto> | 全局問題配項 |

#### 元素ProductListDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | calculate | CalculateDto | 提示級別 |
| 2 | product | ProductDto | 提示信息 |
| 3 | timePromise | String | 派送時間  |

#### 元素CalculateDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | currency | String | 提示級別 |
| 2 | freight | BigDecimal | 提示信息 |

#### 元素ProductDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | cargoTypeCode | String | 快件內容代碼 |
| 2 | expressTypeCode | String | 業務類型代碼 |
| 3 | interProductCode | String | 國際產品代碼 |
| 4 | limitTypeCode | String | 時效類型代碼 |
| 5 | productCode | String | 產品代碼L2 |
| 6 | productCodeChild | String | 產品代碼L3 |
| 7 | productNameChild | String | 產品名稱L3 |
| 8 | productNameChildExtend | String | 產品名稱L4 |
| 9 | productNameChildExtendAlias | String | 產品名稱L4 別稱 |
| 10 | productNo | String | 擴展代碼 |
| 11 | productSeriesCode | String | 主產品代碼L1 |

#### 元素IssuesDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | level | String | 提示級別 |
| 2 | msg | String | 提示信息 |
| 3 | typeCode | String | 提示類型編碼 |

#### 明文請求示例

```
```

#### 明文響應示例

```
```

---

## 3. 預估總運費查詢 — `IPE_ESTIMATE_FEE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=218（文檔中心 > API文檔 > 國際件API > 通用 > 預估總運費查詢；官方更新 2025-08-29；爬取 2026-09-23）

### 功能描述

```
電商預估總運費用接口
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IPE_ESTIMATE_FEE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | channel | String | N | 渠道，傳k1碼，沒有不傳 |
| 2 | orderTime | Long | N | 下單時間戳（精確到ms） |
| 3 | serialNumber | String | Y | 序列號（建議唯一ID，用於後續定位問題） |
| 4 | customerCode | String | Y | 客戶編碼 |
| 5 | customerType | Integer | Y | 客戶類型 1-個人用戶 2-企業用戶 |
| 6 | interProductCode | String | Y | 國際產品映射代碼 |
| 7 | parcelQuantity | Integer | N | 包裹總件數(大於1表示是子母件，最大值300) |
| 8 | parcelTotalWeight | Decimal | Y | 包裹總重量。精確到小數點後2位重量單位-LBS (US & CA);-KG(else); |
| 9 | weightUnit | String | N | 重量單位： KG, LB 默認取寄件國重量單位 |
| 10 | lengthUnit | String | N | 長度單位： CM, INCH 默認CM |
| 11 | declaredValue | Decimal | N | 保價聲明價值 |
| 12 | reportValue | Decimal | N | 商品申報價值 |
| 13 | currency | String | N | 幣種 |
| 14 | iossCode | String | N | IOSS編碼 |
| 15 | senderInfo | SenderInfo | Y | 寄件人信息 |
| 16 | receiverInfo | ReceiverInfo | Y | 收件人信息 |
| 17 | paymentInfo | PaymentInfo | N | 付款方式信息 |
| 18 | parcelInfo | List\<ParcelInfo> | N | 包裹信息 |
| 19 | cargoInfo | List\<CargoInfo> | N | （托寄物）品名信息集合 |
| 20 | customsInfo | CustomsInfo | N | 報關信息 |
| 21 | addServiceInfo | AddServiceInfo | Y | 增值服務列表 |

#### 元素<請求>senderInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | country | String | Y | 寄件方國家/地區；採用ISO 3166 標準 Alpha-2 code如：CN,US,JP,KR,SG,MY更多參考附錄字典 |
| 2 | regionFirst | String | N | 寄件方州/省 |
| 3 | regionSecond | String | N | 寄件方城市 |
| 4 | regionThird | String | N | 寄件方區/縣 |
| 5 | address | String | N | 寄件方詳細地址 |
| 6 | postCode | String | Y | 寄方郵編 |
| 7 | cargoType | Integer | N | 寄件方貨物類型：1-個人件；2-公司件 默認1 |

#### 元素<請求>receiverInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | country | String | Y | 收件方國家/地區；採用ISO 3166 標準 Alpha-2 code如：CN,US,JP,KR,SG,MY |
| 2 | regionFirst | String | N | 收件方州/省 |
| 3 | regionSecond | String | N | 收件方城市 |
| 4 | regionThird | String | N | 收件方區/縣 |
| 5 | address | String | N | 收件方詳細地址 |
| 6 | postCode | String | Y | 收方郵編 |
| 7 | cargoType | Integer | N | 收件方貨物類型：1-個人件；2-公司件 默認1 |

#### 元素<請求>paymentInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | payMethod | String | N | 付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 2 | payMonthCard | String | N | 月結卡號 |
| 3 | taxPayMethod | String | N | 稅金付款方式：1-寄方付; 2-收方付; 3-第三方付 |
| 4 | taxPayMonthCard | String | N | 稅金結算帳號 |

#### 元素<請求>parcelInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | parcelHeight | Decimal | N | 高 |
| 2 | parcelLength | Decimal | N | 長 |
| 3 | parcelVolume | Decimal | N | 體積 |
| 4 | parcelWeight | Decimal | Y | 重量 |
| 5 | parcelWidth | Decimal | N | 寬 |

#### 元素<請求>cargoInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | cargoNum | Integer | Y | 數量 |
| 2 | elementName | String | N | 申報要素 |
| 3 | gdesc | String | Y | 商品名稱（商品名稱、商品編碼二選一） |
| 4 | hsCode | String | Y | 商品編碼（商品名稱、商品編碼二選一） |
| 5 | name | String | Y | 品名 |
| 6 | price | Decimal | Y | 商品單價 |
| 7 | unit | String | Y | 單位（英文單位，如kg、g等） |

#### 元素<請求>customsInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | exportCustomsType | String | N | 出口報關方式1-簡易報關；2-正式報關；3-海運報關；4-個人物品；5-簡報銷售(台灣出口用)；6-簡報樣品(台灣出口用)；7-跨境直郵；8-跨境保稅；9-個人行李；10-自清關 |
| 2 | importCustomsType | String | N | 進口報關方式1-簡易報關；2-正式報關；3-海運報關；4-個人物品；5-簡報銷售(台灣出口用)；6-簡報樣品(台灣出口用)；7-跨境直郵；8-跨境保稅；9-個人行李；10-自清關 |

#### 元素<請求>addServiceInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | codCustomerAccount | String | N | 代收貨款客戶卡號 |
| 2 | codFee | Decimal | N | 付款金額 |
| 3 | fixedTimeDeliverPeriod | String | N | 定時派送時間段，與定時派送增值服務有關 |
| 4 | serviceCodeList | List\<String> | Y | 增值服務編碼集合 |
| 5 | materialInfo | List\<MaterialInfo> | 否 | 包材編碼 |

#### 元素<請求>materialInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | materialCode | String | Y | 物料編碼 |
| 2 | materialNum | String | Y | 包材件數 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | code | Integer | 業務碼 |
| 2 | msg | String | 響應描述 |
| 3 | success | Boolean | 請求是否成功 |
| 4 | data | RespDto | 響應數據 |

#### 元素RespDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | currency | String | 幣種 |
| 2 | interProductCode | String | 國際產品映射代碼 |
| 3 | totalFee | Integer | 總費用 |
| 4 | totalPayFee | Decimal | 折後總費用 |
| 5 | weight | Decimal | 重量 |
| 6 | feeInfoList | List\<FeeInfo> | 費用信息列表 |

#### 元素<響應>FeeInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | currency | String | 幣種 |
| 2 | estimateFee | Integer | 費用 |
| 3 | estimatePayFee | Decimal | 折扣費用 |
| 4 | serviceCode | String | 增值服務代碼 |
| 5 | serviceName | String | 增值服務名稱 |
| 6 | serviceType | String | 增值服務類型 |

#### 明文請求示例

```
{
  "interProductCode": "INT0263",
  "customerCode": "ICRM-CNE00CC5101",
  "parcelQuantity": 5,
  "parcelTotalWeight": 12.34,
  "weightUnit": "KG",
  "lengthUnit": "CM",
  "declaredValue": 100.50,
  "currency": "USD",
  "reportValue": 95.75,
  "senderInfo": {
    "country": "CN",
    "postCode": "100000",
    "regionFirst": "Beijing",
    "regionSecond": "Chaoyang",
    "regionThird": "Dongcheng",
    "address": "No.1, Test Street",
    "cargoType": 1
  },
  "receiverInfo": {
    "country": "US",
    "postCode": "90210",
    "regionFirst": "California",
    "regionSecond": "Los Angeles",
    "regionThird": "Beverly Hills",
    "address": "1234 Elm Street",
    "cargoType": 1
  },
  "paymentInfo": {
    "payMethod": "1",
    "payMonthCard": "7558127036",
    "taxPayMethod": "2",
    "taxPayMonthCard": "DEF789012"
  },
  "customsInfo": {
    "importCustomsType": "1",
    "exportCustomsType": "2"
  },
  "cargoInfo": {
    "name": "Laptop",
    "cargoNum": 2,
    "gdesc": "High-end gaming laptop",
    "hsCode": "84713000",
    "price": 1500.00,
    "unit": "kg",
    "elementName": "Electronic equipment"
  },
  "addServiceInfo": 
    {
      "serviceCodeList": [
        "EXPRESS",
        "INSURANCE",
        "COD"
      ],
      "codFee": 120.50,
      "codCustomerAccount": "COD123456789",
      "fixedTimeDeliverPeriod": "09:00-12:00",
      "materialInfo": [
        {
          "materialCode": "BOX001",
          "materialNum": "3"
        }
      ]
    }
  ,
  "parcelInfo": {
    "parcelWeight": 5.75,
    "parcelLength": 30.00,
    "parcelWidth": 20.00,
    "parcelHeight": 15.00,
    "parcelVolume": 0.009
  },
  "customerType": 2,
  "iossCode": "EU1234567890",
  "channel": "K1-EXPRESS",
  "orderTime": 1633046400000,
  "serialNumber": "SN202310050001"
}
```

#### 明文響應示例

```json
{
    "apiResultCode": 0,
    "apiErrorMsg": null,
    "apiResultData": {
        "msg": "success",
        "code": 0,
        "data": {
            "interProductCode": "INT0263",
            "totalPayFee": 228.0,
            "totalFee": 228,
            "feeInfoList": [
                {
                    "serviceType": "1",
                    "serviceCode": "IN15",
                    "currency": "SGD",
                    "estimateFee": 4,
                    "estimatePayFee": 4.0,
                    "serviceName": "Fuel Surcharge"
                },
                {
                    "serviceType": "1",
                    "serviceCode": "IN23",
                    "currency": "SGD",
                    "estimateFee": 3,
                    "estimatePayFee": 3.0,
                    "serviceName": "Overweight and Oversize"
                }
            ],
            "weight": 12.5,
            "currency": "SGD"
        },
        "success": true
    },
    "apiTimestamp": 1744278041955
}
```

---

## 4. 查詢預計派件時間 — `IPT_QUERY_PROMITM`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=221（文檔中心 > API文檔 > 國際件API > 通用 > 查詢預計派件時間；官方更新 2026-08-18；爬取 2026-09-23）

### 功能描述

```
查詢預計派件時間
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IPT_QUERY_PROMITM`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | traceId | String | Y | 請求唯一標示 |
| 2 | sourcePostalCode | String | Y | 始發地郵編 |
| 3 | sourceCountry | String | Y | 始發國家編碼 |
| 4 | sourceRegionFirst | String | N | 始發地一級區劃 |
| 5 | sourceRegionSecond | String | N | 始發地二級區劃 |
| 6 | sourceRegionThird | String | N | 始發地三級區劃 |
| 7 | destpostalCode | String | Y | 目的地郵編 |
| 8 | destCountry | String | Y | 目的國家編碼 |
| 9 | destRegionFirst | String | N | 目的地一級區劃 |
| 10 | destRegionSecond | String | N | 目的地二級區劃 |
| 11 | destRegionThird | String | N | 目的地三級區劃 |
| 12 | monthlyCard | String | Y | 月結卡號 |
| 13 | interProductCodes | List\<String> | Y | 產品列表 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | Y | 業務碼 |
| 3 | msg | String | N | 響應描述 |
| 4 | data | Dto | N | 信息 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sourcePostalCode | String | 始發地郵編 |
| 2 | sourceCountry | String | 始發國家編碼 |
| 3 | sourceRegionFirst | String | 始發地一級區劃 |
| 4 | sourceRegionSecond | String | 始發地二級區劃 |
| 5 | sourceRegionThird | String | 始發地三級區劃 |
| 6 | destpostalCode | String | 目的地郵編 |
| 7 | destCountry | String | 目的國家編碼 |
| 8 | destRegionFirst | String | 目的地一級區劃 |
| 9 | destRegionSecond | String | 目的地二級區劃 |
| 10 | destRegionThird | String | 目的地三級區劃 |
| 11 | monthlyCard | String | 月結卡號 |
| 12 | products | List\<ProductListDto> | 產品信息 |

#### 元素ProductListDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | interProductCode | String | 國際產品代碼 |
| 2 | expectArriveTime | String | 承諾時效（2025-05-15 18:00:00） |
| 3 | baseTime | BigDecimal | 基礎時效 |
| 4 | overTime | BigDecimal | 超時時效 |
| 5 | holidayTime | BigDecimal | 節假日 |
| 6 | declarationTime | BigDecimal | 報關加時 |
| 7 | exportDeclarationTime | BigDecimal | 出口報關加時 |
| 8 | importDeclarationTime | BigDecimal | 進口報關加時 |
| 9 | addCaliber | String | 如：由於{加時原因}導致時效延長{加時天數}個工作日 |
| 10 | totalTime | BigDecimal | 整體時效 |

#### 明文請求示例

```
{
    "traceId": "1231234413",
    "sourcePostalCode": "518000",
    "sourceCountry": "CN",
    "sourceRegionFirst": "sourceRegionFirst",
    "sourceRegionSecond": "sourceRegionSecond",
    "sourceRegionThird": "sourceRegionThird",
    "destpostalCode": "77276",
    "destCountry": "US",
    "destRegionFirst": "destRegionFirst",
    "destRegionSecond": "destRegionSecond",
    "destRegionThird": "destRegionThird",
    "monthlyCard": "7558127036",
    "interProductCodes": [
        "INT0263",
        "INT0554"
    ]
}
```

#### 明文響應示例

```
{
        "msg": "执行成功",
        "code": "0",
        "data": {
            "destCountry": "US",
            "destRegionSecond": "destRegionSecond",
            "sourceCountry": "CN",
            "sourceRegionSecond": "sourceRegionSecond",
            "destRegionThird": "destRegionThird",
            "destpostalCode": "77276",
            "monthlyCard": "7558127036",
            "sourceRegionThird": "sourceRegionThird",
            "sourcePostalCode": "518000",
            "destRegionFirst": "destRegionFirst",
            "products": [
                {
                    "interProductCode": "INT0263",
                    "declarationTime": 0,
                    "holidayTime": 2,
                    "exportDeclarationTime": 0,
                    "importDeclarationTime": 0,
                    "totalTime": 9,
                    "expectArriveTime": "2025-05-28 23:59:00",
                    "overTime": 0,
                    "addCaliber": "",
                    "baseTime": 7
                },
                {
                    "interProductCode": "INT0554",
                    "declarationTime": 0,
                    "holidayTime": 2,
                    "exportDeclarationTime": 0,
                    "importDeclarationTime": 0,
                    "totalTime": 7,
                    "expectArriveTime": "2025-05-26 23:59:00",
                    "overTime": 0,
                    "addCaliber": "",
                    "baseTime": 5
                }
            ],
            "sourceRegionFirst": "sourceRegionFirst"
        },
        "success": true
    }
```

---

## 5. 國際收寄標準 — `SOP_COLLECT_SHIP_STANDARD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=212（文檔中心 > API文檔 > 國際件API > 通用 > 國際收寄標準；官方更新 2024-12-05；爬取 2026-09-23）

### 功能描述

```
國際收寄標準
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `SOP_COLLECT_SHIP_STANDARD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | requestId | String | Y | 請求id |
| 1 | operateType | String | Y | 固定值1，代表下單 |
| 1 | callTm | Long | Y | 當前接口調用時間戳 |
| 1 | srcCountryAreaCode | String | Y | （始發地）原寄地國家/地區代碼二字碼 （港澳台則設置為3位數字代碼，如852表示香港；CN表示大陸 |
| 1 | desCountryAreaCode| String | Y | 目的地國家/地區代碼二字碼（港澳台則設置為3位數字代碼，如852），如852表示香港；CN表示大陸 |
| 1 | consignorRemark | String | Y | 寄方件種屬性代碼（01：個人件 02：公司件） |
| 1 | addresseeRemark | String | Y | 收方件種屬性代碼（01：個人件 02：公司件） |
| 1 | lang | String | N | 國際化語言代碼，如：簡體中文：zh-CN，繁體中文：zh-TW，英文：en |
| 1 | gdescDtos | List <GdescDto> | Y | （托寄物）品名信息集合 |

#### 元素<請求> GdescDto

| 序號 | 屬性名      | 類型(約束)  | 必填 | 默認值 | 描述                                                         |
| ---- | ----------- | ----------- | ---- | ------ | ------------------------------------------------------------ |
| 1 | quantity | String | N | 數量 |
| 2 | unit | String | N | 單位 |
| 3 | gdesc | String | Y | 物品名稱 ：刪除數字【0-9】和特殊字符後不能為空，【特殊字符】。純中文長度必須大於等於2，純英文必須大於2 |
| 4 | price | String | N | 單價 |
| 4 | weight | String | N | 單托寄物重量 |
| 4 | label | String | N | 物品標籤（例如：易燃易爆） |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 | 信息 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | finalExpressRequireCode | String | 最終收寄要求CODE(值為： ExpressRequireAllow(可收寄)、 ExpressRequireProhibit(不可收寄)、 ExpressRequireRestrict(有條件收寄)、 ""(匹配不到為空)) |
| 2 | details | List\<DetailDto> | 托寄物收寄標準 list |

#### 元素DetailDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | gdesc | String | 托寄物名稱（品名） |
| 2 | isFuzzy | String | 是否模糊匹配（Y:是 N:否） |

#### 明文請求示例

```
```

#### 明文響應示例

```
```

---

## 6. 計費重和費用項查詢 — `IOMS_QUERY_CHARGE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=133（文檔中心 > API文檔 > 國際件API > 通用 > 計費重和費用項查詢；官方更新 2026-04-03；爬取 2026-09-23）

### 功能描述

```
根據月結卡號和批量運單號，查詢對應客戶的運單的計費重和費用項。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_QUERY_CHARGE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | monthlyCard | String | 是 | 月結帳號 |
| 3 | waybillList | List\<String > | 是 | 順豐運單號集合（最多10條） |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 業務接口響應數據 |

元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerCode | String | 客戶編碼 |
| 2 | monthlyCard | String | 月結帳號 |
| 3 | resultCode | String | 接口響應CODE |
| 4 | resultMsg | String | 接口響應描述 |
| 5 | waybillChargeList | List\<WaybillCharge> | 返回的運單費用信息集合 |

元素WaybillCharge

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | waybillNo | String | 順豐運單號 |
| 2 | childWaybillNos | List\<String> | 子單號(包裹號)List |
| 3 | realWeightQty | Double | 實際重 |
| 4 | volume | Double | 體積 |
| 5 | meterageWeightQty | Double | 計費重 |
| 6 | quantity | Integer | 包裹件數 |
| 7 | waybillFeeList | List\<WaybillFee> | 費用明細集合 |

元素WaybillFee

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | feeId | String | 費用類型ID |
| 2 | feeTypeCode | String | 費用類型代碼 |
| 3 | feeAmt | BigDecimal| 費用金額 |
| 4 | destCurrencyCode | String | 費用幣種 |
| 5 | paymentTypeCode | String | 費用付款方式，（收方、寄方、第三方） |
| 6 | settlementTypeCode | String | 結算方式（現結、月結） |
| 7 | ticketOffsetAmt | BigDecimal| 券抵免金額 |
| 8 | deductionAmt | BigDecimal| 抵免金額 |
|9| discountFeeAmt|BigDecimal|折後金額|

#### 明文請求示例

```
{
"customerCode":"ICRME002WRY68",
"monthlyCard":"0203004660",
"waybillList": ["SF1030406570721"]
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "msg": "请求成功",
    "success": true,
    "data": {
        "resultCode": "0000",
        "resultMsg": "成功",
        "customerCode": "ICRME002WRY68",
        "monthlyCard": "0203004660",
        "waybillChargeList": [
            {
                "waybillNo": "SF1030406570721",
                "childWaybillNos": [
                    "SF1030406570721"
                ],
                "realWeightQty": 5.1,
                "volume": 6000.0,
                "meterageWeightQty": 1.0,
                "quantity": 1,
                "waybillFeeList": [
                    {
                        "feeId": "",
                        "feeTypeCode": "14",
                        "feeAmt": 21.0,
                        "destCurrencyCode": "",
                        "paymentTypeCode": "寄付",
                        "settlementTypeCode": "月结",
                        "ticketOffsetAmt": null,
                        "deductionAmt": 0.0
                    },
                    {
                        "feeId": "",
                        "feeTypeCode": "1",
                        "feeAmt": 2.22,
                        "destCurrencyCode": "",
                        "paymentTypeCode": "寄付",
                        "settlementTypeCode": "现结",
                        "ticketOffsetAmt": null,
                        "deductionAmt": 0.0
                    },
                    {
                        "feeId": "",
                        "feeTypeCode": "15",
                        "feeAmt": 100.0,
                        "destCurrencyCode": "",
                        "paymentTypeCode": "寄付",
                        "settlementTypeCode": "月结",
                        "ticketOffsetAmt": null,
                        "deductionAmt": 0.0
                    },
                    {
                        "feeId": "",
                        "feeTypeCode": "3",
                        "feeAmt": 9.9,
                        "destCurrencyCode": "",
                        "paymentTypeCode": "寄付",
                        "settlementTypeCode": "月结",
                        "ticketOffsetAmt": null,
                        "deductionAmt": 0.0
                    },
                    {
                        "feeId": "",
                        "feeTypeCode": "99",
                        "feeAmt": null,
                        "destCurrencyCode": "",
                        "paymentTypeCode": "到付",
                        "settlementTypeCode": "现结",
                        "ticketOffsetAmt": null,
                        "deductionAmt": null
                    }
                ]
            }
        ]
    }
}
```

---

## 7. 操作運單費用明細查詢 — `IOMS_WAYBILL_BILL`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=184（文檔中心 > API文檔 > 國際件API > 通用 > 操作運單費用明細查詢；官方更新 2024-04-19；爬取 2026-09-23）

### 功能描述

```
嘉里泰國門店POS系統調用運單費用帳單信息查詢接口，用於運單費用收取
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_WAYBILL_BILL`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | waybillNo | String(32) | 是 | 順豐運單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 |  |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | waybillNo | String | 運單號 |
| 2 | realWeightQty | BigDecimal | 實際重量 |
| 3 | volume | BigDecimal | 體積 |
| 4 | meterageWeightQty | BigDecimal | 計費重 |
| 5 | unitWeight | String | 重量單位 |
| 6 | quantity | BigDecimal | 包裹數量 |
| 7 | length | BigDecimal | 長度 |
| 8 | height | BigDecimal | 高度 |
| 9 | width | BigDecimal | 寬度 |
| 10 | waybillBillFeeList | List\<WaybillBillFeeDto> | 費用信息列表 |

#### 元素WaybillBillFeeDto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | feeTypeCode | String | 費用類型 |
| 2 | feeAmt | BigDecimal | 折前金額 |
| 3 | currencyCode | String | 幣別代碼 |
| 4 | paymentTypeCode | String | 付款方式<br>1.寄付<br>2.到付<br>3.第三方付 |
| 5 | settlementTypeCode | String | 結算方式<br>1\. 現結<br>2.月結 |
| 6 | ticketOffsetAmt | BigDecimal | 券抵免金額 |
| 7 | deductionAmt | BigDecimal | 抵免金額 |

#### 明文請求示例

```
{
"waybillNo":"SF1040459304216"
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "height": 1.0,
        "length": 36.0,
        "meterageWeightQty": 0.5,
        "quantity": 1.0,
        "realWeightQty": 0.5,
        "unitWeight": "kg",
        "volume": 1008.0,
        "waybillBillFeeList": [
            {
                "currencyCode": "CNY",
                "deductionAmt": 0,
                "feeAmt": 0.0,
                "feeTypeCode": "6",
                "paymentTypeCode": "1",
                "settlementTypeCode": "2",
                "ticketOffsetAmt": 0
            },
            {
                "currencyCode": "CNY",
                "deductionAmt": 0,
                "feeAmt": 16.5,
                "feeTypeCode": "92",
                "paymentTypeCode": "1",
                "settlementTypeCode": "2",
                "ticketOffsetAmt": 0
            },
            {
                "currencyCode": "CNY",
                "deductionAmt": 0,
                "feeAmt": 61.0,
                "feeTypeCode": "14",
                "paymentTypeCode": "1",
                "settlementTypeCode": "2",
                "ticketOffsetAmt": 0
            }
        ],
        "waybillNo": "SF1040459304216",
        "width": 28.0
    },
    "msg": "请求成功",
    "success": true
}
```

---

## 8. 單票派送證明查詢 — `IOMS_SEND_PROVE_ONEFILE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=124（文檔中心 > API文檔 > 國際件API > 通用 > 單票派送證明查詢；官方更新 2026-04-28；爬取 2026-09-23）

### 功能描述

```
根據月結卡號和批量運單號，查詢對應客戶的運單的圖片數據，屬於順豐系統按標準模板生成派送證明文件
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_SEND_PROVE_ONEFILE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | monthlyCard | String | 是 | 月結帳號 |
| 3 | waybillNo | String | 是 | 順豐運單號 |
| 4 | fileType | String | 是 | 文件類型<br>15：派送證明<br>71：微派任務 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼<br>200000	請求成功<br>300603	系統錯誤 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 業務接口響應數據 |

元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerCode | String | 客戶編碼 |
| 2 | monthlyCard | String | 月結帳號 |
| 3 | taskCode | String | 文件生成任務編碼 |
| 4 | resultCode | String | 響應code<br>0000	成功<br>0001	客戶編碼不存在<br>0002	客戶編碼失效<br>0003	月結帳號不存在<br>0004	月結帳號不生效<br>0005	運單號：“××，××，××”非順豐運單號<br>0006	文件類型不支持<br>0007	文件生成任務編碼不存在<br>0008	文件生成任務處理狀態為待處理<br>0009	文件生成任務處理狀態為處理失敗<br>0010	文件生成任務處理狀態為取消<br>0011	文件生成任務已超過有效期，請重新查詢<br>0012	請求重複提交，請三分鐘後再提交<br>0013	查詢運單號數量不能為空<br>0014	查詢運單號數量超過50個<br>0015	TaskCode任務查詢中<br>0016	TaskCode任務5秒內不可重複提交<br>0017	運單號：“××，××，××”非該月結帳號下的運單號<br>0018	未查詢到數據<br>0019	運單號:“××”查詢文件失敗!<br>0020	查詢運單號不能為空<br>0021	查詢運單號數量不可超過10個<br>0022	“××”未查詢到運單信息 |
| 5 | resultMsg | String | 響應msg |
| 6 | status | Integer | 文件生成任務處理狀態 0：待處理 1：處理成功 2：處理失敗 3：取消 |
| 7 | taskBeginDate | Date | 任務有效期開始時間（新增文件生成任務的時間） |
| 8 | taskEndDate | Date | 任務有效期結束時間（新增文件生成任務的時間+7天） |
| 9 | taskCreateTime | Date | 文件生成任務創建時間（新增文件生成任務的時間） |
| 10 | handleTime | Date | 文件生成任務處理完成時間（處理成功或者處理失敗的時間） |
| 11 | waybillFileList | List\<WaybillFile> | 返回的運單查詢結果 |

元素WaybillFile

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | waybillNo | String | 順豐運單號 |
| 2 | waybillFileName | String | 順豐運單號下的文件名 |
| 3 | fileBase64 | String | 文件的Base64字符串 |

#### 明文請求示例

```
{
    "customerCode": "ICRME002WRY68",
    "fileType": "15",
    "monthlyCard": "0203004660",
    "waybillNo": "SF0000000009115"
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "customerCode": "ICRME002WRY68",
        "fileType": "15",
        "handleTime": 1661136652615,
        "monthlyCard": "0203004660",
        "resultCode": "0000",
        "resultMsg": "成功",
        "status": 1,
        "taskBeginDate": 1661136652415,
        "taskCode": "SFIN2022082210505215001",
        "taskCreateTime": 1661136652415,
        "taskEndDate": 1661741452415,
        "waybillFileList": [
            {
                "fileBase64": "/9j/4AAQSkZJRgABAgAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRo...",
                "waybillFileName": "SF0000000009115-0001.jpg",
                "waybillNo": "SF0000000009115"
            }
        ],
        "waybillNo": "SF0000000009115"
    },
    "msg": "请求成功",
    "success": true
}
```

---

## 9. 批量派送證明查詢 — `IOMS_SEND_PROVE_CREATE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=86（文檔中心 > API文檔 > 國際件API > 通用 > 批量派送證明查詢；官方更新 2026-04-28；爬取 2026-09-23）

\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-
\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-
**步驟1. 調用【查詢運單文件接口】，創建查詢任務，並生成taskCode返回。**
**步驟2. 調用【步驟1】得到taskCode三分鐘後，通過taskCode調用【查詢任務結果接口】獲取結果。**

### 功能描述

```
查詢運單文件接口
描述：調用接口，根據月結卡號和批量運單號，查詢對應客戶的運單的圖片數據，並生成異步文件查詢任務，返回任務編碼。是按順豐模板生成的派送證明圖片。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_SEND_PROVE_CREATE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | monthlyCard | String | 是 | 月結帳號 |
| 3 | waybillList | List\<String> | 是 | 運單號集合（一次最多50條） |
| 4 | fileType | String | 是 | 文件類型（派送證明傳15即可） 15：派送證明 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼<br>200000	請求成功<br>300603	系統錯誤 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 業務接口響應數據 |

元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerCode | String | 客戶編碼 |
| 2 | monthlyCard | String | 月結帳號 |
| 3 | waybillList | List\<String> | 運單號集合 |
| 4 | fileType | String | 文件類型 |
| 5 | resultCode | String | 響應原因碼 說明<br>0000	成功<br>0001	客戶編碼不存在<br>0002	客戶編碼失效<br>0003	月結帳號不存在<br>0004	月結帳號不生效<br>0005	運單號：“××，××，××”非順豐運單號<br>0006	文件類型不支持<br>0007	文件生成任務編碼不存在<br>0008	文件生成任務處理狀態為待處理<br>0009	文件生成任務處理狀態為處理失敗<br>0010	文件生成任務處理狀態為取消<br>0011	文件生成任務已超過有效期，請重新查詢<br>0012	請求重複提交，請三分鐘後再提交<br>0013	查詢運單號數量不能為空<br>0014	查詢運單號數量超過50個<br>0015	TaskCode任務查詢中<br>0016	TaskCode任務5秒內不可重複提交<br>0017	運單號：“××，××，××”非該月結帳號下的運單號<br>0018	未查詢到數據<br>0019	運單號:“××”查詢文件失敗!<br>0020	查詢運單號不能為空<br>0021	查詢運單號數量不可超過10個<br>0022	“××”未查詢到運單信息 |
| 6 | resultMsg | String | 響應原因描述 |
| 7 | taskCode | String | 文件生成任務編碼（編碼規則為SFIN+年月日時分秒+請求的文件類型編碼+三位遞增數） |
| 8 | taskBeginDate | Date | 任務有效期開始時間（新增文件生成任務的時間） |
| 9 | taskEndDate | Date | 任務有效期結束時間（新增文件生成任務的時間+7天） |

#### 明文請求示例

```
{
    "customerCode": "SF000121",
    "fileType": "15",
    "monthlyCard": "001244",
    "waybillList": [
        "SF1310480786461",
        "843008125543"
    ]
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "customerCode": "SF000121",
        "fileType": "15",
        "monthlyCard": "001244",
        "resultCode": "0000",
        "resultMsg": "成功",
        "taskBeginDate": 1645064841716,
        "taskCode": "SFIN202221618502315001",
        "taskEndDate": 1645669641716,
        "waybillList": [
            "SF1310480786461",
            "843008125543"
        ]
    },
    "msg": "请求成功",
    "success": true
}
```

### 功能描述

```
查詢任務結果接口
描述：通過任務編碼查詢，返回運單號對應的文件，支持外部客戶調用接口，通過客服編碼、月結帳號、文件生成任務編碼查詢對應文件。
```

### 請求地址

| 環境 | HTTP請求地址 |
| --- | -------- |
| 正式環境 | https://api-ifsp.sf.global/openapi/api/dispatch |
| 沙箱環境 | http://api-ifsp.sit.sf.global/openapi/api/dispatch |

### 公共頭參數

| # | 屬性名 | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | msgType | String | Y | 接口編碼，固定值：IOMS\_SEND\_PROVE\_QUERY |
| 2 | appKey | String | Y | App key |
| 3 | token | String | Y | Access Token接口獲取的token<br>獲取方法查考：Access Token接口（見 `auth-and-crypto.md`） |
| 4 | timestamp | String | Y | 時間戳 |
| 5 | nonce | String | Y | 請求隨機數 |
| 6 | signature | String | Y | 簽名<br>獲取方法查考：加解密樣例代碼（見 `auth-and-crypto.md`） |
| 7 | lang | String | Y | 語言編碼：<br>en：英文<br>zh-CN：簡體中文<br>zh-HK：繁體中文 |

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String | 是 | 客戶編碼 |
| 2 | monthlyCard | String | 是 | 月結帳號 |
| 3 | taskCode | String | 是 | 運單文件查詢服務返回的文件生成任務編碼 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼<br>200000	請求成功<br>300603	系統錯誤 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 業務接口響應數據 |

元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | customerCode | String | 客戶編碼 |
| 2 | monthlyCard | String | 月結帳號 |
| 3 | taskCode | String | 文件生成任務編碼 |
| 4 | resultCode | String | 響應code<br>0000	成功<br>0001	客戶編碼不存在<br>0002	客戶編碼失效<br>0003	月結帳號不存在<br>0004	月結帳號不生效<br>0005	運單號：“××，××，××”非順豐運單號<br>0006	文件類型不支持<br>0007	文件生成任務編碼不存在<br>0008	文件生成任務處理狀態為待處理<br>0009	文件生成任務處理狀態為處理失敗<br>0010	文件生成任務處理狀態為取消<br>0011	文件生成任務已超過有效期，請重新查詢<br>0012	請求重複提交，請三分鐘後再提交<br>0013	查詢運單號數量不能為空<br>0014	查詢運單號數量超過50個<br>0015	TaskCode任務查詢中<br>0016	TaskCode任務5秒內不可重複提交<br>0017	運單號：“××，××，××”非該月結帳號下的運單號<br>0018	未查詢到數據<br>0019	運單號:“××”查詢文件失敗!<br>0020	查詢運單號不能為空<br>0021	查詢運單號數量不可超過10個<br>0022	“××”未查詢到運單信息 |
| 5 | resultMsg | String | 響應msg |
| 6 | status | Integer | 文件生成任務處理狀態 0：待處理 1：處理成功 2：處理失敗 3：取消 |
| 7 | taskBeginDate | Date | 任務有效期開始時間（新增文件生成任務的時間） |
| 8 | taskEndDate | Date | 任務有效期結束時間（新增文件生成任務的時間+7天） |
| 9 | taskCreateTime | Date | 文件生成任務創建時間（新增文件生成任務的時間） |
| 10 | handleTime | Date | 文件生成任務處理完成時間（處理成功或者處理失敗的時間） |
| 11 | waybillInfoList | List\<WaybillInfo> | 返回的運單查詢結果 |

元素WaybillInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | waybillNo | String | 順豐運單號 |
| 2 | waybillFileList | List\<WaybillFile> | 順豐運單號下的文件集合 |

元素WaybillFile

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | waybillNo | String | 順豐運單號 |
| 2 | waybillFileName | String | 順豐運單號下的文件名 |
| 3 | fileBase64 | String | 文件的Base64字符串 |

#### 明文請求示例

```
{
    "customerCode": "SF000121",
    "monthlyCard": "001244",
    "taskCode": "SFIN202221618502315001"
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "customerCode": "SF000121",
        "handleTime": 1645498658433,
        "monthlyCard": "001244",
        "resultCode": "0000",
        "resultMsg": "成功",
        "status": 1,
        "taskBeginDate": 1645066658433,
        "taskCode": "SFIN202221618502315001",
        "taskCreateTime": 1645066658433,
        "taskEndDate": 1645671458433,
        "waybillInfoList": [
            {
                "waybillFileList": [
                    {
                        "fileBase64": "124567dcbvfdsndsadsjmngcbc",
                        "waybillFileName": "SF1310480786461-001.jpg",
                        "waybillNo": "SF1310480786461"
                    },
                    {
                        "fileBase64": "124567dcbvfdsndsadsjmngcbc112",
                        "waybillFileName": "SF1310480786461-002.png",
                        "waybillNo": "SF1310480786461"
                    }
                ],
                "waybillNo": "SF1310480786461"
            }
        ]
    },
    "msg": "请求成功",
    "success": true
}
```

---

## 10. 尾程供應商派送證明查詢 — `ADES_AGENT_QUERY_POD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=168（文檔中心 > API文檔 > 國際件API > 通用 > 尾程供應商派送證明查詢；官方更新 2026-07-15；爬取 2026-09-23）

### 功能描述

```
尾程供應商派送證明查詢，尾程代理提供的派送證明，實拍圖片
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ADES_AGENT_QUERY_POD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 是 | 順豐運單號 |

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
| 1 | sfWaybillNo | String | 順豐運單號 |
| 2 | agentWaybillNo | String | 物流商代理單號 |
| 3 | fileBase64 | String | PDF文件的Base64字符串 |
| 4 | pdfHtmlStr | String | 原始HTML信息 |
| 5 | podList| List\<String> | 所有 POD 文件的 Base64 編碼集合 |

#### 明文請求示例

```
{
"sfWaybillNo":"SF1231321231"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": {
        "agentWaybillNo": "1Z144AW70404858085",
        "fileBase64": "JVBERi0xLjQKJfbk/N8KMSAwIG9iago8PAovVHlwZSAvQ2F0YWxvZwo...",
        "sfWaybillNo": "SF1231321231",
        "pdfHtmlStr": "<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN"><HTML>...</HTML>"
    },
    "msg": "",
    "success": true
}
```

---

## 11. 派送證明上傳 — `ADES_UPLOAD_POD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=183（文檔中心 > API文檔 > 國際件API > 通用 > 派送證明上傳；官方更新 2025-03-03；爬取 2026-09-23）

### 功能描述

```
派送證明上傳
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ADES_UPLOAD_POD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String | 是 | 順豐運單號 |
| 2 | fileContent | String | 是 |Base64格式的pod文件,fileType=URL時該字段傳URL的值，該值必須為可以直接GET訪問的文件地址,且當文件類型不是PDF時需要在url中帶上fileType參數如：fileType=PNG |
| 3 | fileFormat | String | 是 | 文件類型，支持PDF/PNG/JPG/URL |
| 4 | agentCode | String | 是 | 代理商代碼 |
| 5 | agentWaybillNo | String | 是 | 代理商單號 |
| 6 | consignList | List\<Consign> | 否 | 簽收托寄物列表 |
| 7 | timestamp | Long | 否 | 簽收時間毫秒時間戳 |

#### \*\*元素<請求>Consign簽收托寄物信息

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sku | String | 否 | SKU |
| 2 | quantity | Double | 否 | 簽收數量 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | time | long | 否 | 響應數據時間戳 |

#### 明文請求示例

```
{
    "sfWaybillNo": "SF1111111117",
    "fileContent": "JVBERi0xL...",
    "fileFormat": "PDF",
    "agentCode": "J&T-PH",
    "agentWaybillNo": "XV111117"
}
```

#### 明文響應示例

```
{
    "code": "SUC_ADES-MANAGE_00_0000",
    "msg": "success",
    "success": true,
    "time": 1695636030363
}
```

---

## 12. 獲取稅金信息及支付渠道 — `IOMS_TAX_PAYMENT`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=173（文檔中心 > API文檔 > 國際件API > 通用 > 獲取稅金信息及支付渠道；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
獲取稅金信息及支付渠道
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_TAX_PAYMENT`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | BNo | String |  | 運單號 |
| 2 | phone | String |  | 收方或者寄方手機號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 路由列表 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | cargoPieces | double |  | 物品數量 |
| 2 | handlingFee | BigDecimal |  | 報關手續費 |
| 3 | interdna | String |  | 清關口岸對應的國家（國別地區），如US |
| 4 | consignorCityCode | String |  | 寄件城市編碼 |
| 5 | paymentCompanies | List\<paymentCompanies > |  | 支付公司列表 |
| 6 | consignorCountryCode | String |  | 寄件國編碼,如US |
| 7 | fee | FeeInfo |  | 費用信息 |
| 8 | addresseeCountryCode | String |  | 目的國編碼,如US |
| 9 | srcCompany | String |  | 寄件公司名稱 |
| 10 | englishCargoNames | String |  | 英文物品名稱 |
| 11 | decCode | String |  | 報關單號 |
| 12 | phoneStatus | Boolean |  | 電話號碼狀態，true-通過、false-未通過 |
| 13 | currency | String |  | 口岸稅金幣種，如USD |
| 14 | desPhone | String |  | 收件電話 |
| 15 | srcPhone | String |  | 寄件電話 |
| 16 | customsDate | String |  | 實際報關日期 |
| 17 | dutyValue | BigDecimal |  | 貨物稅 |
| 18 | totalValue | BigDecimal |  | 稅金合計 |
| 19 | wareHouseFee | BigDecimal |  | 報關倉儲費 |
| 20 | bno | String |  | 運單號 |
| 21 | mainbNo | String |  | 海關提供提單號 |
| 22 | desAddress | String |  | 收件地址 |
| 23 | otherFee | BigDecimal |  | 其他費用 |
| 24 | gstValue | BigDecimal |  | 消費稅 |
| 25 | inDate | String |  | 貨物到達口岸日期 |
| 26 | incrementFee | BigDecimal |  | 增值稅 |
| 27 | decValue | BigDecimal |  | 申報價值 |
| 28 | mawb | String |  | 航空主單號 |
| 29 | srcPersonName | String |  | 寄件聯繫人 |
| 30 | desPersonName | String |  | 收件聯繫人 |
| 31 | taxPerNo | String |  | 納稅人編號 |
| 32 | cargoNames | String |  | 物品名稱 |
| 33 | otherDutyAmount | BigDecimal |  | 其他稅 |
| 34 | cargoWeight | double |  | 物品重量 |
| 35 | cusDeptCode | String |  | 口岸三字碼，如LAX |
| 36 | quarantineFee | BigDecimal |  | 檢驗檢疫費 |
| 37 | cusCurrencySymbol | String |  | 申報幣別 |
| 38 | traiffValue | BigDecimal |  | 關稅 |
| 39 | desCompany | String |  | 收件公司名稱 |
| 40 | cusNetCode | String |  | 口岸公司核算代碼，如755Z |
| 41 | decType | String |  | 報關類型 |
| 42 | status | Integer |  | 稅金支付狀態（1:已支付、2:待支付、3:異常、4:已關閉 ）|
| 43 | statusDetail | Integer |  | 支付狀態詳情（1：支付方信息不完整/有誤，2：收方超期付款，3：寄方超期付款，4：收方超期付款關閉，5：超期付款轉寄方月結，6：超期付款轉寄方現結，7：寄方超期付款關閉，8：系統超時關閉，9：手動關閉，10：收方超期付款轉第三方） |
| 44 | totalFeeVal | BigDecimal |  | 稅費合計 |
| 45 | desPhoneCode | String |  | 收件電話區號 |
| 46 | srcPhoneCode | String |  | 寄件電話區號 |

#### 元素PaymentCompanies

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | code | String |  | 編碼 |
| 2 | payChannelName | String |  | 渠道名稱 |
| 3 | payChannelLogo | String |  | 渠道logo base64 |
| 4 | name | String |  | 名稱 |
| 5 | logo | String |  | base64 |
| 6 | payChanne | String |  | 渠道 |

#### 元素feeInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | totalFee | BigDecimal |  | 總費用 |
| 2 | freight | FreightInfo |  | 運費項 |
| 3 | currency | String |  | 費用幣種 |
| 4 | tax | TaxInfo |  | 稅金項 |

#### 元素TaxInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | handlingFee | BigDecimal |  | 報關手續費 |
| 2 | interdna | String |  | 清關口岸對應的國家（國別地區） |
| 3 | taxAccount | String |  | 稅金結算帳號（寄付、第三方必填） |
| 4 | decCode | String |  | 報關單號 |
| 5 | currency | String |  | 費用幣種 |
| 6 | customsDate | String |  | 實際報關日期 |
| 7 | dutyValue | BigDecimal |  | 貨物稅 |
| 8 | totalValue | BigDecimal |  | 稅金合計 |
| 9 | wareHouseFee | BigDecimal |  | 報關倉儲費 |
| 10 | mainbNo | String |  | 海關提供提單號 |
| 11 | isAudit | String | 否 | 已審核:Y；未審核：N |
| 12 | otherFee | BigDecimal |  | 其他費用 |
| 13 | gstValue | BigDecimal |  | 消費稅 |
| 14 | inDate | String |  | 貨物到達口岸日期 |
| 15 | incrementFee | BigDecimal |  | 增值稅 |
| 16 | decValue | BigDecimal |  | 申報價值 |
| 17 | mawb | String |  | 航空主單號 |
| 18 | taxPerNo | String |  | 納稅人編號 |
| 19 | otherDutyAmount | BigDecimal |  | 其他稅 |
| 20 | cusDeptCode | String |  | 口岸三字碼，如LAX |
| 21 | totalFee | BigDecimal |  | 稅費合計 |
| 22 | quarantineFee | BigDecimal |  | 檢驗檢疫費 |
| 23 | taxPaymentType | String |  | 稅金付款方式（1：寄方月結，2：到方現結，3：第三方支付，4：其他） |
| 24 | cusCurrencySymbol | String |  | 申報幣別 |
| 25 | traiffValue | BigDecimal |  | 關稅 |
| 26 | cusNetCode | String |  | 口岸公司核算代碼，如755Z（模板配置） |
| 27 | decType | String |  | 報關類型 |
| 28 | totalFeeVal | BigDecimal |  | 稅費合計 |
| 29 | waybillNo | String |  | 運單號 |
| 30 | taxPaymentTypeName | String |  | 稅金付款方式名稱 |

#### 元素FreightInfo

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | totalFreightFee | BigDecimal |  | 總運費 |
| 2 | totalFee | BigDecimal |  | 總費用 |
| 3 | freightFee | BigDecimal |  | 運費 |
| 4 | currency | String |  | 費用幣種 |
| 5 | waybillNo | String |  | 運單號 |
| 6 | valueAddedFee | BigDecimal |  | 增值服務費 |

#### 明文請求示例

```
{
"BNo":"SF1650005639414",
"phone":"1211315422"
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "addresseeCountryCode": "MY",
        "bno": "SF1650005639414",
        "cargoNames": "工a01",
        "cargoPieces": 1.0,
        "cargoWeight": 1.0,
        "consignorCityCode": "755",
        "consignorCountryCode": "CN",
        "currency": "MYR",
        "cusCurrencySymbol": "MYR",
        "cusDeptCode": "LHR",
        "cusNetCode": "LHR01Z",
        "customsDate": "2020-02-26 00:00:00",
        "decCode": "12610002511983",
        "decType": "",
        "decValue": 72.65000,
        "desAddress": "400 N Tustin Ave, Ste 275,Santa Ana,NEW YORK,MY******",
        "desCompany": "des company",
        "desPersonName": "LAX",
        "desPhone": "*****6370",
        "dutyValue": 1.01000,
        "englishCargoNames": "工a01",
        "fee": {
            "currency": "MYR",
            "freight": {
                "currency": "MYR",
                "freightFee": 261,
                "totalFee": 261,
                "totalFreightFee": 261,
                "waybillNo": "SF1650005639414"
            },
            "tax": {
                "currency": "MYR",
                "cusCurrencySymbol": "MYR",
                "cusDeptCode": "LHR",
                "cusNetCode": "LHR01Z",
                "customsDate": "2020-02-26T00:00:00",
                "decCode": "12610002511983",
                "decType": "",
                "decValue": 72.65,
                "dutyValue": 1.01,
                "gstValue": 0.01,
                "handlingFee": 0.01,
                "inDate": "2020-10-26T00:00:00",
                "incrementFee": 0.01,
                "interdna": "MY",
                "isAudit": "",
                "mainbNo": "",
                "mawb": "",
                "otherDutyAmount": 0.01,
                "otherFee": 0.01,
                "quarantineFee": 0.01,
                "taxAccount": "",
                "taxPaymentType": 2,
                "taxPerNo": "",
                "totalFee": 1.08,
                "totalFeeVal": 1.08,
                "totalValue": 100,
                "traiffValue": 0.01,
                "wareHouseFee": 0.01,
                "waybillNo": "SF1650005639414"
            },
            "totalFee": 262.08
        },
        "gstValue": 0.01000,
        "handlingFee": 0.01000,
        "inDate": "2020-10-26 00:00:00",
        "incrementFee": 0.01000,
        "interdna": "MY",
        "mainbNo": "",
        "mawb": "",
        "otherDutyAmount": 0.01000,
        "otherFee": 0.01000,
        "paymentCompanies": [
            {
                "code": "SF_Pay",
                "logo": "[BASE64IMG]...",
                "name": "SF Pay",
                "payChannel": "SF_Pay",
                "payChannelLogo":"[BASE64IMG]...",
                "payChannelName": "SF Pay"
            }
        ],
        "phoneStatus": false,
        "quarantineFee": 0.01000,
        "srcCompany": "",
        "srcPersonName": "工Customer Service",
        "srcPhone": "86-*******8000",
        "status": 2,
        "taxPerNo": "",
        "totalFeeVal": 1.08000,
        "totalValue": 100.00000,
        "traiffValue": 0.01000,
        "wareHouseFee": 0.01000
    },
    "msg": "请求成功",
    "success": true
}
```

---

## 13. 獲取稅金支付鏈接 — `IOMS_TAX_PAYMENT_LINK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=174（文檔中心 > API文檔 > 國際件API > 通用 > 獲取稅金支付鏈接；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
獲取稅金支付鏈接
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_TAX_PAYMENT_LINK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | BNo | String |  | 運單號 |
| 2 | paymentCompanyCode | String |  | 支付渠道/公司代碼 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 路由列表 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | qrUrl | String |  | 二維碼地址 |
| 2 | payType | Integer |  | 支付方式 1-二維碼 2-跳轉 |
| 3 | payUrl | String |  | 支付鏈接-跳轉 |

#### 明文請求示例

```
{
"BNo":"SF1650005639414",
"paymentCompanyCode":"SF_Pay"
}
```

#### 明文響應示例

```
{
        "msg": "请求成功",
        "code": 200000,
        "data": {
            "qrUrl": "https://sfpay.sit.sf-express.com/nextpay/sfpay/hhtPayQr?i=2362i28361icmnl25u8l&t=20230602182836&s=4AECDE77021A259AFC10DA06904EDF84",
            "payType": 1,
            "payUrl": ""
        },
        "success": true
}
```

---

## 14. 獲取在線支付短鏈接 — `IOMS_PAYMENT_LINK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=182（文檔中心 > API文檔 > 國際件API > 通用 > 獲取在線支付短鏈接；官方更新 2023-08-17；爬取 2026-09-23）

### 功能描述

```
獲取在線支付短鏈接，此鏈接可以直接提供給客戶支付
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IOMS_PAYMENT_LINK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | BNo | String |  | 運單號 |
| 2 | payer | String |  | 支付方 1:收方,2:寄方 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 路由列表 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | waybillNo | String |  | 主運單號 |
| 2 | payURL | Integer |  | 支付鏈接 |
| 3 | validTime | Long |  | 鏈接有效時間-單位：分鐘 |
| 4 | subList | List\<String> |  | 子運單號集合 |

#### 明文請求示例

```
{
    "BNo":"SF0000018437939",
    "payer":"1"
}
```

#### 明文響應示例

```
{
    "code": 200000,
    "data": {
        "payURL": "http://su.sit.sf-express.com/6BYb",
        "subList": [
            "SF0000018437939"
        ],
        "validTime": 43200,
        "waybillNo": "SF0000018437939"
    },
    "msg": "请求成功",
    "success": true
}
```

---

## 15. 中國海關稅金信息查詢 — `ICSM_CN_TAX_QUERY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=197（文檔中心 > API文檔 > 國際件API > 通用 > 中國海關稅金信息查詢；官方更新 2024-04-08；爬取 2026-09-23）

### 功能描述

```
使用場景: 中國大陸進口 稅金查詢,適用B/C/D/F類稅金 查詢
使用條件: 需順豐客戶經理郵件管理員申請
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICSM_CN_TAX_QUERY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | BNo | String | Y | 運單號 |
| 2 | monthlyCard | String | Y | 運月結卡號號 |
| 3 | phone | String | N | 收方或者寄方手機號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 |  |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | bno | String | 運單號 |
| 2 | cusDeptCode | String | 口岸代碼 |
| 3 | decCode | String | 報關單編碼/報關單號 |
| 4 | currency | String | 稅費幣種,如CNY |
| 5 | taxAccount | String | 稅金結算帳號 |
| 6 | traiffValue | BigDecimal | 關稅 |
| 7 | incrementFee | BigDecimal | 增值稅 |
| 8 | gstValue | BigDecimal | 消費稅 |
| 9 | dutyValue | BigDecimal | 貨物稅 |
| 10 | otherDutyAmount | BigDecimal | 其他稅金 |
| 11 | quarantineFee | BigDecimal | 檢驗檢疫費 |
| 12 | commissionFee | BigDecimal | 委託外貿代理費 |
| 13 | otherFee | BigDecimal | 其它費用 |
| 14 | handlingFee | BigDecimal | 報關手續費 |
| 15 | wareHouseFee | BigDecimal | 報關倉儲費 |
| 16 | totalValue | BigDecimal | 稅金合計 |
| 17 | totalFee | BigDecimal | 費用合計 |
| 18 | totalFeeVal | BigDecimal | 稅費合計 |

#### 明文請求示例

```
{
 "BNo": "SF1600000673866",
 "monthlyCard": "0010005941"
}
```

#### 明文響應示例

```
{
        "msg": null,
        "code": "200000",
        "data": {
            "dutyValue": 0,
            "totalValue": 1566.1600,
            "wareHouseFee": 0,
            "bno": "SF1600000673866",
            "commissionFee": 0,
            "handlingFee": 0,
            "otherFee": null,
            "gstValue": 0.0000,
            "incrementFee": 921.6500,
            "taxAccount": null,
            "toOms": "Y",
            "otherDutyAmount": 0,
            "decCode": null,
            "cusDeptCode": "SZX",
            "totalFee": 0,
            "quarantineFee": 0,
            "traiffValue": 644.5100,
            "currency": "CNY",
            "totalFeeVal": 1566.1600
        },
        "success": true
    }
```

---

## 16. 代理商更新運單 — `ADES_AGENT_UPDATE_WAYBILL`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=211（文檔中心 > API文檔 > 國際件API > 通用 > 代理商更新運單；官方更新 2024-10-14；爬取 2026-09-23）

### 功能描述

```
請聯繫對接人進行審批
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ADES_AGENT_UPDATE_WAYBILL`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | fromSourceSys | String | Y | 來源系統編碼-每個代理給固定值(請聯繫對接人進行申請) |
|  | action | String | Y | 固定值：agentUpdateOrder |
|  | proxyCode | String | Y | 代理標識-每個代理給固定值(請聯繫對接人進行申請) |
|  | sfRefNo | String | Y | 順豐運單號 |
|  | version | String | Y | 版本號固定傳輸v2.0 |
|  | uuid | String | Y | 請求唯一標識-可用後續查詢 |
|  | createTime | Long | Y | 請求數據的時間戳 |
|  | pdata | Dto | Y | 數據實體 |

#### 元素<請求>Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | trackingNo | String | Y | 代理運單號 |
| 2 | carrier | String | Y | 承運商 |
| 3 | waybillNo | String | Y | 順豐運單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
|  | success | Boolean | Y | 請求是否成功 |
|  | code | String | Y | 狀態碼,SUC\_ADES-MANAGE\_00\_0000-成功,其他失敗 |
|  | msg | String | N | 描述信息 |

#### 明文請求示例

```
```

#### 明文響應示例

```
```
