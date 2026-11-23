---
name: meian-shopcom-affiliate
description: >
  美安台灣（Market America / MarketTaiwan）TW.SHOP.COM 夥伴商店聯盟行銷分潤串接完整技術參考，
  對應官方「附件1-串接技術文件-20230907」+「附件2-常見問題-20200218」。
  這是 affiliate / 導購分潤追蹤串接，**不是金流、不是物流、不是電子發票**。
  涵蓋四大工項：(1) 擷取 RID / Click_ID 兩組追蹤參數並落庫；
  (2) 訂單成立回拋 Conversion API（api.hasoffers.com，Target=Conversion&Method=create）；
  (3) 失效 / 取消 / 退訂回拋（同端點加 data[is_adjustment]=1、金額傳負值）；
  (4) 產品銷售資料表 XML feed（Products/Product，14 欄位，每日自動抓取、約 24 小時同步）。
  另含防詐騙宣導資訊（訂單完成頁 + 防詐騙專頁為合作必要功能）與營業人名稱 / 統一編號揭露規定。
  認證機制為 **固定 IP 白名單**（無 API key / 無簽章 / 無加解密）。
  Use this skill whenever the task involves 美安、美安台灣、Market America、MarketTaiwan、
  markettaiwan、TW.SHOP.COM、tw.shop.com、SHOP.COM、夥伴商店、Partner Store、
  聯盟行銷、導購、分潤、佣金回拋、affiliate tracking、conversion postback、
  RID、Click_ID、Offer_ID、Advertiser_ID、api.hasoffers.com、HasOffers、
  NetworkId=marktamerica、NetworkToken、data[offer_id]、data[advertiser_id]、
  data[sale_amount]、data[payout]、data[revenue]、data[advertiser_info]、
  data[affiliate_info1]、data[ad_id]、data[session_datetime]、data[is_adjustment]、
  Order_Amount、Refund_Amount、Commission_Amount、ORDER_ID、
  產品資料表 XML、product feed、SKU / Name / Description / URL / Price / LargeImage /
  SalePrice / UPC / ISBN / MPN / Manufacturer / Brand / Category / EAN / Condition、
  partner@markettaiwan.com.tw、partnertech@markettaiwan.com.tw、
  防詐騙宣導、165 反詐騙、營業人名稱統一編號揭露，
  或整合美安導購分潤追蹤 / 商品 feed 產出。
  本 SKILL 為唯一 reference 來源——不要再去翻 .tmp/美安/ 底下的 PDF。
---

# 美安台灣 TW.SHOP.COM 夥伴商店串接（聯盟行銷分潤）

> **適用版本**：串接技術文件 **20230907**（PDF 內文無版號，以檔名日期為準）；常見問題 **20200218**（PDF 檔案 metadata 建立日 2020-03-25）
> **加密 / 驗章**：**無**。無 API key、無 signature、無 AES / SHA。**認證方式 = 固定 IP 白名單**（見「認證與前置作業」）
> **官方文件出處**：本地 PDF —
> - `C:\Users\User\DEV\zenbu\features\.tmp\美安\附件1-串接技術文件-20230907.pdf`（8 頁，主文件）
> - `C:\Users\User\DEV\zenbu\features\.tmp\美安\附件2-常見問題-20200218(1).pdf`（5 頁，FAQ）
> **更新驗證**：2026-07-20
> **萃取來源說明**：PDF 以 Microsoft Word 產出，CJK 走 Identity-H 嵌入子集字型，**`pdftotext` 會整段吐掉中文**（只剩 ASCII）。本 SKILL 全文改以 **PyMuPDF (`fitz`) `get_text()` + `find_tables()`** 萃取，四張參數表已用 `find_tables()` 逐格驗證欄位對齊；兩支 API URL 已重組去換行後逐一拆解 query string 比對。第 5 頁內嵌截圖（1409×745）已另存為 PNG 並判讀，內容記錄於「XML 欄位在 SHOP.COM 前台的呈現位置」。

