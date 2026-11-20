/**
 * 新竹物流（hct_logistics）後台設定表單資料（對齊 HctLogisticsSettingsDTO::to_array，全 snake_case）
 *
 * 官方文件的測試帳號（test / test1）實測屬正式環境：在測試站會回「「測試環境」請勿使用正式公司名稱或密碼」，
 * 測試站帳號需另向新竹物流申請。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API --- //
	mode: 'test' | 'prod'
	company: string
	password: string
	customer_code: string

	// --- 出貨人（空白 = 新竹帳號預設值） --- //
	sender_name: string
	sender_tel: string
	sender_address: string

	// --- 號段（9 碼本體，不含檢查碼；起訖皆空 = 由新竹系統配號） --- //
	waybill_start: string
	waybill_end: string

	// --- 託運單預設 --- //
	default_product_type: string
	default_weight: string

	// --- 貨況查詢（申請查貨服務後新竹提供） --- //
	track_offset_days: string
	track_iv: string
	track_v: string
}

/** 號段狀態（對齊 WaybillNumberAllocator::status） */
export type TWaybillStatus = {
	configured: boolean
	start: number
	end: number
	current: number
	remaining: number
	next: string
}

/** GET settings/hct_logistics 回應 data（啟用時含唯讀狀態欄位） */
export type TSettingsResponse = TFormData & {
	waybill_status?: TWaybillStatus
	track_crypto_available?: boolean
}

/** 「測試連線」回應 data（對齊 HctLogisticsProvider::test_connection） */
export type TPingResult = {
	provider: string
	mode: string
	endpoint: string
	waybill_status: TWaybillStatus
	track_query_configured: boolean
	track_crypto_available: boolean
}

/** 商品種類（eprdcl2；溫層） */
export const HCT_PRODUCT_TYPE_OPTIONS = [
	{ value: '001', label: '一般（常溫）' },
	{ value: '008', label: '冷藏' },
	{ value: '003', label: '冷凍' },
]
