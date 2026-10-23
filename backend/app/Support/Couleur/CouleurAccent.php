<?php

namespace App\Support\Couleur;

/**
 * La couleur d'accent d'un établissement qui n'en a pas choisi. Définie ICI
 * seulement : la vitrine la reçoit déjà résolue (EtablissementVitrineResource),
 * les formulaires du back-office la reçoivent de l'API
 * (couleur_accent_par_defaut) — aucun composant, aucune migration ne la
 * recopie. CouleurAccentDefinieUneFoisTest y veille.
 */
final class CouleurAccent
{
    public const PAR_DEFAUT = '#146c43';
}
