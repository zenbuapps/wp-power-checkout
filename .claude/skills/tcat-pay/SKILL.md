---
name: tcat-pay
description: >
  統一客樂得多元支付平台（Kolet / 黑貓 PAY，統一速達旗下金流）WEB API 完整技術參考，
  對應官方規格說明文件 V1.28.2（2026-06-02）。
  主體是 **金流 / 收單 API**（超商繳款、ATM 虛擬帳號、線上刷卡、定期定額續期收款），
  domain 為 4128888card.com.tw。
  **另含平台附掛的物流端點 `/api/Logistics/*`（OpenMap / PrintOBT / PrintOBTByB2S /
  DownloadOBT）——用金流 Bearer token + ServiceId 認證，前提是已在 ccat.com.tw
  「會員專區 › 連線設定 › 物流服務」綁定速達契客代號與授權碼**；問「能不能用黑貓 PAY 憑證
  呼叫黑貓物流 API」看本 skill。完整 12 支印單 API（逆物流 / 貨態 / ParsingAddress /
  FTP 回檔，走 api.suda.com.tw + CustomerId/CustomerToken）仍屬 `tcat-logistics`。
  涵蓋 OAuth Token、CVS 超商銀行繳款（ibon / 安源 / 統一金流 ATM）、COCS 線上刷卡
  （授權 / 請款 / 取消 / 退款）、續期收款定期定額、APN 主動通知、MD5 驗章（checksum / chk）、
  process_code 狀態表、完整錯誤訊息表與測試 / 正式 endpoint。
  當程式碼或任務涉及：統一客樂得、客樂得、Kolet、黑貓 PAY、多元支付平台、統一速達金流、
  4128888card、cocs.4128888card.com.tw、/api/Collect、/api/Period、/Token、
  CvsOrderAppend、CvsOrderQuery、CocsOrderAppend、CocsOrderCancel、CocsOrderRefund、
  cust_id、cust_order_no、mer_trade_no、acquirer_type、limit_product_id、apn_url、
  checksum、hash_base、pay_route、ibon 繳款、續期收款、定期定額、指定請款、取消授權、
  /api/Logistics、OpenMap、PrintOBT、PrintOBTByB2S、DownloadOBT、ServiceId、
  ccat-for-woocommerce、ccat.com.tw、會員專區、連線設定、物流服務、速達契客代號、
  速達契客授權碼、黑貓 PAY 物流、金流憑證叫物流時，
  必須使用此 skill。完整端點清單見本檔內文。
---

# 統一客樂得多元支付平台（Kolet / 黑貓 PAY）WEB API

> **產品**：統一客樂得多元支付平台（官方文件標題「統一客樂得多元支付平台 WEB API 規格說明文件」；後台/品牌稱「黑貓 PAY」「統一客樂得-黑貓 PAY」）
> **系統版本**：`1.28.2`（文件更新日 2026-06-02，PDF 建檔 2026-06-09，共 97 頁）
> **文件來源**：官方 PDF《多元支付平台-WEBAPI介面規格(V1.28.2)》——本 SKILL 已完整萃取，無需再翻 PDF。
> **產品線關係**：統一速達（黑貓宅急便）旗下金流服務。官方 WEB API 規格書 V1.28.2 只涵蓋金流（收單/繳款）。
> **⚠ 但本平台另有一組文件外的物流端點 `/api/Logistics/*`，用金流的 Bearer token 認證**——見下方「§ 平台附掛的物流端點」。
> 前提是先在 `www.ccat.com.tw` 的「會員專區 › 連線設定 › 物流服務」綁定速達契客代號與授權碼（需已與統一速達簽約）。
> 需要完整物流功能（12 支端點、逆物流、貨態、FTP 回檔）仍走 `tcat-logistics`（`api.suda.com.tw`，認證機制完全不同）。

