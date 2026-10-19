<?php

namespace App\Models;

use App\Enums\MoyenPaiementAbonnement;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des paiements d'abonnement, jamais modifié ni supprimé après
 * coup : c'est la trace de l'argent reçu. Une correction se fait par un
 * nouvel enregistrement, pas en réécrivant celui-ci.
 */
class PaiementAbonnement extends Model
{
    use AppartientAEtablissement;

    protected $table = 'paiements_abonnement';

    protected $fillable = [
        'montant',
        'periode_debut',
        'periode_fin',
        'moyen',
        'reference',
        'enregistre_par',
        'paye_le',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'periode_debut' => 'immutable_date',
            'periode_fin' => 'immutable_date',
            'moyen' => MoyenPaiementAbonnement::class,
            'paye_le' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }
}
