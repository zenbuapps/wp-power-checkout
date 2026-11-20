/**
 * 黑貓宅急便直串（tcat_logistics）後台設定表單資料（對齊 TcatLogisticsSettingsDTO::to_array，全 snake_case）
 *
 * 契客代號 / 授權碼測試與正式各一組，需到統一速達契客專區分別發送（沒有公開測試帳號）。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API --- //
	mode: 'test' | 'prod'
	customer_id: string
	customer_token: string

	// --- 寄件人 --- //
	sender_name: string
	sender_tel: string
	sender_mobile: string
	sender_address: string
	sender_zip_code: string

	// --- 託運單預設 --- //
	print_obt_type: string
	default_thermosphere: string
	default_spec: string
	product_type_id: string
	product_name: string
	delivery_time: string
}

/** 「測試連線」回應 data（對齊 TcatLogisticsProvider::test_connection） */
export type TPingResult = {
	provider: string
	mode: string
	address: string
	post_number: string
	zip_code: string
	deliverable: boolean

	/** ParsingAddress 不驗證授權碼 → 恆 false（授權碼需以實際建單確認） */
	credentials_verified: boolean
}

/** 託運單版型（PrintOBT 宅配 PrintOBTType） */
export const TCAT_PRINT_OBT_TYPE_OPTIONS = [
	{ value: '01', label: 'A4 二模' },
	{ value: '02', label: 'A4 三模' },
	{ value: '03', label: '熱轉印' },
]

/** 溫層（Thermosphere） */
export const TCAT_THERMOSPHERE_OPTIONS = [
	{ value: '0001', label: '常溫' },
	{ value: '0002', label: '冷藏' },
	{ value: '0003', label: '冷凍' },
]

/** 規格（Spec；低溫不可 150cm） */
export const TCAT_SPEC_OPTIONS = [
	{ value: '0001', label: '60cm' },
	{ value: '0002', label: '90cm' },
	{ value: '0003', label: '120cm' },
	{ value: '0004', label: '150cm（限常溫）' },
]

/** 希望配達時段（DeliveryTime；官方無 03） */
export const TCAT_DELIVERY_TIME_OPTIONS = [
	{ value: '04', label: '不指定' },
	{ value: '01', label: '13 時前' },
	{ value: '02', label: '14-18 時' },
]

/** 商品類別（ProductTypeId） */
export const TCAT_PRODUCT_TYPE_OPTIONS = [
	{ value: '0001', label: '一般食品' },
	{ value: '0002', label: '名特產 / 甜產' },
	{ value: '0003', label: '酒 / 油 / 醋 / 醬' },
	{ value: '0004', label: '穀物蔬果' },
	{ value: '0005', label: '水產 / 肉品' },
	{ value: '0006', label: '3C' },
	{ value: '0007', label: '家電' },
	{ value: '0008', label: '服飾配件' },
	{ value: '0009', label: '生活用品' },
	{ value: '0010', label: '美容彩妝' },
	{ value: '0011', label: '保健食品' },
	{ value: '0012', label: '醫療相關用品' },
	{ value: '0013', label: '寵物用品飼料' },
	{ value: '0014', label: '印刷品' },
	{ value: '0015', label: '其他' },
]

/** 商品名稱禁用字串（子字串比對，含任一即被黑貓拒收） */
export const TCAT_FORBIDDEN_PRODUCT_WORDS = [
	'文件',
	'標單',
	'明信片',
	'郵簡',
	'信函',
	'資料',
	'發票',
	'訴訟文件',
	'公文',
	'身分證明文件',
	'報名表',
	'申請書',
	'帳單',
	'訂單',
	'報價單',
	'繳費單',
	'通知單',
	'保險單',
	'文書',
]
