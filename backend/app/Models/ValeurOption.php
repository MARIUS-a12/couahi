<?php

namespace App\Models;

use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une valeur d'option : « 39 », « M », « 100 ml », « Bleu ciel ». Libellé
 * libre, ordre explicite. Une valeur portée par une variante ne s'efface
 * jamais (la base le refuse) : elle s'archive (archivee_le), ses variantes
 * deviennent inactives, et l'historique de vente reste intact.
 */
class ValeurOption extends Model
{
    use AppartientAEtablissement;

    protected $table = 'valeurs_option';

    protected $fillable = [
        'option_id',
        'libelle',
        'ordre',
        'archivee_le',
    ];

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'archivee_le' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(OptionProduit::class, 'option_id');
    }
}
