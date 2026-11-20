/**
 * 黑貓PAY（tcat_pay）後台設定表單資料（對齊 TcatPaySettingsDTO::to_array，全 snake_case）
 *
 * max_amount_* 為各子方式單筆上限覆寫：空字串代表沿用平台預設
 * （刷卡 100,000 / ibon 20,000 / ATM 49,999）。
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
	cust_id: string
	api_password: string
	hash_base: string
	acquirer_type: string
	limit_product_id: string

	// --- 子方式 --- //
	enabled_methods: string[]
	expire_days: number
	max_amount_credit: number | string
	max_amount_cvs: number | string
	max_amount_atm: number | string
}

/** 子方式（對齊後端 TcatPayMethod） */
export enum ETcatPayMethod {
	CREDIT = 'credit',
	CVS_IBON = 'cvs_ibon',
	ATM = 'atm',
}

/** 子方式標籤 */
export const TCAT_PAY_METHOD_LABELS: Record<ETcatPayMethod, string> = {
	[ETcatPayMethod.CREDIT]: '信用卡（導向黑貓PAY 刷卡頁）',
	[ETcatPayMethod.CVS_IBON]: 'ibon 超商代碼（安源）',
	[ETcatPayMethod.ATM]: 'ATM 虛擬帳號（統一金流）',
}

/** COCS 收單銀行（對齊後端 TcatPaySettingsDTO::ACQUIRER_TYPES） */
export const TCAT_PAY_ACQUIRER_OPTIONS = [
	{ value: 'payuni', label: '統一金流（payuni）' },
	{ value: 'esun', label: '玉山銀行（esun）' },
	{ value: 'chinatrust', label: '中國信託（chinatrust）' },
]
