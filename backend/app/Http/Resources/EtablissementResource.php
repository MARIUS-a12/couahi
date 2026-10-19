<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EtablissementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type,
            'sous_domaine' => $this->domaines->firstWhere('est_principal', true)?->hote
                ?? $this->domaines->first()?->hote,
            'statut' => $this->statut,
            // whenCounted plutôt qu'un accès direct : absent tant que le
            // contrôleur n'a pas chargé withCount('produits'), pour ne
            // jamais renvoyer un 0 trompeur.
            'produits_count' => $this->whenCounted('produits'),
            'created_at' => $this->created_at,
            // Étape 6C-1 : colonne "Paiement" de la liste super-admin, pour
            // savoir qui accompagner — jamais les identifiants eux-mêmes.
            'paiement_configure' => $this->paiementEstConfigure(),
            // Étape 12 : colonne "Abonnement" de la liste, triable par
            // échéance côté interface — absente si la relation n'a pas été
            // chargée par le contrôleur.
            'abonnement' => $this->whenLoaded('abonnement', fn () => $this->abonnement === null ? null : [
                'statut' => $this->abonnement->statut->value,
                'date_echeance' => $this->abonnement->date_echeance->toDateString(),
            ]),
            // Étape 6A ter : ce que la pastille "Vitrine incomplète" doit
            // dire au survol — vide quand tout y est.
            'identite_champs_manquants' => array_values(array_filter([
                $this->logo_media_id === null ? 'logo' : null,
                $this->couleur_accent === null ? 'couleur' : null,
                $this->telephone_whatsapp === null ? 'whatsapp' : null,
                $this->horaires === null ? 'horaires' : null,
            ])),
        ];
    }
}
