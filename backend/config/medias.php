<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disque de stockage des médias
    |--------------------------------------------------------------------------
    |
    | "public" en développement (fichiers servis via le lien symbolique
    | storage:link). Pour basculer vers un stockage objet en production, il
    | suffit de définir MEDIAS_DISQUE=s3 (et les variables AWS_*) dans l'env
    | — aucun code à toucher, voir config/filesystems.php pour la définition
    | du disque "s3".
    */
    'disque' => env('MEDIAS_DISQUE', 'public'),

    'max_par_produit' => 15,

    'poids_max_octets' => 8 * 1024 * 1024,

    /*
    | Au-delà, l'image est refusée avant d'être décodée (voir
    | GenerateurVariantesImage) : décodée, elle ne tiendrait pas dans les
    | 128 Mo d'une requête PHP de production (environ 5 octets par pixel).
    | Valeur mesurée, pas devinée : EnvoiPhotoSousLimiteMemoireTest envoie
    | une image à ce plafond à un vrai serveur lancé avec 24 Mo de moins
    | que la production. 20 mégapixels y échouaient ; 16 couvrent les
    | photos de téléphone (12 Mpx, et 16 Mpx en 4:3).
    */
    'pixels_max' => 16_000_000,

    'qualite_webp' => 80,

    'qualite_jpeg' => 80,

    /*
    |--------------------------------------------------------------------------
    | Formats générés
    |--------------------------------------------------------------------------
    |
    | vignette : carrée (recadrée), pour les listes du back-office.
    | moyenne / grande : largeur maximale, hauteur proportionnelle, jamais
    | agrandies au-delà de la taille d'origine.
    */
    'formats' => [
        'vignette' => 150,
        'moyenne' => 600,
        'grande' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Formats du logo d'établissement
    |--------------------------------------------------------------------------
    |
    | Les deux toujours carrées (voir GenerateurVariantesImage, appelé avec
    | carre: true) : petit pour l'en-tête de la vitrine, grand pour les
    | affichages plus larges (fiche établissement, pied de page).
    */
    'formats_logo' => [
        'petit' => 64,
        'grand' => 256,
    ],

];
