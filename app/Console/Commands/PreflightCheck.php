<?php

namespace App\Console\Commands;

use App\Support\Deployment\PreflightChecks;
use App\Support\Deployment\PreflightResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Diagnostic d'un hébergement avant (ou après) l'installation : version de PHP, extensions, réglages du serveur,
 * configuration de production, droits d'écriture et surtout droits MySQL (création de tables, de clés étrangères,
 * de vues et de TRIGGERS, que la base utilise pour protéger l'historique). Répond à la grille d'audit d'hébergement
 * (docs/implementation/hosting-audit.md).
 *
 * Les contrôles de base de données créent puis suppriment des objets temporaires préfixés `eeo_preflight_` ; ils
 * ne touchent à aucune table de l'application. Code de sortie : 0 sans point bloquant, 1 sinon (les alertes ne
 * bloquent pas). `--json` donne le rapport pour le consigner.
 */
#[Signature('eeo:preflight
    {--json : Rapport au format JSON}
    {--skip-database : Ne pas contrôler la base de données}')]
#[Description('Diagnostique l\'hébergement : PHP, extensions, configuration, droits d\'écriture et droits MySQL (triggers compris)')]
final class PreflightCheck extends Command
{
    public function handle(PreflightChecks $checks): int
    {
        $results = $checks->run(! $this->option('skip-database'));
        $blocking = array_values(array_filter($results, static fn (PreflightResult $result): bool => $result->isBlocking()));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $blocking === [],
                'blocking' => count($blocking),
                'checks' => array_map(static fn (PreflightResult $result): array => $result->toArray(), $results),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $blocking === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            $line = sprintf('%-48s %s', $result->label, $result->detail);
            match ($result->status) {
                PreflightResult::OK => $this->line('  <fg=green>OK</>      '.$line),
                PreflightResult::WARNING => $this->line('  <fg=yellow>ALERTE</>  '.$line),
                PreflightResult::BLOCKING => $this->line('  <fg=red>BLOQUANT</> '.$line),
                default => $this->line('  info    '.$line),
            };
        }

        $warnings = count(array_filter($results, static fn (PreflightResult $result): bool => $result->status === PreflightResult::WARNING));
        $this->newLine();
        if ($blocking === []) {
            $this->components->info('Aucun point bloquant'.($warnings > 0 ? " ({$warnings} alerte(s) à lire)." : '.'));

            return self::SUCCESS;
        }

        $this->components->error(count($blocking).' point(s) bloquant(s) : corrigez-les avant d\'installer ou de mettre en service.');

        return self::FAILURE;
    }
}
