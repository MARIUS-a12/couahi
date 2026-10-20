<?php

namespace Database\Seeders;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Fabrique les images SOURCES des données de démonstration : un logo (carré
 * à la couleur de marque, initiales au centre) et des photos de produit
 * (rectangle coloré portant le nom du produit). Remplace
 * UploadedFile::fake()->image(), qui ne produit que des carrés noirs unis —
 * impossible de juger du rendu réel d'une vitrine avec ça.
 *
 * Ne produit qu'un fichier JPEG temporaire, enveloppé dans un UploadedFile :
 * l'appelant le fait passer par le même pipeline que les vraies images
 * (MettreAJourLogoEtablissement, GenerateurVariantesImage). Aplats de
 * couleur et texte : quelques dizaines de Ko même à 1200 px.
 */
class ImagesDemo
{
    /** Teintes franches et distinctes, pour que deux produits voisins ne se confondent pas. */
    private const PALETTE = ['#9A3412', '#1D4ED8', '#146C43', '#7C3AED', '#B45309', '#BE123C', '#0F766E', '#4338CA'];

    private const POLICE = 'resources/fonts/Poppins-Bold.ttf';

    /**
     * En dessous, le texte serait illisible de toute façon : il n'est pas
     * tracé. C'est le cas des tests (sources de 20 px, voir
     * EtablissementsDemoSeeder::tailleSourceImage) — le tracé FreeType
     * doublait à lui seul la durée d'un seed().
     */
    private const TAILLE_MINIMALE_TEXTE = 100;

    public function __construct(private readonly int $taille) {}

    public function logo(string $nom, string $couleur, string $nomFichier): UploadedFile
    {
        $image = (new ImageManager(new Driver))->create($this->taille, $this->taille)->fill($couleur);

        if ($this->taille >= self::TAILLE_MINIMALE_TEXTE) {
            $image->text($this->initiales($nom), intdiv($this->taille, 2), intdiv($this->taille, 2), function (FontFactory $police) use ($couleur) {
                $police->filename(base_path(self::POLICE));
                $police->size($this->taille * 0.4);
                $police->color($this->couleurTexte($couleur));
                $police->align('center');
                $police->valign('middle');
            });
        }

        return $this->enFichier($image->toJpeg(85), $nomFichier);
    }

    /** $rang : la photo n° 1, 2, 3 d'un même produit change de teinte. */
    public function photoProduit(string $nomProduit, int $rang, string $nomFichier): UploadedFile
    {
        $couleur = self::PALETTE[(crc32($nomProduit) + $rang) % count(self::PALETTE)];
        $largeur = $this->taille;
        $hauteur = (int) round($this->taille * 0.75);

        $image = (new ImageManager(new Driver))->create($largeur, $hauteur)->fill($couleur);

        if ($this->taille >= self::TAILLE_MINIMALE_TEXTE) {
            $image->text($nomProduit, intdiv($largeur, 2), intdiv($hauteur, 2), function (FontFactory $police) use ($couleur, $largeur) {
                $police->filename(base_path(self::POLICE));
                $police->size($largeur * 0.075);
                $police->color($this->couleurTexte($couleur));
                $police->align('center');
                $police->valign('middle');
                $police->lineHeight(1.5);
                $police->wrap((int) ($largeur * 0.8));
            });
        }

        return $this->enFichier($image->toJpeg(85), $nomFichier);
    }

    /** "Wax Adjamé" → "WA", "Chez Awa" → "CA", "Maquis du Port" → "MP" (les mots de liaison sautent). */
    private function initiales(string $nom): string
    {
        $mots = array_filter(
            preg_split('/\s+/u', trim($nom)) ?: [],
            fn (string $mot) => ! in_array(mb_strtolower($mot), ['de', 'du', 'des', 'la', 'le', 'les', "d'", 'et'], true),
        );

        return mb_strtoupper(implode('', array_map(fn (string $mot) => mb_substr($mot, 0, 1), array_slice(array_values($mots), 0, 2))));
    }

    /** Blanc ou presque-noir selon la luminance du fond (contraste WCAG). */
    private function couleurTexte(string $fond): string
    {
        [$r, $g, $b] = array_map(
            function (string $canal) {
                $c = hexdec($canal) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($fond, '#'), 2),
        );
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#FFFFFF' : '#111827';
    }

    private function enFichier(string $contenu, string $nomFichier): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($chemin, $contenu);

        // La source n'a plus d'usage une fois passée par le pipeline, mais
        // l'appelant la lit APRÈS ce retour : effacée en fin de processus,
        // pour que les ~190 seed() d'une suite de tests ne laissent rien.
        register_shutdown_function(function () use ($chemin) {
            if (is_file($chemin)) {
                unlink($chemin);
            }
        });

        return new UploadedFile($chemin, $nomFichier, 'image/jpeg', null, true);
    }
}
