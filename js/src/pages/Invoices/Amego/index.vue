<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { pick, merge } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import Checkbox from '@/components/Checkbox/index.vue'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'
import { TFormData } from '@/pages/Invoices/Amego/Shared/types'

const gatewayId = 'amego'
const isLocal = env?.IS_LOCAL ?? false

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

// 表單資料
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',

	// --- API --- //
	mode: 'prod',
	app_key: '',
	invoice: '', // 統一編號
	auto_issue_order_statuses: [],
	auto_cancel_order_statuses: ['wc-refunded'],
})

watch(
	data,
	(newData) => {
		if (newData) {
			// 深層合併，只合併 form 存在的屬性
			const filteredData = pick(newData, Object.keys(form))
			console.log({
				newData,
				filteredData,
			})
			if (!isLocal) {
				filteredData.mode = 'prod'
			}
			merge(form, filteredData)

			// 將 API 回傳資料輸入表單
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
		// 成功後可刷新相關快取
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
	onError: (err) => {
		console.error('更新失敗', err)
	},
})

const rules = reactive<FormRules<TFormData>>({
	app_key: [
		{ required: true, message: '此欄位為必填' },
	],
	invoice: [
		{ required: true, message: '此欄位為必填' },
	],
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/invoices')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《電子發票》
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
		<el-divider>基本設定</el-divider>

		<el-form-item prop="title" label="顯示名稱">
			<el-input v-model="form.title" clearable />
		</el-form-item>
		<el-form-item prop="description" label="描述">
			<el-input v-model="form.description" clearable />
		</el-form-item>

		<el-form-item prop="auto_issue_order_statuses">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>自動開立發票的訂單狀態</span>
					<el-tooltip
						content="都不勾選，就不自動開立，但可以在後台手動開立"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.auto_issue_order_statuses">
				<Checkbox
					v-for="orderStatus in env?.ORDER_STATUSES"
					:key="orderStatus.value"
					v-bind="orderStatus"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item prop="auto_cancel_order_statuses">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>自動作廢發票的訂單狀態</span>
					<el-tooltip
						content="都不勾選，就不自動作廢，但可以在後台手動作廢"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.auto_cancel_order_statuses">
				<Checkbox
					v-for="orderStatus in env?.ORDER_STATUSES"
					:key="orderStatus.value"
					v-bind="orderStatus"
				/>
			</el-checkbox-group>
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

		<el-alert type="info" class="mb-4" :closable="false" show-icon>
			<template #title>開始前你需要：</template>
			<ol class="pl-4 my-1 list-decimal">
				<li>
					先跟光貿（Amego）申請電子發票服務，並用你公司的統一編號開通帳號。
				</li>
				<li>
					聯絡光貿客服，說明要「API 串接」，請對方提供這個統編的「App Key」。
				</li>
				<li>
					把你公司的統一編號貼到「統一編號」欄位、光貿給你的 App Key 貼到「App
					Key」欄位。
				</li>
				<li>按最下方「儲存」。</li>
			</ol>
			<p class="my-1">沒填或填錯會無法開立發票。</p>
		</el-alert>

		<el-form-item :required="!isTestMode" prop="invoice">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>統一編號</span>
					<el-tooltip
						content="你公司（開發票的一方）的 8 位數統一編號，要跟在光貿開通的帳號相同"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.invoice" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="app_key">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>App Key</span>
					<el-tooltip
						content="光貿客服提供的 API 串接金鑰，等同密碼，請勿外流"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.app_key" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
