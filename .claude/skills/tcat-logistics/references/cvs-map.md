# 門市 / 站所選擇地圖串接（7-11 快速到店 + 速達自取站）

兩套**完全獨立**的地圖系統，用途不同、機制不同、參數不同。

| 系統 | 用途 | 機制 | 產出欄位 | 對應 API |
|---|---|---|---|---|
| **7-11 倉到店電子地圖** | 消費者選 7-11 門市取貨 | **POST form 導轉** → 選完後 **POST 回 `ReturnUrl`** | `storeid` | `PrintOBTByB2S.ReceiveStoreId` |
| **速達營業站所自取地圖** | 消費者選黑貓營業所自取 | **GET querystring 導轉** → 選完後速達**ajax 呼叫你註冊的 WEB API** | `OFFICE_NO` | `PrintOBT.ReceiptStationNo`（配 `ReceiptLocation='02'`）|

---

# Part A — 7-11 快速到店（倉到店）電子地圖

來源：《倉到店電子地圖串接文件 V1.4》（2022-11-09）

## A1. 端點

| 版本 | 環境 | URL |
|---|---|---|
| PC 版 | **正式** | `https://appservice.ezcat.com.tw/Map.aspx` |
| PC 版 | **測試** | `http://egs.suda.com.tw:8080/Map.aspx` |
| 手機版 | **正式** | `https://appservice.ezcat.com.tw/MobileMap.aspx` |
| 手機版 | **測試** | `http://egs.suda.com.tw:8080/MobileMap.aspx` |

**方式**：使用 **POST Method** 傳遞參數。選擇完門市後，會以 **POST Method** 回傳資料到 `ReturnUrl` 之網頁。

> ⚠️ **測試環境是 `http://`（明文）且為非標準埠 8080**。正式環境才是 https。開發時注意瀏覽器的 mixed-content 阻擋——若你的測試站是 https，POST 到 http 的 `Map.aspx` 會被瀏覽器擋下。

## A2. eShop → eMap 傳遞參數（PC 版與手機版**完全相同**）

| 項次 | 欄位名稱 | 描述 | 長度 | 必要值 | 備註 |
|---|---|---|---|---|---|
| 1 | `CustomerID` | 契客代號 | 12 | **Y** | |
| 2 | `ReturnUrl` | 結果回傳之網址 | 400 | **Y** | 選完門市後，eMap 會 POST 到此網址 |
| 3 | `TempVar` | 自行設定之 Key 值，作為回傳資訊識別用 | 20 | N | 原值回傳，用來對回自己的訂單 / session |
| 4 | `StoreCategory` | 地圖類型 | 2 | N | **常溫帶入 `13`，冷凍帶入 `14`，冷藏帶入 `15`**（預設為 `13`）|

### A2.1 `StoreCategory` 代碼表

| 代碼 | 溫層 | 對應 `PrintOBTByB2S.Thermosphere` |
|---|---|---|
| `13` | 常溫（**預設**）| `0001` |
| `14` | **冷凍** | `0003` |
| `15` | **冷藏** | `0002` |

> ⚠️ **`14` 是冷凍、`15` 是冷藏**——順序與印單 API 的 `Thermosphere`（`0002` 冷藏、`0003` 冷凍）**相反**。這是最容易寫錯的一組對照，寫死映射表不要靠直覺。
> 溫層會影響可選門市：不是每間 7-11 都能收冷凍 / 冷藏，帶錯 `StoreCategory` 會讓消費者選到無法配送的門市。

## A3. eMap → eShop 回傳參數（POST 到 `ReturnUrl`，PC 與手機相同）

