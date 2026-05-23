import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'build' ? '/build/' : '/',
  build: {
    outDir: '../public/build',
    emptyOutDir: true,
  },
  server: {
    host: true,
    port: 8081,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
      },
    },
  },
}))
