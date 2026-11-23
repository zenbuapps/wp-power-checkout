# 26 — AI 生成程式碼防護欄（Guardrails）

> 本檔由 SKILL.md 拆出，收錄「生成任何 ECPay 程式碼之前必讀」的跨服務規則。
> SKILL.md 只保留決策樹與導航；所有硬性禁令、速查表與自檢清單集中在這裡。
>
> **載入時機**：決策樹路由完成後、實際輸出程式碼之前，一律先讀本檔。

---

## 1. 合約模式：代收付（大特店）vs 新型閘道

ECPay 金流有兩種合約模式，**API 技術規格相同**，差異在於商務面：

| 比較項目 | 代收付模式 | 新型閘道模式 |
|---------|-----------|------------|
| **簽約對象** | 僅與綠界簽約 | 需分別與各銀行 + 綠界簽約 |
| **款項撥付** | 綠界代收後依約定時間撥款 | 由合約銀行直接撥付，綠界不經手款項 |
| **支援付款方式** | 信用卡、ATM、超商代碼/條碼、WebATM、TWQR、BNPL、微信、Apple Pay | 信用卡、ATM、超商代碼/條碼 + **美國運通 (AMEX)**、**國旅卡** |
| **可用金流服務** | AIO、站內付 2.0、信用卡綁定、幕後授權、幕後取號、Shopify、直播收款（**共 7 種**） | AIO、站內付 2.0、信用卡綁定、幕後授權（**共 4 種**，不含幕後取號/Shopify/直播） |
| **適用商戶** | 一般電商、中小型商戶 | 大型商戶、需 AMEX/國旅卡的場景 |
| **API 串接差異** | 無 — API 技術文件完全相同，串接方式不變 | 無 — 同左 |

> **開發者注意**：兩種模式的 API 端點、參數、加密方式完全一致，無需為不同模式寫不同程式碼。
> 差異僅在綠界後台的合約設定與銀行閘道設定。不確定選哪個？**先用代收付模式**（門檻最低）。

### 付款方式 × 金流服務 支援矩陣

