<script lang="ts" setup>
import { ref } from 'vue'
import { Setting } from '@element-plus/icons-vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { TProvider } from '../types'
import apiClient from '@/api'
import { ROUTER_MAPPER } from '@/router'
import { resolveSettingsRoute } from '@/utils/settings-route'

const props = withDefaults(defineProps<TProvider>(), {
    method_description: '',
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

const router = useRouter()

// 找不到該廠商的設定路由（通常是瀏覽器還在跑舊版前端 bundle）→ 明確提示重新整理，不再「點了沒反應」
const openSettings = () => {
    const result = resolveSettingsRoute(
        props.id,
        ROUTER_MAPPER,
        (path) => router.resolve(path).matched.length > 0,
    )
    if (!result.ok) {
        ElMessage({ type: 'warning', message: result.message, duration: 8000, showClose: true, offset: 100 }) // offset：避開 WP admin bar + WC 設定頁置頂標題列
        return
    }
    router.push(result.path)
}
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
                    <button
                            v-if="isEnabled"
                            aria-label="設定"
                            class="pc-card-settings p-0 border-0 bg-transparent cursor-pointer leading-none"
                            type="button"
                            @click="openSettings"
                    >
                        <Setting class="text-gray-400 size-5" />
                    </button>
                </div>
            </div>
        </template>
    </el-card>
</template>