| 項次 | 欄位名稱 | 描述 | 長度 | 必要值 | 備註 |
|---|---|---|---|---|---|
| 1 | `TempVar` | 原本傳入之 Key 值，作為資訊識別用 | 20 | N | |
| 2 | `storeid` | **門市店號** | 20 | **Y** | **有可能傳入與傳出之店號會不一樣**，因為可能找不到該店號，消費者需重新選擇 |
| 3 | `storename` | 門市名稱 | 50 | **Y** | |
| 4 | `storeaddress` | 門市地址 | 200 | **Y** | |
| 5 | `outside` | 判斷是否為離島 | 1 | **Y** | `0` 為本島，`1` 為外島 |
| 6 | `ship` | 配送週期 | 7 | **Y** | `0`：不配送　`1`：配送。**順序為（日 一 二 三 四 五 六）**，例：`1111111` |

> **回傳欄位名為全小寫**（`storeid` / `storename` / `storeaddress`），但送出的參數是大駝峰（`CustomerID` / `ReturnUrl` / `TempVar` / `StoreCategory`）。**大小寫不一致，不要統一。**

### A3.1 `ship` 配送週期解析

7 個字元，**索引 0 = 星期日**，依序到索引 6 = 星期六：

```typescript
const WEEK = ['日', '一', '二', '三', '四', '五', '六'] as const;
function parseShip(ship: string): string[] {
  return [...ship].map((c, i) => (c === '1' ? WEEK[i] : null)).filter(Boolean) as string[];
}
parseShip('1111111');  // ['日','一','二','三','四','五','六'] → 全週配送
parseShip('0111110');  // ['一','二','三','四','五']         → 僅平日配送
```

> 用途：在結帳頁提示消費者「此門市週X不配送」，或阻擋選到不配送日。

## A4. 官方範例（`MapSample.zip` → `MapSample.html` 原文）

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title></title>
</head>
<body>
    <form id="form1" method="post" action="http://egs.suda.com.tw:8080/Map.aspx">
            <input name="CustomerID" value="9017292601" />
            <input name="ReturnUrl" value="https://tcloud-daxi.crownonly.com/XXX接收門店網頁" />
            <input name="TempVar" value="這是測試資料" />
            <input name="StoreCategory" value="13" />
            <button type="submit">開啟地圖</button>
    </form>
</body>
</html>
```

> 官方範例極簡（就是一個 POST form）。**`MapSample.zip` 內僅此一個檔案，無其他程式碼。**
> 範例中的 `CustomerID` `9017292601` 為 10 碼（契客代號允許 10 或 12 碼）。

## A5. 整合注意事項

- **`storeid` 可能與你傳入的不同**（官方明載）。若你做「上次選過的門市」預帶功能，回來後**必須以回傳的 `storeid` 為準**覆寫。
- **`ReturnUrl` 收到的是 POST**，不是 GET。後端路由要註冊 POST handler，且通常要**豁免 CSRF token**（請求來自速達網域，不會帶你的 token）——但要改用其他驗證手段：**用 `TempVar` 綁定一個一次性、有時效的 server-side session key**，不要放可預測的訂單編號。
- **`TempVar` 只有 20 字元**，放 UUID（36 字）會被截斷。用短亂數（如 16 碼 base62）或短碼對映表。
- 離島（`outside='1'`）門市的運費 / 可配送性通常不同，取得後應回頭檢查訂單金額與可否配送。
- 取得的 `storeid` 直接填入 `PrintOBTByB2S` 的 **`ReceiveStoreId`**（`String(50)`）。

---

# Part B — 速達營業站所自取地圖

來源：《黑貓自取站地圖查詢 - 操作手冊 C# 版》v1.0.1（2020-12-17）
系統環境：.NET Framework 4.0 / C# / VS2013（速達端）

## B1. 端點

| 環境 | 用途 | URL |
|---|---|---|
| **正式** | 單純檢視（不可回傳）| `http://103.234.81.15/TCAT/CAT0001/CAT0001MM1` |
| **正式** | 可回傳選擇結果 | `http://103.234.81.15/TCAT/CAT0001/CAT0001MM1?UID={UID}&CUST_NO={客代}&TRADE_ID={交易代號}&PKEY={網站鑰匙}` |
| **測試** | 單純檢視 | `http://61.57.232.216/TCAT/CAT0001/CAT0001MM1` |
| **測試** | 可回傳選擇結果 | `http://61.57.232.216/TCAT/CAT0001/CAT0001MM1?UID=...&CUST_NO=...&TRADE_ID=...&PKEY=...` |

