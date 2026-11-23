---
name: sf-express
description: 順豐快遞 / 順豐速運 / 順豐國際（SF Express、SF International）開放平台 SF Global Open API 完整參考（2026-09-23 爬取版），聚焦台灣寄件、台灣收件、兩岸件（TW↔CN、TW↔HK/MO/海外）。涵蓋 Access Token、dispatch + msgType、AES 加解密與 signature、IUOP 國際快遞建單/改單/查單/取消/面單/運費試算/清關資料、IECS 出口電商、路由查詢與軌跡推送 webhook、opCode、產品時效運費推薦、POD、稅金、國際區劃（台灣郵編、縣市英文名）、跨境運輸 IFTP、關務、附錄字典（產品映射碼、幣種 NTD、證件 009 統編號）與錯誤碼，附 PHP（WordPress）與 TypeScript client。當需要串接順豐國際寄件 / 收件、建單、列印面單、試算運費、查貨態、接收軌跡推送，或程式碼出現下列關鍵字時使用：順豐、順豐速運、順豐快遞、順豐國際、SF Express、sf-express、IUOP、IECS、openapi-portal.sf.global、api-ifsp.sf.global、IUOP_CREATE_ORDER、IUOP_PRINT_ORDER、IUOP_QUERY_FREIGHT、IUOP_QUERY_TRACK、GTS_QUERY_TRACK、IECS_CREATE_ORDER、ICMS_、IPE_、msgType、customerCode、interProductCode、sfWaybillNo、payMonthCard、月結卡號、encodingAesKey、apiResultCode。本 skill 為順豐國際開放平台唯一 reference 來源。邊界：黑貓走 tcat-logistics、新竹物流走 hct-logistics、PAYUNi 物流走 payuni-logistics-v3；台灣島內件（TW→TW）正式可否下單待確認，島內宅配預設走上述三個 skill；海外本地件（馬來西亞/印尼/越南）與中國大陸境內件不在本 skill。
---

# 順豐國際開放平台（SF Global Open API）— 台灣聚焦版

順豐國際（SF International / IBU）開放平台：**HTTPS + 單一 dispatch 入口**，header `msgType` 決定呼叫哪支業務接口；**body 是 AES 密文字串**、header 帶 SHA-256 signature；回應 `apiResultData` 也是密文需解密。台灣相關的寄件、收件、兩岸件全部屬「**國際件 API**」（快遞 IUOP / 電商 IECS）。

> **文件版本**：官方 portal `https://openapi-portal.sf.global`（SPA）**選單 117 頁 + 首頁連入 1 頁**（第 84 頁 `IUOP_QUERY_TRACK`）的 zh-CN 原文（各頁官方更新日期 2022-07 ～ 2026-09-21），**爬取日期 2026-09-23**，轉繁體中文；英文版交叉比對。加解密以官方 Php/JS/Java/Python SampleCode 逐行比對，並用官方公開沙箱憑證實測唯讀接口成功（`references/auth-and-crypto.md` §0.8）。Portal 沒有版本號，以各頁官方更新日期為準（`references/sources.md`）。

---

## 與既有 skill 的邊界（先選對路）

| Skill | 物流商 / 平台 | 何時用 |
|---|---|---|
| **`sf-express`（本 skill）** | 順豐國際開放平台 `api-ifsp.sf.global` | 台灣寄出國際/兩岸件、寄到台灣、兩岸跨境電商；順豐 IUOP / IECS 月結客戶自建系統串接 |
| `tcat-logistics` | 黑貓宅急便（統一速達）印單 API | 台灣島內黑貓宅配、自有速達契約 |
| `hct-logistics` | 新竹物流 EDI WebServices（SOAP） | 台灣島內新竹物流 |
| `payuni-logistics-v3` | PAYUNi 統一金流物流（7-11 / 黑貓代叫） | 經 PAYUNi 代開的超商 / 宅配單 |

