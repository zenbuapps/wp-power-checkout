# 資料字典（官方附錄 1–12、15、16）

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

收錄範圍：附錄 1–12、15、16（**附錄 13 響應碼在 `error-codes.md`；附錄 14 路由操作碼 opCode 在 `tracking.md`**）。各節標題為「附錄 N：名稱」，可直接 `grep -n "^## 附錄 2：" references/dictionaries.md`（帶「^## 」與全形冒號，避免查「附錄 1」時連附錄 10–16 一起命中）。另有未列入選單的草稿頁「16.費用項列表」（第 227 頁，內容幾乎空白），見 `sources.md` §4。

**何時讀這份**：查 `msgType` 列表、`interProductCode` 產品映射碼、幣種、國家地區二字碼、增值服務碼、證件類型、時區、擴展屬性、報關方式、貿易條件、報關資料類型、收派範圍 `coverType`、本地化參數 `localizedInfo` 時。

台灣重點（全部出自下方官方表）：
- 國家地區碼：`TW`（官方名稱「中国台湾」）、`HK`、`MO`、`CN`（中国内地）。
- 幣種：新台幣 = **`NTD`**（幣種聲明表沒有 `TWD`；但建單頁 `ecommerceInfo.currency` 範例寫 `TWD`、9610 附錄用 `TWD`，見 `taiwan-rules.md`）。
- 證件類型：`006` 台灣通行證（適用台灣）、`009` 統編號（適用台灣）、`001` 身份證、`002` 護照、`005` 港澳通行證。
- 報關方式：`5` 簡易-銷售（台灣出口用）、`6` 簡易-樣品（台灣出口用）。
- 時區：`Asia/Taipei`（+08:00）。
- 本地化參數 key：`localZhTW`（繁體中文）。
- 附錄 1「業務接口編碼列表」是 2023 年舊表，**不完整**（沒有 IPE / ICSM / IFTP / ADES 等新接口）；完整 msgType 以 SKILL.md 端點索引為準。
- 附錄 3「海外本地件產品映射碼」只有馬來西亞 / 印尼 / 越南，台灣不適用（保留供辨識邊界）。

## 目錄

