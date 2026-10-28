<?php

namespace App\Enums;

/**
 * Lot D — où en est une demande WhatsApp (un clic sur « Commander sur
 * WhatsApp »). Une demande n'est pas une commande : elle ne réserve rien
 * et ne compte dans aucun chiffre. « Traitée » quand le commerçant l'a
 * transformée en commande ou réglée autrement, « ignorée » sinon.
 */
enum StatutDemandeWhatsapp: string
{
    case Nouvelle = 'nouvelle';
    case Traitee = 'traitee';
    case Ignoree = 'ignoree';

    public function libelle(): string
    {
        return match ($this) {
            self::Nouvelle => 'Nouvelle',
            self::Traitee => 'Traitée',
            self::Ignoree => 'Ignorée',
        };
    }
}