**邊界外 / 未定**：
- **海外本地件 API**（`IUOP_OS_*`）：2026-09-23 爬取版 portal 僅列馬來西亞 / 印尼 / 越南，與台灣無關，只在 `references/sources.md` 標出邊界。
- **中國大陸境內件**：portal 文件無對應說明，本 skill 未收錄。
- **台灣島內件（TW→TW）**：文件沒有台灣島內件說明。[實測 2026-09-23] 沙箱 `IPE_QUERY_PRODUCT_FREIGHT` 查 TW 10048 → TW 80141 回傳 13 個產品，含 `INT0005` 順豐特快（承諾隔日 18:00 前送達，23 NTD）、`INT0008`（16 NTD），以及附錄 2 沒有列出的 `INT0202` Taiwan E-commerce（請求與完整結果見 `references/taiwan-rules.md` §2.1）；`INT0005` 也出現在 TW→CN、TW→HK 推薦，非島內專屬。[實測 2026-09-23] 沙箱 `IUOP_PRE_ORDER`（官方功能描述「Verify shipment info」，只校驗、不回運單號）對 TW→TW + `INT0005` / `INT0202` 都回 `code 0`；同一接口對 TW→TW + `INT0657` 會回 `123296` 線路未開通。沙箱結果不代表正式環境已開通（`references/taiwan-rules.md` §2.2）。**正式環境能否下 TW→TW 單、該用哪個產品，要先向順豐台灣客戶經理確認，確認前不要上線**；島內宅配預設走上表三個 skill。

**判斷法**：看 domain。`api-ifsp.sf.global` / `api-ifsp-sit.sf.global` + header `msgType` + `signature` → 本 skill。

---

## References 導引（先看這張表決定要讀哪份）

**定位技巧**：大檔（common-apis / iuop-order-ops / address-region / iecs-ecommerce / iftp / dictionaries）先讀檔頭到目錄結束為止（約前 20–40 行：何時讀、台灣重點、目錄），再 `grep -n "<msgType>" references/*.md` 或 `grep -n "^## " references/<檔名>` 取行號，用 Read 的 offset / limit 只讀該節。`taiwan-rules.md` 可整份讀。