美安（Market America）在台灣經營 **TW.SHOP.COM** 購物入口。消費者（文件稱「優惠顧客」）從 TW.SHOP.COM 的店家簡介頁 / 商品搜尋結果頁 / APP 點進**夥伴商店**（也就是你的網站）時，美安會在你的網址後方掛上 `RID` 與 `Click_ID` 兩個參數。你的網站負責：把這兩個參數收下來、綁在訂單上、**訂單成立時回拋 Conversion**、**訂單失效/取消時回拋負值 Conversion**，另外提供一份 **商品 XML feed** 讓美安匯入 TW.SHOP.COM 呈現。

底層跑在 **HasOffers** 聯盟行銷平台（URL 內 `Service=HasOffers`），美安在該平台的 network 代號為 `marktamerica`。

---

## 全流程一覽

```
[消費者] 在 TW.SHOP.COM 瀏覽 → 點擊夥伴商店連結
    ↓  美安在你的網址後方附加 ?RID=<RID>&Click_ID=<Click_ID>
[你的網站] 首頁 / 商品頁 皆須能接收這兩個參數 → 寫入 cookie（存活至瀏覽器關閉）
    ↓  消費者下單
[你的網站] 訂單成立 → 把 RID / Click_ID 隨訂單寫入 DB
    ↓  伺服器端（固定 IP，已加白名單）呼叫
[美安] Conversion API：api.hasoffers.com  Target=Conversion&Method=create
    ↓  回傳 JSON（請留存以利日後除錯）
    …
[你的網站] 訂單失效 / 取消 / 退訂
    ↓  同一支 API，金額傳負值 + data[is_adjustment]=1
[美安] 沖銷該筆分潤

（另一條獨立軌道）
[你的網站] 產出 產品銷售資料表 XML → 放固定網址
    ↓  美安主機每日自動檢查是否更新
[美安] 更新後約 24 小時同步呈現於 TW.SHOP.COM
```

---

## 認證與前置作業

| 項目 | 內容 |
|---|---|
| **認證機制** | **固定 IP 白名單**。文件原文：「由於傳送訂單紀錄功能有安全性設定，請提供所有會用來傳送訂單紀錄的主機 IP Address 給我們。」FAQ 進一步確認：「是的，請參考技術文件上的說明，我們需要將您的 IP 加入白名單，因此您需要提供固定 IP 給我們。」 |
| **需事先取得** | `Offer_ID`、`Advertiser_ID` 兩組專屬代碼 |
| **取得方式** | 提供貴公司基本資料 → 美安於 **5 個工作天內**提供兩組代碼。工作天不含週六、日與國定假日。 |
| **聯絡窗口（技術）** | `partnertech@markettaiwan.com.tw`（FAQ 指示：直接用信箱「全部回覆」即可） |
| **聯絡窗口（夥伴商店部門 / 商品審核）** | `partner@markettaiwan.com.tw` |
| **需提供給美安** | ① 所有回拋主機的固定 IP ② 產品資料檔 XML 的網址（以 Email 提供） |

> **PDF 未載明**：白名單以外是否還有任何 rate limit、逾時、retry 政策；`NetworkToken` 是否會輪替；是否有測試 / sandbox 環境（FAQ 只提到美安方會下「測試訂單」，測試訂單不需實際出貨）。

---

## 端點

| 用途 | Method | URL |
|---|---|---|
| 訂單成立回拋 / 訂單取消回拋（**同一支端點**） | 文件未指定（URL 為 query string 形式，實務為 `GET`） | `https://api.hasoffers.com/Api` |

> **只有一個 API 端點**。「訂單成立」與「訂單取消」的差別僅在於：金額正負、以及取消時多帶 `data[is_adjustment]=1`。
> **PDF 未載明**：正式 / 測試環境並未分離，文件全篇只出現這一個 URL；HTTP method 未明文指定。

---

## 1. 擷取參數（RID / Click_ID）

消費者從 TW.SHOP.COM 進入你的網站時，美安會在網址後方附加兩個參數：

```
http://www.yourdomain.com.tw/?RID=<RID>&Click_ID=<Click_ID>
```

### 參數表（PDF 第 1 頁，verbatim）

| 序號 | 欄位名稱 | 形式 | 說明 |
|---|---|---|---|
| 1 | `RID` | 文字 | 255 個字元 |
| 2 | `Click_ID` | 文字 | 255 個字元 |

### 註記（PDF 第 1 頁，verbatim）

