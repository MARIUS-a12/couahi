<?php

namespace App\Http\Resources;

use App\Models\EtablissementUtilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EtablissementUtilisateur
 */
class MembreEquipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'utilisateur_id' => $this->utilisateur_id,
            'nom' => $this->utilisateur->name,
            'email' => $this->utilisateur->email,
            'roles' => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'nom' => $role->nom,
                'libelle' => $role->libelle,
            ])->values(),
            'statut' => $this->statut,
            'ajoute_le' => $this->created_at,
            'derniere_connexion' => $this->utilisateur->derniere_connexion_a,
            'mot_de_passe_defini' => $this->utilisateur->mot_de_passe_defini,
        ];
    }
}
