<?php

namespace App\Services\Medias;

use App\Exceptions\ImageTropGrandeException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

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
     * Mémoire : une photo de téléphone de 12 mégapixels occupe environ
     * 48 Mo une fois décodée. L'ancienne version clonait cet original pour
     * chaque format, et dépassait les 128 Mo d'une requête PHP de
     * production (erreur fatale, 500 muette). L'original est désormais
     * décodé une fois et réduit une fois à une taille de travail : son plus
     * petit côté ramené au plus grand format demandé, pour qu'aucun format
     * ne soit agrandi ni recadré à partir de trop peu de pixels. Les six
     * fichiers naissent de cette version réduite.
     *
     * @param  array<string, int>|null  $formats
     * @return array<string, array{webp: string, jpg: string, largeur: int, hauteur: int, taille_webp: int, taille_jpg: int}>
     *
     * @throws ImageTropGrandeException avant tout décodage, voir medias.pixels_max
     */
    public function generer(UploadedFile $fichier, string $disque, ?array $formats = null, bool $carre = false): array
    {
        $formats ??= config('medias.formats');
        $travail = $this->manager->read($this->versionDeTravail($fichier->getRealPath(), max($formats)));

        $identifiant = (string) Str::uuid();
        $qualiteWebp = (int) config('medias.qualite_webp');
        $qualiteJpeg = (int) config('medias.qualite_jpeg');

        $variantes = [];

        foreach ($formats as $nom => $dimension) {
            $redimensionnee = $carre || $nom === 'vignette'
                ? (clone $travail)->cover($dimension, $dimension)
                : (clone $travail)->scaleDown(width: $dimension);

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
     * Décode l'original avec GD directement, une seule fois, le recopie
     * réduit dans une image neuve, puis le libère. Intervention n'intervient
     * qu'après, sur la petite version : appliqué à l'original, il en faisait
     * des copies pleine taille au décodage et au redimensionnement (mesuré :
     * une photo de 20 mégapixels dépassait 128 Mo).
     *
     * Les dimensions sont lues d'abord dans l'en-tête du fichier, sans
     * décoder un pixel : une image de 50 Ko peut annoncer 20 000 × 20 000.
     *
     * L'orientation EXIF d'une photo de téléphone est appliquée à la
     * version réduite, jamais à l'original (une rotation pleine taille
     * coûterait une copie de plus), et seulement si l'extension exif est
     * chargée.
     *
     * @throws ImageTropGrandeException
     * @throws RuntimeException fichier qui n'est pas une image décodable
     */
    private function versionDeTravail(string $chemin, int $plusGrandFormat): GdImage
    {
        $infos = @getimagesize($chemin);
        if ($infos === false) {
            throw new RuntimeException("Le fichier n'est pas une image.");
        }

        [$largeur, $hauteur, $type] = $infos;
        $pixelsMax = (int) config('medias.pixels_max');
        if ($largeur * $hauteur > $pixelsMax) {
            throw ImageTropGrandeException::pour($largeur, $hauteur, $pixelsMax);
        }

        $original = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($chemin),
            IMAGETYPE_PNG => @imagecreatefrompng($chemin),
            IMAGETYPE_WEBP => @imagecreatefromwebp($chemin),
            IMAGETYPE_GIF => @imagecreatefromgif($chemin),
            default => false,
        };
        if ($original === false) {
            throw new RuntimeException("L'image ne se décode pas.");
        }

        $facteur = min(1, $plusGrandFormat / min($largeur, $hauteur));
        $travail = imagecreatetruecolor(max(1, (int) ceil($largeur * $facteur)), max(1, (int) ceil($hauteur * $facteur)));
        imagealphablending($travail, false);
        imagesavealpha($travail, true);
        imagecopyresampled($travail, $original, 0, 0, 0, 0, imagesx($travail), imagesy($travail), $largeur, $hauteur);
        unset($original);

        return $type === IMAGETYPE_JPEG ? $this->orienter($travail, $chemin) : $travail;
    }

    /**
     * Valeurs EXIF 3, 6 et 8 : les seules qu'un téléphone écrit en
     * pratique (retourné, portrait tenu d'un côté ou de l'autre).
     */
    private function orienter(GdImage $image, string $chemin): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($chemin)['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
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
