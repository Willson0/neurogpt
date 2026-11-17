import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

// /admin and /answer are pages of their own: in development it is served from admin.html like nginx does in production
const pageRewrite = (req, _res, next) => {
  if (/^\/admin\/?(\?|$)/.test(req.url)) req.url = req.url.replace(/^\/admin\/?/, '/admin.html')
  if (/^\/answer\/?(\?|$)/.test(req.url)) req.url = req.url.replace(/^\/answer\/?/, '/answer.html')
  next()
}
const adminPage = {
  name: 'admin-page',
  configureServer(server) { server.middlewares.use(pageRewrite) },
  configurePreviewServer(server) { server.middlewares.use(pageRewrite) },
}

export default defineConfig({
  plugins: [vue(), adminPage],
  build: {
    rollupOptions: { input: { main: resolve(__dirname, 'index.html'), admin: resolve(__dirname, 'admin.html'), answer: resolve(__dirname, 'answer.html') } },
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
