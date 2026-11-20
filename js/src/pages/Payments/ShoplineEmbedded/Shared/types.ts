/**
 * SHOPLINE Payments 嵌入式（站內付）後台設定表單資料（對齊 EmbeddedSettingsDTO）
 *
 * 只有顯示欄位：商店憑證（merchantId / apiKey / clientKey / signKey）與測試 / 正式模式
 * 一律共用 SHOPLINE Payments 導轉式設定（/payments/shopline_payment_redirect），本頁不重複存。
 */
export type TFormData = {
	title: string
	description: string
	order_button_text: string
}
