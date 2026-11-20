<!--
新竹物流（hct_logistics）設定頁

- EDI WebServices（SOAP）：公司名稱 + 密碼（測試站 / 正式站分開，測試站帳號需向新竹申請）。
- 純宅配：建單（取得貨號 + 標籤圖）→ 列印標籤 → 查詢貨況（需申請查貨服務）→ 取消託運單 / 退貨。
- 號段：填入新竹核發的貨號區間（9 碼本體，不含檢查碼）即由系統依序原子配號；留空由新竹系統配號。
- 「測試連線」以已儲存的設定呼叫 QueryEDELNO（查一個不存在的訂單編號，唯讀）驗證帳密。
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
	HCT_PRODUCT_TYPE_OPTIONS,
	TFormData,
	TPingResult,
	TSettingsResponse,
	TWaybillStatus,
} from '@/pages/Logistics/Hct/Shared/types'

const gatewayId = 'hct_logistics'

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
	company: '',
	password: '',
	customer_code: '',

	// --- 出貨人 --- //
	sender_name: '',
	sender_tel: '',
	sender_address: '',

	// --- 號段 --- //
	waybill_start: '',
	waybill_end: '',

	// --- 託運單預設 --- //
	default_product_type: '001',
	default_weight: '1',

	// --- 貨況查詢 --- //
	track_offset_days: '',
	track_iv: '',
	track_v: '',
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
const waybillStatus = computed<TWaybillStatus | null>(
	() => pingResult.value?.waybill_status ?? data.value?.waybill_status ?? null
)

/** 主機 OpenSSL 不支援 des-ede3-cbc → 貨況查詢不可用 */
const trackCryptoUnavailable = computed(
	() =>
		pingResult.value?.track_crypto_available === false ||
		data.value?.track_crypto_available === false
)

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

/** 號段起訖：須同時填寫或同時留空，且起號 ≤ 迄號 */
const waybillRangeRule = {
	validator: (
		_rule: unknown,
		_value: unknown,
		callback: (e?: Error) => void
	) => {
		const start = form.waybill_start.trim()
		const end = form.waybill_end.trim()
		if (start === '' && end === '') {
			callback()
			return
		}
		if (start === '' || end === '') {
			callback(new Error('號段起號與迄號須同時填寫'))
			return
		}
		if (Number(start) < 1 || Number(start) > Number(end)) {
			callback(new Error('起號須 ≥ 1 且不可大於迄號'))
			return
		}
		callback()
	},
	trigger: 'blur',
}

/** 貨況查詢參數：三者須同時填寫或同時留空 */
const trackQueryRule = {
	validator: (
		_rule: unknown,
		_value: unknown,
		callback: (e?: Error) => void
	) => {
		const filled = [form.track_offset_days, form.track_iv, form.track_v].filter(
			(v) => v.trim() !== ''
		).length
		callback(
			filled === 0 || filled === 3
				? undefined
				: new Error('金鑰偏移天數、IV、v 值須同時填寫')
		)
	},
	trigger: 'blur',
}