- **註1**：此兩組參數由 TW.SHOP.COM 網站帶至您的網站，您僅需擷取並記錄，**不需檢核格式**。
- **註2**：若您的網站已使用了 `?`，我們會自動將 `RID` 前方改為 `&`。
- **註3**：若您使用 cookie 處理，請將 **cookie 保留到使用者關閉瀏覽器為止**。

### 官方範例（PDF 第 1 頁，verbatim）

> 編號 882516 美安會員或顧客透過 SHOP.COM 網站，要進入家樂福線上購物網站，我們會在家樂福線上購物的網址 `https://online.carrefour.com.tw` 後方加上：

```
https://online.carrefour.com.tw?RID=C8825168-t6666666666666666666&Click_ID=102eb2f7d72c0ad7c4c38e1cd197a4
```

> 由此範例可觀察到（**格式觀察，非 PDF 明文規範，且 PDF 明講「不需檢核格式」**）：`RID` 形如 `C{會員編號}{校驗字元}-t{19 位數字}`，`Click_ID` 為 30 位十六進位字串。**請勿據此寫格式驗證**。

### 實作要求（PDF 第 1 頁，verbatim 重點）

- **首頁與商品頁面都要能接收**這兩個參數（不是只有首頁）。
- 優惠顧客完成訂單時，**寫入您的資料庫保存**。
- **於訂單成立或取消時回拋相對應資訊**。

---

## 2-1. 訂單成立紀錄（Conversion 回拋）

### 完整 URL（PDF 第 2 頁，verbatim，已重組換行）

```
https://api.hasoffers.com/Api?Format=json&Target=Conversion&Method=create&Service=HasOffers&Version=2&NetworkId=marktamerica&NetworkToken=<NETWORK_TOKEN>&data[offer_id]=<Offer_Id>&data[advertiser_id]=<Advertiser_ID>&data[sale_amount]=<Order_Amount>&data[affiliate_id]=12&data[payout]=<Commission_Amount>&data[revenue]=<Commission_Amount>&data[advertiser_info]=<ORDER_ID>&data[affiliate_info1]=<RID>&data[ad_id]=<Click_ID>&data[session_datetime]=<Date>
```

### 固定參數（URL 內硬編碼，PDF 未列入欄位表但必須照送）

| 參數 | 固定值 | 備註 |
|---|---|---|
| `Format` | `json` | 回應格式 |
| `Target` | `Conversion` | |
| `Method` | `create` | |
| `Service` | `HasOffers` | |
| `Version` | `2` | |
| `NetworkId` | `marktamerica` | ⚠️ **verbatim 照抄，是 `markt` 不是 `market`**（PDF 原文如此，兩支 URL 一致）。**不要自行「修正」拼字。** |
| `NetworkToken` | `<NETWORK_TOKEN>` | ⚠️ **本檔刻意不落明文**。實際值寫死在官方 PDF《附件1-串接技術文件-20230907》第 2 頁的範例 URL 中，自行取用並放進環境變數。見下方「安全性提醒」 |
| `data[affiliate_id]` | `12` | PDF 中為固定字面值 `12`，非變數 |

> **安全性提醒**：`NetworkToken` 是寫死在官方文件裡的 network 層級 token，**等同憑證**。實作時請放進環境變數 / secret manager，**不要 hardcode 進原始碼、不要 commit 進 repo、不要出現在前端**。此回拋一律由**伺服器端**發出（也才符合 IP 白名單機制）。

### 可變參數（PDF 第 2 頁欄位表，verbatim 逐格照抄）

| 序號 | 欄位名稱 | 形式 | 說明 |
|---|---|---|---|
| 1 | `Offer_ID` | 文字 | 帶入專屬 Offer ID 值 |
| 2 | `Advertiser_ID` | 文字 | 帶入專屬 Advertiser_ID 值 |
| 3 | `Order_Amount` | 數字 | 帶入消費者使用您網站上的折扣之後，實際支付「商品本身」的金額。請勿加上運費、手續費。<br>例如：消費者購買了 1,000 元的商品，並使用了您網站的 100 元折價券，並支付了 80 元的運費。此時您需要傳 **900** 給我們。 |
| 4 | `Commission_Amount` | 數字 | 帶入佣金金額並計算至小數點第二位<br>計算方式為訂單金額乘上佣金百分比 |
| 5 | `ORDER_ID` | 文字 | 帶入此訂單的訂單編號 |
| 6 | `RID` | 文字 | 帶入此訂單原始記錄到的 RID |
| 7 | `Click_ID` | 文字 | 帶入此訂單原始記錄到的 Clicik_ID（⚠️ `Clicik_ID` 為 PDF 原文拼字，指的就是 `Click_ID`） |
| 8 | `Date` | 日期 | 訂單日期，格式為 `YYYY-MM-DD` |

