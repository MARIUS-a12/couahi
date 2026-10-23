<?php

namespace Database\Seeders;

use App\Services\Medias\GenerateurVariantesImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Le VRAI générateur (le premier appel pour une image donnée passe par son
 * encodage, rien n'est fabriqué à la main), mais qui retient ce qu'il a
 * produit le temps du processus : la même source, aux mêmes formats,
 * redonne les mêmes fichiers à l'octet près, réécrits sous un nouvel
 * identifiant. Réservé aux données de démonstration : le seeder tourne à
 * chaque test de la suite, et ré-encoder ~20 images identiques à chaque
 * fois en était l'essentiel de la durée (92 s → 14 s sur 89 tests une fois
 * les images retirées, mesure du 2026-10-23). Les envois réels du
 * commerçant, eux, passent toujours par GenerateurVariantesImage seul.
 */
class GenerateurVariantesImageDemo extends GenerateurVariantesImage
{
    /** @var array<string, array<string, array{webp: string, jpg: string, largeur: int, hauteur: int}>> */
    private static array $produits = [];

    public function generer(UploadedFile $fichier, string $disque, ?array $formats = null, bool $carre = false): array
    {
        $formats ??= config('medias.formats');
        $cle = implode('|', [
            md5_file($fichier->getRealPath()),
            json_encode($formats),
            $carre ? 'carre' : 'libre',
            config('medias.qualite_webp'),
            config('medias.qualite_jpeg'),
        ]);

        if (! isset(self::$produits[$cle])) {
            $variantes = parent::generer($fichier, $disque, $formats, $carre);
            self::$produits[$cle] = array_map(fn (array $variante) => [
                'webp' => Storage::disk($disque)->get($variante['webp']),
                'jpg' => Storage::disk($disque)->get($variante['jpg']),
                'largeur' => $variante['largeur'],
                'hauteur' => $variante['hauteur'],
            ], $variantes);

            return $variantes;
        }

        // Même forme de retour que le parent, sous un identifiant neuf : deux
        // médias ne partagent jamais un fichier (supprimer l'un n'efface pas
        // l'autre).
        $identifiant = (string) Str::uuid();
        $variantes = [];

        foreach (self::$produits[$cle] as $nom => $contenu) {
            $cheminWebp = "medias/{$identifiant}-{$nom}.webp";
            $cheminJpg = "medias/{$identifiant}-{$nom}.jpg";
            Storage::disk($disque)->put($cheminWebp, $contenu['webp']);
            Storage::disk($disque)->put($cheminJpg, $contenu['jpg']);

            $variantes[$nom] = [
                'webp' => $cheminWebp,
                'jpg' => $cheminJpg,
                'largeur' => $contenu['largeur'],
                'hauteur' => $contenu['hauteur'],
                'taille_webp' => strlen($contenu['webp']),
                'taille_jpg' => strlen($contenu['jpg']),
            ];
        }

        return $variantes;
    }
}
