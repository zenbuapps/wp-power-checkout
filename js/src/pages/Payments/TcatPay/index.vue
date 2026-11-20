<!--
黑貓PAY（tcat_pay）設定頁

- 憑證：金流代號（cust_id = Token username）/ API 密碼 / hash_base（COCS 導轉 chk 密鑰）。
  黑貓PAY 沒有公開測試帳號，測試與正式環境帳密需分別向統一客樂得申請，兩種模式都必須填寫。
- 子方式：信用卡（導向黑貓PAY 刷卡頁）/ ibon 超商代碼 / ATM 虛擬帳號；單筆上限可依合約覆寫。
- 付款結果以「平台查詢」為準：APN 只當觸發，故黑貓PAY 後台的 APN 網址必須指向本站。
-->
<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import {
	ETcatPayMethod,
	TCAT_PAY_ACQUIRER_OPTIONS,
	TCAT_PAY_METHOD_LABELS,
	TFormData,
} from '@/pages/Payments/TcatPay/Shared/types'
import { SITE_URL } from '@/utils/env'

const gatewayId = 'tcat_pay'

const methodOptions = Object.values(ETcatPayMethod).map((value) => ({
	value,
	label: TCAT_PAY_METHOD_LABELS[value],
}))

const apnUrl = `${String(SITE_URL).replace(/\/$/, '')}/wp-json/power-checkout/tcat-pay/apn`

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

const formRef = ref()

const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',
	order_button_text: '',
	min_amount: 0,
	max_amount: 0,

	// --- API --- //
	mode: 'test',
	cust_id: '',
	api_password: '',
	hash_base: '',
	acquirer_type: 'payuni',
	limit_product_id: '',

	// --- 子方式 --- //
	enabled_methods: [ETcatPayMethod.CREDIT],
	expire_days: 3,
	max_amount_credit: '',
	max_amount_cvs: '',
	max_amount_atm: '',
})

watch(
	data,
	(newData) => {
		if (newData) {
			merge(form, pick(newData, Object.keys(form)))
		}
	},
	{ immediate: true }
)

const isTestMode = computed(() => form.mode === 'test')
const hasCredit = computed(() =>
	form.enabled_methods.includes(ETcatPayMethod.CREDIT)
)

const queryClient = useQueryClient()

const { mutate: save, isPending: isSavePending } = useMutation({
	mutationFn: async (payload: TFormData) =>
		await apiClient.post(`/settings/${gatewayId}`, payload),
	onSuccess: () => {
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
})

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form))
		}
	})
}

/** 上限欄位：空白（沿用預設）或 1~10,000,000 的整數 */
const maxAmountRule = {
	validator: (
		_rule: unknown,
		value: unknown,
		callback: (e?: Error) => void
	) => {
		const text = String(value ?? '').trim()
		if (text === '' || /^\d+$/.test(text)) {
			callback()
			return
		}
		callback(new Error('請輸入整數，留空則使用平台預設'))
	},
	trigger: 'blur',
}

const rules = reactive<FormRules<TFormData>>({
	cust_id: [{ required: true, message: '此欄位為必填' }],
	api_password: [{ required: true, message: '此欄位為必填' }],
	enabled_methods: [
		{
			type: 'array',
			required: true,
			message: '請至少勾選一種付款方式',
			trigger: 'change',
		},
	],
	max_amount_credit: [maxAmountRule],
	max_amount_cvs: [maxAmountRule],
	max_amount_atm: [maxAmountRule],
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
			title="黑貓PAY（統一客樂得）：信用卡導向黑貓PAY 刷卡頁；ibon / ATM 於下單後取得繳費代碼。付款結果一律以向黑貓PAY 平台查詢的結果為準（APN 只當通知觸發）。"
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

		<el-form-item prop="min_amount" label="最小金額限制">
			<el-input-number
				v-model="form.min_amount"
				step="1000"
				:min="0"
				:max="10000000"
				class="w-full"
			>
				<template #suffix><span>NT$</span></template>
			</el-input-number>
		</el-form-item>
		<el-form-item prop="max_amount" label="最大金額限制">
			<el-input-number
				v-model="form.max_amount"
				step="1000"
				:min="0"
				:max="10000000"
				class="w-full"
			>
				<template #suffix><span>NT$</span></template>
			</el-input-number>
		</el-form-item>

		<el-divider>付款方式</el-divider>

		<el-form-item prop="enabled_methods" label="啟用的付款方式">
			<el-checkbox-group v-model="form.enabled_methods">
				<el-checkbox
					v-for="opt in methodOptions"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item prop="expire_days">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>繳費天數</span>
					<el-tooltip
						content="ibon / ATM 取號後的繳費期限（自今日起算 1~30 天）；逾期不會自動取消訂單"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number v-model="form.expire_days" :min="1" :max="30" />
		</el-form-item>

		<el-form-item prop="max_amount_credit" label="信用卡單筆上限">
			<el-input
				v-model="form.max_amount_credit"
				placeholder="留空使用平台預設 100,000"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="max_amount_cvs" label="ibon 單筆上限">
			<el-input
				v-model="form.max_amount_cvs"
				placeholder="留空使用平台預設 20,000"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="max_amount_atm" label="ATM 單筆上限">
			<el-input
				v-model="form.max_amount_atm"
				placeholder="留空使用平台預設 49,999"
				clearable
			/>
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-form-item label="使用測試環境">
			<el-switch
				v-model="form.mode"
				active-value="test"
				inactive-value="prod"
			/>
		</el-form-item>

		<el-alert
			:title="
				isTestMode
					? '測試環境（test.4128888card.com.tw）：請填入黑貓PAY 核發的「測試」帳密，正式與測試資料不互通。'
					: '正式環境（cocs.4128888card.com.tw）：請填入黑貓PAY 核發的正式帳密。'
			"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="cust_id" label="金流代號（cust_id）">
			<TrimmedInput v-model="form.cust_id" clearable />
		</el-form-item>
		<el-form-item prop="api_password" label="API 密碼">
			<TrimmedInput
				v-model="form.api_password"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>
		<el-form-item prop="hash_base">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>hash_base</span>
					<el-tooltip
						content="信用卡授權導回驗章（chk）用的密鑰；未填時導回頁不做即時更新，付款結果改由 APN / 查詢補單確認"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.hash_base"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<template v-if="hasCredit">
			<el-form-item prop="acquirer_type" label="信用卡收單銀行">
				<el-select v-model="form.acquirer_type">
					<el-option
						v-for="opt in TCAT_PAY_ACQUIRER_OPTIONS"
						:key="opt.value"
						:value="opt.value"
						:label="opt.label"
					/>
				</el-select>
			</el-form-item>
			<el-form-item prop="limit_product_id">
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>限定產品別</span>
						<el-tooltip
							content="例：payuni.normal|payuni.m3；留空表示允許合約內所有產品別（含分期）"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<TrimmedInput v-model="form.limit_product_id" clearable />
			</el-form-item>
		</template>

		<el-form-item label="APN 網址">
			<el-input :model-value="apnUrl" readonly />
			<small class="text-gray-500"
				>請在黑貓PAY
				後台「APN-主動發送訊息網址」填入此網址（站台必須可由外部連線）</small
			>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
