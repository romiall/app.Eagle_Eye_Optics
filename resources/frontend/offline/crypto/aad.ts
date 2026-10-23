import { utf8 } from './bytes'

/**
 * Données associées (AAD) d'AES-GCM du coffre (vault.md D3, D4) : un tableau JSON
 * `["eeo-vault-v1", but, …]` de chaînes NON VIDES et d'entiers naturels (décimal sans zéro initial), dont les
 * octets UTF-8 sont figés par des vecteurs. Le but est obligatoire et pris dans une liste fermée ; la suite lie le
 * cabinet, le coffre (`vaultId`, jamais le `device_id` : le poste n'est connu qu'après l'enrôlement), le
 * compartiment, la génération de clé (`keyId`), le type, l'identifiant et la version. Une ligne déplacée, rejouée
 * sous une autre version, un autre compartiment ou une autre génération de clé ne se déchiffre pas.
 */
export const AAD_PREFIX = 'eeo-vault-v1'

/**
 * `command` (O04) : charge métier d'une commande du journal du poste, liée au cabinet, au coffre, au compartiment, à
 * la génération de clé, à l'identifiant de commande et à sa séquence (une charge déplacée vers une autre commande ou
 * une autre position du journal ne se déchiffre pas). Ajout seulement : les buts existants gardent leurs octets.
 */
// `export` (O07) : clé et contenu d'un export chiffré du poste ; ajouté à la fin, les encodages existants ne changent pas.
export const AAD_PURPOSES = [
  'entity',
  'document',
  'draft',
  'envelope',
  'kcv',
  'command',
  'export',
] as const
export type AadPurpose = (typeof AAD_PURPOSES)[number]

export type AadPart = string | number

export function encodeAad(purpose: AadPurpose, parts: readonly AadPart[]): Uint8Array {
  if (!(AAD_PURPOSES as readonly string[]).includes(purpose)) {
    throw new RangeError('But d’AAD inconnu')
  }
  const items: string[] = [JSON.stringify(AAD_PREFIX), JSON.stringify(purpose)]
  for (const part of parts) {
    if (typeof part === 'number') {
      if (!Number.isSafeInteger(part) || part < 0) throw new RangeError('Entier d’AAD invalide')
      items.push(String(part))
    } else {
      if (part === '') throw new RangeError('Chaîne d’AAD vide')
      items.push(JSON.stringify(part))
    }
  }
  return utf8(`[${items.join(',')}]`)
}
