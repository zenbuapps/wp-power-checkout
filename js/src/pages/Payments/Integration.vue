<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { computed, reactive, ref, toRaw, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import apiClient from '@/api'
import type { FormRules } from 'element-plus'
import { pick, merge } from 'lodash-es'

type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	order_button_text: string
	min_amount: number
	max_amount: number
	expire_min: number
	// --- API --- //
	mode: string
	// platformId?: string
	merchantId: string
	apiKey: string
	clientKey: string
	signKey: string
	allowPaymentMethodList: string[]
	paymentMethodOptions: {
		CreditCard: {
			installmentCounts: string[]
		}
		ChaileaseBNPL: {
			installmentCounts: string[]
		}
	}
}

const route = useRoute()
const gatewayId = route.params.id

const { isPending, data } = useQuery({
	queryKey: ['gateway_settings', gatewayId],
	queryFn: async () =>
		await apiClient.get<{
			code: string
			message: string
			data: TFormData
		}>(`gateways/${gatewayId}/settings`),
	select: (res) => res.data?.data,
})

// Element Plus 表單 ref
const formRef = ref()

// 表單資料
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',
	order_button_text: '',
	min_amount: 0,
	max_amount: 0,
	expire_min: 360,
	// --- API --- //
	mode: 'test',
	merchantId: '',
	apiKey: '',
	clientKey: '',
	signKey: '',
	allowPaymentMethodList: [],
	paymentMethodOptions: {
		CreditCard: {
			installmentCounts: [],
		},
		ChaileaseBNPL: {
			installmentCounts: [],
		},
	},
})

watch(
	data,
	(newData) => {
		if (newData) {
			// 深層合併，只合併 form 存在的屬性
			const filteredData = pick(newData, Object.keys(form))
			merge(form, filteredData)
			// 將 API 回傳資料輸入表單
		}
	},
	{ immediate: true },
)

const isTestMode = computed(() => form.mode === 'test')

const onSubmit = async () => {
	console.log('toRaw(form)', toRaw(form))
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
		await apiClient.post(`/gateways/${gatewayId}/settings`, payload),
	onSuccess: () => {
		// 成功後可刷新相關快取
		queryClient.invalidateQueries({ queryKey: ['gateway_settings', gatewayId] })
	},
	onError: (err) => {
		console.error('更新失敗', err)
	},
})

const rules = reactive<FormRules<TFormData>>({
	merchantId: [
		{ required: true, message: '此欄位為必填' },
	],
	apiKey: [
		{ required: true, message: '此欄位為必填' },
	],
	clientKey: [
		{ required: true, message: '此欄位為必填' },
	],
	signKey: [
		{ required: true, message: '此欄位為必填' },
	],
	allowPaymentMethodList: [
		{
			validator: (_, value, callback) => {
				if (Array.isArray(value) && value.length > 0) {
					callback()
				} else {
					callback(new Error('請至少選擇一種付款方式'))
				}
			},
		},
	],
})

const creditCardInstallment = [
	'0',
	'3',
	'6',
	'9',
	'12',
	'18',
	'24',
]
const chaileaseBNPLInstallment = [
	'0',
	'3',
	'6',
	'12',
	'18',
	'24',
	'30',
	'36',
]
const apiUrl = window.power_checkout_data.env.API_URL
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
		v-loading="isPending"
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		ref="formRef"
		label-position="right"
		label-width="auto"
		:class="{
			'opacity-25': isPending,
		}"
		:rules="rules"
		style="max-width: 40rem"
	>
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
		<el-form-item prop="expire_time">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>付款期限</span>
					<el-tooltip
						content="預設 6 小時，輸入 0 會套用預設值"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.expire_min"
				step="60"
				:min="60"
				:max="10000000"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>分鐘</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-form-item>
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用測試模式</span>
					<el-tooltip
						content="開發人員專用，啟用後將使用測試的串接碼測試付款"
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

		<!--		<el-form-item prop="platformId">-->
		<!--			<template #label>-->
		<!--				<span class="flex gap-x-2 items-center">-->
		<!--					<span>Platform Id</span>-->
		<!--					<el-tooltip-->
		<!--						content="SLP 平台 ID，平台特店必填，平台特店底下會有子特店"-->
		<!--						placement="top"-->
		<!--					>-->
		<!--						<el-icon><InfoFilled /></el-icon>-->
		<!--					</el-tooltip>-->
		<!--				</span>-->
		<!--			</template>-->
		<!--			<el-input v-model="form.platformId" :disabled="isTestMode" clearable />-->
		<!--		</el-form-item>-->

		<el-form-item :required="!isTestMode" prop="merchantId">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>Merchant Id</span>
					<el-tooltip
						content="直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input v-model="form.merchantId" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="apiKey" label="Api Key">
			<el-input v-model="form.apiKey" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="clientKey" label="Client Key">
			<el-input v-model="form.clientKey" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item
			:required="!isTestMode"
			prop="signKey"
			label="Sign Key"
			class="[&_p]:text-sm [&_p]:text-gray-500 [&_p]:mb-2 [&_p]:mt-0"
		>
			<el-input class="mb-4" v-model="form.signKey" clearable />
			<p>提供以下資訊給 Shopline 窗口後取得 Sign Key</p>
			<p>
				Webhook URL: <code>{{ apiUrl }}/power-checkout/slp/webhook</code>
			</p>
			<p>
				Webhook Event: <code>trade.refund.succeeded</code>,
				<code>trade.refund.failed</code>
			</p>
		</el-form-item>

		<el-form-item prop="allowPaymentMethodList" label="允許的付款方式">
			<el-checkbox-group v-model="form.allowPaymentMethodList">
				<el-checkbox label="CreditCard"> 信用卡 </el-checkbox>
				<el-checkbox label="VirtualAccount"> ATM 虛擬帳號 </el-checkbox>
				<el-checkbox label="JKOPay"> 街口支付 </el-checkbox>
				<el-checkbox label="ApplePay"> Apple Pay </el-checkbox>
				<el-checkbox label="LinePay"> Line Pay </el-checkbox>
				<el-checkbox label="ChaileaseBNPL"> 中租 </el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item
			v-if="form.allowPaymentMethodList.includes('CreditCard')"
			prop="paymentMethodOptions.CreditCard.installmentCounts"
			label="信用卡分期期數"
		>
			<el-checkbox-group
				v-model="form.paymentMethodOptions.CreditCard.installmentCounts"
			>
				<el-checkbox
					v-for="period in creditCardInstallment"
					:key="period"
					:label="period"
				>
					{{ period }}
				</el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item
			v-if="form.allowPaymentMethodList.includes('ChaileaseBNPL')"
			prop="paymentMethodOptions.ChaileaseBNPL.installmentCounts"
			label="中租分期期數"
		>
			<el-checkbox-group
				v-model="form.paymentMethodOptions.ChaileaseBNPL.installmentCounts"
			>
				<el-checkbox
					v-for="period in chaileaseBNPLInstallment"
					:key="period"
					:label="period"
				>
					{{ period }}
				</el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>

<style>
#power-checkout-wc-setting-app .el-divider__text {
	background-color: #f0f0f1;
}
</style>
