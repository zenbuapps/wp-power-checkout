<script lang="ts" setup>
import { Back } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import { TFormData } from '@/pages/Payments/ShoplineEmbedded/Shared/types'
import { ROUTER_MAPPER } from '@/router'

const gatewayId = 'shopline_payment_embedded'

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

// 表單資料（對齊 EmbeddedSettingsDTO：只有顯示欄位）
const form = reactive<TFormData>({
	title: '',
	description: '',
	order_button_text: '',
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

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form))
		}
	})
}

const queryClient = useQueryClient()

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
	title: [{ required: true, message: '此欄位為必填' }],
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
			title="Shopline Payment (嵌入支付)：顧客於本站 order-received 頁的 SHOPLINE Payments 收銀台輸入信用卡完成付款（支援分期），付款結果以 SHOPLINE Payments Webhook 為準。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-alert type="warning" class="mb-4" :closable="false" show-icon>
			<template #title>
				商店憑證（特店 ID / API Key / Client Key / Sign Key）、測試 /
				正式模式與分期期數共用 SHOPLINE Payments 導轉式設定，請至
				<el-link
					type="primary"
					@click="$router.push(ROUTER_MAPPER.shopline_payment_redirect)"
				>
					SHOPLINE Payments 導轉式設定
				</el-link>
				填寫；嵌入式需要 Client Key，並請在 SHOPLINE Payments 後台訂閱 trade.*
				Webhook 事件。
			</template>
		</el-alert>

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

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
