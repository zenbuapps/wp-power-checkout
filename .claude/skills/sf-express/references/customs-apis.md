# 關務接口：清關資料 URL 上傳、9610 申報、發票資訊/檔案

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：要以檔案 URL 方式上傳清關證件（`ICSM_UPLOAD_CERTIFY`；IUOP 另有 base64 版 `IUOP_UPLOAD_CERTIFY` 在 `iuop-order-ops.md`）、中國大陸 9610 跨境電商出口申報（`ICSM_UPLOAD_NSOZ`）時。

邊界：
- `ICSM_INVOICE_INFO_UPLOAD` / `ICSM_INVOICE_FILE_UPLOAD` 官方功能描述為「**待发布**」，欄位含泰文描述 `descriptionThai`，屬泰國關務場景，台灣不適用；保留完整表格供參考。
- 9610 為中國海關監管方式，幣制用中國海關代碼（附錄一：新台幣 `TWD` = 報關單代碼 `143`），只與「大陸出口」有關。

## 目錄

- [1. 關務清關資料上傳 — `ICSM_UPLOAD_CERTIFY`](#1-關務清關資料上傳--icsm_upload_certify)
- [2. 9610申報信息上傳 — `ICSM_UPLOAD_NSOZ`](#2-9610申報信息上傳--icsm_upload_nsoz)
- [3. 發票信息接收 — `ICSM_INVOICE_INFO_UPLOAD`](#3-發票信息接收--icsm_invoice_info_upload)
- [4. 發票文件接收 — `ICSM_INVOICE_FILE_UPLOAD`](#4-發票文件接收--icsm_invoice_file_upload)

---

## 1. 關務清關資料上傳 — `ICSM_UPLOAD_CERTIFY`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=219（文檔中心 > API文檔 > 國際件API > 關務 > 關務清關資料上傳；官方更新 2026-01-19；爬取 2026-09-23）

### 功能描述

```
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICSM_UPLOAD_CERTIFY`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String |  Y |客戶編碼   |
| 2 | sfWaybillNo | String | Y  | 順豐運單號  |
| 3 | files | List\<FileInfo> | Y  |  文件集合 |

#### 元素<請求>FileInfo
| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | certificateImageType | String |N  | 證件圖片類型，1-正面，2-反面  |
| 2 | certificateNo | String | N | 證件號碼  |
| 3 | fileFormat | String | Y | 文件格式，jpg,png,jpeg,tif,pdf  |
| 4 | fileType | String | Y |  文件類型，參考證件類型（見 `dictionaries.md`） |
| 5 | fileUrl | String | Y | 文件下載地址  |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | code | String | Y  | ok或fail，ok表示調用通過，fail表示調用失敗  |
| 2 | data | String|  N |   |
| 3 | msg | String| N  | 失敗原因描述  |
| 4 | success | Boolean|  Y | 請求是否成功  |

#### 明文請求示例

```
{
    "customerCode": "ICRME000SRN93",
    "sfWaybillNo": "SF1390007332561",
    "files": [
        {
            "certificateImageType": "1",
            "certificateNo": "123456789",
            "fileFormat": "jpg",
            "fileType": "001",
            "fileUrl": "url1"
        },
        {
            "certificateImageType": "2",
            "certificateNo": "123456789",
            "fileFormat": "jpg",
            "fileType": "001",
            "fileUrl": "url2"
        }
    ]
}
```

#### 明文響應示例

```
{
    "success": true,
    "code": "ok",
    "msg": null,
    "data": null
}
```

---

## 2. 9610申報信息上傳 — `ICSM_UPLOAD_NSOZ`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=230（文檔中心 > API文檔 > 國際件API > 關務 > 9610申報信息上傳；官方更新 2025-11-18；爬取 2026-09-23）

### 功能描述

```
9610申報信息上傳接口
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICSM_UPLOAD_NSOZ`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | sfWaybillNo | String(32) | Y | 順豐運單號 |
| 2 | bizTime | Long(64) | N | 調用接口時間,毫秒時間戳多次調用時，按這個毫秒時間戳取最新的 |
| 3 | customerOrderNo | String(60) | N | 電商訂單編號,電商平台訂單編號/海外倉訂倉單編號(唯一不重複) |
| 4 | ebpCode | String(18) | N | 電商平台代碼,電商平台的海關注冊登記編號 |
| 5 | ebpName | String(100) | N | 電商平台名稱,電商平台的海關注冊登記名稱 |
| 6 | ebcCode | String(18) | N | 電商企業代碼,電商企業的海關注冊登記編號 |
| 7 | ebcName | String(100) | N | 電商企業名稱,電商企業的海關注冊登記名稱 |
| 8 | ownerCode | String(100) | N | 生產銷售企業代碼	,生產企業的海關注冊登記編號或統一社會信用代碼 |
| 9 | ownerName | String(18) | N | 生產銷售企業名稱,生產企業的登記名稱 |
| 10 | goodsAmount | BigDecimal(19,5) | N | 商品價格,商品實際成交價FOB(不含運雜費)，含非現金抵扣金額 |
| 11 | currency | String(3) | N | 商品價格幣制,海關標準的參數代碼 《JGS-20 海關業務代碼集》- 貨幣代碼可參考附錄-貨幣代碼進行傳值。例如人民幣傳值142 |
| 12 | quantity | Integer(9) | N | 件數,單個運單下包裹數 |
| 13 | freight | BigDecimal(19,5) | N | 運費,商品運輸費用 |
| 14 | freightCurrency | String(3) | N | 運費幣制,海關標準的參數代碼《JGS-20 海關業務代碼集》- 貨幣代碼 |
| 15 | freightFlag | String(1) | N | 運費標誌,1-率，2-單價，3-總價 |
| 16 | premium | BigDecimal(19,5) | N | 保費,物流企業實際收取的商品保價費用 |
| 17 | premiumCurrency | String(3) | N | 保費幣制,海關標準的參數代碼 《JGS-20 海關業務代碼集》- 貨幣代碼 |
| 18 | premiumFlag | String(1) | N | 保費標誌,1-率，2-單價，3-總價 |
| 19 | wrapType | String(1) | N | 包裝種類代碼,海關對進出口貨物實際採用的外部包裝方式的標識代碼，採用1 位數字表示，如：木箱、紙箱、桶裝、散裝、托盤、包、油罐車等 |
| 20 | grossWeight | BigDecimal(19,5) | N | 毛重(公斤),貨物及其包裝材料的重量之和，計量單位為千克 |
| 21 | netWeight | BigDecimal(19,5) | N | 淨重(公斤),貨物的毛重減去外包裝材料後的重量，即貨物本身的實際重量，計量單位為千克 |
| 22 | goodsInfos | List\<Dto> | N | 品名集合 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | itemNo | Integer(5) | Y | 商品序號,從1開始連續序號（與電子訂單商品序號關聯對應） |
| 2 | customGoodsCode | String(20) | Y | 企業商品貨號,企業自定義的商品貨號 |
| 3 | goodsCode | String(10) | Y | 商品編碼,對於符合簡化申報的商品，前4位按稅則歸類申報（需要存在相應章節） |
| 4 | name | String(250) | Y | 商品名稱,同一類商品的名稱。任何一種具體商品可以並只能歸入表中的一個條目 |
| 5 | specifications | String(250) | Y | 規格型號,滿足海關歸類、審價以及監管的要求為準。包括：品名、牌名、規格、型號、成份、含量、等級等 |
| 6 | barCode | String(50) | N | 條形碼,商品條形碼一般由前綴部分、製造廠商代碼、商品代碼和校驗碼組成。 |
| 7 | qty | BigDecimal(19,5) | Y | 申報數量 |
| 8 | unit | String(3) | Y | 申報計量單位,海關標準的參數代碼 《JGS-20 海關業務代碼集》- 計量單位代碼，可參考9610 附錄二-計量單位進行傳值。例如千克傳035 |
| 9 | qtyOne | BigDecimal(19,5) | Y | 法定數量,法定計量單位對應的數量 |
| 10 | qtyTwo | BigDecimal(19,5) | N | 第二數量法定,第二計量單位對應的數量 |
| 11 | unitOne | String(3) | Y | 法定計量單位,與海關hsCode對應的法定計量單位，參考海關標準的參數代碼 《JGS-20 海關業務代碼集》- 計量單位代碼 |
| 12 | unitTwo | String(3) | N | 第二計量單位,與海關hsCode對應的法定第二計量單位，參考海關標準的參數代碼 《JGS-20 海關業務代碼集》- 計量單位代碼 |
| 13 | amount | BigDecimal(19,5) | Y | 商品單價 |
| 14 | totalAmount | BigDecimal(19,5) | Y | 商品總價 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | Y | 請求是否成功 |
| 2 | code | String | N | 業務碼 |
| 3 | msg | String | N | 響應描述 |

#### 明文請求示例

```json
{
    "sfWaybillNo": "SF1350000633820",
    "customerOrderNo": "369687812",
    "ebpCode": "12345",
    "ebpName": "上海落新 ",
    "ebcCode": " 122225",
    "bizTime":1123,
    "ebcName": "上海落新 ",
    "ownerCode": "33621025",
    "ownerName": "生产销售 ",
    "goodsAmount": 1.9,
    "currency": "142",
    "quantity": 11,
    "freight": 33.5,
    "freightCurrency": "142",
    "freightFlag": "3",
    "premium": 1,
    "premiumCurrency": "142",
    "premiumFlag": "3",
    "wrapType": "2 ",
    "grossWeight": 3.5,
    "netWeight": 3.15,
    "goodsInfos": [
        {
            "itemNo": 1,
            "customGoodsCode": "BKR89T",
            "goodsCode": "6912001000",
            "name": " 咖啡杯",
            "specifications": "WEDGWOOD牌/陶器",
            "barCode": "888978466871",
            "qty": 1,
            "unit": "007 ",
            "qtyOne": 0.49,
            "unitOne": "035",
            "qtyTwo": 0.23,
            "unitTwo": "036",
            "amount": 10.15,
            "totalAmount": 10.5
        }
    ]
}
```

#### 明文響應示例

```json
{
    "msg":"",
    "success":true
}
```

### 9610 附錄

#### 9610 附錄一：貨幣代碼

| **貨幣代碼** | **中文名稱** | **原報關單代碼** | **原報檢代碼** |
| ---- | ---- | ------ | ----- |
| NZD | 新西蘭元 | 609 | 554 |
| AUD | 澳大利亞元 | 601 | 36 |
| USD | 美元 | 502 | 840 |
| CAD | 加拿大元 | 501 | 124 |
| RUB | 俄羅斯盧布 | 344 | 643 |
| CHF | 瑞士法郎 | 331 | 756 |
| SEK | 瑞典克朗 | 330 | 752 |
| NOK | 挪威克朗 | 326 | 578 |
| GBP | 英鎊 | 303 | 826 |
| DKK | 丹麥克朗 | 302 | 208 |
| EUR | 歐元 | 300 | 910 |
| TWD | 新台幣 | 143 | 158 |
| CNY | 人民幣 | 142 | 156 |
| THB | 泰國銖 | 136 | 764 |
| KRW | 韓國圓 | 133 | 410 |
| SGD | 新加坡元 | 132 | 702 |
| PHP | 菲律賓比索 | 129 | 608 |
| MYR | 馬來西亞林吉特 | 122 | 458 |
| MOP | 澳門元 | 121 | 446 |
| JPY | 日本元 | 116 | 392 |
| IDR | 印度尼西亞盧比 | 112 | 360 |
| HKD | 港幣 | 110 | 344 |

#### 9610 附錄二：計量單位

| 單位名稱 | 單位編碼 |
| ---- | ---- |
| 台 | 001 |
| 座 | 002 |
| 輛 | 003 |
| 艘 | 004 |
| 架 | 005 |
| 套 | 006 |
| 個 | 007 |
| 只 | 008 |
| 頭 | 009 |
| 張 | 010 |
| 件 | 011 |
| 支 | 012 |
| 枝 | 013 |
| 根 | 014 |
| 條 | 015 |
| 把 | 016 |
| 塊 | 017 |
| 卷 | 018 |
| 副 | 019 |
| 片 | 020 |
| 組 | 021 |
| 份 | 022 |
| 幅 | 023 |
| 雙 | 025 |
| 對 | 026 |
| 棵 | 027 |
| 株 | 028 |
| 井 | 029 |
| 米 | 030 |
| 盤 | 031 |
| 平方米 | 032 |
| 立方米 | 033 |
| 筒 | 034 |
| 千克 | 035 |
| 克 | 036 |
| 盆 | 037 |
| 萬個 | 038 |
| 具 | 039 |
| 百副 | 040 |
| 百支 | 041 |
| 百把 | 042 |
| 百個 | 043 |
| 百片 | 044 |
| 刀 | 045 |
| 疋 | 046 |
| 公擔 | 047 |
| 扇 | 048 |
| 百枝 | 049 |
| 千隻 | 050 |
| 千塊 | 051 |
| 千盒 | 052 |
| 千枝 | 053 |
| 千個 | 054 |
| 億支 | 055 |
| 億個 | 056 |
| 萬套 | 057 |
| 千張 | 058 |
| 萬張 | 059 |
| 千伏安 | 060 |
| 千瓦 | 061 |
| 千瓦時 | 062 |
| 千升 | 063 |
| 英尺 | 067 |
| 噸 | 070 |
| 長噸 | 071 |
| 短噸 | 072 |
| 司馬擔 | 073 |
| 司馬斤 | 074 |
| 斤 | 075 |
| 磅 | 076 |
| 擔 | 077 |
| 英擔 | 078 |
| 短擔 | 079 |
| 兩 | 080 |
| 市擔 | 081 |
| 盎司 | 083 |
| 克拉 | 084 |
| 市尺 | 085 |
| 碼 | 086 |
| 英寸 | 088 |
| 寸 | 089 |
| 升 | 095 |
| 毫升 | 096 |
| 英加侖 | 097 |
| 美加侖 | 098 |
| 立方英尺 | 099 |
| 立方尺 | 101 |
| 平方碼 | 110 |
| 平方英尺 | 111 |
| 平方尺 | 112 |
| 英制馬力 | 115 |
| 公制馬力 | 116 |
| 令 | 118 |
| 箱 | 120 |
| 批 | 121 |
| 罐 | 122 |
| 桶 | 123 |
| 扎 | 124 |
| 包 | 125 |
| 籮 | 126 |
| 打 | 127 |
| 筐 | 128 |
| 羅 | 129 |
| 匹 | 130 |
| 冊 | 131 |
| 本 | 132 |
| 發 | 133 |
| 枚 | 134 |
| 捆 | 135 |
| 袋 | 136 |
| 粒 | 139 |
| 盒 | 140 |
| 合 | 141 |
| 瓶 | 142 |
| 千支 | 143 |
| 萬雙 | 144 |
| 萬粒 | 145 |
| 千粒 | 146 |
| 千米 | 147 |
| 千英尺 | 148 |
| 百萬貝可 | 149 |
| 部 | 163 |
| 億株 | 164 |

---

## 3. 發票信息接收 — `ICSM_INVOICE_INFO_UPLOAD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=215（文檔中心 > API文檔 > 國際件API > 關務 > 發票信息接收；官方更新 2024-12-10；爬取 2026-09-23）

### 功能描述

```
待發布
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICSM_INVOICE_INFO_UPLOAD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | dataSource | String(20) | Y | 數據來源，如BYD |
| 2 | status | String(2) | Y | 上傳動作，N-新增，R-更新，C-刪除<br>N= New<br>R = Revise---只改表頭，不改表體，做整體更新<br>C = Cancel---刪整個單 |
| 3 | bno | String(20) | Y | SF運單號 |
| 4 | GUID | String(30) | N | 接口每次交互的流水號 |
| 5 | invNo | String(35) | Y | 發票號 |
| 6 | invDate | String(8) | Y | 發票日期，格式：YYYYMMDD |
| 7 | orderNo | String(35) | N | 採購訂單號 |
| 8 | incoTerm | String(35) | Y | 貿易條款，根據實際情況填寫， 按照 UNECE Recommendation 17 標準 |
| 9 | paymentTerm | String | Y | 付款條件 |
| 10 | loading | String(35) | N | 啟運港 |
| 11 | dicharge | String(35) | N | 目的地港 |
| 12 | mark | String(600) | N | 嘜頭（收貨人相關信息、品牌、航班、公司名稱等相關信息） |
| 13 | category | String(30) | N | 分類<br>1、進口：local parts,plant transfer,sea import,air import,Re-import<br>2、出口：local parts,plant transfer,sea export,air export,Re-export |
| 14 | shipper | Shipper | Y | 寄方對象 |
| 15 | consignee | Consignee | Y | 收方對象 |
| 16 | goods | List\<Goods> | Y | 商品對象集合 |

#### 元素 Shipper

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | Name | String(70) | Y | 發貨人英文名稱 |
| 2 | Address | String(512) | Y | 發貨人地址 |
| 3 | taxId | String(13) | N | 出口方稅號，出口申報時此字段必填 |

#### 元素 Consignee

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | Name | String(70) | Y | 進口方英文名稱 |
| 2 | Address | String(512) | Y | 進口方地址 |
| 3 | taxId | String(13) | N | 進口方稅號，進口申報時此字段必填 |

#### 元素 Goods

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | description | String(512) | Y | 英文商品描述 |
| 2 | descriptionThai | String(512) | Y | 泰文商品描述 |
| 3 | brand | String(35) | Y | 品牌 |
| 4 | partNo | String(35) | Y | 型號/工廠料號 |
| 5 | qty | Integer | Y | 申報數量 |
| 6 | qtyUnit | String(3) | Y | 申報數量單位 |
| 7 | uPrice | BigDecimel(17,6) | Y | 單價 |
| 8 | amount | BigDecimel(17,6) | Y | 總價 |
| 9 | currency | String(3) | Y | 幣種，按照ISO 4217 (RFCUC)標準 |
| 10 | weight | BigDecimel(17,6) | Y | 淨重 |
| 11 | weightUnit | String(3) | Y | 淨重單位，英文三字碼 |
| 12 | grossWeight | BigDecimel(17,6) | Y | 毛重 |
| 13 | grossWeightUnit | String(3) | Y | 毛重單位 |
| 14 | origin | String(2) | Y | 原產國，取ISO 3166 (RFICC) 二字碼 |
| 15 | hsCode | String(15) | N | 商品編碼 |
| 16 | privilege | String(3) | N | 貿易類型 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 狀態碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto | 否 |  |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | GUID | String(30) | 接口請求流水號 |
| 2 | bno | String(20) | 運單號 |
| 3 | invNo | String | 發票號 |
| 4 | timestamp | String(15) | 時間戳，YYYYMMDDThhmmss |

#### 明文請求示例

```
```

#### 明文響應示例

```
```

---

## 4. 發票文件接收 — `ICSM_INVOICE_FILE_UPLOAD`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=216（文檔中心 > API文檔 > 國際件API > 關務 > 發票文件接收；官方更新 2024-12-10；爬取 2026-09-23）

### 功能描述

```
待發布
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `ICSM_INVOICE_FILE_UPLOAD`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | bno | String(20) | Y | SF運單號 |
| 2 | GUID | String(30) | N | 請求流水號 |
| 3 | invNo | String(35) | Y | 【進口計劃】-表頭“清關發票號”,新增字段，必填，【進口台帳】表頭需要同步顯示該字段信息 |
| 4 | docType | String(2) | Y | 0 = Invoice/Packing |
| 5 | payloadContent | String | Y | 以ZIP格式將文件壓縮後Base64編碼 |
| 6 | payloadFileName | String(70) | Y | 以清關發票號命名，類型是other |
| 7 | status | String(2) | Y | N = New<br/>R = Revise<br/>C = Cancel |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 狀態碼|
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | Dto| 否 |  |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | GUID| String(30)| 接口請求流水號 |
| 2 | bno| String(20)| 運單號 |
| 3 | invNo| String| 發票號 |
| 4 | timestamp| String(15)| 時間戳，YYYYMMDDThhmmss |

#### 明文請求示例

```
```

#### 明文響應示例

```
```
