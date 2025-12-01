<script setup lang="ts">
import { ref } from 'vue'
import { useMutation } from '@tanstack/vue-query'
import apiClient from '@/api'
import { appData, isAdmin, MAPPER } from './index'
import Steps from './Steps/index.vue'
import { Tickets } from '@element-plus/icons-vue'

const dialogVisible = ref(false)

const { mutate: cancelInvoice, isPending: isCanceling } = useMutation({
	mutationFn: async (orderId: string) =>
		await apiClient.post(`/invoices/cancel/${orderId}`),
	onSuccess: () => {
		// alert('電子發票作廢成功')
		// window.location.reload()
	},
	onError: (err) => {
		console.error('作廢電子發票失敗', err)
	},
})

const handleCancel = () => {
	const orderId = appData?.order?.id
	cancelInvoice(orderId)
}
</script>

<template>
	<div
		class="flex gap-2 items-center text-md font-bold text-gray-700 mb-4"
		v-if="isAdmin && appData?.is_issued"
	>
		<el-icon><Tickets /></el-icon>
		<span>發票號碼：{{ appData?.invoice_number }}</span>
	</div>
	<div class="flex justify-between items-center">
		<el-button
			v-if="isAdmin && appData?.is_issued"
			type="danger"
			@click="handleCancel"
			:loading="isCanceling"
			>作廢發票</el-button
		>
		<el-button
			v-if="!appData?.is_issued"
			type="primary"
			@click="dialogVisible = true"
			>{{ MAPPER.ISSUE_INVOICE }}</el-button
		>
	</div>

	<el-dialog
		v-model="dialogVisible"
		:title="MAPPER.ISSUE_INVOICE"
		width="600"
		align-center
		:z-index="999999"
		class="p-8"
	>
		<Steps @close="dialogVisible = false" :dialogVisible="dialogVisible" />
	</el-dialog>
</template>

<style scoped></style>
