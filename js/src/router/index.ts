// router/index.js
import { createRouter, createWebHashHistory } from 'vue-router'

export const ROUTER_MAPPER = {
	shopline_payment_redirect: '/payments/shopline_payment_redirect',
	amego: '/invoices/amego',
}

const routes = [
	{ path: '/', redirect: '/payments' }, // 預設導向 /payments
	{ path: '/payments', component: () => import('@/pages/Payments/index.vue') },
	{
		path: '/payments/shopline_payment_redirect',
		component: () => import('@/pages/Payments/SLP/index.vue'),
	}, // 請根據實際路徑調整
	{ path: '/logistics', component: () => import('@/pages/Logistics.vue') },
	{ path: '/invoices', component: () => import('@/pages/Invoices/index.vue') },
	{
		path: '/invoices/amego',
		component: () => import('@/pages/Invoices/Amego/index.vue'),
	},
	{ path: '/settings', component: () => import('@/pages/Settings.vue') },
]

const router = createRouter({
	history: createWebHashHistory(), // 使用 Hash 模式
	routes,
})

export default router
