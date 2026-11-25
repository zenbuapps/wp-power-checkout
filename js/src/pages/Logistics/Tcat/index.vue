<!--
黑貓宅急便直串（tcat_logistics）設定頁

- 統一速達標準印單 API：契客代號 + 授權碼（測試 / 正式各一組，需到契客專區分別發送；沒有公開測試帳號）。
- 第一版只做宅配：建單（配號）→ 下載託運單 PDF（24 小時內）→ 查詢貨態（同單號 2 小時查一次）→ 逆物流退貨預約。
- 「測試連線」以已儲存的設定呼叫 ParsingAddress（查寄件地址郵號，唯讀）。
-->
<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import {
	TCAT_DELIVERY_TIME_OPTIONS,
	TCAT_FORBIDDEN_PRODUCT_WORDS,
	TCAT_PRINT_OBT_TYPE_OPTIONS,
	TCAT_PRODUCT_TYPE_OPTIONS,
	TCAT_SPEC_OPTIONS,
	TCAT_THERMOSPHERE_OPTIONS,
	TFormData,
	TPingResult,
} from '@/pages/Logistics/Tcat/Shared/types'

const gatewayId = 'tcat_logistics'

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

	// --- API --- //
	mode: 'test',
	customer_id: '',
	customer_token: '',

	// --- 寄件人 --- //
	sender_name: '',
	sender_tel: '',
	sender_mobile: '',
	sender_address: '',
	sender_zip_code: '',

	// --- 託運單預設 --- //
	print_obt_type: '01',
	default_thermosphere: '0001',
	default_spec: '0001',
	product_type_id: '0015',
	product_name: '商品',
	delivery_time: '04',
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

const queryClient = useQueryClient()

const { mutate: save, isPending: isSavePending } = useMutation({
	mutationFn: async (payload: TFormData) =>
		await apiClient.post(`/settings/${gatewayId}`, payload),
	onSuccess: () => {
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
})

const pingResult = ref<TPingResult | null>(null)

const { mutate: ping, isPending: isPingPending } = useMutation({
	mutationFn: async () =>
		await apiClient.post<{ code: string; message: string; data: TPingResult }>(
			`logistics/${gatewayId}/ping`
		),
	onMutate: () => {
		pingResult.value = null
	},
	onSuccess: (res) => {
		pingResult.value = res.data?.data ?? null
	},
})

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form))
		}
	})
}

/** 驗證器：空白或符合 regex */
const optionalPattern = (pattern: RegExp, message: string) => ({
	validator: (
		_rule: unknown,
		value: unknown,
		callback: (e?: Error) => void
	) => {
		const text = String(value ?? '').trim()
		if (text === '' || pattern.test(text)) {
			callback()
			return
		}
		callback(new Error(message))
	},
	trigger: 'blur',
})

/** 寄件人電話 / 手機擇一必填 */
const senderPhoneRule = {
	validator: (
		_rule: unknown,
		_value: unknown,
		callback: (e?: Error) => void
	) => {
		if (form.sender_tel.trim() === '' && form.sender_mobile.trim() === '') {
			callback(new Error('寄件人電話與手機須擇一填寫'))
			return
		}
		callback()
	},
	trigger: 'blur',
}

