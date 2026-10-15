import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  APP_MODULES,
  type AppModule,
  HOME_ACTIONS,
  type NavigationEntry,
  visibleHomeActions,
  visibleModules,
} from '@/app/navigation'

const ROOT = join(import.meta.dirname, '..', '..', '..')

/** Identifiants des tâches du backlog (plan d'exécution, jamais régénéré ici). */
function backlogTaskIds(): Set<string> {
  const backlog = JSON.parse(
    readFileSync(join(ROOT, 'specifications', 'developpement', 'backlog.json'), 'utf8'),
  ) as { tasks: { id: string }[] }
  return new Set(backlog.tasks.map((task) => task.id))
}

/** Codes du référentiel `permissions` seedé (database/02_reference_data.sql). */
function seededPermissions(): Set<string> {
  const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
  const start = sql.indexOf('INSERT INTO `permissions`')
  const end = sql.indexOf(';', start)
  const block = sql.slice(start, end)
  return new Set(
    [...block.matchAll(/\('([a-z_]+(?:\.[a-z_]+)+)',/g)].map((match) => match[1] ?? ''),
  )
}

function fake(id: string, permissions: string[], delivered: boolean, mobile = false): AppModule {
  return {
    id,
    label: id,
    path: `/${id}`,
    permissions,
    deliveredBy: 'P01',
    delivered,
    mobileShortcut: mobile,
  }
}

describe('navigation de la coquille (fichier 04)', () => {
  it('liste les modules dans l’ordre du fichier 04', () => {
    expect(APP_MODULES.map((module) => module.label)).toEqual([
      'Aujourd’hui',
      'Patients',
      'Consultations',
      'Agenda',
      'Ventes et caisse',
      'Stock',
      'Atelier',
      'Communication',
      'Rapports',
      'Paramètres',
    ])
  })

  it('propose sur téléphone les raccourcis Aujourd’hui, Patients et Agenda, le reste sous « Plus »', () => {
    expect(APP_MODULES.filter((module) => module.mobileShortcut).map((m) => m.label)).toEqual([
      'Aujourd’hui',
      'Patients',
      'Agenda',
    ])
  })

  it('liste les actions d’accueil du dossier patient dans l’ordre du fichier 04', () => {
    expect(HOME_ACTIONS.map((action) => action.label)).toEqual([
      'Nouveau patient',
      'Rechercher',
      'Rendez-vous',
      'Consultations du jour',
      'Statistiques',
      'Paramètres',
    ])
  })

  it('ne présente aucun écran futur comme livré dans cette version', () => {
    // Aucun écran métier n'existe encore : chaque tâche qui en livre un passe son entrée à true
    // en même temps que sa route et ses tests (et met ce test à jour).
    const entries: readonly NavigationEntry[] = [...APP_MODULES, ...HOME_ACTIONS]
    expect(entries.filter((entry) => entry.delivered)).toEqual([])
    const everything = [...seededPermissions()]
    expect(visibleModules(everything)).toEqual([])
    expect(visibleHomeActions(everything)).toEqual([])
  })

  it('rattache chaque entrée à une tâche du backlog et à des permissions du référentiel', () => {
    const tasks = backlogTaskIds()
    const permissions = seededPermissions()
    expect(permissions.size).toBeGreaterThan(40)

    for (const entry of [...APP_MODULES, ...HOME_ACTIONS]) {
      expect(tasks.has(entry.deliveredBy), `${entry.id} → ${entry.deliveredBy}`).toBe(true)
      expect(entry.permissions.length, entry.id).toBeGreaterThan(0)
      for (const permission of entry.permissions) {
        expect(permissions.has(permission), `${entry.id} → ${permission}`).toBe(true)
      }
      expect(entry.path.startsWith('/'), entry.id).toBe(true)
    }
    for (const entries of [APP_MODULES, HOME_ACTIONS]) {
      const ids = entries.map((entry) => entry.id)
      expect(new Set(ids).size).toBe(ids.length)
    }
  })

  it('n’affiche une entrée que livrée ET autorisée, l’une des permissions suffisant', () => {
    const modules = [
      fake('livre-autorise', ['patient.read', 'agenda.read'], true),
      fake('livre-interdit', ['users.manage'], true),
      fake('non-livre', ['patient.read'], false),
    ]

    expect(visibleModules(['agenda.read'], modules).map((module) => module.id)).toEqual([
      'livre-autorise',
    ])
    expect(visibleModules([], modules)).toEqual([])
    expect(visibleModules(['users.manage', 'patient.read'], modules).map((m) => m.id)).toEqual([
      'livre-autorise',
      'livre-interdit',
    ])
  })

  it('montre à la caisse les actions de caisse et jamais les écrans cliniques', () => {
    const cashier = [
      'patient.read',
      'agenda.read',
      'catalog.read',
      'invoice.read',
      'payment.collect',
    ]
    const delivered = APP_MODULES.map((module) => ({ ...module, delivered: true }))

    const labels = visibleModules(cashier, delivered).map((module) => module.label)
    expect(labels).toContain('Ventes et caisse')
    expect(labels).not.toContain('Consultations')
    expect(labels).not.toContain('Paramètres')

    const actions = visibleHomeActions(
      cashier,
      HOME_ACTIONS.map((action) => ({ ...action, delivered: true })),
    ).map((action) => action.label)
    expect(actions).toEqual(['Rechercher'])
  })
})
