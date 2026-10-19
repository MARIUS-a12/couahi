<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conditions par défaut d'un nouvel abonnement
    |--------------------------------------------------------------------------
    |
    | Appliquées à la création de chaque établissement (voir
    | Abonnement::ouvrirEssaiPour). Le montant et les jours de grâce se
    | règlent ensuite établissement par établissement depuis sa fiche
    | super-admin ; la durée d'essai, elle, ne sert qu'à cette création.
    | Montant en francs CFA, toujours un entier : le franc CFA n'a pas de
    | subdivision en usage.
    |
    */

    'montant_mensuel_defaut' => (int) env('ABONNEMENT_MONTANT_MENSUEL', 10000),

    'jours_essai' => (int) env('ABONNEMENT_JOURS_ESSAI', 30),

    'jours_de_grace_defaut' => (int) env('ABONNEMENT_JOURS_DE_GRACE', 7),

    /*
    |--------------------------------------------------------------------------
    | Seuil d'avertissement
    |--------------------------------------------------------------------------
    |
    | Le bandeau du back-office apparaît quand l'échéance est à moins de ce
    | nombre de jours, ou déjà dépassée.
    |
    */

    'jours_avertissement' => 7,

    /*
    |--------------------------------------------------------------------------
    | Coordonnées de règlement hors ligne
    |--------------------------------------------------------------------------
    |
    | Affichées au commerçant sur sa page d'abonnement tant que le paiement
    | en ligne de l'abonnement n'existe pas : à qui envoyer l'argent, et sur
    | quel numéro Mobile Money. Jamais écrites en dur dans le code — vides
    | tant qu'elles ne sont pas renseignées, et l'interface le dit.
    |
    */

    'beneficiaire' => env('ABONNEMENT_BENEFICIAIRE'),

    'numero_mobile_money' => env('ABONNEMENT_NUMERO_MOBILE_MONEY'),

    /*
    |--------------------------------------------------------------------------
    | Compte CinetPay DE LA PLATEFORME — à ne JAMAIS confondre
    |--------------------------------------------------------------------------
    |
    | Il existe DEUX configurations CinetPay dans ce projet :
    |
    | 1. Celle DE CHAQUE ÉTABLISSEMENT (colonnes cinetpay_* chiffrées sur la
    |    table "etablissements", voir ConfigurerPaiementEtablissement) :
    |    elle encaisse les VENTES du commerçant à ses clients. L'argent va
    |    chez le commerçant, jamais chez nous.
    |
    | 2. CELLE-CI, unique, qui encaisse les ABONNEMENTS payés par les
    |    commerçants à la plateforme. L'argent va chez l'exploitant de la
    |    plateforme. Elle ne vit JAMAIS en base — seulement dans
    |    l'environnement du serveur — et ne doit jamais servir à encaisser
    |    une vente d'un commerçant, ni l'inverse.
    |
    | Aucun appel à CinetPay n'utilise encore ces valeurs : elles attendent
    | le branchement du paiement en ligne des abonnements.
    |
    */

    'cinetpay_plateforme' => [
        'site_id' => env('PLATEFORME_CINETPAY_SITE_ID'),
        'cle_api' => env('PLATEFORME_CINETPAY_CLE_API'),
        'secret' => env('PLATEFORME_CINETPAY_SECRET'),
    ],

];
