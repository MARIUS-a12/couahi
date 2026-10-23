<?php

namespace App\Models;

use App\Enums\StatutCategorie;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categorie extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'categories';

    /**
     * Miroir du défaut posé en base, pour qu'une catégorie tout juste créée
     * expose déjà une valeur exploitable sans rechargement.
     */
    protected $attributes = [
        'statut' => 'actif',
    ];

    protected $fillable = [
        'nom',
        'slug',
        'description',
        'ordre',
        'statut',
        'parent_id',
    ];

    /**
     * est_racine et parent_est_racine ne sont jamais saisis : ils
     * découlent de parent_id, et la base s'en sert pour refuser un
     * troisième niveau (voir la migration ajouter_parent_aux_categories).
     */
    protected static function booted(): void
    {
        static::saving(function (self $categorie): void {
            $estRacine = $categorie->parent_id === null;
            $categorie->setAttribute('est_racine', $estRacine);
            $categorie->setAttribute('parent_est_racine', $estRacine ? null : true);
        });
    }

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'statut' => StatutCategorie::class,
            'est_racine' => 'boolean',
            'parent_est_racine' => 'boolean',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('ordre')->orderBy('id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }
}
