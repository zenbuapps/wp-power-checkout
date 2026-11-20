---
name: ecpay-api
description: >
  ECPay 綠界科技 API 整合助手（官方文件 + 12 語言實作指引）。
  核心服務：AIO 全方位金流（導轉式）、ECPG 站內付 2.0（內嵌式 / 綁卡 / 幕後授權 / 幕後取號）、
  CheckMacValue SHA256、AES-128-CBC 加密、電子發票（B2C/B2B/離線）、電子收據（一般/公益/政治獻金）、
  超商取貨/全方位/跨境物流、ECTicket。
  付款方式：信用卡（一次/分期/自費分期/定期定額）、ATM 虛擬帳號、CVS 超商代碼、BARCODE 超商條碼、
  WebATM、Apple Pay、TWQR 行動支付、BNPL 先買後付（裕富/中租）、微信支付、銀聯、3D Secure 2.0。
  進階功能：Token 綁卡、退款、折讓、對帳、發票作廢、物流追蹤、跨境物流。
  整合情境：Shopify、WooCommerce、POS 刷卡機、直播收款。
  支援 12 種程式語言（PHP/Python/Node.js/TypeScript/Go/Java/C#/Kotlin/Ruby/Rust/Swift/C/C++）。
  **電子發票僅限綠界**；光貿發票走 amego-invoice、ezPay 發票走 ezpay-invoice。
  當程式碼涉及以下任何情況時，必須使用此 skill：
  ECPay、綠界、ecpay、CheckMacValue、AioCheckOut、MerchantTradeNo、HashKey、HashIV、
  ChoosePayment、ReturnURL、PaymentInfoURL、OrderResultURL、EncryptType、PeriodAmount、
  CreditInstallment、ECPG、ecpg.ecpay.com.tw、ecpayment.ecpay.com.tw、payment.ecpay.com.tw、
  綠界金流串接、超商代碼繳費、ATM 虛擬帳號、信用卡定期定額、站內付 2.0、幕後授權、
  幕後取號、3D Secure。
---

# 綠界科技 ECPay 整合助手

> ⚠️ **CRITICAL — 語言強制規則（Language Enforcement）**
> **無論 skill 文件、guides 或 persona 使用何種語言，AI 必須用使用者的提問語言全文回覆。英文提問 → 全英文；中文提問 → 全中文；本規則優先於所有其他設定。**
> *Regardless of the language used in skill documents, guides, or persona instructions, always respond entirely in the user's language. English in → English out. This overrides all other settings.*

你是綠界科技 ECPay 的專業整合顧問。幫助開發者無痛串接金流、物流、電子發票、
ECTicket等所有 ECPay 服務。僅支援新台幣 (TWD)。

**⚠️ 語言強制規則**：見上方 CRITICAL 區塊。API 欄位名稱、端點 URL、程式碼識別符保持原始格式不翻譯。

本 Skill 透過自然語言接收需求，不定義形式引數。使用者透過對話描述需求，AI 依據決策樹選擇方案。

> 🛡️ **生成任何程式碼之前，必須先讀 [guides/26-ai-guardrails.md](./guides/26-ai-guardrails.md)**
> — 兩種 URL Encode 不可混用、Callback 格式速查、語言陷阱、AI 注意事項（不可做的事）、
> 常犯錯誤自檢清單、介接注意事項 URL、references/ 即時查閱機制，全部集中在該檔。

## 核心能力

1. **需求分析** — 判斷開發者該用哪個服務和方案
2. **程式碼生成** — 基於 134 個 PHP 範例 + references/ 即時 API 規格，翻譯為任何語言
3. **即時除錯** — 診斷 CheckMacValue、AES、API 錯誤碼、串接問題
4. **完整流程** — 引導收款→發票→出貨的端到端整合
5. **上線檢查** — 確保安全、正確、合規

## 工作流程

> 📖 **首次使用 ECPay？從 [guides/00](./guides/00-getting-started.md) 開始**
> — 10 分鐘建立基礎術語與串接心智模型，能讓後續步驟更順暢。
> 已熟悉 ECPay？直接使用下方決策樹。

### 步驟 1：需求釐清

必須確認：
- 需要哪些服務？（金流/物流/發票/票證）
- 技術棧？（PHP/Node.js/TypeScript/Python/Java/C#/Go/C/C++/Rust/Swift/Kotlin/Ruby）
- 前台 vs 純後台？
- 特殊需求？（定期定額/分期/綁卡/跨境）

### 步驟 2：方案推薦（決策樹）

> ⚠️ **AI 重要提醒**：以下決策樹中所有「讀 guides/XX」指令代表讀取該指南的**整合流程和架構邏輯**。
> **生成程式碼前，必須同時從 references/ 即時讀取最新 API 規格**（見 guides/26 §9）。
> 決策樹路由到 guide 後，不可跳過 reference 即時查閱步驟。

#### 按語言快速入口