契約客戶（契客）用自有系統，透過 HTTPS + JSON API 串接三大收款方式：
1. **CVS 超商銀行繳款**：ibon 超商代碼繳款（收單行「安源」）、ATM 虛擬帳號（收單行「統一金流」）。取號後由消費者到超商/ATM 繳費。
2. **COCS 線上刷卡**：產生刷卡短網址，消費者線上刷卡；支援一次付清 / 分期 / 銀聯 / Apple/Google/Samsung Pay，含取消授權、指定請款、取消交易退款。
3. **續期收款（定期定額）**：週期性自動扣款（每週/每月/每年）。

## 傳輸與格式（全域規則）

- 傳輸資料一律 **JSON、UTF-8**；未特別註明 Content-Type 時使用 `application/json`。
- 採 .NET 內建 OAuth 認證；**取 Token 時** Content-Type 必須為 `application/x-www-form-urlencoded`。
- 需驗證的 API：Token 放 HTTP Header `Authorization: Bearer {token}`（**Bearer 與 token 間要有空格**）。
- 系統採 **TLS 1.2**；請確認程式與 OS 支援（PDF 明載 1.12 版起不支援 TLS1.1 以下）。

## 環境與 Endpoint 組成

| 環境 | BaseUrl |
|------|---------|
| 正式 | `https://cocs.4128888card.com.tw/` |
| 測試（Sandbox） | `https://test.4128888card.com.tw/app` |

> 呼叫網址 = **BaseUrl + 功能名稱**。正式與測試 **資料不互通**，帳密需分別開通。
> 例：取 Token 正式 `https://cocs.4128888card.com.tw/Token`、測試 `https://test.4128888card.com.tw/app/Token`；
> 新增訂單正式 `https://cocs.4128888card.com.tw/api/Collect`、測試 `https://test.4128888card.com.tw/app/api/Collect`。

| 功能 | 路徑（接在 BaseUrl 後） | 方法 | 說明 |
|------|------------------------|------|------|
| 取得 Token | `/Token` | POST (form-urlencoded) | OAuth 密碼授權 |
| CVS / COCS / 主檔 全部操作 | `/api/Collect` | POST (JSON + Bearer) | **單一端點，以 body 的 `cmd` 分派**（見下表） |
| 續期收款 新增 | `/api/Period` | POST (JSON + Bearer) | 定期定額建立 |
| 續期收款 狀態修改 | `/api/Period/MdfStatus` | POST (JSON + Bearer) | 啟用/暫停/終止/重新授權 |
| 續期收款 換卡 | `/api/Period/Exchange` | POST (JSON + Bearer) | 產生換卡短網址 |
| 續期收款 訂單修改 | `/api/Period/Modify` | POST (JSON + Bearer) | 修改金額/週期/期數（PDF 原文印為 `/api/Period/ Modify`，研判排版誤植了一個空格） |

### `/api/Collect` 的 `cmd` 分派表（**核心架構：不同操作靠 cmd 值區分，路徑相同**）

| cmd（固定字串）| 操作 | 分類 | 詳見 |
|------|------|------|------|
| `CvsOrderAppend` | 契客新增訂單（超商/ATM 取號）| CVS | `references/api-reference.md` |
| `CvsOrderQuery` | 訂單查詢（單筆）| CVS | 同上 |
| `CvsOrderListQuery` | 訂單日期區間查詢（≤800 筆）| CVS | 同上 |
| `CvsIbonUpdate` | 變更 ibon 繳款金額（僅安源）| CVS | 同上 |
| `CvsIbonUpdateDate` | 變更 ibon 繳款到期日（僅安源，需 checksum）| CVS | 同上 |
| `CocsOrderAppend` | 契客新增刷卡訂單（不主動通知）| COCS | 同上 |
| `CocsOrderAppend2` | 新增刷卡訂單-加值通知（發簡訊/EMAIL）| COCS | 同上 |
| `CocsOrderQuery` | 刷卡訂單查詢（單筆）| COCS | 同上 |
| `CocsOrderListQuery` | 刷卡訂單日期區間查詢 | COCS | 同上 |
| `CocsOrderCancel` | 訂單取消授權（請款前，僅全額）| COCS | 同上 |
| `CocsCashRequest` | 指定請款金額（全額或部分請款）| COCS | 同上 |
| `CocsOrderRefund` | 訂單取消交易（全額或部分退貨退款）| COCS | 同上 |
| `SmsShortNameUpdate` | 變更金流服務簡訊簡稱 | 主檔 | 同上 |

