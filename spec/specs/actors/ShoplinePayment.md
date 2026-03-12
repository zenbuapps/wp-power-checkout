# Shopline Payment

## 描述
第三方金流服務商（外部系統），提供信用卡、ATM 虛擬帳號、Apple Pay、LINE Pay、街口支付、中租零卡等付款方式。透過 Webhook 非同步通知付款/退款結果。

## 關鍵屬性
- 身份：外部系統
- 通訊方式：REST API（建立交易、查詢交易、退款）+ Webhook（非同步通知）
- API 認證：platformId + merchantId + apiKey + clientKey
- Webhook 驗簽：HMAC-SHA256（signKey）
- 支援付款方式：CreditCard、VirtualAccount、JKOPay、ApplePay、LinePay、ChaileaseBNPL
