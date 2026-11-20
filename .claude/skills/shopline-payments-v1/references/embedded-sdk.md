# SHOPLINE Payments — 內嵌式前端 JS SDK（`@shoplinepayments/payment-web`）

> 來源：
> - https://docs.shoplinepayments.com/sdk/payment/ （使用說明）
> - https://docs.shoplinepayments.com/sdk/initData/ （初始化參數說明）
> - https://docs.shoplinepayments.com/sdk/example/CreditCard/ 、/ApplePay/ 、/ChaileaseBNPL/ 、/VirtualAccount/ 、/other/ 、/LargeTransaction/
> - https://docs.shoplinepayments.com/sdk/platform/ （平台特店）
> - https://docs.shoplinepayments.com/sdk/theme/ （UI 樣式自訂）
> - https://docs.shoplinepayments.com/appendix/compatibility/ 、/appendix/sdkErrorCode/
> - 爬取日期：2026-11-16

內嵌式 = **JS SDK（前端收銀台）+ Server-API `POST /api/v1/trade/payment/create`**。
SDK 只做「收集付款資訊 → 產生 `paySession`」與「拿 `nextAction` 執行付款（3DS / 跳轉 / QR）」，
建立交易一定是特店 **Server 端** 用 `apiKey` 呼叫。

## 目錄