| 需求 | 參閱檔案 |
|---|---|
| token / dispatch header / AES 位元組格式 / signature / 回應解密 / ISV authCode / SDK 錯誤碼 / 驗證紀錄 | [references/auth-and-crypto.md](references/auth-and-crypto.md) |
| **可直接複製的 PHP（WordPress）client、webhook 接收、TypeScript 版、測試向量** | [references/php-client.md](references/php-client.md) |
| 台灣寄件 / 收件 / 兩岸 / 島內件實測：流向 × 產品、帳號權限、欄位規則、報關、會擋單的錯誤碼、待確認清單 | [references/taiwan-rules.md](references/taiwan-rules.md) |
| `IUOP_CREATE_ORDER` 全部欄位（OrderExtendInfo / PaymentInfo / SenderInfo / ReceiverInfo / CustomsInfo / ParcelInfo / AddServiceInfo / ChildInfo / OrderEcommerceInfo…）+ 請求 / 回應範例 | [references/iuop-create-order.md](references/iuop-create-order.md) |
| `IUOP_PRE_ORDER` 預創建校驗、信任重量 / 二次信任重量建單完整欄位 | [references/iuop-order-variants.md](references/iuop-order-variants.md) |
| IUOP 查單、取消、面單（同步 / 非同步）、運費預估、預估總費用、清關證件、BBD、快遞櫃 | [references/iuop-order-ops.md](references/iuop-order-ops.md) |
| 路由查詢（`GTS_QUERY_TRACK` / 舊版 `IUOP_QUERY_TRACK` 對照）、按單訂閱、軌跡推送（IP 白名單、sign、body、回應格式）、**附錄 14 opCode 全表**與映射建議 | [references/tracking.md](references/tracking.md) |
| 產品 / 運費 / 時效推薦、收寄標準、計費重、POD、稅金支付、代理商接口 | [references/common-apis.md](references/common-apis.md) |
| 出口電商 IECS 全部接口（CN/HK 始發 → 台灣電商小包） | [references/iecs-ecommerce.md](references/iecs-ecommerce.md) |
| 關務：清關資料 URL 上傳、9610 申報（含中國海關幣制 / 計量單位附錄）、發票資訊 / 檔案 | [references/customs-apis.md](references/customs-apis.md) |
| 跨境運輸 IFTP（空 / 海 / 陸 / 鐵大貨） | [references/iftp-cross-border-transport.md](references/iftp-cross-border-transport.md) |
| 國際區劃 12 支接口 + **台灣沙箱實測**（郵編規則、縣市清單、郵編→區劃範例） | [references/address-region.md](references/address-region.md) |
| 附錄 1–12、15、16（附錄 13 響應碼在 error-codes.md；附錄 14 opCode 在 tracking.md）：msgType 舊表、產品映射碼、幣種、國家碼、增值服務、證件類型、時區、擴展屬性、報關方式、貿易條件、報關資料類型、收派範圍、本地化參數 | [references/dictionaries.md](references/dictionaries.md) |
| 附錄 13 平台響應碼、IUOP 常見異常（123/124/126/127/129xxx…）、IECS 常見異常（9xxx）、其他接口業務碼 | [references/error-codes.md](references/error-codes.md) |
| 接入流程、三種對接角色、沙箱憑證（§8，單一來源）、正式金鑰取得、在線調試 / 加解密工具、官方 MCP / Skill、開發 FAQ | [references/onboarding-and-faq.md](references/onboarding-and-faq.md) |
| 選單 117 頁 + 首頁連入 1 頁的來源 URL、官方更新日期、英文版有無、收錄位置、**隱藏頁與未取得 / 需登入清單** | [references/sources.md](references/sources.md) |

---

## 環境與端點

| 用途 | 沙箱 | 正式 | Method |
|---|---|---|---|
| Access Token | `http://api-ifsp-sit.sf.global/openapi/api/token` | `https://api-ifsp.sf.global/openapi/api/token` | GET `?appKey=&appSecret=` |
| 全部業務接口 | `http://api-ifsp-sit.sf.global/openapi/api/dispatch` | `https://api-ifsp.sf.global/openapi/api/dispatch` | POST |

- 沙箱 `http://` 與 `https://` 兩者實測皆可（2026-09-23）；文件偶見筆誤主機 `api-ifsp.sit.sf.global`（點號），正確為 `api-ifsp-sit`。

### 沙箱測試憑證

> ⚠️ **安全警告**：以下為順豐國際**官方公開共用**的沙箱憑證（官方「測試環境授權信息」頁，更新於 2026-02-26），所有開發者共用。
> - **只能用在 `api-ifsp-sit` 沙箱**，對正式環境無效也不可混用。
> - **不可寫成 plugin 程式碼的預設值或 fallback**：appKey / appSecret / AES key / customerCode 一律從設定讀取（`wp_options` 或 `wp-config.php` 常數），沙箱值只在設定頁或測試環境手動填入。
> - 正式憑證不可進版本控制。官方若輪替沙箱憑證，以 `references/onboarding-and-faq.md` §8 為單一來源同步本表。

| 欄位 | 值 |
|---|---|
| appKey | `cdd169d87a4698d314754a79f180308a` |
| appSecret | `69fb3e868a45d0bdd5b0de0ea1db6ece` |
| encodingAesKey | `oI1YdU1cb1YC70HVjZRa3wXBLUsrIUYr5lDh0gFrMbe` |
| 國際件 customerCode | `ICRME000SRN93` |
| 國際件月結卡號 | `9999999999` |
| apiUsername（IECS 必填） | `popsandBox` |
| 海外本地件 customerCode / 月結卡號 | `OSMYICRM-OSMY00009Z38` / `TESTJACK0004`（非台灣） |

