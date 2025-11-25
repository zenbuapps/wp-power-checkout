export enum EInvoiceTypes {
	INDIVIDUAL = 'individual',
	COMPANY = 'company',
	DONATE = 'donate',
}

export const invoiceTypes = [
	{
		value: EInvoiceTypes.INDIVIDUAL,
		label: '個人',
	},
	{
		value: EInvoiceTypes.COMPANY,
		label: '公司',
	},
	{
		value: EInvoiceTypes.DONATE,
		label: '捐贈',
	},
] as const

export enum EIndividuals {
	CLOUD = 'cloud',
	BARCODE = 'barcode',
	MOICA = 'moica',
	PAPER = 'paper',
}

export const individuals = [
	{
		value: EIndividuals.CLOUD,
		label: '雲端發票',
	},
	{
		value: EIndividuals.BARCODE,
		label: '手機條碼',
	},
	{
		value: EIndividuals.MOICA,
		label: '自然人憑證',
	},
	{
		value: EIndividuals.PAPER,
		label: '紙本發票',
	},
] as const
