# SHOPLINE Payments v1 — 錯誤碼

> 來源（2026-11-16 重新爬取）：https://docs.shoplinepayments.com/appendix/errorCode/ （Server 錯誤碼）、
> https://docs.shoplinepayments.com/appendix/sdkErrorCode/ （SDK 錯誤碼）
> 本檔逐條照抄官方表格（含官方原文拼字錯誤），未增刪。

Server API 錯誤：HTTP 400 / 429 / 500，body `{ "code": "...", "msg": "..." }`。
付款 / 退款失敗原因也會出現在 `paymentMsg` / `refundMsg` 的 `{ code, msg }`。

> 同一錯誤碼可能出現在多個場景（例如 `1004`、`1904` 同時出現在下單與 Onboarding、`4108`/`4550` 同時出現在 Server 與 SDK 表），請依呼叫的 API 判讀。

## 目錄

- 通用
- Connect（平台授權）
- 下單（建立交易）
- 付款
- 退款
- 綁卡
- 定期付款
- 取消授權（trade/payment/cancel）
- 請款（trade/payment/capture）
- SDK（Server 端回傳）
- 結算（平台）
- 轉帳交易（平台）
- 分帳交易（平台）
- Onboarding（平台）
- 前端 SDK 錯誤碼

## 通用

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1005` | 校驗錯誤 | Check error |
| `1006` | 紀錄已存在 | Record already exists |
| `1008` | 狀態錯誤 | Status error |
| `1016` | 付款查詢、退款發起請求時，沒有交易紀錄 | No transaction record. |
| `1018` | 付款失敗/取消/逾期 & 資訊載入失敗 & 未知原因 | Business error |
| `1901` | 系統連線失敗 | System connection failed |
| `1902` | 系統內部格式錯誤 | System internal format error |
| `1904` | 請求過於頻繁 | Requests are too frequent |
| `1997` | 資料庫錯誤 | Database error |
| `1998` | 系統配置錯誤 | System configuration error |
| `1999` | 系統異常 | Server error |
| `2001` | APPID不存在 | APPID does not exist |
| `2002` | 簽名錯誤 | Signature error |
| `2003` | 請求URL錯誤 | Request URL error |
| `2004` | 系統內部錯誤 | Internal System Error |
| `2005` | 訪問拒絕 | Access denied |
| `2006` | 錯誤請求 | Bad request |
| `2007` | 無法訪問 | Not found |
| `2008` | 服務無法存取 | Service cannot be accessed |
| `2009` | Token逾期 | Token expired |
| `2010` | Token被篡改 | Token tampered |
| `2011` | 付款工具狀態禁止修改 | InstrumentStatus forbidden change |
| `2013` | 特店未與平台 Connect | Merchants are not connected to the platform |

## Connect（平台授權）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `URL_NOT_FOUND` | URL不存在 | The url does not exist |
| `ACCESS_DENIED` | apiKey 或 clientKey 不正確/金鑰與商家ID或平台ID不符/金鑰錯誤/商家沒有存取資源權限 | Access Denied |
| `MERCHANT_NOT_EXISTS` | 特店ID不存在 | Merchant information does not exist |
| `UNAUTHORIZED_CLIENT` | 客戶端ID錯誤或授權參數錯誤 | The client is not authorized to access the requested resource |
| `SERVER_ERROR` | 其他系統錯誤 | System exception |
| `KEY_INCORRECT` | 平台 Authorize觸發 connect綁定的時 apiKey錯誤 | The apiKey or clientKey format is incorrect, please check the parameters |
| `INVALID_SCOPE` | 授權範圍錯誤 | The requested range parameter value is invalid or not supported by the authorized server |

## 下單（建立交易）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1001` | 重複下單，交易已存在 | Order exsit |
| `1003` | 下單過程中，參數缺失 | Param miss |
| `1004` | 下單請求過程中，因入參錯誤等系統原因導致的下單失敗 | Param error |
| `1025` | 下單請求過程中，超過最大付款限額或不足最低付款限額 | Create amount error |
| `4001` | 通路連線失敗 | Channel connection failed |
| `4002` | 通路錯誤 | Channel error |
| `4003` | 通路回應逾時 | Channel response timeout |
| `4101` | 付款超額，付款金額不在最低和最高限額之間 | Wrong amount |
| `4102` | 發起請求過程中，因為交易異常情況，下單失敗 | Payment declined due to order abnormalities. |
| `4103` | 因為列表以外的未知原因導致下單失敗 | The payment has been declined for unspecificed reasons. |
| `4104` | 因為特店帳戶狀態異常，導致交易下單被拒絕。 | Payment declined (issues with merchant account) |
| `4105` | 由於顧客帳戶狀態異常，導致交易失敗 | Payment declined (issues with customer account) |
| `4106` | IP位址在通路側未加入白名單 | The payment(s) failed due to system issues. |
| `4107` | 交易幣種通路不支援 | The payment has been declined for unspecificed reasons. |
| `4108` | 喚起付款表單失敗 | Failed to pull up the form |
| `4109` | 網站網域未設定 | Invalid store url |

