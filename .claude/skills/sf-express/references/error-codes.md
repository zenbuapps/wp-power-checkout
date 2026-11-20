# 錯誤碼 / 響應碼總表

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：API 回非 0 `apiResultCode`、解密後 `success=false`、或要把錯誤碼對應到欄位做前端提示時。

錯誤分四層，判斷順序：
1. **HTTP 層**：非 200 → 網路 / 閘道問題。
2. **平台層 `apiResultCode`**（外殼，明文）：0 成功；其餘見下方「附錄 13：響應碼」。token 失效 = `500 Access denied, token invalid` → 重新取 token 後重試一次。`504` 系統繁忙 → 退避重試。
3. **業務層**（解密後 `success` / `code` / `msg`）：IUOP 見「國際快遞常見異常響應」、IECS 見「出口電商常見異常響應」、其他接口的業務碼寫在各 API 頁（例：路由查詢 `tracking.md`、POD `resultCode` `common-apis.md`）。
4. **本地 SDK**：-40001…-40011，見 `auth-and-crypto.md` §0.7。

訊息語言由 header `lang` 決定（`zh-CN` 簡體 / `zh-HK` 繁體 / `en`）；本檔中文訊息已轉繁體，比對請用錯誤碼。

## 目錄

- [附錄 13：響應碼](#附錄-13響應碼)
- [1. 國際快遞常見異常響應](#1-國際快遞常見異常響應)
- [2. 出口電商常見異常響應](#2-出口電商常見異常響應)
- [補充：其他接口頁內的業務碼（彙整）](#補充其他接口頁內的業務碼彙整)

---

## 附錄 13：響應碼

> 來源：https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=72（文檔中心 > API文檔 > 附錄 > 13.響應碼；官方更新 2024-08-14；爬取 2026-09-23）

| 響應碼(apiResultCode) | 響應描述 |
| ------------------ | ---- |
| 0 | 處理成功 |
| 500 | 系統異常 |
| 500 | Access denied, token invalid |
| 502 | 請求參數錯誤 |
| 504 | 系統繁忙，請稍後再試 |
| 505 | 請求報文格式不對 |
| 700 | 不支持的轉換類型 |
| 701 | 業務接口無響應 |
| 702 | 缺少映射配置 |
| 703 | 缺少映射配置的類 |
| 1001 | App name 重複 |
| 1002 | 用戶不存在 |
| 1003 | 業務類型格式不對 |
| 1004 | 沒權限 |

---

## 1. 國際快遞常見異常響應

> 來源：https://openapi-portal.sf.global/question?secondMenu=169（常見問題 > 國際快遞常見異常響應；官方更新 2023-06-21；爬取 2026-09-23）

| 錯誤碼 | 報錯信息 | Error Message | 異常可能涉及字段以及解決方案 |
| --- | ---- | ------------- | -------------- |
| 2 | 權限不足 | Insufficient permission | customerCode |
| 100024 | 客戶編碼不存在或已失效 | Customer code does not exist or has expired | customerCode |
| 121048 | 貿易條件值錯誤 | The trade terms value is incorrect. | CustomsInfo.tradeCondition |
| 121049 | [XX]關務校驗失敗，XX | [XX]Customs verification failed,XX | 按照錯誤提示進行處理 |
| 121050 | 原寄地國家代碼長度超長 | The length of the origin country code is overlong. | senderInfo.country |
| 121051 | 目的地國家代碼長度超長 | The length of the destination country code is overlong. | receiverInfo.country |
| 121072 | 品名要素格式錯誤 | 品名要素格式錯誤 | 按照錯誤提示進行處理 |
| 121094 | 出口報關方式僅支持：XX | Export declaration mode only supports: XX | CustomsInfo.exportCustomsType |
| 121117 | 進口報關方式值無效 | Import declaration mode is Invalid | CustomsInfo.importCustomsType |
| 121118 | 出口報關方式值無效 | Export declaration mode is Invalid | CustomsInfo.exportCustomsType |
| 123002 | 訂單信息不存在 | The order information does not exist. | 按照錯誤提示進行處理 |
| 123004 | 快遞類型必填 | The shipment type is mandatory. | interProductCode |
| 123005 | 包裹數量必須在1\~XX之間的整數 | The number of packages must be an integer between 1 and XX. | parcelQuantity |
| 123006 | 幣種必填 | The currency is mandatory. | declaredCurrency |
| 123008 | 運單備註長度超長 | The length of the waybill remarks is overlong. | remark |
| 123010 | 貨幣代碼錯誤，例如：CNY | The currency code is incorrect, for example, CNY. | declaredCurrency |
| 123011 | 此月結帳號無效或被禁用，請與您的銷售人員聯繫。 | This account is invalid or disabled. Please contact your sales person. | paymentInfo.payMonthCard |
| 123014 | 稅金結算帳號未簽訂《DDP協議》,請聯繫順豐客服 | The tax settlement account has not signed the DDP Agreement, please contact SF CSR. | paymentInfo.taxPayMonthCard |
| 123015 | 稅金結算帳號不能為空 | The tax settlement account cannot be null. | paymentInfo.taxPayMonthCard |
| 123017 | 您好，收件地址為po box，暫時不提供派送服務，帶來不便敬請諒解。 | Dear customer, your receiver address is a PO box, so the delivery service is not provided at present. We are sorry for any inconvenience caused. | receiverInfo.address |
| 123019 | 預約時間必填 | The appointment time is mandatory. | pickupAppointTime<br>pickupType |
| 123020 | 目的地為日本，寄件詳細地址不能包含“公司”、“Co.Ltd”，會影響清關，請重新填寫後再提交。 | For a shipment bound for Japan, the detailed shipper address cannot include “company” or “Co., Ltd”, otherwise the customs clearance will be affected. Please fill in again and submit the information. | senderInfo.address |
| 123021 | 目的地為日本，收件詳細地址不能包含“公司”、“Co.Ltd”，會影響清關，請重新填寫後再提交。 | For a shipment bound for Japan, the detailed receiver address cannot include “company” or “Co., Ltd”, otherwise the customs clearance will be affected. Please fill in again and submit the information. | receiverInfo.address |
| 123022 | 寄件人姓名包含下劃線 “\_”，會影響清關，請修改後再提交。 | The shipper name contains “\_”, which will affect customs clearance. Please modify it and submit again. | senderInfo.contact |
| 123023 | 寄件公司包含下劃線 “\_”，會影響清關，請修改後再提交。 | The shipper company contains “\_”, which will affect customs clearance. Please modify it and submit again. | senderInfo.company |
| 123025 | 寄件人地址包含下劃線 “\_”，會影響清關，請修改後再提交。 | The shipper address contains “\_”, which will affect customs clearance. Please modify it and submit again. | senderInfo.address |
| 123026 | 收件人姓名包含下劃線 “\_”，會影響清關，請修改後再提交。 | The receiver name contains “\_”, which will affect customs clearance. Please modify it and submit again. | receiverInfo.contact |
| 123029 | 收件人地址包含下劃線 “\_”，會影響清關，請修改後再提交。 | The receiver address contains “\_”, which will affect customs clearance. Please modify it and submit again. | receiverInfo.address |
| 123031 | 到方城市必填 | The receiver city is mandatory. | receiverInfo.regionSecond |
| 123032 | 到方城市必填 | The receiver city is mandatory. | receiverInfo.regionSecond |
| 123033 | 該流向不支持到付，請選擇其他付款方式 | Receiver payment is not supported in this flow direction. Please select another payment mode. | paymentInfo.payMethod |
| 123036 | 托寄物內容包含下劃線 “\_”，會影響清關，請修改後再提交。 | The consignment content contains “\_”, which will affect customs clearance. Please modify it and submit again. | ParcelInfo.name |
| 123037 | 此條線路暫未開通服務 | The service is not available in this route. | interProductCode<br>senderInfo.country<br>receiverInfo.country<br>參考[順豐國際官網](https://www.sf-international.com/cn/sc/product/fast?isProduct=product)公佈流向產品或諮詢客戶經理 |
| 123038 | 稅金月結帳號不合法 | The tax settlement credit account number is illegal. | paymentInfo.taxPayMonthCard |
| 123039 | 代收貨款卡號不合法 | The COD card number is illegal. | AddServiceInfo.valueOne |
| 123040 | 寄件方式無效 | The shipping mode is invalid. | pickupType |
| 123045 | 預約時間或者時區無效 | The appointed time or time zone is invalid. | pickupAppointTime<br>pickupAppointTimeZone |
| 123052 | 客戶訂單號與歷史訂單數據重複 | The customer order number is identical with an order number in history order data. | 兩種可能情況：<br>1.訂單號之前已經使用過<br>2.訂單實際已經下單成功，響應報文客戶沒有收到，導致重複請求<br>建議：使用IUOP_CREATE_ORDER的改單功能重新請求，情況1會返回報錯，情況2會正常返回下單成功報文數據 |
| 123057 | 寄件類型（個人件或者公司件）必填 | The shipping type (individual shipment or company shipment) is mandatory. | senderInfo.cargoType |
| 123058 | 您使用的月結帳號暫未綁定，請聯繫客服處理 | The monthly settlement card number you are using has not been bound yet. Please contact customer service | paymentInfo.payMonthCard<br>paymentInfo.payMethod |
| 123060 | 下單失敗，請稍後重試 | Order placement failed. Please try again later. | 如果持續報錯，聯繫順豐國際技術支持 |
| 123061 | 預約時間不能早於當前時間 | The appointment time cannot be earlier than the current time. | pickupAppointTime<br>pickupType |
| 123076 | 只能預約最近XX個工作日的時間（XX） | Can only make an appointment for the last XX working days (XX) | pickupAppointTime<br>pickupType |
| 123077 | 該產品或寄件郵編不支持收派員上門取件 | The shipment type or shipping postcode does not support courier pick up | pickupAppointTime<br>pickupType |
| 123081 | 運單號不合法 | Waybill number is illegal | sfWaybillNo |
| 123101 | 運單號與歷史訂單數據重複 | The sf waybill number is identical with an order number in history order data. | sfWaybillNo |
| 123102 | 個人物品需要上傳證件 | Identity certificate to be uploaded for personal items | receiverInfo.certType<br>receiverInfo.certCardNo |
| 123112 | 訂單狀態為"XX"，不支持改單 | The order is "XX" and cannot be modified | orderOperateType |
| 123113 | 訂單鎖定中，暫不支持改單，請稍後再試 | The order is locked and cannot be modified for now, please try again later | orderOperateType |
| 123114 | 改單失敗，訂單信息不存在 | Order modification failed, the order information does not exist | 按照錯誤提示進行處理 |
| 123121 | 缺少清關資料，請通過【訂單列表】或【上傳列表】上傳 | Missing customs clearance materials, please upload via [Order list] or [Upload list] | IUOP\_UPLOAD\_CERTIFY |
| 123123 | 客戶[XX]無BBD下單權限 | The customer has no [XX] BBD order placement permission | 按照錯誤提示進行處理 |
| 123156 | 您沒有該產品的權限 | no permission for this product | 聯繫順豐國際客戶經理 |
| 123175 | 您未配置預分配訂單權限，不能帶單號下單 | You do not the pre-assigned order permission, you cannot place an order with waybill No. | sfWaybillNo |
| 123186 | 包裹數量與子單數量不匹配 | The number of packages does not match the number of sub-orders | ParcelInfo.quantity<br>ChildInfo |
| 123190 | 當前用戶無二次相信重量下單權限 | The current user does not have the twice trust weight permission to place an order | 按照錯誤提示進行處理 |
| 123198 | 客戶訂單號或者運單號與歷史訂單數據重複 | The customer order number or the waybill no is identical with an order number in history order data. | customerOrderNo |
| 123227 | [收件國家/地區]不支持修改 | [The receiver country/territory] does not support modification. | receiverInfo.country |
| 123228 | [包裹總件數]不支持修改 | [Total Identical Parcels] does not support modification. | parcelQuantity |
| 123232 | 該訂單不支持修改 | This order does not support modification. | 按照錯誤提示進行處理 |
| 123238 | 提交失敗，訂單信息已經下發 | Submit failed, order information has been processed | 按照錯誤提示進行處理 |
| 123247 | 系統正在處理中，請稍後再試。 | The system is processing, please try again later. | 按照錯誤提示進行處理 |
| 123248 | 國際產品映射碼錯誤。 | Mapping code of international product is wrong. | interProductCode |
| 123254 | 改單操作過於頻繁，請稍後重試。 | Order modification is too frequent, please try again later. | 按照錯誤提示進行處理 |
| 124003 | 寄件人聯繫電話格式錯誤 | The format of the shipper contact number is incorrect. | senderInfo.phoneNo<br>senderInfo.telNo |
| 124004 | 寄件人姓名格式錯誤 | The format of the shipper name is incorrect. | senderInfo.contact |
| 124007 | 寄件方寄件類型無效 | The shipper shipping type is invalid. | senderInfo.cargoType |
| 124008 | 寄件方聯繫人必填 | The shipper contact person is mandatory. | senderInfo.contact |
| 124011 | 寄件方公司名必填 | The shipper company name is mandatory. | senderInfo.company |
| 124015 | 寄件方郵編必填 | The shipper postal code is mandatory. | senderInfo.postCode |
| 124019 | 寄件方郵箱不合法 | The shipper mailbox is invalid. | senderInfo.email |
| 124020 | 寄件方國家或地區必填 | The shipper country or district is mandatory. | senderInfo.country |
| 124021 | 寄件方國家或地區長度超長 | The length of the shipper country or district is overlong. | senderInfo.country |
| 124025 | 寄件方地址必填 | The shipper address is mandatory. | senderInfo.address |
| 124029 | 到件人姓名格式錯誤 | The format of the receiver name is incorrect. | receiverInfo.contact |
| 124030 | 到件方聯繫人必填 | The receiver contact person is mandatory. | receiverInfo.contact |
| 124031 | 到件方聯繫人長度超長 | The length of the receiver contact person is overlong. | receiverInfo.contact |
| 124036 | 到件人聯繫電話格式錯誤 | The format of the receiver contact number is incorrect. | receiverInfo.phoneNo<br>receiverInfo.telNo |
| 124039 | 到件方郵編必填 | The receiver postal code is mandatory. | receiverInfo.postCode |
| 124042 | 到件方證件類型值錯誤 | The value of the receiver ID document type is incorrect. | receiverInfo.certType |
| 124044 | 到件方公司名必填 | The receiver company name is mandatory. | receiverInfo.company |
| 124046 | 到件方郵箱不合法 | The receiver mailbox is invalid. | receiverInfo.email |
| 124047 | 到件方國家或地區必填 | The receiver country or district is mandatory. | receiverInfo.country |
| 124048 | 到件方國家或地區長度超長 | The length of the receiver country or district is overlong. | receiverInfo.country |
| 124052 | 到件方詳細地址必填 | The detailed address of the receiver is mandatory. | receiverInfo.address |
| 124053 | 到件方詳細地址長度超長 | The length of the receiver detailed address is overlong. | receiverInfo.address |
| 124054 | 目的國家/地區要求到方郵箱必填 | Requested by the destination country/district, the receiver email is mandatory. | receiverInfo.email |
| 124055 | 到件方寄件類型無效 | The receiver shipping type is invalid. | receiverInfo.cargoType |
| 124056 | 到方公司名稱格式錯誤 | The format of the receiver company name is incorrect. | receiverInfo.company |
| 124057 | 寄方公司名稱格式錯誤 | The format of the shipper company name is incorrect. | senderInfo.company |
| 124079 | 寄件方身份證號或者護照至少填寫一項 | The shipper fill in either the ID card number or passport. | senderInfo.certType<br>senderInfo.certCardNo |
| 124080 | 寄件方統編號必填 | The shipper uniform registration number is mandatory. | senderInfo.certType<br>senderInfo.certCardNo |
| 124082 | 收件方身份證號、護照或者居留證至少填寫一項 | The receiver fill in one filed among the ID card number, passport, or residence permit. | receiverInfo.certType<br>receiverInfo.certCardNo |
| 124086 | 寄件人固定電話區號長度超長 | The length of the area code of the shipper fixed phone number is overlong. | senderInfo.telAreaCode |
| 124087 | 寄件人固定電話區號格式錯誤 | The format of the shipper fixed phone number is incorrect. | senderInfo.telAreaCode |
| 124089 | 收件人固定電話區號長度超長 | The length of the area code of the receiver fixed phone number is overlong. | receiverInfo.telAreaCode |
| 124090 | 收件人固定電話區號格式錯誤 | The format of the receiver fixed phone number is incorrect. | receiverInfo.telAreaCode |
| 124093 | 請輸入正確的寄件人固定電話 | Please fill in the correct shipper fixed phone number. | senderInfo.telNo |
| 124094 | 請輸入正確的收件人固定電話 | Please fill in the correct receiver fixed phone number. | receiverInfo.telNo |
| 124095 | 寄件人手機號碼、固話號碼請至少填寫一個 | Please fill in either the shipper mobile number or fixed phone number. | senderInfo.phoneNo<br>senderInfo.telNo |
| 124096 | 收件人手機號碼、固話號碼請至少填寫一個 | Please fill in either the receiver mobile number or fixed phone number. | receiverInfo.phoneNo<br>receiverInfo.telNo |
| 124097 | 尊敬的客戶，您好！受目的地疫情影響，暫時無法提供收派服務，帶來不便請您理解！ | Dear Customer, affected by the epidemic, delivery services at destination have been temporarily suspended, sorry for the inconvenience caused. | 聯繫順豐國際客戶經理 |
| 124105 | 收方的姓名需要用英文填寫 | The receiver name must be filled in in English. | receiverInfo.contact |
| 124115 | 寄件人姓名需要用英文輸入 | Sender's name should be filled in English. | senderInfo.contact |
| 124116 | 寄件人公司名需要用英文輸入 | Sender's company name should be filled in English. | senderInfo.company |
| 124117 | 收件人公司名需要用英文輸入 | Receiver's company name should be filled in English. | receiverInfo.company |
| 124118 | 寄件人詳細地址需要用英文輸入 | Sender's address should be filled in English. | senderInfo.address |
| 124119 | 收件人詳細地址需要用英文輸入 | Receiver's address should be filled in English. | receiverInfo.address |
| 124120 | 寄件方郵編無效 | The sender's post code is invalid | senderInfo.postCode |
| 124121 | 到件方郵編無效 | The receiver's post code is invalid | receiverInfo.postCode |
| 124122 | 寄件人建築編號需要用英文輸入 | Sender's building No. should be filled in English. | senderInfo.regionSixth |
| 124123 | 收件人建築編號需要用英文輸入 | Receiver's building No. should be filled in English. | receiverInfo.regionSixth |
| 124124 | 寄件人道路名需要用英文輸入 | Sender's road name should be filled in English. | senderInfo.regionFifth |
| 124125 | 收件人道路名需要用英文輸入 | Receiver's road name should be filled in English. | receiverInfo.regionFifth |
| 124128 | 收件方移動電話區號長度超長 | The area code of the receiver's mobile phone is too long | receiverInfo.phoneAreaCode |
| 124129 | 寄件方移動電話區號長度超長 | The area code of the shipper's mobile phone is too long | senderInfo.phoneAreaCode |
| 124135 | 收件方VAT長度超長 | The receiver's VAT is too long | receiverInfo.vat |
| 124144 | 收件方手機號需要填寫正確的本國號碼 | The receiver's mobile No. shall be a correct local No. | receiverInfo.phoneNo |
| 124149 | 收件方統編號必填 | The receiver unified code is required | receiverInfo.certType<br>receiverInfo.certCardNo |
| 124150 | 寄件方國家或地區代碼錯誤 | The shipper country or district code is wrong | senderInfo.country |
| 124151 | 收件方國家或地區代碼錯誤 | The receiver country or district code is wrong | receiverInfo.country |
| 124160 | 請填寫正確的IOSS號 | Please fill in the correct IOSS No. | senderInfo.iossNo |
| 124168 | 收方清關資料支持身份證和護照 | The receiving party's customs clearance data supports ID card and passport | receiverInfo.certType<br>receiverInfo.certCardNo |
| 124213 | 收方清關資料必填，證件類型支持XX。 | The recipient’s customs clearance information is required. The certificate type supports XX. | receiverInfo.certType<br>receiverInfo.certCardNo |
| 124216 | 收方手機號碼僅支持數字和部分符號。 | The receiver's mobile phone number only supports numbers and some symbols. | receiverInfo.phoneNo |
| 124217 | 收方固定電話僅支持數字和部分符號。 | The receiver's landline only supports numbers and some symbols. | receiverInfo.telNo |
| 124219 | 收方清關資料類型為身份證，收方姓名必填中文簡體/繁體 | If the receiver's customs clearance documents is ID card, the receiver's name must be filled in Chinese | receiverInfo.certType<br>receiverInfo.certCardNo |
| 126003 | 商品總重量長度超長(整數最長15位，小數最長5位) | The length of the total weight of the commodity is overlong. (The maximum length of the integer part is 15 digits, and the maximum length of the decimal part is 5 digits.) | ParcelInfo.weight |
| 126008 | 托寄物內容必填 | The commodity name is mandatory. | ParcelInfo.name |
| 126010 | 托寄物內容不允許填寫純數字 | The consignment content cannot be a pure number. | ParcelInfo.name |
| 126011 | 托寄物數量必填 | The consignment quantity is mandatory. | ParcelInfo.quantity |
| 126014 | 托寄物單位必填 | The consignment unit is mandatory. | ParcelInfo.unit |
| 126015 | 托寄物單位長度超長 | The length of the consignment unit is overlong. | ParcelInfo.unit |
| 126016 | 托寄物單價必填 | The consignment unit price is mandatory. | ParcelInfo.amount |
| 126018 | 商品單價長度超長(整數最長14位，小數最長10位) | The length of the commodity unit price is overlong. (The maximum length of the integer part is 14 digits, and the maximum length of the decimal part is 10 digits.) | ParcelInfo.amount |
| 126019 | 輸入的金額或者數量有誤，非文件類產品必須提供真實金額與數量。 | The inputted amount or quantity is incorrect. For a non-document product, the real amount and quantity must be provided. | ParcelInfo.amount<br>ParcelInfo.quantity |
| 126020 | 貨物產品國檢備案編號長度應小於18 | The length of the product AQSIQ filing number of the commodity must be smaller than 18. | ParcelInfo.productRecordNo |
| 126023 | 托寄物原產地長度超長 | The length of the consignment origin is overlong. | ParcelInfo.originCountry |
| 126031 | 包裹體積單位無效 | The package volume unit is invalid. | parcelVolumeUnit |
| 126032 | 包裹重量單位無效 | The package weight unit is invalid. | parcelWeightUnit |
| 126035 | 幣種長度超長 | The currency length is overlong. | declaredCurrency |
| 126040 | 商品編碼長度超長 | The length of the commodity code is overlong. | ParcelInfo.goodsCode |
| 126043 | 您輸入的品名為模糊品名或違禁品，禁止下單 | The commodity names that you have filled in are ambiguous or prohibited articles, so the order cannot be placed. | ParcelInfo.name |
| 126050 | 商品描述長度超長 | The commodity description is too long | ParcelInfo.goodsDesc |
| 126056 | 請使用英文填寫托寄物相關信息 | Please complete the consignment info in English | ParcelInfo |
| 126087 | 托寄物重量長度超長（整數最長14位，小數最長2位） | The length of the consignment weight is overlong. (The maximum length of the integer part is 14 digits, and the maximum length of the decimal part is 2 digits.) | ParcelInfo.weight |
| 126090 | 純中文長度必須大於等於2 | The length of pure Chinese must be at least 2 | ParcelInfo.name |
| 126091 | 純英文長度必須大於2 | The length of pure English must be more than 2 | ParcelInfo.eName |
| 127002 | 月結帳號必填 | The credit account number is mandatory. | paymentInfo.payMonthCard |
| 127005 | 付款方式值錯誤 | The payment mode value is incorrect. | paymentInfo.payMethod |
| 127006 | 稅金付款方式值錯誤 | The tax payment mode value is incorrect. | paymentInfo.taxPayMethod |
| 127014 | 該流向產品運費付款方式未進行配置 | The payment modes of the freight charges in the flow direction have not been configured. | 聯繫順豐國際技術支持 |
| 127015 | 運費付款方式僅支持[XX;XX] | The supported payment mode is only [XX;XX] | paymentInfo.payMethod |
| 127022 | 稅金付款方式僅支持[XX] | The supported tax payment mode is only [XX] | paymentInfo.taxPayMethod |
| 129010 | 【附加服務1】該流向產品及支付方式暫不支持COD代收貨款服務 | [Additional service 1] The product of the flow direction and the payment mode does not support the COD service at present. | AddServiceInfo<br>interProductCode |
| 129013 | 【附加服務1代收貨款卡號】貴用戶月結帳號並未開通代收貨款權限，詳情請聯繫順豐客服9533883 | [Additional service 1 COD account number] Your credit account does not have the COD permission. For details, please contact SF CSR at 9533883. | AddServiceInfo.valueOne |
| 129014 | 【附加服務2】該流向產品及支付方式暫不支持INSURE保價服務 | [Additional service 2] The product of the flow direction and the payment mode does not support the INSURE service at present. | AddServiceInfo.serviceCode |
| 129022 | 保價聲明價值不能大於物品聲明價值 | The insured declared value cannot be greater than the declared value of the article | AddServiceInfo.value<br>declaredValue<br>sum(ParcelInfo.amount\*ParcelInfo.quantity) |
| 129030 | [XX] 增值服務重複勾選，請保留一個重新提交。 | [XX] Value-added services are checked repeatedly, please keep one for resubmission. | AddServiceInfo |
| 136002 | 關務實名認證失敗，禁止下單！XX | Customs real name authentication failed, no order! XX | 按照錯誤提示進行處理 |
| 137001 | 您未配置子母件權限，包裹數量不能大於1 | You do not have child order right,the count of package can not be greater than 1 | parcelQuantity |
| 141005 | 該帳號無[XX]下單權限，請聯繫系統管理員添加 | This account does not have the authority to place orders for [XX], please contact the system administrator to add | [XX]為白名單物流產品，請聯繫順豐國際客戶經理 |
| 141006 | 該月結帳號無[XX]下單權限，請聯繫系統管理員添加 | This Credit account does not have the authority to place orders for [XX], please contact the system administrator to add | 聯繫順豐國際客戶經理 |
|

---

## 2. 出口電商常見異常響應

> 來源：https://openapi-portal.sf.global/question?secondMenu=128（常見問題 > 出口電商常見異常響應；官方更新 2025-09-03；爬取 2026-09-23）

### IECS\_CREATE\_ORDER

| 錯誤碼 | 異常可能涉及字段 | 備註 | 異常響應報文 |
| -------- | --- | ------ | ------ |
| 9071 | senderInfo.regionSecond |  | {msg:寄件方城市（j_city) 禁止出現中文\|j_city required to use non-Chinese characters,code:9071,success:false} |
| 9069 | senderInfo.regionFirst |  | {msg:寄件方省份（j_province) 禁止出現中文\|j_province is required to use non-Chinese characters,code:9069,success:false} |
| 400<br>4000 | senderInfo.phoneNo<br>senderInfo.telNo |  | {msg:寄件人手機號和聯繫電話必須填寫任意一個\|The shipper's phone or cell phone must fill in one.,code:400\|4000,success:false} |
| 9090 | senderInfo.phoneNo |  | {msg:寄件方手機號碼（j_mobile) 不能為空\|j_mobile is required,code:9090,success:false} |
| 9068 | senderInfo.contact |  | {msg:寄件方聯繫人（j_contact) 不能為空\|j_contact is required,code:9068,success:false} |
| 9069 | senderInfo.contact |  | {msg:寄件方聯繫人（j_contact ) 禁止出現中文\|j_contact is required to use non-Chinese characters,code:9069,success:false} |
| 9067 | senderInfo.company |  | {msg:寄件方公司名稱（j_company ) 禁止出現中文\|j_company required to use non-Chinese characters,code:9067,success:false} |
| 9039 | senderInfo.company |  | {msg:寄件方公司必填並且寄件方公司名稱（j_company ) 不能超過64個字符\|j_company is required and j_company is limited to 64 characters,code:9039,success:false} |
| 9046 | senderInfo.address |  | {msg:寄件人地址（j_address）只能為數字或者英文字符\|j_address only allows number or english characters,code:9046,success:false} |
| 9636 | receiverInfo.regionSecond |  | {msg:訂單到方城市（d_city）不超過28位\|This dest city length cannot exceed 28 characters,code:9636,success:false} |
| 9335 | receiverInfo.regionSecond |  | {msg:收件方城市(d_city)不能超過24個字符\|Receiver’s city(d_city) is limited to 24 characters,code:9335,success:false} |
| 9979 | receiverInfo.regionFirst<br>receiverInfo.regionSecond |  | {msg:為了您包裹的成功派送，請輸入正確的城市和省對應關係\|Please fill in correct city, state at the same time.,code:9979,success:false} |
| 5000 | receiverInfo.regionFirst |  | {msg:到方州省填寫錯誤\|The recipient's province is incorrect.,code:5000,success:false} |
| 9706 | receiverInfo.postCode<br>receiverInfo.regionSecond |  | {msg:收件方城市（d_city）和郵編不匹配\!\|Consignee's city (d_city) doesn't match the postcode\!,code:9706,success:false} |
| 9046 | receiverInfo.postCode<br>receiverInfo.regionSecond |  | {msg:收件方城市（d_city）或郵編（d_post_code）不可達\|Consignee's city (d_city) is not within the scope of delivery,code:9046,success:false} |
| 9304 | receiverInfo.postCode<br>receiverInfo.regionSecond |  | {msg:收件方城市（d_city）和郵編不匹配\!\|Consignee's city (d_city) doesn't match the postcode\!,code:9304,success:false} |
| 9744 | receiverInfo.postCode<br>receiverInfo.regionFirst<br>receiverInfo.regionSecond |  | {msg:到方州省，城市，郵編(d_province，d_city，d_postcode)信息不匹配\|The d_province, d_city, d_post_code information does not match,code:9744,success:false} |
| 9748 | receiverInfo.postCode<br>receiverInfo.regionFirst<br>receiverInfo.regionSecond |  | {msg:到方州省，城市，郵編(d_province，d_city，d_postcode)必填\| The d_province and d_city and d_postcode cannot be empty.,code:9748,success:false} |
| 9978 | receiverInfo.postCode |  | {msg:到方郵編不在派送範圍\|the recipient's postcode out of service.,code:9978,success:false} |
| 9903 | receiverInfo.postCode |  | {msg:收件方對應郵編(d_postcode)不可達\!\|Consignee's postcode(d_postcode) is not within the scope of delivery\!,code:9903,success:false} |
| 8099 | receiverInfo.postCode |  | {msg:美國收件郵編必須是5位數字或者5位數字+短橫線+4位數字\|d_post_code is limited to 5 numbers or is consist of 5 numbers ,strigula and 4 numbers,code:8099,success:false} |
| 9711 | receiverInfo.postCode |  | {msg:收件方郵編（d_postcode）不可達\!\|Consignee's postcode (d_postcode) is not within the scope of delivery\!,code:9711,success:false} |
| 9902 | receiverInfo.postCode |  | {msg:收件方對應郵編(d_postcode)不可達\!\|Consignee's postcode(d_postcode) is not within the scope of delivery\!,code:9902,success:false} |
| 9102 | receiverInfo.postCode |  | {msg:到件人郵編（d_post_code）只能包含數字、字母和 -\|d_post only allows number,letter and -,code:9102,success:false} |
| 9749 | receiverInfo.postCode |  | {msg:英國郵編格式不對\|The recipient's postcode format is incorrect,code:9749,success:false} |
| 9304 | receiverInfo.postCode |  | {msg:郵編地區超出該產品派送範圍，請嘗試轉國際小包產品下單！\|The Post code is not available for the service, please try to use E-parcel!,code:9304,success:false} |
| 9638 | receiverInfo.phoneNo<br>receiverInfo.telNo |  | {msg:請填寫正確的收件方手機號或者電話號碼\|Please fill in the correct recipient's mobile phone number or telephone number,code:9638,success:false} |
| 400<br>4000 | receiverInfo.phoneNo<br>receiverInfo.telNo |  | {msg:收件人手機號和聯繫電話必須填寫任意一個\|The consignee's phone or cell phone must fill in one.,code:400\|4000,success:false} |
| 9035 | receiverInfo.phoneNo |  | {msg:收件方手機號碼（d_mobile） 格式不正確\|d_mobile format is incorrect,code:9035,success:false} |
| 9099 | receiverInfo.phoneNo |  | {msg:收件人手機號碼（d_mobile)不能小於6位\|d_mobile is not less than six characters,code:9099,success:false} |
| 9634 | receiverInfo.contact |  | {msg:訂單到方聯繫人（d_contact）去掉數字，符號和空格後，必須大於2個字符\|After removing Numbers, symbols and Spaces, the dest contact must be larger than 2 characters,code:9634,success:false} |
| 9633 | receiverInfo.contact |  | {msg:訂單到方聯繫人（d_contact）不能為純數字\|This dest contact can't all be Numbers,code:9633,success:false} |
| 9632 | receiverInfo.company<br>receiverInfo.contact |  | {msg:收件方公司或收件人(d_company\|d_contact)長度不能超過30個字符\|Consignee’s company or Consignee’s contact is limited to 30 characters,code:9632,success:false} |
| 9794 | receiverInfo.company<br>receiverInfo.contact |  | {msg:收件方公司或收件人(d_company\|d_contact)不能超過48個字符,字符中只能包含a到z A到Z 0到9 _ #，且不能出現非英文字符\|Consignee’s company or Consignee’s contact couldn’t exceed 48 characters, which can only contains English characters and numbers.,code:9794,success:false} |
| 9642 | receiverInfo.address |  | {msg:訂單到方地址（d_address）不能超過60位\|This dest address length cannot exceed 60 digits,code:9642,success:false} |
| 9708 | receiverInfo.address |  | {msg:收件人地址不得超過70個字符\|Recipient’s address couldn’t exceed 70 characters.,code:9708,success:false} |
| 9360 | receiverInfo.address |  | {msg:收件地址不能超過70位數 \|d_address couldn’t longer than 70 digits!,code:9360,success:false} |
| 9752 | receiverInfo.address |  | {msg:地址欄不可包含Packstation！\| Address cannot contain Packstation！,code:9752,success:false} |
| 9039 | receiverInfo.address |  | {msg:收件方地址（d_Address) 不能為空且收件方地址（d_Address) 不能超過128個字符\|d_Address is required and d_Address is limited to 128 characters,code:9039,success:false} |
| 9111 | receiverInfo.address |  | {msg:到件方地址（d_address) 禁止出現中文\|d_address is required to use non-Chinese characters,code:9111,success:false} |
| 9013 | receiverInfo.address |  | {msg:到件方地址（d_address) 不能為空\|d_address is required,code:9013,success:false} |
| 9120 | parcelInfoList.weight |  | {msg:總重量不能小於0.001kg\|total weight cannot be less than 0.001kg,code:9120,success:false} |
| 9137 | parcelInfoList.weight |  | {msg:貨物單位重量（weight）不能為空\|weight is required,code:9137,success:false} |
| 9706 | parcelInfoList.weight |  | {msg:貨物重量不能小於1g(0.001kg)\|The cargo weight cannot be less than 1g (0.001kg)！,code:9706,success:false} |
| 9904 | parcelInfoList.weight |  | {msg:已超過國際電商專遞-CD最大重量限制2千克，請檢查。\|Has exceeded the maximum weight 2 kg of EXCD, please check.,code:9904,success:false} |
| 9706 | parcelInfoList.weight |  | {msg:貨品重量必須大於或等於0.001kg\|Cargo weight must be equal to or more then 0.001kg ,code:9706,success:false} |
| 9628 | parcelInfoList.parcelTotalLength<br>parcelInfoList.parcelTotalWidth<br>parcelInfoList.parcelTotalHeight |  | {msg:超過尺寸限制，尺寸限制為最長邊≤57cm；次長邊≤40cm；最短邊≤35cm；\|Size limit exceeded, size limit is Longest side ≤ 57cm; Secondary length side ≤ 40cm; Shortest side ≤ 35cm,code:9628,success:false} |
| 9654 | parcelInfoList.name |  | {msg:訂單貨物英文品名（name）去掉數字，符號和空格後，必須大於3個字符\|After removing Numbers, symbols and Spaces, the cargo name must be larger than 3 characters,code:9654,success:false} |
| 9956 | parcelInfoList.name |  | {msg:英文品名（name）只能為英文、數字、空格、()、,、%\|name only allows number,english,space,（）,(),code:9956,success:false} |
| 9653 | parcelInfoList.name |  | {msg:訂單貨物英文品名{name}不能超過40位\|This cargo name length cannot exceed 40 digits,code:9653,success:false} |
| 9706 | parcelInfoList.name |  | {msg:英文申報品名字符長度不得少於5位(英文字母),大於45位(數字，英文字母，空格)\|Length of declaration name string should be more than 5 and less than 45.,code:9706,success:false} |
| 9024 | parcelInfoList.name |  | {msg:英文品名（name）超過長度限制\|name is limited from 3 to  50 characters,code:9024,success:false} |
| 9655 | parcelInfoList.hsCode |  | {msg:訂單貨物海關編碼（hscode）長度不超過10個字符\|the cargo hscode cannot exceed 10 characters,code:9655,success:false} |
| 9056 | parcelInfoList.cName |  | {msg:中文品名字段必填且必須包含中文\|Chinese commodity name is required and must include chinese characters,code:9056,success:false} |
| 400 | isBat<br>interProductCode |  | {msg:該產品類型只能下不帶電訂單\|This express type is not support electrification orders.,code:400,success:false} |
| 9131 | isBat |  | {msg:是否帶電(isBat)標誌不能為空, 且只能填0或1（0表示不帶電，1表示帶電）\|isBat is required and can only be written as 0 or 1(0 is not charged, 1 is charged)!,code:9131,success:false} |
| 9934 | isBat |  | {msg:帶電產品不能下單\|Not support electrification orders.,code:9934,success:false} |
| 9704 | interProductCode |  | {msg:該產品類型暫未開放，請聯繫客服核實\!\|The express type is not yet open，please contact customer service to verify,code:9704,success:false} |
| 9663 | declaredValue |  | {msg:商品申報價值*數量之和需大於1美元小於等於800美元\|The declared value must be greater than 1 USD and less than or equal to 800 USD,code:9663,success:false} |
| 9742 | declaredValue |  | {msg:貨物申報價值(declare_value)不能超過15美元\|Declared value cannot be more than 15 dollars,code:9742,success:false} |
| 9977 | declaredValue |  | {msg:申報總價不能小於包裹內單價*數量之和！\|Declared value cannot be less than the sum of unit price * quantity in the package!,code:9977,success:false} |
| 9118 | declaredValue |  | {msg:訂單托寄物聲明價值（declared_value)必須大於等於0.01\|declared_value must be greater than 0.01,code:9118,success:false} |
| 9663 | declaredValue |  | {msg:商品申報價值*數量之和需大於1美元小於800美元\|The declared value must be greater than 1 USD and less than 800 USD,code:9663,success:false} |
| 9003 | customerOrderNo |  | {msg:客戶訂單號（orderid）格式錯誤，只能包含數字、字母、下劃線和中劃線\|orderid only allows numbers and letters,code:9003,success:false} |
| 7001 | abn |  | {msg:IOSS碼(abn)是必填項，請補充後再下單\|IOSS code is required, please add it before placing the order,code:7001,success:false} |
| 4000 |  |  | {msg:egg Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| 4000 |  |  | {msg:cake Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| |  |  | {msg:操作過於頻繁，請稍後重試,code:null,success:false} |
| 400<br>4000 |  |  | {msg:目的地國家超出該產品的可寄送國家範圍（獲取代理失敗）\|the destination country is beyond the reach of the express type (get agentCode error),code:400\|4000,success:false} |
| 400<br>4003 |  |  | {msg:當前用戶已欠費，請還款\|current user is in arrears, please fill the value in time,code:400\|4003,success:false} |
| 4000 |  |  | {msg:food Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| 4000 |  |  | {msg:Mask Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| 4000 |  |  | {msg:mushroom Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| 500 |  |  | {msg:執行校驗規則出現異常，ruleId: 361, code: 9744, 異常信息: 網絡異常，請稍後重試！Network exception, please try again later!,code:500,success:false} |
| 400<br>4003 |  |  | {msg:當前用戶餘額不足，請充值後再下單\|current user cost estimated is not enough, please recharge in time,code:400\|4003,success:false} |
| 401 |  |  | {msg:用戶越權\|User ultra vires,code:401,success:false} |
| 5001 |  |  | {msg:(申報信息1)申報重量必須大於0，最多3位小數,code:5001,success:false} |
| 5001 |  |  | {msg:{\code:\1000,\message:\\},code:5001,success:false} |
| 4000 |  |  | {msg:鞋套 Not allowed in the customs, will affect customs clearance, resulting in customs prosecution and inspection, in order to improve the timeliness of customs clearance, please modify the name.,code:4000,success:false} |
| 5001 |  |  | {msg:(申報信息3)申報重量必須大於0，最多3位小數,code:5001,success:false} |
| 5001 |  |  | {msg:(申報信息1)申報重量必須大於0，最多3位小數, (申報信息2)申報重量必須大於0，最多3位小數,code:5001,success:false} |
| 500<br>5000 |  |  | {msg:當前用戶狀態異常，請聯繫客服\|current user not supported,code:500\|5000,success:false} |

---

## 補充：其他接口頁內的業務碼（彙整）

| 接口 | 代碼 | 說明 |
|---|---|---|
| `GTS_QUERY_TRACK` 路由查詢（中文版） | 1004 | Query No. exceeds the maximum limit:maxNum=10，actualNum=20 / The verification of mandatory parameters is lost |
| 同上 | 1005 | Phone verification failed（手機後四碼不符） |
| 同上 | 300001 | Tracking number is required |
| 同上 | 320001 | This tracking number is invalid or non SF-international |
| 同上 | 999 | System request processing exception |
| 路由查詢英文版另列 | 1003 / 4001 / 5001 / 5006 | 見 `tracking.md` 補充節 |
| `IOMS_SEND_PROVE_*` / `IOMS_QUERY_CHARGE` 外層 `code` | 200000 / 300603 | 請求成功 / 系統錯誤 |
| 同上 `resultCode` | 0000–0022 | 0000 成功、0001 客戶編碼不存在、0002 客戶編碼失效、0003 月結帳號不存在、0004 月結帳號不生效、0005 非順豐運單號、0006 文件類型不支援、0007 任務編碼不存在、0008 任務待處理、0009 任務處理失敗、0010 任務取消、0011 任務已過期、0012 重複提交（三分鐘後再提交）、0013 運單號數量不能為空、0014 超過 50 個、0015 TaskCode 任務查詢中、0016 TaskCode 5 秒內不可重複提交、0017 非該月結帳號下運單、0018 未查詢到資料、0019 查詢文件失敗、0020 運單號不能為空、0021 超過 10 個、0022 未查詢到運單資訊 |
| `IECS_QUERY_AGENTNO` `wrongData.status` | 4001 / 4002 | 順豐單號或訂單號錯誤，未找到對應代理單號 / 代理單號未生成，請稍後再試 |
| `ADES_*` 代理商接口 | `SUC_ADES-MANAGE_00_0000` | 成功，其他為失敗 |
| `ICSM_UPLOAD_CERTIFY` | `ok` / `fail` | 成功 / 失敗 |
| `SF_IFTP_*` | 200 / 500 | 成功 / 失敗 |
| `IECS_QUERY_POD` `smartSignatureStatus` | 1 / 2 / 3 / 4 | 生成成功 / 生成中 / 順豐內部審核失敗（ECP 申請失敗）/ 其他錯誤 |
