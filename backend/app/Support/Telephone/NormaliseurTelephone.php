<?php

namespace App\Support\Telephone;

/**
 * Un seul format canonique en base pour un numéro ivoirien, quelle que soit
 * la façon dont le client l'a saisi : "0X XX XX XX XX" et "+225" suivi des
 * mêmes 10 chiffres (la réforme de numérotation de 2021 ne retire pas le 0
 * initial à l'international, contrairement à l'ancien plan) doivent désigner
 * le même client — voir TrouverOuCreerClient, qui cherche par ce numéro déjà
 * normalisé.
 */
final class NormaliseurTelephone
{
    public static function normaliser(string $brut): ?string
    {
        $compact = preg_replace('/\s+/', '', trim($brut));

        if ($compact === null) {
            return null;
        }

        if (preg_match('/^0\d{9}$/', $compact) === 1) {
            return '+225'.$compact;
        }

        if (preg_match('/^\+225(0\d{9})$/', $compact, $correspondances) === 1) {
            return '+225'.$correspondances[1];
        }

        return null;
    }

    /**
     * Étape 13 — forme lisible d'un numéro DÉJÀ normalisé, telle qu'un client
     * la tape dans son application : "07 01 02 03 04". Renvoie la valeur
     * telle quelle si elle n'est pas au format canonique.
     */
    public static function formaterLocal(string $normalise): string
    {
        if (preg_match('/^\+225(0\d{9})$/', $normalise, $correspondances) !== 1) {
            return $normalise;
        }

        return trim(chunk_split($correspondances[1], 2, ' '));
    }
}
