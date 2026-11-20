# SHOPLINE Payments — 沙盒測試資源

> 來源：https://docs.shoplinepayments.com/overview/sandboxResource/ 、/sdk/example/ApplePay/ 、/overview/supportedPaymentMethod/**
> 最後同步：2026-11-16

## 端點

| 資源 | URL |
|---|---|
| 後台（登入） | `https://login.shoplinepayments.com/zh-Hant/signin/` |
| 開發者管理 FAQ | `https://faq.shoplinepayments.com/faq/developerManagement/`（FAQ 站，未爬取內容） |
| 沙盒 API | `https://api-sandbox.shoplinepayments.com` |
| 正式 API | `https://api.shoplinepayments.com` |
| SDK 環境 | 初始化參數 `env: 'sandbox'`（預設 `production`） |

登入後在「設定 → 開發者管理」取得 **API Key / Client Key / Sign Key**；每一個 Webhook URL 對應一把 Sign Key。
金鑰官方文件未提供，需登入各帳號自行取得（不同 Merchant ID 金鑰不同）。

## 沙盒登入帳號（密碼皆 `shoplinePayments123.`，**結尾的句點是密碼的一部分**；請勿修改）

| 用途 | Email | Merchant ID |
|---|---|---|
| 一般特店串接 | `slpsandbox2@shopline.com` | `2652289079513847808` |
| 一般特店串接 | `slpsandbox2+001@shopline.com` | `2875079337371111424` |
| 一般特店串接 | `slpsandbox2+003@shopline.com` | `3252259480759574528` |
| 一般特店串接 | `slpsandbox2+004@shopline.com` | `3252264968486264832` |
| 一般特店串接 | `slpsandbox2+005@shopline.com` | `3252269936723238912` |
| 平台特店（Connect 與交易測試） | `slpsandbox1@shopline.com` | `2652270930693330944`（**同時為 Platform ID**） |
| 平台的子特店（KYC 未審核通過） | `slpsandbox3@shopline.com` | `2652298043211257856` |
| 平台的子特店（KYC 審核通過） | `slpsandbox4@shopline.com` | `2652302529069717504` |
| 平台的子特店（KYC 審核通過） | `slpsandbox5@shopline.com` | `2652306537700268032` |

平台代子特店呼叫：Header `platformId` = 平台 ID、`merchantId` = 子特店 ID；SDK 初始化同理（`platformId` + 子特店 `merchantId`）。

## 信用卡測試卡號

| 卡組織 | 卡號 | 有效日期 | CVC |
|---|---|---|---|
| JCB | `3565586700000200` | `03/30` | `484` |
| Visa | `4147633700198405` | `03/30` | `638` |
| MasterCard | `5149147700000300` | `03/30` | `231` |

## 金額決定測試結果（沙盒專用）

以 **TWD 元**判斷（API `value` 為 ×100）：

| 規則 | 結果 |
|---|---|
| 金額為 **3 的倍數** | 固定進入 **3D 流程**；沙盒會跳到模擬頁，在頁面上點選要模擬的交易狀態 |
| 非 3 的倍數，且元金額為**單數** | 非 3D，**成功** |
| 非 3 的倍數，且元金額為**雙數** | 非 3D，**失敗** |

官方敘述：「去掉 TWD 最小單位的 00 後的金額，若金額為單數的，交易會成功；若為雙數的，交易會失敗。」即 API `value` 去掉末兩位 `00` 得到元金額，再判斷單雙數。

| 元金額 | API `value` | 結果 |
|---|---|---|
| 300 | `30000` | 3 的倍數 → 3D 流程 |
| 400 | `40000` | 非 3D，雙數 → 失敗 |
| 401 | `40100` | 非 3D，單數 → 成功 |

> 舊版 skill 範例表的 401 推導寫錯（寫成「strip 00 → 4」），已更正。

## ApplePay 測試

| 項目 | 值 |
|---|---|
| 沙盒 Apple ID | `slpsandbox2+001@shopline.com` |
| 密碼 | `Aa123456!`（請勿修改密碼、勿設定雙重驗證） |
| 裝置 | macOS 10.14.1+ 的 MacBook 或 iOS 12.1+ 的 iPhone，Safari |
| 測試卡 | 登入後自行在 Wallet 加入 Apple 官方測試卡：https://developer.apple.com/apple-pay/sandbox-testing/ |
| 網域 | 必須 HTTPS，且與後台「設定 → 網域設定」的商店網域相同；需託管對應**沙盒**環境的網域驗證檔 |

> 舊版 skill 寫 Apple ID 為 `slpsandbox2@shopline.com`，官方為 **`slpsandbox2+001@shopline.com`**，已更正。
> ApplePay 沙盒結果判定同上（非 3 的倍數：單數成功、雙數失敗）。

## 其他付款方式（LINE Pay / 街口 / ATM / 中租）

正式環境會跳轉到第三方通道頁面付款；沙盒統一跳到 SHOPLINE Payments 沙盒模擬頁，在頁面上點選要模擬的交易狀態。可模擬的狀態選項清單官方文件未以文字列出。

## 支援的卡組織與分期銀行（信用卡）

| 期數 | 卡組織 | 分期銀行 |
|---|---|---|
| 一般交易 | Visa、MasterCard、JCB、UnionPay | — |
| 3 期 / 6 期 / 12 期 | Visa、MasterCard、JCB | 台新、中國信託、玉山、凱基、國泰世華、聯邦、永豐、星展、滙豐 |
| 9 期 | Visa、MasterCard、JCB | 台新、中國信託、凱基、國泰世華、聯邦、星展、滙豐 |
| 18 期 / 24 期 | Visa、MasterCard、JCB | 台新、中國信託、玉山、凱基、國泰世華、聯邦、星展、滙豐 |

中租 zingla 銀角零卡：一次付清及 3/6/12/18/24/30/36 期。

## 本地 Webhook 測試

沙盒需能從外網打到 Webhook URL。可用任一 tunnel 工具（如 Cloudflare Tunnel、ngrok）把 localhost 暴露出去，再到後台設定 Webhook URL（官方文件未提供 tunnel 建議）。

## 專案 `.env` 對照（專案慣例，非官方）

| key | 值 |
|---|---|
| `SHOPLINE_SANDBOX_ADMIN_URL` | 後台 URL |
| `SHOPLINE_SANDBOX_ADMIN_ACCOUNT` | `slpsandbox2@shopline.com` |
| `SHOPLINE_SANDBOX_ADMIN_PASSWORD` | `shoplinePayments123.` |
| `SHOPLINE_SANDBOX_MERCHANT_ID` | `2652289079513847808` |
| `SHOPLINE_SANDBOX_API_BASE` | `https://api-sandbox.shoplinepayments.com` |

## 注意

1. 沙盒金額規則（3 的倍數、單雙數）**不可帶進正式環境邏輯**。
2. 子特店帳號只能透過平台 header 模式呼叫。
3. 信用卡與 ApplePay 必須 HTTPS 測試；ApplePay 沙盒 / 正式網域驗證檔不通用。
4. 官方「測試環境資訊」頁（/appendix/stg/）內容為空。
