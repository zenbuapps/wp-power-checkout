<!--
順豐速運（sf_express）設定頁

- 順豐國際開放平台（IUOP）：appKey / appSecret / AES 金鑰（控制台應用）+ 客戶編碼 / 月結卡號（IUOP 後台）。
- 宅配：建單（取得運單號）→ 列印面單 → 查詢軌跡 → 取消（順豐收件前）；軌跡推送需與順豐聯調開通。
- 「測試連線」以已儲存的設定走完整鏈路（取 token → 加密 → 簽章 → 查一張不存在的運單，唯讀）。
- 台灣島內件（TW→TW）正式環境可否下單待順豐確認（R12）→ 頁首警示。
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
	SF_CARGO_TYPE_OPTIONS,
	TFormData,
	TPingResult,
	TSettingsResponse,
} from '@/pages/Logistics/SfExpress/Shared/types'

const gatewayId = 'sf_express'

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
	app_key: '',
	app_secret: '',
	encoding_aes_key: '',
	customer_code: '',
	month_card: '',
	product_code: 'INT0005',
	push_sign_key: '',

	// --- 寄件人 --- //
	sender_name: '',
	sender_mobile: '',
	sender_tel: '',
	sender_company: '',
	sender_postcode: '',
	sender_address: '',
	sender_cargo_type: '2',
	sender_tax_id: '',

	// --- 託寄物 --- //
	parcel_name: '日用品',
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

