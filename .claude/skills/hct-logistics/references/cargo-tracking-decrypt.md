# 新竹物流 貨況查詢（searchGoods）DES CBC 加解密串接

> **來源**：
> - 《新竹物流 貨物配送狀況串接說明文件》**Ver3.1（2026/06/11）**（通用模板：演算法 + 完整 worked example + status_code 對應表）
> - 《新竹物流 貨物配送狀況串接方式_zenbu》（2026/06/30，**zenbu 專屬實際 production 參數**：金鑰偏移 / IV / v 值）
>
> **此檔解決 FU-4（HIGH risk）**：舊版（API 服務說明 V2.0、V15）皆寫「加解密金鑰**申請後提供**，未公開」。
> 申請後拿到的這兩份文件補齊了 `mapHctStatusToState` 與 `queryShipment` degrade 升級為真實貨況映射所需的全部規格。
> 取代 `cargo-status-and-addr-compare.md` Part B.1 的占位說明（B.1 已改為指回此檔）。

---

## ⚠️ 0. 安全須知（production 憑證）

下方「zenbu 實際參數」中的 IV `<HCT_TRACK_IV>`、v `<HCT_TRACK_V>` 為**佔位值**——
**實際值請由環境變數 HCT_TRACK_V / HCT_TRACK_IV 提供，勿寫入 repo**。兩者屬 zenbu 正式環境的查貨憑證，等同 `.claude/rules/hct-logistics.rule.md` 的 `shipping.hct_track_query_key` 材料。

- **必須走 DB settings**（`shipping.hct_track_query_*`），對齊 hct-logistics rule Rule 4「endpoint / 憑證走 SettingsService（禁直讀 process.env）」與「禁硬編」。**禁止**把這些值硬編進程式碼、測試或任何會被 commit 的文件。
- 本 skill 檔**會被 commit**，因此只放佔位值；原始申請文件（PDF，設有開啟密碼）與實際值請向負責人索取，勿在任何文件中揭露 PDF 密碼或其線索。
- 對齊 memory `public-settings-leak-rotate-creds`：`shipping.` prefix 已在 `PUBLIC_BLOCKED_SETTING_PREFIXES`，天然不洩漏公開 settings。

---

## 目錄（TOC）

