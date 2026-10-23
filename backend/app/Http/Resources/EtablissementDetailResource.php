<?php

namespace App\Http\Resources;

use App\Support\Couleur\CouleurAccent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La fiche d'un établissement : informations, domaines, et utilisateurs
 * rattachés avec leur rôle. Ne renvoie jamais de mot de passe — seule la
 * réponse de création (EtablissementController::store()) en porte un, via
 * ->additional(), jamais via une Resource.
 */
class EtablissementDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type,
            'statut' => $this->statut,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'couleur_accent' => $this->couleur_accent,
            // Affichée quand couleur_accent est null : le frontend ne
            // recopie jamais cette valeur (voir CouleurAccent).
            'couleur_accent_par_defaut' => CouleurAccent::PAR_DEFAUT,
            'created_at' => $this->created_at,
            // Étape 7 : conditionne, côté interface, la présence du bouton
            // "Supprimer définitivement" — la vérification qui compte
            // reste côté serveur (EtablissementController::destroy()).
            // Aucun contournement du scope global ici : chaque route qui
            // construit cette Resource pose déjà le contexte sur CET
            // établissement (DefinirEtablissementCible, ou explicitement
            // dans EtablissementController::store() juste après création) —
            // le filtrage tenant s'applique donc normalement.
            'nombre_commandes' => $this->commandes()->count(),
            // Lot A : le type n'est modifiable que tant que c'est 0 (voir
            // EtablissementController::modifierType, qui le revérifie).
            'nombre_produits' => $this->produits()->count(),
            'domaines' => $this->domaines->map(fn ($domaine) => [
                'id' => $domaine->id,
                'hote' => $domaine->hote,
                'est_principal' => $domaine->est_principal,
                'statut' => $domaine->statut,
            ]),
            'utilisateurs' => $this->appartenances->map(fn ($appartenance) => [
                'id' => $appartenance->utilisateur->id,
                'nom' => $appartenance->utilisateur->name,
                'email' => $appartenance->utilisateur->email,
                'role' => $appartenance->role->nom,
                'statut' => $appartenance->statut,
            ]),
        ];
    }
}
