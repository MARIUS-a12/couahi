<?php

namespace App\Http\Resources;

use App\Models\PaiementAbonnement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaiementAbonnement
 */
class PaiementAbonnementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'montant' => $this->montant,
            'periode_debut' => $this->periode_debut->toDateString(),
            'periode_fin' => $this->periode_fin->toDateString(),
            'moyen' => $this->moyen->value,
            'reference' => $this->reference,
            'paye_le' => $this->paye_le,
        ];
    }
}