### 佔位符 → query key 對照

| 欄位表名稱 | 實際 query key |
|---|---|
| `Offer_ID` | `data[offer_id]`（URL 內佔位符寫作 `<Offer_Id>`，大小寫與欄位表不一致，屬 PDF 原文） |
| `Advertiser_ID` | `data[advertiser_id]` |
| `Order_Amount` | `data[sale_amount]` |
| `Commission_Amount` | `data[payout]` **與** `data[revenue]`（**同一個值送兩次**） |
| `ORDER_ID` | `data[advertiser_info]` |
| `RID` | `data[affiliate_info1]` |
| `Click_ID` | `data[ad_id]` |
| `Date` | `data[session_datetime]` |

### 注意事項（PDF 第 2 頁，verbatim）

- ◼ 請設定檢查機制，勿因操作錯誤或是消費者重新整理網頁等因素，**造成同一筆訂單重複回傳訂單成立紀錄**。
- ◼ 當執行此 API 後，我們會回傳 **JSON 格式的資料，請紀錄此資料以便日後除錯時可以使用**。

> **PDF 未載明**：回應 JSON 的欄位結構、成功 / 失敗判斷依據、HTTP 狀態碼。文件僅說「會回傳 JSON 格式的資料」並要求留存，**沒有給任何 response schema 或錯誤碼表**。實作時請**全文留存 raw response**，並在串接初期與美安技術窗口確認成功判準。

---

## 2-2. 失效訂單及訂單取消紀錄回傳

### 觸發時機（PDF 第 3 頁，verbatim）

1. 訂單因使用者未付款造成訂單失效
2. 消費者自行取消訂單
3. 網站管理者取消訂單
4. 日後消費者退訂訂單。
5. 其他造成訂單失效的各種原因。

### 完整 URL（PDF 第 3 頁，verbatim，已重組換行）

```
https://api.hasoffers.com/Api?Format=json&Target=Conversion&Method=create&Service=HasOffers&Version=2&NetworkId=marktamerica&NetworkToken=<NETWORK_TOKEN>&data[offer_id]=<Offer_Id>&data[advertiser_id]=<Advertiser_ID>&data[sale_amount]=<Refund_Amount>&data[affiliate_id]=12&data[payout]=<Commission_Amount>&data[revenue]=<Commission_Amount>&data[advertiser_info]=<ORDER_ID>&data[affiliate_info1]=<RID>&data[ad_id]=<Click_ID>&data[is_adjustment]=1&data[session_datetime]=<Date>
```

### 與「訂單成立」URL 的差異（**只有兩處**）

| # | 差異 |
|---|---|
| 1 | `data[sale_amount]` 佔位符由 `<Order_Amount>` 改為 `<Refund_Amount>`（**傳負值**） |
| 2 | **新增** `data[is_adjustment]=1`（位置在 `data[ad_id]` 之後、`data[session_datetime]` 之前） |

其餘固定參數（`Format` / `Target` / `Method` / `Service` / `Version` / `NetworkId` / `NetworkToken` / `data[affiliate_id]=12`）完全相同。

### 可變參數（PDF 第 3 頁欄位表，verbatim 逐格照抄）

| 序號 | 欄位名稱 | 形式 | 說明 |
|---|---|---|---|
| 1 | `Offer_ID` | 文字 | 帶入專屬 Offer ID 值 |
| 2 | `Advertiser_ID` | 文字 | 帶入專屬 Advertiser_ID 值 |
| 3 | `Refund_Amount` | 數字 | 帶入退款金額，**負值** |
| 4 | `Commission_Amount` | 數字 | 帶入佣金金額，**負值**，並計算至小數點第二位<br>計算方式為退款金額乘上佣金百分比 |
| 5 | `ORDER_ID` | 文字 | 帶入此訂單**原始的**訂單編號 |
| 6 | `RID` | 文字 | 帶入此訂單原始記錄到的 RID |
| 7 | `Click_ID` | 文字 | 帶入此訂單原始記錄到的 Clicik_ID |
| 8 | `Date` | 日期 | 訂單日期，格式為 `YYYY-MM-DD` |

