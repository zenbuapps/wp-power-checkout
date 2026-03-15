import {fileURLToPath, URL} from 'node:url'
import {defineConfig} from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import postcssPrefixSelector from 'postcss-prefix-selector'
import tailwindcss from 'tailwindcss'

export default defineConfig({
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
    build: {
        outDir: 'js/dist',
        rollupOptions: {
            input: 'js/src/index.ts',
            output: {
                entryFileNames: 'index.js',
                name: 'settings',
                // 修改資源檔案名稱
                assetFileNames: '[name].[ext]',
            }
        },
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./js/src', import.meta.url))
        },
    },
})