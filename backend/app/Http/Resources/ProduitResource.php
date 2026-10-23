<?php

namespace App\Http\Resources;

use App\Enums\ModeStock;
use App\Enums\StatutVariante;
use App\Models\VarianteProduit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    /**
     * etablissement_id n'apparaît jamais ici : c'est une colonne interne de
     * tenancy, jamais une information utile au client (voir Étape 2, règle
     * n°6).
     *
     * "medias" (triées par ordre, la première est la principale) n'apparaît
     * que si la relation a été chargée en amont (voir ProduitController::
     * index/show, qui l'eager-loadent systématiquement) — whenLoaded() évite
     * sinon une requête N+1 silencieuse.
     *
     * "stock_variantes" résume le stock des variantes actives : null pour
     * un produit sans variante, dont seules les colonnes du produit font
     * foi. Pour un produit qui en a, ses propres colonnes ne sont jamais
     * consultées (voir Produit::estDisponibleEnQuantite) — la pastille du
     * back-office qui les lisait affichait « Épuisé » pour un riz dont les
     * trois sacs étaient en stock. Seules les variantes actives comptent :
     * ce sont les seules que la vitrine propose.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie_id' => $this->categorie_id,
            'nom' => $this->nom,
            'slug' => $this->slug,
            'description' => $this->description,
            'reference' => $this->reference,
            'prix' => $this->prix,
            'prix_barre' => $this->prix_barre,
            'mode_stock' => $this->mode_stock,
            'quantite_stock' => $this->quantite_stock,
            'quantite_reservee' => $this->quantite_reservee,
            'disponible' => $this->disponible,
            'stock_variantes' => $this->whenLoaded('variantes', fn () => $this->resumerStockVariantes()),
            'statut' => $this->statut,
            'publie_le' => $this->publie_le,
            'medias' => MediaResource::collection($this->whenLoaded('medias')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{nombre: int, quantite_disponible: int|null, disponible: bool}|null
     */
    private function resumerStockVariantes(): ?array
    {
        $actives = $this->variantes->filter(fn (VarianteProduit $variante) => $variante->statut === StatutVariante::Actif);

        if ($actives->isEmpty()) {
            return null;
        }

        if ($this->mode_stock === ModeStock::Interrupteur) {
            return [
                'nombre' => $actives->count(),
                'quantite_disponible' => null,
                'disponible' => $actives->contains(fn (VarianteProduit $variante) => $variante->disponible),
            ];
        }

        $quantite = $actives->sum(fn (VarianteProduit $variante) => max(0, $variante->quantite_stock - $variante->quantite_reservee));

        return [
            'nombre' => $actives->count(),
            'quantite_disponible' => $quantite,
            'disponible' => $quantite > 0,
        ];
    }
}
