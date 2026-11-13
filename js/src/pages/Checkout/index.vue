<script lang="ts" setup>
import { InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormInstance, FormRules } from 'element-plus'
import { pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import {
	PRICE_PLACEHOLDER,
	TCheckoutEnhancementSettings,
} from '@/pages/Checkout/Shared/types'

const providerId = 'checkout_enhancement'

/** 預覽用的範例差額 */
const PREVIEW_DIFF = '150'

const HEX_COLOR_REGEX = /^#[0-9a-fA-F]{6}$/

const { isPending, data } = useQuery({
	queryKey: ['settings', providerId],
	queryFn: async () =>
		await apiClient.get<{
			code: string
			message: string
			data: TCheckoutEnhancementSettings
		}>(`settings/${providerId}`),
	select: (res) => res.data?.data,
})

const formRef = ref<FormInstance>()

// 表單資料（預設值對齊 CheckoutEnhancementSettingsDTO::DEFAULTS）
const form = reactive<TCheckoutEnhancementSettings>({
	last_name_first: 'no',
	tw_address: 'no',
	tw_phone_validation: 'no',
	virtual_hide_address: 'no',
	virtual_auto_complete: 'no',
	free_shipping_hint: 'no',
	free_shipping_text_left: '再買 {price} 元即可享免運',
	free_shipping_text_achieved: '已享免運',
	free_shipping_bg_color: '#d36f6f',
	free_shipping_text_color: '#ffffff',
})

watch(
	data,
	(newData) => {
		if (newData) {
			// 只合併表單已知的鍵
			Object.assign(form, pick(newData, Object.keys(form)))
		}
	},
	{ immediate: true }
)

/** 開關分組（依實作計劃第 2 階段） */
type TToggleKey = Exclude<
	keyof TCheckoutEnhancementSettings,
	| 'free_shipping_text_left'
	| 'free_shipping_text_achieved'
	| 'free_shipping_bg_color'
	| 'free_shipping_text_color'
>

type TToggleItem = {
	key: TToggleKey
	label: string
	tooltip?: string
}

const toggleGroups: { title: string; items: TToggleItem[] }[] = [
	{
		title: '地址與姓名',
		items: [
			{
				key: 'last_name_first',
				label: '姓在前、名在後',
				tooltip: '國家為台灣時，結帳欄位改為「姓氏」在前、「名字」在後',
			},
			{
				key: 'tw_address',
				label: '台灣縣市 / 鄉鎮 / 郵遞區號',
				tooltip:
					'縣市改為 22 縣市下拉；鄉鎮下拉與郵遞區號自動帶入僅支援傳統結帳',
			},
		],
	},
	{
		title: '欄位檢查',
		items: [
			{
				key: 'tw_phone_validation',
				label: '台灣手機格式檢查',
				tooltip:
					'國家為台灣時，電話須為 09 開頭的 10 碼手機號碼（可含空白或連字號）',
			},
		],
	},
	{
		title: '虛擬商品',
		items: [
			{
				key: 'virtual_hide_address',
				label: '全虛擬購物車免填地址',
				tooltip: '購物車內全部為虛擬商品時，隱藏國家與地址欄位並改為非必填',
			},
			{
				key: 'virtual_auto_complete',
				label: '全虛擬訂單自動完成',
				tooltip: '訂單商品全部為虛擬商品時，轉為「處理中」後自動改為「已完成」',
			},
		],
	},
]

/** 預覽：未達門檻文案（以範例差額替換 {price}；由 Vue 文字插值跳脫） */
const previewTextLeft = computed(() =>
	form.free_shipping_text_left.split(PRICE_PLACEHOLDER).join(PREVIEW_DIFF)
)

const previewStyle = computed(() => ({
	backgroundColor: form.free_shipping_bg_color || undefined,
	color: form.free_shipping_text_color || undefined,
}))

const requiredText = { required: true, message: '此欄位為必填' }

const hexColorRule = {
	validator: (
		_rule: unknown,
		value: unknown,
		callback: (e?: Error) => void
	) => {
		if (typeof value === 'string' && HEX_COLOR_REGEX.test(value)) {
			callback()
			return
		}
		callback(new Error('請選擇 #RRGGBB 格式的顏色'))
	},
}

const rules = reactive<FormRules<TCheckoutEnhancementSettings>>({
	free_shipping_text_left: [
		requiredText,
		{
			// UX 用的前端檢查；後端 merge_valid 仍為最終把關
			validator: (_rule, value: string, callback) => {
				if (typeof value === 'string' && value.includes(PRICE_PLACEHOLDER)) {
					callback()
					return
				}
				callback(new Error(`須包含 ${PRICE_PLACEHOLDER}，用來顯示還差多少金額`))
			},
		},
	],
	free_shipping_text_achieved: [requiredText],
	free_shipping_bg_color: [hexColorRule],
	free_shipping_text_color: [hexColorRule],
})

const queryClient = useQueryClient()

// 成功 / 失敗通知皆由 API interceptor 統一處理
const { mutate: save, isPending: isSavePending } = useMutation({
	mutationFn: async (payload: TCheckoutEnhancementSettings) =>
		await apiClient.post(`settings/${providerId}`, payload),
	onSuccess: () => {
		queryClient.invalidateQueries({ queryKey: ['settings', providerId] })
	},
})

const onSubmit = async () => {
	if (!formRef.value) {
		return
	}
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save({ ...toRaw(form) })
		}
	})
}
</script>

