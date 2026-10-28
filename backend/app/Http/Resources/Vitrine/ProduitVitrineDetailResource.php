<?php

namespace App\Http\Resources\Vitrine;

use App\Http\Resources\MediaResource;
use App\Http\Resources\Vitrine\Concerns\ExposeVariantesPubliques;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche produit complète (voir GET /api/vitrine/produits/{id}) : toutes les
 * photos dans leurs trois formats (MediaResource, déjà public — voir
 * l'Étape 5, aucun champ sensible), description entière, options et variantes.
 *
 * Suppose "medias" (ordonné), "variantes.valeurs" et "options.valeurs"
 * déjà chargés — voir VitrineProduitController::show().
 */
class ProduitVitrineDetailResource extends JsonResource
{
    use ExposeVariantesPubliques;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'prix' => $this->prix,
            'prix_barre' => $this->prixBarrePublic(),
            'categorie' => $this->categorie ? ['id' => $this->categorie->id, 'nom' => $this->categorie->nom] : null,
            'disponible' => $this->estDisponibleEnQuantite(),
            'photos' => MediaResource::collection($this->medias),
            'options' => $this->optionsPubliques($this->resource),
            'variantes' => $this->variantesPubliques($this->resource, avecCombinaison: true),
        ];
    }
}
