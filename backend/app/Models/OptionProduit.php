<?php

namespace App\Models;

use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une dimension d'un produit : Taille, Couleur, Pointure, Contenance...
 * Nom libre, ordre d'affichage explicite. Voir la migration
 * creer_options_valeurs_et_combinaisons pour ce que la base garantit.
 */
class OptionProduit extends Model
{
    use AppartientAEtablissement;

    protected $table = 'options_produit';

    protected $fillable = [
        'produit_id',
        'nom',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * Toujours dans l'ordre choisi par le commerçant, jamais par libellé :
     * « 39, 4, 40 » et « L, M, S, XL » sont tous deux faux. L'identifiant
     * ne départage que deux valeurs de même ordre.
     */
    public function valeurs(): HasMany
    {
        return $this->hasMany(ValeurOption::class, 'option_id')->orderBy('ordre')->orderBy('id');
    }
}
