/**
 * 黑貓PAY（tcat_pay）— WC Block Checkout 付款方式註冊
 *
 * 子方式（信用卡 / ibon 超商代碼 / ATM 虛擬帳號）由顧客於結帳頁選擇：
 *   1. 後端 TcatPayBlocksIntegration 於 `tcat_pay_data.methods` 提供欄位名與選項（含各子方式單筆上限）。
 *   2. 依購物車金額停用超限的子方式（後端 before_process_payment 仍會再驗一次，前端只是提示）。
 *   3. 選擇結果於 onPaymentSetup 以 paymentMethodData 回傳；Store API 會放進 $_POST，
 *      後端 TcatPayGateway 依白名單 + 上限驗證。
 *
 * 含 hooks 的元件以 `@wordpress/element`（window.wp.element）的 createElement 建立，
 * 與 WC Blocks 渲染器同一份 React（比照 ecpay_aio.tsx）。
 *
 * @see inc/classes/Domains/Payment/TcatPay/Services/TcatPayGateway.php
 * @see .claude/rules/react-blocks.rule.md
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import {
	createElement,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'

/** 子方式選項 */
type TMethodOption = {
	value: string
	label: string
	max_amount: number
}

/** 後端 TcatPayGateway::get_block_method_data() */
type TMethodData = {
	field: string
	options: TMethodOption[]
}

/** WC Blocks 傳給付款方式 content 元件的 props（僅宣告本檔用到的部分） */
type TPaymentMethodProps = {
	billing?: {
		cartTotal?: { value?: number }
		currency?: { minorUnit?: number }
	}
	eventRegistration?: {
		onPaymentSetup?: (callback: () => unknown) => () => void
		onPaymentProcessing?: (callback: () => unknown) => () => void
	}
	emitResponse?: {
		responseTypes?: {
			SUCCESS?: string
			ERROR?: string
		}
	}
}

/** PaymentMethodLabel 元件 props */
type TLabelProps = {
	components: {
		PaymentMethodLabel: (props: { text: string }) => unknown
	}
}

const id = 'tcat_pay'
const settings = getSetting(`${id}_data`, {})
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const methods: TMethodData = {
	field: 'pc_tcat_pay_method',
	options: [],
	...(settings.methods ?? {}),
}

/**
 * 購物車總額（元）：Store API 金額為最小單位整數
 *
 * @param props WC Blocks props
 * @return 總額（元）
 */
const getCartTotal = (props: TPaymentMethodProps): number => {
	const value = props.billing?.cartTotal?.value ?? 0
	const minorUnit = props.billing?.currency?.minorUnit ?? 0
	return value / Math.pow(10, minorUnit)
}

/** 付款方式描述 */
const Description = () => decodeEntities(settings.description || '')

/**
 * 子方式 radio：超限選項停用，選擇結果於 onPaymentSetup 回傳
 *
 * @param props WC Blocks props
 * @return 元素
 */
const MethodRadios = (props: TPaymentMethodProps): unknown => {
	const total = getCartTotal(props)
	const available = methods.options.filter(
		(option) => total <= 0 || total <= option.max_amount
	)
	const [selected, setSelected] = useState(available[0]?.value ?? '')

	const register =
		props.eventRegistration?.onPaymentSetup ??
		props.eventRegistration?.onPaymentProcessing
	const successType = props.emitResponse?.responseTypes?.SUCCESS ?? 'success'
	const errorType = props.emitResponse?.responseTypes?.ERROR ?? 'error'

	useEffect(() => {
		if (!register) {
			return undefined
		}
		return register(() =>
			'' === selected
				? { type: errorType, message: '請選擇黑貓PAY 付款方式' }
				: {
						type: successType,
						meta: { paymentMethodData: { [methods.field]: selected } },
					}
		)
	}, [register, successType, errorType, selected])

	return createElement(
		'div',
		{ className: 'pc-tcat-pay-methods', style: { marginTop: '12px' } },
		...methods.options.map((option) => {
			const disabled = !available.includes(option)
			return createElement(
				'label',
				{
					key: option.value,
					style: {
						display: 'block',
						marginBottom: '6px',
						opacity: disabled ? 0.5 : 1,
					},
				},
				createElement('input', {
					type: 'radio',
					name: methods.field,
					value: option.value,
					checked: selected === option.value,
					disabled,
					onChange: () => setSelected(option.value),
					style: { marginRight: '6px' },
				}),
				decodeEntities(option.label),
				disabled
					? ` （超過單筆上限 ${option.max_amount.toLocaleString()} 元）`
					: ''
			)
		})
	)
}

/**
 * 結帳頁付款方式內容：描述 + 子方式選擇
 *
 * @param props WC Blocks props
 * @return 元素
 */
const Content = (props: TPaymentMethodProps): unknown =>
	createElement(
		Fragment,
		null,
		createElement(Description),
		methods.options.length > 0 ? createElement(MethodRadios, props) : null
	)

/**
 * Label component
 *
 * @param props Props from payment API.
 * @return 元素
 */
const Label = (props: TLabelProps) => {
	const { PaymentMethodLabel } = props.components
	return createElement(PaymentMethodLabel, { text: label })
}

registerPaymentMethod({
	name,
	label: createElement(Label as never),
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: createElement(Content),
	edit: createElement(Description),
	canMakePayment: () => true,
	paymentMethodId: id,
	supports: {
		features,
		showSavedCards: false,
		showSaveOption: false,
	},
})
