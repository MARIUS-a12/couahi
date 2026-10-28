<?php

namespace App\Http\Resources\Vitrine;

use App\Http\Resources\Vitrine\Concerns\ExposeVariantesPubliques;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Une carte de la grille publique (voir GET /api/vitrine/produits). Aucun
 * champ de coût, de marge ni de quantité de stock : seulement de quoi
 * afficher et filtrer — voir ProduitVitrineDetailResource pour la fiche
 * complète (photos en 3 formats, description entière).
 *
 * Suppose "medias" (ordonné par pivot, la première est la principale) et
 * "variantes" déjà chargés par l'appelant — voir VitrineProduitController.
 */
class ProduitVitrineResource extends JsonResource
{
    use ExposeVariantesPubliques;

    public function toArray(Request $request): array
    {
        $photoPrincipale = $this->medias->first();

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description ? Str::limit($this->description, 160) : null,
            'prix' => $this->prix,
            'prix_barre' => $this->prixBarrePublic(),
            'categorie' => $this->categorie ? ['id' => $this->categorie->id, 'nom' => $this->categorie->nom] : null,
            'disponible' => $this->estDisponibleEnQuantite(),
            'photo' => $photoPrincipale ? [
                'vignette' => ['webp' => $photoPrincipale->urlVariante('vignette', 'webp'), 'jpg' => $photoPrincipale->urlVariante('vignette', 'jpg')],
                'moyenne' => ['webp' => $photoPrincipale->urlVariante('moyenne', 'webp'), 'jpg' => $photoPrincipale->urlVariante('moyenne', 'jpg')],
            ] : null,
            'variantes' => $this->variantesPubliques($this->resource),
        ];
    }
}
