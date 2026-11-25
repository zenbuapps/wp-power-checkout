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
	PAYUNI_HOME_DELIVERY_TIME_OPTIONS,
	PAYUNI_HOME_SPEC_OPTIONS,
	PAYUNI_LGS_TYPE_OPTIONS,
	PAYUNI_SUB_TYPE_OPTIONS,
} from '@/pages/Logistics/Payuni/Shared/enums'
import {
	TFormData,
	TSettingsResponse,
} from '@/pages/Logistics/Payuni/Shared/types'

const gatewayId = 'payuni_logistics'
const isLocal = env?.IS_LOCAL ?? false

const { isPending, data } = useQuery({
	queryKey: ['settings', gatewayId],
	queryFn: async () =>
		await apiClient.get<{
			code: string
			message: string
			data: TSettingsResponse
		}>(`settings/${gatewayId}`),
	select: (res) => res.data?.data,
})

// Element Plus 表單 ref
const formRef = ref()

// 表單資料（對齊 PayuniLogisticsSettingsDTO）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',

	// --- API / 模式 --- //
	mode: 'prod',

	// --- 憑證（單一組） --- //
	mer_id: '',
	hash_key: '',
	hash_iv: '',

	// --- 物流方式與寄件人 --- //
	cvs_lgs_type: 'B2C',
	enabled_methods: [],
	sender_name: '',
	sender_mobile: '',

	// --- 黑貓宅配 --- //
	home_delivery_time_tag: '04',
	home_spec: '1',
})

// 後端算好的通知網址（唯讀，給商家貼到 PAYUNi 後台）
const notifyUrl = computed(() => data.value?.notify_url ?? '')
const isHomeEnabled = computed(() => form.enabled_methods.includes('HOME'))

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

// 正式模式才強制憑證必填（測試模式可填沙盒憑證，留空由 DTO 補官方文件測試金鑰）
const rules = computed<FormRules<TFormData>>(() => {
	if (isTestMode.value) {
		return {}
	}
	const requiredRule = [{ required: true, message: '此欄位為必填' }]
	return {
		mer_id: requiredRule,
		hash_key: requiredRule,
		hash_iv: requiredRule,
	}
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/logistics')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《物流》
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
			<el-input
				v-model="form.description"
				type="textarea"
				:rows="2"
				clearable
			/>
		</el-form-item>

		<el-divider>物流方式</el-divider>

		<el-form-item prop="enabled_methods">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用的物流方式</span>
					<el-tooltip
						content="勾選欲在結帳頁提供的取貨方式，7-ELEVEN 需消費者選店、宅配為黑貓配送"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.enabled_methods">
				<el-checkbox
					v-for="opt in PAYUNI_SUB_TYPE_OPTIONS"
					:key="opt.value"
					:label="opt.value"
				>
					{{ opt.label }}
				</el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item prop="cvs_lgs_type">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>超商物流型態</span>
					<el-tooltip
						content="B2C 大宗寄倉與 C2C 店到店；PAYUNi 兩者共用同一組憑證，僅切換 LgsType"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-radio-group v-model="form.cvs_lgs_type">
				<el-radio
					v-for="opt in PAYUNI_LGS_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
				>
					{{ opt.label }}
				</el-radio>
			</el-radio-group>
		</el-form-item>

		<template v-if="isHomeEnabled">
			<el-divider>黑貓宅配</el-divider>

			<el-form-item prop="home_delivery_time_tag" label="希望配達時段">
				<el-radio-group v-model="form.home_delivery_time_tag">
					<el-radio
						v-for="opt in PAYUNI_HOME_DELIVERY_TIME_OPTIONS"
						:key="opt.value"
						:value="opt.value"
					>
						{{ opt.label }}
					</el-radio>
				</el-radio-group>
			</el-form-item>

			<el-form-item prop="home_spec">
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>包裹尺寸</span>
						<el-tooltip
							content="列印託運單時帶給黑貓的包裹尺寸（長 + 寬 + 高）。冷凍、冷藏包裹最大 120 公分，選 150 會自動改成 120"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<el-select v-model="form.home_spec" style="width: 12rem">
					<el-option
						v-for="opt in PAYUNI_HOME_SPEC_OPTIONS"
						:key="opt.value"
						:label="opt.label"
						:value="opt.value"
					/>
				</el-select>
			</el-form-item>
		</template>

		<el-divider>寄件人資訊</el-divider>

		<el-form-item prop="sender_name" label="寄件人姓名">
			<el-input v-model="form.sender_name" clearable />
		</el-form-item>
		<el-form-item prop="sender_mobile" label="寄件人手機">
			<el-input v-model="form.sender_mobile" clearable />
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-alert
			v-if="notifyUrl"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		>
			<template #title>
				請到 PAYUNi
				後台「物流設定」把貨態通知網址設成下面這個，訂單才會自動更新物流狀態：
			</template>
			<code class="break-all select-all">{{ notifyUrl }}</code>
		</el-alert>

		<el-form-item
			:class="{
				'tw-hidden': !isLocal,
			}"
		>
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用測試模式</span>
					<el-tooltip
						content="開發人員專用：改連 PAYUNi 沙盒（sandbox-api.payuni.com.tw）。可填沙盒帳號實測；憑證留空時只能跑本機模擬"
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
			title="正式模式請填入 PAYUNi 後台的商店代號 MerID、HashKey、HashIV，否則無法建立物流單"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item :required="!isTestMode" prop="mer_id">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>商店代號 MerID</span>
					<el-tooltip
						content="PAYUNi 後台的商店代號（物流與金流共用）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.mer_id"
				:placeholder="
					isTestMode ? '測試模式可填 PAYUNi 沙盒帳號；留空只能跑本機模擬' : ''
				"
				clearable
			/>
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hash_key" label="HashKey">
			<TrimmedInput
				v-model="form.hash_key"
				:placeholder="
					isTestMode ? '測試模式可填 PAYUNi 沙盒帳號；留空只能跑本機模擬' : ''
				"
				clearable
			/>
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hash_iv" label="HashIV">
			<TrimmedInput
				v-model="form.hash_iv"
				:placeholder="
					isTestMode ? '測試模式可填 PAYUNi 沙盒帳號；留空只能跑本機模擬' : ''
				"
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
