import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { __ } from '@wordpress/i18n'
import { decodeEntities } from '@wordpress/html-entities'
import { getSetting } from '@woocommerce/settings'

const id = 'shopline_payment_redirect'
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
		showSavedCards: true,
		showSaveOption: false,
	},
}

/**
 * 註冊付款方式
 * 也可以用 import { registerPaymentMethod } from '@woocommerce/blocks-registry';
 */
registerPaymentMethod(options)
