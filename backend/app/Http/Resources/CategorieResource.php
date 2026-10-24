<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategorieResource extends JsonResource
{
    /**
     * etablissement_id n'apparaît jamais ici, voir ProduitResource.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'slug' => $this->slug,
            'description' => $this->description,
            'ordre' => $this->ordre,
            'parent_id' => $this->parent_id,
            'statut' => $this->statut,
            // Seulement présent quand le contrôleur a chargé le compte
            // (withCount) : whenCounted renvoie sinon un champ absent plutôt
            // qu'un zéro trompeur.
            'produits_count' => $this->whenCounted('produits'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
