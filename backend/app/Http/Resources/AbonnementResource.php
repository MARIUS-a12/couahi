<?php

namespace App\Http\Resources;

use App\Models\Abonnement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Résumé d'un abonnement : partagé par /api/moi (bandeau d'avertissement,
 * carte de statut de la barre latérale) et par les écrans d'abonnement.
 * Les jours restants sont calculés ici, côté serveur, pour que le bandeau
 * et la page disent exactement la même chose que la tâche quotidienne.
 *
 * @mixin Abonnement
 */
class AbonnementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'statut' => $this->statut->value,
            'montant_mensuel' => $this->montant_mensuel,
            'jours_de_grace' => $this->jours_de_grace,
            'date_echeance' => $this->date_echeance->toDateString(),
            'date_coupure' => $this->dateCoupure()->toDateString(),
            'jours_avant_echeance' => $this->joursAvantEcheance(),
            'jours_avant_coupure' => $this->joursAvantCoupure(),
            'doit_avertir' => $this->doitAvertir(),
        ];
    }
}
