# Sandbox、憑證取得、合作商店申請與台灣注意事項

> 來源：https://developers-pay.line.me/zh/sandbox 、/zh/online/prerequisites 、/zh/join-as-merchant 、/zh/faq 、/zh/api-change-log 、/zh/contact
> 申請表單欄位為 2026-11-18 以 agent-browser 實際打開「註冊sandbox帳號」抽屜確認（未送出）。截圖在 `.tmp/[金流] LINE Pay/截圖/`。
> 給人看的逐步申請說明：`.tmp/[金流] LINE Pay/如何申請測試環境.md`。

## 1. 有沒有公開的測試憑證？

**沒有。** 官方文件與網站都沒有公開共用的 sandbox Channel ID / Secret。每個開發者需自助申請 sandbox 帳號（免商家身分；「免審核」為推測，未實際送出驗證），或用已簽約合作商店的正式憑證直接打 sandbox。

## 2. 自助申請 sandbox 帳號

- 入口：https://developers-pay.line.me/zh/sandbox → 「註冊sandbox帳號」按鈕（開啟右側抽屜表單；舊網址 `https://pay.line.me/tw/developers/techsupport/sandbox/creation` 會 302 到這裡）。
- 表單欄位（全部必填）：

| 欄位 | 選項 | 台灣線上串接要選 |
|---|---|---|
| 對象國家 | `台灣（TW）`、`泰國（TH）` | 台灣（TW） |
| 對象API | `Online API`、`Offline API` | Online API |
| 對應貨幣 | 依國家而異；選 TW 時為 `新台幣（TWD）`、`韓元（KRW）` | 新台幣（TWD） |
| 電子郵件地址 | 接收 sandbox 帳號資訊的 email | 團隊共用信箱為佳 |

- **每個 email 只能建立一個 Online 或 Offline 的 sandbox 帳號**（要兩種就用兩個 email）。
- 送出後「帳號資訊」寄到該 email（官方 tooltip 原文）；內容預期為合作商店中心登入用的商店 ID / 密碼（推測，未實際送出驗證）。所需時間約 5–15 分鐘亦為推測。

## 3. 取得 Channel ID / Channel Secret

1. 登入合作商店中心 https://pay.line.me/portal/tw/auth/login （登入頁第一步為「請輸入商店ID」→「下一步」，之後依 email 收到的帳號資訊輸入密碼；頁面另有「以管理者 LINE 帳號登入」、「忘記商店ID」）。
2. 左側選單 **開發者工具 › 管理連結金鑰**（prerequisites 頁的舊稱為「管理付款連結 › 管理連結金鑰」）。
3. 按「查詢」→ 系統寄驗證碼到申請 / 註冊時的 email → 在「驗證電子郵件」視窗輸入驗證碼 → 確認。
4. 頁面顯示 **Channel ID** 與 **Channel Secret Key**。

FAQ 補充：

- 已是正式合作商店者**不需另申請 sandbox**，正式的 Channel ID / Secret 可直接打 sandbox host。
- 透過代理商註冊的帳號無法自行查看 Channel ID / Secret，要向代理商索取。
- 提供「LINE Pay 固定付款碼」的商家無法使用 LINE Pay API；要用 Online API 需另外申請合作商店（linepaymerchant@linecorp.com）。
- Sandbox 交易紀錄可在合作商店中心的 sandbox 選單查詢。

## 4. Sandbox 測試要點

| 項目 | 規則 |
|---|---|
| Host | `https://sandbox-api-pay.line.me`（付款頁 `sandbox-web-pay.line.me`） |
| 付款頁登入 | **必須用個人 LINE 帳號登入**（sandbox 也是），不會真的扣款。2026-11-19 實測：頁面標「LINE Pay Simulation」，選項只有「LINE Log in」（轉 `access.line.me` 輸入 email / 密碼）或手機 LINE 掃 QR，自動化測試無法免人工完成 |
| App 付款 | sandbox **不支援** `info.paymentUrl.app`，只能用 `info.paymentUrl.web` |
| 商品圖 | sandbox 的 `imageUrl` 若非 HTTPS 可能不顯示（正式環境不受影響） |
| EPI | sandbox 線上交易無法模擬 EPI，`paymentProvider` 固定 `"TSP"` |
| Preapproved | 正式環境台灣需事先申請；官方明載開發階段可先用 sandbox 測 |
| 分開請款（`capture=false`） | 正式環境台灣需事先申請。**2026-11-19 sandbox 實測：自助 sandbox 商店被拒 `` 2103 Parameter is not allowed. [capture:`false`] ``**（v3 / v4 皆同），無法在自助 sandbox 測 |
| Offline 我的條碼（非本 skill 範圍） | `https://sandbox-web-pay.line.me/web/sandbox/payment/oneTimeKey?countryCode=TW` 產生測試條碼；EPI 測試條碼見 api-change-log |

## 5. 正式環境（合作商店）申請

- 台灣：https://pay.line.me/merchant-apply/tw/contact-request?locale=zh_TW （可選「需要專人聯繫」或「我想自行完成線上申請」）。
- 「需要專人聯繫」表單欄位：商店類型（全新註冊 / 已經是合作商店）、推薦人代號、品牌名稱、聯絡人姓名、聯絡電話、電子郵件、營業型態（實體 / 網路）、官網網址、產業類型、備註。
- 需要公司 / 商業登記身分與審核（sandbox 不需要）。
- 合作商店中心使用指南：官方指向 `https://pay.line.me/file/guidebook`（2026-11-18 實測回 404，僅繁中版）。

## 6. 台灣（TWD）特定注意事項彙整

| 主題 | 規則 | 出處 |
|---|---|---|
| 貨幣 | `currency: "TWD"`；API 支援 `USD`/`TWD`/`THB`，商店不支援的幣別回 `1178` | API 欄位表 |
| 金額型別 | `number`；TWD 無小數，實務用整數 | 欄位表（整數為實務建議） |
| 自動請款 | 台灣**預設自動請款**，分開請款需事先申請 | implement-capture-separated-payment |
| 預先授權 | 台灣需事先申請 | implement-preapproved-payment |
| `creditCardAuth=true` | 檢查 regKey 時做信用卡最低金額驗證，需 LINE Pay 審核，TW only | check regKey / 指南 |
| `maskedCreditCardNumber` | 遮罩卡號，台灣限定、需另申請 | confirm 回應 |
| `merchantReference.affiliateCards[]` | 回傳顧客的電子發票手機條碼載具（`cardType = "MOBILE_CARRIER"`）或會員卡，台灣限定、需聯絡 LINE Pay 開通 | confirm 回應 |
| `options.extra.promotionRestriction` | 因法規不適用促銷 / 折抵的金額（`rewardLimit` / `useLimit`），TW only | request 欄位表 |
| `paymentProvider` TSP / EPI | 台灣電子支付機構管理條例；v4 才回傳 | api-change-log |
| `2042` | EPI 交易退款，商家退款準備金不足 | v4 結果碼 |
| iPASS | LINE Pay 付款方式之一（一卡通）；sandbox 無法測 iPASS Money 條碼（Offline） | glossary / FAQ |
| `options.display.locale` | 繁中用 `"zh_TW"`（預設 `"en"`） | request 欄位表 |

## 7. 聯絡窗口

- LINE Pay Taiwan 技術支援：`dl_tech_support_tw@linecorp.com`
- LINE Pay Global：`dl_tech_support_global@linecorp.com`
- 合作商店申請（固定付款碼轉 Online API）：`linepaymerchant@linecorp.com`
