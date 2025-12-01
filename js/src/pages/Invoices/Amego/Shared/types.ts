export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	// --- API --- //
	mode: string
	invoice: string
	app_key: string
	auto_issue_order_statuses: string[]
	auto_cancel_order_statuses: string[]
}