### 注意事項（PDF 第 3 頁，verbatim）

- ◼ 若消費者**分次退訂，您可以分次回傳**取消訂單資訊給我們。
- ◼ 請設定檢查機制，勿因操作錯誤，**造成同一筆訂單回傳的取消金額超出原始的成立金額**。
- ◼ **取消訂單請以一個月內的訂單內為限，超過一個月的訂單請勿再回傳取消資訊。**
- ◼ 當執行此 API 後，我們會回傳 JSON 格式的資料，請紀錄此資料以便日後除錯時可以使用。

> **`Date` 欄位語意注意**：取消回拋的欄位表仍寫「**訂單日期**，格式為 `YYYY-MM-DD`」（不是取消日期），且 query key 仍為 `data[session_datetime]`。**PDF 未載明**此處應填原始訂單日期或取消當日——依欄位表字面應為**原始訂單日期**，但建議串接時向美安確認。

---

## 3. 產品銷售資料表 XML 檔案

### 運作方式（PDF 第 4 頁，verbatim 重點）

- 依指定格式製作 XML 檔案，**儲存於您的伺服器上**，提供美安該檔案的**網址**。
- 若網址為 **HTTPS，請確認您的網站使用的是預設的 443 PORT**。
- 商品資訊異動時，**再次產生一份產品資料檔並放置於同一路徑**（網址不變）。
- 美安主機**每天自動檢查**您的資料內容是否更新，於更新後**約 24 小時**同步到 TW.SHOP.COM。
- **每一個必填欄位都須填寫，若遺漏了任一必填欄位，則該筆資料將會被略過不被匯入。**
- 欄位中需要使用特殊字元，**建議使用 CDATA 形式**呈現。
- **每一個標籤的英文字母大小寫必須與文件上的說明一致。**

### XML 格式（PDF 第 4 頁，verbatim）

```xml
<?xml version="1.0" encoding="utf-8"?>
<Products>
  <Product>
    <SKU>產品編號</SKU>
    <Name>商品名稱</Name>
    <Description>產品敘述</Description>
    <URL>產品網址</URL>
    <Price>售價</Price>
    <LargeImage>圖片網址</LargeImage>
    <SalePrice>優惠價</SalePrice>
    <UPC>UPC 商品條碼</UPC>
    <ISBN>ISBN 書碼</ISBN>
    <MPN>零件號碼</MPN>
    <Manufacturer>製造商名稱</Manufacturer>
    <Brand>品牌名稱</Brand>
    <Category>分類</Category>
    <EAN>EAN 商品條碼</EAN>
    <Condition>New</Condition>
  </Product>
</Products>
```

### 欄位表（PDF 第 5 頁，verbatim 逐格照抄，已用 `find_tables()` 驗證欄位對齊）

| 序號 | 欄位名稱 | 形式 | 說明 |
|---|---|---|---|
| 1 | `SKU` | C | 必填，最大 50 字元，帶入商品編號 |
| 2 | `Name` | C | 必填，最大 255 字元，帶入商品名稱 |
| 3 | `Description` | C | 必填，最大 4000 字元，帶入商品敘述 |
| 4 | `URL` | C | 必填，最大 255 字元，帶入商品頁面網址<br>請勿使用中文形式的網址，避免因為 URL encode 造成網址太長被截斷 |
| 5 | `Price` | N | 必填，最大 10 字元，帶入商品價格 |
| 6 | `LargeImage` | C | 必填，最大 2000 字元，帶入商品圖片網址<br>請使用 **RGB 模式的 jpg 或 gif 檔案，英文檔名** |
| 7 | `SalePrice` | N | 非必填，最大 10 字元，促銷價格 |
| 8 | `UPC` | C | 非必填，最大 20 字元，UPC 商品條碼 |
| 9 | `ISBN` | C | 非必填，最大 13 個字元，ISBN 書碼 |
| 10 | `MPN` | C | 非必填，最大 50 個字元，MPN 零件號碼 |
| 11 | `Manufacturer` | C | 非必填，最大 255 個字元，製造商名稱 |
| 12 | `Brand` | C | 非必填，最大 255 個字元，品牌名稱 |
| 13 | `Category` | C | 必填，最大 255 個字元，分類名稱。<br>您可使用貴網站的分類，亦可至 TW.SHOP.COM 網站選擇合適的分類。**請填寫最下層分類名稱。** |
| 14 | `EAN` | C | 非必填，最大 13 個字元，EAN 商品條碼 |

