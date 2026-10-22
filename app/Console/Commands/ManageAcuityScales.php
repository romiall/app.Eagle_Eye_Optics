<?php

namespace App\Console\Commands;

use App\Application\TransactionRunner;
use App\Domain\Access\AuditRecorder;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Échelles d'acuité visuelle du cabinet (`visual_acuity_scales`, C02). Aucun écran de paramétrage n'existe à ce
 * jalon : cette commande est le seul moyen de les créer.
 *
 *  - `--demo` crée trois échelles de DÉMONSTRATION fictives, libellées comme telles, dans les environnements de
 *    développement, de test et de démonstration SEULEMENT (liste fermée, comme les comptes de démonstration). Elles
 *    ne représentent aucune échelle validée par la cliente (Q06, décision G11 ouverte) : rien ne doit être présenté
 *    comme validé cliniquement.
 *  - `--code` / `--label` (et `--description`) ajoutent l'échelle RÉELLE choisie par le cabinet, dans tout
 *    environnement : c'est un acte de paramétrage de l'exploitant, journalisé.
 *
 * Idempotente : un code déjà présent n'est ni modifié ni dupliqué. Aucune conversion entre échelles n'est écrite.
 */
#[Signature('eeo:clinical:scales
    {--demo : Crée les trois échelles de DÉMONSTRATION fictives (développement, test et démonstration seulement)}
    {--code= : Code de l\'échelle à ajouter (majuscules, chiffres et _, 32 caractères au plus)}
    {--label= : Libellé affiché de l\'échelle à ajouter}
    {--description= : Description facultative de l\'échelle à ajouter}
    {--clinic= : Identifiant du cabinet (sinon l\'unique cabinet de la base)}')]
#[Description('Crée les échelles d\'acuité visuelle du cabinet (échelles de démonstration fictives ou échelle réelle choisie)')]
final class ManageAcuityScales extends Command
{
    /** Environnements où les échelles de démonstration sont admises (liste fermée). */
    private const DEMO_ENVIRONMENTS = ['local', 'testing', 'e2e', 'demo'];

    /** @var array<string, array{0: string, 1: string}> code => [libellé, description] */
    public const DEMO_SCALES = [
        'DEMO_DIXIEMES' => [
            'Dixièmes (X/10) — DÉMONSTRATION',
            'Échelle de DÉMONSTRATION fictive, non validée cliniquement : valeurs de type 10/10, 8/10…',
        ],
        'DEMO_SNELLEN_6' => [
            'Snellen mètres (6/x) — DÉMONSTRATION',
            'Échelle de DÉMONSTRATION fictive, non validée cliniquement : valeurs de type 6/6, 6/12…',
        ],
        'DEMO_PRES' => [
            'Vision de près (Parinaud) — DÉMONSTRATION',
            'Échelle de DÉMONSTRATION fictive, non validée cliniquement : valeurs de type P2, P5…',
        ],
    ];

    public function handle(AuditRecorder $audit, TransactionRunner $transactions): int
    {
        $demo = (bool) $this->option('demo');
        $code = $this->option('code');
        $code = is_string($code) ? trim($code) : '';

        if ($demo === ($code !== '')) {
            $this->components->error('Choisissez --demo (échelles de démonstration) OU --code et --label (échelle réelle).');

            return self::FAILURE;
        }
        if ($demo && ! app()->environment(self::DEMO_ENVIRONMENTS)) {
            $this->components->error('Échelles de démonstration refusées dans cet environnement ('.app()->environment().') : admises seulement en '.implode(', ', self::DEMO_ENVIRONMENTS).'. Utilisez --code et --label pour l\'échelle réelle du cabinet.');

            return self::FAILURE;
        }

        $scales = self::DEMO_SCALES;
        if (! $demo) {
            $label = $this->option('label');
            $label = is_string($label) ? trim($label) : '';
            if (preg_match('/^[A-Z0-9_]{1,32}\z/', $code) !== 1 || $label === '' || mb_strlen($label, 'UTF-8') > 180) {
                $this->components->error('Code (A-Z, 0-9, _ ; 32 au plus) et libellé (180 au plus) obligatoires.');

                return self::FAILURE;
            }
            $description = $this->option('description');
            $scales = [$code => [$label, is_string($description) && trim($description) !== '' ? trim($description) : '']];
        }

        try {
            $created = $transactions->run(fn (): array => $this->provision($audit, $scales));
        } catch (\DomainException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        foreach ($scales as $scaleCode => $unused) {
            $this->components->info(($created[$scaleCode] ?? false) ? $scaleCode.' : créée.' : $scaleCode.' : déjà présente, inchangée.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $scales
     * @return array<string, bool> code => créée (faux : déjà présente)
     */
    private function provision(AuditRecorder $audit, array $scales): array
    {
        $clinicId = $this->clinicId();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $created = [];

        foreach ($scales as $code => [$label, $description]) {
            $created[$code] = false;
            if (DB::table('visual_acuity_scales')->where('clinic_id', $clinicId)->where('code', $code)->exists()) {
                continue;
            }
            $id = Ulid::generate();
            DB::table('visual_acuity_scales')->insert([
                'id' => $id,
                'clinic_id' => $clinicId,
                'code' => $code,
                'label' => $label,
                'description' => $description === '' ? null : $description,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $audit->record($clinicId, null, 'clinical.acuity_scale_created', 'visual_acuity_scales', $id, 'Échelle d\'acuité créée par commande', ['code' => $code], 'Commande de paramétrage');
            $created[$code] = true;
        }

        return $created;
    }

    private function clinicId(): string
    {
        $option = $this->option('clinic');
        if (is_string($option) && $option !== '') {
            if (! DB::table('clinics')->where('id', $option)->exists()) {
                throw new \DomainException('Cabinet introuvable.');
            }

            return $option;
        }

        $clinics = DB::table('clinics')->orderBy('id')->pluck('id');
        if ($clinics->count() !== 1) {
            throw new \DomainException($clinics->isEmpty() ? 'Aucun cabinet : créez le cabinet d\'abord.' : 'Plusieurs cabinets : indiquez --clinic.');
        }

        return (string) $clinics->first();
    }
}
