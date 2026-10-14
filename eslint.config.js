import js from '@eslint/js'
import { defineConfig, globalIgnores } from 'eslint/config'
import prettier from 'eslint-config-prettier'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'

export default defineConfig([
  globalIgnores([
    'node_modules/**',
    'vendor/**',
    'public/**',
    'storage/**',
    'bootstrap/cache/**',
    'test-results/**',
    'playwright-report/**',
    'specifications/**',
    'database/**',
    '.spec-work/**',
  ]),

  js.configs.recommended,
  tseslint.configs.recommended,
  pluginVue.configs['flat/recommended'],

  {
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },

  {
    files: ['resources/frontend/**/*.{ts,vue}'],
    languageOptions: { globals: { ...globals.browser } },
    rules: {
      // Le frontend est servi en fichiers statiques : aucune API Node dans le code applicatif.
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['node:*'],
              message: 'Le code frontend ne doit pas dépendre des API Node.',
            },
          ],
        },
      ],
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      'vue/multi-word-component-names': 'off',
    },
  },

  {
    // Contrat 23 : « Les composants Vue appellent un service d'application, jamais une table Dexie
    // directement. » Seul resources/frontend/offline accède à IndexedDB.
    files: [
      'resources/frontend/components/**/*.{ts,vue}',
      'resources/frontend/pages/**/*.{ts,vue}',
      'resources/frontend/app/**/*.{ts,vue}',
    ],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: 'dexie',
              message: 'Passer par un service d’application de resources/frontend/offline.',
            },
          ],
          patterns: [
            {
              group: ['node:*'],
              message: 'Le code frontend ne doit pas dépendre des API Node.',
            },
            {
              group: ['@/offline/*', '**/offline/db*'],
              message: 'Accès aux tables locales réservé aux services du dossier offline.',
            },
          ],
        },
      ],
    },
  },

  {
    files: ['tests/frontend/**/*.ts'],
    languageOptions: { globals: { ...globals.browser, ...globals.node } },
  },

  {
    files: ['tests/e2e/**/*.{ts,mjs}', 'contracts/tools/**/*.mjs', '*.ts', '*.js'],
    languageOptions: { globals: { ...globals.node } },
  },

  prettier,
])
