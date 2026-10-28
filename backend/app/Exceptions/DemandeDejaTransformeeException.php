<?php

namespace App\Exceptions;

use App\Models\Commande;
use RuntimeException;

/**
 * Lot D — une demande WhatsApp ne devient qu'une seule commande. Levée
 * quand la référence est déjà reliée à une commande : le serveur refuse,
 * même par requête forgée (voir SaisirCommandeManuelle).
 */
class DemandeDejaTransformeeException extends RuntimeException
{
    public function __construct(public readonly Commande $commande)
    {
        parent::__construct("Cette demande est déjà devenue la commande {$commande->numero}.");
    }
}
