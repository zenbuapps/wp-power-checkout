<script setup lang="ts">
import { ref } from 'vue'
import { useMutation } from '@tanstack/vue-query'
import apiClient from '@/api'
import { appData } from './index'
import Steps from './Steps/index.vue'

const dialogVisible = ref(false)

const { mutate: cancelInvoice, isPending: isCanceling } = useMutation({
	mutationFn: async (orderId: string) =>
		await apiClient.post(`/invoices/cancel/${orderId}`),
	onSuccess: () => {},
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
	<div class="flex justify-between items-center">
		<el-button type="danger" @click="handleCancel" :loading="isCanceling"
			>作廢發票</el-button
		>
		<el-button type="primary" @click="dialogVisible = true">開立發票</el-button>
	</div>

	<el-dialog
		v-model="dialogVisible"
		title="開立發票"
		width="600"
		align-center
		:z-index="999999"
		class="p-8"
	>
		<Steps />
	</el-dialog>
</template>

<style scoped></style>