> ⚠️ **正式與測試皆為 `http://` 明文 IP 位址**（無網域、無 TLS）。這條路徑會在 query string 傳遞客戶代號與識別參數。若整合到 https 站台，會有 mixed content 與資安疑慮——**評估是否改以後端代理或另行與速達確認是否有 https 版本**。

**兩種模式的差異**：不帶參數 → 只能檢視自取站資訊與地圖，**不會出現「選取自取站」按鈕**；帶參數 → 左方自取站資訊處多顯示「選取自取站」按鈕。

## B2. 網址輸入參數

| 輸入參數 | 型別 | 功能說明 | 補充 |
|---|---|---|---|
| `UID` | string | **廠商代號** | **預設對應廠商統編**（系統會根據此統編去資料庫查詢要回傳的路徑）。**必填** |
| `TRADE_ID` | string | **交易代號** | 廠商原始功能要開啟該頁面時所提供的交易代號（來源端的 KEY）。回傳時也會把此交易代號回傳，讓來源端知道是哪個功能使用。**長度最多 30 碼英數字**，如：集貨代碼 or 交貨代碼…`202011171330`。**必填** |
| `CUST_NO` | string | **客戶代碼** | 資料定義長度 **10 或 12**，**純數字**。**必填** |
| `PKEY` | string | **網站鑰匙** | **預設 PIC 統編**，避免被攻擊。範例值 `16151427`。**必填** |

## B3. 網址輸出參數（速達 → 廠商 WEB API）

| 輸出參數 | 型別 | 功能說明 | 補充 |
|---|---|---|---|
| `CUST_NO` | string | 客戶代碼 | 資料定義長度 10 或 12，純數字 |
| `TRADE_ID` | string | 交易代號 | 廠商開啟該頁面時所提供的交易代號，回傳時把此交易代號回傳。長度最多 30 碼英數字，如 `202011171330` |
| `OFFICE_NO` | string | **自取站代碼** | 黑貓自取站代碼 → 填入 `PrintOBT.ReceiptStationNo` |
| `OFFICE_NAME` | string | 自取站名稱 | |
| `OFFICE_ADDRESS` | string | 自取站地址 | |
| `POSTCODE_NO` | string | 自取站郵號 | |

## B4. 廠商 API 需回傳值（你的 API 要回給速達）

| 參數 | 型別 | 功能說明 | 補充 |
|---|---|---|---|
| `STATUS` | string | 狀態 | **`S`：成功／`F`：失敗** |
| `MESSAGE` | string | 訊息 | **如果錯誤會在頁面顯示此訊息** |

## B5. 事前登錄（必須先向速達註冊，否則無法使用）

要使用黑貓自取站地圖系統，**廠商必須先提供以下資訊給速達存入速達資料庫，設定完畢後才能正式使用**：

| 欄位名稱 | 中文 | 功能說明 | 欄位型態 |
|---|---|---|---|
| `UID` | 廠商代號 | 預設對應廠商統編（如果要使用其他代碼也可）| 英數字，長度 20 |
| `NAME` | 廠商名稱 | 廠商名稱 | 中英文數字，長度 30 |
| `DESC` | 功能說明 | 簡述是何種功能使用 | 中英文數字，長度 100 |
| `URL` | **廠商 WEB API 網址** | 當使用者選取自取站後要回傳給廠商的 WEB API 網址 | 英數字，長度 500 |
| `PKEY` | 網站金鑰 | 防止被外部駭客破壞，使用的保護參數，**不須提供**（預設 PIC 統編 `16151427`）| 英數字，長度 20 |

## B6. 互動流程