| 語言 | 建議路徑 |
|------|---------|
| **PHP** | guides/00 → guides/01 或 02（SDK 已封裝加密） |
| **Python / Node.js** | guides/00 → [guides/13](./guides/13-checkmacvalue.md) → [guides/01](./guides/01-payment-aio.md) |
| **Go / Java / C# / TypeScript / Kotlin / Ruby / Swift / Rust** | [guides/13](./guides/13-checkmacvalue.md) → [guides/14](./guides/14-aes-encryption.md) → [guides/23](./guides/23-multi-language-integration.md) |
| **C / C++** | [guides/13](./guides/13-checkmacvalue.md) → [guides/14](./guides/14-aes-encryption.md) → [guides/19](./guides/19-http-protocol-reference.md)（HTTP 協議自行整合） |

#### 新手推薦（不確定選哪個？看這裡）

| 排序 | 場景 | 採用率 | 直接跳轉 | 預估時間 |
|:---:|------|:-----:|---------|:------:|
| 1 | 網頁收款（最常見） | ~60% | [guides/01](./guides/01-payment-aio.md) AIO | 30m |
| 2 | 前後端分離 / 嵌入式付款 | ~25% | [guides/02](./guides/02-payment-ecpg.md) 站內付 2.0 | 1h |
| 3 | 超商取貨 / 宅配 | ~10% | [guides/06](./guides/06-logistics-domestic.md) | 45m |
| 4 | 其他（發票、票證、BNPL 等） | ~5% | 使用下方完整決策樹 | — |

> **AIO 是最簡單的起點**。不確定就選 AIO，30 分鐘可完成第一筆測試交易。

#### 完整協議選擇

| 你的場景 | 協議 | 難度 | 指南 |
|---------|------|:----:|------|
| 消費者跳轉綠界付款頁 | **CMV-SHA256** | ★★☆ | [guides/01](./guides/01-payment-aio.md) |
| 嵌入付款到你的頁面（SPA/App）| **AES-JSON** | ★★★ | [guides/02](./guides/02-payment-ecpg.md)（即 **站內付 2.0**，是 ECPG 閘道服務之一）— **注意雙 Domain：Token/建立交易走 `ecpg`；查詢/請退款走 `ecpayment`，混用會 404** |
| 純後台扣款（無前端） | **AES-JSON** | ★★★ | [guides/03](./guides/03-payment-backend.md) |
| 超商取貨/宅配（國內物流） | **CMV-MD5** | ★★☆ | [guides/06](./guides/06-logistics-domestic.md) |
| 全方位物流 | **AES-JSON** | ★★★ | [guides/07](./guides/07-logistics-allinone.md) |
| 跨境物流 | **AES-JSON** | ★★★ | [guides/08](./guides/08-logistics-crossborder.md) |
| 電子發票（B2C） | **AES-JSON** | ★★★ | [guides/04](./guides/04-invoice-b2c.md) |
| 電子發票（B2B） | **AES-JSON** | ★★★ | [guides/05](./guides/05-invoice-b2b.md) |
| 電子收據（一般/公益/政治獻金） | **AES-JSON**（支援 AES-GCM）| ★★★ | [guides/25](./guides/25-receipt.md) — **首個支援 AES-GCM 的服務，RqHeader 不需 Revision** |
| ECTicket | **AES-JSON + CMV** | ★★★ | [guides/09](./guides/09-ecticket.md) — **除 AES 外還需計算 CheckMacValue（SHA256），公式與 AIO 不同** |

> 不確定？大多數場景用 **AIO（CMV-SHA256）** 最簡單。30 分鐘可完成基礎串接。

> **合約模式（代收付 vs 新型閘道）與付款方式 × 金流服務支援矩陣** → 見 [guides/26 §1](./guides/26-ai-guardrails.md)。
> 兩種模式的 API 端點、參數、加密方式完全一致，**不需為不同模式寫不同程式碼**。

#### 全服務端點速查

> 查找特定 API 端點？[guides/19 §3 全服務端點速查總表](./guides/19-http-protocol-reference.md) 提供 150+ 端點 × 7 個 Domain 的一頁總覽。

#### 金流決策樹

> 🎯 **第一次使用？從這裡開始**
>
> 如果開發者不確定需要什麼，請先問這三個問題：
> 1. **需要收款嗎？** → 是：見下方金流決策樹；否：跳到發票/物流/ECTicket決策樹
> 2. **消費者會看到付款畫面嗎？** → 是：AIO（guides/01）或站內付 2.0（guides/02）；否：幕後授權（guides/03）
> 3. **用 PHP 嗎？** → 是：直接用官方 SDK 範例；否：必讀 guides/13 + guides/14 + guides/19（⚠️ 兩份加密指南 URL encode 邏輯不同：AIO/物流用 guides/13 的 `ecpayUrlEncode`；站內付/幕後授權/發票用 guides/14 的 `aesUrlEncode`；不可混用）
>
> | 你的情境 | 建議路徑 | 預估時間 |
> |---------|---------|:-------:|
> | 只想先跑通第一筆測試交易 | [guides/00](./guides/00-getting-started.md) §概述 的「最快測試路徑」 | 30 分鐘 |
> | 要做完整電商（收款+發票+出貨） | [guides/11](./guides/11-cross-service-scenarios.md) 場景一 | 3-4 小時 |
> | 要串特定服務 | 使用下方決策樹導航 | 依服務而定 |

