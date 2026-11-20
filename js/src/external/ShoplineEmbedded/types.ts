/**
 * SHOPLINE Payments 嵌入式（站內付）前端型別
 *
 * SDK 型別官方未提供，依 .claude/skills/shopline-payments-v1/references/embedded-sdk.md 欄位自行宣告。
 */

/**
 * order-received 頁 localize 資料（EmbeddedGateway::before_order_received → power_checkout_slp_embedded_data）
 *
 * 注意：wp_localize_script 會把所有值轉成字串。
 */
export interface IShoplineEmbeddedData {
	/** POST power-checkout/v1/pay/{order_id}/dispatch */
	dispatch_url: string
	order_id: string
	order_key: string

	/** 官方 CDN（https://cdn.shoplinepayments.com/sdk/v1/payment-web.js） */
	sdk_url: string

	/** SDK 收銀台容器 id */
	container_id: string

	/** 付款完成後導回頁 */
	order_received_url: string

	/** 'yes'：最近一次查詢交易仍處理中，不渲染收銀台（避免重複扣款） */
	awaiting_confirmation: 'yes' | 'no'
}

/** dispatch init 回應的 SDK 初始化參數（金額由後端計算） */
export interface IShoplineSdkInit {
	clientKey: string
	merchantId: string
	platformId?: string
	paymentMethod: string
	currency: string
	amount: number
	env: 'sandbox' | 'production'
	language: string
	installmentCounts: string[]
}

/** PaymentDispatchApiService 回應 envelope */
export interface IDispatchResponse<T> {
	code: string
	message: string
	error_code?: string
	data: T | null
}

/** init 階段 data */
export interface IDispatchInitData {
	mode: string
	provider: string
	phase: 'init'
	sdk: IShoplineSdkInit
}

/** confirm 階段 data（next_action 為原樣 JSON 字串，前端 JSON.parse 後交給 SDK pay()） */
export interface IDispatchConfirmData {
	mode: string
	provider: string
	phase: 'confirm'
	next_action: string
}

/** SDK 錯誤 */
export interface IShoplineSdkError {
	code: string
	message: string
}

/** SDK 付款實例 */
export interface IShoplinePayment {
	createPayment: () => Promise<{
		paySession?: string
		error?: IShoplineSdkError
	}>
	pay: (
		nextAction: unknown
	) => Promise<{ error?: IShoplineSdkError } | undefined>
	destroy?: () => void
}

/** SDK 初始化參數（ShoplinePayments(options)） */
export interface IShoplinePaymentsOptions extends IShoplineSdkInit {
	element: string
}

/** window.ShoplinePayments（由官方 CDN 注入） */
export type IShoplinePaymentsGlobal = (
	options: IShoplinePaymentsOptions
) => Promise<{
	payment?: IShoplinePayment
	error?: IShoplineSdkError
}>
