import {fileURLToPath, URL} from 'node:url'
import {defineConfig} from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import postcssPrefixSelector from 'postcss-prefix-selector'
import tailwindcss from 'tailwindcss'

export default defineConfig(({command}) => ({
    server: {
        port: 5182,
        cors: {
            origin: '*',
        },
        fs: {
            allow: ['./'],
        },
    },
    css: {
        preprocessorOptions: {
            scss: {api: 'modern-compiler'},
        },
        // postcss: {
        //     plugins: [
        //         tailwindcss('./tailwind.config.cjs'),
        //         postcssPrefixSelector({
        //             prefix: '#power-checkout-wc-setting-app',
        //             transform(prefix, selector, prefixedSelector, filePath) {
        //                 // 只處理 node_modules/element-plus 的 CSS
        //                 if (filePath && !filePath.includes('/power-checkout/js/src/index.css')) {
        //                     if (selector.startsWith(':root') || selector.startsWith('html') || selector.startsWith('body')) {
        //                         return selector
        //                     }
        //                     return prefixedSelector
        //                 }
        //                 console.log(filePath)
        //                 // 其他 CSS (例如 Tailwind) 不加 prefix
        //                 return selector
        //             }
        //         })
        //     ]
        // }
    },
    plugins: [
        vue(),
        vueDevTools(),
    ],
    // build 產物以相對路徑互相引用（chunk preload 不再指向站台根目錄 /assets/...）
    base: command === 'build' ? './' : '/',
    build: {
        outDir: 'js/dist',
        emptyOutDir: true,
        // PHP（ViteAsset）讀 js/dist/manifest.json 取得帶 hash 的 entry / CSS 檔名
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'js/src/index.ts',
            output: {
                // 檔名一律帶 content hash：部分主機對靜態檔回 immutable 一年快取且忽略 ?ver=，
                // 固定檔名會讓更新後的瀏覽器繼續跑舊 bundle（例：新 PHP 列出新廠商卡片，舊 bundle 沒有它的路由）
                entryFileNames: '[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                name: 'settings',
                assetFileNames: '[name]-[hash][extname]',
            }
        },
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./js/src', import.meta.url))
        },
    },
}))