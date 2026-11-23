import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { adminSearchIndex } from './build/adminSearchIndex.js'
import { fileURLToPath } from 'node:url'

// /admin is a page of its own: in development it is served from admin.html like nginx does in production
const adminPage = {
  name: 'admin-page',
  configureServer(server) {
    server.middlewares.use((req, _res, next) => {
      if (/^\/admin\/?(\?|$)/.test(req.url)) req.url = req.url.replace(/^\/admin\/?/, '/admin.html')
      next()
    })
  },
}

export default defineConfig({
  plugins: [adminSearchIndex(), vue(), adminPage],
  build: {
    rollupOptions: { input: {
      main: fileURLToPath(new URL('./index.html', import.meta.url)),
      admin: fileURLToPath(new URL('./admin.html', import.meta.url)),
    } },
  },
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': { target: process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000', changeOrigin: true },
      '/storage': { target: process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
})
