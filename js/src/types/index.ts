export type TIGateway = TIntegration & {
	plugin_id: 'woocommerce_'
	errors: any[]
	settings: {
		enabled: 'yes' | 'no'
		title: string
		description: string
		min_amount: string
		max_amount: string
		expire_date: string
	}
	form_fields: any[]
	order_button_text: string
	title: string
	description: string
	chosen: null | any
	has_fields: null | any
	countries: null | any
	availability: null | any
	supports: string[]
	max_amount: 0
	view_transaction_url: string
	new_method_label: string
	pay_button_id: string
	payment_type: string
	min_amount: number
	order: null | any
	error: {
		errors: any[]
		error_data: any[]
	}
}

export type TIntegration = {
	id: string
	icon: string
	enabled: 'yes' | 'no'
	method_title: string
	method_description: string
}
