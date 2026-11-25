<!--
嘉里大榮（kerrytj_logistics）設定頁

- EDIEntrance：Basic Auth 帳號 + 密碼 + 客戶編號（測試 / 正式各一套，向嘉里大榮業務索取）。
- 純宅配：建單（我方配號）→ 列印標籤 / 託運明細 PDF → 查詢貨況（常溫 / 低溫分流）→ 修改託運資料 / 退貨取件。
- 號段：常溫 11 碼、低溫 9 碼流水號（或含檢查碼的 10 碼單號），系統依序原子配號；號段用罄建單會失敗。
- 「測試連線」以已儲存的帳密 POST UpdateShipmentDelivery 空陣列（不寫入任何資料）驗證 Basic Auth。
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
	KERRYTJ_SENDER_FIELD_LABELS,
	KERRYTJ_TEMPERATURE_OPTIONS,
	TFormData,
	TPingResult,
	TSettingsResponse,
	TWaybillStatus,
} from '@/pages/Logistics/Kerrytj/Shared/types'

const gatewayId = 'kerrytj_logistics'

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

const formRef = ref()

const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',

	// --- API --- //
	mode: 'test',
	username: '',
	password: '',
	customer_no: '',

	// --- 寄件人 --- //
	sender_name: '',
	sender_phone: '',
	sender_postcode: '',
	sender_address: '',

	// --- 號段 --- //
	ambient_waybill_start: '',
	ambient_waybill_end: '',
	cold_waybill_start: '',
	cold_waybill_end: '',

	// --- 託運單預設 --- //
	default_temperature: '1',
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

/** 號段狀態：ping 結果優先（最新），否則取設定頁 GET（僅 provider 啟用時有） */
const ambientStatus = computed<TWaybillStatus | null>(
	() =>
		pingResult.value?.ambient_waybill_status ??
		data.value?.ambient_waybill_status ??
		null
)
const coldStatus = computed<TWaybillStatus | null>(
	() =>
		pingResult.value?.cold_waybill_status ??
		data.value?.cold_waybill_status ??
		null
)

/** 測試連線成功但寄件人 / 客編缺漏的提示 */
const pingWarnings = computed<string[]>(() => {
	if (!pingResult.value) {
		return []
	}
	const warnings = pingResult.value.missing_sender_fields.map(
		(key) => KERRYTJ_SENDER_FIELD_LABELS[key] ?? key
	)
	if (!pingResult.value.customer_no_configured) {
		warnings.unshift('客戶編號')
	}
	return warnings
})

const statusTitle = (status: TWaybillStatus) =>
	status.remaining > 0
		? `剩餘 ${status.remaining} 號，下一號 ${status.next}`
		: '號段已用罄，請補新號段'

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form))
		}
	})
}

/** 驗證器：空白或符合條件 */
const optionalCheck = (check: (text: string) => boolean, message: string) => ({
	validator: (
		_rule: unknown,
		value: unknown,
		callback: (e?: Error) => void
	) => {
		const text = String(value ?? '').trim()
		callback(text === '' || check(text) ? undefined : new Error(message))
	},
	trigger: 'blur',
})

/** 低溫單號：10 碼且第 10 碼 = 前 9 碼 mod 7 */
const isValidColdBln = (text: string) =>
	/^\d{10}$/.test(text) && Number(text.slice(0, 9)) % 7 === Number(text[9])

/** 號段起訖 → 流水號（不合法回 null） */
const toSerial = (kind: 'ambient' | 'cold', raw: string): number | null => {
	const text = raw.trim()
	if (kind === 'ambient') {
		return /^\d{11}$/.test(text) ? Number(text) : null
	}
	if (/^\d{9}$/.test(text)) {
		return Number(text)
	}
	return isValidColdBln(text) ? Number(text.slice(0, 9)) : null
}

/** 號段起訖：須同時填寫或同時留空，且起號 ≤ 迄號 */
const rangeRule = (kind: 'ambient' | 'cold') => ({
	validator: (
		_rule: unknown,
		_value: unknown,
		callback: (e?: Error) => void
	) => {
		const start =
			kind === 'ambient' ? form.ambient_waybill_start : form.cold_waybill_start
		const end =
			kind === 'ambient' ? form.ambient_waybill_end : form.cold_waybill_end
		if (start.trim() === '' && end.trim() === '') {
			callback()
			return
		}
		const startSerial = toSerial(kind, start)
		const endSerial = toSerial(kind, end)
		if (startSerial === null || endSerial === null) {
			callback(
				new Error(
					kind === 'ambient'
						? '常溫號段起訖須同時填寫 11 碼數字'
						: '低溫號段起訖須同時填寫 9 碼流水號或檢查碼正確的 10 碼單號'
				)
			)
			return
		}
		callback(
			startSerial > endSerial ? new Error('起號不可大於迄號') : undefined
		)
	},
	trigger: 'blur',
})

