export enum EInvoiceType {
	INDIVIDUAL = 'individual',
	COMPANY = 'company',
	DONATE = 'donate',
}

export const invoiceTypes = [
	{
		value: EInvoiceType.INDIVIDUAL,
		label: '個人',
	},
	{
		value: EInvoiceType.COMPANY,
		label: '公司',
	},
	{
		value: EInvoiceType.DONATE,
		label: '捐贈',
	},
] as const

export enum EIndividual {
	CLOUD = 'cloud',
	BARCODE = 'barcode',
	MOICA = 'moica',
	PAPER = 'paper',
}

export const individuals = [
	{
		value: EIndividual.CLOUD,
		label: '雲端發票',
	},
	{
		value: EIndividual.BARCODE,
		label: '手機條碼',
	},
	{
		value: EIndividual.MOICA,
		label: '自然人憑證',
	},
	// {
	// 	value: EIndividual.PAPER,
	// 	label: '紙本發票',
	// },
] as const
