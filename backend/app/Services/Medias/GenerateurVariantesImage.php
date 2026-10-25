<?php

namespace App\Services\Medias;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Reçoit la photo envoyée par le commerçant (3 à 8 Mo, prise au téléphone) et
 * produit les trois formats servis par l'API : vignette (150 px, carrée),
 * moyenne (600 px de large) et grande (1200 px de large). Chaque format est
 * encodé en WebP (qualité 80) avec un repli JPEG, pour que le frontend puisse
 * utiliser <picture> sans dépendre du support WebP du navigateur.
 *
 * Ne touche jamais à la base de données : ne fait qu'écrire des fichiers sur
 * le disque donné et retourner leurs chemins. C'est à l'appelant (voir
 * MediaProduitController) de décider quoi en faire, et de les effacer si la
 * transaction qui suit échoue.
 */
class GenerateurVariantesImage
{
    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * $formats : par défaut config('medias.formats') (produits — seule
     * "vignette" est carrée). $carre force TOUS les formats passés à être
     * carrés (recadrés) — utilisé pour le logo d'établissement
     * (config('medias.formats_logo')), où les deux tailles doivent l'être,
     * voir MettreAJourLogoEtablissement.
     *
     * @param  array<string, int>|null  $formats
     * @return array<string, array{webp: string, jpg: string, largeur: int, hauteur: int, taille_webp: int, taille_jpg: int}>
     */
    public function generer(UploadedFile $fichier, string $disque, ?array $formats = null, bool $carre = false): array
    {
        $image = $this->manager->read($fichier->getRealPath());
        $identifiant = (string) Str::uuid();
        $qualiteWebp = (int) config('medias.qualite_webp');
        $qualiteJpeg = (int) config('medias.qualite_jpeg');

        $variantes = [];

        foreach ($formats ?? config('medias.formats') as $nom => $dimension) {
            $redimensionnee = $carre || $nom === 'vignette'
                ? (clone $image)->cover($dimension, $dimension)
                : (clone $image)->scaleDown(width: $dimension);

            $cheminWebp = "medias/{$identifiant}-{$nom}.webp";
            $cheminJpg = "medias/{$identifiant}-{$nom}.jpg";

            Storage::disk($disque)->put($cheminWebp, (string) $redimensionnee->toWebp($qualiteWebp));
            Storage::disk($disque)->put($cheminJpg, (string) $redimensionnee->toJpeg($qualiteJpeg));

            $variantes[$nom] = [
                'webp' => $cheminWebp,
                'jpg' => $cheminJpg,
                'largeur' => $redimensionnee->width(),
                'hauteur' => $redimensionnee->height(),
                'taille_webp' => Storage::disk($disque)->size($cheminWebp),
                'taille_jpg' => Storage::disk($disque)->size($cheminJpg),
            ];
        }

        return $variantes;
    }

    /**
     * Retire du disque tous les fichiers produits par generer() ci-dessus —
     * utilisé quand la transaction qui devait les référencer en base échoue
     * (limite de photos atteinte par un envoi concurrent).
     */
    public function supprimer(string $disque, array $variantes): void
    {
        $chemins = [];

        foreach ($variantes as $variante) {
            $chemins[] = $variante['webp'];
            $chemins[] = $variante['jpg'];
        }

        Storage::disk($disque)->delete($chemins);
    }
}
