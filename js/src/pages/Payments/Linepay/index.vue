<!--
LINE Pay 直連（linepay）設定頁

- 憑證：Channel ID / Channel Secret（LINE Pay 合作商店中心 › 開發者工具 › 管理連結金鑰）。
  官方沒有公開測試 Channel：sandbox 需自助申請，sandbox 與正式憑證不可混用（混用回 1104）。
- LINE Pay 沒有 webhook：顧客認證後由導回網址（confirmUrl）完成扣款；
  顧客關閉瀏覽器時由系統排程（Action Scheduler）約 5 分鐘後自動補單，後台訂單另有「查詢補單」操作。
-->
<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { TFormData } from '@/pages/Payments/Linepay/Shared/types'

const gatewayId = 'linepay'

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
	channel_id: '',
	channel_secret: '',
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

const rules = reactive<FormRules<TFormData>>({
	channel_id: [
		{ required: true, message: '此欄位為必填' },
		{
			pattern: /^\d+$/,
			message: 'Channel ID 只能為數字',
			trigger: 'blur',
		},
	],
	channel_secret: [{ required: true, message: '此欄位為必填' }],
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
			title="LINE Pay（Online API v4）：使用商家自己向 LINE Pay 申請的 Channel ID / Channel Secret，顧客導向 LINE Pay 付款頁，自動請款。與 SHOPLINE Payments 等「代收 LINE Pay」無關。"
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
				:max="100000000"
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
				:max="100000000"
				class="w-full"
			>
				<template #suffix><span>NT$</span></template>
			</el-input-number>
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
					? '測試環境（sandbox-api-pay.line.me）：請填入自助申請的 sandbox Channel ID / Secret；sandbox 付款頁需以個人 LINE 帳號登入。'
					: '正式環境（api-pay.line.me）：請填入合作商店中心「管理連結金鑰」的正式 Channel ID / Secret。'
			"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="channel_id" label="Channel ID">
			<TrimmedInput v-model="form.channel_id" clearable />
		</el-form-item>
		<el-form-item prop="channel_secret">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>Channel Secret</span>
					<el-tooltip
						content="只用於簽章（HMAC-SHA256），不會送出到任何地方"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.channel_secret"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-alert
			title="LINE Pay 沒有付款通知（webhook）：顧客認證後會被導回本站完成扣款；若顧客中途關閉瀏覽器，系統約 5 分鐘後自動查詢補單（需 WP-Cron / Action Scheduler 正常運作），亦可在訂單頁使用「LINE Pay 重新查詢付款狀態（補單）」。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