- 正式憑證：控制台 `https://openapi.sf.global/` 建立應用取得 appKey / appSecret / AES 密鑰並「關聯 API」（部分 API 關聯後需審核）；customerCode 與月結卡號在 IUOP 後台（`https://iuop.sf-express.com` 或 `https://iuop.sf.global/`），**月結卡號需由順豐地區銷售在 ICRM 綁定**。流程見 `references/onboarding-and-faq.md`。

---

## 認證與簽名（每次 dispatch 都要做）

```
1. token     = GET /openapi/api/token?appKey&appSecret → apiResultData.accessToken（7200 秒；取新的會讓舊的失效；沙箱 token 固定）
2. plain     = json_encode(body)                      // 業務 JSON（UTF-8）
3. encrypt   = base64( AES-256-CBC-NoPadding( PKCS7_32( random16 + uint32BE(byteLen(plain)) + plain + appKey ), key, iv ) )
               key = base64_decode(encodingAesKey + "=")（32 bytes），iv = key 前 16 bytes，PKCS7 block = 32
4. timestamp = 毫秒字串；nonce = 任意隨機字串（UUID）
5. signature = sha256_hex( join("", sort_as_strings([token, timestamp, nonce, encrypt])) )
6. POST dispatch，headers: appKey / token / timestamp / nonce / signature / msgType / lang / Content-Type: application/json
   body = encrypt 字串本身（不是 JSON、不是 key=value、不要加換行）
7. 回應 {apiResultCode, apiErrorMsg, apiResultData, apiTimestamp}：apiResultCode === 0 → 解密 apiResultData → {success, code, msg, data}
```

PHP 核心（完整 client、token 快取、webhook 見 `references/php-client.md`，已驗證）：

```php
<?php
function sf_encrypt(string $plain, string $appKey, string $aesKey): string {
    $key = base64_decode($aesKey . '=');                    // 32 bytes
    $data = random_bytes_alnum16() . pack('N', strlen($plain)) . $plain . $appKey;
    $pad = 32 - (strlen($data) % 32);                       // 1..32
    $data .= str_repeat(chr($pad), $pad);
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16)));
}
function sf_decrypt(string $b64, string $appKey, string $aesKey): string {
    $key = base64_decode($aesKey . '=');
    $p = openssl_decrypt(base64_decode($b64), 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
    $pad = ord(substr($p, -1)); if ($pad < 1 || $pad > 32) { $pad = 0; }
    $p = substr($p, 0, strlen($p) - $pad);
    $len = unpack('N', substr($p, 16, 4))[1];
    if (substr($p, 20 + $len) !== $appKey) { throw new RuntimeException('appKey mismatch'); }
    return substr($p, 20, $len);
}
function sf_signature(string $token, string $ts, string $nonce, string $encrypt): string {
    $a = [$token, $ts, $nonce, $encrypt]; sort($a, SORT_STRING);
    return hash('sha256', implode('', $a));
}
function random_bytes_alnum16(): string {
    $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; $s = '';
    for ($i = 0; $i < 16; $i++) { $s .= $pool[random_int(0, 61)]; }
    return $s;
}
```

- `lang` header 只有 `en` / `zh-CN` / `zh-HK`（繁體）；**沒有 `zh-TW`**。實測 `zh-HK` 時業務錯誤訊息為繁體。
- 第三方 ERP（ISV）應用另帶 header `authCode`（ERP 用戶授權碼）。
- 推送（webhook）不加密，另一套簽名：header `sign = Base64(MD5(key + rawBody + key))`。

**成功判斷兩層**：`apiResultCode === 0`（平台層，非 0 時沒有 `apiResultData`，例 `500 Access denied, token invalid`、`504 系統繁忙`）→ 解密後 `success === true`（業務層；`code` 型別各接口不一：`"0"` / `200000` / `"200"` / `"ok"` / `SUC_ADES-MANAGE_00_0000`）。**HTTP 200 不代表成功。**

