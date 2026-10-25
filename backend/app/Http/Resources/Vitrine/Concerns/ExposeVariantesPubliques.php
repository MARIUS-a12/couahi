<?php

namespace App\Http\Resources\Vitrine\Concerns;

use App\Enums\StatutVariante;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use Illuminate\Support\Collection;

/**
 * Partagé entre ProduitVitrineResource (liste) et ProduitVitrineDetailResource
 * (fiche) : les deux exposent exactement la même forme de variante, jamais
 * de quantité — seulement prix et disponibilité.
 *
 * Calcule la disponibilité inline (mode_stock du produit déjà en mémoire,
 * colonnes de la variante déjà chargées) plutôt que via
 * VarianteProduit::estDisponibleEnQuantite(), qui relancerait une requête
 * pour recharger CE MÊME produit depuis chaque variante — voir la docblock
 * de Produit::estDisponibleEnQuantite() pour ce même piège déjà documenté.
 */
trait ExposeVariantesPubliques
{
    /**
     * $avecCombinaison : la fiche seule en a besoin (sélecteur par
     * dimension, photo du coloris) ; la grille s'en passe, et ne charge
     * donc ni les valeurs ni les options.
     *
     * @return array<int, array{id: int, nom: string, prix: int, disponible: bool, valeurs?: array<int, int>, photo_id?: int|null}>
     */
    protected function variantesPubliques(Produit $produit, bool $avecCombinaison = false): array
    {
        return $this->variantesActives($produit)
            ->map(fn (VarianteProduit $variante) => [
                'id' => $variante->id,
                'nom' => $variante->nom,
                'prix' => $variante->prixEffectif(),
                'disponible' => $produit->mode_stock->estDisponibleEnQuantite(
                    $variante->quantite_stock,
                    $variante->quantite_reservee,
                    $variante->disponible,
                    1,
                ),
                ...($avecCombinaison ? [
                    'valeurs' => $variante->valeurs->pluck('id')->sort()->values()->all(),
                    'photo_id' => $variante->media_id,
                ] : []),
            ])
            ->values()
            ->all();
    }

    /**
     * Les dimensions dans l'ordre du commerçant, et pour chacune les seules
     * valeurs portées par au moins une variante active : une valeur archivée,
     * ou jamais combinée, n'existe pas pour le client. Une valeur qui existe
     * dans une autre combinaison reste listée : c'est au sélecteur de la
     * désactiver selon le choix en cours, pas à l'API de la cacher.
     *
     * Suppose "options.valeurs" et "variantes.valeurs" chargés.
     *
     * @return array<int, array{id: int, nom: string, valeurs: array<int, array{id: int, libelle: string}>}>
     */
    protected function optionsPubliques(Produit $produit): array
    {
        $portees = $this->variantesActives($produit)
            ->flatMap(fn (VarianteProduit $variante) => $variante->valeurs->pluck('id'))
            ->unique()
            ->flip();

        return $produit->options
            ->map(fn (OptionProduit $option) => [
                'id' => $option->id,
                'nom' => $option->nom,
                'valeurs' => $option->valeurs
                    ->filter(fn (ValeurOption $valeur) => $valeur->archivee_le === null && $portees->has($valeur->id))
                    ->map(fn (ValeurOption $valeur) => ['id' => $valeur->id, 'libelle' => $valeur->libelle])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $option) => $option['valeurs'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, VarianteProduit>
     */
    private function variantesActives(Produit $produit): Collection
    {
        return $produit->variantes->filter(fn (VarianteProduit $variante) => $variante->statut === StatutVariante::Actif);
    }
}
