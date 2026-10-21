import type { CommandName } from '@/domain/contracts/generated'

import index from '../../../../contracts/commands/index.json'

/**
 * Index des commandes lu directement depuis le contrat F06 (`contracts/commands/index.json`, généré
 * par `contracts/tools/build_commands.py`) : même source que le serveur pour la route directe, la
 * permission, la nature de la version attendue et le type de numéro. Aucune copie à maintenir.
 */
export type ExpectedVersionKind = 'none' | 'entity' | 'draft' | 'ledger' | 'state'

export interface CommandContract {
  name: CommandName
  method: 'POST' | 'PATCH'
  /** Chemin sous /api, avec ses paramètres `{id}`, `{step}`. */
  path: string
  permission: string
  expectedVersion: ExpectedVersionKind
  entityType: string
  entityIdField: string
  numberType: string | null
  /**
   * Champ du numéro de pièce dans la charge : renseigné par le poste (plage préparée) dans son
   * journal, mais `null` sur la route directe, où le serveur numérote (contrat `DirectRequest`).
   */
  directNumberField: string | null
}

/** Champ de numéro par type de numéro (vérifié contre chaque `DirectRequest` par les tests). */
const NUMBER_FIELDS: Readonly<Record<string, string>> = {
  PATIENT: 'record_number',
  INVOICE: 'number',
  RECEIPT: 'receipt_number',
  CREDIT_NOTE: 'number',
  REFUND: 'number',
}

interface RawContract {
  direct_route: string
  permission: string
  expected_version: string
  entity_type: string
  entity_id_field: string
  number_type?: string | null
}

const EXPECTED_VERSION_KINDS: readonly ExpectedVersionKind[] = [
  'none',
  'entity',
  'draft',
  'ledger',
  'state',
]

function parse(name: string, raw: RawContract): CommandContract {
  const match = /^(POST|PATCH) (\/\S+)$/.exec(raw.direct_route)
  const kind = EXPECTED_VERSION_KINDS.find((candidate) => candidate === raw.expected_version)
  if (match === null || kind === undefined) {
    throw new Error(`Contrat de commande illisible : ${name}.`)
  }
  const numberType = raw.number_type ?? null
  const directNumberField = numberType === null ? null : (NUMBER_FIELDS[numberType] ?? null)
  if (numberType !== null && directNumberField === null) {
    throw new Error(`Type de numéro sans champ connu : ${name} (${numberType}).`)
  }
  return {
    name: name as CommandName,
    method: match[1] as 'POST' | 'PATCH',
    path: `/api/v1${match[2] ?? ''}`,
    permission: raw.permission,
    expectedVersion: kind,
    entityType: raw.entity_type,
    entityIdField: raw.entity_id_field,
    numberType,
    directNumberField,
  }
}

const contracts = new Map<string, CommandContract>(
  Object.entries(index.const as Record<string, RawContract>).map(([name, raw]) => [
    name,
    parse(name, raw),
  ]),
)

export function commandContract(name: CommandName): CommandContract {
  const contract = contracts.get(name)
  if (contract === undefined) throw new Error(`Commande inconnue du contrat : ${name}.`)
  return contract
}

export function commandNames(): CommandName[] {
  return [...contracts.keys()] as CommandName[]
}

/** Chemin concret : chaque paramètre `{nom}` est remplacé (encodé) ; un paramètre manquant est refusé. */
export function commandPath(
  contract: CommandContract,
  params: Readonly<Record<string, string>>,
): string {
  return contract.path.replace(/\{([a-z_]+)\}/g, (_whole, name: string) => {
    const value = params[name]
    if (value === undefined || value === '') {
      throw new Error(`Paramètre de route manquant pour ${contract.name} : ${name}.`)
    }
    return encodeURIComponent(value)
  })
}
