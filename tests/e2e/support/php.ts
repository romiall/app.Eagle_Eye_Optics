import { spawnSync } from 'node:child_process'

let cached: string[] | null = null

/**
 * Options PHP des serveurs de test (`php -S`) : OPcache actif pour ce seul processus, qui dure tout
 * le test. Sans lui, chaque requête recompile Laravel : plusieurs secondes par requête sur un poste
 * Windows chargé, assez pour dépasser les délais des tests (le serveur intégré traite une requête à
 * la fois, précache du service worker compris). Rien n'est modifié dans le php.ini du poste : OPcache
 * déjà chargé (CI) → seul `opcache.enable_cli` est activé ; extension présente mais non chargée →
 * chargée par `-d` ; absente → aucune option.
 */
export function phpServerOptions(): string[] {
  if (cached !== null) return cached
  const probe = spawnSync(
    'php',
    [
      '-r',
      "echo extension_loaded('Zend OPcache') ? 'loaded' : (is_file(ini_get('extension_dir').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php_opcache.dll' : 'opcache.so')) ? 'available' : 'absent');",
    ],
    { encoding: 'utf8', windowsHide: true },
  )
  const state = probe.status === 0 ? probe.stdout.trim() : 'absent'
  cached =
    state === 'loaded'
      ? ['-d', 'opcache.enable_cli=1']
      : state === 'available'
        ? ['-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1']
        : []
  return cached
}