const rules = reactive<FormRules<TFormData>>({
	username: [{ required: true, message: '此欄位為必填' }],
	password: [{ required: true, message: '此欄位為必填' }],
	customer_no: [
		{ required: true, message: '此欄位為必填' },
		optionalCheck((t) => /^\d{8}$/.test(t), '客戶編號須為 8 碼數字'),
	],
	sender_postcode: [
		optionalCheck((t) => /^\d{3,6}$/.test(t), '請填 3~6 碼郵遞區號'),
	],
	ambient_waybill_start: [rangeRule('ambient')],
	ambient_waybill_end: [rangeRule('ambient')],
	cold_waybill_start: [rangeRule('cold')],
	cold_waybill_end: [rangeRule('cold')],
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
			title="目前後台訂單頁沒有建單、列印、查貨況按鈕，出貨需工程師用程式串接；沒有工程師請先別啟用。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-alert
			title="嘉里大榮宅配：只支援宅配到府（常溫／冷藏／冷凍），不支援貨到付款；建單後無法在這裡取消。"
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
				<el-radio value="test">測試（EDIEntranceTest）</el-radio>
				<el-radio value="prod">正式（EDIEntrance）</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-alert
			title="測試與正式的帳號、密碼、客戶編號與號段各自一套，切換環境時須一併更換。貨況查詢主機只有一組（沒有測試網址），測試帳號可能無法查詢貨況。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="username" label="帳號">
			<TrimmedInput v-model="form.username" clearable />
		</el-form-item>

		<el-form-item prop="password" label="密碼">
			<TrimmedInput
				v-model="form.password"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-form-item prop="customer_no" label="客戶編號">
			<TrimmedInput v-model="form.customer_no" maxlength="8" clearable />
		</el-form-item>

		<el-form-item label="測試連線">
			<div class="flex flex-col gap-y-2 w-full">
				<div class="flex items-center gap-x-2">
					<el-button :loading="isPingPending" @click="ping()">
						測試連線
					</el-button>
					<span class="text-xs text-gray-500"
						>使用「已儲存」的帳號密碼驗證（不建立、不修改任何託運單）</span
					>
				</div>
				<el-alert
					v-if="pingResult"
					type="success"
					:closable="false"
					show-icon
					:title="`連線成功（${pingResult.mode === 'prod' ? '正式' : '測試'}環境）：帳號密碼驗證通過`"
				/>
				<el-alert
					v-if="pingWarnings.length"
					type="warning"
					:closable="false"
					show-icon
					:title="`尚未設定：${pingWarnings.join('、')}（建單前必填）`"
				/>
			</div>
		</el-form-item>

		<el-divider>寄件人（建單寄件人 / 退貨收件人）</el-divider>

		<el-form-item prop="sender_name">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>寄件人名稱</span>
					<el-tooltip
						content="標籤上只印得下約 12 字，超過會截斷"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input v-model="form.sender_name" clearable />
		</el-form-item>
		<el-form-item prop="sender_phone" label="寄件人電話">
			<el-input
				v-model="form.sender_phone"
				maxlength="20"
				placeholder="0223456789"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_postcode" label="郵遞區號">
			<el-input
				v-model="form.sender_postcode"
				maxlength="6"
				placeholder="104"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_address" label="寄件地址">
			<el-input v-model="form.sender_address" maxlength="60" clearable />
		</el-form-item>

		<el-divider>託運單號段</el-divider>

		<el-alert
			title="嘉里大榮不回傳單號，由系統依嘉里大榮核發的號段依序配號。常溫填 11 碼；低溫（冷藏 / 冷凍）填 9 碼流水號，或含檢查碼的 10 碼單號。退貨取件也會使用常溫號段。號段用罄時建單會失敗，請提前補新號段。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="ambient_waybill_start" label="常溫起號">
			<el-input
				v-model="form.ambient_waybill_start"
				maxlength="11"
				placeholder="例：40350718600"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="ambient_waybill_end" label="常溫迄號">
			<el-input
				v-model="form.ambient_waybill_end"
				maxlength="11"
				placeholder="例：40350719599"
				clearable
			/>
		</el-form-item>
		<el-form-item label="常溫號段狀態">
			<el-alert
				v-if="ambientStatus && ambientStatus.configured"
				:type="ambientStatus.remaining > 0 ? 'success' : 'error'"
				:closable="false"
				show-icon
				:title="statusTitle(ambientStatus)"
			/>
			<span v-else class="text-xs text-gray-500"
				>未設定；號段狀態於啟用後或測試連線時顯示</span
			>
		</el-form-item>

		<el-form-item prop="cold_waybill_start" label="低溫起號">
			<el-input
				v-model="form.cold_waybill_start"
				maxlength="10"
				placeholder="例：667000000"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="cold_waybill_end" label="低溫迄號">
			<el-input
				v-model="form.cold_waybill_end"
				maxlength="10"
				placeholder="例：667000999"
				clearable
			/>
		</el-form-item>
		<el-form-item label="低溫號段狀態">
			<el-alert
				v-if="coldStatus && coldStatus.configured"
				:type="coldStatus.remaining > 0 ? 'success' : 'error'"
				:closable="false"
				show-icon
				:title="statusTitle(coldStatus)"
			/>
			<span v-else class="text-xs text-gray-500"
				>未設定（未設定則無法建立冷藏 / 冷凍託運單）</span
			>
		</el-form-item>

		<el-divider>託運單預設</el-divider>

		<el-form-item prop="default_temperature">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>預設溫層</span>
					<el-tooltip
						content="訂單未指定時使用；結帳運送方式可各自設定（WooCommerce › 運送區域 › 嘉里大榮）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-select v-model="form.default_temperature">
				<el-option
					v-for="opt in KERRYTJ_TEMPERATURE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