> 續期收款（`/api/Period*`）**不使用 cmd 欄位**，以獨立路徑區分。詳見 `references/recurring.md`。

## 平台附掛的物流端點 `/api/Logistics/*`（⚠ 不在官方 WEB API 規格書內）

**來源：不是 PDF，是官方外掛 `ccat-for-woocommerce` 的實作 + 平台後台實況**（2026-07-29 驗證）。
V1.28.2 規格書全文沒有這組端點，但外掛確實在打，**呼叫時完全共用金流那組憑證**——
request 裡沒有 `CustomerId`/`CustomerToken`、沒有簽章。

### 前置條件（★ 必讀，否則呼叫必失敗）

這組端點是**代理**，不是「黑貓 PAY 自帶物流」。你仍然要先與統一速達（＝黑貓物流）簽約：

1. 與統一速達簽約，取得**速達契客代號** + **速達契客授權碼**（即印單 API 授權碼，
   對應 `tcat-logistics` 的 `CustomerId` / `CustomerToken`）
2. 登入 `https://www.ccat.com.tw` → **會員專區 › 連線設定 › 物流服務**，把上述兩個值填進去綁定
3. 綁定完成後，`/api/Logistics/*` 才會對你的金流服務代號生效

**憑證是「寄放」而非「免除」**：授權碼仍然必要，只是從你的程式碼移到黑貓 PAY 後台保管，
之後由黑貓 PAY 拿它代你去打印單 API。沒簽物流約 = 這條路走不通，得自己另簽。

```
POST {BaseUrl}api/Logistics/{功能}
Headers: Content-Type: application/json
         Authorization: Bearer {金流 /Token 換來的 access_token}
Body:    { "ServiceId": "{金流服務代號，即 cust_id}", ... }
```

| 功能 | 路徑 | Body 主要欄位 | 外掛用途 |
|------|------|--------------|---------|
| 7-11 門市地圖 | `api/Logistics/OpenMap` | `ServiceId` / `ReturnUrl` / `TempVar` / `StoreCategory` | 回傳地圖網址字串，前端開窗選店 |
| 宅配託運單 | `api/Logistics/PrintOBT` | `ServiceId` / `PrintOBTType` / `Orders[]` | 建單，回 `Data.Orders[0].OBTNumber` + `Data.FileNo` |
| 7-11 到店託運單 | `api/Logistics/PrintOBTByB2S` | 同上，`Orders[].ReceiveStoreId` 必填 | 倉到店建單 |
| 下載託運單 PDF | `api/Logistics/DownloadOBT` | `ServiceId` / `FileNo` / `Orders[].OBTNumber` | 串流回 PDF |

- **認證身分是 `ServiceId`，值 = 金流服務代號**（外掛取 `get_account()`，即 `cust_id`／後台「商店代號」），
  **不是** 印單 API 的 10 碼物流客代。兩者數值不同，切勿互代。
- 回應外殼與印單 API 一致：判 `IsOK === 'Y'`，失敗看 `Message`。`DownloadOBT` 成功時直接回 PDF binary。
- 端點名（`PrintOBT` / `PrintOBTByB2S` / `DownloadOBT`）與 `tcat-logistics` 印單 API 同名同義，
  研判黑貓 PAY 在自家 domain 代理了印單 API 的子集，並把認證換成金流 OAuth。
- **只有這 4 支**。逆物流（`ReserveAdd/Edit/Delete/Query`）、貨態（`OBTStatus`）、
  郵號查詢（`ParsingAddress`）、呼叫黑貓（`Call`）**都沒有** → 需要這些就得走 `tcat-logistics`。
