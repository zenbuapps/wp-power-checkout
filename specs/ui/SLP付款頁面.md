# SLP 付款頁面

## 描述
Shopline Payment 託管的付款頁面，顧客在 WooCommerce 結帳後被導向至此頁面完成付款。

## 關鍵屬性
- 頁面類型：外部託管頁面（SLP 提供的 sessionUrl）
- 入口：WooCommerce process_payment 成功後跳轉
- 回跳：付款完成或取消後，SLP 導回 WooCommerce order-received 頁面
- 付款方式：依 allowPaymentMethodList 設定顯示可用付款選項
