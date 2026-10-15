<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Le dépôt est public : .env.example ne doit contenir aucun secret et seul ce fichier est suivi.
 */
final class EnvExampleTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function variables(): array
    {
        $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        $variables = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $variables[trim($name)] = trim($value, " \t\"'");
        }

        return $variables;
    }

    public function test_les_variables_sensibles_sont_vides(): void
    {
        $variables = $this->variables();

        foreach (['APP_KEY', 'DB_PASSWORD'] as $name) {
            $this->assertArrayHasKey($name, $variables);
            $this->assertSame('', $variables[$name], "{$name} doit rester vide dans .env.example.");
        }

        foreach ($variables as $name => $value) {
            if (preg_match('/(SECRET|TOKEN|PASSWORD|API_KEY|PRIVATE)/', $name) === 1) {
                $this->assertSame('', $value, "{$name} ressemble à un secret et doit rester vide.");
            }
            $this->assertStringNotContainsString('base64:', $value, "{$name} contient une clé encodée.");
        }
    }

    public function test_les_valeurs_par_defaut_sont_sures_et_sans_fournisseur_reel(): void
    {
        $variables = $this->variables();

        $this->assertSame('false', $variables['APP_DEBUG']);
        $this->assertSame('false', $variables['PRODUCTION_DOCUMENTS_ENABLED']);
        $this->assertSame('true', $variables['SESSION_SECURE_COOKIE']);
        $this->assertSame('log', $variables['MAIL_MAILER']);
        $this->assertSame('fake', $variables['EEO_INTEGRATIONS_MODE']);
        $this->assertSame('Africa/Douala', $variables['CLINIC_TIMEZONE']);
        $this->assertSame('XAF', $variables['CLINIC_CURRENCY']);
        $this->assertSame('fw_sessions', $variables['SESSION_TABLE']);
        $this->assertSame('fw_cache', $variables['DB_CACHE_TABLE']);
        $this->assertSame('fw_cache_locks', $variables['DB_CACHE_LOCK_TABLE']);
        $this->assertSame('fw_jobs', $variables['DB_QUEUE_TABLE']);
        $this->assertSame('fw_job_batches', $variables['DB_QUEUE_BATCH_TABLE']);
        $this->assertSame('fw_failed_jobs', $variables['DB_QUEUE_FAILED_TABLE']);
        $this->assertSame('mysql', $variables['DB_CONNECTION']);
        $this->assertNotSame('eeo_test', $variables['DB_DATABASE'], 'La base applicative ne doit pas être la base de test.');
    }

    public function test_seul_env_example_est_versionnable(): void
    {
        // « git --version » répond sous sh comme sous cmd.exe (Windows), contrairement à « command -v ».
        $git = trim((string) shell_exec('git --version 2>&1'));

        if (! str_starts_with($git, 'git version') || ! is_dir(base_path('.git'))) {
            $this->markTestSkipped('Dépôt git ou binaire git indisponible.');
        }

        // Fichiers suivis ou prêts à l'être (non ignorés) : un .env réel serait ignoré par .gitignore.
        $tracked = shell_exec('git -C '.escapeshellarg(base_path()).' ls-files --cached --others --exclude-standard -- ".env*" "*/.env*"');
        $files = array_values(array_filter(explode("\n", (string) $tracked)));

        $this->assertSame(['.env.example'], $files);
    }
}
