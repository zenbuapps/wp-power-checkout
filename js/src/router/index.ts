// router/index.js
import { createRouter, createWebHashHistory } from 'vue-router'
import { Settings, Payments, Logistics, Invoices, SLP, Amego } from '@/pages'

export const ROUTER_MAPPER = {
	shopline_payment_redirect: '/payments/shopline_payment_redirect',
	amego: '/invoices/amego',
}

const routes = [
	{ path: '/', redirect: '/payments' }, // 預設導向 /payments
	{ path: '/payments', component: Payments },
	{ path: '/payments/shopline_payment_redirect', component: SLP },
	{ path: '/logistics', component: Logistics },
	{ path: '/invoices', component: Invoices },
	{ path: '/invoices/amego', component: Amego },
	{ path: '/settings', component: Settings },
]

const router = createRouter({
	history: createWebHashHistory(), // 使用 Hash 模式
	routes,
})

export default router
