/**
 * 順豐速運（sf_express）後台設定表單資料（對齊 SfExpressSettingsDTO::to_array，全 snake_case）
 *
 * 憑證欄位沒有任何預設值（官方公開沙箱憑證也不預填），由站主自行填入。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API --- //
	mode: 'test' | 'prod'
	app_key: string
	app_secret: string
	encoding_aes_key: string
	customer_code: string
	month_card: string
	product_code: string
	push_sign_key: string

	// --- 寄件人 --- //
	sender_name: string
	sender_mobile: string
	sender_tel: string
	sender_company: string
	sender_postcode: string
	sender_address: string
	sender_cargo_type: '1' | '2'
	sender_tax_id: string

	// --- 託寄物 --- //
	parcel_name: string
}

/** GET settings/sf_express 回應 data（含唯讀的推送接收網址） */
export type TSettingsResponse = TFormData & {
	track_push_url?: string
}

/** 「測試連線」回應 data（對齊 SfExpressProvider::test_connection） */
export type TPingResult = {
	provider: string
	mode: string
	endpoint: string
	customer_code_verified: boolean
	push_key_configured: boolean
	track_push_url: string
}

/** 寄件類型（senderInfo.cargoType；台灣始發必填） */
export const SF_CARGO_TYPE_OPTIONS = [
	{ value: '2', label: '公司件' },
	{ value: '1', label: '個人件' },
]