- [1. 加 / 解密規格（DES CBC）](#1-加--解密規格des-cbc)
- [2. ⚠️ Node.js / OpenSSL 3 實作陷阱（legacy provider）](#2-️-nodejs--openssl-3-實作陷阱legacy-provider)
- [3. 實作範例](#3-實作範例)
- [4. 串接模式（端點 + XML 格式）](#4-串接模式端點--xml-格式)
- [5. 已驗證 test vector（可直接寫成單元測試）](#5-已驗證-test-vector可直接寫成單元測試)
- [6. 貨況對應表（status_code，Ver3.1 新增）](#6-貨況對應表status_codever31-新增)
- [7. status_code → ShippingState 建議映射（FU-4）](#7-status_code--shippingstate-建議映射fu-4)
- [8. 注意事項（查貨服務）](#8-注意事項查貨服務)
- [9. 與既有實作對接](#9-與既有實作對接)

---

## 1. 加 / 解密規格（DES CBC）

`no` 參數（查詢字串）以 **DES CBC** 加密、輸出 **Base64**。

| 項目 | 規格 |
|---|---|
| 演算法 | **DES**（單組，非 3DES）、模式 **CBC** |
| Padding | **PKCS7**（= PKCS5，8-byte block；.NET `DESCryptoServiceProvider` 預設、Node `des-cbc` 預設）|
| 金鑰（KEY）| **查詢當日日期 ± n 天**，格式 `yyyyMMdd` 的 **UTF-8 bytes**（恰 8 bytes = DES key 長度）|
| 加密向量（IV）| **8 位固定值**字串的 **UTF-8 bytes**（恰 8 bytes = DES block 長度）|
| 輸入 | 待加密內容（單筆＝訂單編號/貨號；多筆＝XML 字串）的 **UTF-8 bytes** |
| 輸出 | 密文 bytes → **Base64** 字串，即為 `no` 值 |
| `v` 參數 | **32 位固定值**（帳號/版本識別，**不參與加解密**，原樣放 query string）|

> **金鑰即日期**：KEY 隨「查詢當日」每天變動（today + offset 的 `yyyyMMdd`），無需另存固定密鑰——
> 偏移天數 `n`、IV、v 三者才是帳號專屬的申請後憑證。

### 1.1 zenbu 實際 production 參數（2026/06/30 文件）

| 參數 | zenbu 值 | 說明 |
|---|---|---|
| 金鑰日期偏移 `n` | **`-33`** | KEY = `DateTime.Today.AddDays(-33)` 的 `yyyyMMdd`（**當日減 33 天**）|
| 加密向量 IV | **`<HCT_TRACK_IV>`** | 8 位固定（實際值請由環境變數 HCT_TRACK_IV 提供，勿寫入 repo）|
| `v` 值 | **`<HCT_TRACK_V>`** | 32 位固定（實際值請由環境變數 HCT_TRACK_V 提供，勿寫入 repo；原始申請文件 PDF 設有開啟密碼，密碼不在 repo 揭露，請向負責人索取）|

```
單筆端點範例（zenbu）：
https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no={DES-CBC(貨號)}&v=<HCT_TRACK_V>
```

### 1.2 通用模板參數（Ver3.1 文件，非 zenbu）

文件以佔位示意，正式值「依郵件檔案內容文件為主，請勿使用範例的參數」：
- 金鑰 = 查詢當日 ± **n 天**（n 為申請後提供）
- IV = 8 位固定（例：`ABCDEFGH`）
- v = 32 位固定（例：`ABCDEFGHIJK1234567891ABCDEFGHIJK`）

---

## 2. ⚠️ Node.js / OpenSSL 3 實作陷阱（legacy provider）

**DES（單組）是 legacy cipher，OpenSSL 3 預設停用**。Node 17+（含本專案 Node 22 / OpenSSL 3）直接呼叫
`crypto.createCipheriv('des-cbc', ...)` 會拋：

```
Error: error:0308010C:digital envelope routines::unsupported
  code: 'ERR_OSSL_EVP_UNSUPPORTED'
```

**解法（擇一）**：

| 方案 | 做法 | 取捨 |
|---|---|---|
| **✅ 0. 3DES-EDE 三重相同金鑰（本專案採用）** | 用 `des-ede3-cbc` 並把 8-byte 金鑰**重複三次**成 24-byte（K,K,K）：`3DES-EDE(K,K,K) = E(D(E(x,K),K),K) = E(x,K) = 單組 DES`。`des-ede3-cbc` 在 OpenSSL 3 **非 legacy**（仍啟用）| **零依賴、零全域旗標、不弱化平台**；輸出與單組 DES **位元相同**（已對官方 vector 驗證）；用 Node 內建 `crypto`。**最佳解** |
| 1. 純 JS DES | 用純 JS 實作（如 `des.js`）做 DES-CBC，不碰 OpenSSL | 需新依賴（lockfile / install 風險）；對 payment-adjacent service 擴大供應鏈 |
| 2. legacy provider 旗標 | `node --openssl-legacy-provider` / `NODE_OPTIONS=...` | **進程級全域**開啟所有 legacy cipher（RC4/DES…），弱化整個 api-gateway；**不採用** |
| 3. 程式內載入 legacy provider | OpenSSL 3 需 provider 載入，Node 無穩定 API | 脆弱；不採用 |

> **建議採方案 0**（已在一份 NestJS 參考實作中驗證過）：
> `des-ede3-cbc` + 24-byte 三重金鑰，於 Node 22 / OpenSSL 3.0.16 **無須 legacy provider**，
> 已對官方 test vector（§5）位元級驗證（gitignored spec `hct-track-crypto.spec.ts`，10/10 綠）。
> `queryShipment` 在 `shipping.hct_track_query_key` 未設 / 格式錯誤 / 任何例外時仍 degrade `pending`（P-2）。
> §3.2 範例已更新為此實作（`des-ede3-cbc`），非單組 `des-cbc`。

---

## 3. 實作範例

### 3.1 官方 SAMPLE CODE（C#）

**通用模板（Ver3.1，佔位）：**
```csharp
//KEY 是以日期做為 Random key
byte[] rgbKey = Encoding.UTF8.GetBytes(DateTime.Today.AddDays(文件中提供的天數).ToString("yyyyMMdd"));
//向量值，此值為固定，請換成申請文件中所提供的 IV 值
byte[] rgbIV = Encoding.UTF8.GetBytes(文件中提供的IV值);
//查詢貨號
byte[] inputByteArray = Encoding.UTF8.GetBytes(需查詢之貨號/訂單編號/XML內容);
DESCryptoServiceProvider dCSP = new DESCryptoServiceProvider();
MemoryStream mStream = new MemoryStream();
CryptoStream cStream = new CryptoStream(mStream, dCSP.CreateEncryptor(rgbKey, rgbIV),
    CryptoStreamMode.Write);
cStream.Write(inputByteArray, 0, inputByteArray.Length);
cStream.FlushFinalBlock();
//加密後的值，轉成 base64 string
string str_return = Convert.ToBase64String(mStream.ToArray());
//String str_return 即為 no = str_return
```

**zenbu 實際值（2026/06/30 文件）：**
```csharp
//KEY 是以日期做為 Random key（zenbu = 當日 -33 天）
byte[] rgbKey = Encoding.UTF8.GetBytes(DateTime.Today.AddDays(-33).ToString("yyyyMMdd"));
//向量值，固定 = <HCT_TRACK_IV>
byte[] rgbIV = Encoding.UTF8.GetBytes("<HCT_TRACK_IV>");
//查詢貨號
byte[] inputByteArray = Encoding.UTF8.GetBytes("1234567891");
DESCryptoServiceProvider dCSP = new DESCryptoServiceProvider();
MemoryStream mStream = new MemoryStream();
CryptoStream cStream = new CryptoStream(mStream, dCSP.CreateEncryptor(rgbKey, rgbIV),
    CryptoStreamMode.Write);
cStream.Write(inputByteArray, 0, inputByteArray.Length);
cStream.FlushFinalBlock();
string str_return = Convert.ToBase64String(mStream.ToArray());  // no = str_return
```

### 3.2 Node.js / TypeScript（參考實作，已對 PDF test vector 位元級驗證）

> ✅ 用 `des-ede3-cbc` + **24-byte 三重相同金鑰**（方案 0，§2）→ 等價單組 DES，**無須 OpenSSL legacy
> provider**。

```typescript
import { createCipheriv, createDecipheriv } from 'node:crypto';

const ALGO = 'des-ede3-cbc'; // 3DES-EDE(K,K,K) = 單組 DES；OpenSSL 3 非 legacy

/** 8-byte DES 金鑰 → 24-byte（K,K,K），使 3DES-EDE 等價單組 DES。 */
function expandKey(keyYmd: string): Buffer {
  const k8 = Buffer.from(keyYmd, 'utf8'); // 8 bytes
  return Buffer.concat([k8, k8, k8]);     // 24 bytes
}

/** 查貨金鑰日期：當日 + offset 天 → yyyyMMdd（UTF-8 8 bytes）。zenbu offset = -33。 */
export function hctTrackKeyDate(offsetDays: number, now = new Date()): string {
  const d = new Date(now.getTime());
  d.setDate(d.getDate() + offsetDays);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}${m}${day}`;
}

/** DES-CBC + PKCS7 + Base64（經 3DES-EDE 三重金鑰）。key/iv 皆 8-byte UTF-8。 */
export function hctTrackEncrypt(plain: string, keyYmd: string, iv: string): string {
  const c = createCipheriv(ALGO, expandKey(keyYmd), Buffer.from(iv, 'utf8'));
  return Buffer.concat([c.update(Buffer.from(plain, 'utf8')), c.final()]).toString('base64');
}

/** 解密回 UTF-8。 */
export function hctTrackDecrypt(b64: string, keyYmd: string, iv: string): string {
  const d = createDecipheriv(ALGO, expandKey(keyYmd), Buffer.from(iv, 'utf8'));
  return Buffer.concat([d.update(Buffer.from(b64, 'base64')), d.final()]).toString('utf8');
}

// 用法（zenbu）：
// const keyYmd = hctTrackKeyDate(-33);            // 查詢當日 -33 天
// const no = hctTrackEncrypt('1234567891', keyYmd, '<HCT_TRACK_IV>');
// const url = `https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no=${encodeURIComponent(no)}&v=<HCT_TRACK_V>`;
```

---

## 4. 串接模式（端點 + XML 格式）

### 4.1 網頁串接模式（單筆，回傳完整配送歷程網頁）

`GET`，把加密貨號 / 訂單編號放 `no`：

```
https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no={加密字串}&v={32位固定值}
```

可直接連至新竹物流網頁顯示貨況，即時呈現給使用者（網址可放貴司網頁供客戶點擊）。

### 4.2 多筆貨號查詢（XML，POST）

`POST`，回傳「加密後字串」，解密後得 XML。**單次上限 100 筆**。

```
https://hctapiweb.hct.com.tw/phone/searchGoods_Main_Xml.ashx?no={加密後XML}&v={32位固定值}
```

**加密前傳出格式（XML）：**
```xml
<?xml version="1.0" encoding="utf-8"?>
<qrylist>
  <order orderid="1234567890"></order>
  <order orderid="1234567891"></order>
  <order orderid="1234567892"></order>
</qrylist>
```

**解密後返回格式（XML）— a. 有貨況時**（Ver3.1 新增 `category` 狀態分類 + `status_code` 貨況代碼屬性）：
```xml
<?xml version="1.0" encoding="utf-8"?>
<rlist>
  <orders ordersid="1234567890">
    <order orderid="1234567890" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況" status_code="貨況代碼" />
    <order orderid="1234567890" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況2" status_code="貨況代碼" />
  </orders>
  <orders ordersid="1234567891">
    <order orderid="1234567891" wrktime="YYYY/MM/DD HH24:MM" category="狀態分類" status="貨物狀況" status_code="貨況代碼" />
  </orders>
</rlist>
```

**b. 查詢該筆貨號無貨況時回空值：**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<rlist />
```

> **vs 舊版（`cargo-status-and-addr-compare.md` B.3）**：舊 V2.0 的 `<order>` 只有 `wrktime` / `category` / `status`；
> Ver3.1 **新增 `status_code` 屬性**（對應 §6 貨況代碼），是穩定映射 `ShippingState` 的關鍵（不必再用中文 `status` 字串 fuzzy match）。

---

## 5. 已驗證 test vector（可直接寫成單元測試）

以下 vector 取自 Ver3.1 文件第六、七頁，**已用 Node `des-cbc`（legacy provider）對拍，密文逐字元相符**：

```
共用參數：
  貨號 / 查詢日：1234567890 / 2025-03-13
  金鑰偏移 n：+235 天  → KEY = 20251103（2025-03-13 + 235 天，已驗算）
  IV：TCAVZYKU
  v ：098F6BCD4621D373CADE4E832627B4F6   （= MD5("test")，文件範例值）
```

**(一) 單筆：**
```
明文：1234567890
密文：W/19ch1m+WBi9kMnQo7Hog==
URL ：https://hctapiweb.hct.com.tw/phone/searchGoods_Main.aspx?no=W/19ch1m+WBi9kMnQo7Hog==&v=098F6BCD4621D373CADE4E832627B4F6
```

**(二) 多筆 XML：**
```
明文：<?xml version="1.0" encoding="utf-8"?><qrylist><order orderid="1234567890"></order></qrylist>
密文：U0Yhj0joZ8rHf4C6RXHqhYUscbDg9KZKq/qeMC9+94l76fRrA8XLthCdYB+IeoR83ZBno3S9e1/GZFJnahkG/DSvH39qdvqURQcsxV31mun8EMvjE7f6z2uxTl5n974K
```
請求返回（密文，解密後）：
```xml
<?xml version="1.0" encoding="UTF-8"?>
<rlist>
  <orders ordersid="1234567890">
    <order orderid="1234567890" wrktime="2025/03/11 12:21" status="貨件已由北投營業所送達。貨物件數共1件。"/>
    <order orderid="1234567890" wrktime="2025/03/11 06:25" status="貨件由北投營業所人員配送中。"/>
    <order orderid="1234567890" wrktime="2025/03/11 05:03" status="貨件已抵達北投營業所，分貨中。"/>
    <order orderid="1234567890" wrktime="2025/03/10 16:47" status="貨件已抵達台中營業所，貨件整理中。貨物件數共1件。"/>
  </orders>
</rlist>
```

> int-spec 對拍建議：固定 `now = 2025-03-13`、`offset = 235`、`iv = TCAVZYKU` → 斷言 `hctEncrypt('1234567890', '20251103', 'TCAVZYKU') === 'W/19ch1m+WBi9kMnQo7Hog=='`。
> 因 zenbu 正式 KEY 隨「當日」變動，單元測試請鎖死 `now` 用上述 vector，**勿**用 zenbu offset `-33` + 浮動今日（不可重現）。

---

## 6. 貨況對應表（status_code，Ver3.1 新增）

新竹物流中文貨況對應表（`xx` = 站所名稱、`N` = 件數）。**4 欄完整版**（含 Ver3.1 新增的 `category` 狀態分類 + `status_code` 貨況代碼）。
代碼以前綴分群：**A**=正向集配主流程、**R**=取件 / 逆向 / 異常、**H**=配達結果、**O**=委外、**C**=已結案。

| status_code | 說明 | 貨況說明（status）| 狀態分類（category）|
|---|---|---|---|
| A01 | 集貨 | 已由 xx 取件完成。| 集貨中 |
| A02 | 卸集貨 | 貨件已抵達 xx，貨件整理中。貨物件數共 N 件。| 集貨中 |
| A03 | 發送 | 貨件已由 xx 發出，前往配送站途中。| 集貨中 |
| A04 | 到著 | 貨件已抵達 xx，分貨中。| 配送中 |
| A05 | 配送 | 貨件由 xx 人員配送中。| 配送中 |
| A06 | 持回 | 貨件由 xx 保管中。| 配送中 |
| R01 | 派遣 | 取貨通知處理中。| 集貨中 |
| R02 | 支援 | xx 取件中。| 集貨中 |
| R03 | 客戶不在 | 寄貨人不在無法收件。| 配送中 |
| R04 | 已取 | xx 已至客戶端取回貨件。| 集貨中 |
| R05 | 誤派 | xx 取件中。| 集貨中 |
| R06 | 址誤 | 取件異常地址錯誤，xx 處理中。| 異常 |
| R07 | 同業收取 | 取件異常，貨件由其他同業取走。| 異常 |
| R08 | 無貨可退 | 取件異常，無貨可收。| 異常 |
| R09 | 其他 | 取件異常，專人處理中。| 配送中 |
| R10 | 預計 12 點前到客戶端 | xx 取件中。| 配送中 |
| R11 | 預計 15 點前到客戶端 | xx 取件中。| 配送中 |
| R12 | 預計 17 點前到客戶端 | xx 取件中。| 配送中 |
| R13 | 預計 17 點後到客戶端 | xx 取件中。| 配送中 |
| R14 | 查無此人 | 該地址查無此人，確認中。| 異常 |
| R15 | 電聯異常 | 已電話聯繫，無人接聽。| 異常 |
| R16 | 商品保留不退 | 收件人確定保留商品，取消退貨。| 異常 |
| R17 | 約定收件 | 另約時間再次取件。| 集貨中 |
| R18 | 公司行號休息 | 收件地址為公司行號，本日休假。| 配送中 |
| R19 | 派遣取消 | 廠商通知取消取件。| 異常 |
| R20 | 派遣確認 | xx 已派遣人員取件中。| 集貨中 |
| H01 | 正常配交 | 貨件已由 xx 送達。貨物件數共 N 件。| 配達 |
| H02 | 缺件配達 | xx 缺件配交。| 異常 |
| H03 | 破損配達 | xx 破損配交。| 異常 |
| H04 | 客戶不在 | 送達客戶不在。| 配送中 |
| H05 | 地址錯誤 | 收貨人地址異常。| 異常 |
| H06 | 查無此人 | 該地址查無此人。| 異常 |
| H07 | 短少配達 | xx 短少配交。| 異常 |
| H08 | 簽單留置 | 貨件已由 xx 送達。| 配達 |
| H09 | 約定再配 | 與客戶另約時間配送。| 配送中 |
| H10 | 店取 | 貨件已由 xx 送至 oo，請撥空領取。| 配送中 |
| H11 | 店取收回 | 貨件由 xx 店取收回。| 集貨中 |
| H12 | 店取收回異常 | 貨件由 xx 保管中。| 異常 |
| H13 | 拒收 | 收貨人拒絕收件。| 異常 |
| H14 | 公司行號休假 | 收件地址為公司行號，本日休假。| 配送中 |
| H15 | 電聯異常 | 已電話聯繫，無人接聽。| 配送中 |
| H16 | 到站自領 | 與客戶另約時間到站自領，xx 保管中。| 配送中 |
| H17 | 拒收退回 | 貨件已退回，退貨號碼為 xx。| 異常 |
| O01 | 委外配送中 | 貨件由 xx 委外金門協力商人員配送中。| 配送中 |
| O02 | 委外配送中 | 貨件由 xx 委外澎湖協力商人員配送中。| 配送中 |
| O03 | 委外配送中 | 貨件由 xx 委外金門協力商人員配送中。| 配送中 |
| O04 | 委外配送中 | 貨件已由 xx 委外協力商人員配送中。| 配送中 |
| O05 | 委外配送中 | 貨件由 xx 轉運中。| 配送中 |
| C01 | 正常配交 | 正常配交 | 已結案 |
| C04 | 短少配交 | 短少配交 | 已結案 |
| C05 | 破損配交 | 破損配交 | 已結案 |
| C06 | 查無此人 | 查無此人 | 已結案 |
| C10 | 轉出 | 轉出 | 已結案 |
| C11 | 不計津貼 | 不計津貼 | 已結案 |
| C12 | 未到補銷 | 未到補銷 | 已結案 |
| C13 | 喪失 | 喪失 | 已結案 |
| C14 | 轉郵寄 | 轉郵寄 | 已結案 |
| C15 | 轉加達 | 轉加達 | 已結案 |
| C19 | 留置貨件 | 留置貨件 | 已結案 |
| C22 | 超商店取 | 超商店取 | 已結案 |
| C24 | 店取收回異常 | 店取收回異常 | 已結案 |
| C25 | 店配異常 | 店配異常 | 已結案 |

> 共 **62 碼**（A×6、R×20、H×17、O×5、C×14）。`C01 正常配交` 文件中以**紅字**標示（最終結案成功態）。

---

## 7. status_code → ShippingState 映射（FU-4，已實作）

對齊 `hct-logistics.rule.md` Rule 6：`mapHctStatusToState` **只能輸出 9 個** `ShippingState`，
**禁止**輸出 `ready_for_pickup` / `expired_unclaimed`（`mapShippingStateToOrderStatus` 不接受）。
未涵蓋 / 未知代碼 → `pending` + `logger.warn`（對齊 ecpay `mapRtnCodeToState` 慣例）。

**已實作**於 `hct-logistics.service.ts` 的 `HCT_STATUS_CODE_STATE`（以 `status_code` 為主鍵；缺漏時 fallback 中文 `status` 關鍵字比對，見 `mapHctStatusToState`）：

| ShippingState | status_code | 理由 |
|---|---|---|
| `accepted` | A01, R04 | 已取件完成 / 已取回 |
| `in_transit` | A02, A03, A05, A06, O01–O05, H09, H10, R09–R13 | 集貨後運送中 / 配送中 / 委外運送 / 約定再配 / 店取待領 |
| `arrived` | A04 | 到著（抵達配送站分貨中）|
| `delivered` | H01, H08, C01 | 正常配交 / 簽單留置 / 結案正常配交 |
| `returned` | H17, C10, C14, C15 | 拒收退回 / 轉出 / 轉郵寄 / 轉加達（離開新竹體系）|
| `failed` | R06, R07, R08, R14, R15, R16, H02, H03, H05, H06, H07, H12, H13, C04, C05, C06, C13, C25 | 地址 / 查無此人 / 拒收 / 破損缺件 / 電聯異常 / 喪失等異常 |
| `cancelled` | R19 | 派遣取消（廠商通知取消取件；實作歸 cancelled，**不**歸 failed）|
| `pending` | R01, R02, R03, R05, R17, R18, R20, H04, H14, H15, H16, C11, C12, C19, C22, C24, 及未知碼 | 取件流程中 / 暫無明確進展 / 結案雜項 → 保守 pending + warn |
| `picked_up` | （宅配無門市取件語意，HCT 純宅配 → 不使用）| — |

> **取捨**：上表為**起手建議**，非文件硬性映射（文件只給 `category`：集貨中 / 配送中 / 配達 / 異常 / 已結案）。
> 可先用 `category` 粗分（配達→delivered、異常→failed、集貨中→accepted/pending、配送中→in_transit）再用 `status_code` 細修。
> `category='已結案'` 需看代碼：`C01`→delivered、`C04/05/06/13/25`→failed、`C10/14/15`→returned，其餘→pending。
> 上線前以真實回傳資料校準（FU-4 follow-up）。

---

## 8. 注意事項（查貨服務）

1. 一般貨況正常僅供查詢 **3 個月內**資料（**注意**：舊 V2.0 寫 30 天；Ver3.1 已放寬為 3 個月）。歷史查詢需求請連繫原申請營業所。
2. **訂單編號**與**十碼貨號**目前**只能選擇其一**進行查詢（申請時即選擇，更改需連繫營業所）。
3. 若使用訂單編號查詢，需綁定特約客戶帳號及客戶代號。
4. 對所查詢貨況資訊有疑慮，請連繫原申請營業所。
5. XML 多筆查詢**單次上限 100 筆**，且需等待結果回傳後才可再次提交。
6. 系統每日凌晨 **03:40–04:30** 進行查貨系統維護，不會有貨況更新。
7. 異常聯絡：**02-2837-1122 #5122 徐閺彥**（Ver3.1；舊 V2.0 為 #5123 許先生）。

---

## 9. 與既有實作對接

| 既有資產 | 對接方式 |
|---|---|
| `hct-logistics.service.ts` `queryShipment`（**已實作 FU-4 / 2026-06-30**）| DES CBC 加密 `<qrylist>` → POST `searchGoods_Main_Xml.ashx` → 解密 → 取最新 `<order>` → `mapHctStatusToState`。未設定 / 格式錯誤 / 任何例外 → degrade pending（rule Rule 6，P-2）。crypto 在 `hct-track-crypto.ts` |
| `shipping.hct_track_query_key`（settings，rule Rule 4）| **採單一 JSON**（保留既有單一 reserved key，不擴 3 key）：`{"offsetDays":-33,"iv":"<HCT_TRACK_IV>","v":"<HCT_TRACK_V>"}`。`parseTrackQueryConfig` 解析（缺 / 非 JSON / offsetDays 非數字 / iv≠8 字元 / v 空 → undefined → degrade）。受 `ShippingCredentials` index signature 約束故存 string |
| `mapHctStatusToState`（rule Rule 6，輸出 9 state）| **已實作**：`HCT_STATUS_CODE_STATE`（§7，status_code 主鍵）+ 中文 `status` 關鍵字 fallback；未知 → `pending` + `logger.warn`。簽名改 `(statusCode?, statusText?)` |
| OpenSSL 3 legacy（§2）| **採方案 0**：`des-ede3-cbc` + 三重相同金鑰 = 單組 DES（OpenSSL 3 非 legacy，無全域旗標、無新依賴）。實作 `hct-track-crypto.ts`，已對 vector 驗證 |
| `cargo-status-and-addr-compare.md` Part B | 該檔為**舊版 V2.0** searchGoods 補充（無 status_code、無加解密）；**本檔取代其 B.1 加解密占位**，B.1 已改指回此檔。status_code 完整表以**本檔 §6** 為準 |

> **FU-4 狀態更新**：原「searchGoods 加解密金鑰演算法（高風險，未公開）」**已解決**——演算法 = DES-CBC（本檔 §1）、zenbu 參數已知（§1.1）、test vector 已驗證（§5）、status_code 對應表已補（§6）。剩餘僅「以真實回傳資料校準 §7 映射」的上線前 follow-up。
