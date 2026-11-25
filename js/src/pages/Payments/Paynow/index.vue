<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'
import {
	EPaynowPaymentMethod,
	PAYNOW_PAYMENT_METHOD_LABELS,
} from '@/pages/Payments/Paynow/Shared/enums'
import { TFormData } from '@/pages/Payments/Paynow/Shared/types'

const gatewayId = 'paynow'
const isLocal = env?.IS_LOCAL ?? false

// 付款方式勾選清單（排除 ApplePayDeferred，與後端 enum 一致）
const paymentMethodOptions = Object.values(EPaynowPaymentMethod).map(
	(value) => ({
		value,
		label: PAYNOW_PAYMENT_METHOD_LABELS[value],
	})
)

const { isPending, data } = useQuery({
	queryKey: ['settings', gatewayId],
	queryFn: async () =>
		await apiClient.get<{
			code: string
			message: string
			data: TFormData
		}>(`settings/${gatewayId}`),
	select: (res) => res.data?.data,
})

// Element Plus 表單 ref
const formRef = ref()

// 表單資料（對齊 PaynowSettingsDTO，全 snake_case）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',
	order_button_text: '',
	min_amount: 0,
	max_amount: 0,

	// --- API --- //
	mode: 'test',
	public_key: '',
	private_key: '',

	// --- 付款方式與分期設定 --- //
	allowed_payment_methods: [EPaynowPaymentMethod.CREDIT_CARD],
	allow_installments: false,
	expire_days: 3,
})

watch(
	data,
	(newData) => {
		if (newData) {
			// 深層合併，只合併 form 存在的屬性
			const filteredData = pick(newData, Object.keys(form))
			if (!isLocal) {
				filteredData.mode = 'prod'
			}
			merge(form, filteredData)
		}
	},
	{ immediate: true }
)

const isTestMode = computed(() => form.mode === 'test')

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form)) // 呼叫 mutation
		}
	})
}

const queryClient = useQueryClient()

// 定義 mutation
const { mutate: save, isPending: isSavePending } = useMutation({
	mutationFn: async (payload: TFormData) =>
		await apiClient.post(`/settings/${gatewayId}`, payload),
	onSuccess: () => {
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
	onError: (err) => {
		console.error('更新失敗', err)
	},
})

const rules = reactive<FormRules<TFormData>>({
	public_key: [{ required: true, message: '此欄位為必填' }],
	private_key: [{ required: true, message: '此欄位為必填' }],
	allowed_payment_methods: [
		{
			type: 'array',
			required: true,
			message: '請至少勾選一種付款方式',
			trigger: 'change',
		},
	],
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/payments')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《金流》
	</div>

	<el-form
		ref="formRef"
		v-loading="isPending"
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		label-position="right"
		label-width="auto"
		:class="{
			'opacity-25': isPending,
		}"
		:rules="rules"
		style="max-width: 40rem"
	>
		<el-alert
			title="PayNow 站內付：顧客不用離開你的網站就能付款（信用卡、ATM、超商代碼、LINE Pay、Apple Pay）。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-divider>基本設定</el-divider>

		<el-form-item prop="title" label="顯示名稱">
			<el-input v-model="form.title" clearable />
		</el-form-item>
		<el-form-item prop="description" label="描述">
			<el-input v-model="form.description" clearable />
		</el-form-item>

		<el-form-item prop="order_button_text" label="結帳按鈕文字">
			<el-input v-model="form.order_button_text" clearable />
		</el-form-item>

		<el-form-item prop="min_amount">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>最小金額限制</span>
					<el-tooltip
						content="低於此金額，無法使用此付款方式，輸入 0 則不限制"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.min_amount"
				step="1000"
				:min="0"
				:max="10000000"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>NT$</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-form-item prop="max_amount">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>最大金額限制</span>
					<el-tooltip
						content="超過此金額，無法使用此付款方式，輸入 0 則不限制"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.max_amount"
				step="1000"
				:min="0"
				:max="10000000"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>NT$</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-divider>付款方式設定</el-divider>

		<el-form-item prop="allowed_payment_methods">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>允許的付款方式</span>
					<el-tooltip
						content="顧客結帳時可選用的付款方式（至少勾選一種）。離線付款（ATM / 超商代碼）需設定繳款天數。"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-checkbox-group v-model="form.allowed_payment_methods">
				<el-checkbox
					v-for="opt in paymentMethodOptions"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item prop="allow_installments" label="允許信用卡分期">
			<el-switch v-model="form.allow_installments" />
		</el-form-item>

		<el-form-item prop="expire_days">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>繳款天數</span>
					<el-tooltip
						content="離線付款（ATM / 超商代碼）的繳款期限天數（含當天）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.expire_days"
				:min="1"
				:max="60"
				align="right"
			/>
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-form-item
			:class="{
				'tw-hidden': !isLocal,
			}"
		>
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用測試模式</span>
					<el-tooltip
						content="開發人員專用，啟用後將使用 PayNow 沙箱環境（sandboxapi.paynow.com.tw）測試付款"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-switch
				v-model="form.mode"
				active-value="test"
				inactive-value="prod"
			/>
		</el-form-item>

		<el-alert
			v-if="!isTestMode"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		>
			<template #title>取得方式：</template>
			<ol class="pl-4 my-1 list-decimal">
				<li>
					寫信給 PayNow 客服（service@paynow.com.tw），主旨「申請 PayNow
					串接私鑰 (PrivateKey)」，附上商家帳號
				</li>
				<li>把 PayNow 提供的公鑰、私鑰貼到下方</li>
			</ol>
		</el-alert>

		<el-form-item
			:required="!isTestMode"
			prop="public_key"
			label="公鑰（PublicKey）"
		>
			<TrimmedInput
				v-model="form.public_key"
				:disabled="isTestMode"
				clearable
			/>
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="private_key">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>私鑰（PrivateKey）</span>
					<el-tooltip content="等同密碼，勿外流" placement="top">
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.private_key"
				:disabled="isTestMode"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