```
1. 你的網站 → 導轉到 CAT0001MM1?UID=..&CUST_NO=..&TRADE_ID=..&PKEY=..
2. 使用者用「縣市 / 鄉鎮市區」兩個下拉選單過濾，點「查詢」
   （直接查詢會列出全國資料；結果區塊每頁最多顯示 5 筆）
3. 點地址後方的地圖 icon → 頁面中央顯示地圖
4. 點「選取自取站」→ 跳出 POPUP 確認視窗 → 點「確認」
5. 速達依 UID 到自家資料庫取出你註冊的 WEB API 網址
6. 速達用 ajax 呼叫該網址，帶上 CUST_NO / TRADE_ID / OFFICE_NO /
   OFFICE_NAME / OFFICE_ADDRESS / POSTCODE_NO
7. 你的 API 回 { STATUS: 'S' | 'F', MESSAGE: '...' }
8. 回傳成功後速達關閉該頁面；點「取消」則僅關閉 POPUP
```

## B7. 整合注意事項

- **回傳機制與 7-11 地圖完全不同**：7-11 是「POST 回你的網頁」，自取站是「**速達 server 端 ajax 呼叫你註冊的 API**」。後者你必須**事先向速達登錄 URL**，臨時改網址無法生效。
- **`TRADE_ID` 是唯一的關聯鍵**（最多 30 碼英數字），你的 API 收到後要靠它找回是哪一張訂單。**要能防重放**——同一 `TRADE_ID` 被呼叫兩次要冪等處理。
- **`PKEY` 不是真正的簽章**（官方自述「預設 PIC 統編，避免被攻擊」，且是**固定值 `16151427`**）。這是**弱保護**——你的回傳 API 必須**自行驗證來源**（IP 白名單 `103.234.81.15` / `61.57.232.216`、`TRADE_ID` 一次性且有時效、`CUST_NO` 比對），**不可只靠 `PKEY`**。
- 你的 API 回 `STATUS='F'` 時，`MESSAGE` 會**直接顯示在速達頁面上給消費者看**——訊息內容要對消費者友善，且**不可洩漏內部錯誤細節**。
- 取得的 `OFFICE_NO` 填入 `PrintOBT` 的 **`ReceiptStationNo`**，並且**必須同時設定 `ReceiptLocation='02'`（到所）、`RecipientAddress` 留空**（見 `api-reference.md` §13.5）。
- `POSTCODE_NO`（自取站郵號）是**收件端**郵號，與 `SenderZipCode`（寄件端）無關，不要填錯位置。

---

## C. 兩套地圖對照速查

| 項目 | 7-11 倉到店電子地圖 | 速達自取站地圖 |
|---|---|---|
| 導轉方式 | **POST form** | **GET querystring** |
| 回傳方式 | **POST 到 `ReturnUrl`**（前端導轉）| **速達 ajax 呼叫你的 API**（後端對後端）|
| 事前登錄 | 不需要（`ReturnUrl` 動態帶入）| **必須**（UID / NAME / DESC / URL / PKEY）|
| 關聯鍵 | `TempVar`（≤ 20 字元）| `TRADE_ID`（≤ 30 碼英數字）|
| 溫層過濾 | ✅ `StoreCategory`（13/14/15）| ❌ 無 |
| 回傳門市代碼 | `storeid` | `OFFICE_NO` |
| 回傳地址 | `storeaddress` | `OFFICE_ADDRESS` |
| 回傳郵號 | ❌ 無 | ✅ `POSTCODE_NO` |
| 回傳配送週期 | ✅ `ship`（7 碼）| ❌ 無 |
| 回傳離島旗標 | ✅ `outside` | ❌ 無 |
| 你需要回應 | ❌ 不需要 | ✅ `{ STATUS, MESSAGE }` |
| 對應印單 API | `PrintOBTByB2S.ReceiveStoreId` | `PrintOBT.ReceiptStationNo` + `ReceiptLocation='02'` |
| 正式環境 TLS | ✅ https | ❌ **http + IP 位址** |
