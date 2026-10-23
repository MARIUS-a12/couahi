<?php

namespace App\Models;

use App\Enums\StatutVariante;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class VarianteProduit extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'variantes_produit';

    /**
     * Miroir des défauts posés en base, pour qu'une variante tout juste
     * créée expose déjà des valeurs exploitables sans rechargement.
     */
    protected $attributes = [
        'statut' => 'actif',
        'quantite_stock' => 0,
        'quantite_reservee' => 0,
        'disponible' => true,
    ];

    protected $fillable = [
        'produit_id',
        'nom',
        'reference',
        'prix',
        'quantite_stock',
        'quantite_reservee',
        'disponible',
        'media_id',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'integer',
            'quantite_stock' => 'integer',
            'quantite_reservee' => 'integer',
            'disponible' => 'boolean',
            'statut' => StatutVariante::class,
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
     * Les valeurs qui font de cette variante une combinaison (« 39 » et
     * « Noir »), une par option de son produit. Le pivot porte option_id
     * et produit_id : c'est sur eux que la base garantit une seule valeur
     * par option et l'appartenance au même produit.
     */
    public function valeurs(): BelongsToMany
    {
        return $this->belongsToMany(ValeurOption::class, 'variante_valeur', 'variante_id', 'valeur_id')
            ->withPivot(['option_id', 'produit_id']);
    }

    /**
     * L'image de la variante, toujours une des photos de son produit (clé
     * étrangère composée sur media_produit). Null : la variante montre les
     * photos du produit.
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * La signature d'une combinaison : les identifiants de ses valeurs,
     * triés, joints par un tiret (« 12-47 »). Jamais les libellés : un
     * commerçant renomme « M » en « Moyen », et la signature, l'unicité et
     * les variantes ne doivent pas bouger d'un octet. Seul point de calcul.
     *
     * @param  iterable<int>  $identifiantsValeurs
     */
    public static function signaturePour(iterable $identifiantsValeurs): string
    {
        $identifiants = array_map('intval', is_array($identifiantsValeurs) ? $identifiantsValeurs : iterator_to_array($identifiantsValeurs, false));
        sort($identifiants);

        return implode('-', $identifiants);
    }

    /**
     * Seule une variante active se vend : une variante inactive (archivée
     * avec sa valeur, par exemple) reste en base pour l'historique, mais
     * aucun parcours d'achat ne doit l'accepter.
     */
    public function estActive(): bool
    {
        return $this->statut === StatutVariante::Actif;
    }

    /**
     * Le mode de stock (compte / interrupteur) est une propriété du produit
     * parent, même quand — comme ici — le stock physique vit sur la
     * variante : cette méthode applique ce mode à ses propres colonnes.
     *
     * Coût en requêtes : `$this->produit` déclenche un lazy load (une
     * requête) si la relation n'est pas déjà chargée. Sur une liste de
     * variantes, charge en amont avec `VarianteProduit::with('produit')`.
     * En pratique, ce chemin ne devrait servir qu'à vérifier une variante
     * précise déjà en main (ex. panier) : pour un catalogue de produits,
     * utilise `Produit::estDisponibleEnQuantite()`, qui ne passe jamais par
     * ici et n'a donc pas besoin de cette relation.
     */
    public function estDisponibleEnQuantite(int $quantite = 1): bool
    {
        return $this->produit->mode_stock->estDisponibleEnQuantite(
            $this->quantite_stock,
            $this->quantite_reservee,
            $this->disponible,
            $quantite,
        );
    }

    /**
     * Le prix d'une variante hérite de celui du produit tant qu'il n'a pas
     * été explicitement fixé.
     */
    public function prixEffectif(): int
    {
        return $this->prix ?? $this->produit->prix;
    }

    /**
     * Réservation atomique par UPDATE conditionnel, jamais par
     * SELECT ... FOR UPDATE : `quantite_reservee` n'est incrémenté que si le
     * stock restant suffit encore, dans la même instruction SQL. Sous
     * concurrence réelle (deux commandes sur la dernière unité), la base ne
     * laisse gagner qu'une seule des deux UPDATE — c'est le seul juge.
     *
     * Retourne false si le stock restant est insuffisant : au 0 ligne
     * affectée, l'appelant (CreerCommande) doit lever
     * ArticleIndisponibleException plutôt que retenter.
     */
    /**
     * pourTousEtablissements() dans les trois méthodes ci-dessous : l'id de
     * variante vient toujours d'une réservation ou d'une ligne déjà résolue
     * et validée par l'appelant (CreerCommande, LibererReservation,
     * ConsommerReservation) — ce n'est pas une lecture tenant-scopée qui
     * doive dépendre d'un contexte ambiant, potentiellement absent dans un
     * job ou un webhook de paiement.
     */
    public static function reserverAtomiquement(int $varianteId, int $quantite): bool
    {
        return static::pourTousEtablissements()
            ->whereKey($varianteId)
            ->whereRaw('quantite_stock - quantite_reservee >= ?', [$quantite])
            ->increment('quantite_reservee', $quantite) > 0;
    }

    public static function libererAtomiquement(int $varianteId, int $quantite): void
    {
        static::pourTousEtablissements()->whereKey($varianteId)->decrement('quantite_reservee', $quantite);
    }

    public static function consommerAtomiquement(int $varianteId, int $quantite): void
    {
        static::pourTousEtablissements()->whereKey($varianteId)->update([
            'quantite_stock' => DB::raw("quantite_stock - {$quantite}"),
            'quantite_reservee' => DB::raw("quantite_reservee - {$quantite}"),
        ]);
    }

    /**
     * Voir Produit::restaurerAtomiquement() — même logique, même raison
     * d'être (Étape 9).
     */
    public static function restaurerAtomiquement(int $varianteId, int $quantite): void
    {
        static::pourTousEtablissements()->whereKey($varianteId)->increment('quantite_stock', $quantite);
    }
}