## 付款

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `3000` | 命中 SLP 風控，使用者行為異常 | The payment was declined due to payer/card irregularities. |
| `3001` | 命中 SLP 風控，交易卡異常 | The payment was declined due to payer/card irregularities. |
| `3002` | 命中 SLP 風控，收貨人資訊異常 | The payment was declined due to payer/card irregularities. |
| `3003` | 命中 SLP 風控，高風險交易 | The payment was declined due to payer/card irregularities. |
| `3004` | 命中 SLP 風控，交易資訊異常 | The payment was declined due to payer/card irregularities. |
| `1012` | 特店 SLP 付款權限已停用 | Merchant payment permission is disabled |
| `4350` | 命中通路/銀行風控，交易被拒絕 | The payment was rejected by the issuing bank. |
| `4400` | 通路端交易過程中資料傳輸異常、系統異常 | The payment failed due to the transaction timed out. |
| `4401` | 通路端交易交易逾期 | The payment period has expired. |
| `4402` | ATM 虛擬帳號場景：客戶實際繳款金額與產生帳號的金額不一致 | The payment failed due to the actual amount [xxx幣種] paid by the payer is different from the set amount. |
| `4403` | 系統繁忙 | System Busy |
| `4404` | 付款完成後，token內與付款請求內金額錯誤 | Payment token amount not match |
| `4405` | 與錢包前端互動後，回應的token非法 | Payment token invailid |
| `4406` | 付款公鑰錯誤 | Payment public key invailid |
| `4407` | 付款私鑰錯誤 | Payment private key invailid |
| `4408` | 付款簽名錯誤 | Payment signature invailid |
| `4409` | 與通路前端互動後，回傳的token逾期 | Payment token expires |
| `4410` | 與通路互動後，通路提示重複付款 | Duplicate payment. |
| `4411` | 通路介面回傳鑑權失敗 | Payment failed due to technical issue |
| `4412` | 通路介面回應not found | Payment failed due to technical issue |
| `4450` | 銀行卡付款中，3DS驗證流程逾時 | The payer did not complete 3D Secure authentication. |
| `4451` | 發卡行回傳信用卡交易失敗 | The payment was rejected by the issuing bank. |
| `4452` | 銀行卡付款中，3DS交驗不透過 | The payer's attempt at 3D Secure authentication failed. |
| `4453` | 銀行卡CVV2驗證不透過 | The CVC number is incorrect. |
| `4454` | 交易卡餘額不足 | The card has insufficient funds to complete the purchase. |
| `4455` | 交易卡卡號驗證不透過（卡號無效） | The card number used is invalid. |
| `4456` | 通路側系統異常 | The payment failed due to system issues. |
| `4457` | 發卡行識別出為高風險交易 | The payment was declined by the issuing bank due to payer/card abnormalities. |
| `4458` | 交易依然pending在通路側，處理中 | The payment is still being processed. |
| `4459` | 發卡銀行識別出銀行卡已過有效期限 | The card used has expired. |
| `4460` | 發卡行回傳交易密碼輸入次數超額限額 | The payer exceeded the maximum number of PIN attempts. |
| `4461` | 發卡行回傳交易金額超過最大/最小限額 | The payer exceeded their available balance/credit limit. |
| `4462` | 發卡行回傳交易密碼檢定不透過 | The PIN entered is invalid. |
| `4463` | 發卡銀行識別出交易卡為被竊/遺失卡 | The payment was declined after being flagged by the issuing bank. |
| `4464` | 卡被凍結 | The selected card application is blocked. |
| `4465` | 輸入PIN | Card/E-Wallet requires online pin |
| `4466` | 金融卡卡受限 | Restricted Card or Invalid card in this country |
| `4467` | 已超交易次數 | The number of withdrawals permitted for the shopper's card was exceeded |
| `4468` | 付款碼或收款碼已逾期或無效 | Invalid QR Code, or QR Code has expired. |
| `4550` | 客戶自主取消交易 | Customer cancellation |
| `4551` | 客戶發起了拒付，交易正在爭議期 | Customer dispute |
| `4552` | 顧客付款帳號異常 | Customer account error |
| `4600` | Others-Exception | The payment has been declined for unspecificed reasons. |