---

## 端點索引（msgType）

全部走同一個 dispatch URL。★ = 台灣電商寄件最常用。

**國際快遞 IUOP**（台灣寄件主力；`references/iuop-create-order.md`、`references/iuop-order-variants.md`、`references/iuop-order-ops.md`）

| msgType | 用途 | 重點 |
|---|---|---|
| ★ `IUOP_CREATE_ORDER` | 建單 / 改單（`orderOperateType` 1 新增 / 5 改單，僅順豐收件前） | 回 `sfWaybillNo`、`labelUrl`（面單）、`invoiceUrl`、`identityUploadUrl`；同 msgType 亦用於信任重量模式 |
| `IUOP_PRE_ORDER` | 預創建（只校驗） | `validateModule=1` 一次回全部錯誤明細 |
| ★ `IUOP_QUERY_ORDER` | 查單 | `sfWaybillNo` / `customerOrderNo` 擇一 |
| ★ `IUOP_CANCEL_ORDER` | 取消 | 順豐收件前 |
| `IUOP_PRINT_ORDER` | 面單 / 發票（PDF 或 ZPL 203/300dpi） | 建單已回 `labelUrl`，通常不必呼叫 |
| `IUOP_ASYN_PRINT_ORDER` / `IUOP_ASYN_PRINT_RESULT` | 非同步列印 / 取結果 | 建議 2 分鐘後查、每分鐘 ≤ 1 次 |
| ★ `IUOP_QUERY_FREIGHT` | 基本運費預估（不含附加費） | 有白名單限制時要帶月結卡號 |
| `IUOP_ESTIMATE_FEE` | 運費 + 附加費預估 | 需額外申請開通 |
| `IUOP_UPLOAD_CERTIFY` / `IUOP_VALIDITY_CERTIFY` / `IUOP_REQUIRE_CERTIFY` | 清關證件上傳（base64）/ 有效性 / 需求查詢 | PNG/JPG/PDF，單檔 < 3MB |
| `IUOP_BBD_PRE_MERGE` / `IUOP_BBD_MERGE_RESULT` / `IUOP_CANCEL_BBD_MERGE` | BBD 集運合單 | 一般客戶不用 |
| `IOMS_QUERY_LOCKER` | 快遞櫃查詢 | 官方頁標明只支援新加坡 |

**路由 / 推送**（`references/tracking.md`）

| msgType / 方向 | 用途 |
|---|---|
| ★ `GTS_QUERY_TRACK` | 路由查詢（≤ 10 單，需下單手機**後四碼**；官方定位為推送的補充） |
| `IUOP_QUERY_TRACK` | 舊版路由軌跡查詢（第 84 頁，**不在左側選單**，首頁卡片連入）：`customerCode` + `sfWaybillNo`，**不需手機後四碼、一次一單**、每分鐘 ≤ 600 次；單號錯誤時整體 `success=true`、錯誤在該單 `trackSummary`（沙箱實測可用） |
| `GTS_COM_ROUTE_SUBSCRIBE` | 按運單號訂閱推送（需已對接推送；`trackType=4` 繁體路由） |
| ★ 運單軌跡推送（順豐 → 你的 URL） | POST JSON array、≤ 20 筆、無序、`sign` 驗章；需 email 客戶經理 + `open_intl@sf-express.com` 聯調開通 |
| 附錄 14 路由操作碼 opCode | `50` 已收件、`30/31` 裝車/到達、`44` 派送中、`80` 已簽收、`70` 異常、`99` 轉寄退回、`634` 港澳台二程派件… |

**通用 / 試算 / 對帳 / POD / 稅金**（`references/common-apis.md`）