- 測試環境同樣掛在 `https://test.4128888card.com.tw/app/` 底下。
- ⚠ 因為不在官方文件內，欄位細節與錯誤碼**無規格書可查**，以外掛實作與實測為準。
  底層既然是印單 API，錯誤碼可先參照 `tcat-logistics` 的 `E001`–`E091` 表推敲。
- 外掛端只有一個本地開關 `{prefix}_shipping_enable`（yes/no），**沒有任何物流憑證欄位**——
  因為授權碼在黑貓 PAY 後台，不在 WP 這側。看到外掛沒填物流帳密就以為不用簽約，是誤解。

**參考實作**：官方 WordPress 外掛 `ccat-for-woocommerce`（黑貓 PAY 官方發布）。
關鍵檔案 — `includes/class-ccatpay-shipping-display.php`（建單 / 下載託運單）、
`711-checkout-block/class-ccatpay-711-blocks-integration.php`（`get_api_data()` 取三元組、`OpenMap` 選店）、
`includes/class-ccatpay-gateway-abstract.php`（`get_base_url()` / `get_payment_api_token()` / `get_account()`）。

## 取得 Token（`POST /Token`）

`Content-Type: application/x-www-form-urlencoded`，body：`grant_type=password&username={金流代號}&password={API密碼}`。

- `grant_type` 固定 `password`；`username` = 客戶金流代號（**與後續 API 的 `cust_id` 為同一組**）；`password` = API 密碼。
- 成功回 `access_token` / `token_type`(`bearer`) / `expires_in` / `userName` / `.issued`(GMT) / `.expires`(GMT)。
- 失敗回 `{ "error": "invalid_grant", "error_description": "使用者名稱或密碼不正確。" }`。
- **Token 有效期以回傳 `.expires` 為準**。⚠️ PDF 敘述矛盾：內文寫「有效期 3 小時」，但欄位說明與範例為「1 天（`expires_in`≈86399 秒）」。以實際回傳 `.expires` 為準，過期需重新取得。

PHP / C# 原廠範例見 `references/examples.md`。

## 驗章機制（兩套 MD5，勿混用）

本平台**無對稱加解密**（不是 AES）；資料完整性靠兩種 MD5 摘要，金鑰只有 COCS 導轉回報用到 `hash_base`。

### 1. `checksum`（冒號 `:` 串接，用於 APN 主動通知 + CvsIbonUpdateDate）

```
CvsIbonUpdateDate 請求：  checksum = MD5( cust_order_no + ":" + order_amount + ":" + nonce )
APN 主動通知（CVS/COCS）：checksum = MD5( api_id + ":" + trans_id + ":" + amount + ":" + status + ":" + nonce )
```
- `nonce`：不重覆的「時分秒(6 位) + 4 位隨機」= `HHNNSSRRRR`。
- **官方範例已驗證**（可直接拿來測實作）：
  - `MD5("20190402000001:250:21")` = `e309160d46bcefaa7dd8db18a23f179f`
  - `MD5("CV0000000000:550e8400e29b41d4a716446655440000:1250:D:1234569999")` = `3579609ba3914a49441e98cb7e8a55de`
  - `MD5("CC0000000001:550e8400e29b41d4a716446655440000:1250:B:1234569999")` = `d09d5532767453ad4c6ba9b649034187`
  - 串接符號 `:` **無空格**（PDF COCS 範例印出的空格為排版問題）。

### 2. `chk`（錢字號 `$` 串接 + `hash_base`，用於 COCS 導轉回報 + 續期首次授權回報）

