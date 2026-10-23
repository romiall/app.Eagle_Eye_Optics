<?php

namespace App\Console\Commands;

use App\Application\Command;
use App\Application\CommandBus;
use App\Application\TransactionRunner;
use App\Domain\Access\AuditRecorder;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\DB;

/**
 * Agenda de démonstration FICTIF (S01) : une salle d'examen rattachée au compte `demo.professionnel`, des créneaux
 * hebdomadaires de démonstration, deux prestations et les deux rappels internes de rendez-vous de la proposition
 * commerciale (la veille à 18 h, deux heures avant ; canal interne, aucun envoi). Les horaires et les durées sont
 * FICTIFS : ceux du cabinet sont saisis par la gérante (aucun horaire inventé en production).
 *
 * Mêmes garde-fous que `eeo:demo:sales` : environnements de développement, de test et de démonstration seulement, dans
 * le cabinet de démonstration « DONNÉES FICTIVES » seulement, jamais en production. Écritures par le chemin central au
 * nom de `demo.gerante`. Relançable : ce qui existe déjà est laissé intact.
 */
#[Signature('eeo:demo:agenda')]
#[Description('Crée une salle, des horaires, deux prestations et deux rappels internes de démonstration (cabinet de démonstration seulement)')]
final class AgendaDemoCommand extends ConsoleCommand
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'e2e', 'demo'];

    public const RESOURCE_NAME = 'Salle d\'examen (démonstration)';

    public const EXAM_SERVICE = 'Examen de vue (démonstration)';

    public const CONTROL_SERVICE = 'Contrôle (démonstration)';

    /** Créneaux FICTIFS : du lundi au vendredi 08:00–12:00 et 14:00–17:00, le samedi 08:00–12:00. */
    private const HOURS = [
        [1, '08:00', '12:00'], [1, '14:00', '17:00'],
        [2, '08:00', '12:00'], [2, '14:00', '17:00'],
        [3, '08:00', '12:00'], [3, '14:00', '17:00'],
        [4, '08:00', '12:00'], [4, '14:00', '17:00'],
        [5, '08:00', '12:00'], [5, '14:00', '17:00'],
        [6, '08:00', '12:00'],
    ];

    public function handle(CommandBus $bus, AuditRecorder $audit, TransactionRunner $transactions): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->components->error('Agenda de démonstration refusé dans cet environnement ('.app()->environment().') : admis seulement en '.implode(', ', self::ALLOWED_ENVIRONMENTS).'. Jamais en production.');

            return self::FAILURE;
        }

        try {
            $clinicId = $this->demoClinic();
            $manager = User::query()->where('clinic_id', $clinicId)->where('login', 'demo.gerante')->where('active', true)->first();
            if (! $manager instanceof User) {
                throw new DomainException('Le compte « demo.gerante » est introuvable ou désactivé : lancez d\'abord eeo:demo:accounts.');
            }
            $professionalId = DB::table('users')->where('clinic_id', $clinicId)->where('login', 'demo.professionnel')->value('id');

            $resourceId = DB::table('scheduling_resources')->where('clinic_id', $clinicId)->where('name', self::RESOURCE_NAME)->value('id');
            if (! is_string($resourceId)) {
                $resourceId = Ulid::generate();
                $bus->dispatch(
                    new Command('SaveSchedulingResource', [
                        'resource_id' => $resourceId,
                        'name' => self::RESOURCE_NAME,
                        'professional_user_id' => is_string($professionalId) ? $professionalId : null,
                        'slot_duration_minutes' => 15,
                        'active' => true,
                    ], $resourceId, null),
                    $this->demoContext($manager),
                    Ulid::generate(),
                );
            }
            if (! DB::table('resource_opening_hours')->where('clinic_id', $clinicId)->where('resource_id', $resourceId)->exists()) {
                $version = (int) DB::table('scheduling_resources')->where('clinic_id', $clinicId)->where('id', $resourceId)->value('version');
                $bus->dispatch(
                    new Command('ReplaceOpeningHours', [
                        'hours' => array_map(static fn (array $hour): array => [
                            'id' => Ulid::generate(), 'weekday' => $hour[0], 'opens_at' => $hour[1], 'closes_at' => $hour[2],
                        ], self::HOURS),
                    ], $resourceId, $version),
                    $this->demoContext($manager),
                    Ulid::generate(),
                );
            }
            foreach ([self::EXAM_SERVICE => 30, self::CONTROL_SERVICE => 20] as $name => $minutes) {
                if (DB::table('appointment_services')->where('clinic_id', $clinicId)->where('name', $name)->exists()) {
                    continue;
                }
                $serviceId = Ulid::generate();
                $bus->dispatch(
                    new Command('SaveAppointmentService', [
                        'service_id' => $serviceId, 'name' => $name, 'duration_minutes' => $minutes, 'active' => true,
                    ], $serviceId, null),
                    $this->demoContext($manager),
                    Ulid::generate(),
                );
            }

            // Rappels internes de rendez-vous de la proposition commerciale (J-1 à 18 h, H-2) : canal INTERNE, aucun
            // envoi (L2 activera les transmissions). Aucune commande ne configure encore les politiques : écriture
            // directe, tracée, dans le seul cabinet de démonstration.
            $transactions->run(function () use ($clinicId, $audit): void {
                DB::table('clinics')->where('id', $clinicId)->lockForUpdate()->first();
                $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
                foreach ([
                    ['DEMO_RDV_VEILLE_18H', 'Rappel la veille à 18 h (démonstration)', 'DAY', 1, '18:00:00'],
                    ['DEMO_RDV_2H_AVANT', 'Rappel deux heures avant (démonstration)', 'HOUR', 2, null],
                ] as [$code, $label, $unit, $value, $time]) {
                    if (DB::table('reminder_policies')->where('clinic_id', $clinicId)->where('code', $code)->exists()) {
                        continue;
                    }
                    $id = Ulid::generate();
                    DB::table('reminder_policies')->insert([
                        'id' => $id, 'clinic_id' => $clinicId, 'code' => $code, 'label' => $label, 'purpose' => 'APPOINTMENT',
                        'channel' => 'INTERNAL', 'offset_unit' => $unit, 'offset_value' => $value, 'local_send_time' => $time,
                        'active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $audit->record($clinicId, null, 'reminder_policy.created', 'reminder_policies', $id, 'Agenda de démonstration', ['code' => $code], 'Commande eeo:demo:agenda');
                }
            });
        } catch (BusinessError|DomainException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Agenda de démonstration prêt (horaires et durées FICTIFS).');
        $this->line('Ouvrez le module avec EEO_ENABLED_MODULES=…,agenda ; la gérante saisit les vrais horaires dans « Agenda », « Configuration ».');

        return self::SUCCESS;
    }

    private function demoContext(User $manager): CommandContext
    {
        return CommandContext::direct($manager, 'demo-agenda-'.substr(Ulid::generate(), -10));
    }

    private function demoClinic(): string
    {
        $clinics = DB::table('clinics')->orderBy('id')->get(['id', 'name']);
        if ($clinics->count() !== 1 || $clinics->first()?->name !== CreateDemoAccounts::DEMO_CLINIC_NAME) {
            throw new DomainException('L\'agenda de démonstration n\'existe que dans le cabinet « '.CreateDemoAccounts::DEMO_CLINIC_NAME.' » (lancez d\'abord eeo:demo:accounts).');
        }

        return (string) $clinics->first()->id;
    }
}
