<?php

namespace App\Enums;

/**
 * Canal de communication utilisé pour la commande. Détermine notamment le
 * délai avant expiration des réservations de stock (voir CreerCommande).
 */
enum Canal: string
{
    case Web = 'web';
    case Whatsapp = 'whatsapp';

    // Lot C — saisis au back-office : la vente au comptoir est terminée à
    // la saisie, celle par téléphone ou WhatsApp est convenue, pas livrée.
    case Comptoir = 'comptoir';
    case Telephone = 'telephone';

    public function libelle(): string
    {
        return match ($this) {
            self::Web => 'Boutique en ligne',
            self::Whatsapp => 'WhatsApp',
            self::Comptoir => 'Comptoir',
            self::Telephone => 'Téléphone',
        };
    }
}