1. [套件與載入](#1-套件與載入)
2. [準備容器](#2-準備容器)
3. [初始化 `ShoplinePayments(options)`](#3-初始化)
4. [初始化參數完整表](#4-初始化參數完整表)
5. [建立 paySession：`payment.createPayment()`](#5-建立-paysession)
6. [發起付款：`payment.pay(nextAction)`](#6-發起付款)
7. [其他實例方法：update / getCurrentCardInfo / destroy](#7-其他實例方法)
8. [各付款方式差異](#8-各付款方式差異)
9. [平台特店初始化差異](#9-平台特店初始化差異)
10. [UI 樣式自訂 theme](#10-ui-樣式自訂-theme)
11. [瀏覽器相容性](#11-瀏覽器相容性)
12. [SDK 錯誤碼](#12-sdk-錯誤碼)
13. [完整前後端串接範例（TypeScript）](#13-完整前後端串接範例)

---

## 1. 套件與載入

| 方式 | 寫法 |
|---|---|
| NPM | `npm install @shoplinepayments/payment-web`（官方亦列 Yarn / pnpm 頁籤） |
| ESM import | `import ShoplinePayments from '@shoplinepayments/payment-web'` |
| CDN | `<script src="https://cdn.shoplinepayments.com/sdk/v1/payment-web.js"></script>`，之後使用 `window.ShoplinePayments` |

> npm registry（2026-11-16 查詢，非官方文件內容）：`latest = 1.0.0`、`rc = 1.1.0`、`dev = 1.0.3-alpha.1`。官方文件未指定版本號。
> TypeScript 型別定義：官方文件未載明（下方範例型別為依文件欄位自行宣告）。

## 2. 準備容器

```html
<div id="paymentContainer"></div>
```

> 官方提醒（一般付款串接 guide）：**結帳頁一定要給 SDK 留框架位置**，即使某些付款方式通常不需顧客輸入資訊。
> 大額交易時 SDK 可能要求顧客輸入身分證資訊（見 §8.6）；若沒收集到，交易會無法建立。

## 3. 初始化

初始化時 SDK 依 `merchantId`、`paymentMethod` 在 SLP 端校驗特店收款狀態、判斷當前瀏覽器是否支援該付款方式，然後在容器渲染收銀台；**渲染完成才算初始化完成**，之後才能呼叫其他 API（否則 SDK 錯誤 `1029`）。

```typescript
const { payment, error } = await ShoplinePayments({
  clientKey: '***YOUR**CLIENT**KEY***',
  merchantId: '***YOUR**MERCHANTID***',
  paymentMethod: 'CreditCard',   // 指定付款方式（一次一種）
  currency: 'TWD',               // 目前僅支援 TWD
  amount: 10000,                 // 真實付款金額，台幣 *100（1 元傳 100）
  element: '#paymentContainer',  // 已建立好的容器 selector
  env: 'sandbox',                // 預設 production；沙盒傳 'sandbox'
})

if (error) {
  const { message, code } = error   // 參照 SDK 錯誤碼；官方建議不要直接把錯誤訊息顯示給顧客
  return
}
```

- 回傳 `{ payment, error }`。初始化出錯時，收銀台位置會呈現錯誤介面並回傳錯誤。
- **一個 SDK 實例只對應一個 `paymentMethod`**。要讓顧客切換付款方式，需由特店自行做付款方式選單，切換時 `destroy()` 舊實例再以新的 `paymentMethod` 初始化（官方文件未直接給切換範例；此為依「單一 paymentMethod 參數」推得的做法）。

## 4. 初始化參數完整表

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `clientKey` | String | 必填 | 通訊憑證（後台「設定 → 開發者管理」取得） |
| `merchantId` | String | 必填 | SLP 特店 ID（平台模式填子特店 ID） |
| `platformId` | String | 選填 | SLP 平台 ID，**平台特店必填** |
| `paymentMethod` | String | 必填 | 付款方式，列舉見下 |
| `currency` | String | 必填 | 目前僅支援 `TWD` |
| `amount` | Number | 必填 | 付款金額（台幣 ×100）。initData 頁註記「後期不再更改」，但可用 `payment.update({ amount })` 更新顯示金額（§7.1） |
| `element` | String | 必填 | 呈現節點；字串須能以 `document.querySelector()` 取得**唯一** DOM |
| `language` | String | 選填 | 顯示語言，預設為瀏覽器語言。列舉見下 |
| `countryCode` | String | 選填 | 國家碼，目前僅支援 `TW` |
| `env` | String | 選填 | 使用環境，預設 `production`。列舉見下 |
| `customerToken` | String | 選填 | 付款會員授權 token，用於記住顧客（快捷付款載入已綁卡列表）。由 Server `POST /api/v1/customer/token` 取得 |
| `installmentCounts` | String[] \| Number[] | 選填 | 分期數，如 `"3","6","9"` 或 `3,6,9`（SDK 內部轉字串）。僅 `CreditCard` / `ChaileaseBNPL` 生效 |
| `theme` | Object | 選填 | 主題配置，見 §10 |
| `paymentInstrument` | PaymentInstrument | 選填 | 付款工具參數 |
| `paymentInstrument.bindCard` | object | 選填 | 綁卡場景參數 |
| `paymentInstrument.bindCard.enable` | boolean | 選填 | 是否啟用綁卡功能，預設 `false` |
| `paymentInstrument.bindCard.protocol` | object | 選填 | 綁卡協議，`enable: true` 時生效 |
| `...protocol.switchVisible` | boolean | 選填 | 是否顯示綁卡勾選框。`true` 顧客可自選；`false` 顧客無法取消綁卡。預設 `false` |
| `...protocol.defaultSwitchStatus` | boolean | 選填 | 勾選框預設是否勾選，僅 `switchVisible: true` 有效。預設 `true` |
| `...protocol.mustAccept` | boolean | 選填 | 顧客是否必須勾選才能提交，僅 `switchVisible: true` 有效。預設 `false` |
| `...protocol.textType` | object | 選填 | 綁卡協議文本設定 |
| `...textType.paymentAgreement` | boolean | 選填 | 顯示「信用卡交易協議」文本，預設 `true`，只在新增綁定卡號時展示 |
| `...textType.subscribeAgreement` | boolean | 選填 | 顯示「定期付款信用卡代扣協議」文本，預設 `true` |

### 列舉值（官方「參考」彈出表）

`paymentMethod`：

| 值 | 說明 |
|---|---|
| `CreditCard` | 信用卡 / 信用卡分期 |
| `VirtualAccount` | ATM 銀行轉帳 |
| `ApplePay` | ApplePay |
| `JKOPay` | 街口支付 |
| `LinePay` | LINE Pay |
| `ChaileaseBNPL` | 中租 zingla 銀角零卡 |

`language`、`env` 列舉：見 [`api-reference.md` 列舉附錄](./api-reference.md#附錄列舉值)（SDK 頁與 API 頁共用同一組「參考」表）。

### bindCard 常見組合（guide/quick）

| 特店需求 | switchVisible | defaultSwitchStatus | mustAccept | 顧客行為 |
|---|---|---|---|---|
| 顧客可自選是否綁卡（預設勾選） | `true` | `true` | `false` | 顯示勾選框，預設勾，可取消 |
| 顧客可自選是否綁卡（預設不勾） | `true` | `false` | `false` | 顯示勾選框，預設不勾 |
| 強制綁卡，無法取消 | `false` | — | — | 不顯示勾選框，提交時自動綁卡 |
| 強制綁卡，且必須勾選才能提交 | `true` | `true` | `true` | 顯示勾選框，必須勾才能提交 |

## 5. 建立 paySession

顧客點擊結帳按鈕後**立刻**呼叫：

```typescript
const { paySession, error } = await payment.createPayment()
if (error) {
  const { message, code } = error   // 例：1112 顧客輸入錯誤；SDK 會在欄位下方顯示提示
  return
}
// 把 paySession 原封不動送到特店 Server，Server 帶進 trade/payment/create 的 paySession 欄位
```

- SDK 內部會校驗顧客輸入；只有輸入正確才回傳 `paySession`。
- **`paySession` 不可解析**，原樣傳給 Server。Server 建單時 `paySession` 為**必填**（型別 JSON String）。
- `createPayment()` **務必在顧客點擊事件內呼叫**；ApplePay 在點擊與 `createPayment()` 之間**不可有任何非同步操作**，否則報 `Must create a new ApplePaySession from a user gesture handler`（SDK 錯誤 `1106`）。

## 6. 發起付款

```typescript
// Server 建單回應中的 nextAction（原樣轉給前端）
const payResult = await payment.pay(nextAction)
// 成功時 payResult 為 undefined；失敗時才有 payResult.error
if (payResult && payResult.error) {
  const { message, code } = payResult.error
}
```

- `nextAction` 必填，來自 `POST /api/v1/trade/payment/create` 的回應。官方：「為明文資訊，注意**不要解析、使用裡面的結構**，資料類型隨時可發生變化」。
- 建單回應若**沒有** `nextAction`，代表建單失敗，不可呼叫 `pay()`。
- 呼叫 `pay()` 後請等待呼叫結束再處理。可能情況（官方原文整理）：
  1. 正常回應 → 頁面跳到建單時傳入的 `returnUrl`。
  2. 正常回應但 SLP 智慧風控要求 3D 驗證 → 跳到通路的 3D 驗證頁，完成後同樣跳 `returnUrl`。
  3. 錯誤回應 → 授權 / 請款時銀行端錯誤，參照 SDK 錯誤碼。
- 非信用卡付款方式（LINE Pay、街口、中租、ATM 等）`pay()` 會跳轉到對應付款方式頁面（掃碼、確認付款資訊等）。
- **3DS 頁面不要放在 iframe 開啟**；信用卡功能**必須在 HTTPS** 環境測試（否則 SDK 錯誤 `1105`）。
- 交易最終結果以 **Webhook（`trade.*`）或 `POST /api/v1/trade/payment/get`** 為準，不可只信前端 `pay()` / `returnUrl`。

## 7. 其他實例方法

### 7.1 `payment.update({ language?, amount? })`

更新 SDK 顯示語言或付款金額，**保留顧客已輸入資訊**。

```typescript
const updateResult = await payment.update({
  language: 'en',
  amount: 300000,   // 3000 台幣
})
// 成功時 updateResult 為 undefined；失敗時才有 error
if (updateResult && updateResult.error) {
  const { message, code } = updateResult.error
}
```

- 金額更新後觸發大額交易驗證時，SDK 會補渲染身分資訊輸入框並保留原輸入。
- 金額超出該付款方式可支援範圍時，SDK 顯示暫不支援樣式，**不保留**先前輸入。

### 7.2 `payment.getCurrentCardInfo()`

取得目前卡片資訊，**僅 `paymentMethod: 'CreditCard'`（含分期）可用**，其他付款方式會拋 `4206`。

```typescript
try {
  const cardInfo = await payment.getCurrentCardInfo()
  if (cardInfo) {
    const { bin, brand, issuerBank, cardType, cardCategory, issuerCountry, commercialIndicator } = cardInfo
  }
} catch (error) {
  const { message, code } = error as { message: string; code: string }
}
```

| 欄位 | 型別 | 說明 |
|---|---|---|
| `bin` | string | 卡 bin（前六位，部分情況回前八位） |
| `brand` | string | 卡片品牌 |
| `issuerBank` | string | 發卡銀行 |
| `cardType` | string | `CHARGE CARD` / `CREDIT` / `DEBIT` |
| `cardCategory` | string | 卡片等級 |
| `issuerCountry` | string | 發卡國家 |
| `commercialIndicator` | string（選填） | `PERSONAL` / `COMMERCIAL`；**僅即時輸入卡號時回傳**，已綁定卡片查詢時不回傳 |

資料來源：即時輸入（輸入滿 8 碼才查，不足回 `null`）或已綁定卡片（選卡後才有，未選回 `null`）。

### 7.3 `payment.destroy()`

銷毀實例與 SDK 建立的所有元素。**容器本身不會被移除**，需自行處理。

## 8. 各付款方式差異

所有付款方式流程一致：初始化 → `createPayment()` → Server 建單 → `pay(nextAction)`；差異只在初始化的 `paymentMethod` 與以下注意事項。

### 8.1 CreditCard
- 必須 HTTPS；3DS 頁勿放 iframe。
- 舊瀏覽器（TLS1.2 支援不完善，如 Safari 6–8、IE 11）無法使用信用卡，初始化回 `error.code === '1026'`，應提示升級瀏覽器。
- 綁卡 / 快捷 / 定期扣款僅內嵌式支援，見 `card-binding-recurring.md`。

### 8.2 ApplePay
1. 託管網域驗證檔：`https://[YOUR_DOMAIN_NAME]/.well-known/apple-developer-merchantid-domain-association`（從官方頁下載；**沙盒與正式環境檔案不通用**；**去掉 `.txt` 副檔名**）。下載連結在官方頁面上，檔案內容官方文件未以文字載明。
2. 後台「設定 → 網域設定 → 管理網域」新增網域（第 1 步未完成則驗證失敗）。
3. 點擊與 `createPayment()` 間**不可有非同步操作**。
4. 發起付款的網址須與後台「網域設定」的商店網域相同才會成功。
- 相容性：官方寫「僅支援 iOS 12.1 / macOS 10.14.1（含）以上的 Safari」；但支援付款方式頁另載「自 2025 年 5 月起，Apple Pay 補充支援在非 Apple 裝置上使用」（顯示 QR Code 供 iOS 18 以上 iPhone 掃描）。不支援時初始化回 `error.code === '1100'`，應引導改用其他付款方式。
- 不支援中國大陸 ApplePay。
- 測試：macOS 10.14.1+ 或 iOS 12.1+、Safari、非中國大陸 Apple ID 且綁一張非銀聯卡、HTTPS 且網域與後台設定相同。沙盒 Apple ID 見 `sandbox-resources.md`。

### 8.3 ChaileaseBNPL / 信用卡分期（`installmentCounts`）
- 僅 `CreditCard` 或 `ChaileaseBNPL` 時生效。
- `"0"` 代表不分期；只傳 `['0']` 或 `[]` 都代表不分期（不顯示下拉框，顯示跳轉提示文字）。
- 傳非 `"0"` 期數時，SDK 依特店實際開通期數過濾。
- 只剩一個有效期數 → 不顯示下拉框，預設該期數。
- 多個有效期數 → 顯示下拉框，由小到大，預設選第一個。
- 範例：傳 `['0','3','6','9']`、特店支援 `['3','9','12']` → 下拉框可選 3、9，預設 3。
- 中租支援一次付清與 3/6/12/18/24/30/36 期（overview 頁）。信用卡分期支援 3/6/9/12/18/24 期（各期數的發卡銀行見 `sandbox-resources.md` §支援的卡組織與分期銀行）。

### 8.4 VirtualAccount（ATM 銀行轉帳）
- 前端寫法同其他方式。虛擬帳號有效期限：Server 建單 `expireTime`（分鐘，部分付款方式支援）。帳號繳款後即失效、不可重複使用。

### 8.5 LinePay / JKOPay（other）
- 寫法相同，只換 `paymentMethod`。

### 8.6 大額交易驗證
- 依「第三方支付服務業防制洗錢及打擊資恐辦法」，大額交易需蒐集身分證資料（身分證號碼、發證日期、發證地點、領補換類別）。SDK 會自動渲染輸入欄位。
- 觸發門檻金額：官方文件未載明。顧客體驗截圖（信用卡 / 其他付款方式頁籤）為圖片，無文字規格。
- 相關錯誤：SDK `43010` 身分資訊核驗異常、`43011` 超出核驗次數（當日錯 3 次）。

## 9. 平台特店初始化差異

對比直連特店，**只有初始化不同**：`merchantId` 傳子特店 ID（透過 Connect 授權取得），並加 `platformId`。

```typescript
const { payment, error } = await ShoplinePayments({
  clientKey: '***YOUR**CLIENT**KEY***',
  merchantId: '***YOUR**SUB**MERCHANTID***',
  platformId: '***YOUR**PLATFORMID***',
  paymentMethod: 'CreditCard',
  currency: 'TWD',
  amount: 10000,
  element: '#paymentContainer',
  env: 'sandbox',
})
```

## 10. UI 樣式自訂 theme

目前**只有 `CreditCard`** 支援自訂主題。`theme` 結構：

| 鍵 | 說明 |
|---|---|
| `default` | 全域配置 |
| `CreditCard` | 信用卡配置；可用所有 default 中字體與輸入框配置，優先序高於 default；專用：`footerFontColor`、`footerIconColor`、`footerLinkColor` |
| `installment` | 分期配置；可用 default 中字體、下拉框、下拉選項配置；專用：`quesIconColor`、`infoIconColor`、`headerFontColor` |
| `simple` | 其他跳轉模式；可用 default 中字體配置 |

`default` 可用鍵（皆 string 選填，除 `fontFamily: string[]`、`inputLabelGutter: number`）：

- 字體：`fontLib`（目前僅 `googleFont`）、`fontFamily`、`fontColor`
- 下拉框：`selectHeight`、`selectBorderRadius`、`selectBorderColor`、`selectFontColor`、`selectPlaceholderFontColor`、`selectBackground`、`selectIconColor`、`selectErrorBorderColor`、`selectErrorFontColor`、`selectPaddingTop`、`selectPaddingRight`、`selectPaddingBottom`、`selectPaddingLeft`、`selectDropdownMaxHeight`、`selectDividingLineColor`
- 下拉選項：`optionsFontColor`、`optionsBackground`、`optionsDescriptionFontColor`、`optionsActiveBackground`、`optionsActiveFontColor`、`optionsDisabledBackground`、`optionsDisabledFontColor`
- 輸入框：`inputBackground`、`inputHeight`、`inputIconColor`、`inputBorderColor`、`inputBorderRadius`、`inputFocusBorderColor`、`inputErrorBorderColor`、`inputErrorFontColor`、`inputTipGutter`、`inputLabelColor`、`inputTipColor`、`inputFontColor`、`inputSkeletonBackground`、`inputPaddingTop`、`inputPaddingRight`、`inputPaddingBottom`、`inputPaddingLeft`、`inputLabelSmallFontSize`、`inputLabelGutter`（number，單位 px）

```typescript
const { payment, error } = await ShoplinePayments({
  /* ...必要參數 */
  paymentMethod: 'CreditCard',
  theme: {
    default: { fontColor: '#333333', inputBorderRadius: '8px', inputFocusBorderColor: '#1677ff' },
    CreditCard: { footerLinkColor: '#1677ff' },
  },
})
```

## 11. 瀏覽器相容性

| 裝置 | OS | 瀏覽器 | 相容性 |
|---|---|---|---|
| Mobile | iOS 12 以上 | Safari / Facebook / Instagram | 相容 |
| Mobile | iOS 12 以上 | Chrome for iOS | 90.x 以上相容 |
| Mobile | Android 9 以上 | Chrome | 90.x 以上相容 |
| Mobile | Android 9 以上 | Facebook / Instagram | 相容 |
| PC | Windows 7 以上 | Chrome | 90.x 以上相容 |
| PC | Windows 7 以上 | Firefox / Edge | 相容 |
| PC | Windows 7 以上 | IE | **不相容** |
| PC | Mac OS 10 以上 | Chrome / Safari | 相容 |

## 12. SDK 錯誤碼

| 碼 | 說明 | msg |
|---|---|---|
| `1026` | 不支援瀏覽器 | Browser version old |
| `1027` | SDK 內部錯誤 | Inner error |
| `1028` | 初始化呈現失敗（兜底） | Init error |
| `1029` | SDK 未完成呈現就呼叫其他 API | Render not finish |
| `1100` | 當前裝置環境不支援該付款方式 | Device not support |
| `1101` | 載入通路 JS 出錯 | Loadding payment method js error |
| `1102` | 介面回應入參出錯，配置出錯 | API request parameter error |
| `1105` | 當前環境非 HTTPS | Https needed |
| `1106` | 建立訂單時脫離使用者操作 | Payment method instance user action needed |
| `1107` | 其他建立實例未識別錯誤 | Paymen tmethod instance other error |
| `1108` | 初始化 SDK 逾時 | Over time |
| `1109` | 網路錯誤 | Network erorr |
| `1111` | 載入內部 JS 出錯 | Loadding inner js error |
| `1112` | 顧客輸入錯誤 | Incorrect user input |
| `4108` | 喚起通路付款表單失敗 | Failed to pull up the form |
| `4110` | 建立通道實例失敗 | Failed to create PaySession instance |
| `4111` | nextAction 入參出錯 | nextAction input parameter error |
| `4205` | 交易異常（交易狀態未知） | Transaction abnormal |
| `4206` | 該 API 不支援該付款方式 | The API does not support the payment method |
| `4207` | 呈現 dom 不存在 | The current transaction requires dom elements |
| `4208` | SDK 入參出錯 | Init data erorr |
| `4550` | 顧客取消付款表單 | Customer cancellation |
| `43000` | 卡組不支援 | The scheme of your credit card is not currently supported |
| `43001` | 發卡銀行不支援 | The issuing bank ... not currently supported |
| `43002` | 卡類型不支援 | The type ... not currently supported |
| `43003` | 發卡國家不支援 | The issuing country ... not currently supported |
| `43004` | 卡等級不支援 | The category ... not currently supported |
| `43005` | 卡片系列（卡 bin）不支援 | The series ... not currently supported |
| `43006` | 分期期數不支援 | The period of installments is not supported |
| `43007` | 暫不支援該付款方式（特店 ID 不支援） | This payment method is not currently supported (current merchant ID is not supported) |
| `43008` | 暫不支援此金額 | This amount of transactions is not currently supported |
| `43009` | 付款方式維護中 | The payment method is under maintenance |
| `43010` | 身分資訊核驗異常 | Please enter a valid Number |
| `43011` | 超出身分資訊核驗次數 | The identity information has been wrong 3 times today, please try again tomorrow |

> 錯誤 `code` 型別：官方範例以字串比較（`error.code === '1026'`），建議一律用字串處理。

## 13. 完整前後端串接範例

### 前端（瀏覽器，TypeScript）

```typescript
import ShoplinePayments from '@shoplinepayments/payment-web'

type SlpError = { code: string; message: string }

async function mountCheckout(amountCents: number) {
  const { payment, error } = await ShoplinePayments({
    clientKey: import.meta.env.VITE_SLP_CLIENT_KEY,   // clientKey 可放前端；apiKey 絕對不行
    merchantId: import.meta.env.VITE_SLP_MERCHANT_ID,
    paymentMethod: 'CreditCard',
    currency: 'TWD',
    amount: amountCents,
    element: '#paymentContainer',
    env: import.meta.env.PROD ? 'production' : 'sandbox',
  })
  if (error) { showFriendlyError(error as SlpError); return }

  document.getElementById('submit')!.addEventListener('click', async () => {
    // ApplePay：這裡到 createPayment() 之間不可 await 其他東西
    const { paySession, error: e1 } = await payment.createPayment()
    if (e1) return                                   // SDK 已在欄位顯示錯誤

    const res = await fetch('/api/checkout/slp/create-payment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ orderId: currentOrderId, paySession }),
    })
    const { nextAction } = (await res.json()) as { nextAction?: unknown }
    if (!nextAction) { showFriendlyError({ code: 'CREATE_FAILED', message: '' }); return }

    const payResult = await payment.pay(nextAction)  // 成功：跳 returnUrl 或 3DS 頁
    if (payResult && payResult.error) showFriendlyError(payResult.error as SlpError)
  })
}
declare const currentOrderId: string
declare function showFriendlyError(e: SlpError): void
```

### 後端（Node.js 18+，TypeScript）

```typescript
import { randomUUID } from 'node:crypto'

const BASE = process.env.SLP_API_BASE!   // https://api-sandbox.shoplinepayments.com 或 https://api.shoplinepayments.com

export async function createSlpPayment(input: {
  orderId: string; amountCents: number; paySession: string; customerIp: string; userAgent?: string
}) {
  const res = await fetch(`${BASE}/api/v1/trade/payment/create`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      merchantId: process.env.SLP_MERCHANT_ID!,
      apiKey: process.env.SLP_API_KEY!,
      requestId: randomUUID().replace(/-/g, ''),   // String(32)
    },
    body: JSON.stringify({
      acquirerType: 'SDK',                    // 固定值
      referenceOrderId: input.orderId,        // ≤ 32
      amount: { value: input.amountCents, currency: 'TWD' },
      language: 'zh-TW',
      returnUrl: `https://shop.example.com/checkout/result?order=${input.orderId}`,
      paySession: input.paySession,           // 前端 createPayment() 的結果，原樣帶入
      confirm: { paymentMethod: 'CreditCard', paymentBehavior: 'Regular' },
      order: {
        products: [{ id: 'SKU-1', name: '商品', quantity: 1, amount: { value: input.amountCents, currency: 'TWD' } }],
        shipping: {
          shippingMethod: '宅配', carrier: '黑貓宅配',
          personalInfo: { lastName: '王', firstName: '小明', phone: '+886912345678' },
          address: { countryCode: 'TW', city: '台北市', street: '信義路五段7號' },
        },
      },
      billing: {
        personalInfo: { lastName: '王', firstName: '小明', email: 'buyer@example.com' },
        address: { countryCode: 'TW', street: '信義路五段7號' },
      },
      customer: {
        referenceCustomerId: 'CUST_123456',
        personalInfo: { lastName: '王', firstName: '小明', email: 'buyer@example.com' },
      },
      client: { ip: input.customerIp, userAgent: input.userAgent },
    }),
  })
  const data = await res.json()
  if (!res.ok) throw new Error(`SLP ${data.code}: ${data.msg}`)
  // 保存 data.tradeOrderId（退款 / 請款 / 查詢都靠它），把 data.nextAction 原樣回前端
  return { tradeOrderId: data.tradeOrderId as string, status: data.status as string, nextAction: data.nextAction }
}
```

> `paymentBehavior: 'Regular'` 取自官方 webhook 範例電文（`"paymentBehavior": "Regular"`）與 `paymentBehavior` 列舉表；綁卡 / 快捷 / 定期請改用 `CardBind` / `CardBindPayment` / `QuickPayment` / `Recurring`（見 `card-binding-recurring.md`）。