- [附錄 1：業務接口編碼列表](#附錄-1業務接口編碼列表)
- [附錄 2：國際件產品映射碼聲明](#附錄-2國際件產品映射碼聲明)
- [附錄 3：海外本地件產品映射碼](#附錄-3海外本地件產品映射碼)
- [附錄 4：幣種聲明](#附錄-4幣種聲明)
- [附錄 5：國家地區代碼聲明](#附錄-5國家地區代碼聲明)
- [附錄 6：增值服務聲明](#附錄-6增值服務聲明)
- [附錄 7：證件類型聲明](#附錄-7證件類型聲明)
- [附錄 8：時區聲明](#附錄-8時區聲明)
- [附錄 9：擴展屬性](#附錄-9擴展屬性)
- [附錄 10：報關方式屬性](#附錄-10報關方式屬性)
- [附錄 11：貿易條件屬性值](#附錄-11貿易條件屬性值)
- [附錄 12：報關資料類型](#附錄-12報關資料類型)
- [附錄 15：收派範圍列表](#附錄-15收派範圍列表)
- [附錄 16：本地化參數](#附錄-16本地化參數)

---

## 附錄 1：業務接口編碼列表

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=60（文檔中心 > API文檔 > 附錄 > 1.業務接口編碼列表；官方更新 2023-06-07；爬取 2026-09-23）

| 接口編碼(msgType) | 接口名稱 |
| ------------- | ---- |
| IECS\_CREATE\_ORDER | 電商下單 |
| IECS\_IMPORT\_PACKAGE | 電商導入包票關係 |
| IECS\_PRINT\_ORDER | 電商打印面單 |
| IECS\_QUERY\_AGENTNO | 電商電商服務商單號查詢 |
| IECS\_QUERY\_WEIGHT\_FREIGHT | 電商電商重量及運費查詢 |
| IECS\_UPLOAD\_WEIGHT | 電商電商小包上傳重量 |
| IUOP\_CREATE\_ORDER | 快遞下單 |
| IUOP\_PRINT\_ORDER | 快遞打印面單 |
| IUOP\_QUERY\_ORDER | 快遞查詢訂單 |
| IUOP\_QUERY\_FREIGHT | 快遞查詢運費 |
| IUOP\_REQUIRE\_CERTIFY | 快遞證件要求 |
| IUOP\_UPLOAD\_CERTIFY | 快遞證件上傳 |
| IUOP\_VALIDITY\_CERTIFY | 快遞證件有效性 |
| IUOP\_ASYN\_PRINT\_ORDER | 快遞異步打單 |
| IUOP\_ASYN\_PRINT\_RESULT | 快遞異步打單結果 |
| IUOP\_CREATE\_ORDER | 快遞信任重量及二次信任重量下單 |
| IUOP\_BBD\_PRE\_MERGE | 快遞 BBD預合單 |
| IUOP\_CANCEL\_ORDER | 快遞消單 |
| IUOP\_PUSH\_TRACK | 快遞物流單推送接口 |
| IUOP\_QUERY\_TRACK | 快遞查詢路由 |
| IOMS\_SEND\_PROVE\_CREATE | 通用查詢運單文件接口 |
| IOMS\_SEND\_PROVE\_ONEFILE | 通用單票派送證明獲取 |
| IOMS\_SEND\_PROVE\_QUERY | 通用查詢任務結果接口 |
| ADES\_AGENT\_QUERY\_POD | 通用代理派送證明查詢 |
| GTS\_QUERY\_TRACK | 通用查詢路由 |
| ICMS\_GET\_CONTINENT\_LIST | 地址區劃-查詢大洲列表 |
| ICMS\_QUERY\_CITY\_LIST | 地址區劃-查詢城市列表 |
| ICMS\_QUERY\_COUNTRY\_LIST | 地址區劃-查詢國家列表 |
| ICMS\_QUERY\_POST\_CODE\_ATTRIBUTE | 地址區劃-查詢郵編屬性 |
| ICMS\_QUERY\_POST\_CODE\_BY\_REGION | 地址區劃-根據國際區劃查詢郵編 |
| ICMS\_QUERY\_POST\_CODE\_INFO | 地址區劃-查詢郵編詳細信息 |
| ICMS\_QUERY\_POST\_CODE\_VAGUE | 地址區劃-郵編模糊查詢 |
| ICMS\_QUERY\_PROVINCE\_LIST | 地址區劃-查詢州省列表 |
| ICMS\_QUERY\_REGION\_BY\_POST\_CODE | 地址區劃-根據郵編查詢國際區劃 |
| ICMS\_REGION\_CASCADE\_QUERY | 地址區劃-國際區劃級聯查詢 |
| ICMS\_REGION\_VAGUE\_QUERY | 地址區劃-國際區劃模糊查詢 |

---

## 附錄 2：國際件產品映射碼聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=61（文檔中心 > API文檔 > 附錄 > 2.國際件產品映射碼聲明；官方更新 2026-09-11；爬取 2026-09-23）

> 業務類型為“電商IECS”需使用出口電商業務場景（msgType = IECS\_CREATE\_ORDER  ）創建訂單
> 業務類型為“快遞IUOP”需使用國際快遞業務場景（msgType = IUOP\_CREATE\_ORDER）創建訂單

| 國際產品映射碼 | 國際產品名稱 | 支持的業務類型 | 備註 |
| ------- | ------ | ------- | --- |
| INT0054 | 國際小包 | 電商IECS <br>快遞IUOP | CN、HK始發出口流向使用IECS\_CREATE\_ORDER下單<br>其餘流向使用IUOP\_CREATE\_ORDER下單 |
| INT0255 | 國際電商專遞-標準 | 電商IECS | IECS出口電商業務通常僅有CN、HK始發出口流向 |
| INT0257 | 國際電商專遞-快速 | 電商IECS | IECS出口電商業務通常僅有CN、HK始發出口流向 |
| INT0554 | 國際電商專遞-優先CD | 電商IECS | IECS出口電商業務通常僅有CN、HK始發出口流向 |
| ~~INT0560~~ | ~~國際電商專遞-服裝~~ | ~~電商IECS~~ | ~~IECS出口電商業務通常僅有CN、HK始發出口流向(已廢棄)~~ |
| INT0562 | 國際電商專遞-拋貨CD | 電商IECS | IECS出口電商業務通常僅有CN、HK始發出口流向 |
| INT0561 | 國際電商專遞-特惠CD | 電商IECS | IECS出口電商業務通常僅有CN、HK始發出口流向 |
| INT0627 | 敏貨電商專遞 | 電商IECS | IECS出口電商業務當前僅支持CN-US流向，主要是日化和化妝品類，含固態、液態、粉末狀等 |
| INT0655 | 服裝專線 | 電商IECS | 當前主要是CN-US流向的服裝 |
| INT0678 | 輕拋專線 | 電商IECS | 當前主要是CN-US流向 |
| INT0700 | 歐洲-泡貨專線 | 電商IECS | 當前主要是CN-EU流向，適用歐洲流向 |
| INT0734 | 輕小件小包掛號 | 電商IECS | 當前主要是CN-JP流向,適用日本流向 |
| INT0710 | 國際電商專遞-KIXSGW | 電商IECS | 當前主要是CN-JP流向,適用日本流向大尺寸包裹 |
| INT0747 | 國際電商專遞-經濟 | 電商IECS | 當前主要是CN-GB流向 |
| INT0761 | 國際電商專遞-加拿大商派 | 電商IECS | 當前主要是CN-CA流向 |
| INT0726 | 國際電商末派 UNIUNI | 電商IECS | 個性化產品，是否適用請聯繫對接銷售 |
| INT0711 | 國際電商專遞-JPYMT | 電商IECS | 個性化產品，日本流向產品，是否適用請聯繫對接銷售 |
| INT0720 | 國際電商專遞-商派專線 | 電商IECS |  |
| INT0701 | 輕小件專線 | 電商IECS |  |
| INT0780 | 國際電商專遞-優先服裝 | 電商IECS | 對應KTS的內部產品代碼是207 |
| INT0822 | 國際電商專遞-優選 | 電商IECS | 對應KTS的內部產品代碼是366 |
| INT0783 | 國際電商末派 UNIUNI L1 | 電商IECS |  |
| INT0782 | 國際電商末派 UNIUNI L2 | 電商IECS |  |
| INT0784 | 國際電商末派 UNIUNI L3 | 電商IECS |  |
| INT0785 | 國際電商末派 UNIUNI L4 | 電商IECS |  |
| ~~INT0478~~ | ~~平台解決方案荷郵小包平郵~~ | ~~電商IECS~~ |  |
| ~~INT0479~~ | ~~平台解決方案荷郵小包掛號~~ | ~~電商IECS~~ |  |
| ~~INT0480~~ | ~~平台解決方案荷郵小包重掛號~~ | ~~電商IECS~~ |  |
| ~~INT0586~~ | ~~平台方案PB尾程清派~~ | ~~電商IECS~~ |  |
| ~~INT0584~~ | ~~平台方案PB純尾程~~ | ~~電商IECS~~ |  |
| ~~INT0490~~ | ~~平台解決方案皇郵小包掛號~~ | ~~電商IECS~~ |  |
| ~~INT0555~~ | ~~平台解決方案皇郵小包掛號GBP~~ | ~~電商IECS~~ |  |
| ~~INT0431~~ | ~~平台解決方案ZFC美國輕小件~~ | ~~電商IECS~~ |  |
| ~~INT0429~~ | ~~平台解決方案荷郵小包平郵IOS~~ | ~~電商IECS~~ |  |
| ~~INT0433~~ | ~~平台解決方案荷郵小包掛號IOS~~ | ~~電商IECS~~ |  |
| ~~INT0434~~ | ~~平台解決方案荷郵小包重掛號IOS~~ | ~~電商IECS~~ |  |
| INT0745 | 國際電商專遞-Y2 普貨 | 電商IECS |  |
| INT0792 | 商派服裝專線 | 電商IECS |  |
| INT0793 | 特貨電商專遞 | 電商IECS |  |
| INT0391 | 國際經濟小包掛號 | 電商IECS | 適用日本投函件 |
| INT0708 | 國際電商專遞投函件 | 電商IECS |  |
| INT0691 | 9610專線 | 電商IECS |  |
| INT0777 | 美國島嶼專線 | 電商IECS |  |
| INT0263 | 國際電商專遞-CD | 電商IECS<br>快遞IUOP | CN、HK始發出口流向使用IECS\_CREATE\_ORDER下單<br>其餘流向使用IUOP\_CREATE\_ORDER下單 |
| INT0007 | **國際標快（文件）** | 快遞IUOP | 收件國家若為CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN時，使用國際標快<br>其餘使用國際標快+ |
| INT0008 | **國際標快（包裹）** | 快遞IUOP | 收件國家若為CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN時，使用國際標快<br>其餘使用國際標快+ |
| INT0137 | **國際標快+（文件）** | 快遞IUOP | 收件國家若為CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN時，使用國際標快<br>其餘使用國際標快+ |
| INT0139 | **國際標快+（包裹）** | 快遞IUOP | 收件國家若為CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN時，使用國際標快<br>其餘使用國際標快+ |
| INT0013 | 國際特惠（文件） | 快遞IUOP |  |
| INT0014 | 國際特惠（包裹） | 快遞IUOP |  |
| INT0253 | 國際大件 | 快遞IUOP |  |
| INT0282 | 國際重貨-港到港 | 快遞IUOP |  |
| INT0275 | 國際重貨-門到門 | 快遞IUOP |  |
| INT0173 | 國際標快-BD2 | 快遞IUOP |  |
| INT0174 | 國際標快-BD3 | 快遞IUOP |  |
| INT0175 | 國際標快-BD4 | 快遞IUOP |  |
| INT0176 | 國際標快-BD5 | 快遞IUOP |  |
| INT0177 | 國際標快-BD6 | 快遞IUOP |  |
| INT0172 | 國際標快-BDE | 快遞IUOP |  |
| INT0001 | E順遞 | 快遞IUOP |  |
| INT0009 | 電商標快 | 快遞IUOP |  |
| INT0287 | 國際倉儲-頭程 | 快遞IUOP |  |
| INT0280 | 國際特惠-FBA | 快遞IUOP |  |
| INT0201 | 集貨轉運 | 快遞IUOP |  |
| INT0010 | 陸運包裹 | 快遞IUOP |  |
| INT0005 | 順豐特快 | 快遞IUOP |  |
| INT0006 | 順豐特快（D類） | 快遞IUOP |  |
| INT0011 | 順丰標快 | 快遞IUOP |  |
| INT0214 | 順豐特惠（D類） | 快遞IUOP |  |
| INT0248 | 自貿區特配 | 快遞IUOP |  |
| INT0638 | 國際特快（文件） | 快遞IUOP |  |
| INT0639 | 國際特快（包裹） | 快遞IUOP |  |
| INT0077 | 順豐卡航 | 快遞IUOP |  |
| INT0657 | 香港本地急送 | 快遞IUOP |  |

---

## 附錄 3：海外本地件產品映射碼

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=201（文檔中心 > API文檔 > 附錄 > 3.海外本地件產品映射碼；官方更新 2025-10-24；爬取 2026-09-23）

| 國家 | 海外本地件產品映射碼 | 海外本地件產品名稱 |
| --- | ---------- | --------- |
| 馬來 | M111 | KEX即日達 |
| 馬來 | M101 | KEX標快-文件 |
| 馬來 | M102 | KEX標快-包裹 |
| 馬來 | M105 | KEX榴蓮專遞 |
| 馬來 | M106 | KEX生鮮專遞 |
| 馬來 | M108 | 大件標快 |
| 印尼 | ID101 | KEX大件-包裹 |
| 印尼 | ID102 | KEX特惠-包裹 |
| 印尼 | ID103 | KEX特惠-文件 |
| 越南| SFVN100101| SF-標準件 |
| 越南| SFVN100102| SF-特快件 |
| 越南| SFVN100103| SF-自寄件 |
| 越南| SFVN100104| SF-當日達 |
| 越南| SFVN100105| SF-標準件48H |
| 越南| SFVN100106| SF-整車 |

---

## 附錄 4：幣種聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=62（文檔中心 > API文檔 > 附錄 > 4.幣種聲明；官方更新 2023-07-31；爬取 2026-09-23）

| **幣別代碼（A-K）** | **幣別說明** |  | **幣別代碼（L-Z）** | **幣別說明** |
| --------- | ---- | --- | --------- | ---- |
| AED | 阿聯酋迪拉姆 |  | LAK | 老撾基普 |
| AFN | 阿富汗尼 |  | LBP | 黎巴嫩鎊 |
| ALL | 阿爾巴尼亞列克 |  | LKR | 斯里蘭卡盧比 |
| AMD | 亞美尼亞德拉姆 |  | LRD | 利比里亞元 |
| ANG | 荷屬安的列斯盾 |  | LSL | 萊索托洛蒂 |
| AOA | 安哥拉寬扎 |  | LYD | 利比亞第納爾 |
| ARS | 阿根廷比索 |  | MAD | 摩洛哥迪爾汗 |
| AUD | 澳大利亞元 |  | MDL | 摩爾多瓦列伊 |
| AWG | 阿魯巴弗羅林 |  | MGA | 馬達加斯加阿里亞里 |
| AZN | 阿塞拜疆馬納特 |  | MKD | 馬其頓代納爾 |
| BAM | 波斯尼亞和黑塞哥維那可兌換馬克 |  | MMK | 緬元 |
| BBD | 巴巴多斯元 |  | MNT | 蒙古圖格里克 |
| BDT | 孟加拉塔卡 |  | MOP | 澳門幣 |
| BHD | 巴林第納爾 |  | MUR | 毛里求斯盧比 |
| BIF | 布隆迪法郎 |  | MVR | 馬爾代夫拉菲亞 |
| BMD | 百慕大元 |  | MWK | 馬拉維克瓦查 |
| BND | 文萊元 |  | MXN | 墨西哥比索 |
| BOB | 玻利維亞諾 |  | MYR | 馬來西亞令吉 |
| BRL | 巴西雷亞爾 |  | MZN | 莫桑比克梅蒂卡爾 |
| BSD | 巴哈馬元 |  | NAD | 納米比亞元 |
| BTN | 不丹努爾特魯姆 |  | NGN | 尼日利亞奈拉 |
| BWP | 博茨瓦納普拉 |  | NIO | 尼加拉瓜科多巴 |
| BZD | 伯利茲元 |  | NOK | 挪威克朗 |
| CAD | 加拿大元 |  | NPR | 尼泊爾盧比 |
| CDF | 剛果法郎 |  | NTD | 新台幣 |
| CHF | 瑞士法郎 |  | NZD | 新西蘭元 |
| CLP | 智利比索 |  | OMR | 阿曼里亞爾 |
| CNY | 人民幣元 |  | PAB | 巴拿馬巴波亞 |
| COP | 哥倫比亞比索 |  | PEN | 秘魯索爾 |
| CRC | 哥斯達黎加科朗 |  | PGK | 巴布亞新幾內亞基那 |
| CUP | 古巴比索 |  | PHP | 菲律賓比索 |
| CVE | 佛得角埃斯庫多 |  | PKR | 巴基斯坦盧比 |
| CZK | 捷克克朗 |  | PLN | 波蘭茲羅提 |
| DJF | 吉布提法郎 |  | PYG | 巴拉圭瓜拉尼 |
| DKK | 丹麥克朗 |  | QAR | 卡塔爾里亞爾 |
| DOP | 多米尼加比索 |  | RSD | 塞爾維亞第納爾 |
| DZD | 阿爾及利亞第納爾 |  | RUB | 俄羅斯盧布 |
| EGP | 埃及鎊 |  | RWF | 盧旺達法郎 |
| ERN | 厄立特里亞納克法 |  | SAR | 沙特里亞爾 |
| ETB | 埃塞俄比亞比爾 |  | SBD | 所羅門群島元 |
| EUR | 歐元 |  | SCR | 塞舌爾盧比 |
| FJD | 斐濟元 |  | SDG | 蘇丹鎊 |
| FKP | 福克蘭群島鎊 |  | SEK | 瑞典克朗 |
| GBP | 英鎊 |  | SGD | 新加坡元 |
| GEL | 格魯吉亞拉里 |  | SHP | 聖赫勒拿鎊 |
| GIP | 直布羅陀鎊 |  | SLL | 塞拉利昂利昂 |
| GMD | 岡比亞達拉西 |  | SOS | 索馬里先令 |
| GNF | 幾內亞法郎 |  | SRD | 蘇里南元 |
| GTQ | 危地馬拉格查爾 |  | SSP | 南蘇丹鎊 |
| GYD | 圭亞那元 |  | SYP | 敘利亞鎊 |
| HKD | 港元 |  | SZL | 斯威士蘭裡蘭吉尼 |
| HNL | 洪都拉斯倫皮拉 |  | THB | 泰銖 |
| HRK | 克羅地亞庫納 |  | TJS | 塔吉克斯坦索莫尼 |
| HTG | 海地古德 |  | TMT | 土庫曼斯坦馬納特 |
| HUF | 匈牙利福林 |  | TND | 突尼斯第納爾 |
| IDR | 印尼盾 |  | TOP | 湯加潘加 |
| ILS | 以色列新謝克爾 |  | TRY | 土耳其里拉 |
| INR | 印度盧比 |  | TTD | 特立尼達和多巴哥元 |
| IQD | 伊拉克第納爾 |  | TZS | 坦桑尼亞先令 |
| IRR | 伊朗里亞爾 |  | UAH | 烏克蘭格里夫納 |
| ISK | 冰島克朗 |  | UGX | 烏干達先令 |
| JMD | 牙買加元 |  | USD | 美元 |
| JOD | 約旦第納爾 |  | UYU | 烏拉圭比索 |
| JPY | 日圓 |  | UZS | 烏茲別克斯坦索姆 |
| KES | 肯尼亞先令 |  | VND | 越南盾 |
| KGS | 吉爾吉斯斯坦索姆 |  | VUV | 瓦努阿圖瓦圖 |
| KHR | 柬埔寨瑞爾 |  | WST | 薩摩亞塔拉 |
| KMF | 科摩羅法郎 |  | XAF | 中非法郎 |
| KPW | 朝鮮圓 |  | XCD | 東加勒比元 |
| KRW | 韓圓 |  | XOF | 西非法郎 |
| KWD | 科威特第納爾 |  | XPF | 太平洋法郎（franc Pacifique） |
| KYD | 開曼群島元 |  | YER | 也門裡亞爾 |
| KZT | 哈薩克斯坦堅戈 |  | ZAR | 南非蘭特 |
|  |  |  | ZMW | 贊比亞克瓦查 |

---

## 附錄 5：國家地區代碼聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=63（文檔中心 > API文檔 > 附錄 > 5.國家地區代碼聲明；官方更新 2023-03-28；爬取 2026-09-23）

| **國家地區二字碼<br>（A-K）** | **國家地區名稱** || **國家地區二字碼<br>（L-Z）** | **國家地區名稱** |
| ------------ | ------ |-| ------------ | ------ |
|AD|安道爾共和國||LA|老撾|
|AE|阿拉伯聯合酋長國||LB|黎巴嫩|
|AF|阿富汗||LC|聖盧西亞|
|AG|安提瓜和巴布達||LI|列支敦士登|
|AI|安圭拉島||LK|斯里蘭卡|
|AL|阿爾巴尼亞||LR|利比里亞|
|AM|亞美尼亞||LS|萊索托|
|AO|安哥拉||LT|立陶宛|
|AR|阿根廷||LU|盧森堡|
|AS|美屬薩摩亞群島||LV|拉脫維亞|
|AT|奧地利||LY|利比亞|
|AU|澳大利亞||MA|摩洛哥|
|AW|阿魯巴||MC|摩納哥|
|AX|奧蘭群島||MD|摩爾多瓦|
|AZ|阿塞拜疆||ME|黑山|
|BA|波斯尼亞和黑塞哥維那||MF|聖馬丁|
|BB|巴巴多斯||MG|馬達加斯加|
|BD|孟加拉國||MH|馬紹爾群島|
|BE|比利時||MK|馬其頓|
|BF|布基納法索||ML|馬裡|
|BG|保加利亞||MM|緬甸|
|BH|巴林||MN|蒙古|
|BI|布隆迪||MO|中國澳門|
|BJ|貝寧||MP|北馬里亞納群島|
|BL|聖巴泰勒米島||MQ|馬提尼克（法屬）|
|BM|百慕大群島||MR|毛利塔尼亞|
|BN|文萊||MS|蒙特塞拉特島|
|BO|玻利維亞||MT|馬耳他|
|BQ|博內爾島||MU|毛里求斯|
|BR|巴西||MV|馬爾代夫|
|BS|巴哈馬||MW|馬拉維|
|BT|不丹||MX|墨西哥|
|BW|博茨瓦納||MY|馬來西亞|
|BY|白俄羅斯||MZ|莫桑比克|
|BZ|伯利茲||NA|納米比亞|
|CA|加拿大||NC|新喀里多尼亞（法屬）|
|CC|科科斯（基林）島||NE|尼日爾|
|CD|剛果民主共和國||NF|諾福克島|
|CF|中非共和國||NG|尼日利亞|
|CG|剛果||NI|尼加拉瓜|
|CH|瑞士||NL|荷蘭|
|CI|科特迪瓦（共和國）||NO|挪威|
|CK|庫克群島||NP|尼泊爾|
|CL|智利||NR|瑙魯|
|CM|喀麥隆||NU|紐埃|
|CN|中國內地||NZ|新西蘭|
|CO|哥倫比亞||OM|阿曼|
|CR|哥斯達黎加||PA|巴拿馬|
|CU|古巴||PE|秘魯|
|CV|佛得角||PF|玻利尼西亞（法屬）|
|CW|庫拉索島||PG|巴布亞新幾內亞|
|CX|聖誕島||PH|菲律賓|
|CY|塞浦路斯||PK|巴基斯坦|
|CZ|捷克||PL|波蘭|
|DE|德國||PM|聖皮埃爾和密克隆（法屬）|
|DJ|吉布提||PR|波多黎各|
|DK|丹麥||PS|巴勒斯坦|
|DM|多米尼克||PT|葡萄牙|
|DO|多米尼加共和國||PW|帕勞|
|DZ|阿爾及利亞||PY|巴拉圭|
|EC|厄瓜多爾||QA|卡塔爾|
|EE|愛沙尼亞||RE|留尼汪（法屬）|
|EG|埃及||RO|羅馬尼亞|
|EH|西撒哈拉||RS|塞爾維亞共和國|
|ER|厄立特里亞||RU|俄羅斯|
|ES|西班牙||RW|盧旺達|
|ET|埃塞俄比亞||SA|沙特阿拉伯|
|FI|芬蘭||SB|所羅門群島|
|FJ|斐濟||SC|塞舌爾|
|FK|福克蘭群島||SD|蘇丹|
|FM|密克羅尼西亞聯邦||SE|瑞典|
|FO|法羅群島||SG|新加坡|
|FR|法國||SH|聖赫勒拿島|
|GA|加蓬||SI|斯洛文尼亞|
|GB|英國||SK|斯洛伐克|
|GD|格林納達||SL|塞拉利昂|
|GE|格魯吉亞||SM|聖馬力諾|
|GF|法屬圭亞那||SN|塞內加爾|
|GG|根西||SO|索馬里|
|GH|加納||SR|蘇里南|
|GI|直布羅陀||SS|南蘇丹|
|GL|格陵蘭島||ST|聖多美和普林西比|
|GM|岡比亞||SV|薩爾瓦多|
|GN|幾內亞||SX|聖馬丁島|
|GP|瓜德魯普||SY|敘利亞|
|GQ|赤道幾內亞||SZ|斯威士蘭|
|GR|希臘||TC|特克斯和凱科斯群島（英屬）|
|GT|危地馬拉||TD|乍得|
|GU|關島||TG|多哥|
|GW|幾內亞比紹||TH|泰國|
|GY|圭亞那||TJ|塔吉克斯坦|
|HI|夏威夷||TK|托克勞群島|
|HK|中國香港||TL|東帝汶|
|HN|洪都拉斯||TM|土庫曼斯坦|
|HR|克羅地亞||TN|突尼斯|
|HT|海地||TO|湯加|
|HU|匈牙利||TR|土耳其|
|ID|印度尼西亞||TT|特立尼達和多巴哥|
|IE|愛爾蘭||TV|圖瓦盧|
|IL|以色列||TW|中國台灣|
|IM|馬恩島||TZ|坦桑尼亞|
|IN|印度||UA|烏克蘭|
|IO|英屬印度洋領地||UG|烏干達|
|IQ|伊拉克||UM|美國海外領地|
|IR|伊朗||US|美國|
|IS|冰島||UY|烏拉圭|
|IT|意大利||UZ|烏茲別克斯坦|
|JE|澤西島||VA|梵蒂岡|
|JM|牙買加||VC|聖文森特|
|JO|約旦||VE|委內瑞拉|
|JP|日本||VG|維爾京群島（英屬）|
|KE|肯尼亞||VI|維爾京群島（美屬）|
|KG|吉爾吉斯斯坦||VN|越南|
|KH|柬埔寨||VU|瓦努阿圖|
|KI|基里巴斯||WF|瓦利斯群島和富圖納群島|
|KM|科摩羅||WS|薩摩亞|
|KN|聖基茨和尼維斯||XZ|科索沃|
|KP|朝鮮||YE|也門|
|KR|韓國||ZA|南非|
|KW|科威特||ZM|贊比亞|
|KY|開曼群島||ZW|津巴布韋|
|KZ|哈薩克斯坦||||

---

## 附錄 6：增值服務聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=64（文檔中心 > API文檔 > 附錄 > 6.增值服務聲明；官方更新 2025-10-24；爬取 2026-09-23）

### 國際件

| 服務代碼 | 服務名稱 | 字段說明 |
| ---- | ---- | ---- |
| IN01/COD | 代收貨款 | value傳貨款金額，類型為BigDecimal (16,6) ，以到件地所在區域幣種為準 <br>valueOne傳月結卡號或者協議號,類型為String |
| IN02/SPP | 保價 | value傳保價金額，幣種同訂單申報價值幣種且保價金額不大於訂單總申報價值，類型為BigDecimal (16,6) |
| IN162 | 要求遞送確認簽名 | 只需傳服務代碼即可 |
| IN16 | 國際偏遠附加費 |
| IN23 | 超長超重附加費 |
| IN38 | 國際住宅附加費 |
| IN07 | 進口報關 |
| IN137 | 出口報關 |
| IN102 | 特殊入倉 |
| IN123 | 參展服務 |
| IN72 | 清關綜合服務費 |
| IN167 | 國際代收稅金 |
| IN96 | 電商操作費 |
| IN171 | 國際處理費 |

### 海外本地件

| 服務代碼 | 服務名稱 | 字段說明 |
| ---- | ---- | ---- |
| INKEX2 | 保價 | value為聲明價值<br>value必填 |
| INKEX3 | 代收貨款 | value為貨款，valueOne為代收貨款卡號<br>value必填，valueOne選填 |
| INKEX48 | 上門服務 |  |
| INKEX43 | 簽單返還 | value為返還方式【必填】，0-拍照回傳，1-單票寄回<br>valueOne為簽單備註【選填】 |
| INKEX100| 清點數目 |  |

---

## 附錄 7：證件類型聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=65（文檔中心 > API文檔 > 附錄 > 7.證件類型聲明；官方更新 2026-01-19；爬取 2026-09-23）

| 證件類型代碼 | 證件名稱 | 描述說明 |
| ------ | ---- | ---- |
| 001 | 身份證 |  |
| 002 | 護照 |  |
| 003 | 軍官證 |  |
| 004 | 警察證 |  |
| 005 | 港澳通行證 | 適用香港、澳門 |
| 006 | 台灣通行證 | 適用台灣 |
| 007 | 社會統一信用代碼 |  |
| 008 | 海關注冊編碼 |  |
| 009 | 統編號 | 適用台灣 |
| 010 | 海關進出口號 |  |
| 011 | 法人番號 |  |
| 012 | 進口商號碼 |  |
| 013 | 納稅帳號 |  |
| 014 | 社會安全號碼 |  |
| 015 | 經營者註冊和識別號碼 |  |
| 016 | 個人清關編碼 / 出生日期 | 適用韓國 |
| 017 | 公司清關編碼 | 適用韓國 |
| 018 | 營業執照號 |  |
| 019 | 印度公民身份識別卡 | 適用印度 |
| 020 | 永久帳戶納稅號 |  |
| 021 | 進出口註冊號碼 |  |
| 022 | 貨物服務稅號 |  |
| 023 | 居留證 |  |
| 024 | 經濟經營者登記識別號（EORI） |  |
| 025 | 增值稅號（VAT） |  |
| 029 | 印尼身份證 | 適用印尼 |
| 031 | 印尼駕照 | 適用印尼 |
| 032 | 印尼有限居留許可證 | 適用印尼 |
| 033 | 印尼永久居留許可證 | 適用印尼 |
| 034 | 印尼稅號 | 適用印尼 |
| 035 | 巴西個人稅務登記號 | 適用巴西 |
| 036 | 巴西法人國家登記號 | 適用巴西 |
| 037 | 公司稅號 |  |
| 038 | 孟加拉商業識別碼(BIN) | 適用孟加拉 |
| 040 | MRN |  |
| 041 | 稅務登記號RFC |  |
| 044 | 商業登記註冊號 |  |
| 100 | 其他證件 |  |
| 5 | 稅號 |  |
| 116 | 稅單 |  |

---

## 附錄 8：時區聲明

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=66（文檔中心 > API文檔 > 附錄 > 8.時區聲明；官方更新 2022-07-27；爬取 2026-09-23）

| 時區 | 說明 |
| --- | --- |
| Indian/Mayotte | Indian/Mayotte,+03:00,EAT |
| Africa/Johannesburg | Africa/Johannesburg,+02:00,SAST |
| Africa/Lusaka | Africa/Lusaka,+02:00,CAT |
| Africa/Harare | Africa/Harare,+02:00,CAT |
| Asia/Magadan | Asia/Magadan,+12:00,MAGT/MAGST |
| Asia/Novokuznetsk | Asia/Novokuznetsk,+07:00,NOVT/NOVST |
| Asia/Novosibirsk | Asia/Novosibirsk,+07:00,NOVT/NOVST |
| Asia/Omsk | Asia/Omsk,+07:00,OMST/OMSST |
| Asia/Sakhalin | Asia/Sakhalin,+11:00,SAKT/SAKST |
| Asia/Vladivostok | Asia/Vladivostok,+11:00,VLAT/VLAST |
| Asia/Yakutsk | Asia/Yakutsk,+10:00,YAKT/YAKST |
| Asia/Yekaterinburg | Asia/Yekaterinburg,+06:00,YEKT/YEKST |
| Europe/Kaliningrad | Europe/Kaliningrad,+03:00,EET/EEST |
| Europe/Moscow | Europe/Moscow,+04:00,MSK/MSD |
| Europe/Samara | Europe/Samara,+04:00,SAMT/SAMST |
| Europe/Volgograd | Europe/Volgograd,+04:00,VOLT/VOLST |
| Africa/Kigali | Africa/Kigali,+02:00,CAT |
| Asia/Riyadh | Asia/Riyadh,+03:00,AST |
| Pacific/Guadalcanal | Pacific/Guadalcanal,+11:00,SBT |
| Indian/Mahe | Indian/Mahe,+04:00,SCT |
| Africa/Khartoum | Africa/Khartoum,+03:00,EAT |
| Europe/Stockholm | Europe/Stockholm,+01:00,CET/CEST |
| Asia/Singapore | Asia/Singapore,+08:00,SGT |
| Atlantic/St\_Helena | Atlantic/St\_Helena,+00:00,GMT |
| Europe/Ljubljana | Europe/Ljubljana,+01:00,CET/CEST |
| Arctic/Longyearbyen | Arctic/Longyearbyen,+01:00,CET/CEST |
| Europe/Bratislava | Europe/Bratislava,+01:00,CET/CEST |
| Africa/Freetown | Africa/Freetown,+00:00,GMT |
| Europe/San\_Marino | Europe/San\_Marino,+01:00,CET/CEST |
| Africa/Dakar | Africa/Dakar,+00:00,GMT |
| Africa/Mogadishu | Africa/Mogadishu,+03:00,EAT |
| America/Paramaribo | America/Paramaribo,-03:00,SRT |
| Africa/Juba | Africa/Juba,+03:00,EAT |
| Africa/Sao\_Tome | Africa/Sao\_Tome,+00:00,GMT |
| America/El\_Salvador | America/El\_Salvador,-06:00,CST |
| America/Sint-Maarten | America/Sint-Maarten,-04:00,AST |
| Asia/Damascus | Asia/Damascus,+02:00,EET |
| Africa/Mbabane | Africa/Mbabane,+02:00,SAST |
| America/Grand\_Turk | America/Grand\_Turk,-05:00,EST/EDT |
| Africa/Ndjamena | Africa/Ndjamena,+01:00,WAT |
| Indian/Kerguelen | Indian/Kerguelen,+05:00,TFT |
| Africa/Lome | Africa/Lome,+00:00,GMT |
| Asia/Bangkok | Asia/Bangkok,+07:00,ICT |
| Asia/Dushanbe | Asia/Dushanbe,+05:00,TJT |
| Pacific/Fakaofo | Pacific/Fakaofo,+14:00,TKT |
| Asia/Dili | Asia/Dili,+09:00,TLT |
| Asia/Ashgabat | Asia/Ashgabat,+05:00,TMT |
| Africa/Tunis | Africa/Tunis,+01:00,CET/CEST |
| Pacific/Tongatapu | Pacific/Tongatapu,+13:00,TOT |
| Europe/Istanbul | Europe/Istanbul,+02:00,EET/EEST |
| America/Port\_of\_Spain | America/Port\_of\_Spain,-04:00,AST |
| Pacific/Funafuti | Pacific/Funafuti,+12:00,TVT |
| Asia/Taipei | Asia/Taipei,+08:00,CST |
| Africa/Dar\_es\_Salaam | Africa/Dar\_es\_Salaam,+03:00,EAT |
| Europe/Kiev | Europe/Kiev,+02:00,EET/EEST |
| Europe/Simferopol | Europe/Simferopol,+02:00,EET/EEST |
| Europe/Uzhgorod | Europe/Uzhgorod,+02:00,EET/EEST |
| Europe/Zaporozhye | Europe/Zaporozhye,+02:00,EET/EEST |
| Africa/Kampala | Africa/Kampala,+03:00,EAT |
| Pacific/Johnston | Pacific/Johnston,-10:00,HAST |
| Pacific/Midway | Pacific/Midway,-11:00,SST |
| Pacific/Wake | Pacific/Wake,+12:00, |
| America/Adak | America/Adak,-10:00,HAST/HADT |
| America/Anchorage | America/Anchorage,-09:00,AKST/AKDT |
| America/Boise | America/Boise,-07:00,MST/MDT |
| America/Chicago | America/Chicago,-06:00,CST/CDT |
| America/Denver | America/Denver,-07:00,MST/MDT |
| America/Detroit | America/Detroit,-05:00,EST/EDT |
| America/Indiana/Indianapolis | America/Indiana/Indianapolis,-05:00,EST/EDT |
| America/Indiana/Knox | America/Indiana/Knox,-06:00,CST/CDT |
| America/Indiana/Marengo | America/Indiana/Marengo,-05:00,EST/EDT |
| America/Indiana/Petersburg | America/Indiana/Petersburg,-05:00,EST/EDT |
| America/Indiana/Tell\_City | America/Indiana/Tell\_City,-06:00,CST/CDT |
| America/Indiana/Vevay | America/Indiana/Vevay,-05:00,EST/EDT |
| America/Indiana/Vincennes | America/Indiana/Vincennes,-05:00,EST/EDT |
| America/Indiana/Winamac | America/Indiana/Winamac,-05:00,EST/EDT |
| America/Juneau | America/Juneau,-09:00,AKST/AKDT |
| America/Kentucky/Louisville | America/Kentucky/Louisville,-05:00,EST/EDT |
| America/Kentucky/Monticello | America/Kentucky/Monticello,-05:00,EST/EDT |
| America/Los\_Angeles | America/Los\_Angeles,-08:00,PST/PDT |
| America/Menominee | America/Menominee,-06:00,CST/CDT |
| America/New\_York | America/New\_York,-05:00,EST/EDT |
| America/Nome | America/Nome,-09:00,AKST/AKDT |
| America/North\_Dakota/Beulah | America/North\_Dakota/Beulah,-06:00,CST/CDT |
| America/North\_Dakota/Center | America/North\_Dakota/Center,-06:00,CST/CDT |
| America/North\_Dakota/New\_Salem | America/North\_Dakota/New\_Salem,-06:00,CST/CDT |
| America/Phoenix | America/Phoenix,-07:00,MST |
| America/Yakutat | America/Yakutat,-09:00,AKST/AKDT |
| Pacific/Honolulu | Pacific/Honolulu,-10:00,HAST |
| America/Montevideo | America/Montevideo,-03:00,UYT/UYST |
| Asia/Samarkand | Asia/Samarkand,+05:00,UZT |
| Asia/Tashkent | Asia/Tashkent,+05:00,UZT |
| Europe/Vatican | Europe/Vatican,+01:00,CET/CEST |
| America/St\_Vincent | America/St\_Vincent,-04:00,AST |
| America/Caracas | America/Caracas,-04:30,VET |
| America/Tortola | America/Tortola,-04:00,AST |
| America/St\_Thomas | America/St\_Thomas,-04:00,AST |
| Asia/Ho\_Chi\_Minh | Asia/Ho\_Chi\_Minh,+07:00,ICT |
| Pacific/Efate | Pacific/Efate,+11:00,VUT |
| Pacific/Wallis | Pacific/Wallis,+12:00,WFT |
| Pacific/Apia | Pacific/Apia,+13:00,WST |
| Asia/Aden | Asia/Aden,+03:00,AST |
| America/Ojinaga | America/Ojinaga,-07:00,MST/MDT |
| America/Santa\_Isabel | America/Santa\_Isabel,-08:00,PST/PDT |
| America/Tijuana | America/Tijuana,-08:00,PST/PDT |
| Asia/Kuala\_Lumpur | Asia/Kuala\_Lumpur,+08:00,MYT |
| Asia/Kuching | Asia/Kuching,+08:00,MYT |
| Africa/Maputo | Africa/Maputo,+02:00,CAT |
| Africa/Windhoek | Africa/Windhoek,+01:00,WAT/WAST |
| Pacific/Noumea | Pacific/Noumea,+11:00,NCT |
| Africa/Niamey | Africa/Niamey,+01:00,WAT |
| Pacific/Norfolk | Pacific/Norfolk,+11:30,NFT |
| Africa/Lagos | Africa/Lagos,+01:00,WAT |
| America/Managua | America/Managua,-06:00,CST |
| Europe/Amsterdam | Europe/Amsterdam,+01:00,CET/CEST |
| Europe/Oslo | Europe/Oslo,+01:00,CET/CEST |
| Asia/Kathmandu | Asia/Kathmandu,+05:45\_NPT |
| Pacific/Nauru | Pacific/Nauru,+12:00,NRT |
| Pacific/Niue | Pacific/Niue,-11:00,NUT |
| Pacific/Auckland | Pacific/Auckland,+12:00,NZST/NZDT |
| Pacific/Chatham | Pacific/Chatham,+12:45\_CHAST/CHAD |
| Asia/Muscat | Asia/Muscat,+04:00,GST |
| America/Panama | America/Panama,-05:00,EST |
| America/Lima | America/Lima,-05:00,PET |
| Pacific/Gambier | Pacific/Gambier,-09:00,GAMT |
| Pacific/Marquesas | Pacific/Marquesas,-09:30,MART |
| Pacific/Tahiti | Pacific/Tahiti,-10:00,TAHT |
| Pacific/Port\_Moresby | Pacific/Port\_Moresby,+10:00,PGT |
| Asia/Manila | Asia/Manila,+08:00,PHT |
| Asia/Karachi | Asia/Karachi,+05:00,PKT/PKST |
| Europe/Warsaw | Europe/Warsaw,+01:00,CET/CEST |
| America/Miquelon | America/Miquelon,-03:00,PMST/PMDT |
| Pacific/Pitcairn | Pacific/Pitcairn,-08:00,PST |
| America/Puerto\_Rico | America/Puerto\_Rico,-04:00,AST |
| Asia/Gaza | Asia/Gaza,+02:00,EET |
| Atlantic/Azores | Atlantic/Azores,-01:00,AZOT/AZOST |
| Atlantic/Madeira | Atlantic/Madeira,+00:00,WET/WEST |
| Europe/Lisbon | Europe/Lisbon,+00:00,WET/WEST |
| Pacific/Palau | Pacific/Palau,+09:00,PWT |
| America/Asuncion | America/Asuncion,-04:00,PYT/PYST |
| Asia/Qatar | Asia/Qatar,+03:00,AST |
| Indian/Reunion | Indian/Reunion,+04:00,RET |
| Europe/Bucharest | Europe/Bucharest,+02:00,EET/EEST |
| Europe/Belgrade | Europe/Belgrade,+01:00,CET/CEST |
| Asia/Anadyr | Asia/Anadyr,+12:00,ANAT/ANAST |
| Asia/Irkutsk | Asia/Irkutsk,+09:00,IRKT/IRKST |
| Asia/Kamchatka | Asia/Kamchatka,+12:00,PETT/PETST |
| Asia/Krasnoyarsk | Asia/Krasnoyarsk,+08:00,KRAT/KRAST |
| Asia/Pyongyang | Asia/Pyongyang,+09:00,KST |
| Asia/Seoul | Asia/Seoul,+09:00,KST |
| Asia/Kuwait | Asia/Kuwait,+03:00,AST |
| America/Cayman | America/Cayman,-05:00,EST |
| Asia/Almaty | Asia/Almaty,+06:00,ALMT |
| Asia/Aqtau | Asia/Aqtau,+05:00,AQTT |
| Asia/Aqtobe | Asia/Aqtobe,+05:00,AQTT |
| Asia/Oral | Asia/Oral,+05:00,ORAT |
| Asia/Qyzylorda | Asia/Qyzylorda,+06:00,QYZT |
| Asia/Vientiane | Asia/Vientiane,+07:00,ICT |
| Asia/Beirut | Asia/Beirut,+02:00,EET |
| America/St\_Lucia | America/St\_Lucia,-04:00,AST |
| Europe/Vaduz | Europe/Vaduz,+01:00,CET/CEST |
| Asia/Colombo | Asia/Colombo,+05:30,LKT |
| Africa/Monrovia | Africa/Monrovia,+00:00,GMT |
| Africa/Maseru | Africa/Maseru,+02:00,SAST |
| Europe/Vilnius | Europe/Vilnius,+02:00,EET/EEST |
| Europe/Luxembourg | Europe/Luxembourg,+01:00,CET/CEST |
| Europe/Riga | Europe/Riga,+02:00,EET/EEST |
| Africa/Tripoli | Africa/Tripoli,+02:00,EET |
| Africa/Casablanca | Africa/Casablanca,+00:00,WET |
| Europe/Monaco | Europe/Monaco,+01:00,CET/CEST |
| Europe/Chisinau | Europe/Chisinau,+02:00,EET/EEST |
| Europe/Podgorica | Europe/Podgorica,+01:00,CET/CEST |
| America/Marigot | America/Marigot,-04:00,AST |
| Indian/Antananarivo | Indian/Antananarivo,+03:00,EAT |
| Pacific/Kwajalein | Pacific/Kwajalein,+12:00,MHT |
| Pacific/Majuro | Pacific/Majuro,+12:00,MHT |
| Europe/Skopje | Europe/Skopje,+01:00,CET/CEST |
| Africa/Bamako | Africa/Bamako,+00:00,GMT |
| Asia/Rangoon | Asia/Rangoon,+06:30,MMT |
| Asia/Choibalsan | Asia/Choibalsan,+08:00,CHOT/CHOST |
| Asia/Hovd | Asia/Hovd,+07:00,HOVT/HOVST |
| Asia/Ulaanbaatar | Asia/Ulaanbaatar,+08:00,ULAT/ULAST |
| Asia/Macau | Asia/Macau,+08:00,CT |
| Pacific/Saipan | Pacific/Saipan,+10:00,ChST |
| America/Martinique | America/Martinique,-04:00,AST |
| Africa/Nouakchott | Africa/Nouakchott,+00:00,GMT |
| America/Montserrat | America/Montserrat,-04:00,AST |
| Europe/Malta | Europe/Malta,+01:00,CET/CEST |
| Indian/Mauritius | Indian/Mauritius,+04:00,MUT |
| Indian/Maldives | Indian/Maldives,+05:00,MVT |
| Africa/Blantyre | Africa/Blantyre,+02:00,CAT |
| America/Bahia\_Banderas | America/Bahia\_Banderas,-06:00,CST/CDT |
| America/Cancun | America/Cancun,-06:00,CST/CDT |
| America/Chihuahua | America/Chihuahua,-07:00,MST/MDT |
| America/Hermosillo | America/Hermosillo,-07:00,MST |
| America/Matamoros | America/Matamoros,-06:00,CST/CDT |
| America/Mazatlan | America/Mazatlan,-07:00,MST/MDT |
| America/Merida | America/Merida,-06:00,CST/CDT |
| America/Mexico\_City | America/Mexico\_City,-06:00,CST/CDT |
| America/Monterrey | America/Monterrey,-06:00,CST/CDT |
| Indian/Cocos | Indian/Cocos,+06:30,CCT |
| Africa/Kinshasa | Africa/Kinshasa,+01:00,WAT |
| Africa/Lubumbashi | Africa/Lubumbashi,+02:00,CAT |
| Africa/Bangui | Africa/Bangui,+01:00,WAT |
| Africa/Brazzaville | Africa/Brazzaville,+01:00,WAT |
| Europe/Zurich | Europe/Zurich,+01:00,CET/CEST |
| Africa/Abidjan | Africa/Abidjan,+00:00,GMT |
| Pacific/Rarotonga | Pacific/Rarotonga,-10:00,HAST |
| America/Santiago | America/Santiago,-04:00,CLT/CLST |
| Pacific/Easter | Pacific/Easter,-06:00,EAST/EASST |
| Africa/Douala | Africa/Douala,+01:00,WAT |
| Asia/Chongqing | Asia/Chongqing,+08:00,CST |
| Asia/Harbin | Asia/Harbin,+08:00,CST |
| Asia/Kashgar | Asia/Kashgar,+08:00,CST |
| Asia/Shanghai | Asia/Shanghai,+08:00,CST |
| Asia/Urumqi | Asia/Urumqi,+08:00,CST |
| America/Bogota | America/Bogota,-05:00,COT |
| America/Costa\_Rica | America/Costa\_Rica,-06:00,CST |
| America/Havana | America/Havana,-05:00,EST/EDT |
| Atlantic/Cape\_Verde | Atlantic/Cape\_Verde,-01:00,CVT |
| America/Curacao | America/Curacao,-04:00,AST |
| Indian/Christmas | Indian/Christmas,+07:00,LINT |
| Asia/Nicosia | Asia/Nicosia,+02:00,EET |
| Europe/Prague | Europe/Prague,+01:00,CET/CEST |
| Europe/Berlin | Europe/Berlin,+01:00,CET/CEST |
| Africa/Djibouti | Africa/Djibouti,+03:00,EAT |
| Europe/Copenhagen | Europe/Copenhagen,+01:00,CET/CEST |
| America/Dominica | America/Dominica,-04:00,AST |
| America/Santo\_Domingo | America/Santo\_Domingo,-04:00,AST |
| Africa/Algiers | Africa/Algiers,+01:00,CET |
| America/Guayaquil | America/Guayaquil,-05:00,ECT |
| Pacific/Galapagos | Pacific/Galapagos,-06:00,GALT |
| Europe/Tallinn | Europe/Tallinn,+02:00,EET/EEST |
| Africa/Cairo | Africa/Cairo,+02:00,EET/EEST |
| Africa/El\_Aaiun | Africa/El\_Aaiun,+00:00,GMT |
| Africa/Asmara | Africa/Asmara,+03:00,EAT |
| Africa/Ceuta | Africa/Ceuta,+01:00,CET/CEST |
| Atlantic/Canary | Atlantic/Canary,+00:00,WET/WEST |
| Europe/Madrid | Europe/Madrid,+01:00,CET/CEST |
| Africa/Addis\_Ababa | Africa/Addis\_Ababa,+03:00,EAT |
| Europe/Helsinki | Europe/Helsinki,+02:00,EET/EEST |
| Pacific/Fiji | Pacific/Fiji,+12:00,FJT |
| Atlantic/Stanley | Atlantic/Stanley,-03:00,FKT/FKST |
| Pacific/Chuuk | Pacific/Chuuk,+10:00,ChST |
| Pacific/Kosrae | Pacific/Kosrae,+11:00,KOST |
| Pacific/Pohnpei | Pacific/Pohnpei,+11:00,KOST |
| Atlantic/Faroe | Atlantic/Faroe,+00:00,WET/WEST |
| Europe/Paris | Europe/Paris,+01:00,CET/CEST |
| Africa/Libreville | Africa/Libreville,+01:00,WAT |
| Europe/London | Europe/London,+00:00,GMT/BST |
| America/Grenada | America/Grenada,-04:00,AST |
| Asia/Tbilisi | Asia/Tbilisi,+04:00,GET |
| America/Cayenne | America/Cayenne,-03:00,GFT |
| Europe/Guernsey | Europe/Guernsey,+00:00,GMT/BST |
| Africa/Accra | Africa/Accra,+00:00,GMT |
| Europe/Gibraltar | Europe/Gibraltar,+01:00,CET/CEST |
| America/Danmarkshavn | America/Danmarkshavn,+00:00,GMT |
| America/Godthab | America/Godthab,-03:00,WGT/WGST |
| America/Scoresbysund | America/Scoresbysund,-01:00,EGT/EGST |
| America/Thule | America/Thule,-04:00,AST/ADT |
| Africa/Banjul | Africa/Banjul,+00:00,GMT |
| Africa/Conakry | Africa/Conakry,+00:00,GMT |
| America/Guadeloupe | America/Guadeloupe,-04:00,AST |
| Africa/Malabo | Africa/Malabo,+01:00,WAT |
| Europe/Athens | Europe/Athens,+02:00,EET/EEST |
| Atlantic/South\_Georgia | Atlantic/South\_Georgia,-02:00, |
| America/Guatemala | America/Guatemala,-06:00,CST |
| Pacific/Guam | Pacific/Guam,+10:00,ChST |
| Africa/Bissau | Africa/Bissau,+00:00,GMT |
| America/Guyana | America/Guyana,-04:00,GYT |
| Asia/Hong\_Kong | Asia/Hong\_Kong,+08:00,HKT |
| Australia/Heard and McDonald | Australia/Heard and McDonald,+05:00,HIMI |
| America/Tegucigalpa | America/Tegucigalpa,-06:00,CST |
| Europe/Zagreb | Europe/Zagreb,+01:00,CET/CEST |
| America/Port-au-Prince | America/Port-au-Prince,-05:00,EST/EDT |
| Europe/Budapest | Europe/Budapest,+01:00,CET/CEST |
| Asia/Jakarta | Asia/Jakarta,+07:00,WIB |
| Asia/Jayapura | Asia/Jayapura,+09:00,EIT |
| Asia/Makassar | Asia/Makassar,+08:00,CIT |
| Asia/Pontianak | Asia/Pontianak,+07:00,WIB |
| Europe/Dublin | Europe/Dublin,+00:00,GMT/IST |
| Asia/Jerusalem | Asia/Jerusalem,+02:00,IST/IDT |
| Europe/Isle\_of\_Man | Europe/Isle\_of\_Man,+00:00,GMT/BST |
| Asia/Kolkata | Asia/Kolkata,+05:30,IST |
| Indian/Chagos | Indian/Chagos,+06:00, |
| Asia/Baghdad | Asia/Baghdad,+03:00,AST |
| Asia/Tehran | Asia/Tehran,+03:30,IRST |
| Atlantic/Reykjavik | Atlantic/Reykjavik,+00:00,GMT |
| Europe/Rome | Europe/Rome,+01:00,CET/CEST |
| Europe/Jersey | Europe/Jersey,+00:00,GMT/BST |
| America/Jamaica | America/Jamaica,-05:00,EST |
| Asia/Amman | Asia/Amman,+03:00,AST |
| Asia/Tokyo | Asia/Tokyo,+09:00,JST |
| Africa/Nairobi | Africa/Nairobi,+03:00,EAT |
| Asia/Bishkek | Asia/Bishkek,+06:00,KGT |
| Asia/Phnom\_Penh | Asia/Phnom\_Penh,+07:00,ICT |
| Pacific/Enderbury | Pacific/Enderbury,+13:00,PHOT |
| Pacific/Kiritimati | Pacific/Kiritimati,+14:00,LINT |
| Pacific/Tarawa | Pacific/Tarawa,+12:00,GILT |
| Indian/Comoro | Indian/Comoro,+03:00,EAT |
| America/St\_Kitts | America/St\_Kitts,-04:00,AST |
| Asia/Dubai | Asia/Dubai,+04:00,GST |
| Asia/Kabul | Asia/Kabul,+04:30,AFT |
| America/Antigua | America/Antigua,-04:00,AST |
| America/Anguilla | America/Anguilla,-04:00,AST |
| Europe/Tirane | Europe/Tirane,+01:00,CET/CEST |
| Asia/Yerevan | Asia/Yerevan,+04:00,AMT |
| Africa/Luanda | Africa/Luanda,+01:00,WAT |
| Antarctica/Macquarie | Antarctica/Macquarie,+11:00, |
| America/Argentina/Buenos\_Aires | America/Argentina/Buenos\_Aires,-03:00,ART |
| America/Argentina/Catamarca | America/Argentina/Catamarca,-03:00,ART |
| America/Argentina/Cordoba | America/Argentina/Cordoba,-03:00,ART |
| America/Argentina/Jujuy | America/Argentina/Jujuy,-03:00,ART |
| America/Argentina/La\_Rioja | America/Argentina/La\_Rioja,-03:00,ART |
| America/Argentina/Mendoza | America/Argentina/Mendoza,-03:00,ART |
| America/Argentina/Rio\_Gallegos | America/Argentina/Rio\_Gallegos,-03:00,ART |
| America/Argentina/Salta | America/Argentina/Salta,-03:00,ART |
| America/Argentina/San\_Juan | America/Argentina/San\_Juan,-03:00,ART |
| America/Argentina/San\_Luis | America/Argentina/San\_Luis,-03:00,ART |
| America/Argentina/Tucuman | America/Argentina/Tucuman,-03:00,ART |
| America/Argentina/Ushuaia | America/Argentina/Ushuaia,-03:00,ART |
| Pacific/Pago\_Pago | Pacific/Pago\_Pago,-11:00,SST |
| Europe/Vienna | Europe/Vienna,+01:00,CET/CEST |
| Australia/Adelaide | Australia/Adelaide,+09:30,ACST |
| Australia/Brisbane | Australia/Brisbane,+10:00,AEST |
| Australia/Broken\_Hill | Australia/Broken\_Hill,+09:30,ACST |
| Australia/Currie | Australia/Currie,+10:00,AEST |
| Australia/Darwin | Australia/Darwin,+09:30,ACST |
| Australia/Eucla | Australia/Eucla,+08:45\_CWST |
| Australia/Hobart | Australia/Hobart,+10:00,AEST |
| Australia/Lindeman | Australia/Lindeman,+10:00,AEST |
| Australia/Lord\_Howe | Australia/Lord\_Howe,+10:30,LHST |
| Australia/Melbourne | Australia/Melbourne,+10:00,AEST |
| Australia/Perth | Australia/Perth,+08:00,AWST |
| Australia/Sydney | Australia/Sydney,+10:00,AEST |
| America/Aruba | America/Aruba,-04:00,AST |
| Europe/Mariehamn | Europe/Mariehamn,+02:00,EET/EEST |
| Asia/Baku | Asia/Baku,+04:00,AZT/AZST |
| Europe/Sarajevo | Europe/Sarajevo,+01:00,CET/CEST |
| America/Barbados | America/Barbados,-04:00,AST |
| Asia/Dhaka | Asia/Dhaka,+06:00,BDST |
| Europe/Brussels | Europe/Brussels,+01:00,CET/CEST |
| Africa/Ouagadougou | Africa/Ouagadougou,+00:00,GMT |
| Europe/Sofia | Europe/Sofia,+02:00,EET/EEST |
| Asia/Bahrain | Asia/Bahrain,+03:00,AST |
| Africa/Bujumbura | Africa/Bujumbura,+02:00,CAT |
| Africa/Porto-Novo | Africa/Porto-Novo,+01:00,WAT |
| America/St\_Barthelemy | America/St\_Barthelemy,-04:00,AST |
| Atlantic/Bermuda | Atlantic/Bermuda,-04:00,AST/ADT |
| Asia/Brunei | Asia/Brunei,+08:00,BNT |
| America/La\_Paz | America/La\_Paz,-04:00,BOT |
| America/Bonaire | America/Bonaire,-04:00,AST |
| America/Araguaina | America/Araguaina,-03:00,BRT |
| America/Bahia | America/Bahia,-03:00,BRT |
| America/Belem | America/Belem,-03:00,BRT |
| America/Boa\_Vista | America/Boa\_Vista,-04:00,AMT |
| America/Campo\_Grande | America/Campo\_Grande,-04:00,AMT/AMST |
| America/Cuiaba | America/Cuiaba,-04:00,AMT/AMST |
| America/Eirunepe | America/Eirunepe,-04:00,AMT |
| America/Fortaleza | America/Fortaleza,-03:00,BRT |
| America/Maceio | America/Maceio,-03:00,BRT |
| America/Manaus | America/Manaus,-04:00,AMT |
| America/Noronha | America/Noronha,-02:00,FNT |
| America/Porto\_Velho | America/Porto\_Velho,-04:00,AMT |
| America/Recife | America/Recife,-03:00,BRT |
| America/Rio\_Branco | America/Rio\_Branco,-04:00,AMT |
| America/Santarem | America/Santarem,-03:00,AMT |
| America/Sao\_Paulo | America/Sao\_Paulo,-03:00,BRT/BRST |
| America/Nassau | America/Nassau,-05:00,EST/EDT |
| Asia/Thimphu | Asia/Thimphu,+06:00,BTT |
| Antarctica/Bouvet | Antarctica/Bouvet,+00:00,BVIS |
| Africa/Gaborone | Africa/Gaborone,+02:00,CAT |
| Europe/Minsk | Europe/Minsk,+03:00,EET/EEST |
| America/Belize | America/Belize,-06:00,CST |
| America/Atikokan | America/Atikokan,-05:00,EST |
| America/Blanc-Sablon | America/Blanc-Sablon,-04:00,AST |
| America/Cambridge\_Bay | America/Cambridge\_Bay,-07:00,MST/MDT |
| America/Coral\_Harbour | America/Coral\_Harbour,-05:00,EST |
| America/Dawson | America/Dawson,-08:00,PST/PDT |
| America/Dawson\_Creek | America/Dawson\_Creek,-07:00,MST |
| America/Edmonton | America/Edmonton,-07:00,MST/MDT |
| America/Glace\_Bay | America/Glace\_Bay,-04:00,AST/ADT |
| America/Goose\_Bay | America/Goose\_Bay,-04:00,AST/ADT |
| America/Halifax | America/Halifax,-04:00,AST |
| America/Inuvik | America/Inuvik,-07:00,MST/MDT |
| America/Iqaluit | America/Iqaluit,-05:00,EST/EDT |
| America/Moncton | America/Moncton,-04:00,AST/ADT |
| America/Montreal | America/Montreal,-05:00,EST/EDT |
| America/Nipigon | America/Nipigon,-05:00,EST/EDT |
| America/Pangnirtung | America/Pangnirtung,-05:00,EST/EDT |
| America/Rainy\_River | America/Rainy\_River,-06:00,CST/CDT |
| America/Rankin\_Inlet | America/Rankin\_Inlet,-06:00,CST/CDT |
| America/Regina | America/Regina,-06:00,CST |
| America/Resolute | America/Resolute,-06:00,EST/EDT |
| America/St\_Johns | America/St\_Johns,-03:30,NST/NDT |
| America/Swift\_Current | America/Swift\_Current,-06:00,CST |
| America/Thunder\_Bay | America/Thunder\_Bay,-05:00,EST/EDT |
| America/Toronto | America/Toronto,-05:00,EST/EDT |
| America/Vancouver | America/Vancouver,-08:00,PST/PDT |
| America/Whitehorse | America/Whitehorse,-08:00,PST/PDT |
| America/Winnipeg | America/Winnipeg,-06:00,CST/CDT |
| America/Yellowknife | America/Yellowknife,-07:00,MST/MDT |
| Europe/Andorra | Europe/Andorra,+01:00,CET/CEST |

---

## 附錄 9：擴展屬性

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=67（文檔中心 > API文檔 > 附錄 > 9.擴展屬性；官方更新 2025-02-28；爬取 2026-09-23）

| 字段屬性名 | 類型（約束） | 描述 |
| ----- | ------ | --- |
| receiverDoorplate | string(200) | 收件方門牌號 |
| isGenBillNo | int(1) | 是否申請運單號（1代表true） |
| isGenEletricPic | int(1) | 是否生成電子運單圖片（1代表true） |
| returnTrackingNo | string(30) | 簽單返回運單號 |
| transporter | decimal(10,2) | 運輸方 |
| agentCode | string(50) | 代理code |
| customerErpCode | string(50) | 客戶ERP的標識碼 |
| taxNO | string(128) | 稅號 |
| ebcOrderId | string(100) | 電商平台訂單號 |
| isLiquid | int(5) | 是否液體（0：非液體，1：液體） |
| ebcPlatformCode | string(50) | 電商平台code |
| ebcPlatformId | string(100) | 電商平台ID |
| ebcErpCode | varchar(50) | 電商erp代碼 |
| ebcCategory | string(50) | 電商商品分類 |
| ebcSubCarrierNo | string(60) | 電商轉單號 |
| ebcAbn | string(20) | 在澳洲有註冊公司的企業，基於其ABN號走標準流程註冊的號碼 |
| ebcBusinessCode | string(50) | 在澳洲有註冊公司的企業, 在澳大利亞的商業編號 |
| ebcWebsite | string(200) | 電商商品網站 |
| ebcShopName | String(500) | 電商店鋪名稱 |
| discountFreight | decimal(11,3) | 客戶申報運費 |
| customizeFreightFeeCurrency | String(10) | 客戶申報運費幣種 2025年3月3日申報運費幣種字段生效，屆時必須同時傳“客戶申報運費”及“客戶申報運費幣種” |

---

## 附錄 10：報關方式屬性

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=68（文檔中心 > API文檔 > 附錄 > 10.報關方式屬性；官方更新 2025-09-17；爬取 2026-09-23）

| 報關方式代碼 | 報關方式名稱 |
| ------ | ------ |
| 1 | 簡易報關 |
| 2 | 正式報關 |
| 3 | 海運報關 |
| 4 | 個人物品 |
| 5 | 簡易-銷售（台灣出口用） |
| 6 | 簡易-樣品（台灣出口用） |
| 7 | 跨境直郵 |
| 8 | 跨境保稅 |
| 9 | 個人行李 |
| 10 | 自清關 |
|11|跨境直郵-自主申報|

---

## 附錄 11：貿易條件屬性值

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=69（文檔中心 > API文檔 > 附錄 > 11.貿易條件屬性值；官方更新 2022-07-27；爬取 2026-09-23）

| 貿易條件 | 貿易條件說明 |
| ---- | ------ |
| CFR | C . F . R |
| CIF | C . I . F |
| CIP | C . I . P |
| CPT | C . P . T |
| DDP | D . D . P |
| DPU | D . P . U |
| EXW | E . X . W |
| FAS | F . A . S |
| FCA | F . C . A |
| FOB | F . O . B |
| DAP | D . A . P |

---

## 附錄 12：報關資料類型

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=71（文檔中心 > API文檔 > 附錄 > 12.報關資料類型；官方更新 2025-09-22；爬取 2026-09-23）

| 代碼 | 報關資料類型 |
| --- | ------ |
| 500 | 其他報關資料 |
| 501 | 商業發票 |
| 502 | 合同 |
| 503 | 價格證明 |
| 504 | 裝箱單 |
| 505 | 報關委託書 |
| 506 | 報關單 |
| 507 | 機票 |
| 508 | 簽證 |
| 509 | 申報要素說明 |
| 510 | 出口醫療物資聲明 |
| 511 | 醫療器械註冊證 |
| 512 | 報檢電子底帳編號 |
| 513 | 檢測報告及合格證（廠檢單） |
| 514 | 出口非醫療物資確認函 |
| 515 | 出口方和進口方共同聲明 |
| 516 | 無不良記錄 |
| 517 | 個案委任書 |
| 518 | 販賣製造許可證書 |
| 520 | 核消單 |
| 521 | 進出境旅客行李物品申報單 |
| 522 | 進境行李物品清單 |
| 354| 進口發票 |
| 355| 進口其他單證|

---

## 附錄 15：收派範圍列表

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=209（文檔中心 > API文檔 > 附錄 > 15.收派範圍列表；官方更新 2024-07-15；爬取 2026-09-23）

| 收派範圍編碼 | 收派範圍服務 |
| ------ | ------ |
|11	|上門攬收✓ 網點自寄✓ 上門派送✓ 網點自提✓|
|12	|上門攬收✓ 網點自寄✓ 上門派送✓ 網點自提×|
|13	|上門攬收✓ 網點自寄✓ 上門派送× 網點自提✓|
|14	|上門攬收✓ 網點自寄✓ 上門派送× 網點自提×|
|21	|上門攬收✓ 網點自寄× 上門派送✓ 網點自提✓|
|22	|上門攬收✓ 網點自寄× 上門派送✓ 網點自提×|
|23	|上門攬收✓ 網點自寄× 上門派送× 網點自提✓|
|24	|上門攬收✓ 網點自寄× 上門派送× 網點自提×|
|31	|上門攬收× 網點自寄✓ 上門派送✓ 網點自提✓|
|32	|上門攬收× 網點自寄✓ 上門派送✓ 網點自提×|
|33	|上門攬收× 網點自寄✓ 上門派送× 網點自提✓|
|34	|上門攬收× 網點自寄✓ 上門派送× 網點自提×|
|41	|上門攬收× 網點自寄× 上門派送✓ 網點自提✓|
|42	|上門攬收× 網點自寄× 上門派送✓ 網點自提×|
|43	|上門攬收× 網點自寄× 上門派送× 網點自提✓|
|44	|上門攬收× 網點自寄× 上門派送× 網點自提×|

---

## 附錄 16：本地化參數

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=229（文檔中心 > API文檔 > 附錄 > 16.本地化參數；官方更新 2025-10-17；爬取 2026-09-23）

### Key值-語言

| 語言 | Key值|
| --- | --- |
| 簡體中文| localZhCN  |
| 繁體中文| localZhTW  |
| 日文| localJP  |

### Value-LocalizedDetail對象字段
| **#** | **屬性名** | **類型（約束）** | **必填** | **描述** |
| --- | --- | ------ | --- | --- |
| 1 | receiverName | String | N  | 收件人姓名  |
| 2 | receiverAddress | String |  N | 收件人地址  |
| 3 | senderName | String | N  | 寄件人姓名  |
| 4 | senderAddress | String |  N | 寄件人地址  |

### 例子
```
{
  "localJP": {
    "receiverName": "receiverNameJP",
    "receiverAddress": "receiverAddressJP",
    "senderName": "senderNameJP",
    "senderAddress": "senderAddressJP"
  },
  "localZhCN": {
    "receiverName": "receiverNameCN",
    "receiverAddress": "receiverAddressCN",
    "senderName": "senderNameCN",
    "senderAddress": "senderAddressCN"
  }
}
```
