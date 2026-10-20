<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le secret n'apparaît JAMAIS ici, à aucun rôle — une fois saisi, il ne se
 * relit pas, il se remplace (voir Étape 6C-1). site_id et clé API peuvent
 * être renvoyés masqués : seuls les quatre derniers caractères visibles.
 */
class PaiementEtablissementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'configure' => $this->paiementEstConfigure(),
            // Configuré n'est pas disponible : faux tant que l'intégration
            // CinetPay n'est pas active sur la plateforme — le commerçant
            // doit le savoir, ses clients ne voient pas "Payer maintenant".
            'disponible' => $this->cinetpayEstDisponible(),
            'configure_le' => $this->paiement_configure_le,
            'cinetpay_site_id_masque' => $this->masquer($this->cinetpay_site_id),
            'cinetpay_cle_api_masque' => $this->masquer($this->cinetpay_cle_api),
        ];
    }

    private function masquer(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $longueur = mb_strlen($valeur);

        if ($longueur <= 4) {
            return str_repeat('•', $longueur);
        }

        return str_repeat('•', min($longueur - 4, 6)).mb_substr($valeur, -4);
    }
}
