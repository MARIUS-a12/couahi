<?php

namespace App\Http\Resources;

use App\Enums\StatutVariante;
use App\Models\LigneCommande;
use App\Models\OptionProduit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tout ce que l'écran des variantes d'un produit affiche, en une réponse :
 * les options et leurs valeurs dans l'ordre du commerçant, et les
 * variantes avec les identifiants de leurs valeurs. Chaque écriture
 * renvoie cette même forme, que l'écran substitue à la précédente.
 *
 * Pour chaque valeur et chaque variante, de quoi prévenir avant d'archiver
 * (« 12 articles en stock, 3 commandes la mentionnent ») sans requête de
 * plus.
 */
class StructureVariantesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $options = OptionProduit::where('produit_id', $this->id)
            ->with(['valeurs'])
            ->orderBy('ordre')->orderBy('id')
            ->get();
        $variantes = VarianteProduit::where('produit_id', $this->id)
            ->with('valeurs')
            ->orderBy('id')
            ->get();
        $commandesParVariante = LigneCommande::whereIn('variante_id', $variantes->pluck('id'))
            ->get(['variante_id', 'commande_id'])
            ->groupBy('variante_id')
            ->map(fn ($lignes) => $lignes->pluck('commande_id')->unique()->count());

        return [
            'produit_id' => $this->id,
            'mode_stock' => $this->mode_stock,
            'prix_produit' => $this->prix,
            'options' => $options->map(fn (OptionProduit $option) => [
                'id' => $option->id,
                'nom' => $option->nom,
                'ordre' => $option->ordre,
                'valeurs' => $option->valeurs->map(function (ValeurOption $valeur) use ($variantes, $commandesParVariante) {
                    $porteuses = $variantes->filter(fn (VarianteProduit $variante) => $variante->valeurs->contains('id', $valeur->id));

                    return [
                        'id' => $valeur->id,
                        'libelle' => $valeur->libelle,
                        'ordre' => $valeur->ordre,
                        'archivee' => $valeur->archivee_le !== null,
                        'nombre_variantes' => $porteuses->count(),
                        'stock_total' => (int) $porteuses->sum('quantite_stock'),
                        'nombre_commandes' => (int) $porteuses->sum(fn (VarianteProduit $variante) => $commandesParVariante[$variante->id] ?? 0),
                    ];
                })->values(),
            ])->values(),
            'variantes' => $variantes->map(fn (VarianteProduit $variante) => [
                'id' => $variante->id,
                'nom' => $variante->nom,
                'valeur_ids' => $variante->valeurs->pluck('id')->sort()->values(),
                'quantite_stock' => $variante->quantite_stock,
                'quantite_reservee' => $variante->quantite_reservee,
                'prix' => $variante->prix,
                'media_id' => $variante->media_id,
                'disponible' => $variante->disponible,
                'active' => $variante->statut === StatutVariante::Actif,
                'nombre_commandes' => (int) ($commandesParVariante[$variante->id] ?? 0),
            ])->values(),
        ];
    }
}