**形式代號**：`C` = 字元（character）、`N` = 數字（numeric）。**PDF 未載明**此二代號的定義，此為由欄位性質（Price / SalePrice 為 N，其餘為 C）推得的合理解讀。

> ⚠️ **`Condition` 欄位不一致**：XML 範例含 `<Condition>New</Condition>`，但**第 5 頁的 14 欄欄位表完全沒有 `Condition` 這一列**（表格從 `SKU` 到 `EAN` 共 14 列，已逐格驗證）。是否必填、可填哪些值、最大長度，**PDF 未載明**。建議照範例固定填 `New`，並向美安確認。

> **`Category` 分類參考來源**：PDF 第 5 頁「TW.SHOP.COM」該處為超連結，指向 `https://tw.shop.com/departments.xhtml`（連結目標由 PDF link annotation 萃取，內文未印出此網址）。

### XML 欄位在 SHOP.COM 前台的呈現位置（第 5 頁內嵌截圖判讀）

PDF 第 5 頁在欄位表後寫著「您所輸入的各項資料將會呈現於 TW.SHOP.COM 商品詳細頁面，例如：」，接著是一張 1409×745 的 SHOP.COM 商品詳細頁截圖，以紅框標註各欄位落點：

| 標註 | 前台位置 |
|---|---|
| `<Category>` | 頁面上方麵包屑（範例：`SHOP.COM › 書籍、雜誌 › 雜誌`）——呼應欄位表「請填寫最下層分類名稱」 |
| `<Name>` | 商品標題（範例：「美安超夯行銷力暢銷新版-今周刊特刊系列」） |
| `<Price>` | 標題下方的**刪除線原價**（範例：~~NT$129~~） |
| `<SalePrice>` | 原價下方的**大字實售價**（範例：NT$123，其右並顯示現金回饋 `+ NT$5`） |
| `<LargeImage>` | 左側主要商品圖 |
| `<Description>` | 頁面下半的商品敘述區塊，末尾接「詳細內容」連結 |

截圖中另可見（**皆為前台呈現，非你需提供的欄位**）：夥伴商店名稱與「前往夥伴商店」按鈕、「評價此商店」連結、現金回饋金額、右側分享列。

### 注意事項（PDF 第 6 頁，verbatim）

- ◼ 請勿於資料表中放置**重複的商品**，避免因系統檢核時被阻擋無法上架。
- ◼ 準備產品資料時，**請勿使用被「毒性及關注化學物質管理法」列為有毒物質的化學名稱**，避免商品被系統阻擋。
- ◼ 因**口罩屬醫療器材**，若您販賣的商品為口罩，請先提供販賣許可予美安台灣公司夥伴商店部門審核：`partner@markettaiwan.com.tw`，待審核通過後將以予上架。

> **PDF 未載明**：XML 檔案大小上限、單檔商品筆數上限、檔案編碼以外的傳輸要求（gzip 等）、下架商品如何表達（是否只要從 feed 移除即可）、美安主機抓取的來源 IP 或 User-Agent、抓取失敗的通知機制。

---

## 4. 加入防詐騙宣導資訊（合作必要條件）

PDF 第 7 頁明列四項，其中 **1、2 為「此為雙方合作之必要功能」**，3、4 為建議選項。