| msgType | 用途 |
|---|---|
| `IPE_QUERY_PRODUCT_LIST` | 帳號可用國際產品清單（body `{}`） |
| `IPE_QUERY_PRODUCT_FREIGHT` | 依寄收國（港澳台用 HK/MO/TW）推薦產品 + 運費 + 時效 |
| `IPE_ESTIMATE_FEE` | 預估總運費（含增值服務） |
| `IPT_QUERY_PROMITM` | 預計派件時間（承諾時效） |
| `SOP_COLLECT_SHIP_STANDARD` | 品名收寄標準（可收 / 禁寄 / 有條件） |
| `IOMS_QUERY_CHARGE` / `IOMS_WAYBILL_BILL` | 計費重與費用項 / 運單費用明細 |
| `IOMS_SEND_PROVE_ONEFILE` / `IOMS_SEND_PROVE_CREATE` + `IOMS_SEND_PROVE_QUERY` / `ADES_AGENT_QUERY_POD` | 派送證明（單票 / 批量非同步 / 尾程供應商） |
| `IOMS_TAX_PAYMENT` / `IOMS_TAX_PAYMENT_LINK` / `IOMS_PAYMENT_LINK` | 稅金資訊 / 稅金支付連結 / 線上支付短連結 |
| `ICSM_CN_TAX_QUERY` | 中國大陸進口稅金（需申請） |
| `ADES_UPLOAD_POD` / `ADES_AGENT_UPDATE_WAYBILL` | 代理商（尾程）上傳 POD / 更新運單 |

**出口電商 IECS**（通常僅 CN/HK 始發；`references/iecs-ecommerce.md`）：`IECS_CREATE_ORDER`（不可改單）、`IECS_PRINT_ORDER`、`IECS_CANCEL_ORDER`、`IECS_ORDER_INTERCEPT`、`IECS_QUERY_AGENTNO`、`IECS_QUERY_WEIGHT_FREIGHT`、`IECS_UPLOAD_WEIGHT`、`IECS_IMPORT_PACKAGE`、`IECS_QUERY_POD`、`IECS_AGENT_TRANS`、`IECS_BIG_PACKAGE_BIND`。

**關務**（`references/customs-apis.md`）：`ICSM_UPLOAD_CERTIFY`（檔案 URL 上傳清關資料）、`ICSM_UPLOAD_NSOZ`（9610 申報，大陸出口）、`ICSM_INVOICE_INFO_UPLOAD` / `ICSM_INVOICE_FILE_UPLOAD`（官方標「待發布」，泰國場景）。

**國際區劃**（`references/address-region.md`）：`ICMS_QUERY_REGION_BY_POST_CODE`（★ 台灣郵編 → regionFirst/Second）、`ICMS_QUERY_POST_CODE_BY_REGION`、`ICMS_QUERY_POST_CODE_INFO`、`ICMS_QUERY_POST_CODE_VAGUE`、`ICMS_QUERY_POST_CODE_ATTRIBUTE`、`ICMS_QUERY_COUNTRY_LIST`、`ICMS_QUERY_PROVINCE_LIST`、`ICMS_QUERY_CITY_LIST`、`ICMS_GET_CONTINENT_LIST`、`ICMS_REGION_CASCADE_QUERY`、`ICMS_REGION_VAGUE_QUERY`、`ICMS_PHONE_VALID_CHECK`。

**跨境運輸 IFTP**（B2B 空海陸鐵大貨，非台灣電商；`references/iftp-cross-border-transport.md`）：`IFTP_CREATE_ORDER`、`IFTP_QUERY_ORDER`、`IFTP_PRINT_ORDER`、`IFTP_CANCEL_ORDER`、`IFTP_UPLOAD_CERTIFY`、`SF_IFTP_RECEIVE_GPS_TRACK`、`SF_IFTP_RECEIVE_COST_DETAILS`。

---

## 台灣專節（寄件 / 收件 / 兩岸 / 島內）

完整欄位矩陣與出處見 `references/taiwan-rules.md`。