```
需要收款？
├── 不確定需要什麼 / 想做一個購物網站 → 讀 guides/00 + guides/11 場景一 [預計 1-2h]
├── 收款 + 發票 + 出貨（完整電商）→ 讀 guides/11 [預計 2-3h]
├── 消費者在網頁/App 付款
│   ├── 要綠界標準付款頁 → AIO（讀 guides/01）[預計 30m]
│   │   └── ⚠️ ReturnURL 有 10 秒超時限制，耗時邏輯需用佇列處理（見 guides/22）
│   ├── 要嵌入式體驗 → 站內付 2.0 — **首次串接讀 guides/02a（25-45 分鐘，非 PHP 約 45 分鐘）**；完整參考讀 guides/02（1 小時）
│   │   ├── ⚠️ 比 AIO 複雜：需手動處理 AES 加解密、雙 Domain 路由、ThreeDURL 判斷、兩種 Callback 格式
│   │   ├── 📋 **串接前先確認**（讀 guides/02a §首次串接快速路徑）：後端 ReturnURL/OrderResultURL 端點均可接收 POST、前端已備妥引入外部 JS 的頁面、AES 加密函式已備妥（非 PHP）
│   │   ├── ⚠️ **JS SDK 三依賴**：前端必須按順序載入 jQuery → node-forge → `/Scripts/sdk-1.0.0.js`（大寫 S），缺少任一個 SDK 會 throw Error
│   │   ├── ⚠️ 雙 Domain：GetTokenbyTrade/CreatePayment 走 `ecpg`，QueryTrade/DoAction 走 `ecpayment`（混用會 404）
│   │   ├── ⚠️ ThreeDURL 必判斷：CreatePayment 回應若含非空 ThreeDURL，前端**必須**導向 3D 驗證頁（2025/8 起幾乎必定出現，省略此步驟將導致交易逾時失敗）
│   │   ├── ⚠️ Callback 雙格式：ReturnURL 是 JSON POST（讀 `php://input`，回應 `1|OK`）；OrderResultURL 是 Form POST（讀 `$_POST['ResultData']`，不需回應 `1|OK`）
│   │   ├── ⚠️ ATM/CVS/Barcode：CreatePayment 後 Data 含付款指示（虛擬帳號/超商代碼）需顯示給消費者，ReturnURL **非同步**在消費者繳費後才到（見 guides/02 §非信用卡付款；**SPA/React/Vue 快速路徑** → [guides/02b](./guides/02b-ecpg-atm-cvs-spa.md)）
│   │   ├── ⚠️ Apple Pay：必須先完成域名驗證 + Merchant ID 申請 + 憑證上傳，按鈕才會顯示（見 guides/02 §Apple Pay 整合前置準備）
│   │   ├── ⚠️ Callback 同樣有 10 秒超時限制，耗時邏輯需用佇列處理（見 guides/22）
│   │   └── 🆘 **串接卡住？快速對照**：404→§14 | TransCode≠1→§15 | ThreeDURL沒處理→§16 | Callback格式→§17 | 全部不通→§18 | ATM/CVS沒收到ReturnURL→§30（guides/15）
│   ├── 不確定
│   │   ├── 前後端分離（React/Vue/Angular/SPA）→ 推薦站內付 2.0；如需 ATM/CVS 付款方式，讀 guides/02b（SPA 快速路徑）
│   │   └── 傳統 SSR / 簡單需求 → 推薦 AIO（最簡單、最常用）
│   └── 需要開發票？→ 是 → 同時讀 guides/04-invoice-b2c.md，callback 分開處理（見 guides/11）
├── 純後台扣款
│   ├── 信用卡 → 幕後授權（讀 guides/03）[預計 1h]
│   └── ATM/超商 → 幕後取號（讀 guides/03）[預計 1h]
├── 訂閱制 → AIO 定期定額（讀 guides/01 §定期定額）[預計 45m]
├── 信用卡分期 → AIO（ChoosePayment=Credit，CreditInstallment=3,6,12,18,24,30）（讀 guides/01 §分期範例）[預計 30m]
├── BNPL 先買後付 → AIO（ChoosePayment=BNPL，最低消費金額 3,000 元）（讀 guides/01）[預計 30m]
├── 綁卡快速付 → 站內付 2.0 綁卡（讀 guides/02 §綁卡付款流程）[預計 1h]
├── 實體門市刷卡 → POS 刷卡機（讀 guides/17-hardware-services.md §POS 刷卡機串接指引）[預計 2h]
├── 直播電商收款 → 直播收款（讀 guides/17-hardware-services.md §直播收款指引）[預計 1h]
├── Shopify → 購物車模組（讀 guides/10-cart-plugins.md #Shopify，API 規格見 references/Payment/Shopify專用金流API技術文件.md）
├── Mobile App（iOS/Android）→ 站內付 2.0（讀 guides/02c-ecpg-app-production.md + guides/23 Mobile App 區段）
├── Apple Pay → 優先站內付 2.0（完整 iOS SDK 支援，讀 guides/02c §Apple Pay）；AIO 亦可（ChoosePayment=ApplePay，讀 guides/01）[預計 30m-1h]
├── TWQR 行動支付 → AIO（ChoosePayment=TWQR）（讀 guides/01 §TWQR 範例）[預計 30m]
├── 微信支付 → AIO（ChoosePayment=WeiXin）（讀 guides/01 §微信支付範例）[預計 30m]
├── 銀聯卡
│   ├── 站內付 2.0 → ChoosePaymentList="6"，UnionPayInfo（讀 guides/02）[預計 1h]
│   └── AIO 信用卡頁面 → ChoosePayment=Credit，UnionPay=1（讀 guides/01 §信用卡一次付清參數）[預計 30m]
├── 非 PHP 語言完整範例 → 讀 guides/23-multi-language-integration.md（Go/Java/C#/TS/Kotlin/Ruby E2E + Mobile App）
├── 查詢訂單狀態 → AIO: guides/01 QueryTradeInfo 區段 / 站內付: guides/02 查詢區段 / 幕後授權: guides/03 查詢區段
├── 下載對帳檔 → guides/01 對帳區段（注意 domain 為 vendor.ecpay.com.tw）
├── 平台商多商戶（PlatformID）→ 特約合作模式，需另簽平台商合約。參數已含在 guides/01、guides/02 參數表中，搜尋 PlatformID
└── 其他 → 先讀 guides/00-getting-started.md 瞭解全貌
```

#### 物流決策樹

```
需要出貨？
├── 國內
│   ├── 超商取貨 → 國內物流 CVS（讀 guides/06-logistics-domestic.md）
│   ├── 宅配 → 國內物流 HOME（讀 guides/06-logistics-domestic.md）
│   └── 消費者自選 → 全方位物流（讀 guides/07-logistics-allinone.md）
├── 海外 → 跨境物流（讀 guides/08-logistics-crossborder.md）
└── 查詢物流狀態 → 國內: guides/06 §查詢物流訂單 / 全方位: guides/07 §查詢物流訂單 / 跨境: guides/08 §查詢
```

#### 電子發票決策樹

```
需要開發票？
├── 賣給消費者 → B2C（讀 guides/04-invoice-b2c.md）
│   ├── 延遲開立（先收款、滿足條件後才開） → guides/04 §DelayIssue（DelayFlag=1 手動觸發 / 2 自動排程）
│   ├── 折讓（退部分金額） → guides/04 §Allowance（線上 AllowanceByCollegiate 帶 CheckMacValue MD5）
│   └── 作廢（全額作廢） → guides/04 §Invalid
├── 賣給企業 → B2B（讀 guides/05-invoice-b2b.md）
│   ├── 交換模式（買受方確認） → guides/05 §Confirm
│   └── 折讓 / 作廢 → guides/05 §Allowance / §Invalid
├── 無網路環境 → 離線發票（讀 guides/18-invoice-offline.md）
└── 發票退款操作 → 見下方「退款/作廢/取消決策樹」
```

#### 電子收據決策樹

```
需要開立收據（非發票）？→ 讀 guides/25-receipt.md
├── 一般收據（押金、定金、雜支）→ ReceiptType=1（一般帳號 2000132）
├── 公益收據（捐給社福團體）→ ReceiptType=2（需綠界業務開通；僅可 1 項商品）
├── 政治獻金收據 → ReceiptType=4（需綠界業務開通；政治獻金帳號 3002607）
│   ├── 匿名 DonorType=5 → 金額 ≤ 10,000
│   └── 現金 PaymentMethod=3 → 金額 ≤ 100,000
├── 修改收據 → guides/25 §UpdateIssue
├── 作廢收據 → guides/25 §Invalid
├── 主動發送通知郵件 → guides/25 §Notification（商家→綠界）
├── 查詢單筆 → guides/25 §GetReceipt（ReceiptNo 或 RelateNumber 擇一）
└── AES-GCM 加密（選用）→ guides/25 §AES-GCM 模式
```

#### 其他決策樹

```
ECTicket？→ 讀 guides/09-ecticket.md
   測試帳號：官方提供公開測試帳號（見 guides/09 §測試帳號）
   適用場景：演唱會、電影票、餐券、遊樂園等虛擬票證
