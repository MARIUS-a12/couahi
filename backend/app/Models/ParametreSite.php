<?php

namespace App\Models;

use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne par établissement (contrainte unique sur etablissement_id) :
 * les réglages du site vitrine, distincts des colonnes propres à
 * Etablissement (nom, type...). Créée avec ses valeurs par défaut dans la
 * même transaction que l'établissement — voir CreerEtablissement.
 */
class ParametreSite extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'parametres_site';

    protected $attributes = [
        'accepte_commandes' => true,
        'delai_preparation_minutes' => 30,
        'livraison_par_commune' => false,
    ];

    protected $fillable = [
        'etablissement_id',
        'accepte_commandes',
        'delai_preparation_minutes',
        'livraison_par_commune',
    ];

    protected function casts(): array
    {
        return [
            'accepte_commandes' => 'boolean',
            'delai_preparation_minutes' => 'integer',
            'livraison_par_commune' => 'boolean',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
