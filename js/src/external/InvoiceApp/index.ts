// 訂單詳情頁掛載

import { createApp } from 'vue'
import App from '@/external/InvoiceApp/App.vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import '@/index.css'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'

/**
 * Invoice App
 * 開發票、做廢發票
 * @constructor
 */

export const appData = window?.power_checkout_invoice_metabox_app_data
export const isAdmin = !!appData?.is_admin

const MAPPER_ADMIN = {
    ISSUE_INVOICE: '開立發票',
}
const MAPPER_CHECKOUT = {
    ISSUE_INVOICE: '設定發票資訊',
}
export const MAPPER = isAdmin ? MAPPER_ADMIN : MAPPER_CHECKOUT

const Module = () => {
    if (!appData) {
        return
    }

    const CONTAINER_IDS = appData?.render_ids

    // Mount Vue app
    const app = createApp(App)

    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                staleTime: 15 * 60 * 1000, // 15 分鐘內視為新鮮
                gcTime: 15 * 60 * 1000, // 快取保留 15 分鐘
                retry: 0, // 最多重試 0 次
                refetchOnWindowFocus: false, // 禁用視窗聚焦時重新請求
            },
            mutations: {
                retry: 0, // mutation 失敗不重試次
            },
        },
    })
    app.use(VueQueryPlugin, { queryClient })
    app.use(ElementPlus)

    setTimeout(() => {
        CONTAINER_IDS?.forEach((id) => {
            const node = document.getElementById(id)
            if (!node) {
                return
            }
            // 在 node 隔壁創建 render div
            const containerDiv = document.createElement('div')
            containerDiv.id = `${id}_container`
            node.insertAdjacentElement('afterend', containerDiv)

            app.mount(`#${id}_container`)
        })
    }, 1000)
}

export default Module
