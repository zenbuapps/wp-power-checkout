# 台灣寄件 / 台灣收件 / 兩岸件 規則彙整

> 本檔把散落在官方 portal 各頁（建單、附錄、錯誤碼表、FAQ）中與台灣有關的規則集中整理，每條標注出處頁（`secondMenu` id，URL 為 `https://openapi-portal.sf.global/document?firstMenu=API&secondMenu={id}`）。標 **[實測]** 者為 2026-09-23 以官方公開沙箱憑證唯讀查詢所得；標 **[待確認]** 者官方文件未明寫，不可當成規格。

**何時讀這份**：要決定台灣相關訂單走哪個產品 / 哪個 API、欄位該怎麼填（幣種、郵編、區劃、證件、統編、件種、報關方式）、或被 `124080`、`124149`、`124082`、`123037` 等錯誤擋下時。

## 目錄

- [1. 台灣在順豐國際開放平台的定位](#1-台灣在順豐國際開放平台的定位)
- [2. 流向 × API × 產品對照](#2-流向--api--產品對照)
  - [2.1 台灣島內件（TW→TW）沙箱實測](#21-台灣島內件twtw沙箱實測)
  - [2.2 PRE_ORDER 校驗實測（TW→TW）](#22-pre_order-校驗實測twtw)
- [3. 帳號、月結卡號與權限](#3-帳號月結卡號與權限)
- [4. 欄位填寫規則（台灣相關）](#4-欄位填寫規則台灣相關)
- [5. 報關 / 清關 / 稅金](#5-報關--清關--稅金)
- [6. 路由、推送與語言](#6-路由推送與語言)
- [7. 會擋下台灣訂單的錯誤碼](#7-會擋下台灣訂單的錯誤碼)
- [8. 待確認事項](#8-待確認事項)

---

## 1. 台灣在順豐國際開放平台的定位

- 國家 / 地區二字碼 **`TW`**，官方名稱「中国台湾」（附錄 5，id 63）。港 `HK`、澳 `MO`、大陸 `CN`（「中国内地」）。
- 台灣相關件在本平台一律屬 **「國際件 API」**：快遞走 **IUOP**（`IUOP_*`），電商小包走 **IECS**（`IECS_*`，通常只 CN / HK 始發）。
- **「海外本地件 API」（`IUOP_OS_*`）在 2026-09-23 爬取版 portal 僅列馬來西亞 / 印尼 / 越南**（id 194、202、201），不含台灣。
- **台灣島內件（TW→TW）：文件沒有台灣島內件說明。** 但 [實測 2026-09-23] 沙箱 `IPE_QUERY_PRODUCT_FREIGHT` 查 TW 10048 → TW 80141 有回傳 13 個產品（見 §2.1）。**正式環境能否下 TW→TW 單、該用哪個產品，要先向順豐台灣客戶經理確認，確認前不要上線**；島內宅配預設走 `tcat-logistics` / `hct-logistics` / `payuni-logistics-v3`。
- 取 IUOP 帳號：官方說「港澳台地區及海外客戶可使用 `https://iuop.sf-express.com` 或 `https://iuop.sf.global/`」（id 141、172）。
- [實測] `ICMS_QUERY_COUNTRY_LIST {countryCode:"TW"}`：`currencyCode=NTD`、`phoneCode=886`、`timeZone=Asia/Taipei`、`customsCode=143`（中國海關國別碼）、`numberCode=158`、`threeCode=TWN`、`weightUnit=KG`。

## 2. 流向 × API × 產品對照

| 流向 | 建單 API | 常見產品（附錄 2，id 61） | 備註 |
|---|---|---|---|
| **台灣 → 大陸**（兩岸，TW 出口 / CN 進口） | `IUOP_CREATE_ORDER` | `INT0007`/`INT0008` 國際標快（收件國為 CN 時官方指定用標快，不用標快+）、`INT0013`/`INT0014` 國際特惠、`INT0253` 國際大件 | 收方為大陸個人物品需身分證（`123102`），證件為身份證時收方姓名須中文（`124219`）；大陸進口稅金可用 `ICSM_CN_TAX_QUERY`（需申請） |
| **大陸 → 台灣**（兩岸，CN 出口 / TW 進口） | 快遞：`IUOP_CREATE_ORDER`；電商小包：`IECS_CREATE_ORDER` | 快遞：TW 不在「國際標快」收件國清單 → 依規則用 `INT0137`/`INT0139` 國際標快+、或國際特惠；電商：`INT0255`/`INT0257`/`INT0263`/`INT0054` 等（IECS 通常僅 CN/HK 始發） | **收方 `certType` + `certCardNo` 必填**（跨境件收件國為 TW）；大陸出口可能需 `customsInfo.customsExtInfoMap`（生產銷售單位，證件類型固定 `007` 統一社會信用代碼）與 9610 申報（`ICSM_UPLOAD_NSOZ`） |
| **台灣 → 香港 / 澳門 / 海外** | `IUOP_CREATE_ORDER` | 收件國在 `CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN` → 國際標快 `INT0007/0008`；其餘（含 HK、MO、US、EU…）→ 國際標快+ `INT0137/0139`；另有國際特惠 / 大件 / 重貨 | **寄方 `cargoType` 必填**（TW 始發）；日本收件地址禁含「公司 / Co.Ltd」（`123020/123021`）；歐盟需 IOSS 等 |
| **香港 / 海外 → 台灣** | `IUOP_CREATE_ORDER` | 依始發地產品 | 收方證件必填（同上） |
| 台灣島內（TW→TW） | 文件無說明；產品推薦接口有回 TW→TW 產品 | 沙箱推薦含 `INT0005` 順豐特快、`INT0008`、附錄 2 未列的 `INT0202` Taiwan E-commerce 等（§2.1） | **正式可否下單待確認**，確認前不要上線 |

產品選擇原則（官方 FAQ id 127 + 附錄 2）：
- 先跟客戶經理確認簽約產品；「電商IECS」產品一律用 `IECS_CREATE_ORDER`，「快遞IUOP」產品用 `IUOP_CREATE_ORDER`，兩者 `msgType` 不同。`INT0054` 國際小包、`INT0263` 國際電商專遞-CD：CN / HK 始發用 IECS，**其餘流向（含台灣始發）用 IUOP**。
- 用 `IPE_QUERY_PRODUCT_LIST`（帳號可用產品清單）+ `IPE_QUERY_PRODUCT_FREIGHT`（依始發 / 目的國與重量推薦產品、運費、時效；港澳台國碼寫 `HK`/`MO`/`TW`）動態取得，不要寫死。
- `INT0005` 顺丰特快、`INT0011` 顺丰标快、`INT0214` 顺丰特惠(D类)、`INT0006` 顺丰特快(D类)、`INT0077` 顺丰卡航、`INT0657` 香港本地急送 等產品在附錄只列名稱，**適用流向官方未說明**。[實測 2026-09-23] 沙箱 `INT0005` 出現在 TW→TW 推薦結果（§2.1），也出現在 TW→CN（時效 2026-09-26 12:00、132 NTD）與 TW→HK（2026-09-25 18:00、121 NTD），**非島內專屬**；正式環境適用流向仍 **[待確認]**。

### 2.1 台灣島內件（TW→TW）沙箱實測

文件沒有台灣島內件說明；以下是 2026-09-23 用官方公開沙箱憑證對 `IPE_QUERY_PRODUCT_FREIGHT`（產品運費時效推薦，唯讀）的實測結果，**不是規格**，沙箱資料可能是假資料。

請求（明文 body，`msgType: IPE_QUERY_PRODUCT_FREIGHT`、`lang: zh-HK`；欄位定義見 `common-apis.md`）：

```json
{"actualWeight":1,"currency":"NTD","customerType":2,"declaredValue":100,"operateType":1,"parcelNum":1,"serialNumber":"tw-test-1790144338","sourceCityCode":"886","sourceCountry":"TW","sourcePostcode":"10048","destCityCode":"886","destCountry":"TW","destPostcode":"80141"}
```

PHP（`php-client.md` 的 `SfGlobalClient`）：

```php
$r = $sf->dispatch('IPE_QUERY_PRODUCT_FREIGHT', [
    'actualWeight' => 1, 'currency' => 'NTD', 'customerType' => 2, 'declaredValue' => 100,
    'operateType' => 1, 'parcelNum' => 1, 'serialNumber' => wp_generate_uuid4(),
    'sourceCityCode' => '886', 'sourceCountry' => 'TW', 'sourcePostcode' => '10048',
    'destCityCode' => '886', 'destCountry' => 'TW', 'destPostcode' => '80141',
]);
// $r['data']['products'][i]['product']['interProductCode'] / ['timePromise'] / ['calculate']['freight']
```

回傳 13 個產品（`issues: []`）：

| interProductCode | productNameChild | timePromise | freight |
|---|---|---|---|
| `INT0096` | Alcohol Delivery | 2026-09-24 18:00 | 8 NTD |
| `INT0005` | SF Speedy Express（順豐特快） | 2026-09-24 18:00（隔日 18:00 前） | 23 NTD |
| `INT0001` | EC-Ship | — | — |
| `INT0007` | SF Standard Express (International)（DOC） | — | — |
| `INT0137` | Global Express+(DOC) | — | — |
| `INT0008` | SF Standard Express(International)(Package) | — | 16 NTD |
| `INT0139` | Global Express+(Package) | — | — |
| `INT0013` | Int'l Doc-Economy | — | — |
| `INT0672` | 国际小包挂号（productNameChildExtend「测试尝试」） | — | — |
| `INT0054` | SFE_RAM（E-Commerce Registered Parcel-Standard） | — | — |
| `INT0014` | Int'l Parcel – Economy (Type B) | — | — |
| **`INT0202`** | **Taiwan E-commerce**（附錄 2 未列） | — | — |
| `INT0754` | T1-3（expressTypeCode B4） | — | — |

判讀：
- 推薦接口對 TW→TW 有回產品，且 `INT0096` / `INT0005` 帶時效與運費，但沙箱含明顯測試資料（`INT0672`「测试尝试」），**不能據此認定正式環境可下 TW→TW 單**。
- `INT0202` Taiwan E-commerce 不在附錄 2 產品映射碼表中，用途、業務類型（IUOP / IECS）、能否下單皆未知。沙箱回傳的產品明細：

  ```json
  {"productSeriesCode":"SE0001","interProductCode":"INT0202","productCode":"SE0001","productNameChild":"Taiwan E-commerce","expressTypeCode":"B1","limitTypeCode":"T4","cargoTypeCode":"Z108","productCodeChild":"TW-ECOM","productNameChildExtend":"Taiwan E-commerce","productNo":""}
  ```

  → `productCodeChild: TW-ECOM`（產品代碼 L3）、`cargoTypeCode: Z108`（快件內容代碼）、`productSeriesCode: SE0001`（主產品代碼 L1）；欄位語意取自 `IPE_QUERY_PRODUCT_FREIGHT` 的 ProductDto 表（`common-apis.md`），代碼值本身的含義官方未說明。
- `INT0005` 不是島內專屬：同一請求改目的地後，TW→CN 回 6 個產品（`INT0096`,`INT0005`,`INT0001`,`INT0008`,`INT0263`,`INT0014`；`INT0005` 時效 2026-09-26 12:00、132 NTD），TW→HK 回 2 個產品（`INT0005`,`INT0014`；`INT0005` 時效 2026-09-25 18:00、121 NTD）。
- 正式導入前：向順豐台灣客戶經理確認 TW→TW 是否開放、簽約產品碼與 msgType，並以正式帳號呼叫 `IPE_QUERY_PRODUCT_LIST` 核對。

### 2.2 PRE_ORDER 校驗實測（TW→TW）

**為何可以呼叫**：`IUOP_PRE_ORDER` 官方頁（`iuop-order-variants.md` 第 1 節）功能描述，英文版為「**Verify shipment info**」，中文版為「预创建快递订单,校验订单信息」。回應欄位只有 `success` / `code` / `msg` / `data.errorDtoList`（`validateModule=1` 時的校驗錯誤明細），**沒有 `sfWaybillNo`、`labelUrl` 等運單欄位**，回應範例也只有 `{"code":"0","msg":"执行成功","success":true}`。中文「预创建」是否會在順豐端留下預建紀錄，文件沒有說明；因此只在公開沙箱呼叫，並使用 `SKILLTEST-PRE-` 開頭的測試訂單號。

**測試條件**（2026-09-23）：
- 環境：公開沙箱 `https://api-ifsp-sit.sf.global`，全程只呼叫 `IUOP_PRE_ORDER`。
- 帳號：`customerCode=ICRME000SRN93`，月結卡號 `9999999999`（`payMethod=1` 運費寄方付；`taxPayMethod=2` 稅金收方付）。
- header：`lang: zh-HK`；body 帶 `validateModule=1`。
- 共同欄位：
  - `parcelQuantity=1`、`parcelTotalWeight=0.5 KG`、`parcelVolumeUnit=CM`、`declaredCurrency=NTD`、`pickupType=0`。
  - `parcelInfoList`：`name 茶葉 / eName Oolong tea / unit box / amount 500 / quantity 1 / originCountry TW / weight 0.5`。
- 寄件方：TW `10048` `Taipei City` / `Zhongzheng District`，`phoneAreaCode 886`，`cargoType 1`，`certType 001`。
- 台灣收件方：TW `80652` `Kaohsiung City` / `Qianzhen District`，`886`，`cargoType 1`，`certType 001` + `certCardNo`。
- 證件號碼都是格式範例值，不是真人資料。

| 組 | 流向 × 產品 | 請求重點 | 重試 | `apiResultCode` | 解密後回應（原文） |
|---|---|---|---|---|---|
| **A** | TW→TW × `INT0005` 順豐特快 | 上述共同欄位 | 0（首次即過） | `0` | `{"msg":"Executed successfully","code":"0","success":true}` |
| **B** | TW→TW × `INT0202` Taiwan E-commerce | 同 A，只換產品 | 0 | `0` | `{"msg":"Executed successfully","code":"0","success":true}` |
| **C**（對照） | TW→HK × `INT0014` 國際特惠（包裹）（沙箱 `IPE_QUERY_PRODUCT_FREIGHT` 對 TW→HK 推薦的產品之一） | 收件方 HK `000000` `Hong Kong` / `Kowloon`，`852`，無證件 | 0 | `0` | `{"msg":"Executed successfully","code":"0","success":true}` |
| D（反向對照） | TW→TW × 不存在的產品 `INT9999` | 同 A，只換產品 | — | `0` | `{"msg":"Mapping code of international product is wrong.","code":"123248","data":{"errorDtoList":[{"code":"123248","message":"Mapping code of international product is wrong."}]},"success":false}` |
| E（反向對照） | TW→TW × `INT0005`，收件方**不帶證件** | `certType` / `certCardNo` 留空 | — | `0` | `{"msg":"Executed successfully","code":"0","success":true}` |
| F（反向對照） | TW→TW × `INT0005`，收件郵編**只給 3 碼** `806` | 其餘同 A | — | `0` | `{"msg":"Executed successfully","code":"0","success":true}` |
| G（反向對照） | HK→TW × `INT0014`，台灣收件方不帶證件 | 寄件方換 HK，`declaredCurrency=HKD` | — | `0` | `{"msg":"This line has not yet opened this product. If you have any questions, please contact your customer manager.","code":"123296","data":{"errorDtoList":[{"code":"123296","message":"This line has not yet opened this product. If you have any questions, please contact your customer manager."}]},"success":false}` |
| H（反向對照） | TW→TW × `INT0657` 香港本地急送 | 同 A，只換產品 | — | `0` | 同 G：`code 123296`「This line has not yet opened this product…」 |

判讀（保守）：
- **沙箱 PRE_ORDER 對 TW→TW + `INT0005`、TW→TW + `INT0202` 都回 `code 0`（Executed successfully）。**
  - 這個校驗確實會擋「線路 × 產品未開通」：H 的 TW→TW + `INT0657` 回 `123296`，D 的不存在產品回 `123248`。
  - 所以 A / B 通過，表示**沙箱帳號 `ICRME000SRN93`** 的 TW→TW + `INT0005` / `INT0202` 線路產品校驗有通過。
- **沙箱結果不代表正式環境已開通。** 正式環境能否下 TW→TW、該用哪個產品，仍需向順豐台灣客戶經理確認，確認前不要上線。
  - PRE_ORDER 只是校驗，沒有取得運單號，也沒有驗證取件、派送與計費。
- 沙箱 PRE_ORDER **不擋**以下兩種情況，不能拿它取代本地欄位檢查：
  - 3 碼郵編（F）。對照：`ICMS_QUERY_REGION_BY_POST_CODE` 對 3 碼郵編會回錯。
  - TW→TW 收件方缺證件（E）。官方寫的證件必填條件是「跨境件」收件國為 TW，島內件不在此列。
- 跨境件收件證件是否由 PRE_ORDER 強制檢查，本次未驗到：G 先被 `123296` 擋下，即使 `validateModule=1`（並行校驗）也只回這一條。
- `lang: zh-HK` 時，PRE_ORDER 回的是**英文**訊息（`Executed successfully`），和其他接口回繁體不同。
- `123296` 不在官方「國際快遞常見異常響應」表內，是本次沙箱實測才看到的業務碼。

## 3. 帳號、月結卡號與權限

| 項目 | 規則 | 出處 |
|---|---|---|
| 客戶編碼 `customerCode` | IUOP 登入 → 管理中心 → 基本信息 → 客戶編號；IUOP 類 body 必填 | id 172 |
| 月結卡號 | 需由**地區銷售在 ICRM 後台綁定**到 IUOP 帳號後才能用（IUOP 首頁可見）；未綁定 → `123058` | id 172、169 |
| 付款方式 `paymentInfo.payMethod` | 1 寄方付 / 2 收方付 / 3 第三方付；月結付款時填 `payMonthCard`，現金付款留空 | id 77 |
| 到付限制 | 有些流向不支援收方付 → `123033`；流向運費付款方式未配置 → `127014` | id 169 |
| 稅金 `taxPayMethod` | 通常**收方現金支付**（2）；寄付 / 第三方付須填 `taxPayMonthCard` 並**另簽稅金結算協議（DDP，中國大陸始發除外）**，否則 `123014` | id 77、127、169 |
| COD 代收貨款 | 增值服務 `IN01/COD`：`value` = 貨款（**以到件地幣種**，BigDecimal(16,6)）、`valueOne` = 月結卡號或協議號；月結帳號未開通 COD 權限 → `129013`；流向不支援 → `129010` | 附錄 6（id 64）、id 169 |
| 保價 | `IN02/SPP`：`value` = 保價金額，幣種同申報幣種且 ≤ 總申報價值（`129022`） | 附錄 6、id 169 |
| 產品權限 | 無權限 → `123156` / `141005` / `141006`（白名單產品，找客戶經理） | id 169 |
| 預估總費用 `IUOP_ESTIMATE_FEE` | 需額外申請開通（嚴格權限） | id 129 |
| 推送 | email 客戶經理 + `open_intl@sf-express.com` 聯調，非自助 | id 92 |

## 4. 欄位填寫規則（台灣相關）

| 欄位 | 台灣規則 | 出處 |
|---|---|---|
| `senderInfo.country` / `receiverInfo.country` | `TW` | 附錄 5 |
| `senderInfo.cargoType` | **CN / TW / KR 始發必填**：1 個人件、2 公司件（缺 → `123057`） | id 77、169 |
| `receiverInfo.certType` + `certCardNo` | **跨境件收件國為 ID / TW / KR 時必填**（預創建頁寫 ID,TW）。證件類型碼見附錄 7 | id 77、210 |
| 證件類型（附錄 7） | `006` 台灣通行證（適用台灣）、`009` 統編號（適用台灣）、`001` 身份證、`002` 護照、`023` 居留證 | id 65 |
| 公司件統編 | `124080` 寄件方統編號必填、`124149` 收件方統編號必填 → 公司件要帶 `certType=009` + `certCardNo=統一編號` **[對應關係依錯誤碼推論，待確認哪些流向觸發]** | id 169 |
| 個人收件人 | `124082` 收件方身份證號、護照或居留證至少填一項 → `001` / `002` / `023` **[台灣身分證字號是否用 `001`，官方未明寫]** | id 169 |
| `declaredCurrency` | 用 SF 幣種表代碼：新台幣 **`NTD`**（表中沒有 `TWD`）。[實測] `IUOP_QUERY_FREIGHT` 傳 `currency:"TWD"` 回應仍為 `NTD` | 附錄 4（id 62） |
| `postCode` | [實測] 台灣郵編規則 `^[0-9]{5,6}$`（3+2 / 3+3 碼）；3 碼郵遞區號會被拒（`邮编示例格式12345`） | 實測 |
| `regionFirst` / `regionSecond` / `regionThird` | [實測] `regionFirst` = 縣市英文（`Taipei City`、`Kaohsiung City`…）、`regionSecond` = 區英文（`Zhongzheng District`、`Qianzhen District`）、`regionThird` 留空。以 `ICMS_QUERY_REGION_BY_POST_CODE`（countryCode=TW + 5/6 碼郵編）取得標準值 | 實測、address-region.md |
| `address` | 詳細地址（String 200）。部分流向要求英文（`124118` 寄件人詳細地址需英文、`124119` 收件人詳細地址需英文）；中文可放 `receiverInfo.addressI18n` / `contactI18n` 或 `localizedInfo.localZhTW` **[哪些流向強制英文未明列]** | id 77、169、229 |
| 電話 | `phoneAreaCode` / `telAreaCode` = `886`（純數字，不可 `+886`、`00886`）；手機、固話至少填一個（`124095`/`124096`）；[實測] `ICMS_PHONE_VALID_CHECK` 對 `886` + `0912345678` 回 OK | id 77、205 |
| `pickupAppointTimeZone` | 上門取件時區用 `Asia/Taipei`（附錄 8 時區表） | id 66、77 |
| `parcelVolumeUnit` | 台灣始發用 `CM`（只有 CA / US 用 INCH） | id 77 |
| `ParcelInfo.originCountry` | 原產地二字碼（台灣製 = `TW`）必填 | id 77 |
| `ParcelInfo.name` | 品名禁止含 `_`（`123036`）、不可純數字（`126010`）、純中文 ≥ 2 字、純英文 > 2 字（`126090`/`126091`）；模糊 / 違禁品名直接擋（`126043`）。可先用 `SOP_COLLECT_SHIP_STANDARD` 檢查（港澳台國碼用 3 位數字，台灣推定 `886` [待確認]） | id 169、212 |
| `localizedInfo` | 繁體中文 key 為 `localZhTW`（欄位：receiverName / receiverAddress / senderName / senderAddress） | 附錄 16（id 229） |

## 5. 報關 / 清關 / 稅金

- 出口報關方式 `customsInfo.exportCustomsType`（附錄 10）：**`5` 簡易-銷售（台灣出口用）**、**`6` 簡易-樣品（台灣出口用）**；另有 `1` 簡易報關、`2` 正式報關、`4` 個人物品、`7` 跨境直郵、`10` 自清關、`11` 跨境直郵-自主申報等。
- 寄件原因 `customsInfo.senderReason`：1 商業 / 2 非商業 / 5 禮物 / 6 銷售 / 7 樣品 / 8 維修 / 12 其它。
- 貿易條件 `tradeCondition`（附錄 11）：`CFR`/`CIF`/`CIP`/`CPT`/`DDP`/`DPU`/`EXW`/`FAS`/`FCA`/`FOB`/`DAP`。
- 清關資料：建單回應 `identityUploadUrl`（身分證上傳頁）；或 API `IUOP_REQUIRE_CERTIFY`（查需要哪些證件）→ `IUOP_UPLOAD_CERTIFY`（base64，PNG/JPG/PDF，單檔 < 3MB，身分證需正反兩張）/ `ICSM_UPLOAD_CERTIFY`（檔案 URL）；`IUOP_VALIDITY_CERTIFY` 查證件是否已在關務系統有效。缺資料 → `123121`。
- 報關資料類型（附錄 12）含 `501` 商業發票、`504` 裝箱單、`505` 報關委託書、`517` 個案委任書、`518` 販賣製造許可證書、`354` 進口發票等（完整表見 `dictionaries.md`）。
- 稅金：`IOMS_TAX_PAYMENT`（稅金資訊與支付渠道）、`IOMS_TAX_PAYMENT_LINK`、`IOMS_PAYMENT_LINK`（線上支付短連結）見 `common-apis.md`。

## 6. 路由、推送與語言

- dispatch header `lang`：`zh-HK` = 繁體中文（**沒有 `zh-TW`**）。[實測] `lang: zh-HK` 時業務錯誤訊息回繁體（例 `123002` → 「訂單不存在或訂單已歸檔」）；但部分區劃接口訊息仍為簡體（`邮编示例格式12345`）。
- 路由推送語言：快遞 IUOP 支援**繁體中文**（聯調時指定）；電商 IECS 只有英文。
- 按單訂閱 `GTS_COM_ROUTE_SUBSCRIBE`：`trackType=4` = 繁體中文路由。
- 路由查詢 `GTS_QUERY_TRACK` 需要下單手機號**後四碼** `phoneNo`；舊版 `IUOP_QUERY_TRACK`（第 84 頁，不在選單）只需 `customerCode` + `sfWaybillNo`、一次一單（對照見 `tracking.md`）。
- opCode `634` = 港澳台二程接驳派件（港澳台特有派送節點）。

## 7. 會擋下台灣訂單的錯誤碼

| 錯誤碼 | 訊息（簡） | 處理 |
|---|---|---|
| 123037 | 此条线路暂未开通服务 | 產品 × 始發 × 目的不支援；改產品或問客戶經理 |
| 123296 | This line has not yet opened this product.（沙箱 PRE_ORDER 實測，官方表未列） | 線路未開通此產品；改產品或問客戶經理（§2.2） |
| 123057 | 寄件类型（个人件或者公司件）必填 | TW 始發補 `senderInfo.cargoType` |
| 124080 | 寄件方统编号必填 | 公司寄件補 `certType=009` + 統編 |
| 124149 | 收件方统编号必填 | 公司收件補 `certType=009` + 統編 |
| 124082 | 收件方身份证号、护照或者居留证至少填写一项 | 個人收件補證件 |
| 124042 | 到件方证件类型值错误 | `certType` 必須用附錄 7 代碼 |
| 124121 / 124120 | 到件方 / 寄件方邮编无效 | 用 5/6 碼郵編 |
| 124036 / 124003 | 电话格式错误 | 區號 `886` 純數字、號碼純數字 |
| 124118 / 124119 | 地址需要用英文输入 | 地址改英文，中文放 I18n 欄位 |
| 123010 / 126035 | 货币代码错误 / 币种长度超长 | 用 `NTD` 等附錄 4 代碼 |
| 123102 | 个人物品需要上传证件 | 上傳清關證件 |
| 123121 | 缺少清关资料 | `IUOP_UPLOAD_CERTIFY` |
| 123033 | 该流向不支持到付 | 改寄付 / 第三方付 |
| 123014 / 123015 | 税金结算账号未签订DDP协议 / 不能为空 | 稅金改收方付或簽協議 |

完整錯誤碼見 `error-codes.md`。

## 8. 待確認事項

- 台灣收件人個人證件：官方只寫「ID/TW/KR 必填」，未說明台灣身分證字號應用 `001` 還是其他代碼；`006` 台灣通行證語意上是大陸居民赴台證件。**上線前向客戶經理確認。**
- `124080` / `124149`（統編號必填）觸發的流向與件種官方未說明。
- **台灣島內件（TW→TW）正式環境可否下單、用哪個產品與 msgType**：文件無說明。沙箱推薦有回產品（§2.1），沙箱 `IUOP_PRE_ORDER` 對 TW→TW + `INT0005` / `INT0202` 也回 `code 0`（§2.2）。但這些都只是沙箱結果，沒有實際建單取號，正式環境仍待確認，確認前不要上線。
- **`INT0202` Taiwan E-commerce**：附錄 2 沒有列出。沙箱 TW→TW 推薦有出現，沙箱 PRE_ORDER 校驗也通過（§2.2）。它的用途、業務類型（IUOP / IECS），以及正式環境能否下單，都還不知道。
- **`123296`**（This line has not yet opened this product）：沙箱 PRE_ORDER 實測出現，但不在官方錯誤碼表內；正式建單是否用同一碼待確認。
- 沙箱 PRE_ORDER 不擋 3 碼郵編與 TW→TW 收件方缺證件（§2.2 E、F）；正式 `IUOP_CREATE_ORDER` 是否擋這兩項，還沒驗證。
- `INT0005` 顺丰特快 / `INT0011` 顺丰标快 等「順豐」系列產品是否適用兩岸 / 台灣流向：附錄無說明。[實測 2026-09-23] 沙箱 `INT0005` 在 TW→TW、TW→CN、TW→HK 推薦中都有出現，非島內專屬；正式適用流向待確認。
- 幣種 `NTD` vs `TWD`：附錄與實測皆為 `NTD`，但建單頁 `ecommerceInfo.currency` 範例寫 `TWD`；建議一律用 `NTD`。
- 國際收寄標準 `SOP_COLLECT_SHIP_STANDARD` 的台灣代碼（推定 `886`）。
- 沙箱區劃資料缺澎湖 / 金門 / 連江 / 雲林（見 `address-region.md` 補充節），正式環境是否相同未知。
- 台灣郵編計費 / 口岸代碼 `MZ` 的含義（實測 `billingAreaCode:"886,MZ"`）。