| # | 項目 | 性質 | 要求 |
|---|---|---|---|
| 1 | **訂單完成頁面** | **[此為雙方合作之必要功能]** | 當美安的優惠顧客透過 TW.SHOP.COM 連結進入貴網站並完成訂單時，請於訂單完成頁面中顯示防詐騙資訊 |
| 2 | **防詐騙宣導專頁** | **[此為雙方合作之必要功能]** | 請於網站中加入宣導防詐騙相關資訊之**專屬頁面** |
| 3 | **網站進入頁** | [此為建議選項] | 當優惠顧客透過 TW.SHOP.COM 連結進入貴網站時，請使用**新視窗**顯示防詐騙資訊，並於該視窗中增加一個**關閉按鈕**，畫面顯示至消費者點下關閉按鈕為止 |
| 4 | **發送電子郵件提醒** | [此為建議選項] | 定期寄送防詐騙宣導資訊給消費者 |

呈現方式可依貴網站的美術、排版設計，但**請務必確保相關文字必須清晰可閱讀**。

### 建議文案（PDF 第 7 頁，verbatim）

**訂單完成頁面**：

> 「提醒您，以下都是詐騙：訂單設錯、訂單金額有誤、設成分期每月扣款、重覆訂購多筆、宅配或超商出錯、簽收單簽成簽帳單、條碼刷錯、重覆扣款。歹徒會以更改訂單要求退費給您為由，要求您操作 ATM，這些都是詐騙手法。若遇可疑來電，隨時可撥打 165 反詐騙諮詢專線。」

**網站進入頁**：

> 「防詐騙！我們不會要求並指示您至 ATM 操作。ATM 只有匯款及轉帳功能，無法解除分期付款或訂單錯誤問題。隨時可撥打 165 反詐騙諮詢專線。」

### 註記（PDF 第 7 頁，verbatim）

> 註: *1: 當美安的優惠顧客會透過 TW.SHOP.COM 連結至貴網站時，我們會帶入兩個參數到貴網站，**您可使用此兩個參數來判斷消費者是由 TW.SHOP.COM 而來**。相關說明，請參考本文件第 1 項「擷取參數」之敘述。

→ 也就是說：**是否顯示防詐騙視窗（第 3 項）可以用 `RID` / `Click_ID` 是否存在來判斷來源。**

更多防詐騙資訊：
- 內政部警政署 165 全民防騙網：https://165.npa.gov.tw/
- 臉書專頁：https://www.facebook.com/165bear/

---

## 5. 揭露「營業人名稱」及「統一編號」

PDF 第 8 頁，verbatim：

> 根據財政部「稅籍登記規則」及「稅捐稽徵機關管理營利事業會計帳簿憑證辦法」，自 **112 年 1 月 1 日**起，營業人專營或兼營以網路平臺必須清楚揭露「營業人名稱」及「統一編號」。
>
> 請您的網站公司或工程師於您的網站中揭露相關資訊，您可依照您的網站設計將此資訊顯示於貴網站的**頁尾、公司介紹頁面，或是消費者可以清楚看到的地方**。

相關說明可參考財政部宣導說明（連結目標由 PDF link annotation 萃取，內文未印出網址）：
`https://www.etax.nat.gov.tw/etwmain/tax-info/network-transaction-taxtation-area/press/vODLnWe`

---

## 實作範例

完整可執行範例（擷取並保存 RID / Click_ID、金額計算、訂單成立回拋、冪等性防護）
見 [`references/implementation-examples.md`](references/implementation-examples.md)。

> ⚠️ 該檔程式碼為依 PDF 邏輯撰寫的示範，非 PDF 原文；規格以本檔 verbatim 區塊為準。

---

## FAQ 重點（工程相關）

完整 20 則 Q&A 見 `references/faq.md`。以下為與串接直接相關者：

