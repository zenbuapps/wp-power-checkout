# 路由（貨態）：查詢、訂閱、推送 webhook、opCode 對照表

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：要查貨態、接收順豐主動推送的軌跡、驗證推送 `sign`、或把 `opCode` 映射成自家出貨狀態時。

重點：
- 官方建議**以推送為主、查詢為輔**（`GTS_QUERY_TRACK` 頁原文）。`GTS_QUERY_TRACK` 一次最多 10 個單號，且要帶下單手機號**後四碼**。
- 另有舊版 `IUOP_QUERY_TRACK`（第 84 頁，**不在左側選單**，由 portal 首頁卡片「查詢軌跡 › 國際快遞」連入）：只需 `customerCode` + `sfWaybillNo`、**不需手機後四碼**、一次查一單、每客戶每分鐘 ≤ 600 次；2026-09-23 沙箱實測仍可用。兩者對照見本檔「補充：IUOP_QUERY_TRACK 與 GTS_QUERY_TRACK 對照」。
- 推送要 email 客戶經理 + 抄送 `open_intl@sf-express.com` 申請聯調，**不是控制台自助設定**；按運單號訂閱另外呼叫 `GTS_COM_ROUTE_SUBSCRIBE`（需配合推送接口）。
- 推送 body 是**明文 JSON array**（不加密），header `sign` = `Base64(MD5(key + body + key))`，key 聯調時由順豐提供。
- 推送路由描述語言：快遞 IUOP 支援中文 / 英文 / **繁體中文** / 日文；電商 IECS 只有英文。訂閱接口 `trackType=4` 為繁體中文路由。
- 需開防火牆白名單的推送來源 IP 見下方「運單軌跡推送」節。
- 接收端 PHP 範例見 `php-client.md`。

## 目錄

