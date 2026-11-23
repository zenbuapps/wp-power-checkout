# LINE Pay Online API v4 — 完整端點參考

> 來源：https://developers-pay.line.me/zh/online-api-v4 及其 10 個子頁（2026-11-18 以 agent-browser 展開所有巢狀欄位後擷取）。
> 原始存檔：`.tmp/[金流] LINE Pay/官方文件/zh-TW/online-api-v4_*.md`（英文版在 `官方文件/en/`）。
> v3 與 v4 差異見 `v3-differences.md`；簽章與共通標頭見 `authentication.md`；結果碼全表見 `error-codes.md`。

**閱讀規則**

- Host：sandbox `https://sandbox-api-pay.line.me`、production `https://api-pay.line.me`。
- 所有回應 HTTP 一律 `200 OK`，成敗看 body 的 `returnCode`（`"0000"` = 成功）。
- 欄位名以「完整路徑」表示（`options.payment.capture`、`packages[].products[].price`）；`[]` 代表陣列元素。
- **Required / Included 欄位是「相對於父物件」**：例如 `options.extra.promotionRestriction.rewardLimit` 標 Y，意思是「有帶 `promotionRestriction` 時必填」。
- Response 表的 `Included` 欄：Y = 一定出現；N = 視情況出現。
- `transactionId`、`refundTransactionId`、`originalTransactionId` 都是 19 位整數，**超過 JS `Number.MAX_SAFE_INTEGER`**，解析 JSON 前要轉字串（見 `authentication.md` § BigInt）。
- 每個端點標有官方建議的 Read timeout（request 10s / confirm 40s / capture 60s / 其餘 20s）。
- 官方範例中的筆誤已原樣保留並在此標註：
  - 預先授權付款請求的 curl 範例 header 寫成 `X-LINE-Authorize-Nonce`，正確是 **`X-LINE-Authorization-Nonce`**。
  - 退款 curl 範例 `-d` 後漏了 `\` 續行符號。
  - 查詢付款明細範例的 `refundTransactionDate ` key 多一個尾隨空白、`"T011:00:00Z"`、最後多一個逗號——是官方範例瑕疵，不是 API 真實格式。
  - 付款請求回應範例有 `info.paymentUrl.universal`，但回應欄位表沒有列；**2026-11-19 sandbox 實測回應沒有此欄位**，不要依賴它。

**2026-11-19 sandbox 實測補充**（完整紀錄 `.tmp/[金流] LINE Pay/sandbox-實測紀錄-2026-11-19.md`）：付款請求 v4 / v3 皆 `0000`；未 confirm 的交易查明細回 `1150`；提前 confirm 回 `1169` 且使交易變 `0122`；`capture: false` 回未列出的 `2103`；金額加總不符回 `2101`（非 1124）；請求階段不擋重複 orderId（但 orderId 已 confirm 後再建請求回 `1172`）。confirm / 明細回應實際仍含 `packages[].userFeeAmount`（v4 文件已移除），sandbox confirm 也回 `maskedCreditCardNumber`；退款回應多 `info.paymentProvider`；0 元 PREAPPROVED 綁定的 confirm 回應沒有 `transactionId` 與 `paymentProvider`。
  - 檢查 regKey / 刪除 regKey 的回應範例 JSON 結尾多一個逗號（非合法 JSON），實際回應以 `{returnCode, returnMessage}` 為準。

## 目錄

1. [付款請求 `POST /v4/payments/request`](#1-付款請求)
2. [查詢付款請求狀態 `GET /v4/payments/requests/{transactionId}/check`](#2-查詢付款請求狀態)
3. [付款授權 `POST /v4/payments/{transactionId}/confirm`](#3-付款授權)
4. [請款 `POST /v4/payments/authorizations/{transactionId}/capture`](#4-請款)
5. [取消授權 `POST /v4/payments/authorizations/{transactionId}/void`](#5-取消授權)
6. [查詢付款明細 `GET /v4/payments`](#6-查詢付款明細)
7. [退款 `POST /v4/payments/{transactionId}/refund`](#7-退款)
8. [檢查預先授權付款密鑰狀態 `GET /v4/payments/preapprovedPay/{regKey}/check`](#8-檢查預先授權付款密鑰狀態)
9. [預先授權付款請求 `POST /v4/payments/preapprovedPay/{regKey}/payment`](#9-預先授權付款請求)
10. [刪除預先授權付款密鑰 `POST /v4/payments/preapprovedPay/{regKey}/expire`](#10-刪除預先授權付款密鑰)

---

## 1. 付款請求

使用輸入的付款訊息向LINE Pay伺服器[請求付款](https://developers-pay.line.me/zh/online/implement-basic-payment#request-payment)。設定付款訊息中，顧客需付款的訂單訊息和付款方式。如果付款請求處理順利，顧客將被引導至[LINE Pay認證](https://developers-pay.line.me/zh/online/implement-basic-payment#line-pay-user-auth)畫面。

另外，回應該API會發出一個交易ID，可用於請求[付款授權](https://developers-pay.line.me/zh/online-api-v4/confirm-payment)、[取消授權](https://developers-pay.line.me/zh/online-api-v4/void)和[請款](https://developers-pay.line.me/zh/online-api-v4/capture)、[退款](https://developers-pay.line.me/zh/online-api-v4/refund)。

**`POST /v4/payments/request`**

> 請將Read逾時設定為至少10秒以上。

**Path**

None

**Query**

None

**Body**

| Name | Type | Length | Description | Required |
|---|---|---|---|---|
| `amount` | number |  | 付款金額。這是整個套裝產品的購買金額(`packages[].amount`)合計金額。 | Y |
| `currency` | string | 3 | 付款貨幣代碼([ISO 4217](https://en.wikipedia.org/wiki/ISO_4217))。支援貨幣代碼如下。 • `"USD"` • `"TWD"` • `"THB"` | Y |
| `options` | object |  | 付款請求設定訊息 | N |
| `options.display` | object |  | 請求付款時的畫面訊息 | N |
| `options.display.checkConfirmUrlBrowser` | boolean |  | 授權付款請求後，設定在前往confirmURL (`redirectUrls.confirmUrl`)時是否傳回請求付款的原始瀏覽器畫面。預設值為`false`。 • `true`: 如果不是請求付款的瀏覽器窗口，則會顯示傳回最初請求付款的原始瀏覽器。 • `false`: 無需確認瀏覽器，導覽至confirmURL (`redirectUrls.confirmUrl`)。 | N |
| `options.display.locale` | string |  | 付款等待畫面的語言代碼（[BCP-47](https://en.wikipedia.org/wiki/IETF_language_tag)）。預設值為`"en"`。支援以下值。 • `"en"`: 英語 • `"ja"`: 日語 • `"ko"`: 韓語 • `"th"`: 泰語 • `"zh_TW"`: 繁體中文 • `"zh_CN"`: 簡體中文 | N |
| `options.events[]` | object array |  | 當訂單有符合與LINE Pay行銷合作的指定條件時帶入 | N |
| `options.events[].code` | string | 30 | 依照LINE Pay定義並提供之代碼，當商家訂單符合條件時帶入 | N |
| `options.events[].totalAmount` | number |  | 計算定率回饋的金額。 當有符合定率回饋活動條件時，於此欄位帶入符合回饋的金額。 例如：符合條件的金額會獲得3%回饋，此欄位帶入1000即可獲得30點回饋。如果在此欄位中指定有效值，則須在`options.events[].productQuantity`欄位中設定`null`。 實際回饋點數依然會受到其他條件影響。 | N |
| `options.events[].productQuantity` | number |  | 計算定額回饋次數的數量。 當有符合定額回饋活動條件時，於此欄位帶入符合對應次數的數量。 例如：每符合一個活動條件可獲得10點，此欄位帶入3即可獲得30點回饋。如果在此欄位中指定有效值，則須在`options.events[].totalAmount`欄位中設定`null`。 實際回饋點數依然會受到其他條件影響。 | N |
| `options.extra` | object |  | 合作商店附加訊息 | N |
| `options.extra.branchId` | string | 32 | 申請付款的合作商店ID。如果輸入的值大於最大長度，則僅顯示最大長度。 | N |
| `options.extra.branchName` | string | 200 | 請求付款的合作商店名稱。只能輸入英文字母、數字、特殊符號。 | N |
| `options.extra.promotionRestriction` | object |  | 促銷限制訊息。請輸入因法律、規則等原因不適用促銷的產品金額。TW only | N |
| `options.extra.promotionRestriction.rewardLimit` | number |  | 不適用於促銷的金額 | Y |
| `options.extra.promotionRestriction.useLimit` | number |  | 不適用於折抵的金額 | N |
| `options.payment` | object |  | 付款設定訊息 | N |
| `options.payment.capture` | boolean |  | 是否為自動請款。[付款授權](https://developers-pay.line.me/zh/online-api-v4/confirm-payment)時，可以決定是否同時處理[請款](https://developers-pay.line.me/zh/online-api-v4/capture)或[分開](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)。預設值為`true`。 • `true`: 同時處理。 • `false`: 分開處理。 | N |
| `options.payment.payType` | string |  | 付款類型 • `"NORMAL"`: 一般付款 • `"PREAPPROVED"`: 預先授權付款。詳細內容請參閱[實現預先授權付款](https://developers-pay.line.me/zh/online/implement-preapproved-payment)。 | N |
| `options.regPayRequest` | object |  | 預先授權付款設定 | N |
| `options.regPayRequest.perTransactionLimit` | number |  | 預先授權付款的單筆扣款金額限制。此欄位僅於預先授權付款類型(`options.regPayRequest.regPayPeriodType`)設定為`"NON_RECURRING"`時有效。 | N |
| `options.regPayRequest.productPrice` | number |  | 扣款金額 | N |
| `options.regPayRequest.recurringDay` | number |  | 定期定額扣款日。當定期定額間隔(`options.regPayRequest.recurringPeriod`)設定為每月(`"MONTH"`)或者每年(`"YEAR"`)時，用以表示會進行定期定額扣款的日期。此欄位只允許1到31之間的整數。 如果間隔不是設定為每月或者每年，則只需代入`null`。此外，請提供對應指定月份（`options.regPayRequest.recurringMonth`）的有效日期。例如，1至30是4月的有效日期。 | N |
| `options.regPayRequest.recurringDayOfWeek` | string |  | 當定期定額間隔(`options.regPayRequest.recurringPeriod`)設定為每週(`"WEEK"`)時，用以表示於一個星期中會進行定期定額扣款的日子，您可以帶入其一值： • `"SUN"` • `"MON"` • `"TUE"` • `"WED"` • `"THU"` • `"FRI"` • `"SAT"` 如果間隔不是設定為每週，則只需代入`null`。 | N |
| `options.regPayRequest.recurringMonth` | number |  | 當定期定額間隔(`options.regPayRequest.recurringPeriod`)設定為每年(`"YEAR"`)時，用以表示會進行定期定額扣款的月份。此欄位只允許1到12之間的整數。 如果間隔不是設定為每年，則只需代入`null`。 | N |
| `options.regPayRequest.recurringPeriod` | string |  | 定期定額間隔。當預先授權付款(`options.regPayRequest.regPayPeriodType`)設定為`"RECURRING"`時，此欄位用以表示付款間隔，您可以帶入其一值： • `"WEEK"`: 每週付款 • `"MONTH"`: 每月付款 • `"YEAR"`: 每年付款 | N |
| `options.regPayRequest.regPayPeriodType` | string |  | • `"RECURRING"`: 定期定額付款 • `"NON_RECURRING"`: 非定期定額付款(即一般的預先授權付款) | N |
| `orderId` | string | 100 | 訂單號碼。輸入商家生成的用於管理訂單的ID。 | Y |
| `packages[]` | object array |  | 以配送或商店區分的套裝產品訊息 | Y |
| `packages[].amount` | number |  | 套裝產品總購買金額。套裝產品中不同產品購買金額總和。不同產品的購買金額是產品價格(`packages[].products[].price`)乘以產品數量(`packages[].products[].quantity`)的值。 | Y |
| `packages[].id` | string | 50 | 套裝產品ID | Y |
| `packages[].name` | string | 100 | 套裝產品的​​名稱或配送的合作分店名稱 | N |
| `packages[].products[]` | object array |  | 屬於套裝產品的產品訊息 | Y |
| `packages[].products[].id` | string | 50 | 產品ID | N |
| `packages[].products[].imageUrl` | string | 500 | 銷售產品圖片URL | N |
| `packages[].products[].name` | string | 4000 | 產品名稱 | Y |
| `packages[].products[].originalPrice` | number |  | 產品原價 | N |
| `packages[].products[].price` | number |  | 產品價格 | Y |
| `packages[].products[].quantity` | number |  | 購買數量 | Y |
| `redirectUrls` | object |  | 用於後處理付款請求的URL資訊 | Y |
| `redirectUrls.appPackageName` | string | 4000 | 傳回的應用程式包​​名稱。在Android上切換應用程式時，請輸入要傳回的應用程式的準確套組名稱，避免網路釣魚等詐騙。 | N |
| `redirectUrls.cancelUrl` | string | 500 | 顧客在LINE應用程式的支付畫面取消付款時重新導向的URL | Y |
| `redirectUrls.confirmUrl` | string | 500 | 顧客完成LINE Pay認證後重新導向的URL | Y |
| `redirectUrls.confirmUrlType` | string |  | 顧客完成LINE Pay認證後呼叫confirmURL的方法。[執行無confirmURL訊息的付款](https://developers-pay.line.me/zh/online/implement-payment-without-confirmUrl)時可以使用此欄位。預設值為`"CLIENT"`。 • `"CLIENT"`: 在已完成LINE Pay認證的LINE應用程式或彈出頁面執行頁面重定向。 • `"NONE"`: 不提供用於重定向的URL訊息。 • `"SERVER"`: LINE Pay伺服器不會在已完成LINE Pay認證的LINE應用程式或彈出頁面中執行頁面重定向，而是另行呼叫重定向URL。 | N |

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

*〔一般付款請求〕*

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     -d '{
          "amount" : 100,
          "currency" : "TWD",
          "orderId" : "MKSI_S_20180904_1000001",
          "packages" : [
            {
              "id" : "1",
              "amount": 100,
              "products" : [
                {
                  "id" : "PEN-B-001",
                  "name" : "Pen Brown",
                  "imageUrl" : "https://pay-store.example.com/images/pen_brown.jpg",
                  "quantity" : 2,
                  "price" : 50
                }
              ]
            }
          ],
          "redirectUrls" : {
            "confirmUrl" : "https://pay-store.example.com/order/payment/authorize",
            "cancelUrl" : "https://pay-store.example.com/order/payment/cancel"
          }
        }'
     https://sandbox-api-pay.line.me/v4/payments/request
```