const rules = reactive<FormRules<TFormData>>({
	company: [{ required: true, message: '此欄位為必填' }],
	password: [{ required: true, message: '此欄位為必填' }],
	customer_code: [optionalPattern(/^\d{11}$/, '客戶代號須為 11 碼數字')],
	waybill_start: [
		optionalPattern(/^\d{1,9}$/, '請填 9 碼以內數字（不含檢查碼）'),
		waybillRangeRule,
	],
	waybill_end: [
		optionalPattern(/^\d{1,9}$/, '請填 9 碼以內數字（不含檢查碼）'),
		waybillRangeRule,
	],
	default_weight: [
		{ required: true, message: '此欄位為必填' },
		optionalPattern(/^[1-9]\d{0,4}$/, '請填 1~99999 的整數'),
	],
	track_offset_days: [
		optionalPattern(/^-?\d{1,4}$/, '請填整數（可為負數）'),
		trackQueryRule,
	],
	track_iv: [optionalPattern(/^.{8}$/, 'IV 須為 8 位'), trackQueryRule],
	track_v: [optionalPattern(/^.{32}$/, 'v 值須為 32 位'), trackQueryRule],
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
			title="新竹物流 EDI 直串：目前只支援宅配。建單時會一併取得標籤圖（列印不再呼叫新竹）；新竹沒有貨態推送，貨況需申請查貨服務後於後台查詢。物流操作目前透過 REST API 進行。"
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
				<el-radio value="test">測試站（edi_webservice2_test）</el-radio>
				<el-radio value="prod">正式站（edi_webservice2）</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-alert
			title="測試站與正式站帳號不同：官方文件列的測試帳號（test / test1）實測屬正式環境，在測試站會回「「測試環境」請勿使用正式公司名稱或密碼」，測試站帳號請向新竹物流配合站所申請。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="company" label="公司名稱">
			<TrimmedInput v-model="form.company" clearable />
		</el-form-item>

		<el-form-item prop="password" label="密碼">
			<TrimmedInput
				v-model="form.password"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-form-item prop="customer_code">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>客戶代號</span>
					<el-tooltip
						content="選填。新竹客戶代號（11 碼）；留空使用帳號預設值"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.customer_code" clearable />
		</el-form-item>

		<el-form-item label="測試連線">
			<div class="flex flex-col gap-y-2 w-full">
				<div class="flex items-center gap-x-2">
					<el-button :loading="isPingPending" @click="ping()">
						測試連線
					</el-button>
					<span class="text-xs text-gray-500"
						>使用「已儲存」的公司名稱與密碼向新竹查詢（唯讀）</span
					>
				</div>
				<el-alert
					v-if="pingResult"
					type="success"
					:closable="false"
					show-icon
					:title="`連線成功（${pingResult.mode === 'prod' ? '正式站' : '測試站'}）：公司名稱與密碼驗證通過${pingResult.track_query_configured ? '，已設定貨況查詢' : '，尚未設定貨況查詢'}`"
				/>
			</div>
		</el-form-item>

		<el-divider>出貨人（留空使用新竹帳號預設）</el-divider>

		<el-form-item prop="sender_name" label="出貨人名稱">
			<el-input v-model="form.sender_name" maxlength="40" clearable />
		</el-form-item>
		<el-form-item prop="sender_tel" label="出貨人電話">
			<el-input
				v-model="form.sender_tel"
				maxlength="15"
				placeholder="0223456789"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_address">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>出貨地址</span>
					<el-tooltip
						content="貨件拒收時的退回地址；留空使用新竹帳號預設"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input v-model="form.sender_address" maxlength="100" clearable />
		</el-form-item>

		<el-divider>託運單號段</el-divider>

		<el-alert
			title="填入新竹物流核發的貨號區間（9 碼本體，不含最後一碼檢查碼），系統會依序配號並自動加上 MOD 7 檢查碼；起訖皆留空則由新竹系統配號。貨號 100 天內不可重複，號段用罄時建單會失敗，請提前補新號段。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="waybill_start" label="起號">
			<el-input
				v-model="form.waybill_start"
				maxlength="9"
				placeholder="例：100173483"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="waybill_end" label="迄號">
			<el-input
				v-model="form.waybill_end"
				maxlength="9"
				placeholder="例：100183482"
				clearable
			/>
		</el-form-item>
		<el-form-item label="號段狀態">
			<el-alert
				v-if="waybillStatus && waybillStatus.configured"
				:type="waybillStatus.remaining > 0 ? 'success' : 'error'"
				:closable="false"
				show-icon
				:title="
					waybillStatus.remaining > 0
						? `剩餘 ${waybillStatus.remaining} 號，下一號 ${waybillStatus.next}（號段 ${waybillStatus.start} ~ ${waybillStatus.end}）`
						: `號段已用罄（${waybillStatus.start} ~ ${waybillStatus.end}），請補新號段`
				"
			/>
			<span v-else class="text-xs text-gray-500"
				>未設定號段（由新竹系統配號）；號段狀態於啟用後或測試連線時顯示</span
			>
		</el-form-item>

		<el-divider>託運單預設</el-divider>

		<el-form-item prop="default_product_type">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>預設商品種類</span>
					<el-tooltip
						content="訂單未指定時使用；結帳運送方式可各自設定（WooCommerce › 運送區域 › 新竹物流）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-select v-model="form.default_product_type">
				<el-option
					v-for="opt in HCT_PRODUCT_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					:label="opt.label"
				/>
			</el-select>
		</el-form-item>
		<el-form-item prop="default_weight">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>預設重量（kg）</span>
					<el-tooltip
						content="商品未設定重量時使用；有重量時以商品重量 × 數量加總後無條件進位"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input v-model="form.default_weight" maxlength="5" clearable />
		</el-form-item>

		<el-divider>貨況查詢（選填）</el-divider>

		<el-alert
			v-if="trackCryptoUnavailable"
			title="此主機的 OpenSSL 不支援 des-ede3-cbc，無法使用新竹物流貨況查詢，請聯絡主機商。"
			type="error"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-alert
			title="貨況查詢需向新竹物流申請，申請後會提供「金鑰日期偏移天數」「IV（8 位）」「v 值（32 位）」三項參數；未設定時查詢貨況只會回「待處理」。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="track_offset_days" label="金鑰偏移天數">
			<el-input
				v-model="form.track_offset_days"
				placeholder="例：-33"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="track_iv" label="IV">
			<TrimmedInput
				v-model="form.track_iv"
				type="password"
				show-password
				maxlength="8"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="track_v" label="v 值">
			<TrimmedInput
				v-model="form.track_v"
				type="password"
				show-password
				maxlength="32"
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
