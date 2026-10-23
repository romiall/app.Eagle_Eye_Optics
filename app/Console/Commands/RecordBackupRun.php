<?php

namespace App\Console\Commands;

use App\Domain\Access\ClinicContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Journal d'une sauvegarde serveur (`backup_runs`, X02/X03) : appelée par `tools/deploy/backup.sh` à la fin de chaque
 * sauvegarde, une ligne par partie (base, fichiers). Le journal n'est pas la sauvegarde : il dit quand, où (dossier
 * privé hors racine web), quelle empreinte, quelle taille et quelle clé de chiffrement (son identifiant, jamais la
 * clé). Une sauvegarde non chiffrée est consignée en échec `NOT_ENCRYPTED` (fichier 09 : copie chiffrée obligatoire),
 * même si ses fichiers existent : elle ne peut pas servir de preuve de restauration (`eeo:restore:verify --record`).
 */
final class RecordBackupRun extends Command
{
    protected $signature = 'eeo:backup:record
        {--scope=SERVER_DATABASE : SERVER_DATABASE ou SERVER_FILES}
        {--status=SUCCEEDED : SUCCEEDED ou FAILED}
        {--started-at= : Début de la sauvegarde, instant ISO 8601}
        {--completed-at= : Fin de la sauvegarde, instant ISO 8601}
        {--destination= : Dossier privé de la sauvegarde}
        {--sha256= : Empreinte SHA-256 du fichier sauvegardé}
        {--size= : Taille en octets}
        {--key-id= : Identifiant de la clé de chiffrement (jamais la clé)}
        {--error= : Code d\'erreur d\'un échec}';

    protected $description = 'Consigne une sauvegarde serveur dans backup_runs (appelée par tools/deploy/backup.sh)';

    private const SCOPES = ['SERVER_DATABASE', 'SERVER_FILES'];

    public function handle(ClinicContext $clinics): int
    {
        $scope = $this->stringOption('scope') ?? '';
        $status = $this->stringOption('status') ?? '';
        if (! in_array($scope, self::SCOPES, true) || ! in_array($status, ['SUCCEEDED', 'FAILED'], true)) {
            $this->error('--scope vaut SERVER_DATABASE ou SERVER_FILES ; --status vaut SUCCEEDED ou FAILED.');

            return self::INVALID;
        }
        $sha = $this->stringOption('sha256');
        if ($sha !== null && preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            $this->error('--sha256 doit être une empreinte SHA-256 hexadécimale.');

            return self::INVALID;
        }
        $size = $this->stringOption('size');
        if ($size !== null && preg_match('/^[0-9]{1,18}$/', $size) !== 1) {
            $this->error('--size doit être un nombre d\'octets.');

            return self::INVALID;
        }
        try {
            $started = CarbonImmutable::parse($this->stringOption('started-at') ?? 'now')->utc();
            $completedAt = $this->stringOption('completed-at');
            $completed = $completedAt === null ? null : CarbonImmutable::parse($completedAt)->utc();
        } catch (Throwable) {
            $this->error('--started-at et --completed-at sont des instants ISO 8601.');

            return self::INVALID;
        }

        $keyId = $this->text('key-id', 100);
        $error = $this->text('error', 100);
        // Fichier 09 : sans clé de chiffrement, la sauvegarde n'est pas conforme, donc pas « réussie ».
        if ($status === 'SUCCEEDED' && $keyId === null) {
            $status = 'FAILED';
            $error = 'NOT_ENCRYPTED';
        }
        $destination = $this->text('destination', 500);
        if ($status === 'SUCCEEDED' && ($completed === null || $destination === null || $sha === null)) {
            $this->error('Une sauvegarde réussie exige --completed-at, --destination et --sha256.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $id = Ulid::generate();
        DB::table('backup_runs')->insert([
            'id' => $id,
            'clinic_id' => $clinics->configuredClinicId(),
            'scope' => $scope,
            'device_id' => null,
            'started_at' => $started->format('Y-m-d H:i:s.u'),
            'completed_at' => $completed?->format('Y-m-d H:i:s.u'),
            'status' => $status,
            'private_destination' => $destination,
            'sha256' => $sha,
            'size_bytes' => $size === null ? null : (int) $size,
            'encryption_key_id' => $keyId,
            'error_code' => $status === 'FAILED' ? ($error ?? 'BACKUP_FAILED') : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->line($id.' '.$scope.' '.$status.($status === 'FAILED' ? ' '.($error ?? 'BACKUP_FAILED') : ''));

        return self::SUCCESS;
    }

    /** Valeur d'une option texte : une chaîne, ou null quand elle est absente. */
    private function stringOption(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : null;
    }

    private function text(string $option, int $max): ?string
    {
        $value = $this->stringOption($option);
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
