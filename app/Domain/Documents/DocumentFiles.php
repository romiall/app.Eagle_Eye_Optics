<?php

namespace App\Domain\Documents;

use App\Domain\Shared\BusinessError;

/**
 * Règles des fichiers privés (D01, X01) : contrôle des octets reçus et chemin calculé par le serveur. Pures : les
 * écritures et lectures du disque privé sont faites par `App\Support\Files\PrivateFiles`, hors du domaine (le
 * chemin central ne déclenche aucun effet externe). Le chemin est adressé par le contenu (empreinte SHA-256) et ne
 * contient aucune donnée personnelle. Un fichier écrit dont la commande échoue reste orphelin, sans métadonnées :
 * jamais un document « prêt » ni une créance sans fichier.
 */
final class DocumentFiles
{
    /** Justificatifs acceptés : signature des premiers octets → type et extension. */
    private const EVIDENCE_TYPES = [
        'application/pdf' => ['signature' => '%PDF-', 'extension' => 'pdf'],
        'image/jpeg' => ['signature' => "\xFF\xD8\xFF", 'extension' => 'jpg'],
        'image/png' => ['signature' => "\x89PNG\r\n\x1A\n", 'extension' => 'png'],
    ];

    /** Taille maximale d'un fichier (configurable : `eeo.documents.max_bytes`). */
    public static function maxBytes(): int
    {
        return (int) config('eeo.documents.max_bytes', 5 * 1024 * 1024);
    }

    /**
     * Contrôle d'un original PDF : signature « %PDF- », taille bornée, empreinte annoncée identique si fournie.
     *
     * @return array{sha256: string, size: int}
     */
    public static function inspect(string $bytes, ?string $announcedSha256): array
    {
        self::assertSize($bytes);
        if (! str_starts_with($bytes, '%PDF-')) {
            throw BusinessError::validation(['content' => ['Le contenu reçu n\'est pas un PDF.']]);
        }

        return ['sha256' => self::checkedSha256($bytes, $announcedSha256), 'size' => strlen($bytes)];
    }

    /**
     * Contrôle d'un justificatif scanné (X01, pièce papier) : PDF, JPEG ou PNG reconnu à ses premiers octets (jamais
     * au seul type annoncé), taille bornée, empreinte annoncée identique si fournie.
     *
     * @return array{sha256: string, size: int, mime_type: string, extension: string}
     */
    public static function inspectEvidence(string $bytes, ?string $announcedSha256): array
    {
        self::assertSize($bytes);
        foreach (self::EVIDENCE_TYPES as $mime => $type) {
            if (str_starts_with($bytes, $type['signature'])) {
                return [
                    'sha256' => self::checkedSha256($bytes, $announcedSha256),
                    'size' => strlen($bytes),
                    'mime_type' => $mime,
                    'extension' => $type['extension'],
                ];
            }
        }
        throw BusinessError::validation(['content' => ['Le justificatif doit être un PDF, une image JPEG ou PNG.']]);
    }

    /** Chemin privé d'un original : cabinet, document et empreinte, sans aucune donnée personnelle. */
    public static function pathFor(string $clinicId, string $documentId, string $sha256): string
    {
        return 'documents/'.$clinicId.'/'.$documentId.'-'.substr($sha256, 0, 16).'.pdf';
    }

    /** Chemin privé d'un justificatif : cabinet et empreinte complète (le même scan déposé deux fois = un fichier). */
    public static function evidencePathFor(string $clinicId, string $sha256, string $extension): string
    {
        return 'evidence/'.$clinicId.'/'.$sha256.'.'.$extension;
    }

    private static function assertSize(string $bytes): void
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw BusinessError::validation(['content' => ['Aucun contenu reçu : le fichier est vide.']]);
        }
        if ($size > self::maxBytes()) {
            throw BusinessError::validation(['content' => ['Le fichier dépasse la taille autorisée ('.intdiv(self::maxBytes(), 1024 * 1024).' Mo).']]);
        }
    }

    private static function checkedSha256(string $bytes, ?string $announcedSha256): string
    {
        $sha256 = hash('sha256', $bytes);
        if ($announcedSha256 !== null && strtolower($announcedSha256) !== $sha256) {
            throw BusinessError::validation(['content' => ['L\'empreinte annoncée ne correspond pas aux octets reçus : rien n\'est enregistré.']]);
        }

        return $sha256;
    }
}