- [1. 路由查詢 — `GTS_QUERY_TRACK`](#1-路由查詢--gts_query_track)
- [2. 路由軌跡查詢（舊版，首頁入口） — `IUOP_QUERY_TRACK`](#2-路由軌跡查詢舊版首頁入口--iuop_query_track)
- [3. 通用路由訂閱 — `GTS_COM_ROUTE_SUBSCRIBE`](#3-通用路由訂閱--gts_com_route_subscribe)
- [4. 運單軌跡推送](#4-運單軌跡推送)
- [附錄 14：路由操作碼](#附錄-14路由操作碼)
- [補充：IUOP_QUERY_TRACK 與 GTS_QUERY_TRACK 對照](#補充iuop_query_track-與-gts_query_track-對照)
- [補充：路由查詢業務碼（英文版頁面，2025-11-24）](#補充路由查詢業務碼英文版頁面2025-11-24)
- [補充：opCode 映射建議（非官方）](#補充opcode-映射建議非官方)

---

## 1. 路由查詢 — `GTS_QUERY_TRACK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=145（文檔中心 > API文檔 > 國際件API > 通用 > 路由查詢；官方更新 2026-07-15；爬取 2026-09-23）

### 功能描述

請先通過對接路由消息推送（見 `tracking.md`）方式獲取路由數據，此接口僅用作路由推送的補充操作。

```
本接口API主要查詢國際路由信息，同時校驗數據準確性，並提供相應的請求安全校驗功能客戶可通過此接口查詢順豐運單路由，順豐返回當時點要求的全部路由節點信息。
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `GTS_QUERY_TRACK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| # | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | billNoType | String | 否 | 1.順豐運單號 2.代理運單號，默認為1<br>注：兩種單號皆可查詢全程軌跡 |
| 2 | sfWaybillNoList | List\<String> | 是 | 運單號，一次查詢最多支持10個單號 |
| 3 | phoneNo | String | 是 | 手機號後四位（下單時使用的手機號mobile） |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<Dto> | 否 | 路由列表 |

#### 元素Dto

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 運單號 |
| 2 | code | String | 否 |
| 3 | msg | String | 否 |
| 4 | trackDetailItems | List\<DtoInfo> | 路由詳情 |

#### 元素DtoInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | opCode | String | 操作碼 |
| 2 | reasonCode | String | SF國際原因代碼 |
| 3 | sfWaybillNo | String | 順豐運單號 |
| 4 | trackCountry | String | 軌跡發生國家 |
| 5 | trackRegionFirst | String | 軌跡發生一級區劃 州/省 |
| 6 | trackRegionSecond | String | 軌跡發生二級區劃 城市 |
| 7 | trackAddr | String | 軌跡發生詳細地址 |
| 8 | trackPostCode | String | 軌跡發生郵編 |
| 9 | localTm | String | 軌跡發生時間（當地時間）<br> 格式:yyyy-MM-dd HH:mm:ss |
| 10 | gmt | String | 標準時區格式：<br> GMT+/- 08:00 or GMT+/-08:30 |
| 11 | trackOutRemark | String | 路由軌跡對外描述 |
| 12 | extendAttach4 | String | 1：轉寄 <br>2：退回<br>3：簽收後轉寄<br>4：簽收後退回 |

#### 明文請求示例

```
{
    "sfWaybillNoList":["SF6042021596800"],
    "phoneNo":"3046"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": [
        {
            "code": "0",
            "msg": "success",
            "sfWaybillNo": "SF6042021596800",
            "trackDetailItems": [
                {
                    "gmt": "GMT+08:00",
                    "localTm": "2022-11-21 08:04:00",
                    "opCode": "31",
                    "reasonCode": "",
                    "sfWaybillNo": "SF6042021596800",
                    "trackAddr": "Dongguan International Small Bag Operation Center",
                    "trackCountry": "CN",
                    "trackOutRemark": "【Dongguan International Small Bag Operation Center,DongGuan,Guangdongsheng,CN】Shipmen has arrived at the station and unloading.",
                    "trackPostCode": "",
                    "trackRegionFirst": "Guangdongsheng",
                    "trackRegionSecond": "DongGuan"
                },
                {
                    "gmt": "GMT+08:00",
                    "localTm": "2022-11-21 08:04:00",
                    "opCode": "208",
                    "reasonCode": "",
                    "sfWaybillNo": "SF6042021596800",
                    "trackAddr": "Dongguan International Small Bag Operation Center",
                    "trackCountry": "CN",
                    "trackOutRemark": "【Dongguan International Small Bag Operation Center,DongGuan,Guangdongsheng,CN】Shipment in transit. Thanks for your waiting.",
                    "trackPostCode": "",
                    "trackRegionFirst": "Guangdongsheng",
                    "trackRegionSecond": "DongGuan"
                }
            ]
        }
    ],
    "msg": "success",
    "success": true
}
```

### 業務碼

| **業務碼** | **描述** |
| --- | --- |
| 1004 | Query No. exceeds the maximum limit:maxNum=10，actualNum=20 |
| 1004 | The verification of mandatory parameters is lost |
| 1005 | Phone verification failed |
| 300001 | Tracking number is required |
| 320001 | This tracking number is invalid or non SF-international |
| 999 | System request processing exception |

---

## 2. 路由軌跡查詢（舊版，首頁入口） — `IUOP_QUERY_TRACK`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=84（文檔中心 > API文檔 > 國際件API > 路由軌跡查詢（首頁卡片「查詢軌跡 › 國際快遞」連入，不在左側選單）；官方更新 2023-12-20；爬取 2026-09-23）

> **本 skill 註**：本頁**不在 portal 左側選單**，是從 portal 首頁卡片「查詢軌跡 › 國際快遞」連入（`secondMenu=84`）。2026-09-23 以沙箱實測仍可呼叫（見本檔「補充：IUOP_QUERY_TRACK 與 GTS_QUERY_TRACK 對照」）。

### 功能描述

```
客戶可通過此接口查詢順豐運單路由，會在響應JSON報文返回當時點要求的全部路由節點信息。
此路由查詢接口支持下面方式：
根據順豐運單號查詢：查詢請求中提供接入編碼與運單號，將驗證接入編碼與所有請求運單號的歸屬關係，系統只返回具有正確歸屬關係的運單路由信息
追蹤路由接口限制每個客戶每分鐘調用量不能超過600次，如需擴大調用量，請聯繫銷售人員申請
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `IUOP_QUERY_TRACK`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | customerCode | String(50) | 是 | 客戶編碼 |
| 2 | version | String(10) | 否 | 版本號（默認為空即可） |
| 3 | requestId | String(64) | 否 | 請求id（可使用UUID，確保請求唯一性字段，只要確保每個請求的requestId唯一即可） |
| 4 | sfWaybillNo | String(32) | 是 | 順豐運單號 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | success | boolean | 是 | 請求是否成功 |
| 2 | code | String | 否 | 業務碼 |
| 3 | msg | String | 否 | 響應描述 |
| 4 | data | List\<TrackInfo> | 否 | 業務接口響應數據 |

#### 元素TrackInfo

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | sfWaybillNo | String | 運單號 |
| 2 | trackDetailItems | List\<TrackInfoDetail> | 路由信息 |
| 3 | trackSummary | String | 錯誤提示 |

#### 元素TrackInfoDetail

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | opCode | String | 操作碼，參考附錄路由操作碼（見 `tracking.md`） |
| 2 | opCodeTranslation | String | SF國際路由操作碼描述 |
| 3 | sfWaybillNo | String | 順豐運單號(母單號) |
| 4 | trackCountry | String | 軌跡發生國家/地區 |
| 5 | trackRegionFirst | String | 軌跡發生一級區劃（州/省） |
| 6 | trackRegionSecond | String | 軌跡發生二級區劃（城市） |
| 7 | localTm | String | 軌跡發生時間（當地時間）; 格式:yyyy-MM-dd HH:mm:ss |
| 8 | gmt | String | 軌跡發生地所在時區 格式：GMT+08:00或GMT-08:00或GMT+00:00 |
| 9 | trackOutRemark | String | 路由軌跡對外描述 |

#### 明文請求示例

```
{
"customerCode":"ICRME000SRN93",
"sfWaybillNo":"SF1334453289970"
}
```

#### 明文響應示例

```
{
    "code": "0",
    "data": [
        {
            "sfWaybillNo": "SF1334453289970",
            "trackDetailItems": [
                {
                    "gmt": "GMT+08:00",
                    "localTm": "2022-07-27 19:57:31",
                    "opCode": "80",
                    "opCodeTranslation": "派件扫描",
                    "sfWaybillNo": "SF1334453289970",
                    "trackCountry": "CN",
                    "trackOutRemark": "您的快件已被签收。签收人。感谢使用顺丰,期待再次为您服务。",
                    "trackRegionFirst": "",
                    "trackRegionSecond": ""
                },
                {
                    "gmt": "GMT+08:00",
                    "localTm": "2022-07-27 19:57:22",
                    "opCode": "50",
                    "opCodeTranslation": "一票一件",
                    "sfWaybillNo": "SF1334453289970",
                    "trackCountry": "CN",
                    "trackOutRemark": "顺丰速运已收取快件",
                    "trackRegionFirst": "",
                    "trackRegionSecond": ""
                }
            ],
            "trackSummary": ""
        }
    ],
    "msg": "执行成功",
    "success": true
}
```

---

## 3. 通用路由訂閱 — `GTS_COM_ROUTE_SUBSCRIBE`

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=225（文檔中心 > API文檔 > 國際件API > 通用 > 通用路由訂閱；官方更新 2026-07-08；爬取 2026-09-23）

### 功能描述

```
本接口仅用于按运单号进行订阅推送，需配合路由推送接口同步使用，不能单独使用。请同步对接运单轨迹推送接口，发起路由订阅后，接收顺丰推送该运单的轨迹。
如需按账号推送，请直接对接路由推送接口https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=92，由技术进行配置
```

### 請求地址

- `https://api-ifsp.sf.global/openapi/api/dispatch`
- `http://api-ifsp-sit.sf.global/openapi/api/dispatch`
（POST，body 為 AES 密文；見 `auth-and-crypto.md`）

### 公共頭參數

公共 header 同 `auth-and-crypto.md` §業務接口（appKey / token / timestamp / nonce / signature / lang）；本接口 `msgType` = `GTS_COM_ROUTE_SUBSCRIBE`。

### 請求參數（請以密文形式傳輸，加密方法參考：加解密樣例代碼（見 `auth-and-crypto.md`））

#### 元素<請求Body>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | callbackType | String | Y | 推送路由類型  URL  不填默認 URL |
| 2 | isFilterInfer | String | Y | 是否過濾推斷數據，<br>0：不過濾<br> 1：過濾（推斷數據：系統根據路由關係自動推送路由節點）<br>不填默認 0 |
| 3 | outFlag | String | N | 對外數據標識 <br>ALL.訂閱全部數據<br>Y.只訂閱對外展示數據<br>不傳默認Y |
| 4 | phoneNo | String | Y | 手機號後四位 |
| 5 | sfWaybillNo | String | Y | 運單號 |
| 6 | subscribeSysCode | String | Y | 訂閱系統編碼 |
| 7 | trackType | String | Y | 路由類型， <br>1.無描述翻譯路由（無路由描述），<br>2.中文路由，<br>3.英文路由  <br>4.繁體中文路由 5.日本路由 <br>不填默認 3 |

### 響應參數

#### 元素<響應data>

| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | code | String | Y | 業務碼 |
| 2 | data | String | N | 響應描述 |
| 3 | msg | String | N | 訂閱結果描述 |
| 4 | success | null | Y | 請求是否成功 |

#### 明文請求示例

```
{
    "subscribeSysCode": "sysCode",
    "phoneNo": "3569",
    "sfWaybillNo": "SF1030414729508",
    "callbackType": "URL",
    "isFilterInfer": "0",
    "trackType": "3",
    "outFlag": "Y"
}
```

#### 明文響應示例

```
{
        "msg": "success",
        "code": "0",
        "data": "运单订阅请求已受理",
        "success": true
    }
```

---

## 4. 運單軌跡推送

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=92（文檔中心 > API文檔 > 國際件API > 消息推送 > 運單軌跡推送；官方更新 2026-07-30；爬取 2026-09-23）

#### 對接說明

此功能由順豐相關係統主動發起把數據推送給客戶，客戶只需提供接口接收數據即可。
每當運單狀態有更新時，系統會自動推送最新運單狀態。
對接此功能時，請先按照本文檔完成接收端的接口開發，確保接收端返回的響應報文格式正確。
然後把以下信息發送至對接客戶經理郵箱並抄送給open\_intl@sf-express.com，主題為“XXX公司運單軌跡推送測試聯調”
客戶經理依據收到的郵箱內容提交鏈接：[軌跡推送信息收集](https://sf-alidocs.dingtalk.com/notable/share/form/v011X3lE5jA5gDz6lJb_D8OLxmF_6u8Lnfo?source=link) （內部鏈接）會自動通知工程師進行測試聯調溝通，測試聯調通過後方可上線生產環境。郵件發送請先聯繫對接的順豐客戶經理。

| 需提供信息 | 說明 |
| ----- | --- |
| 客戶公司名稱 | 公司正式名稱，最好附上英文簡稱 |
| 順豐月結卡號 |
| 生產環境下單帳號客戶編號 | 登陸IUOP後，"管理中心" - "基本信息" 中查看，只有使用此客戶編碼下單才會推送路由 |
| 業務類型 | 根據使用的物流產品填寫 “電商IECS” 或 "快遞IUOP", 參考附錄國際產品映射碼（見 `dictionaries.md`） |
| 測試環境推送地址 |
| 生產環境推送地址 |
| 希望接收的路由描述的語言 | 快遞業務：目前支持中文、英文、繁體中文、日文；電商業務：當前只支持英文 |
| 測試環境運單號（可選） | 運單號由順豐提供，將用於測試推送，快遞IUOP默認推送50、30、31、80四個節點，電商IECS默認推送950、30、31、80四個節點 |

#### 請求方法類型

POST

#### 請求協議支持

HTTP/HTTPS

#### 推送使用IP地址

若接收推送的服務器有防火牆，請允許以下IP訪問

| # | IP地址 |
| --- | ---- |
| 測試環境 | 113.105.119.22<br>59.36.221.255<br>101.52.239.143<br>101.52.239.128<br>218.201.242.128 |
| 生產環境 | 218.18.161.92<br>14.215.129.51<br>123.58.48.251<br>218.17.130.245<br>123.58.40.245<br>113.46.221.102<br>139.159.152.152<br>139.159.199.61<br>1.12.231.26<br>193.112.151.155<br>159.75.122.214 |

#### 推送頭參數

| # | 屬性名 | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | Content-Type | String | 固定值：application/json |
| 2 | sign | String | 報文簽名，用於校驗接收到的body報文是否遭到篡改。<br>客戶可自行選擇是否增加該校驗，為了避免篡改攻擊，建議校驗。<br>簽名生成邏輯參考下方說明。 |

sign計算邏輯以下供參考，可使用postman demo校驗簽名是否生成正確

#### 【JAVA】

```java
/** 
* @data: 推送的body报文
* @keys: 测试联调时提供，联调通过后提供生成环境key
* */

private String sign(String data, String keys) {
    String secret = "";
    data = keys + data + keys;
    try {
        MessageDigest md = MessageDigest.getInstance("MD5"); 
        md.update(data.getBytes(StandardCharsets.UTF_8));
        secret = new String(Base64.encodeBase64(md.digest()), StandardCharsets.UTF_8);
    } catch (Exception e) {
        log.error("sign生成失败e={}",e);
    }
    return secret;
}
```

#### 【Track Pushing Demo for Postman】

另存為json文件，在Postman中導入

```json
{"info":{"_postman_id":"8e8c38b3-3111-4170-acab-2c2a9fd1daf7","name":"GTS Track Push Demo","schema":"https://schema.getpostman.com/json/collection/v2.1.0/collection.json"},"item":[{"name":"GTS_track_pushing","event":[{"listen":"prerequest","script":{"exec":["//fill in your gtskey\r","let gtskey = \"your_gtskey_here\";\r","console.log(`info: gtskey=${gtskey}`);\r","\r","var data = pm.request.body.raw;\r","if (!data) {\r","    data = \"\";\r","}\r","var message = gtskey + data + gtskey;\r","console.log(`info: message=${message}`);\r","\r","var sign = CryptoJS.enc.Base64.stringify(CryptoJS.MD5(message));\r","console.log(`info: sign=${sign}`);\r","pm.environment.set(\"sign\", sign);\r",""],"type":"text/javascript"}}],"request":{"method":"POST","header":[{"key":"sign","value":"{{sign}}","type":"default"}],"body":{"mode":"raw","raw":"[{\"opCodeTranslation\":\"Out for delivery\",\"trackCountry\":\"SG\",\"trackUniqueId\":\"c1eba6fb9a5bf1144114a6cacdb39822\",\"trackRemark\":\"【SG Expo business station Singapore Expo Business Department,Singapore,SG】Out for delivery\",\"trackAddr\":\"SG Expo business station Singapore Expo Business Department\",\"trackPostCode\":\"\",\"trackCity\":\"Singapore\",\"localTm\":\"2022-08-22 08:35:38\",\"gmt\":\"GMT+08:00\",\"reasonCodeTranslation\":\"\",\"trackProvince\":\"\",\"opCode\":\"204\",\"reasonCode\":\"\",\"sfWaybillNo\":\"SF1600000000000\"}]","options":{"raw":{"language":"json"}}},"url":{"raw":"http://localhost/fill_in_your_url_to_call","protocol":"http","host":["localhost"],"path":["fill_in_your_url_to_call"]}},"response":[]}]}
```

#### body信息說明

1、推送的數據以json list的方式推送；
2、一次請求報文中，會存在不同運單的軌跡，且軌跡數據是無序的；
3、每次推送最大不超過20條軌跡數據。

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | trackUniqueId | String | 當前此路由的唯一Id |
| 2 | opCode | String | SF國際路由操作碼，參考附錄路由操作碼（見 `tracking.md`） |
| 3 | opCodeTranslation | String | SF國際路由操作碼描述 |
| 4 | reasonCode | String | 國際原因代碼 |
| 5 | reasonCodeTranslation | String | 國際原因代碼描述 |
| 6 | sfWaybillNo | String | 順豐運單號 |
| 7 | trackCountry | String | 軌跡發生國家/地區 |
| 8 | trackProvince | String | 軌跡發生州/省 |
| 9 | trackCity | String | 軌跡發生城市 |
| 10 | trackAddr | String | 軌跡發生詳細地址 |
| 11 | trackPostCode | String | 軌跡發生郵編 |
| 12 | localTm | String | 軌跡發生時間（當地時間）; 格式:yyyy-MM-dd HH:mm:ss |
| 13 | gmt | String | 軌跡發生地所在時區 格式：GMT+08:00或GMT-08:30或GMT+00:00 |
| 14 | trackRemark | String | 路由軌跡對外描述 |
| 15 | otherInfo| String | 僅當操作碼=99時，返回轉寄退回原因枚舉值，枚舉值如下：1：轉寄，2：返回，3：部分攔截，45：簽收後轉寄，46：簽收後退回 |

推送body示例：

```
[
    {
        "gmt": "GMT+08:00",
        "localTm": "2019-12-09 16:48:34",
        "opCode": "30",
        "opCodeTranslation": "装车",
        "reasonCode": "",
        "reasonCodeTranslation": "",
        "sfWaybillNo": "885204935082",
        "trackAddr": "",
        "trackCity": "",
        "trackCountry": "KR",
        "trackPostCode": "",
        "trackProvince": "",
        "trackRemark": "快件在【香港新界青衣中转场】已装车,准备发往下一站",
        "trackUniqueId": "2ef21f6613c3119392f27ba294d04277"
    },
    {
        "gmt": "GMT+08:00",
        "localTm": "2019-12-09 17:48:34",
        "opCode": "70",
        "opCodeTranslation": "问题件",
        "reasonCode": "7",
        "reasonCodeTranslation": "经客户同意，更改收派时间",
        "sfWaybillNo": "885204935082",
        "trackAddr": "",
        "trackCity": "",
        "trackCountry": "HK",
        "trackPostCode": "",
        "trackProvince": "",
        "trackRemark": "快件派送不成功(已与收方客户约定新派送时间2019-12-10 12:56:46),待再次派送",
        "trackUniqueId": "be9580769dbe16e878a8c37a7d768784"
    }
]
```

#### 響應報文說明

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | code | String | 0:全部成功或部分成功， 1：全部失敗 |
| 2 | msg | String | 全部失敗的原因描述，注：全部成功或部分成功可為空 |
| 3 | data | List\<Object> | 全部成功或部分成功具體每條的返回報文 |

data字段說明

| **#** | **屬性名** | **類型（約束）** | **描述** |
| --- | --- | ------ | --- |
| 1 | code | String | 0:成功 1：失敗 |
| 2 | msg | String | 失敗或成功的原因描述，注：成功可為空 |
| 3 | trackUniqueId | String | 當前路由的唯一id, 推送報文中存在 |
| 4 | sfWaybillNo | String | 順豐單號 |

全部失敗情況：

```
{
"code":"1",
"msg":"接收失败，sign不对",
"data":null
}
```

部分路由失敗或全部成功：

```
{
    "code": "0",
    "data": [
        {
            "code": "1",
            "msg": "查无此单",
            "trackUniqueId": "2ef21f6613c3119392f27ba294d04277"
        },
        {
            "code": "0",
            "msg": "成功",
            "trackUniqueId": "be9580769dbe16e878a8c37a7d768784"
        }
    ],
    "msg": "成功"
}
```

```
{
    "code": "0",
    "data": [
        {
            "code": "0",
            "msg": "成功",
            "trackUniqueId": "2ef21f6613c3119392f27ba294d04277"
        },
        {
            "code": "0",
            "msg": "成功",
            "trackUniqueId": "be9580769dbe16e878a8c37a7d768784"
        }
    ],
    "msg": "成功"
}
```

---

## 附錄 14：路由操作碼

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=132（文檔中心 > API文檔 > 附錄 > 14.路由操作碼；官方更新 2024-01-18；爬取 2026-09-23）

| 操作碼(opCode) | 中文說明 | Description |
| ----------- | ---- | ----------- |
| 50 | 順豐已收件 | Mainwaybill Pickup |
| 51 | 一票多件的子件 | Subwaybill |
| 54 | 上門收件 | Shipment collected |
| 30 | 快件在【XXX】已裝車,準備發往 【XXX】 | Vehicle Load |
| 31 | 快件到達 【XXX】 | Vehicle Unload |
| 302 | 車輛發車 | Vehicle leave |
| 304 | 離開經停點 | Leave the stop |
| 33 | 滯留件入倉 | Detained shipments inbound |
| 34 | 滯留件出倉 | Detained shipments outbound |
| 36 | 封車操作 | Vehicle Seal |
| 10 | 辦事處發車/中轉發車/海關發車/機場發貨 | Agent Flight departure |
| 105 | 航空起飛 | Flight departure scan |
| 106 | 航空落地 | Flight arrival scan |
| 11 | 辦事處到車/中轉到車/海關到車/機場提貨 | Agent Flight arrival |
| 122 | 加時區域派件出倉 | Delivery in progress; require additional delivery time |
| 123 | 快件正送往順豐店/站 | Out for CVS |
| 125 | 快遞員派件至豐巢 | Shipment delivered into HIVE BOX |
| 126 | 快遞員取消派件將快件取出豐巢 | Delivery cancelled and shipment retreieved from SF Locker |
| 130 | 快件到達順豐店/站 | CVS handover |
| 135 | 信息記錄 | Record |
| 14 | 貨件已放行 | Customs Release |
| 140 | 國際件特殊通知 | International special announcement |
| 15 | 海關查驗 | Customs Inspection |
| 16 | 正式報關待申報 | Formal Clearance |
| 17 | 海關待查 | Wait for Customs |
| 18 | 海關扣件 | Customs Hold |
| 201 | 準備揀貨 | Order picking |
| 202 | 出庫 | Warehouse out |
| 205 | 倉庫內操作-訂單審核 | Warehouse Operation-Order Review |
| 206 | 倉庫內操作-揀貨 | Warehouse Operation-goods picking |
| 208 | 代理交接 | Agent Handover |
| 405 | 船舶離港 | Ship departed |
| 406 | 船舶到港 | Ship arrived |
| 41 | 交收件聯(二程接駁收件) | Collect original Waybill copy |
| 43 | 收件入倉 | Received at origin location |
| 46 | 二程接駁收件 | On-road Tfr pickup shipment |
| 47 | 二程接駁派件 | On-road Tfr - deliv. shpt |
| 604 | CFS清關 | CFS Clearance |
| 605 | 運力抵達口岸 | Conveyances Arriving Customs gateway |
| 606 | 清關完成 | customs clearance |
| 607 | 代理收件 | Agent Handover-Pickup |
| 611 | 理貨異常 | Check Exceptional |
| 612 | 暫存口岸待申報 | Brokerage Hold |
| 613 | 海關放行待補稅 | Customs released |
| 614 | 清關時效延長 | Customs Clearance Delay |
| 619 | 檢疫查驗 | Quarantine |
| 620 | 檢疫待查 | Pending for Quarantine |
| 621 | 檢疫扣件 | Detain for Quarantine |
| 623 | 海關數據放行 | Customs Data Release |
| 626 | 到轉第三方快遞 | Forward to third party |
| 627 | 寄轉第三方快遞 | Forward Third Express |
| 630 | 落地配派件出倉 | LDP Out for delivery |
| 631 | 新單退回 | Returned by ＜new AWB No.＞ |
| 632 | 儲物櫃交接 | Deliver to the locker |
| 634 | 港澳台二程接駁派件 | On-road Tfr - deliv. shpt |
| 64 | 晨配轉出 | Transit-out CHENPEI |
| 642 | 門市/順豐站快件上架 | Retail Parcel Racking |
| 643 | 門市/順豐站快件轉移 | Retail Parcel Transfer |
| 646 | 包裝完成 | Shipment ready for leaving the warehouse |
| 647 | 寄方準備快件中 | Preparing shipment at shipper's facility |
| 648 | 快件已退回/轉寄,新單號為: XXX | New-old Waybill Relevance |
| 649 | 代理轉運 | In transit |
| 65 | 晨配轉入 | Transit-in CHENPEI |
| 651 | SLC已攬件 | Picked up by SLC |
| 655 | 合作點收件 | Received by SF Authorized partner |
| 656 | 合作點交接給順豐 | Cooperation Handover To SF |
| 657 | 合作點從順豐交接 | Cooperation Handover From SF |
| 658 | 合作點已派件 | Cooperation Deliveried |
| 66 | 中轉批量滯留 | Batch on hold |
| 660 | 合作點退件給客戶 | Cooperation Bounce To Customer |
| 664 | 客戶接觸點交接 | Customer contact point handover |
| 676 | 順PHONE車 | SF CAR |
| 677 | 順手派 | SF LINK |
| 678 | 快件到達驛站 | Temperature Control Box Associated |
| 679 | 驛站完成派件 | Materials Recovery Shipment |
| 70 | 異常件 | Exception |
| 700 | 拍照收件 | Mainwaybill(Photo POD) |
| 701 | 一票多件拍照收件 | Subwaybill(Photo POD) |
| 75 | 混建包複核 | Mixed Bagging checking |
| 77 | 中轉滯留 | On hold at sort facility |
| 830 | 出庫 | Warehouse out |
| 831 | 入庫 | Arrived At Facility |
| 833 | 滯留件入倉 | Detained shipments inbound after delivered failure |
| 84 | 重量複核 | Re-weigh |
| 86 | 晨配裝車 | Vehicle load by external resources |
| 87 | 晨配卸車 | Vehicle unload by external resources |
| 880 | 上門派件 | Delivery to the door |
| 921 | 晨配在途 | CHENPEI In-transit |
| 930 | 外配裝車 | Matching external loading |
| 931 | 外配卸車 | Matching external unloading |
| 932 | 外配交接 | Matching external handover |
| 933 | 外配出倉 | Matching external out of warehouse |
| 934 | 外配異常 | Matching external abnormal |
| 935 | 外配簽收 | Matching external delivered |
| 950 | 快速收件 | SF e-parcel pick-up |
| 98 | 代理路由信息 | Agent Tracking Info |
| 99 | 應客戶要求,快件正在轉寄/退回中，通過在reasonCode中加以區分：1:轉寄;2:退回;45:簽收後轉寄;46:簽收後退回 | Forward/Return |
| 44 | 正在派送途中,請您準備簽收(派件人:XXX,電話:XXX) | Out for delivery |
| 204 | 派件責任交接 | Out for delivery |
| 80 | 已簽收,感謝使用順豐,期待再次為您服務 | Delivered |
| 8000 | 在官網"運單資料&簽收圖",可查看簽收人信 | Yellow copy data entry |
| 845 | 快件交接 | Shipment Handover |
| EXCHECKIN | 貨物已到達順豐轉運中心 | The item has been arrival the transport center of SF. |
| EXCHECKOUT | 貨物已離開順豐轉運中心 | The item has been departure the transport center of SF. |
| SF002 | 代理下單 | Shipment information received from agent |
| SF003 | 滯留件入倉 | Detained shipment in warehouse |
| SF004 | 滯留件入倉 | Detained shipment in warehouse |
| G01 | 發往目的國家 | Delivery to destination country |
| G02 | 到達目的國家 | Arrived at destination country |
| G03 | 清關失敗 | The customs clearance is failed. |
| G04 | 到目的地郵局 | Arrived at the place of delivery |
| G05 | 預計到達目的國家 | Expected to arrive at the destination country |
| G06 | 正發往自提點 | Shipment transfers to SF appointed self-pickup location |
| G07 | 待自取 | Self pickup at an agreed place is confirmed with the consignee and the shipment is to be picked up. |
| G08 | 到達分揀中心 | Arrived at sorting centre |
| G09 | 離開分揀中心 | Leaving sorting centre |
| G10 | 交航成功 | Successful delivery to airlines |
| G21 | 操作中心簽入 | The shipment is checked in at the Distribution Center |
| G22 | 操作中心簽出 | The shipment is checked out at the Distribution Center |
| G26 | 訂單信息已收到 | Order information has been received |
| G30 | 操作中心裝車 | Loading in operation center |
| G31 | 操作中心卸車 | Unloading in operation center |
| 924 | 第三方路由 | third code |
| 951 | 一票多件的子件 | Subwaybill |
| G34 | 報關開始 | 報關開始 |
| G35 | 抵達卡轉機場 | 抵達卡轉機場 |
| G36 | 清關開始 | 清關開始 |
| G37 | 已訂艙 | 已訂艙 |
| G38 | 制單完成 | 制單完成 |
| G99 | 海外退回 | Returned shipments |

---

## 補充：IUOP_QUERY_TRACK 與 GTS_QUERY_TRACK 對照

| 項目 | `IUOP_QUERY_TRACK`（第 84 頁，首頁入口） | `GTS_QUERY_TRACK`（第 145 頁，選單「通用 › 路由查詢」） |
|---|---|---|
| 入口 | 不在左側選單；首頁卡片「查詢軌跡 › 國際快遞」 | 左側選單 |
| 官方更新 | 2023-12-20 | 2026-07-15 |
| 驗證方式 | `customerCode` 與運單號歸屬關係（只回有正確歸屬的運單） | 下單手機號**後四碼** `phoneNo` |
| 一次查詢量 | **1 單**（`sfWaybillNo` String）。[實測 2026-09-23] 用逗號串接（`"SF1334453289970,SF1334456901862"`）或傳陣列，皆回該筆 `trackSummary: "請輸入一個有效的順豐運單號"`，不支援批次 | **≤ 10 單**（`sfWaybillNoList`）；可用 `billNoType=2` 以代理單號查 |
| 限流 | 每客戶每分鐘 ≤ 600 次（超過需找銷售申請） | 英文版業務碼 5006：每分鐘次數上限（數字未公開） |
| 回應明細 | `TrackInfo{sfWaybillNo, trackDetailItems, trackSummary}`；明細含 `opCodeTranslation`，**無** `reasonCode` / `trackAddr` / `trackPostCode` | `{sfWaybillNo, code, msg, trackDetailItems}`；明細含 `reasonCode`、`trackAddr`、`trackPostCode`、`extendAttach4`（轉寄 / 退回） |
| 單號錯誤時 | 整體 `success=true`，該單 `trackDetailItems=[]`、錯誤放 `trackSummary`（實測） | 外層或該單 `code` 為 320001 等 |
| 建議 | 自家系統只存 customerCode、不存收件手機時可用；**官方選單已不列出，視為舊版**，新開發以推送 + `GTS_QUERY_TRACK` 為主 | 官方現行查詢接口（推送的補充） |

[實測 2026-09-23，公開沙箱憑證，`lang: zh-HK`] 請求：

```json
{"customerCode":"ICRME000SRN93","sfWaybillNo":"SF1334453289970"}
```

回應（解密後）：

```json
{"msg":"執行成功","code":"0","data":[{"trackDetailItems":[],"trackSummary":"訂單不存在或訂單已歸檔","sfWaybillNo":"SF1334453289970"}],"success":true}
```

→ 接口存在且可呼叫；沙箱無此單的軌跡資料，錯誤訊息放在 `trackSummary`，必須逐單檢查，不能只看 `success`。

---

## 補充：路由查詢業務碼（英文版頁面，2025-11-24）

中文版（2026-07-15 更新）列出的業務碼為 `1004`、`1005`、`300001`、`320001`、`999`（見上方路由查詢節）。英文版（`...secondMenu=145&lang=en`，2025-11-24）另列了以下代碼，兩版**不一致**，實作時兩組都要能處理：

| business code | describe |
|---|---|
| 1003 | request data is empty |
| 1003 | Sign Verification failed |
| 1003 | Illegal SF order No |
| 1004 | Query No. exceeds the maximum limit:maxNum=10，actualNum=20 |
| 1004 | This order has no permission to query the track |
| 1005 | Data verification failed |
| 4001 | Not associated with system ID |
| 5001 | The query order number set is empty |
| 5006 | The collection of query order numbers refers to frequent interface requests, which cannot exceed XXX times in a minute |
| 999 | System request processing exception |

---

## 補充：opCode 映射建議（非官方）

以下為依官方 opCode 說明文字整理的**建議**映射，非官方文件，上線前請以真實推送資料校準；未知 opCode 必須 fallback，不可丟例外（附錄明列 G01–G99、EXCHECKIN 等非數字碼）。

| 自家狀態 | 可觸發的 opCode（依官方說明） |
|---|---|
| 已攬收 | `50` 顺丰已收件、`54` 上门收件、`43` 收件入仓、`607` 代理收件、`655` 合作点收件、`950` 快速收件、`651` SLC已揽件 |
| 運送中 | `30` 装车、`31` 到达、`10`/`11` 办事处/中转发车到车、`105` 航空起飞、`106` 航空落地、`208` 代理交接、`649` 代理转运、`G01`/`G02` |
| 清關中 / 清關異常 | `14` 货件已放行、`15` 海关查验、`16`、`17`、`18` 海关扣件、`604`、`606` 清关完成、`612`、`613`、`614`、`619`–`621`、`G03` 清关失败、`G34`/`G36` |
| 派送中 | `44` 正在派送途中、`204` 派件责任交接、`880` 上门派件、`634` 港澳台二程接驳派件、`630` 落地配派件出仓 |
| 待自取 / 到店 | `130` 快件到达顺丰店/站、`123`、`G06`、`G07` 待自取、`678` 快件到达驿站 |
| 已簽收 | `80` 已签收、`8000`、`679` 驿站完成派件、`658` 合作点已派件、`935` 外配签收 |
| 異常 | `70` 异常件（看 `reasonCode`）、`77` 中转滞留、`33` 滞留件入仓、`934` 外配异常 |
| 轉寄 / 退回 | `99`（`reasonCode` / `otherInfo`：1 轉寄、2 退回、3 部分攔截、45 簽收後轉寄、46 簽收後退回）、`631`、`648`、`G99` 海外退回 |
