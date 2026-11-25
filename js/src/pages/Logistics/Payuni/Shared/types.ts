import type {
	EPayuniLgsType,
	EPayuniLogisticsMode,
	EPayuniSubType,
} from '@/pages/Logistics/Payuni/Shared/enums'

/**
 * PAYUNi 統一金流物流後台設定表單資料（對齊 PayuniLogisticsSettingsDTO）
 *
 * 欄位採 snake_case，與後端 DTO 屬性一致。
 * ⚠️ 與綠界不同：PAYUNi 僅單一組憑證（mer_id / hash_key / hash_iv），
 * 超商 B2C / C2C 由 cvs_lgs_type 切換 LgsType 參數，不換金鑰。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API / 模式 --- //
	mode: `${EPayuniLogisticsMode}`

	// --- 憑證（單一組，物流 / 金流共用） --- //
	mer_id: string
	hash_key: string
	hash_iv: string

	// --- 物流方式與寄件人 --- //
	cvs_lgs_type: `${EPayuniLgsType}`
	enabled_methods: `${EPayuniSubType}`[]
	sender_name: string
	sender_mobile: string

	// --- 黑貓宅配 --- //
	home_delivery_time_tag: '01' | '02' | '04'
	home_spec: '1' | '2' | '3' | '4'

	// --- 物流狀態自動查詢 --- //
	status_polling: 'yes' | 'no'
	status_polling_intervals: string
	status_polling_max_days: string
}

/** 設定 API 回傳（含後端算好的唯讀網址，不回存） */
export type TSettingsResponse = TFormData & {
	notify_url?: string
	map_return_url?: string
}
