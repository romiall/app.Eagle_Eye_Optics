<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabinet (table `clinics`) : paramètres administratifs, fuseau et monnaie. Cabinet unique initial.
 * `production_documents_enabled` n'est jamais modifiable par l'API en L0 (paramètres réels non validés : Q03).
 *
 * @property string $id
 * @property string $name
 * @property string $timezone
 * @property string $currency
 * @property bool $production_documents_enabled
 */
class Clinic extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'production_documents_enabled' => 'boolean',
            'document_settings' => 'array',
        ];
    }
}