*〔分開請款時的主體〕*

```
{
  "amount": 100,
  "currency": "TWD",
  "orderId": "MKSI_S_20180904_1000001",
  "options": {
    "payment": {
      "capture": false
    }
  },
  "packages": [
    {
      "id": "1",
      "amount": 100,
      "products": [
        {
          "id": "PEN-B-001",
          "name": "Pen Brown",
          "imageUrl": "https://pay-store.example.com/images/pen_brown.jpg",
          "quantity": 2,
          "price": 50
        }
      ]
    }
  ],
  "redirectUrls": {
    "confirmUrl": "https://pay-store.example.com/order/payment/authorize",
    "cancelUrl": "https://pay-store.example.com/order/payment/cancel"
  }
}
```

*〔預先授權付款時的主體〕*

```
{
  "amount": 1000,
  "currency": "TWD",
  "orderId": "MKSI_P_20181231_1000001",
  "options": {
    "payment": {
      "payType": "PREAPPROVED"
    }
  },
  "packages": [
    {
      "id": "1",
      "amount": 0,
      "products": [
        {
          "id": "1",
          "name": "Prime MemberShip",
          "imageUrl": "https://pay-store.example.com/images/pen_brown.jpg",
          "quantity": 1,
          "price": 0
        }
      ]
    }
  ],
  "redirectUrls": {
    "confirmUrl": "https://pay-store.example.com/order/payment/authorize",
    "cancelUrl": "https://pay-store.example.com/order/payment/cancel"
  }
}
```