```
授權完成：chk = MD5(hash_base +'$'+ order_amount +'$'+ send_time +'$'+ ret +'$'+ acquire_time +'$'+ auth_code +'$'+ card_no +'$'+ notify_time +'$'+ cust_order_no)
授權失敗：chk = MD5(hash_base +'$'+ order_amount +'$'+ send_time +'$'+ ret +'$'+ notify_time +'$'+ cust_order_no)
```
- `hash_base`：申請服務時由系統配發的密鑰。失敗版少了 `acquire_time`、`auth_code`、`card_no` 三欄。
- 收到導轉回報後**必須用同公式重算 `chk` 比對**再認定授權結果。詳見 `references/callbacks-apn.md`。

## 收單行 / 收款方式速查

**CVS 超商銀行繳款**（`payment_type` 決定繳款方式、`payment_acquirerType` 決定收單行）：

| 收單行 | payment_acquirerType | 支付類型 | 金額上限 |
|--------|:--:|------|------|
| 安源 | `2` | ibon 超商繳款（`payment_type=0`）| 20,000 元 |
| 統一金流 | `3` | ATM 虛擬帳號（`payment_type=1`）| 49,999 元 |

- 統一金流 ATM 可指定繳費銀行 `bank_id`：`822` 中國信託、`013` 國泰世華（幕後取號留空則隨機/由消費者選）。
- **變更繳款金額/到期日僅安源支援，統一金流不支援。**

**COCS 線上刷卡 / 續期收款**（`acquirer_type` 指定收單銀行）：`esun` 玉山銀行、`chinatrust` 中國信託、`payuni` 統一金流。訂單金額上限依合約（預設 100,000）。**信用卡分期與統一金流銀聯卡僅能全額請/退款。**

> 完整產品別 `limit_product_id`（如 `esun.normal`、`payuni.m12`、`payuni.unionpay`、`payuni.applepay`…）與 `period_type` 對照見 `references/codes-reference.md`。

## 建立訂單必填速覽

**CVS `CvsOrderAppend`** 必填：`cmd` `cust_id` `cust_order_no`(≤25 統一金流/≤30 安源) `order_amount` `expire_date`(YYYY-MM-DD) `payer_mobile` `order_detail`(≤500，若開 PDF 綜合繳款單則 ≤150；為法規必填品名) `payment_type` `payment_acquirerType`；統一金流另需 `payer_name`/`payer_postcode`/`payer_address`/`payer_email`。
**COCS `CocsOrderAppend`** 必填：`cmd` `cust_id` `cust_order_no`(≤30，可留空自動編 `YYYYMMDD######`) `order_amount` `order_detail`(≤500) `acquirer_type` `send_time`(yyyy-MM-dd HH:mm:ss，須為當下時間)。回傳 `url` = 刷卡短網址。
**開電子發票**（`b2c=1`）時另有一組共用發票欄位（`product_name`/`print_invoice`/`vehicle_type`/`vehicle_barcode`/`donate_invoice`/`love_code`/`buyer_bill_no`/`buyer_invoice_title`…），完整定義見 `references/api-reference.md` 與 `references/codes-reference.md`。

> **回覆欄位 CVS 與 COCS 共用**（同一份規格），用不到的欄位帶 `NULL` 或 `0`。全部欄位表見 `references/api-reference.md`。

## 交易結果 / 通知（三條路）

1. **COCS 授權導轉回報**（前景）：授權完成/失敗時瀏覽器 **redirect（GET query string）** 到契客指定網址，帶 `chk` 驗章。統一金流授權失敗**不轉址**（停留失敗頁）。
2. **APN 主動通知**（背景 server-to-server）：狀態變更時對契客 URL 發 **POST JSON**，帶 `checksum`。繳款/交易完成即時送一次，之後每 15 分鐘重送、**同一狀態碼最多送 3 次**；**契客回純文字 `OK` 即停止重送**。
3. **主動查詢**：`CvsOrderQuery` / `CocsOrderQuery`（單筆）、`*ListQuery`（區間）。

> APN 的 `status` 狀態碼、`payment_detail` 物件結構、`payment_code`（CVS=2 / COCS=1）詳見 `references/callbacks-apn.md`。