/** 推送接收網址（聯調時提供給順豐） */
const trackPushUrl = computed(
	() => pingResult.value?.track_push_url ?? data.value?.track_push_url ?? ''
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

const required = { required: true, message: '此欄位為必填' }

const rules = reactive<FormRules<TFormData>>({
	app_key: [required],
	app_secret: [required],
	encoding_aes_key: [
		required,
		optionalPattern(/^[A-Za-z0-9+/]{43}$/, 'AES 金鑰須為 43 個字元'),
	],
	customer_code: [required],
	month_card: [required],
	product_code: [
		required,
		optionalPattern(/^[A-Za-z0-9]{2,30}$/, '產品代碼格式不正確（例：INT0005）'),
	],
	sender_name: [required],
	sender_mobile: [required],
	sender_postcode: [
		required,
		optionalPattern(/^\d{3}(\d{2,3})?$/, '郵遞區號須為 3、5 或 6 碼數字'),
	],
	sender_address: [required],
	sender_tax_id: [optionalPattern(/^\d{8}$/, '統一編號須為 8 碼數字')],
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
			title="台灣島內件（台灣寄台灣）注意：順豐國際開放平台文件沒有島內件說明，沙箱雖可試算 / 校驗，但正式環境能否下單、該用哪個產品代碼，請先向順豐台灣客戶經理確認後再上線。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-alert
			title="目前後台訂單頁沒有建單、列印、查貨況按鈕，出貨需工程師用程式串接；沒有工程師請先別啟用。"
			type="warning"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-alert
			title="順豐速運宅配：只支援宅配到府，不支援貨到付款和退貨單；順豐收件前可取消。"
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
				<el-radio value="test">沙箱（api-ifsp-sit.sf.global）</el-radio>
				<el-radio value="prod">正式（api-ifsp.sf.global）</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-alert type="info" class="mb-4" :closable="false" show-icon>
			<template #title>取得方式：</template>
			<ol class="pl-4 my-1 list-decimal">
				<li>
					登入<a
						href="https://openapi.sf.global/"
						target="_blank"
						rel="noopener noreferrer"
						>順豐開放平台</a
					>，點「應用列表 &gt; 詳情」
				</li>
				<li>複製 appKey、appSecret、AES 金鑰，貼到下方</li>
				<li>
					登入
					<a
						href="https://iuop.sf-express.com"
						target="_blank"
						rel="noopener noreferrer"
						>IUOP 後台</a
					>，複製客戶編碼、月結卡號（月結卡號需請順豐業務綁定）
				</li>
			</ol>
			<p class="my-1">※ 沙箱與正式的值不同，別混用。</p>
		</el-alert>

		<el-form-item prop="app_key" label="appKey">
			<TrimmedInput v-model="form.app_key" clearable />
		</el-form-item>
		<el-form-item prop="app_secret" label="appSecret">
			<TrimmedInput
				v-model="form.app_secret"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>
		<el-form-item prop="encoding_aes_key" label="AES 金鑰">
			<TrimmedInput
				v-model="form.encoding_aes_key"
				type="password"
				show-password
				maxlength="43"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="customer_code" label="客戶編碼">
			<TrimmedInput v-model="form.customer_code" clearable />
		</el-form-item>
		<el-form-item prop="month_card" label="月結卡號">
			<TrimmedInput
				v-model="form.month_card"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>
		<el-form-item prop="product_code">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>產品代碼</span>
					<el-tooltip
						content="interProductCode，例：INT0005 順豐特快；實際可用產品以順豐簽約為準"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.product_code" clearable />
		</el-form-item>

		<el-form-item label="測試連線">
			<div class="flex flex-col gap-y-2 w-full">
				<div class="flex items-center gap-x-2">
					<el-button :loading="isPingPending" @click="ping()">
						測試連線
					</el-button>
					<span class="text-xs text-gray-500"
						>使用「已儲存」的憑證取 token 並查詢一張不存在的運單（唯讀）</span
					>
				</div>
				<el-alert
					v-if="pingResult"
					type="success"
					:closable="false"
					show-icon
					:title="`連線成功（${pingResult.mode === 'prod' ? '正式' : '沙箱'}）：appKey / appSecret / AES 金鑰驗證通過；客戶編碼無法以唯讀接口驗證，請以實際建單確認`"
				/>
			</div>
		</el-form-item>

		<el-divider>軌跡推送（選填）</el-divider>

		<el-alert
			title="軌跡推送需 email 順豐客戶經理（並抄送 open_intl@sf-express.com）申請聯調，聯調時順豐會提供簽章金鑰；伺服器有防火牆時需開放順豐推送 IP。未設定金鑰時推送一律拒收，貨態改由後台查詢更新。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>
		<el-form-item label="推送接收網址">
			<el-input :model-value="trackPushUrl" readonly />
		</el-form-item>
		<el-form-item prop="push_sign_key" label="推送簽章金鑰">
			<TrimmedInput
				v-model="form.push_sign_key"
				type="password"
				show-password
				clearable
			/>
		</el-form-item>

		<el-divider>寄件人</el-divider>

		<el-form-item prop="sender_name" label="寄件人姓名">
			<el-input v-model="form.sender_name" maxlength="50" clearable />
		</el-form-item>
		<el-form-item prop="sender_mobile" label="寄件人手機">
			<el-input
				v-model="form.sender_mobile"
				maxlength="20"
				placeholder="0912345678"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_tel" label="寄件人市話">
			<el-input
				v-model="form.sender_tel"
				maxlength="20"
				placeholder="選填，例：0223456789"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_company" label="寄件公司">
			<el-input v-model="form.sender_company" maxlength="100" clearable />
		</el-form-item>
		<el-form-item prop="sender_postcode" label="寄件郵遞區號">
			<el-input
				v-model="form.sender_postcode"
				maxlength="6"
				placeholder="例：10048"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_address">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>寄件地址</span>
					<el-tooltip
						content="請以縣市 + 鄉鎮市區開頭（例：臺北市中正區重慶南路一段1號），用來換算順豐英文區劃"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input
				v-model="form.sender_address"
				maxlength="200"
				placeholder="臺北市中正區重慶南路一段1號"
				clearable
			/>
		</el-form-item>
		<el-form-item prop="sender_cargo_type" label="寄件類型">
			<el-radio-group v-model="form.sender_cargo_type">
				<el-radio
					v-for="opt in SF_CARGO_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
					>{{ opt.label }}</el-radio
				>
			</el-radio-group>
		</el-form-item>
		<el-form-item
			v-if="form.sender_cargo_type === '2'"
			prop="sender_tax_id"
			label="統一編號"
		>
			<el-input
				v-model="form.sender_tax_id"
				maxlength="8"
				placeholder="選填，公司件可帶統編（證件類型 009）"
				clearable
			/>
		</el-form-item>

		<el-divider>託寄物</el-divider>

		<el-form-item prop="parcel_name">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>品名</span>
					<el-tooltip
						content="面單上的託寄物品名；不可包含底線（_ 會自動換成空白）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input v-model="form.parcel_name" maxlength="50" clearable />
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