## 退款

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1010` | 特店SLP退款權限已停用 | Merchant refund permission is disabled |
| `1013` | 退款要求已存在，不可重複發起退款 | The refund request is existing. |
| `1014` | 交易已完成全部金額退款，無可退款金額 | The refund failed due to exceeded the allowable refund amount. |
| `1015` | 特店帳戶未完成高級認證，不可發起退款 | Refund decline（issues with customer account） |
| `1020` | 付款交易可以退款的時效為180天，如超過，無法退款 | The allowable refund period has expired. |
| `1021` | 請求退款的交易不存在或狀態異常（失敗/逾時） | The refund failed due to the order does not exist or is in exception status. |
| `1202` | 通路不支援線上退款API，只能線下退款 | Refund is not support currently |
| `1022` | 由於特店帳戶餘額不足，退款失敗 | The refund failed due to the merchant acc does not have enough balance. |
| `1023` | 因為清單以外的未知原因導致退款失敗 | The refund request can not be processed at the moment. |
| `4502` | 請求退款的交易不存在或狀態異常（失敗/逾時） | The refund failed due to the order does not exist or is in exception status. |
| `4701` | 退款要求金額超過交易可退款金額 | Refund request amount exceeds order refundable amount |
| `4702` | 由於特店帳戶餘額不足，退款失敗 | The refund failed due to insufficient merchant account balance |
| `4703` | 付款交易已超過可退款時效 | The payment order has exceeded the refundable time limit |
| `4704` | 因為清單以外的未知原因導致退款失敗 | The refund failed because of unknown reasons. |
| `4705` | 傳輸金額不正確導致退款失敗 | Refund failed because of amount not valid |
| `4706` | 上次的退款交易尚在退款中，無法再新發起退款 | The new refund cannot be initiated because the previously refund of the payment is still processing |
| `4707` | 該交易不支援部分退款 | Partial refund doesn't support. |
| `4700` | 請求退款的交易不存在或狀態異常（失敗/逾時） | The refund failed because of unknown reasons. |

## 綁卡

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1200` | 暫時不支援綁卡功能 | Card binding is not support currently. |
| `1201` | 系統正在自動處理綁卡後流程，請5分鐘後再試 | Card is cloning |
| `4800` | 未知原因 | Binding failed due to unknown reason. |
| `4801` | 使用者拒絕授權 | The merchant rejected the binding. |
| `4802` | 通路參數錯誤 | Bind parameter error. |
| `1203` | 通路進行卡片驗證，通路回應失敗 | Card verification is failed |

