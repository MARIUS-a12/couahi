<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lot D — une ligne de « Demandes WhatsApp ». Au clic sur wa.me, on ne sait
 * RIEN du client : ni nom, ni téléphone. Cette ressource n'en invente donc
 * aucun champ ; seulement le produit, la variante, l'heure du clic, la
 * référence, le statut et la commande éventuelle.
 *
 * Suppose produit.medias (ordonnés), variante.media et commande chargés.
 */
class DemandeWhatsappResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $photo = $this->variante?->media ?? $this->produit?->medias->first();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'reference' => "[REF:{$this->code}]",
            'produit' => ['id' => $this->produit_id, 'nom' => $this->produit?->nom],
            'variante' => $this->variante ? ['id' => $this->variante->id, 'nom' => $this->variante->nom] : null,
            'photo' => $photo?->urlVariante('vignette', 'jpg'),
            'cree_le' => $this->created_at,
            'expire_le' => $this->expire_le,
            'expiree' => ! $this->estValide(),
            'statut' => $this->statut->value,
            'statut_libelle' => $this->statut->libelle(),
            'statut_change_le' => $this->statut_change_le,
            'commande' => $this->commande ? ['id' => $this->commande->id, 'numero' => $this->commande->numero] : null,
        ];
    }
}
