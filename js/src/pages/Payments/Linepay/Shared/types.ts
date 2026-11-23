/**
 * LINE Pay 直連（linepay）後台設定表單資料（對齊 LinepaySettingsDTO::to_array，全 snake_case）
 *
 * 與 SHOPLINE Payments / 藍新 / PAYUNi「代收 LINE Pay」無關：本 gateway 使用商家自己向
 * LINE Pay 申請的 Channel ID / Channel Secret 直連 Online API v4。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	order_button_text: string
	min_amount: number
	max_amount: number

	// --- API --- //
	mode: string
	channel_id: string
	channel_secret: string
}