## 定期付款

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `4900` | 通路要求該筆交易需要進行3DS，但由於是顧客不在場定期付款，無法完成 | Need 3DS |
| `4901` | 通路要求該筆交易需要進行CVS認證，但由於是顧客不在場定期付款，無法完成 | Need cvs |
| `4902` | 因其它原因，無法完成定期付款 | Saved card payment other erorr |

## 取消授權（trade/payment/cancel）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `6001` | 交易處理中，交易取消失敗 | Transaction is processing, can not cancel. |
| `6002` | 交易已請款，無法取消 | Can not Cancel the Captured transaction |
| `6003` | 交易已取消，無法取消 | Can not Cancel the Cancelled transaction |
| `6400` | 交易狀態異常，無法取消 | The transaction status is abnormal and cannot be cancelled |
| `6401` | 通路系統原因導致無法取消 | Can not Cancel for system error |

## 請款（trade/payment/capture）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `7001` | 請款金額大於可請款金額 | The capture amount is bigger than the amount that can be captured |
| `7002` | 交易狀態異常，無法請款 | The transaction status is abnormal and cannot be captured |
| `7400` | 交易狀態異常，無法請款 | The transaction status is abnormal and cannot be captured |
| `7401` | 通路系統原因導致無法請款 | Can not Capture for system error |
| `7402` | 請款金額大於可請款金額 | The capture amount is bigger than the amount that can be captured |
| `7403` | 因授權額度問題請款失敗 | Capture fail for bank decline |

## SDK（Server 端回傳）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1009` | 特店SLP收款權限已停用 | Receive forbidden |
| `4200` | 特店暫未綁定該付款通路 | Payment method not activated |
| `4201` | 特店暫無使用權限 | The payment failed due to merchant account invalid |
| `4202` | 通路或付款方式暫時不支援該交易幣種 | Currency not support |
| `4203` | 特店收款帳戶狀態異常 | Merchant payment permission is disabled |
| `4204` | 暫不支援該付款方式 | This payment method is not currently supported |

## 結算（平台）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `8100` | 無法對已經結算的交易執行結算 | Transaction already settled. |
| `8101` | 無法對已經預約結算的交易執行結算 | Transaction already scheduled for settlement. |

## 轉帳交易（平台）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1011` | 提現已停用 | Withdraw Forbidden |
| `9007` | 帳戶餘額不足 | Account balance not enough |

## 分帳交易（平台）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1002` | 可操作餘額不足 | Amount not enough |
| `1040` | 該付款方式的交易不支援分帳 | Transactions using this payment method cannot be split. |