**定位**
- 台灣國家碼 `TW`（官方名「中国台湾」），台灣相關件一律用國際件 API。帳號用 IUOP（港澳台可用 `iuop.sf.global`）。
- 島內件 TW→TW 見上方邊界節。海外本地件在爬取版 portal 僅列馬來西亞 / 印尼 / 越南。

**流向與產品**（產品代碼見 `references/dictionaries.md` 附錄 2；實際可用以 `IPE_QUERY_PRODUCT_LIST` / `IPE_QUERY_PRODUCT_FREIGHT` 與簽約為準）

| 流向 | API | 產品原則 |
|---|---|---|
| 台灣 → 大陸 | `IUOP_CREATE_ORDER` | 收件國 CN 屬「國際標快」清單 → `INT0007` 文件 / `INT0008` 包裹；或國際特惠 `INT0013`/`INT0014` |
| 台灣 → 港澳 / 歐美等 | `IUOP_CREATE_ORDER` | 收件國不在 `CN,AU,ID,MY,SG,TH,VN,JP,KR,KH,MM,MN` → 國際標快+ `INT0137`/`INT0139` |
| 大陸 / 香港 → 台灣 | 快遞 `IUOP_CREATE_ORDER`；電商小包 `IECS_CREATE_ORDER` | TW 不在標快清單 → 標快+；電商 `INT0255`/`INT0257`/`INT0263`/`INT0054`（IECS 僅 CN/HK 始發） |
| `INT0054` 國際小包 / `INT0263` 電商專遞-CD | CN/HK 始發用 IECS，**其餘（含台灣始發）用 IUOP** | |
| 台灣島內 TW→TW | 文件無說明 | 沙箱推薦含 `INT0005`（23 NTD，隔日 18:00；亦見於 TW→CN / TW→HK，非島內專屬）、`INT0008`（16 NTD）、`INT0202` Taiwan E-commerce（附錄 2 未列）；**正式待確認** |

**必填 / 格式（台灣特有）**
- `senderInfo.cargoType`：**CN / TW / KR 始發必填**（1 個人件、2 公司件）→ 缺了 `123057`。
- `receiverInfo.certType` + `certCardNo`：**跨境件收件國為 TW（及 ID、KR）必填**。證件碼：`001` 身份證、`002` 護照、`006` 台灣通行證、`009` **統編號**（公司件；`124080` 寄件方統編號必填 / `124149` 收件方統編號必填）、`023` 居留證。台灣個人收件人該用哪個代碼官方未明寫 **[待確認]**。
- 幣種：新台幣 = **`NTD`**（SF 幣種表沒有 `TWD`；實測傳 TWD 回應也是 NTD）。
- 郵編：**5 或 6 碼**（實測 `^[0-9]{5,6}$`；3 碼郵遞區號被拒）。
- 區劃：`regionFirst` = 縣市英文（`Taipei City`）、`regionSecond` = 區英文（`Zhongzheng District`）、`regionThird` 空；用 `ICMS_QUERY_REGION_BY_POST_CODE` 取標準值（沙箱回傳 18 縣市，缺澎湖 / 金門 / 連江 / 雲林 **[待確認]**）。
- 電話區號 `886`（純數字，不可 `+886`）；時區 `Asia/Taipei`；繁體本地化 key `localZhTW`。
- 地址：部分流向要求英文（`124118`/`124119`），中文放 `addressI18n` / `contactI18n` / `localizedInfo`。

**報關 / 稅金 / 付款**
- 出口報關方式 `exportCustomsType`：**`5` 簡易-銷售（台灣出口用）**、**`6` 簡易-樣品（台灣出口用）**。
- 稅金通常 `taxPayMethod=2` 收方付；寄付 / 第三方付需 `taxPayMonthCard` + 另簽稅金（DDP）協議（中國大陸始發除外），否則 `123014`。
- 月結卡號需先由銷售綁定（`123058`）；COD `IN01/COD` 金額以**到件地幣種**、需月結開通權限（`129013`）。
- 兩岸：大陸收件個人物品需證件（`123102`），證件為身份證時收方姓名須中文（`124219`）；大陸出口電商可能需 9610 申報與 `customsExtInfoMap`（生產銷售單位，證件類型 `007`）。

