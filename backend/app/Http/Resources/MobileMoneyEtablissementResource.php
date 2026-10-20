<?php

namespace App\Http\Resources;

use App\Models\Etablissement;
use App\Support\Telephone\NormaliseurTelephone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En clair, contrairement aux identifiants CinetPay : ces trois valeurs
 * sont affichées à chaque client qui paie, ce ne sont pas des secrets.
 *
 * @mixin Etablissement
 */
class MobileMoneyEtablissementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'configure' => $this->mobileMoneyEstConfigure(),
            'numero' => $this->mm_numero,
            'numero_affiche' => $this->mm_numero ? NormaliseurTelephone::formaterLocal($this->mm_numero) : null,
            'operateur' => $this->mm_operateur?->value,
            'operateur_libelle' => $this->mm_operateur?->libelle(),
            'beneficiaire' => $this->mm_beneficiaire,
            // CinetPay passe avant quand il est configuré : le Mobile Money
            // n'est alors PAS proposé aux clients, même rempli ici.
            'cinetpay_prioritaire' => $this->cinetpayEstDisponible(),
        ];
    }
}