## Onboarding（平台）

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1004` | 因入參錯誤等系統原因導致的失敗 | The request header is invalid |
| `1904` | 請求過於頻繁 | Requests too frequent |
| `2113` | 此主體已經存在 | entity not unique |
| `2116` | 此負責人或最終受益人已經存在 | person not unique |
| `2121` | 此銀行帳戶已提交，請勿重複提交 | The bank has been submitted, please do not repeat |
| `2131` | 此主體正在申請中 | entity applying |
| `URL_NOT_FOUND` | URL不存在 | The url does not exist |

## 前端 SDK 錯誤碼

`ShoplinePayments()`、`createPayment()`、`pay()`、`update()`、`getCurrentCardInfo()` 回傳的 `error.code`。

| 錯誤碼 | 錯誤說明 | 錯誤 msg |
|---|---|---|
| `1026` | 不支援瀏覽器 | Browser version old |
| `1027` | SDK 內部錯誤 | Inner error |
| `1029` | SDK 未完成呈現，呼叫了其他 API | Render not finish |
| `1101` | 載入通路 JS 出錯 | Loadding payment method js error |
| `1102` | 介面回應入參出錯，配置出錯 | API request parameter error |
| `1105` | 當前環境為非 HTTPS | Https needed |
| `1106` | 建立訂單時脫離使用者操作 | Payment method instance user action needed |
| `1107` | 其他建立實例未識別到的錯誤 | Paymen tmethod instance other error |
| `1108` | 初始化 SDK 逾時 | Over time |
| `1109` | 網路錯誤 | Network erorr |
| `1111` | 載入內部 JS 出錯 | Loadding inner js error |
| `4110` | 建立通道實例失敗 | Failed to create PaySession instance |
| `4111` | nextAction 入參出錯 | nextAction input parameter error |
| `4206` | 該 API 不支援該付款方式 | The API does not support the payment method |
| `4207` | 呈現 dom 不存在 | The current transaction requires dom elements |
| `4208` | SDK 入參出錯 | Init data erorr |
| `4205` | 交易異常（交易狀態未知） | Transaction abnormal |
| `1112` | 顧客輸入錯誤 | Incorrect user input |
| `4108` | 喚起通路付款表單失敗 | Failed to pull up the form |
| `1100` | 當前裝置環境不支援該付款方式 | Device not support |
| `1028` | 初始化呈現失敗（兜底錯誤碼） | Init error |
| `4550` | 顧客取消付款表單 | Customer cancellation |
| `43000` | 目前卡的卡組不支援 | The scheme of your credit card is not currently supported |
| `43001` | 目前卡的發卡銀行不支援 | The issuing bank of your credit card is not currently supported |
| `43002` | 當前卡的卡類型不支援 | The type of your credit card is not currently supported |
| `43003` | 目前卡片的發卡國家不支援 | The issuing country of your credit card is not currently supported |
| `43004` | 當前卡的卡等級不支援 | The category of your credit card is not currently supported |
| `43005` | 目前卡片的卡片系列不支援(即卡bin不支援) | The series of your credit card is not currently supported |
| `43006` | 目前分期期數不支援 | The period of installments is not supported |
| `43007` | 暫不支援該付款方式（目前特店ID不支援） | This payment method is not currently supported (current merchant ID is not supported) |
| `43008` | 暫不支援此金額進行交易 | This amount of transactions is not currently supported |
| `43009` | 目前付款方式正在維護中 | The payment method is under maintenance |
| `43010` | 身分資訊核驗異常 | Please enter a valid Number |
| `43011` | 超出身分資訊核驗次數 | The identity information has been wrong 3 times today, please try again tomorrow |

## 常見處置建議

| 情境 | 錯誤碼 | 建議 |
|---|---|---|
| 金鑰 / 商家 ID 不符 | `ACCESS_DENIED`、`2005` | 檢查 `merchantId` 與 `apiKey`（Server）或 `clientKey`（SDK）是否同一特店、同一環境 |
| 重複下單 | `1001`、`1006` | `referenceOrderId` / `referenceId` 須唯一；重試請換單號或用 `idempotentKey` |
| 參數錯誤 | `1003`、`1004`、`1005` | 檢查必填欄位（order.products / shipping / billing / customer / client 皆必填） |
| 金額超出限額 | `1025`、`4101`、SDK `43008` | 檢查付款方式金額上下限（官方未列具體限額） |
| 網站網域未設定 | `4109` | 後台「網域設定」新增網域 |
| 請求過於頻繁 | `1904`（HTTP 429） | 退避重試 |
| 退款超過 180 天 | `1020`、`4703` | 改線下處理 |
| 前一筆退款處理中 | `4706` | 等 `trade.refund.*` 通知後再發起 |
| 不支援部分退款 | `4707` | 改全額退款 |
| 已請款不可取消 | `6002` | 改走退款 |
| 定期扣款需 3DS | `4900` | 通知顧客回站以快捷付款（顧客在場）完成 |

