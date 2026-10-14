<?php

namespace Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Tout code d'erreur que l'API peut renvoyer est déclaré dans contracts/schemas/error.json (fichier 08 :
 * codes stables). Les codes propres à une commande console, jamais renvoyés par l'API, sont listés ici.
 */
final class ErrorCodeCatalogTest extends TestCase
{
    /** Codes émis uniquement par des commandes console (sortie texte, pas d'enveloppe JSON). */
    private const CONSOLE_ONLY = ['RECOVERY_TARGET_NOT_MANAGER'];

    public function test_chaque_code_emis_par_l_application_est_declare_dans_le_contrat(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = json_decode((string) file_get_contents($root.'/contracts/schemas/error.json'), true, 512, JSON_THROW_ON_ERROR);
        $declared = $schema['$defs']['ErrorCode']['enum'];

        $emitted = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/new (?:self|BusinessError)\\(\\s*'([A-Z][A-Z_]+)'/", $source, $constructed);
            preg_match_all("/^\\s*(?:\\d{3}|\\\$exception->getStatusCode\\(\\)), '([A-Z][A-Z_]+)'/m", $source, $rendered);
            $emitted = [...$emitted, ...$constructed[1], ...$rendered[1]];
        }
        $emitted = array_values(array_unique(array_diff($emitted, self::CONSOLE_ONLY)));
        sort($emitted);

        $this->assertNotEmpty($emitted);
        $this->assertContains('AUTH_REQUIRED', $emitted, 'Les codes du rendu des erreurs sont bien lus.');
        $this->assertContains('VALIDATION_FAILED', $emitted, 'Les codes des fabriques de BusinessError sont bien lus.');
        $this->assertSame([], array_values(array_diff($emitted, $declared)), 'Codes émis mais absents de contracts/schemas/error.json');
    }
}
