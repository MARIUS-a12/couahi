<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée par GenerateurVariantesImage AVANT tout décodage, sur la seule
 * lecture des dimensions : une image au-delà de medias.pixels_max ne
 * tiendrait pas dans la mémoire d'une requête PHP en production (128 Mo),
 * et la requête mourrait sur une erreur fatale que rien ne rattrape — une
 * 500 muette. Le message s'adresse au commerçant et lui dit quoi faire.
 */
class ImageTropGrandeException extends RuntimeException
{
    public static function pour(int $largeur, int $hauteur, int $pixelsMax): self
    {
        $megapixels = (int) round($largeur * $hauteur / 1_000_000);
        $maximum = intdiv($pixelsMax, 1_000_000);

        return new self(
            "Cette photo est trop grande ({$megapixels} mégapixels, {$maximum} au maximum). "
            .'Envoyez-la depuis votre téléphone, qui la réduit automatiquement, '
            .'ou enregistrez-la en plus petit avant de l’envoyer.'
        );
    }
}
