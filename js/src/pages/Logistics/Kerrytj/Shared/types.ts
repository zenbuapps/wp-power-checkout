/**
 * 嘉里大榮（kerrytj_logistics）後台設定表單資料（對齊 KerrytjLogisticsSettingsDTO::to_array，全 snake_case）
 *
 * 測試（EDIEntranceTest）與正式（EDIEntrance）的帳密、客編、號段各自一套，由嘉里大榮業務提供；
 * 貨況查詢主機（KerryTracingEDI）只有一組、沒有測試網址。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API --- //
	mode: 'test' | 'prod'
	username: string
	password: string
	customer_no: string

	// --- 寄件人（商店；退貨取件時為收貨人） --- //
	sender_name: string
	sender_phone: string
	sender_postcode: string
	sender_address: string

	// --- 號段（常溫 11 碼；低溫 9 碼流水號或 10 碼完整單號） --- //
	ambient_waybill_start: string
	ambient_waybill_end: string
	cold_waybill_start: string
	cold_waybill_end: string

	// --- 託運單預設 --- //
	default_temperature: string
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

/** GET settings/kerrytj_logistics 回應 data（啟用時含唯讀號段狀態） */
export type TSettingsResponse = TFormData & {
	ambient_waybill_status?: TWaybillStatus
	cold_waybill_status?: TWaybillStatus
}

/** 「測試連線」回應 data（對齊 KerrytjLogisticsProvider::test_connection） */
export type TPingResult = {
	provider: string
	mode: string
	endpoint: string
	customer_no_configured: boolean
	missing_sender_fields: string[]
	ambient_waybill_status: TWaybillStatus
	cold_waybill_status: TWaybillStatus
}

/** 溫層（Temperature） */
export const KERRYTJ_TEMPERATURE_OPTIONS = [
	{ value: '1', label: '常溫' },
	{ value: '2', label: '冷藏' },
	{ value: '3', label: '冷凍' },
]

/** 寄件人欄位中文（測試連線缺漏提示用） */
export const KERRYTJ_SENDER_FIELD_LABELS: Record<string, string> = {
	sender_name: '寄件人名稱',
	sender_phone: '寄件人電話',
	sender_postcode: '寄件人郵遞區號',
	sender_address: '寄件人地址',
}