> ⚠️ **SNAPSHOT 2026-03** | 來源：[developers.ecpay.com.tw](https://developers.ecpay.com.tw/) 開發者導覽首頁

#### 代收付模式 / 大特店模式（廠商僅與綠界簽約）

| 金流服務＼付款方式 | 信用卡一次付清 | 紅利折抵 | 分期付款 | 定期定額 | 銀聯卡 | Apple Pay | TWQR | 微信支付 | BNPL 無卡分期 | ATM | 超商代碼 | 超商條碼 |
|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| 全方位金流 AIO（guides/01） | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| 站內付 2.0 Web（guides/02） | ● | ● | ● | ● | ● | ● | | | | ● | ● | ● |
| 站內付 2.0 APP（guides/02） | ● | ● | ● | ● | ● | ● | | | | ● | ● | ● |
| 綁定信用卡（guides/02 §綁卡） | ● | | ● | | | | | | | | | |
| 非信用卡幕後取號（guides/03） | | | | | | | | | | ● | ● | ● |
| 信用卡幕後授權（guides/03） | ● | ● | ● | ● | ● | | | | | | | |

#### 新型閘道模式（廠商分別與銀行 + 綠界簽約）

> 新型閘道同時提供 **7 家銀行**的閘道服務，涵蓋市場約 **80%** 的信用卡，支援 3-24 期消費分期，並完整支援**國旅卡**與**美國運通卡 (AMEX)**。

| 金流服務＼付款方式 | 信用卡一次付清 | 紅利折抵 | 分期付款 | 定期定額 | 銀聯卡 | 美國運通 | Apple Pay | 國旅卡 |
|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| 全方位金流 AIO（guides/01） | ● | ● | ● | ● | ● | ● | ● | ● |
| 站內付 2.0 Web（guides/02） | ● | ● | ● | ● | ● | ● | ● | ● |
| 站內付 2.0 APP（guides/02） | ● | ● | ● | ● | ● | ● | ● | ● |
| 綁定信用卡（guides/02 §綁卡） | ● | | ● | | | ● | | |
| 信用卡幕後授權（guides/03） | ● | ● | ● | ● | ● | ● | | |

> ⚠️ 矩陣中空格表示「不支援」。BNPL 無卡分期由裕富 (URICH) / 銀角零卡 (Zingala) 提供，支援 3/6/9/18/24 期，最低消費金額 3,000 元。

---

## 2. ⚠️ 兩種 URL Encode 不可混用

| 協議 | 使用函式 | 調用位置 | 混用後果 |
|------|---------|---------|---------|
| AIO 金流（CMV-SHA256）| `ecpayUrlEncode()` | CheckMacValue 計算 | 混用 `aesUrlEncode` → CheckMacValue 永遠不符 |
| ECPG / 發票 / 物流 v2（AES-JSON）| `aesUrlEncode()` | Data JSON 加密前 | 混用 `ecpayUrlEncode` → TransCode ≠ 1 |

> 兩者差異：`ecpayUrlEncode` 先 `urlencode` → `strtolower` → .NET 字元替換；`aesUrlEncode` 只做 `urlencode`（空格→`+`，`~`→`%7E`），無 lowercase、無 .NET 替換。詳見 [guides/14 §對比表](./14-aes-encryption.md)。

---

## 3. Callback 格式速查表

> 不同服務的 Callback 接收方式與回應格式不同，混淆是最常見的高頻錯誤。

| 服務 | Callback 類型 | 讀取方式 | 必要回應 | RtnCode 型別 |
|-----|:---:|---------|---------|:-----------:|
| AIO 金流（CMV-SHA256）| Form POST | `$_POST` / `req.body`（urlencoded） | 純文字 `1\|OK` | 字串 `'1'` |
| 國內物流（CMV-MD5）| Form POST | `$_POST` / `req.body`（urlencoded） | 純文字 `1\|OK` | 字串 `'1'` |
| ECPG ReturnURL（S2S）| JSON POST | `php://input` / `req.body`（json） | 純文字 `1\|OK` | 整數 `1` |
| ECPG OrderResultURL（前端）| Form POST + ResultData | `$_POST['ResultData']` → json_decode | HTML 結果頁（無需 `1\|OK`） | 整數 `1` |
| 信用卡幕後授權（S2S）| JSON POST | `php://input` / `req.body`（json） | 純文字 `1\|OK` | 整數 `1` |
| 非信用卡幕後取號（S2S）| JSON POST | `php://input` / `req.body`（json） | 純文字 `1\|OK` | 整數 `1` |
| 全方位物流 v2 | JSON POST | `php://input` / `req.body`（json） | AES 加密 JSON（三層結構） | 整數 `1` |
| B2C 電子發票（AllowanceByCollegiate 限定）| Form POST | `$_POST` / `req.body`（urlencoded） | 純文字 `1\|OK` | 字串 `'1'` |
| ECTicket | JSON POST | `php://input` / `req.body`（json） | AES 加密 JSON + CheckMacValue | 整數 `1` |
| 直播收款（ReturnURL）| JSON POST | `php://input` / `req.body`（json） | 純文字 `1\|OK`（⚠️ 請求格式同ECTicket，但回應不同） | 整數 `1` |

> 完整 Callback 回應規則見下方 §5「不可省略 Callback 回應」。

---

## 4. 語言陷阱速查表

> 非 PHP 開發者必讀。完整實作見 [guides/13](./13-checkmacvalue.md) + [guides/14](./14-aes-encryption.md)。
> 📌 **語言慣例**：生成目標語言程式碼時，同時載入 `guides/lang-standards/{語言}.md`（如 [python.md](./lang-standards/python.md)、[go.md](./lang-standards/go.md)），確保命名、型別、錯誤處理符合該語言慣例。

| 語言 | 最常見 Bug | 解決方案 | 詳細位置 |
|------|-----------|---------|---------|
| Python | `quote_plus()` 不編碼 `~` | 手動替換 `~` → `%7e`（CMV）或 `%7E`（AES） | guides/13 §Python |
| Node.js | `encodeURIComponent()` 空格為 `%20` 非 `+` | 替換 `%20` → `+` | guides/13 §Node.js |
| Java | CMV: `HashMap` 不保證 key 順序。AES: `URLEncoder.encode` 不編碼 `!*` | CMV: 用 `TreeMap`。AES: 補 `.replace("!", "%21").replace("*", "%2A")` | guides/13 §Java, guides/14 §Java |
| C# | CMV: `WebUtility.UrlEncode` 不編碼 `~`。AES: `JsonSerializer` 預設轉義 `<>&+'` | CMV: 補 `~→%7e`。AES: 用 `UnsafeRelaxedJsonEscaping` | guides/13 §C#, guides/23 §C# |
| Go | CMV: `url.QueryEscape` 不編碼 `~`（需補 `~→%7e`）。AES: `url.QueryEscape` 不編碼 `~`（需補 `~→%7E`）；`json.Marshal` 預設轉義 `<>&` | CMV: 補 `~→%7e`。AES: 補 `~→%7E`；`SetEscapeHTML(false)` | guides/13 §Go, guides/23 §Go |
| Kotlin | CMV: `URLEncoder.encode` 不編碼 `~`。AES: 不編碼 `!*` | CMV: 補 `~→%7e`。AES: 補 `!→%21`, `*→%2A` | guides/13 §Kotlin, guides/14 §Kotlin |
| Ruby | `CGI.escape` vs `URI.encode_www_form_component` 行為不同 | 使用 `CGI.escape`，替換 `~` → `%7e` | guides/13 §Ruby |
| Rust | `form_urlencoded` crate 不編碼 `~` | 手動替換 `~` → `%7e`（CMV）或 `%7E`（AES） | guides/13 §Rust |
| Swift | `addingPercentEncoding` 不編碼 `+` 和 `~` | 使用自訂 `CharacterSet`，補編碼 `+~` | guides/13 §Swift |
| TypeScript | 同 Node.js | 同 Node.js | guides/13 §TypeScript |
| C/C++ | `curl_easy_escape` 行為因 libcurl 版本不同（7.x vs 8.x 對 `~` 處理有差異） | 使用 guides/13 §C 的自訂 `ecpay_url_encode()` 實作，不依賴 libcurl 版本 | guides/13 §C |

### 翻譯 PHP 為其他語言時，最關鍵的三個陷阱

1. **AES vs CMV URL-encode 邏輯不同**（全非 PHP 語言）— AES 不做 `toLowerCase` 和 `.NET 字元還原`，見 guides/14 §AES vs CMV 對比表
2. **空格編碼為 `%20` 而非 `+`**（Node.js, TypeScript, C, Swift, Rust）— 編碼後替換 `%20` → `+`
3. **`~` 未被編碼**（全非 PHP 語言）— 手動替換 `~` → `%7E`

> 其他陷阱（PKCS7 padding、JSON key 順序、compact JSON、`'` 編碼、HTML 轉義、hex 大小寫、timing-safe 比較）：見 guides/14 各語言章節。

---

## 5. AI 注意事項（不可做的事）

- **不可將 ECPG 等同於站內付 2.0**：ECPG 是 EC Payment Gateway 的簡稱，代表綠界的**線上金流服務**，涵蓋站內付 2.0、綁定信用卡、幕後授權等多項服務；站內付 2.0 只是其中之一。POS 刷卡機屬於**線下金流服務**，與 ECPG 平行而非從屬。同理，代收付模式（大特店模式）和新型閘道模式是合約模式，不可自行發明英文名稱（如 ~~"ECPG Model"~~、~~"General Model"~~）
- **不可使用 iframe** 嵌入綠界付款頁（會被擋，使用站內付 2.0 或新視窗）
- **不可混用** CMV 的 `ecpayUrlEncode` 和 AES 的 `aesUrlEncode`（兩者邏輯不同，見 guides/14 對比表）
- **不可假設所有 API 回應都是 JSON**（AIO 回 HTML/URL-encoded/pipe-separated）
- **不可在前端或版本控制中暴露** HashKey/HashIV
- **不可將 ATM RtnCode=2 或 CVS RtnCode=10100073 視為錯誤**（代表取號成功，消費者尚未付款）
- **生成程式碼或回答 API 規格問題時，必須 web_fetch references/ 中的對應 URL**：不可僅依賴 guides/ SNAPSHOT 或 AI 自身記憶回答。唯一可省略 web_fetch 的情況是：(1) 純概念說明且不涉及具體參數值，或 (2) web_fetch 失敗後的備援（但必須告知使用者）
- **URL 來源白名單（強制）**：回覆中引用的所有 ECPay 技術文件 URL **必須來自 references/ 檔案中列出的 443 個 URL**。禁止引用 AI 記憶中的 URL、第三方部落格、Stack Overflow、或任何非 `developers.ecpay.com.tw` 網域的連結作為 API 規格來源。若需要的 URL 不在 references/ 中，應告知使用者「此資訊未收錄於官方索引，建議至 developers.ecpay.com.tw 搜尋確認」
- **生成程式碼時必須標註資料來源**：在程式碼註解中標明參數值取自 SNAPSHOT 或 web_fetch（例如 `// Source: web_fetch references/Payment/... 2026-03-06`），方便開發者日後驗證
- **不可將 ECPG 所有端點都打向 ecpg domain**（查詢/請退款走 `ecpayment`；Token 類及 CreatePayment 走 `ecpg`，詳見 guides/02 端點表）
- **不可省略 Callback 回應**：CMV-SHA256（AIO）回 `1|OK`、**站內付 2.0 ReturnURL** 回 `1|OK`（官方規格 9058.md）、**站內付 2.0 OrderResultURL** 回 HTML 頁面（前端跳轉，不重試）、信用卡幕後授權回 `1|OK`（官方規格 45907.md）、非信用卡幕後取號回 `1|OK`、國內物流 CMV-MD5 回 `1|OK`、全方位/跨境物流 v2 回 **AES 加密 JSON**（三層結構）、ECTicket回 **AES 加密 JSON + CheckMacValue**（Data 內 `{"RtnCode": 1, "RtnMsg": "成功"}`）、**直播收款** 回 `1|OK`（⚠️ callback 格式與ECTicket相同：JSON POST + AES 解密 Data + ECTicket 式 CheckMacValue SHA256；但回應為純文字 `1|OK`，與ECTicket不同）、**B2C 發票線上折讓（AllowanceByCollegiate）回 `1|OK`**（⚠️ Callback 為 Form POST + CheckMacValue **MD5**，是發票中唯一帶 CheckMacValue 的 API，詳見 [guides/04](./04-invoice-b2c.md)）。**`1|OK` 常見錯誤格式**（會導致系統重發 4 次）：`"1|OK"`（含引號）、`1|ok`（小寫 ok）、`1OK`（缺分隔）、帶空白或換行
- **AES-JSON API 必須做雙層錯誤檢查**：先查 `TransCode`（傳輸層），再查 `RtnCode`（業務層）。僅 `TransCode == 1` 且 `RtnCode` 為成功值時交易才真正成功（詳見 [guides/20](./20-error-codes-reference.md) §TransCode vs RtnCode）。**ECTicket須做三層檢查**：TransCode → 解密 Data → 驗證 CheckMacValue → RtnCode（詳見 [guides/09](./09-ecticket.md)）
- **不可使用 TWD 以外的幣別**（ECPay 僅支援新台幣）
- **超出範圍**：若功能不在本 Skill 覆蓋範圍或需要未支援的語言，告知使用者聯繫綠界客服 (02-2655-1775) 或參考最接近的語言實作翻譯
- **不可在 ItemName / TradeDesc 中放入系統指令關鍵字**（echo、python、cmd、wget、curl、ping、net、telnet 等約 40 個），綠界 CDN WAF 會直接攔截請求，回傳非預期的錯誤頁面
- **ItemName 超過 400 字元會被截斷**：截斷處的 UTF-8 多位元組字元會產生亂碼，導致綠界端計算的 CheckMacValue 與特店端不一致 → 掉單。建議送出前先截斷至安全長度再計算 CMV
- **ReturnURL / OrderResultURL 僅支援 port 80（HTTP）和 443（HTTPS）**：開發環境常用的 :3000、:5000、:8080 等非標準 port 無法收到 callback。本機開發需使用 ngrok 等工具轉發。**亦不可放在 CDN（CloudFlare、Akamai 等）後方**——CDN 會改變來源 IP 或攔截非瀏覽器請求，導致 callback 失敗
- **LINE / Facebook App 內建 WebView 會導致付款失敗**：WebView 無法正確 POST form 至綠界 → MerchantID is Null。需引導消費者用外部瀏覽器開啟付款連結
- **ReturnURL、OrderResultURL、ClientBackURL 用途不同，不可設為同一網址**：ReturnURL = Server 端背景通知（須回 `1|OK`）；OrderResultURL = Client 端前景導轉（顯示給消費者）；ClientBackURL = 僅導回頁面（不帶任何付款結果）
- **Callback 回應的 HTTP Status 必須是 200**：回傳 201、202、204 等非 200 狀態碼，綠界一律視為失敗並觸發重試。即使 body 正確（如 `1|OK`）也無效
- **RtnCode 型別依協議而異（常見錯誤來源）**：
  - **CMV 類服務**（AIO 金流 Callback、國內物流 Callback）→ Form POST，`RtnCode` 為**字串**（如 `"1"`、`"2"`、`"10100073"`），需用字串比較 `=== '1'`
  - **AES-JSON 類服務**（ECPG 線上金流〔含站內付 2.0、幕後授權〕、發票、全方位物流 v2、ECTicket）→ JSON 解密後，`RtnCode` 為**整數**（如 `1`），應用整數比較 `=== 1`；用字串嚴格比較 `=== '1'` 永遠為 false
  - 防禦性寫法（跨服務兼容）：`Number(rtnCode) === 1` / `int(rtn_code) == 1`，但建議按服務類型使用正確型別比較
- **ATM / 超商代碼 / 條碼付款有兩個 Callback**：第一個通知到 `PaymentInfoURL`（取號成功，RtnCode=2 或 10100073），第二個通知到 `ReturnURL`（實際付款成功，RtnCode=1）。必須同時實作兩個端點，漏掉 PaymentInfoURL 會導致消費者拿不到繳費資訊
- **加密/解密每一步都必須驗證**：(1) AES 加密前確認 JSON 序列化正確（key 順序、無 HTML escape）；(2) AES 解密後確認得到合法 JSON（非 null/空字串）；(3) Base64 必須使用**標準 alphabet**（`+/=`），不可使用 URL-safe alphabet（`-_`）；(4) 若啟用 `NeedExtraPaidInfo=Y`，Callback 額外回傳的欄位**全部**必須納入 CheckMacValue 驗證（非 PHP 語言手動計算時最易遺漏）
- CheckMacValue 驗證**禁止使用 `==` / `===`**，必須使用 timing-safe 函式 → [guides/13 §timing-safe](./13-checkmacvalue.md)
- **DoAction（請款/退款/取消）僅適用於信用卡**：ATM、超商代碼、條碼付款為消費者臨櫃/轉帳付現，**不支援線上退款 API**。若開發者要求退款，必須先確認原交易的 `PaymentType` — 僅信用卡類（`Credit_CreditCard`）可呼叫 `/CreditDetail/DoAction`（Action=R），其他付款方式需透過綠界商家後台人工處理或聯繫客服
- **Callback 必須實作冪等（Idempotency）與重放保護**：綠界 Callback 可能因網路異常重送最多 4 次。處理邏輯應以 `MerchantTradeNo` 為 key 做 upsert（非 insert），避免重複入帳或重複出貨。建議同時檢查 `PaymentDate` 與系統時間差異，過大時記錄警告。實作建議：使用 `SELECT ... FOR UPDATE`（PostgreSQL/MySQL）或 unique constraint + upsert 確保同一 MerchantTradeNo 不會因併發 Callback 造成重複入帳
- **送出前驗證與消毒所有使用者輸入**：`ItemName`、`TradeDesc` 應過濾 HTML 標籤與控制字元（`\x00-\x1F`）；`MerchantTradeNo` 應限制為英數字（長度上限 20 字元）；金額 `TotalAmount` 必須為正整數。不做驗證可能觸發 WAF 攔截或 CheckMacValue 不符
- **MerchantTradeDate 必須使用 UTC+8 時區**：格式為 `yyyy/MM/dd HH:mm:ss`。伺服器若在海外或使用 UTC，必須先轉換為台灣時間，否則 ECPay 會拒絕超過允許時差的訂單
- **比對 RtnCode 時建議使用防禦性轉型**：`Number(rtnCode) === 1`（JavaScript）或等效寫法，避免因字串/數字型別差異導致判斷錯誤。AIO/國內物流 Callback 的 RtnCode 為字串 `'1'`，ECPG/發票解密後為整數 `1`
- **語言強制規則**：見 SKILL.md 頂部 CRITICAL 區塊（本規則優先順序最高）

> **AI 注意**：大多數請求只需載入 SKILL.md + 本檔 + 1-2 份 guide。
> **guides/ 參數表為 SNAPSHOT（2026-03）**—穩定度高（改動機率 < 5%），可作為流程理解的參考。
> **預設行為：有 web_fetch 能力時，一律先從 references/ 取得即時規格再回答。** guides/ 僅作為 web_fetch 失敗時的備援，且必須告知使用者資料來自 SNAPSHOT。
> **唯一可省略 web_fetch**：純概念說明（如「什麼是站內付？」）且不涉及具體參數值、端點路徑、或程式碼生成。
> guides/13、14、23 有 AI Section Index（行號索引），若只需單一語言可用 offset/limit 讀取特定行範圍。
> AES vs CMV 對比表見 guides/14 §AES vs CMV URL Encode 對比表（line 129-226）。
> guides/23 有約 1700 行，建議使用 AI Section Index 的行號範圍只讀取目標語言的 E2E 區段。

---

## 6. 程式碼翻譯品質準則

翻譯 PHP 範例為其他語言時：

1. 翻譯後程式碼必須可直接編譯/執行
2. 使用該語言 2024-2025 年的慣用寫法
3. 必須包含套件管理器安裝命令
4. 必須包含最低版本需求
5. 不變項：端點 URL、參數名、JSON 結構、加密邏輯、Callback 回應格式（見 [guides/21](./21-webhook-events-reference.md)）
6. **拆解 PHP SDK 封裝層**：PHP SDK 的 Service 類別隱藏了大量 HTTP 細節。翻譯前必須逐一確認：
   - `$_POST` / `$_GET` 背後的 **Content-Type** 是什麼（form-urlencoded vs JSON）
   - SDK 方法背後的實際 **HTTP 請求方式**（endpoint、headers、body 格式）
   - 回傳值的**實際型態**（字串 vs 物件 vs 陣列）
   - SDK 內建處理的**隱含行為**（如 3D Secure 跳轉、自動解密、錯誤重試）

   > 這些隱含行為不會出現在 API 文件中，必須從 PHP 範例程式碼和 `scripts/SDK_PHP/` 原始碼推斷。

---

## 7. AI 常犯錯誤清單（生成程式碼前自檢）

| # | 錯誤 | 後果 | 防範 |
|---|------|------|------|
| 1 | 混用 `ecpayUrlEncode`（CMV）與 `aesUrlEncode`（AES） | CheckMacValue 永遠不符 | 確認當前 API 協定後選用正確函式 |
| 2 | AES Base64 使用 URL-safe alphabet（`-_`） | 解密失敗 | 明確指定標準 alphabet（`+/=`） |
| 3 | Callback 回 `1\|OK` 格式錯誤（帶引號/小寫/換行） | 觸發最多 4 次重試 | 回傳精確 ASCII `1\|OK`，HTTP 200 |
| 4 | CheckMacValue 用 `==` 比對（非 timing-safe） | Timing attack 風險 | 使用語言對應的 timing-safe 函式 |
| 5 | 將 ATM `RtnCode=2` / CVS `RtnCode=10100073` 視為錯誤 | 訂單誤取消 | 取號成功碼≠付款成功碼 |
| 6 | 站內付 2.0 所有請求打同一 domain | 404 錯誤 | Token/CreatePayment→`ecpg`；查詢/退款→`ecpayment` |
| 7 | 使用 iframe 嵌入付款頁 | 瀏覽器封鎖 | 用站內付 2.0 或 `window.location.href` |
| 8 | `RtnCode` 型別比對錯誤（字串 vs 整數） | 判斷永遠失敗 | CMV 協定→字串；AES-JSON→整數 |
| 9 | 對非信用卡付款呼叫 DoAction 退款 | API 回錯誤 | 先檢查 PaymentType |
| 10 | ItemName 含系統關鍵字（echo、curl 等） | WAF 攔截 10400011 | 僅放商品名稱 |
| 11 | JS SDK `initialize` 傳整數（0/1）而非字串 | SDK 靜默失敗或連錯環境 | `ECPay.initialize('Stage', 1, cb)`（測試）或 `'Prod'`（正式） |
| 12 | 自訂容器 ID（如 `payment-form`） | 付款表單不渲染 | 必須使用固定 `<div id="ECPayPayment">`，SDK 硬編碼此 ID |
| 13 | 直接讀 `data.ThreeDURL`（扁平化）而非 `data.ThreeDInfo.ThreeDURL`（巢狀） | ThreeDURL 永遠取不到，交易逾時 | CreatePayment 回應為巢狀結構，後端需 `data['ThreeDInfo']['ThreeDURL']` |

---

## 8. 介接注意事項 URL 速查表

> ⚠️ **AI 必讀**：首次串接某服務時（本次對話中第一次涉及該服務），**必須 web_fetch 對應的介接注意事項頁面**，摘取所有注意事項主動告知開發者。

| 服務 | 介接注意事項 URL |
|------|----------------|
| AIO 金流 | https://developers.ecpay.com.tw/2858.md |
| 站內付 2.0 (Web) | https://developers.ecpay.com.tw/8987.md |
| 站內付 2.0 (App) | https://developers.ecpay.com.tw/9168.md |
| 國內物流 | https://developers.ecpay.com.tw/7400.md |
| 全方位物流 | https://developers.ecpay.com.tw/10092.md |
| 跨境物流 | https://developers.ecpay.com.tw/8291.md |
| B2C 電子發票 | https://developers.ecpay.com.tw/7854.md |
| B2B 電子發票（存證模式）| https://developers.ecpay.com.tw/24176.md |
| 離線 POS 電子發票 | https://developers.ecpay.com.tw/13768.md |
| 電子收據 | https://developers.ecpay.com.tw/64230.md |
| ECTicket（純發行） | https://developers.ecpay.com.tw/29916.md |
| ECTicket（價金保管，首選） | https://developers.ecpay.com.tw/40322.md |
| 信用卡幕後授權 | https://developers.ecpay.com.tw/45901.md |
| 非信用卡幕後取號 | https://developers.ecpay.com.tw/27984.md |
| Shopify | https://developers.ecpay.com.tw/29070.md |
| 直播收款 | https://developers.ecpay.com.tw/41022.md |

---

## 9. references/ 即時查閱機制

**references/ 是即時 API 規格入口，不是靜態文件。**

references/ 的 20 個檔案包含 443 個 URL，連結至綠界 `developers.ecpay.com.tw` 官方最新 API 規格頁面。
**guides/ = 如何做**（整合邏輯、流程、範例）；**references/ = 最新規格 + ⚠ 注意事項**（當前參數定義、欄位規格、限制條件）。兩者結合才是完整回答。

### 何時必須即時查閱（禁止僅依賴 guides/ 回答）

- 生成 API 呼叫程式碼時（確認端點路徑、必填參數、回應格式是否為最新）
- 具體 API 參數名稱、型態、必填/選填、長度限制
- 最新錯誤碼清單或特定錯誤碼含義
- API 端點是否有更新或異動、回應欄位的完整規格
- 確認該 API 的注意事項、限制條件、金額範圍、時間限制
- guides/ 內容與開發者實際呼叫結果有出入時

> ⚠️ guides/ 中所有參數表和端點 URL 標記為 **SNAPSHOT（2026-03）**，僅供流程理解，不可直接作為程式碼生成依據。

### 查閱流程

```
需要 API 規格？
├── 1. 從 references/ 找到對應檔案（例：references/Payment/全方位金流API技術文件.md）
├── 2. 讀取該檔案，找到相關章節 URL（例：https://developers.ecpay.com.tw/2866.md）
├── 3. web_fetch 該 URL
│      ├── 成功 → 摘取頁面所有 ⚠ 注意事項，在回覆或程式碼註解中主動告知
│      ├── 404 / 連線失敗 → web_fetch https://developers.ecpay.com.tw 首頁搜尋替代 URL
│      │      └── 仍失敗 → 以 guides/ SNAPSHOT 備援，但必須告知開發者並附上 reference URL
│      └── 回傳內容缺參數表 → 告知開發者手動開啟該 URL 確認
├── 4. 首次串接該服務？→ 另外 web_fetch §8 介接注意事項 URL
└── 5. references/ 未收錄的 API？
       → 禁止從 AI 記憶中編造或猜測 URL
       → 可 web_fetch developers.ecpay.com.tw 首頁搜尋；找到需註明「未收錄於 references/ 索引」
       → 找不到 → 告知開發者聯繫綠界客服 (02-2655-1775)
       → 禁止引用非 ecpay.com.tw 網域的第三方連結作為 API 規格來源
```
