import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { decodeEntities } from '@wordpress/html-entities'

const id = 'linepay'
const settings = getSetting(`${id}_data`, {})
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const Content = () => {
	return decodeEntities(settings.description || '')
}

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props: any) => {
	const { PaymentMethodLabel } = props.components
	return <PaymentMethodLabel text={label} />
}

const options = {
	name,
	label: <Label />,
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: id,
	supports: {
		features,

		// LINE Pay 直連（api-redirect）：下單後導向 LINE Pay 付款頁認證，不在結帳頁收卡、不存綁定卡片。
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊 LINE Pay 直連（Online API v4）付款方式
 */
registerPaymentMethod(options)