## 狀態碼速查（詳表見 `references/codes-reference.md`）

- **`process_code`（訂單程序狀態，附件 1）**：`0` 待確認 → `3` 等待繳納 → `4` 已繳納 → `6` 逾期 → `7`/`8` 撥款；刷卡類 `15` 授權完成 / `16` 授權失敗 / `17` 取消授權 / `22` 請款完成 / `27` 取消交易完成…（完整含 13/14/18/20/21/23/24/25/26/28/29）。
- **APN `status`（CVS）**：`A` 等待繳納 / `B` 已繳納 / `C` 契客註銷 / `D` 過期 / `E` 已預約撥款 / `I` 開發票 / `J` 折讓。
- **APN `status`（COCS）**：`B` 授權完成 / `O` 請款作業中 / `E` 請款完成 / `F` 授權失敗 / `D` 逾期 / `P` 請款失敗 / `M` 取消交易完成 / `N` 取消交易失敗 / `Q` 取消授權完成 / `R` 取消授權失敗 / `I` 開發票 / `J` 折讓。

## 注意事項與陷阱

1. **`cust_id` = Token 的 `username`**：同一組客戶金流代號；`cust_id` 與 token 不匹配會報錯「cust_id(XXXX)與 token(XXXX)不匹配」。
2. **`cust_order_no` 唯一**：同一契客代號下不可重複；重送報「您已經上傳過此一契約訂單號碼」。長度：CVS 統一金流 ≤25、安源 ≤30（開重複繳款自動新增訂單功能時保留 2~3 碼給系統）；COCS 需「三位數以上英數字或減號」。
3. **APN 以 `pay_amount`（實際繳款金額）為準**：有重複繳款時，需比對實收金額相符後才可出貨（勿只看 `order_amount`）。
4. **APN 冪等**：同一狀態最多送 3 次；務必回 `OK` 停止重送，並對重複通知做去重。
5. **`send_time` 必須是「傳送當下」時間**（yyyy-MM-dd HH:mm:ss），過舊會報「send_time 異常」。
6. **取消授權 vs 取消交易**：請款前用 `CocsOrderCancel`（僅全額）；已請款用 `CocsOrderRefund`（可部分）。分期/銀聯僅能全額。
7. **變更 ibon 金額/到期日僅限安源、且務必在消費者列印繳款單前**，否則實收金額可能不符。
8. **驗章符號別搞錯**：APN/CvsIbonUpdateDate 用 `:`（checksum）；COCS 導轉回報用 `$`+`hash_base`（chk）。
9. **統一金流不提供** ibon 繳款門市、QP 碼、繳款條碼；ATM 幕後取號預設關閉，需登入黑貓 PAY 平台開啟。
10. **`order_detail` 為政府法規必填品名**（2023-03-01 起），不可含 HTML tag。
11. **行動支付（DPH）已於 2026-01-01 停止**（版本 1.26 移除），本版文件已無此功能，勿再串接。
12. **金額型別**：`order_amount`/`cr_amount`/`refund_amount` 為整數數值（number），不可有小數位。

## References 導引

| 需求 | 檔案 |
|------|------|
| 每個 cmd / endpoint 的完整請求·回應參數表（型態/長度/必填/說明）+ 逐項錯誤訊息 | `references/api-reference.md` |
| 續期收款（定期定額）完整流程：建立/首次授權回報/狀態修改/換卡/訂單修改 | `references/recurring.md` |
| COCS 授權導轉回報（chk）、CVS/COCS APN（checksum、payment_detail、狀態碼、重送規則）| `references/callbacks-apn.md` |
| process_code 一覽表、limit_product_id/period_type、pay_route、收單行/銀行代碼、金額上限、電子發票欄位、錯誤訊息彙整 | `references/codes-reference.md` |
| 程式碼範例：PHP/C# 原廠取 Token、TypeScript checksum/chk 驗章（含已驗證測試向量）、建單/APN 處理 | `references/examples.md` |
