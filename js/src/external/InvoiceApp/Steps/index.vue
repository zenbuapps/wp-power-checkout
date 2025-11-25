<script setup lang="ts">
import { reactive, ref, toRaw } from 'vue'
import { TFormData } from '@/external/InvoiceApp/Shared/types'
import {
	EIndividuals,
	EInvoiceTypes,
	individuals,
	invoiceTypes,
} from '@/external/InvoiceApp/Shared/constants'
import { useMutation } from '@tanstack/vue-query'
import apiClient from '@/api'
import { appData } from '@/external/InvoiceApp'

const active = ref(0)

// Element Plus 表單 ref
const formRef = ref()

// 表單資料
const form = reactive<TFormData>({
	provider: '', // 服務提供商
	invoiceType: undefined, // 發票類型
	individual: undefined, // 個人發票類型 雲端載具、手機條碼、自然人憑證
	carrier: '', // 載具
	moica: '', //自然人憑證
	companyName: '',
	companyId: '',
	donateCode: '', // 捐贈碼
})

const prev = () => {
	if (active.value-- < 0) active.value = 0
}
const next = () => {
	if (active.value++ > 2) active.value = 0
}

const { mutate: issueInvoice, isPending } = useMutation({
	mutationFn: async ({ orderId, data }: { orderId: string; data: TFormData }) =>
		await apiClient.post(`/invoices/issue/${orderId}`, data),
	onError: (err) => {
		console.error('發行電子發票失敗', err)
	},
})

const handleIssue = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (form.invoiceType === EInvoiceTypes.DONATE) {
			form.carrier = ''
			form.moica = ''
			form.companyName = ''
			form.companyId = ''
		}

		if (form.invoiceType === EInvoiceTypes.COMPANY) {
			form.carrier = ''
			form.moica = ''
			form.donateCode = ''
		}

		if (form.invoiceType === EInvoiceTypes.INDIVIDUAL) {
			if (
				form.individual === EIndividuals.CLOUD ||
				form.individual === EIndividuals.PAPER
			) {
				form.carrier = ''
				form.moica = ''
			}

			if (form.individual === EIndividuals.BARCODE) {
				form.moica = ''
			}

			if (form.individual === EIndividuals.MOICA) {
				form.carrier = ''
			}

			form.companyName = ''
			form.companyId = ''
			form.donateCode = ''
		}

		if (!valid) {
			return
		}

		console.log(toRaw(form))
		const orderId = appData?.order?.id
		const isAdmin = appData?.is_admin // 是否在後台 還是 前台 checkout
		if (isAdmin) {
			issueInvoice({
				orderId,
				data: toRaw(form),
			})
		}
		// if (valid) {
		// 	save(toRaw(form)) // 呼叫 mutation
		// }
	})
}
</script>

<template>
	<el-steps :active="active" finish-status="finish" align-center class="my-8">
		<el-step title="選擇服務提供商" />
		<el-step title="選擇發票種類" />
		<el-step title="填寫發票資料" />
	</el-steps>

	<el-form
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		ref="formRef"
		label-position="top"
		label-width="auto"
		class="mb-8"
	>
		<!-- 選擇發票服務提供商 -->
		<el-form-item
			:class="{
				'tw-hidden': active !== 0,
			}"
			prop="provider"
		>
			<el-radio-group
				v-model="form.provider"
				class="grid grid-cols-3 gap-4 [&_label]:w-full w-full"
			>
				<el-radio value="1" border>光茂電子發票</el-radio>
				<el-radio value="2" border>綠界電子發票</el-radio>
				<el-radio value="3" border>藍新電子發票</el-radio>
				<el-radio value="4" border>PAYNOW 電子發票</el-radio>
				<el-radio value="5" border>其他電子發票 A</el-radio>
				<el-radio value="6" border>其他電子發票 B</el-radio>
			</el-radio-group>
		</el-form-item>

		<!-- 選擇發票種類 -->
		<el-form-item
			:class="{
				'tw-hidden': active !== 1,
			}"
			prop="invoiceType"
		>
			<el-radio-group
				v-model="form.invoiceType"
				class="grid grid-cols-3 gap-4 [&_label]:w-full w-full"
			>
				<el-radio v-for="item in invoiceTypes" :value="item.value" border>{{
					item.label
				}}</el-radio>
			</el-radio-group>
		</el-form-item>

		<div
			:class="{
				'tw-hidden': active !== 2,
			}"
		>
			<el-form-item
				prop="individual"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceTypes.INDIVIDUAL,
				}"
			>
				<el-radio-group
					v-model="form.individual"
					class="grid grid-cols-3 gap-4 [&_label]:w-full w-full"
				>
					<el-radio v-for="item in individuals" :value="item.value" border>{{
						item.label
					}}</el-radio>
				</el-radio-group>
			</el-form-item>

			<el-form-item
				prop="carrier"
				label="載具"
				:class="{
					'tw-hidden': form.individual !== EIndividuals.BARCODE,
				}"
			>
				<el-input v-model="form.carrier" clearable />
			</el-form-item>
			<el-form-item
				prop="moica"
				label="自然人憑證"
				:class="{
					'tw-hidden': form.individual !== EIndividuals.MOICA,
				}"
			>
				<el-input v-model="form.moica" clearable />
			</el-form-item>
			<el-form-item
				prop="companyName"
				label="公司名稱"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceTypes.COMPANY,
				}"
			>
				<el-input v-model="form.companyName" clearable />
			</el-form-item>
			<el-form-item
				prop="companyId"
				label="統一編號"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceTypes.COMPANY,
				}"
			>
				<el-input v-model="form.companyId" clearable />
			</el-form-item>
			<el-form-item
				prop="donateCode"
				label="捐贈碼"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceTypes.DONATE,
				}"
			>
				<el-input v-model="form.donateCode" clearable />
			</el-form-item>
		</div>
	</el-form>

	<div class="flex justify-between items-center">
		<el-button
			@click="prev"
			:class="{
				'opacity-0': active === 0,
			}"
			>上一步</el-button
		>
		<el-button
			@click="next"
			:class="{
				'tw-hidden': active === 2,
			}"
			:disabled="
				(active === 0 && !form.provider) || (active === 1 && !form.invoiceType)
			"
			>下一步</el-button
		>
		<el-button
			@click="handleIssue"
			:class="{
				'tw-hidden': active !== 2,
			}"
			type="primary"
			:loading="isPending"
			>開立發票</el-button
		>
	</div>
</template>

<style></style>
