import { readFileSync } from 'node:fs'
import { join } from 'node:path'

// Comptes de démonstration FICTIFS créés par prepare-env.mjs (mots de passe aléatoires, fichier ignoré).
interface DemoAccount {
  login: string
  role: string
  password: string | null
}

let cache: DemoAccount[] | null = null

function accounts(): DemoAccount[] {
  cache ??= JSON.parse(
    readFileSync(
      join(
        import.meta.dirname,
        '..',
        '..',
        '..',
        'storage',
        'framework',
        'testing',
        'e2e-accounts.json',
      ),
      'utf8',
    ),
  ) as DemoAccount[]
  return cache
}

/** Identifiant et mot de passe du compte de démonstration d'un profil. */
export function demoAccount(role: string): { login: string; password: string } {
  const found = accounts().find((candidate) => candidate.role === role)
  if (!found?.password)
    throw new Error(`Compte de démonstration ${role} absent : relancer prepare-env.`)
  return { login: found.login, password: found.password }
}