**語言**：dispatch `lang: zh-HK` 取繁體訊息；推送路由可指定繁體中文（IUOP）；IECS 推送只有英文。

---

## 常見坑

1. **body 放錯**：body 必須是純密文字串；包成 `{"data": "..."}` 或加換行 → `签名验证错误`。`Content-Type` 不是 `application/json` → `system error`（FAQ）。
2. **PKCS7 block 是 32 不是 16**；IV 是 key 前 16 bytes 不是隨機；長度欄位是 UTF-8 **byte** 長度（中文 body 用 `mb_strlen` 會壞）。
3. **signature 要排序**：四個字串做字典序排序後串接，不是固定順序；timestamp / nonce 必須與 header 同值。Java 樣例 `SHA1` 類別裡要用 `getSHA2`。
4. **token 互踢**：新 token 讓舊 token 立即失效；多程序 / 多站台必須共享快取。沙箱 token 固定所以測不出來，上正式才出現 `500 Access denied, token invalid`。
5. **兩層成功判斷**：`apiResultCode` 0 之後還要解密看 `success`；平台錯誤時沒有 `apiResultData` 可解。`IUOP_QUERY_TRACK` 另有第三層：逐單看 `trackSummary`。
6. **`declaredValue` 已下線**（IUOP 建單）：申報價值以 `parcelInfoList[].amount × quantity` 為準；幣種用 `NTD`。
7. **客戶訂單號重複 `123052`**：可能是上次其實成功但沒收到回應——官方建議用 `orderOperateType=5` 同單號重送，已成功會直接回原單資料。
8. **IECS 不可改單**：只能先 `IECS_CANCEL_ORDER`（攬收前）再重建；IECS body 用 `apiUsername` 不是 `customerCode`。
9. **推送不是自助**：要 email 聯調；推送 IP 需開白名單；事件無序、以 `trackUniqueId` 去重；驗 `sign` 必須用原始 body。
10. **`lang` 沒有 `zh-TW`**（只有 `SOP_COLLECT_SHIP_STANDARD` body 的 `lang` 欄位用 `zh-TW`）；該接口港澳台國碼用 3 位數字（852…），而 `IPE_QUERY_PRODUCT_FREIGHT` 用 `HK/MO/TW`——兩套寫法並存。
11. **路由查詢**：`GTS_QUERY_TRACK` 要手機後四碼、最多 10 單，業務碼中英文版頁面不一致；舊版 `IUOP_QUERY_TRACK` 不需手機但一次一單（見 `references/tracking.md` 對照表）。
12. **文件互相不一致處**：預創建頁收件證件必填寫「ID,TW」、建單頁寫「ID|TW|KR」；`productNameElement` 分隔符一頁寫「,」一頁寫「;」；附錄 1 msgType 表是 2023 舊表不完整——以各 API 頁為準。

---

## 未取得 / 待確認

- TW→TW 正式可否下單：見上方邊界節（確認前不要上線）。
- 其他待確認（台灣個人收件人證件碼、統編必填觸發流向、離島區劃、幣種寫法等）見 `references/taiwan-rules.md` §8；需登入未取得的頁面與隱藏頁清單見 `references/sources.md` §4。
- 寫入型接口（建單 / 取消 / 上傳）**未在沙箱實測**，只驗證了唯讀接口與加解密。

---

**本 SKILL 為順豐國際開放平台唯一 reference 來源——portal 是 SPA，不要 WebFetch openapi-portal.sf.global；[待確認] 項目向順豐客戶經理確認。** 官方頁面若有更新，依 `references/sources.md` 的 URL 與日期比對後再增修。