<template>
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
			title="若同站 woomp 已開啟同名功能，請擇一啟用以免重複顯示或重複驗證"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<template v-for="group in toggleGroups" :key="group.title">
			<el-divider>{{ group.title }}</el-divider>

			<el-form-item
				v-for="item in group.items"
				:key="item.key"
				:prop="item.key"
			>
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>{{ item.label }}</span>
						<el-tooltip
							v-if="item.tooltip"
							:content="item.tooltip"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<el-switch
					v-model="form[item.key]"
					active-value="yes"
					inactive-value="no"
					:data-testid="`pc-checkout-${item.key}`"
				/>
			</el-form-item>
		</template>

		<el-divider>免運提示</el-divider>

		<el-form-item prop="free_shipping_hint">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>顯示「差多少免運」</span>
					<el-tooltip
						content="購物車與結帳頁顯示距離 WooCommerce 免運門檻還差多少金額"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-switch
				v-model="form.free_shipping_hint"
				active-value="yes"
				inactive-value="no"
				data-testid="pc-checkout-free_shipping_hint"
			/>
		</el-form-item>

		<el-form-item prop="free_shipping_text_left" label="未達門檻文案">
			<el-input
				v-model="form.free_shipping_text_left"
				data-testid="pc-checkout-free_shipping_text_left"
				clearable
			/>
			<div class="text-xs text-gray-400 mt-1">
				使用 {price} 代表還差的金額，例如「再買 {price} 元即可享免運」
			</div>
		</el-form-item>

		<el-form-item prop="free_shipping_text_achieved" label="已達門檻文案">
			<el-input
				v-model="form.free_shipping_text_achieved"
				data-testid="pc-checkout-free_shipping_text_achieved"
				clearable
			/>
		</el-form-item>

		<el-form-item prop="free_shipping_bg_color" label="背景顏色">
			<el-color-picker
				v-model="form.free_shipping_bg_color"
				:show-alpha="false"
			/>
		</el-form-item>

		<el-form-item prop="free_shipping_text_color" label="文字顏色">
			<el-color-picker
				v-model="form.free_shipping_text_color"
				:show-alpha="false"
			/>
		</el-form-item>

		<el-form-item label="預覽">
			<div
				class="w-full flex flex-col gap-y-2"
				data-testid="pc-checkout-preview"
			>
				<div class="px-4 py-2 rounded text-center" :style="previewStyle">
					{{ previewTextLeft }}
				</div>
				<div class="px-4 py-2 rounded text-center" :style="previewStyle">
					{{ form.free_shipping_text_achieved }}
				</div>
			</div>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button
				:loading="isSavePending"
				type="primary"
				data-testid="pc-checkout-save"
				@click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