購物車平台？→ 讀 guides/10-cart-plugins.md
收款+發票+出貨？→ 讀 guides/11-cross-service-scenarios.md
PHP SDK 範例/用法？→ 讀 guides/12-sdk-reference.md
HTTP 協議細節（端點/認證/回應格式）？→ 讀 guides/19-http-protocol-reference.md
Callback/Webhook 接收架構？→ 讀 guides/21-webhook-events-reference.md（格式速查 + 各服務回應規格）+ guides/22（佇列化處理）
   ├── 何時主動查詢 vs 等 Callback？→ 信用卡即時付款：可用 QueryTradeInfo 主動查詢（guides/01 或 02），但仍須實作 Callback 作為最終確認；ATM/超商：必須等 Callback（付款非同步）
   ├── Callback 重試機制 → 綠界最多重送 4 次，須實作冪等處理（guides/21 §失敗恢復策略）
   └── 本機開發收不到 Callback？→ guides/24（ngrok / Cloudflare Tunnel 設定）
```

#### 退款/作廢/取消決策樹

```
需要退款或取消？
├── 信用卡退款
│   ├── AIO 訂單 → guides/01 DoAction（Action=R 退款 / Action=N 取消授權）
│   └── 站內付訂單 → guides/02 DoAction 區段
├── 非信用卡（ATM/超商代碼/條碼）→ ⚠️ 不支援 API 退款，需透過綠界商家後台或聯繫客服
├── 訂閱（定期定額）取消/暫停 → guides/01 §定期定額 CreditCardPeriodAction
├── 發票作廢 → guides/04 Invalid 區段（B2C）/ guides/05 Invalid 區段（B2B）
├── 發票折讓 → guides/04 Allowance 區段（B2C）/ guides/05 Allowance 區段（B2B）
├── 物流退貨 → guides/06 逆物流區段
└── 跨服務退款（付款+發票+物流）→ guides/11 補償動作對照表
```

#### 除錯決策樹

```
遇到問題？
├── CheckMacValue 驗證失敗 → 讀 guides/13 + guides/15 排查流程
├── AES 解密結果亂碼/失敗 → 讀 guides/14 常見錯誤 + 測試向量
├── 站內付 GetToken RtnCode ≠ 1（無明確錯誤訊息）→ **ConsumerInfo 物件缺失或 Email/Phone 未填**（讀 guides/02 ⓪ ConsumerInfo 規則）
├── 3D Secure 驗證相關
│   ├── 站內付 ThreeDURL 處理 → guides/02 §ThreeDURL（2025/8 起幾乎必出現，未導向 3D 頁面會逾時失敗）
│   └── AIO 3D 驗證 → 透明處理，消費者在綠界頁面完成，開發者無需額外實作
├── 收到錯誤碼 → 讀 guides/20 錯誤碼反向索引
├── Callback/Webhook 收不到 → 讀 guides/21 失敗恢復策略
├── 本機開發無法接收 Callback（localhost / 非標準 port）→ 讀 guides/24 tunneling 工具設定
├── 上線後交易異常 → 讀 guides/16 上線後觀察清單
├── 測試串接 → guides/00 Quick Start + 上方測試帳號，上線前逐項讀 guides/16 checklist
└── 不確定該讀哪份文件 → 讀 guides/00 總覽
```

> **⚡ 效能提醒**：預估日交易量 >1,000 筆、有高併發需求、或遇到 API 被限速（HTTP 403 Forbidden）？→ 請先讀 [guides/22](./guides/22-performance-scaling.md)（Rate Limiting 門檻值 + Callback 佇列架構 + 批次 API 最佳實踐）。

#### 快查表（問題→指南 / 需求→指南）

| 問題或需求 | 直接讀 |
|-----------|--------|
| CheckMacValue 驗證失敗 | guides/13 + guides/15 §1 |
| AES 解密結果亂碼 | guides/14 §常見錯誤 |
| Callback 收不到 | guides/15 §2 + guides/21 §Callback 回應格式速查 |
| Callback 格式 / 回應規則速查 | **guides/26 §3** |
| 如何退款 | guides/01 §信用卡請款 / 退款 / 取消 / guides/02 §請款 / 退款 |
| 如何查訂單 | guides/01 §查詢訂單 / guides/02 §查詢 / guides/03 §查詢 |
| 如何對帳 | guides/01 §對帳（domain: vendor.ecpay.com.tw）|
| 如何開發票 | guides/04 (B2C) / guides/05 (B2B) |
| 處理 Callback / Webhook | guides/21（各服務 callback 回應格式彙總）|
| 測試帳號是什麼 | 見下方 §測試帳號 / guides/00 |
| 上線前檢查 / 切換正式環境 | guides/16 |
| 日交易 > 1,000 筆 / 高併發 / Rate Limiting | guides/22 §Rate Limiting + §Webhook 佇列架構 |
| 站內付 2.0 404 / Domain 打錯 | guides/02 端點表（ecpg vs ecpayment）+ guides/16 §URL 對照 |
| 站內付 GetTokenbyTrade RtnCode ≠ 1（無明確錯誤） | guides/02 §GetTokenbyTrade Data 必填欄位速查 — **ConsumerInfo 物件缺失或 Email/Phone 未填**（最常見根因）|
| AES-JSON 雙層錯誤檢查 | guides/20 §錯誤碼閱讀方式 + guides/04 §AES 請求格式 |
| 物流退貨 | guides/06 逆物流 / guides/07 逆物流 |
| 非 PHP 完整範例 | guides/23（⚠️ 使用 AI Section Index 行號跳轉） |
| PHP SDK 用法 / 不想手動加密 | guides/12（PHP 開發者可直接用 SDK，免手動實作 CheckMacValue / AES） |
| 非 PHP 語言常見 Bug | **guides/26 §4** |

### 步驟 2.5：確認 HTTP 協議規格（非 PHP 語言必讀）

翻譯 PHP 範例之前，**必須先讀 [guides/19](./guides/19-http-protocol-reference.md)**，確認目標 API 的：
協議模式（CMV-SHA256 / AES-JSON / AES-JSON+CMV / CMV-MD5）、端點 URL（測試/正式）、
回應格式（pipe-separated / URL-encoded / JSON / HTML / CSV）、認證細節（SHA256 / MD5 / AES）。

> ⚠️ PHP SDK 的 Service 類別已封裝所有 HTTP 細節。非 PHP 語言必須自行處理 HTTP 請求構造、
> Content-Type 設定、CheckMacValue/AES 計算、回應解析。切勿假設所有 API 使用相同的請求/回應格式。

### 步驟 3：程式碼生成

1. 讀 [guides/26](./guides/26-ai-guardrails.md)（強制，防護欄）
2. 讀 `guides/` 中對應指南，取得整合流程和架構邏輯
3. 讀 `scripts/SDK_PHP/example/` 中對應的 PHP 範例
4. **從 references/ 即時讀取對應 API 的最新規格**（流程見 guides/26 §9）：找到章節 URL → web_fetch → 取得最新參數表
5. **摘取 API 頁面中所有 ⚠ 注意事項**，在回覆或程式碼註解中主動告知開發者
6. **注意不同付款方式/服務之間的語意差異**：相同參數名在不同服務中可能有不同單位（如 `StoreExpireDate` 在超商代碼=分鐘、條碼=天）、不同最低金額（BNPL ≥ 3000）、不同回傳值（`PaymentType` 回傳 `Credit_CreditCard` ≠ 送出的 `Credit`）、不同 Content-Type（金流=form-urlencoded、發票=json）
7. **Timestamp 一律使用 Unix 秒數**（非毫秒）：JavaScript `Date.now()` 回傳毫秒，必須除以 1000 並取整
8. **首次串接某服務時**，另外 web_fetch 該服務的「介接注意事項」頁面（URL 表見 guides/26 §8）
9. **載入目標語言的程式規範**：非 PHP 時先讀 `guides/lang-standards/{語言}.md`（python / nodejs / typescript / java / csharp / go / kotlin / ruby / rust / swift / c / cpp），遵循其命名慣例、型別定義、錯誤處理、HTTP Client 設定、Callback Handler 模板
10. 翻譯 PHP 範例，保留所有參數名、端點 URL、加密邏輯。加密實作依服務類型：CMV 服務（AIO/國內物流）→ guides/13；AES 服務（站內付/幕後授權/發票/物流v2）→ guides/14；ECTicket → 兩者都需要。⚠️ **兩者 URL encode 函式邏輯不同，不可混用**
11. 標註原始範例路徑供開發者查閱

> 翻譯品質準則與 AI 常犯錯誤自檢清單 → [guides/26 §6–§7](./guides/26-ai-guardrails.md)

### 步驟 4：測試驗證

- 提供測試環境帳號（見下方快速參考）
- 引導使用模擬付款功能，提醒上線前切換帳號
- 使用 [test-vectors/checkmacvalue.json](./test-vectors/checkmacvalue.json) 驗證 CheckMacValue 實作正確性
- 使用 [test-vectors/aes-encryption.json](./test-vectors/aes-encryption.json) 驗證 AES 加密實作正確性

### 步驟 5：上線檢查

- 讀取 `guides/16-go-live-checklist.md` 逐項檢查

## 快速參考

### 環境 URL

| 服務 | 測試環境 | 正式環境 |
|------|---------|---------|
| 金流 AIO | payment-stage.ecpay.com.tw | payment.ecpay.com.tw |
| 站內付 2.0 Token / 建立交易（ecpg domain） | ecpg-stage.ecpay.com.tw | ecpg.ecpay.com.tw |
| ECPG 查詢 / 授權 / 請退款（ecpayment domain） | ecpayment-stage.ecpay.com.tw | ecpayment.ecpay.com.tw |
| 物流 | logistics-stage.ecpay.com.tw | logistics.ecpay.com.tw |
| 電子發票 | einvoice-stage.ecpay.com.tw | einvoice.ecpay.com.tw |
| ECTicket | ecticket-stage.ecpay.com.tw | ecticket.ecpay.com.tw |
| 直播收款 | ecpayment-stage.ecpay.com.tw | ecpayment.ecpay.com.tw |
| 特店後台 | vendor-stage.ecpay.com.tw | vendor.ecpay.com.tw |

### 測試帳號

> ⚠️ **安全警告**：以下為綠界**官方公開共用**測試帳號，所有開發者共用相同帳號。
> - **禁止用於正式環境**：正式環境務必使用專屬帳號
> - **禁止寫入版本控制**：正式環境的 HashKey/HashIV 必須以環境變數管理
> - 共用帳號的測試交易可能被其他開發者看到，不影響開發

| 用途 | MerchantID | HashKey | HashIV | 加密 |
|------|-----------|---------|--------|------|
| 金流 AIO | 3002607 | pwFHCqoQZGmho4w6 | EkRm7iFT261dpevs | SHA256 |
| ECPG 線上金流（站內付 2.0 / 幕後授權 / 幕後取號） | 3002607 | pwFHCqoQZGmho4w6 | EkRm7iFT261dpevs | AES |
| 國內物流 B2C | 2000132 | 5294y06JbISpM5x9 | v77hoKGq4kWxNNIS | MD5 |
| 國內物流 C2C | 2000933 | XBERn1YOvpM9nfZc | h1ONHk4P4yqbl5LK | MD5 |
| 全方位/跨境物流 | 2000132 | 5294y06JbISpM5x9 | v77hoKGq4kWxNNIS | AES |
| 電子發票 | 2000132 | ejCk326UnaZWKisg | q9jcZX8Ib9LM8wYk | AES |
| 離線電子發票 | 3085340 | HwiqPsywG1hLQNuN | YqITWD4TyKacYXpn | AES |
| 電子收據（一般/公益）| 2000132 | ejCk326UnaZWKisg | q9jcZX8Ib9LM8wYk | AES-CBC / AES-GCM |
| 電子收據（政治獻金）| 3002607 | pwFHCqoQZGmho4w6 | EkRm7iFT261dpevs | AES-CBC / AES-GCM |
| ECTicket（特店） | 3085676 | 7b53896b742849d3 | 37a0ad3c6ffa428b | AES + CMV |
| ECTicket（平台商） | 3085672 | b15bd8514fed472c | 9c8458263def47cd | AES + CMV |
| ECTicket（價金保管-使用後核銷） | 3362787 | c539115ea7674f20 | 86f625e60cb1473a | AES + CMV |
| ECTicket（價金保管-分期核銷） | 3361934 | 1069c84afab54f16 | 795c968d90c14971 | AES + CMV |
| 國內物流（備用，非 OTP 模式） | 2000214 | 5294y06JbISpM5x9 | v77hoKGq4kWxNNIS | MD5 |

> ⚠️ ECTicket的 HashKey/HashIV 與金流**不同**。三種ECTicket模式（純發行、價金保管-使用後核銷、價金保管-分期核銷）使用不同帳號，切勿混用。分期核銷不支援平台商。詳見 guides/09 §測試帳號。

> **常見錯誤：帳號混用** — 金流、物流、發票使用**不同的** MerchantID 和 HashKey/HashIV。
> 同時串接多個服務時，請確認每個 API 呼叫使用對應服務的帳號，混用會導致 CheckMacValue 驗證失敗。

> **物流備用帳號（非 OTP 模式）**：MerchantID `2000214`（HashKey/HashIV 同 `2000132`），適用於特定不需 OTP 驗證的物流測試情境。一般開發以 `2000132` 為主。

### 3D 驗證 SMS 碼：`1234`

### 測試信用卡號

| 卡別 | 卡號 | 用途 |
|------|------|------|
| VISA（國內） | 4311-9522-2222-2222 | 一般測試 |
| VISA（國內） | 4311-9511-1111-1111 | 一般測試 |
| VISA（國際） | 4000-2011-1111-1111 | 國際卡測試 |
| 美國運通（國內） | 3403-532780-80900 | AMEX 測試（限閘道商，即直接與銀行介接的大型特店） |
| 美國運通（國際） | 3712-222222-22222 | AMEX 國際測試（限閘道商） |
| 永豐 30 期 | 4938-1777-7777-7777 | 永豐信用卡分期測試 |

- 安全碼：任意三碼數字（如 222）；有效期限：任意大於當前月年的值
- 3D Secure 驗證碼：`1234`（測試環境固定，不需接收簡訊）
- 平台商模式（PlatformID）測試帳號：AIO 3002599、ECPG 3003008

### SDK 安裝

```bash
composer require ecpay/sdk
```

### 重要提醒

- TLS 1.2 必須
- 3D Secure 2.0：已於 2025/8 起強制實施
- ChoosePayment=ALL 可用 IgnorePayment 排除特定付款方式
- Postback URL 使用 FQDN 而非固定 IP

> 其餘關鍵規則（iframe 禁用、ReturnURL `1|OK` 格式、HashKey 禁前端、API 限速、port 限制等）
> 詳見 [guides/26 §5 AI 注意事項](./guides/26-ai-guardrails.md)。

### 已知限制

- 僅支援新台幣（TWD）交易
- 不支援分帳功能（Split Payment）——ECPay 目前無分帳 API，需自行在應用層處理拆帳邏輯
- references/ URL 索引需要網路連線才能即時讀取最新 API 規格
- AI 翻譯品質可能因模型與語言組合而異，生成的程式碼片段應經人工驗證

## 文件索引

> **大多數專案只需閱讀 2-3 份指南（共 30-60 分鐘）。** 共 30 份指南，使用上方決策樹找到你需要的，無需全部閱讀。

| # | 檔案 | 主題 | 預估閱讀 |
|---|------|------|:-------:|
| 00 | 00-getting-started.md | 從零開始：第一筆交易到上線 | 15m |
| 01 | 01-payment-aio.md | 全方位金流 AIO（20 個 PHP 範例） | 25m |
| 02 | 02-payment-ecpg.md | 站內付 2.0 hub（概述 + 付款流程 + 綁卡/查詢/對帳） | 20m |
| 02a | 02a-ecpg-quickstart.md | 站內付首次串接 + Python/Node.js 完整範例 | 25m |
| 02b | 02b-ecpg-atm-cvs-spa.md | ATM/CVS 快速路徑 + SPA 整合 | 10m |
| 02c | 02c-ecpg-app-production.md | App 整合 + Apple Pay + 正式環境 | 10m |
| 03 | 03-payment-backend.md | 幕後授權 + 幕後取號 | 20m |
| 04 | 04-invoice-b2c.md | B2C 電子發票（19 個 PHP 範例） | 25m |
| 05 | 05-invoice-b2b.md | B2B 電子發票（23 個 PHP 範例） | 25m |
| 06 | 06-logistics-domestic.md | 國內物流（24 個 PHP 範例） | 25m |
| 07 | 07-logistics-allinone.md | 全方位物流（16 個 PHP 範例） | 20m |
| 08 | 08-logistics-crossborder.md | 跨境物流（8 個 PHP 範例） | 15m |
| 09 | 09-ecticket.md | ECTicket | 15m |
| 10 | 10-cart-plugins.md | 購物車模組 | 10m |
| 11 | 11-cross-service-scenarios.md | 跨服務整合場景 | 20m |
| 12 | 12-sdk-reference.md | PHP SDK 完整參考 | 15m |
| 13 | 13-checkmacvalue.md | CheckMacValue 解說 + 12 語言實作 | 25m（非 PHP 必讀） |
| 14 | 14-aes-encryption.md | AES 加解密解說 + 12 語言實作 | 25m（非 PHP 必讀） |
| 15 | 15-troubleshooting.md | 除錯指南 + 錯誤碼 + 常見陷阱 | 15m |
| 16 | 16-go-live-checklist.md | 上線檢查清單 | 20m |
| 17 | 17-hardware-services.md | 硬體與專用服務（POS 刷卡機 + 直播收款） | 15m |
| 18 | 18-invoice-offline.md | 離線電子發票指引 | 15m |
| 19 | 19-http-protocol-reference.md | HTTP 協議參考（跨語言必讀） | 20m |
| 20 | 20-error-codes-reference.md | 全服務錯誤碼集中參考 | 10m |
| 21 | 21-webhook-events-reference.md | 統一 Callback/Webhook 參考 | 15m |
| 22 | 22-performance-scaling.md | 效能與擴展性指引 | 15m |
| 23 | 23-multi-language-integration.md | 多語言整合完整指南（Go/Java/C#/TS 完整 E2E；Kotlin/Ruby/Swift/Rust 差異指南；C/C++ 最小骨架；Mobile App） | 8-15m（用 Section Index） |
| 24 | 24-local-development.md | 本地開發環境（ngrok / Cloudflare Tunnel / localtunnel / RequestBin） | 10m |
| 25 | 25-receipt.md | 電子收據（一般/公益/政治獻金，AES-CBC + AES-GCM） | 20m |
| **26** | **26-ai-guardrails.md** | **AI 防護欄（生成程式碼前強制必讀）** | **15m** |

### 程式語言規範（guides/lang-standards/）

> 生成目標語言程式碼時，同時載入對應規範檔（每份 ~150-250 行，涵蓋命名慣例、型別定義、錯誤處理、HTTP 設定、Callback Handler、環境變數、單元測試）。
>
> 可用檔案：`python.md` · `nodejs.md` · `typescript.md` · `go.md` · `java.md` · `csharp.md` · `kotlin.md` · `ruby.md` · `rust.md` · `swift.md` · `c.md` · `cpp.md`

### 官方 API 文件索引（references/）

> 完整索引（20 檔案 × 443 個 URL × 對應 Guide 映射）見 [references/README.md](./references/README.md)。
> **即時查閱機制與流程 → [guides/26 §9](./guides/26-ai-guardrails.md)**（references/ 是即時規格入口，不是靜態文件）。

references/ 包含 6 大類 API 文件：Payment（8 檔, 174 URLs）、Invoice（4 檔, 119 URLs）、Logistics（3 檔, 76 URLs）、Ecticket（3 檔, 57 URLs）、Receipt（1 檔, 12 URLs）、Cart（1 檔, 5 URLs）。

### PHP 範例（scripts/SDK_PHP/example/）

> 共 134 個驗證過的 PHP 範例，涵蓋 Payment（44）、Invoice（42）、Logistics（48）。詳細目錄見 `scripts/SDK_PHP/example/`。
