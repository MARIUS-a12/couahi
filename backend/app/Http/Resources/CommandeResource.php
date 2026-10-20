<?php

namespace App\Http\Resources;

use App\Enums\StatutCommande;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une seule Resource pour la liste et le détail (Étape 9) : lignes et
 * historique n'apparaissent que si la relation a été chargée en amont
 * (whenLoaded), exactement comme ProduitResource le fait pour "medias" —
 * CommandeController::index() ne les charge pas (liste légère), ::show() le
 * fait (détail complet).
 */
class CommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'statut' => $this->statut->value,
            'statut_libelle' => $this->statut->libelle(),
            'canal' => $this->canal->value,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'total' => $this->total,
            'note' => $this->note,
            // Correctif livraison : null pour toute commande antérieure à
            // cette colonne — jamais une chaîne vide ni "non renseigné"
            // affichée à sa place (voir PageDetailCommande côté frontend).
            'commune' => $this->commune,
            'quartier' => $this->quartier,
            'created_at' => $this->created_at,
            'nombre_articles' => $this->lignes->sum('quantite'),
            // Une commande non traitée depuis plus de 2h : un client qui
            // attend est un client qui part (voir Étape 9).
            'en_retard' => $this->statut === StatutCommande::AttentePaiement
                && $this->created_at->lt(now()->subHours(2)),
            // Étape 13 — le paiement, sa propre machine d'état, à côté du
            // statut de la commande et jamais fusionné avec lui.
            'mode_paiement' => $this->mode_paiement?->value,
            'paiement' => [
                'confirme' => $this->paiementEstConfirme(),
                'confirme_le' => $this->paiement_confirme_le,
                'confirme_par' => $this->whenLoaded('paiementConfirmePar', fn () => $this->paiementConfirmePar?->name),
                'montant_recu' => $this->paiement_montant_recu,
                'ecart' => $this->paiement_montant_recu === null ? null : $this->paiement_montant_recu - $this->total,
                'reference' => $this->paiement_reference,
                'en_retard' => $this->paiementEnRetard(),
            ],
            'client' => $this->whenLoaded('client', fn () => [
                'nom' => $this->client->nom,
                'telephone' => $this->client->telephone,
            ]),
            'zone_livraison' => $this->whenLoaded('zoneLivraison', fn () => $this->zoneLivraison ? [
                'nom' => $this->zoneLivraison->nom,
                'frais' => $this->zoneLivraison->frais,
            ] : null),
            'lignes' => $this->whenLoaded('lignes', fn () => $this->lignes->map(function ($ligne) {
                $media = $ligne->relationLoaded('produit') && $ligne->produit?->relationLoaded('medias')
                    ? $ligne->produit->medias->first()
                    : null;

                return [
                    'id' => $ligne->id,
                    'nom' => $ligne->nom_produit_capture,
                    'variante' => $ligne->variante?->nom,
                    'photo' => $media ? ['webp' => $media->urlVariante('vignette', 'webp'), 'jpg' => $media->urlVariante('vignette', 'jpg')] : null,
                    'prix_unitaire' => $ligne->prix_unitaire,
                    'quantite' => $ligne->quantite,
                    'total' => $ligne->total,
                ];
            })),
            'historique' => $this->whenLoaded('historique', fn () => $this->historique
                ->sortBy('id')
                ->values()
                ->map(fn ($entree) => [
                    'ancien_statut' => $entree->ancien_statut?->libelle(),
                    'nouveau_statut' => $entree->nouveau_statut->libelle(),
                    'utilisateur' => $entree->utilisateur?->name,
                    'motif' => $entree->motif,
                    'evenement' => $entree->evenement,
                    'date' => $entree->created_at,
                ])),
        ];
    }
}
