import { createHash } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

import { createPwaOptions, PWA_ICONS } from './resources/frontend/app/pwa.config.ts'

/** Empreinte des fichiers statiques de public/, utilisée comme révision de précache. */
function publicFileRevisions(urls: readonly string[]): Record<string, string> {
  const revisions: Record<string, string> = {}
  for (const url of urls) {
    const path = fileURLToPath(new URL(`./public${url}`, import.meta.url))
    if (existsSync(path)) {
      revisions[url] = createHash('sha256').update(readFileSync(path)).digest('hex').slice(0, 16)
    }
  }
  return revisions
}

// La coquille « / » est rendue par Laravel et référence des fichiers versionnés : sa révision change
// à chaque build (ou prend EEO_BUILD_ID pour un build reproductible).
const shellRevision = process.env['EEO_BUILD_ID'] ?? new Date().toISOString()

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/frontend/app/main.ts'],
      refresh: ['resources/views/**'],
    }),
    vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    tailwindcss(),
    VitePWA(
      createPwaOptions(
        publicFileRevisions([...PWA_ICONS.map((icon) => icon.src), '/icons/favicon.svg']),
        shellRevision,
      ),
    ),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./resources/frontend', import.meta.url)) },
  },
  build: {
    sourcemap: false,
  },
  server: {
    watch: { ignored: ['**/storage/framework/views/**', '**/vendor/**'] },
  },
})
