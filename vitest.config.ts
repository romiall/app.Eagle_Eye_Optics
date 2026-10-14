import { fileURLToPath } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// Configuration séparée de vite.config.ts : les tests n'ont besoin ni de Laravel, ni de Tailwind, ni
// du plugin PWA.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./resources/frontend', import.meta.url)) },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/frontend/**/*.test.ts'],
    setupFiles: ['tests/frontend/setup.ts'],
    clearMocks: true,
    restoreMocks: true,
    unstubGlobals: true,
  },
})
