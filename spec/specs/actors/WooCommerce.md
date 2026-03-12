# WooCommerce

## 描述
WordPress 電商平台系統，管理訂單生命週期。Power Checkout 作為 WooCommerce Payment Gateway 外掛整合金流與發票功能。訂單狀態變更時自動觸發開立/作廢發票。

## 關鍵屬性
- 身份：系統
- 訂單存儲：HPOS（wc_orders 表）或傳統（wp_posts 表）
- 狀態機：pending -> processing -> completed / cancelled / refunded
- Hook 系統：透過 woocommerce_order_status_{status} 觸發自動發票操作
