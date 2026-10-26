<?php

namespace App\Exceptions;

use App\Models\Commande;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Une commande saisie au back-office ressemble à une commande déjà en cours
 * (même téléphone depuis moins de 24 heures, ou même référence WhatsApp).
 * Un avertissement, jamais un blocage : le commerçant voit les commandes en
 * question et confirme s'il veut quand même créer celle-ci.
 */
class DoublonPossibleException extends RuntimeException
{
    /**
     * @param  Collection<int, Commande>  $commandes
     */
    public function __construct(public readonly Collection $commandes)
    {
        parent::__construct('Une commande semblable est déjà en cours.');
    }
}