const rules = reactive<FormRules<TFormData>>({
	customer_id: [
		{ required: true, message: '此欄位為必填' },
		optionalPattern(
			/^([0-9A-Za-z]{10}|[0-9A-Za-z]{12})$/,
			'契客代號須為 10 或 12 碼'
		),
	],
	customer_token: [{ required: true, message: '此欄位為必填' }],
	sender_name: [{ required: true, message: '此欄位為必填' }],
	sender_address: [{ required: true, message: '此欄位為必填' }],
	sender_tel: [senderPhoneRule],
	sender_mobile: [
		senderPhoneRule,
		optionalPattern(/^09\d{8}$/, '手機須為 09 開頭 10 碼'),
	],
	product_name: [
		{ required: true, message: '此欄位為必填' },
		{ max: 20, message: '最多 20 字' },
		{
			validator: (
				_rule: unknown,
				value: unknown,
				callback: (e?: Error) => void
			) => {
				const text = String(value ?? '')
				const hit = TCAT_FORBIDDEN_PRODUCT_WORDS.find((word) =>
					text.includes(word)
				)
				callback(hit ? new Error(`不可包含黑貓禁用字串「${hit}」`) : undefined)
			},
			trigger: 'blur',
		},
	],
	default_spec: [
		{
			validator: (
				_rule: unknown,
				value: unknown,
				callback: (e?: Error) => void
			) => {
				if (value === '0004' && form.default_thermosphere !== '0001') {
					callback(new Error('冷藏 / 冷凍包裹不可為 150cm'))
					return
				}
				callback()
			},
			trigger: 'change',
		},
	],
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
		<el-alert
			title="注意：目前 WordPress 後台的訂單頁還沒有「建立物流單／列印標籤／查詢貨況」按鈕。啟用後顧客可以在結帳時選這個運送方式，但出貨要由工程師用程式串接處理。如果你沒有工程師協助，請先不要啟用。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-alert
			title="黑貓宅急便宅配（直接連到統一速達系統）：目前只支援宅配到府。物流單建立後請在 24 小時內下載託運單 PDF。同一張託運單 2 小時內只能查一次貨況，期間會顯示上一次的結果。"
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
			<el-input
				v-model="form.description"
				type="textarea"
				:rows="2"
				clearable
			/>
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-form-item prop="mode" label="環境">
			<el-radio-group v-model="form.mode">
				<el-radio value="test">測試站（egs.suda.com.tw）</el-radio>
				<el-radio value="prod">正式站（api.suda.com.tw）</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-alert
			title="測試站與正式站的授權碼不同，請到統一速達契客專區「取得印單 API 授權碼」分別發送，切換環境時須同步更換授權碼。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="customer_id">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>契客代號</span>
					<el-tooltip
						content="統一速達契約客戶代號（10 或 12 碼）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.customer_id" clearable />
		</el-form-item>

		<el-form-item prop="customer_token" label="授權碼">
			<TrimmedInput
				v-model="form.customer_token"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-form-item label="測試連線">
			<div class="flex flex-col gap-y-2 w-full">
				<div class="flex items-center gap-x-2">
					<el-button :loading="isPingPending" @click="ping()">
						測試連線
					</el-button>
					<span class="text-xs text-gray-500"
						>使用「已儲存」的設定查詢寄件地址郵號</span
					>
				</div>
				<el-alert
					v-if="pingResult"
					:type="pingResult.deliverable ? 'success' : 'warning'"
					:closable="false"
					show-icon
					:title="
						pingResult.deliverable
							? `連線成功（${pingResult.mode === 'prod' ? '正式站' : '測試站'}）：${pingResult.address} → 郵號 ${pingResult.post_number}（寄件郵號 ${pingResult.zip_code}）`
							: `連線成功，但「${pingResult.address}」查無可配送郵號（${pingResult.post_number || '空白'}），請確認寄件地址`
					"
				/>
				<el-alert
					v-if="pingResult && !pingResult.credentials_verified"
					type="info"
					:closable="false"
					show-icon
					title="這個測試只確認「網站連得上黑貓」，還不能確認你的「契客代號」與「授權碼」是否正確；要等第一次實際建立物流單才知道。"
				/>
			</div>
		</el-form-item>

		<el-divider>寄件人（集貨地址）</el-divider>

		<el-form-item prop="sender_name" label="寄件人姓名">
			<el-input v-model="form.sender_name" clearable />
		</el-form-item>
		<el-form-item prop="sender_tel" label="寄件人電話">
			<el-input v-model="form.sender_tel" placeholder="02-87654321" clearable />
		</el-form-item>
		<el-form-item prop="sender_mobile" label="寄件人手機">
			<el-input
				v-model="form.sender_mobile"
				placeholder="0912345678"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_address" label="寄件人地址">
			<el-input v-model="form.sender_address" clearable />
		</el-form-item>
		<el-form-item prop="sender_zip_code">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>寄件人郵號</span>
					<el-tooltip
						content="選填。黑貓六碼郵號（如 71-802-24-B 填入後自動取末六碼 80224B）；留空則建單時以寄件地址向黑貓查詢"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input
				v-model="form.sender_zip_code"
				placeholder="留空自動查詢"
				clearable
			/>
		</el-form-item>

		<el-divider>託運單預設</el-divider>

		<el-form-item prop="print_obt_type" label="託運單版型">
			<el-select v-model="form.print_obt_type">
				<el-option
					v-for="opt in TCAT_PRINT_OBT_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="default_thermosphere">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>預設溫層</span>
					<el-tooltip
						content="訂單未指定溫層時使用；結帳運送方式可各自設定溫層（WooCommerce › 運送區域 › 黑貓宅急便）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-select v-model="form.default_thermosphere">
				<el-option
					v-for="opt in TCAT_THERMOSPHERE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="default_spec" label="預設規格">
			<el-select v-model="form.default_spec">
				<el-option
					v-for="opt in TCAT_SPEC_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="delivery_time" label="希望配達時段">
			<el-select v-model="form.delivery_time">
				<el-option
					v-for="opt in TCAT_DELIVERY_TIME_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="product_type_id" label="商品類別">
			<el-select v-model="form.product_type_id">
				<el-option
					v-for="opt in TCAT_PRODUCT_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="product_name">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>替代商品名稱</span>
					<el-tooltip
						content="託運單商品名稱預設取訂單第一個商品；商品名含黑貓禁用字串（如「訂單」「發票」「資料」）時改用此名稱"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input
				v-model="form.product_name"
				maxlength="20"
				show-word-limit
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