| 問題 | 回答重點 |
|---|---|
| 兩組專屬代碼如何取得？ | 依技術文件說明提供貴公司基本資料，**5 個工作天內**提供。（⚠️ FAQ 原文寫「需要 Offer_ID 和 Click_ID 兩組代碼」，但技術文件中由美安核發的是 **`Offer_ID` 與 `Advertiser_ID`**；`Click_ID` 是每次點擊動態產生的追蹤值，不是核發代碼。**研判為 FAQ 筆誤**——verbatim 原文保留於 `references/faq.md`。） |
| 基本資料寄給誰？ | 使用信箱「全部回覆」即可回到 `partnertech@markettaiwan.com.tw` |
| 工作天定義 | 不含週六、日；國定假日不算工作天；5 個工作天是**上限**，不是一定要等滿 5 天 |
| 是否一定要固定 IP？ | **是**。美安需將你的 IP 加入白名單 |
| 產品資料怎麼給？ | 依技術文件備妥後，**用 Email 將產品資料檔網址**提供給美安 |
| 美安如何測試網站？ | 從首頁與商品頁進入，模擬消費者瀏覽後**下測試訂單**；之後夥伴商店部門以 email 聯繫，請依信件說明回覆報表與網站相關資料 |
| 測試訂單需要出貨嗎？ | **不需實際出貨** |
| 有沒有現成程式碼可直接套用？ | **沒有**。美安不提供程式碼，也未與任何網站建置公司合作 |

---

## 待澄清 / PDF 未載明彙整

| # | 項目 | 狀態 |
|---|---|---|
| 1 | **Conversion API 回應 JSON schema** | PDF 只說「會回傳 JSON 格式的資料，請紀錄」，**無任何欄位說明** |
| 2 | **錯誤碼表 / 狀態碼表** | **PDF 完全沒有**（兩份文件皆無） |
| 3 | **HTTP method** | 未指定；URL 為 query string 形式，實務推定 `GET` |
| 4 | **測試 / Sandbox 環境** | 未提供獨立測試端點；測試由美安方下測試訂單進行 |
| 5 | **`Condition` 欄位規格** | 出現在 XML 範例但**不在 14 欄欄位表中**；必填性、可用值、長度均未載明 |
| 6 | **取消回拋的 `Date` 語意** | 欄位表寫「訂單日期」，query key 為 `session_datetime`；是原始訂單日或取消日未明確 |
| 7 | **佣金百分比（commission rate）** | 未載明，屬合約約定值 |
| 8 | **XML feed 限制** | 檔案大小 / 筆數上限、下架商品表達方式、抓取失敗通知機制皆未載明 |
| 9 | **rate limit / retry / timeout** | 未載明 |
| 10 | **`NetworkToken` 是否會輪替** | 未載明；文件中為固定值 |
| 11 | **多筆訂單批次回拋** | 未提供批次介面；文件語意為**一筆訂單一次呼叫** |
| 12 | **`RID` / `Click_ID` 的有效期（歸因視窗）** | 未載明。僅規定 cookie 保留至瀏覽器關閉；跨 session 的歸因規則未定義 |

> 上述第 1、2 項是串接時最關鍵的缺口——**成功 / 失敗判斷無官方依據**。建議串接初期以 raw response 全量留存 + 人工比對美安後台報表校準。

---

## 常見誤解

- ❌ **「美安是金流商」** → 不是。這裡沒有付款、沒有請退款、沒有對帳單，只有**聯盟行銷分潤追蹤**。金流仍走你自己的金流商（ECPay / PAYUNi / NewebPay 等）。
- ❌ **「回拋要簽章 / 加密」** → 沒有。安全機制只有**固定 IP 白名單**。
- ❌ **「`NetworkId` 拼錯了，應該是 marketamerica」** → PDF 原文兩支 URL 都是 `marktamerica`，**照抄**。
- ❌ **「`payout` 和 `revenue` 是不同的值」** → PDF 中兩者都填 `<Commission_Amount>`，**同一個值送兩次**。
- ❌ **「`Order_Amount` 是訂單總額」** → 不是。是**扣除折扣後、不含運費與手續費**的商品金額。
- ❌ **「取消訂單要打另一支 API」** → 同一支端點，差別只在金額負值 + `data[is_adjustment]=1`。

---

## 參考檔案

- `references/faq.md` — 附件2 常見問題完整 20 則 Q&A（verbatim，依原文四大分類）
- `references/implementation-examples.md` — RID / Click_ID 擷取、金額計算、Conversion 回拋、冪等性防護的可執行範例（依 PDF 邏輯撰寫，非 PDF 原文）

---

**本 SKILL 為唯一 reference 來源——不要再去翻 `.tmp/美安/` 底下的 PDF。**
若需重新萃取，注意 `pdftotext` 會吐掉全部中文，必須改用 PyMuPDF (`fitz`)。
