import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

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
  plugins: [vue(), adminPage],
  build: {
    rollupOptions: { input: { main: resolve(__dirname, 'index.html'), admin: resolve(__dirname, 'admin.html') } },
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
