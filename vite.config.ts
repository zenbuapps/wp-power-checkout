import {fileURLToPath, URL} from 'node:url'
import {defineConfig} from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

export default defineConfig({
    css: {
        preprocessorOptions: {
            scss: {api: 'modern-compiler'},
        }
    },
    plugins: [
        vue(),
        vueDevTools()
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