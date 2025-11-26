<script lang="ts" setup>
import { ref } from 'vue'
import { Setting } from '@element-plus/icons-vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { TIntegration } from '@/types'
import apiClient from '@/api'
import { ROUTER_MAPPER } from '@/router'

const props = withDefaults(defineProps<TIntegration>(), {
	description: '',
	icon: '',
	enabled: 'yes',
})

const isEnabled = ref<boolean>(props.enabled === 'yes')

const queryClient = useQueryClient()

const { mutateAsync: toggleGateway, isPending } = useMutation({
	mutationFn: async () => {
		return apiClient.post(`settings/${props.id}/toggle`)
	},
	onSuccess() {
		queryClient.invalidateQueries({ queryKey: ['settings'] })
	},
})

// 當 switch 改變時觸發 mutation
const handleChange: () => Promise<boolean> = async () => {
	try {
		await toggleGateway()
		return true // 成功 → 允許切換
	} catch (e) {
		return false // 失敗 → 阻止切換
	}
}

const url = ROUTER_MAPPER?.[`${props.id as keyof typeof ROUTER_MAPPER}`] ?? ''
</script>

<template>
	<el-card class="rounded-lg max-w-[30rem]" footer-class="py-2" shadow="hover">
		<div class="flex items-center gap-x-4 mb-4">
			<div
				class="size-12 rounded-xl flex items-center justify-center bg-gray-200"
			>
				<img
					v-if="icon"
					:alt="method_title"
					:src="icon"
					class="size-7 object-contain"
				/>
			</div>

			<div class="flex-1">
				<h5 class="text-gray-900 font-semibold text-xl m-0 leading-5">
					{{ method_title }}
				</h5>
			</div>
		</div>

		<p class="text-gray-600 text-base">
			{{ method_description || `使用 ${method_title} 收款` }}
		</p>

		<template #footer>
			<div class="flex justify-between items-center">
				<div class="flex items-center gap-x-2">
					<el-switch
						v-model="isEnabled"
						:before-change="handleChange"
						:loading="isPending"
						size="small"
					/>
					<span>{{ isEnabled ? 'Enabled' : 'Disabled' }}</span>
				</div>
				<div>
					<RouterLink :to="url">
						<Setting
							v-if="isEnabled"
							class="text-gray-400 size-5 cursor-pointer"
						/>
					</RouterLink>
				</div>
			</div>
		</template>
	</el-card>
</template>