*〔未提供ConfirmURL時的主體〕*

```
{
  "amount": 1000,
  "currency": "TWD",
  "orderId": "MKSI_P_20181231_1000001",
  "redirectUrls": {
    "confirmUrlType": "NONE"
  },
  "packages": [
    {
      "id": "1",
      "amount": 0,
      "products": [
        {
          "id": "1",
          "name": "Prime MemberShip",
          "imageUrl": "https://pay-store.example.com/images/pen_brown.jpg",
          "quantity": 1,
          "price": 0
        }
      ]
    }
  ]
}
```

#### Response

| Name | Type | Description | Included |
|---|---|---|---|
| `info` | Object | 結果訊息 | N |
| `info.paymentAccessToken` | String | 在LINE Pay應用程式中輸入代碼，而不使用掃描器時使用的代碼值 | N |
| `info.paymentUrl` | Object | 付款畫面confirmURL訊息 | N |
| `info.paymentUrl.app` | String | 深層連結confirmURL。Android從合作商店應用程式轉到LINE Pay應用程式的URL。 | N |
| `info.paymentUrl.web` | String | 重定向網頁URL。進入LINE Pay付款等待畫面的URL。如果顯示為彈出窗口，請將窗口大小設為寬度700px、高度546px。 | N |
| `info.transactionId` | Number | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | N |
| `returnCode` | String | 結果程式碼。API呼叫成功時，傳回`0000`值。其他結果碼均為錯誤碼，更多訊息請參考[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1104`、`1105`、`1106`、`1124`、`1145`、`1172`、`1178`、`1183`、`1194`、`2101`、`2102`、`9000`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "Success.",
  "info": {
    "paymentUrl": {
      "web": "https://sandbox-web-pay.line.me/web/payment/wait?transactionReserveId=REpEWEttQ0F2RmFnaFFzVndIdjl6Z0lqbGpPemZjOHpNWTFZTmdibUlRNlEzOG50N2VSRmdGU2IxcnVjMHZ1NQ",
      "app": "line://pay/payment/REpEWEttQ0F2RmFnaFFzVndIdjl6Z0lqbGpPemZjOHpNWTFZTmdibUlRNlEzOG50N2VSRmdGU2IxcnVjMHZ1NQ",
      "universal": "https://line.me/R/pay/payment/REpEWEttQ0F2RmFnaFFzVndIdjl6Z0lqbGpPemZjOHpNWTFZTmdibUlRNlEzOG50N2VSRmdGU2IxcnVjMHZ1NQ"
    },
    "transactionId": 2023042201206549310,
    "paymentAccessToken": "056579816895"
  }
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/request-payment

---

## 2. 查詢付款請求狀態

查詢請求付款的狀態。主要在[執行無confirmURL訊息的付款](https://developers-pay.line.me/zh/online/implement-payment-without-confirmUrl)時，可以定期呼叫該API來檢查顧客是否完成LINE Pay認證。根據回應(`returnCode`)，可以確認LINE Pay是否已通過認證或成功與否。建議以1秒以上的間隔呼叫API。

**`GET /v4/payments/requests/{transactionId}/check`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `transactionId` | 付款交易ID。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |

**Query**

None

**Body**

None

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X GET \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     https://sandbox-api-pay.line.me/v4/payments/requests/2018110112345678910/check
```

#### Response

| Name | Type | Length | Description | Included |
|---|---|---|---|---|
| `returnCode` | String | 4 | 結果程式碼。當API呼叫成功時，會回傳以下其中一個結果程式碼。 • `0000`: 顧客完成LINE Pay認證之前的狀態。 • `0110`: 顧客已完成LINE Pay認證，可以進行付款授權。 • `0121`: 顧客取消付款或超過LINE Pay認證等待時間。 • `0122`: 付款失敗。 • `0123`: 付款完成。 其他結果碼均為錯誤碼，更多訊息請參考[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String |  | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1104`、`1105`、`9000`

**Response example**

*〔一般付款〕*

```
{
  "returnCode": "0000",
  "returnMessage": "success"
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/check-payment-request-status

---

## 3. 付款授權

向LINE Pay伺服器[請求付款授權](https://developers-pay.line.me/zh/online/implement-basic-payment#confirm)。顧客可以在執行[LINE Pay認證](https://developers-pay.line.me/zh/online/implement-basic-payment#line-pay-user-auth)後，呼叫此API進行付款。如果[無重定向URL訊息執行付款](https://developers-pay.line.me/zh/online/implement-payment-without-confirmUrl)，可以定期呼叫[付款請求狀態查詢API](https://developers-pay.line.me/zh/online-api-v4/check-payment-request-status)，檢查是否可以請求付款授權。

如果[分開付款授權和請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)，即使呼叫此API，也不會完成付款，後續必須[請款](https://developers-pay.line.me/zh/online-api-v4/capture)或[取消授權](https://developers-pay.line.me/zh/online-api-v4/void)。此外，[簽發預先授權付款密鑰](https://developers-pay.line.me/zh/online/implement-preapproved-payment#issue-key)請求付款，可以呼叫該API，收到預先授權付款密鑰作為回應。

**`POST /v4/payments/{transactionId}/confirm`**

> 請將Read逾時設定為至少40秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `transactionId` | 付款交易ID。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |

**Query**

None

**Body**

| Name | Type | Length | Description | Required |
|---|---|---|---|---|
| `amount` | number |  | 付款金額 | Y |
| `currency` | string | 3 | 付款貨幣代碼([ISO 4217](https://en.wikipedia.org/wiki/ISO_4217))。支援貨幣代碼如下。 • `"USD"` • `"TWD"` • `"THB"` | Y |

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     -d '{
          "amount": 1000,
          "currency": "TWD"
        }' \
      https://sandbox-api-pay.line.me/v4/payments/2018082512345678910/confirm
```

#### Response

| Name | Type | Description | Included |
|---|---|---|---|
| `info` | Object | 結果訊息 | N |
| `info.authorizationExpireDate` | String | 付款授權到期日([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601))。[分開付款授權和請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)時傳回此欄位。需在付款授權到期日前，完成[請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment#capture)。 | N |
| `info.merchantReference` | Object | 合作商店的合作資訊。若該交易用戶符合該合作商店載具或會員卡類型，則包含電子發票載具或會員卡的資訊。TW only 此欄位目前僅限於台灣使用。欲使用此欄位，請聯絡LINE Pay[負責人](https://developers-pay.line.me/zh/contact)。 | N |
| `info.merchantReference.affiliateCards[]` | Object array | 電子發票載具或會員卡的資訊 | Y |
| `info.merchantReference.affiliateCards[].cardId` | String | 電子發票載具或會員卡ID | Y |
| `info.merchantReference.affiliateCards[].cardType` | String | 載具或會員類型。若該欄位的值為`"MOBILE_CARRIER"`，電子發票載具資訊則將包含在`cardId`欄位中。其他類型的載具或會員卡由各合作商店自行定義。 | Y |
| `info.orderId` | String | 訂單號碼。由合作商店建立用於管理訂單的ID，同時為呼叫[付款請求API](https://developers-pay.line.me/zh/online-api-v4/request-payment)時輸入的值。 | N |
| `info.packages[]` | Object array | 購買套裝產品訊息 | Y |
| `info.packages[].amount` | Number | 套裝產品總購買金額 | Y |
| `info.packages[].id` | String | 套裝產品ID | Y |
| `info.payInfo[]` | Object | 付款訊息 | N |
| `info.payInfo[].amount` | Number | 付款金額 | Y |
| `info.payInfo[].creditCardBrand` | String | 用於付款的信用卡的品牌名稱。可能包括以下品牌名稱。 • `"VISA"` • `"MASTER"` • `"AMEX"` • `"DINERS"` • `"JCB"` | N |
| `info.payInfo[].creditCardNickname` | String | 用於付款的信用卡的暱稱。付款時，會傳送顧客在LINE Pay註冊的信用卡暱稱。如果未設定暱稱，則傳遞空字串。 | N |
| `info.payInfo[].maskedCreditCardNumber` | String | 隱藏部分號碼的信用卡號碼(例如，`"**** **** **** 1234"`)。僅向台灣地區提供此服務，可單獨申請使用。TW only | N |
| `info.payInfo[].method` | String | 付款方式。包含以下數值。 • `"BALANCE"`: LINE Pay餘額(LINE Pay Money) • `"CREDIT_CARD"`: 信用卡（包括簽帳卡） • `"POINT"`: LINE POINTS | N |
| `info.paymentProvider` | String | 分辨該交易是由哪間公司進行清算及撥款。 • `"TSP"`: 由TSP（Third-party Service Provider）清算的交易 • `"EPI"`: 由[EPI（Electronic Payment Institutions）](https://law.moj.gov.tw/ENG/LawClass/LawAll.aspx?pcode=G0380237)清算的交易 如果該值為`null`值，您可以視為是`TSP`的交易 | Y |
| `info.regKey` | String | 預先授權付款密鑰，詳細內容請參閱[實現預先授權付款](https://developers-pay.line.me/zh/online/implement-preapproved-payment)。 | N |
| `info.transactionId` | Number | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |
| `returnCode` | String | 結果程式碼。API呼叫成功時，傳回`0000`值。其他結果碼均為錯誤碼，更多訊息請參考[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1101`、`1102`、`1104`、`1105`、`1106`、`1110`、`1124`、`1141`、`1142`、`1150`、`1152`、`1153`、`1159`、`1169`、`1170`、`1172`、`1180`、`1198`、`1199`、`1280`、`1281`、`1282`、`1283`、`1284`、`1285`、`1286`、`1287`、`1288`、`1289`、`1290`、`1291`、`1292`、`1293`、`1294`、`1295`、`1296`、`1298`、`9000`

**Response example**

*〔付款授權 - 基本回應〕*

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "orderId": "MKSI_M_20180904_1000001",
    "transactionId": 2018082512345678910,
    "payInfo": [
      {
        "method": "CREDIT_CARD",
        "amount": 900
      },
      {
        "method": "POINT",
        "amount": 100
      }
    ],
    "paymentProvider": "TSP"
  }
}
```

*〔付款授權 - 分開請款時回應〕*

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "authorizationExpireDate": "2023-05-08T02:53:43Z",
    "transactionId": 2023050301302917310,
    "orderId": "ORD_2cDbr0nIwcvbQTVwJWMHKm3AC",
    "payInfo": [
      {
        "method": "CREDIT_CARD",
        "amount": 900
      },
      {
        "method": "POINT",
        "amount": 100
      }
    ],
    "paymentProvider": "TSP"
  }
}
```

*〔簽發預先授權付款密鑰時回應〕*

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "payInfo": [
      {
        "method": "CREDIT_CARD",
        "amount": 100
      }
    ],
    "regKey": "RK2AE3519XTFXHM",
    "transactionId": 2023050301300251010,
    "orderId": "ORD_dfrlW5t0g8vDyf5lgsuvJTFTH",
    "paymentProvider": "TSP"
  }
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/confirm-payment

---

## 4. 請款

執行請款。若執行[分開付款授權和請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)，[付款授權](https://developers-pay.line.me/zh/online/implement-basic-payment#confirm)後，合作商店呼叫此API執行請款，方能完成付款。

**`POST /v4/payments/authorizations/{transactionId}/capture`**

> 請款金額可能與呼叫請求付款API時輸入的付款金額不同。

> 請將Read逾時設定為至少60秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `transactionId` | 付款交易ID。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |

**Query**

None

**Body**

| Name | Type | Description | Required |
|---|---|---|---|
| `amount` | number | 付款金額 | Y |
| `currency` | string | 付款貨幣代碼([ISO 4217](https://en.wikipedia.org/wiki/ISO_4217))。支援貨幣代碼如下。 • `"USD"` • `"TWD"` • `"THB"` | Y |

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     -d '{
           "amount": 1000,
           "currency": "TWD"
         }' \
     https://sandbox-api-pay.line.me/v4/payments/authorizations/2018082512345678910/capture
```

#### Response

| Name | Type | Description | Included |
|---|---|---|---|
| `info` | Object | 結果訊息 | N |
| `info.orderId` | String | 訂單號碼。由合作商店建立用於管理訂單的ID，同時為呼叫[付款請求API](https://developers-pay.line.me/zh/online-api-v4/request-payment)時輸入的值。 | N |
| `info.payInfo[]` | Object | 付款訊息 | Y |
| `info.payInfo[].amount` | Number | 付款金額 | Y |
| `info.payInfo[].method` | String | 付款方式。包含以下數值。 • `"BALANCE"`: LINE Pay餘額(LINE Pay Money) • `"CREDIT_CARD"`: 信用卡（包括簽帳卡） • `"POINT"`: LINE POINTS | N |
| `info.paymentProvider` | String | 分辨該交易是由哪間公司進行清算及撥款。 • `"TSP"`: 由TSP（Third-party Service Provider）清算的交易 • `"EPI"`: 由[EPI（Electronic Payment Institutions）](https://law.moj.gov.tw/ENG/LawClass/LawAll.aspx?pcode=G0380237)清算的交易 如果該值為`null`值，您可以視為是`TSP`的交易 | Y |
| `info.transactionId` | Number | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |
| `returnCode` | String | 結果程式碼。API呼叫成功時，傳回`0000`值。其他結果碼為錯誤碼，如果回傳`1199`、`1280`至`1298`代碼，付款將自動取消。詳細內容請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1104`、`1105`、`1106`、`1150`、`1155`、`1170`、`1172`、`1179`、`1183`、`1184`、`1198`、`1199`、`1280`、`1281`、`1282`、`1283`、`1284`、`1285`、`1286`、`1287`、`1288`、`1289`、`1290`、`1291`、`1292`、`1293`、`1294`、`1295`、`1296`、`1298`、`9000`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "transactionId": 20140101123123123,
    "orderId": "order_210124213",
    "payInfo": [
      {
        "method": "CREDIT_CARD",
        "amount": 10
      },
      {
        "method": "POINT",
        "amount": 10
      }
    ],
    "paymentProvider": "TSP"
  }
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/capture

---

## 5. 取消授權

取消[付款授權](https://developers-pay.line.me/zh/online/implement-basic-payment#confirm)。[分開付款授權和請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)後，可以呼叫API，取消授權的付款。執行[請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment#capture)後，不能取消付款授權，應進行[退款](https://developers-pay.line.me/zh/online/handle-refund)。

**`POST /v4/payments/authorizations/{transactionId}/void`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `transactionId` | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |

**Query**

None

**Body**

None

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     https://sandbox-api-pay.line.me/v4/payments/authorizations/2018082512345678910/void
```

#### Response

| Name | Type | Length | Description | Included |
|---|---|---|---|---|
| `returnCode` | String | 4 | 結果程式碼。API呼叫成功時，傳回`0000`代碼。其他結果碼為錯誤碼，如果回傳`1900`、`1902`、`1999`代碼，可以重試呼叫此API。詳細內容請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String |  | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1101`、`1102`、`1104`、`1105`、`1106`、`1150`、`1155`、`1165`、`1170`、`1198`、`1199`、`1900`、`1902`、`1999`、`9000`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "OK"
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/void

---

## 6. 查詢付款明細

[已授權的付款](https://developers-pay.line.me/zh/online/implement-basic-payment#confirm)或[請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment#capture)的[付款明細檢索](https://developers-pay.line.me/zh/online/retrieve-payment-info)。可以使用參數查詢指定[交易ID (transaction ID)]或訂單號碼的付款明細，也可以只查詢交易訊息或訂單訊息。

**`GET /v4/payments`**

> 請將Read逾時設定為至少20秒以上。

**Path**

None

**Query**

| Name | Description | Required |
|---|---|---|
| `orderId[]` | 訂單號碼。可以輸入一個或多個訂單號碼作為查詢參數，並且必須輸入交易ID (`transactionId`)或此參數。 | N |
| `transactionId[]` | 一個或多個支付交易 ID。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | N |

**Body**

None

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X GET \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     https://sandbox-api-pay.line.me/v4/payments?transactionId=20140101123123123&orderId=1002045572
```

#### Response

| Name | Type | Description | Included |
|---|---|---|---|
| `info[]` | Object array | 結果訊息 | N |
| `info[].authorizationExpireDate` | String | 認證到期日([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601)) | N |
| `info[].currency` | String | 結算貨幣代碼([ISO 4217](https://en.wikipedia.org/wiki/ISO_4217)) • `"USD"` • `"TWD"` • `"THB"` | N |
| `info[].merchantName` | String | 合作商店名稱 | N |
| `info[].orderId` | String | 訂單號碼。由合作商店建立用於管理訂單的ID，同時為呼叫[付款請求API](https://developers-pay.line.me/zh/online-api-v4/request-payment)時輸入的值。 | N |
| `info[].originalTransactionId` | Number | 原始付款交易ID。有退款歷史記錄時，傳回此欄位。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | N |
| `info[].packages[]` | Object array | 以配送或商店區分的套裝產品訊息 | Y |
| `info[].packages[].amount` | Number | 套裝產品總購買金額。套裝產品中不同產品購買金額總和。不同產品的購買金額是產品價格(`info[].packages[].products[].price`)乘以產品數量(`info[].packages[].products[].quantity`)的值。 | Y |
| `info[].packages[].id` | String | 套裝產品ID | Y |
| `info[].packages[].name` | String | 套裝產品的​​名稱或配送的合作分店名稱 | N |
| `info[].packages[].products[]` | Object array | 屬於套裝產品的產品訊息 | Y |
| `info[].packages[].products[].id` | String | 產品ID | N |
| `info[].packages[].products[].imageUrl` | String | 銷售產品圖片URL | N |
| `info[].packages[].products[].name` | String | 產品名稱 | Y |
| `info[].packages[].products[].originalPrice` | Number | 產品原價 | N |
| `info[].packages[].products[].price` | Number | 產品價格 | Y |
| `info[].packages[].products[].quantity` | Number | 購買數量 | Y |
| `info[].payInfo[]` | Object | 付款明細。檢索具有退款歷史記錄的付款時包含此欄位。 | N |
| `info[].payInfo[].amount` | Number | 付款金額 | Y |
| `info[].payInfo[].method` | String | 付款方式。包含以下數值。 • `"BALANCE"`: LINE Pay餘額(LINE Pay Money) • `"CREDIT_CARD"`: 信用卡（包括簽帳卡） • `"POINT"`: LINE POINTS | N |
| `info[].paymentProvider` | String | 分辨該交易是由哪間公司進行清算及撥款。 • `"TSP"`: 由TSP（Third-party Service Provider）清算的交易 • `"EPI"`: 由[EPI（Electronic Payment Institutions）](https://law.moj.gov.tw/ENG/LawClass/LawAll.aspx?pcode=G0380237)清算的交易 如果該值為`null`值，您可以視為是`TSP`的交易 | Y |
| `info[].payStatus` | String | [付款狀態](https://developers-pay.line.me/zh/online#process) • `"AUTHORIZATION"`: 完成付款授權 • `"CAPTURE"`: 完成請款 • `"EXPIRED_AUTHORIZATION"`: 付款授權逾期（未在允許的時間內處理請款） • `"VOIDED_AUTHORIZATION"`: [取消付款授權](https://developers-pay.line.me/zh/online-api-v4/void) | Y |
| `info[].productName` | String | 產品名稱 | N |
| `info[].refundList[]` | Object array | 退款訊息。檢索具有退款歷史記錄的付款時包含此欄位。 | N |
| `info[].refundList[].refundAmount` | Number | 退款金額 | Y |
| `info[].refundList[].refundTransactionDate` | String | 退款時間([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601)) | Y |
| `info[].refundList[].refundTransactionId` | Number | 退款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |
| `info[].refundList[].transactionType` | String | 退款類型 • `"PARTIAL_REFUND"`: 部分退款 • `"PAYMENT_REFUND"`: 全額退款 | Y |
| `info[].transactionDate` | String | 交易時間([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601)) | N |
| `info[].transactionId` | Number | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | N |
| `info[].transactionType` | String | 交易分類 • `"PAYMENT"`: 付款交易 • `"PAYMENT_REFUND"`: 全額退款 • `"PARTIAL_REFUND"`: 部分退款 | N |
| `returnCode` | String | 結果程式碼。API呼叫成功時，傳回`0000`值。其他結果碼均為錯誤碼，更多訊息請參考[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1104`、`1105`、`1106`、`1150`、`1177`、`9000`

**Response example**

*〔查詢付款明細〕*

```
{
  "returnCode": "0000",
  "returnMessage": "success",
  "info": [
    {
      "transactionId": 2019060112345678910,
      "transactionDate": "2019-06-01T09:00:00Z",
      "transactionType": "PAYMENT",
      "payInfo": [
        { "method": "CREDIT_CARD", "amount": 100 },
        { "method": "POINT", "amount": 10 }
      ],
      "productName": "test production",
      "currency": "TWD",
      "paymentProvider": "TSP",
      "orderId": "20190601ORD45678910",
      "refundList": [
        {
          "refundTransactionId": 2019060112345678911,
          "transactionType": "PARTIAL_REFUND",
          "refundAmount": -1,
          "refundTransactionDate": "2019-06-06T09:00:00Z"
        },
        {
          "refundTransactionId": 2019060112345678911,
          "transactionType": "PARTIAL_REFUND",
          "refundAmount": -1,
          "refundTransactionDate ": "2019-06-06T10:00:00Z"
        },
        {
          "refundTransactionId": 2019060112345678911,
          "transactionType": "PARTIAL_REFUND",
          "refundAmount": -1,
          "refundTransactionDate ": "2019-06-06T011:00:00Z"
        }
      ],
      "packages": [
        {
          "id": "1",
          "amount": 85
        },
        {
          "id": "3",
          "amount": 5
        }
      ],
    }
  ]
}
```

*〔查詢退款訊息時〕*

```
{
  "returnCode": "0000",
  "returnMessage": "success",
  "info": [
    {
      "transactionId": 2019060112345678912,
      "transactionDate": "2019-06-01T09:48:43Z",
      "transactionType": "PARTIAL_REFUND",
      "payInfo": [
        {
          "method": "CREDIT_CARD",
          "amount": 85
        },
        {
          "method": "POINT",
          "amount": 8
        }
      ],
      "productName": "Brown",
      "currency": "TWD",
      "paymentProvider": "TSP",
      "orderId": "20190101123123123",
      "originalTransactionId": 2019060112345678910
    }
  ]
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/retrieve-payment-details

---

## 7. 退款

已完成結算的[退款](https://developers-pay.line.me/zh/online/handle-refund)。

**`POST /v4/payments/{transactionId}/refund`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `transactionId` | 付款交易ID。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |

**Query**

None

**Body**

| Name | Type | Description | Required |
|---|---|---|---|
| `refundAmount` | number | 退款金額。可以透過在此欄位中輸入任意金額來進行部分退款。若省略此欄位，將退還已付款的全部金額。 | N |

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID"\
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID"\
     -d '{ "refundAmount": 1000 }'
     https://sandbox-api-pay.line.me/v4/payments/2018082512345678910/refund
```

#### Response

| Name | Type | Length | Description | Included |
|---|---|---|---|---|
| `info` | Object |  | 結果訊息 | N |
| `info.refundTransactionDate` | String | 30 | 退款時間([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601)) | Y |
| `info.refundTransactionId` | Number |  | 退款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |
| `returnCode` | String | 4 | 結果程式碼。API呼叫成功時，傳回`0000`代碼。其他結果碼為錯誤碼，如果回傳`1900`、`1902`、`1999`代碼，可以重試呼叫此API。詳細內容請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String |  | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1101`、`1102`、`1104`、`1105`、`1106`、`1124`、`1150`、`1155`、`1163`、`1164`、`1165`、`1179`、`1198`、`1199`、`2042`、`9000`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "success",
  "info": {
    "refundTransactionId": 2018082512345678911,
    "refundTransactionDate": "2018-08-25T09:15:01Z"
  }
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/refund

---

## 8. 檢查預先授權付款密鑰狀態

檢查[已簽發預先授權付款密鑰](https://developers-pay.line.me/zh/online/implement-preapproved-payment#issue-key)的狀態。

**`GET /v4/payments/preapprovedPay/{regKey}/check`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `regKey` | 已註冊預先授權付款密鑰 | Y |

**Query**

| Name | Description | Required |
|---|---|---|
| `creditCardAuth` | 檢查預先授權付款密鑰狀態時，是否要透過執行最低付款來進行認證 • `true`: 執行LINE Pay驗證和信用卡最低付款認證，檢查預先授權付款密鑰的狀態。使用此功能需要經過LINE Pay負責人的審核。 • `false`: 只需驗證LINE Pay即可檢查預先授權付款密鑰的狀態。 | N |

**Body**

None

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X GET \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     https://sandbox-api-pay.line.me/v4/payments/preapprovedPay/RK123asd213/check
```

#### Response

| Name | Type | Description | Included |
|---|---|---|---|
| `returnCode` | String | 結果程式碼。API呼叫成功時，傳回`0000`值。其他結果碼均為錯誤碼，更多訊息請參考[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1101`、`1102`、`1104`、`1105`、`1106`、`1141`、`1154`、`1190`、`1193`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/check-preapproved-payment-key-status

---

## 9. 預先授權付款請求

使用[已簽發預先授權付款密鑰](https://developers-pay.line.me/zh/online/implement-preapproved-payment#issue-key)向LINE Pay伺服器請求預先授權付款。

**`POST /v4/payments/preapprovedPay/{regKey}/payment`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `regKey` | 已簽發的預先授權付款密鑰 | Y |

**Query**

None

**Body**

| Name | Type | Length | Description | Required |
|---|---|---|---|---|
| `amount` | number |  | 付款金額 | Y |
| `capture` | boolean |  | 是否自動請款。處理預先授權付款時，決定同時處理或[分開](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)處理。預設值為`true`。 • `true`: 同時處理。 • `false`: 分開處理。 | N |
| `currency` | string | 3 | 付款貨幣代碼([ISO 4217](https://en.wikipedia.org/wiki/ISO_4217))。支援貨幣代碼如下。 • `"USD"` • `"TWD"` • `"THB"` | N |
| `orderId` | string | 100 | 訂單號碼。輸入商家生成的用於管理訂單的ID。 | Y |
| `productName` | string | 4000 | 產品名稱 | N |

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorize-Nonce: 6120489b-53f3-4c51-9d6f-b80be93e509a"\
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     -d '{
          "productName": "Brown pen",
          "amount": 1000,
          "currency": "TWD",
          "orderId": "Ord2018123100000001"
        }' \
     https://sandbox-api-pay.line.me/v4/payments/preapprovedPay/RK123asd213/payment
```

#### Response

| Name | Type | Length | Description | Included |
|---|---|---|---|---|
| `info` | Object |  | 結果訊息 | N |
| `info.authorizationExpireDate` | String | 30 | 認證到期日([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601))。[分開付款授權和請款](https://developers-pay.line.me/zh/online/implement-capture-separated-payment)時包含此欄位。 | N |
| `info.paymentProvider` | String |  | 分辨該交易是由哪間公司進行清算及撥款。 • `"TSP"`: 由TSP（Third-party Service Provider）清算的交易 • `"EPI"`: 由[EPI（Electronic Payment Institutions）](https://law.moj.gov.tw/ENG/LawClass/LawAll.aspx?pcode=G0380237)清算的交易 如果該值為`null`值，您可以視為是`TSP`的交易 | Y |
| `info.orderId` | String | 100 | 訂單號碼。合作商店用於管理訂單時建立的ID，也是請求預先授權付款時輸入的值。 | N |
| `info.transactionDate` | String | 30 | 交易時間([ISO 8601](http://en.wikipedia.org/wiki/ISO_8601)) | N |
| `info.transactionId` | Number |  | 付款交易ID。根據平台的不同，交易ID可能需要被視為字串。詳細說明請參考[交易ID (transaction ID)](https://developers-pay.line.me/zh/online-api-v4#transaction-id)。 | Y |
| `returnCode` | String | 4 | 結果程式碼。API呼叫成功時，傳回`0000`代碼。其他結果碼為錯誤碼，如果回傳`1141`, `1282`-`1287`, `1290`-`1295`代碼，預先授權付款密鑰會被註銷。詳細內容請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String |  | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1101`、`1102`、`1104`、`1105`、`1106`、`1110`、`1124`、`1141`、`1142`、`1150`、`1152`、`1153`、`1159`、`1169`、`1170`、`1172`、`1180`、`1198`、`1199`、`1280`、`1281`、`1282`、`1283`、`1284`、`1285`、`1286`、`1287`、`1288`、`1289`、`1290`、`1291`、`1292`、`1293`、`1294`、`1295`、`1296`、`1298`、`9000`

**Response example**

*〔自動請款時回應〕*

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "transactionId": 2018123112345678910,
    "transactionDate": "2018-12-31T09:00:31Z",
    "paymentProvider": "TSP"
  }
}
```

*〔分開請款時回應〕*

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
  "info": {
    "transactionId": 2018123112345678910,
    "transactionDate": "2018-12-31T09:00:31Z",
    "authorizationExpireDate": "2019-01-31T09:00:31Z",
    "paymentProvider": "TSP"
  }
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/request-preapproved-payment

---

## 10. 刪除預先授權付款密鑰

[已簽發預先授權付款密鑰](https://developers-pay.line.me/zh/online/implement-preapproved-payment#issue-key)的逾期處理。

**`POST /v4/payments/preapprovedPay/{regKey}/expire`**

> 請將Read逾時設定為至少20秒以上。

**Path**

| Name | Description | Required |
|---|---|---|
| `regKey` | 已註冊預先授權付款密鑰 | Y |

**Query**

None

**Body**

None

**Headers**：共通請求標頭（見 `authentication.md`）

**Request examples**

```
curl -X POST \
     -H "Content-Type: application/json" \
     -H "X-LINE-ChannelId: YOUR_CHANNEL_ID" \
     -H "X-LINE-Authorization-Nonce: GENERATED_NONCE" \
     -H "X-LINE-Authorization: PROCESSED_SIGNATURE" \
     -H "X-LINE-MerchantDeviceProfileId: YOUR_DEVICE_PROFILE_ID" \
     https://sandbox-api-pay.line.me/v4/payments/preapprovedPay/RK123asd213/expire
```

#### Response

| Name | Type | Length | Description | Included |
|---|---|---|---|---|
| `returnCode` | String | 4 | 結果程式碼。API呼叫成功時，傳回`0000`代碼。其他結果碼為錯誤碼，如果回傳`1141`, `1282`-`1287`, `1290`-`1295`代碼，預先授權付款密鑰會被註銷。詳細內容請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |
| `returnMessage` | String |  | 結果訊息。結果訊息請參閱[結果程式碼](https://developers-pay.line.me/zh/online-api-v4#result-code)。 | Y |


**Related errors**：`1104`、`1105`、`1106`、`1190`、`1193`

**Response example**

```
{
  "returnCode": "0000",
  "returnMessage": "OK",
}
```


> 來源：https://developers-pay.line.me/zh/online-api-v4/discard-preapproved-payment-key
