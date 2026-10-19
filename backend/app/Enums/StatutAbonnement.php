<?php

namespace App\Enums;

/**
 * Seul Suspendu coupe quelque chose — la vitrine, et elle seule (voir
 * VerifierAbonnementVitrine). Le back-office reste ouvert dans tous les
 * états : un commerçant en défaut doit toujours pouvoir payer.
 */
enum StatutAbonnement: string
{
    case Essai = 'essai';
    case Actif = 'actif';
    case EnRetard = 'en_retard';
    case Suspendu = 'suspendu';
}
