<?php

namespace App\Models;

use App\Enums\StatutCommande;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoriqueCommande extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'historique_commande';

    protected $fillable = [
        'commande_id',
        'ancien_statut',
        'nouveau_statut',
        'utilisateur_id',
        'motif',
        'evenement',
    ];

    protected function casts(): array
    {
        return [
            'ancien_statut' => StatutCommande::class,
            'nouveau_statut' => StatutCommande::class,
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
