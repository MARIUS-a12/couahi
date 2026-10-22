<?php

namespace App\Enums;

/**
 * Les pages dont la consultation par le super-admin est journalisée : celles
 * qui montrent l'activité commerciale de l'établissement. Identité,
 * abonnement et configuration de paiement n'en font volontairement pas
 * partie (voir decisions.md).
 */
enum PageConsultee: string
{
    case Commandes = 'commandes';
    case DetailCommande = 'detail_commande';
    case Clients = 'clients';
    case ChiffreAffaires = 'chiffre_affaires';

    public function libelle(): string
    {
        return match ($this) {
            self::Commandes => 'Liste des commandes',
            self::DetailCommande => "Détail d'une commande",
            self::Clients => 'Clients',
            self::ChiffreAffaires => "Chiffre d'affaires",
        };
    }
}
