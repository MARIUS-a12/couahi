<?php

namespace App\Http\Resources;

use App\Enums\StatutZoneLivraison;
use App\Models\Etablissement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'écran « Livraison » du back-office : le choix de facturer ou non, et
 * les zones actives (les archivées n'existent plus pour le commerçant).
 *
 * @mixin Etablissement
 */
class LivraisonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'par_commune' => (bool) $this->parametreSite()->pourTousEtablissements()->value('livraison_par_commune'),
            'zones' => $this->zonesLivraison()
                ->pourTousEtablissements()
                ->where('statut', StatutZoneLivraison::Actif)
                ->orderBy('nom')
                ->get()
                ->map(fn ($zone) => ['id' => $zone->id, 'nom' => $zone->nom, 'frais' => $zone->frais])
                ->all(),
        ];
    }
}
