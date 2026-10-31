import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import react, { reactCompilerPreset } from '@vitejs/plugin-react'
import babel from '@rolldown/plugin-babel'
import tailwindcss from '@tailwindcss/vite'

// Vite 8 (Rolldown) + React 19 + React Compiler (stable Babel route) + Tailwind v4.
export default defineConfig({
  plugins: [react(), babel({ presets: [reactCompilerPreset()] }), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    // Lets *.localhost subdomains (e.g. mjengo.localhost:5173) reach the dev server
    // so subdomain storefront routing can be tested locally.
    host: true,
    allowedHosts: ['.localhost'],
    // The Laravel app (and its vendor folder) lives in ./laravel; don't watch it.
    watch: { ignored: ['**/laravel/**'] },
  },
})
