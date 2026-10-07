<?php

namespace Database\Seeders;

use App\Enums\StatutProduit;
use App\Models\Categorie;
use App\Models\Domaine;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Etablissements\MettreAJourLogoEtablissement;
use App\Services\Medias\GenerateurVariantesImage;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Correctif "données de démonstration réalistes" : le bug du logo
 * super-admin (voir DefinirEtablissementCible) est passé inaperçu parce
 * qu'aucun établissement de démo n'avait de logo, alors que les deux
 * établissements réels en ont un — les données de démo étaient plus pauvres
 * que la réalité. Règle désormais inscrite dans AGENTS.md : chaque champ
 * facultatif du formulaire d'identité est rempli ici, avec de vraies photos
 * générées par le système de médias (jamais une ligne "medias" fabriquée à
 * la main), et le catalogue couvre les cas réels (plusieurs photos, aucune
 * photo, épuisé, archivé) plutôt que des produits tous identiques.
 *
 * La taille de SORTIE des images générées est elle aussi réduite pendant
 * les tests (voir tests/TestCase) — mais le décodage et le redimensionnement
 * de la SOURCE coûtent déjà cher en eux-mêmes avant même d'encoder quoi que
 * ce soit : réduire seulement la sortie ne suffit pas, la source reste donc
 * petite en test elle aussi (voir tailleSourceImage()), réaliste seulement
 * en développement.
 */
class EtablissementsDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $chezAwa = Etablissement::factory()->boutique()->create([
            'nom' => 'Chez Awa',
            'slug' => 'chez-awa',
            'description' => "L'épicerie de quartier d'Awa : produits frais livrés tous les matins, prix honnêtes, service en moins de 10 minutes.",
            'couleur_accent' => '#146c43',
            'telephone_whatsapp' => '+2250701020304',
            'telephone_fixe' => '+2252720304050',
            'email_contact' => 'contact@chez-awa.example.test',
            'adresse' => 'Rue des Jardins, Cocody, Abidjan',
            'horaires' => $this->horairesBoutique(),
            'lien_facebook' => 'https://facebook.com/chezawa',
            'lien_instagram' => 'https://instagram.com/chez.awa',
            'lien_tiktok' => null,
            'lien_site_web' => 'https://chez-awa.example.test',
        ]);

        Domaine::factory()->for($chezAwa)->create([
            'hote' => 'chez-awa.localhost',
            'est_principal' => true,
        ]);

        $maquisDuPort = Etablissement::factory()->restaurant()->create([
            'nom' => 'Maquis du Port',
            'slug' => 'maquis-du-port',
            'description' => 'Grillades et braisés face au port, ambiance conviviale, musique live le week-end.',
            'couleur_accent' => '#1D4ED8',
            'telephone_whatsapp' => '+2250506070809',
            'telephone_fixe' => null,
            'email_contact' => 'contact@maquis-du-port.example.test',
            'adresse' => 'Boulevard de la Lagune, Port-Bouët, Abidjan',
            'horaires' => $this->horairesRestaurant(),
            'lien_facebook' => 'https://facebook.com/maquisduport',
            'lien_instagram' => null,
            'lien_tiktok' => 'https://tiktok.com/@maquis.du.port',
            'lien_site_web' => null,
        ]);

        Domaine::factory()->for($maquisDuPort)->create([
            'hote' => 'maquis-du-port.localhost',
            'est_principal' => true,
        ]);

        $this->poserLogo($chezAwa, 'logo-chez-awa.jpg');
        $this->poserLogo($maquisDuPort, 'logo-maquis-du-port.jpg');

        $this->peuplerBoutique($chezAwa);
        $this->peuplerRestaurant($maquisDuPort);
    }

    /**
     * 1200px en développement (le format "grande" en a besoin pour ne pas
     * être agrandi, voir config/medias.php) ; une fraction de ça en test, où
     * seule la FORME des données compte (ligne "medias" réelle, bonnes
     * relations) — jamais le rendu visuel. Décoder et redimensionner une
     * source à cette taille des dizaines de fois par $this->seed() (donc
     * par test de la suite) coûterait sinon des minutes de CPU en pure
     * perte, même avec une taille de sortie déjà réduite (voir
     * tests/TestCase).
     */
    private function tailleSourceImage(): int
    {
        return app()->environment('testing') ? 20 : 1200;
    }

    private function horairesBoutique(): array
    {
        $ouvert = ['ouverture' => '08:00', 'fermeture' => '19:30', 'ferme' => false];

        return [
            'lundi' => $ouvert,
            'mardi' => $ouvert,
            'mercredi' => $ouvert,
            'jeudi' => $ouvert,
            'vendredi' => $ouvert,
            'samedi' => ['ouverture' => '08:00', 'fermeture' => '21:00', 'ferme' => false],
            'dimanche' => ['ouverture' => null, 'fermeture' => null, 'ferme' => true],
        ];
    }

    private function horairesRestaurant(): array
    {
        $soir = ['ouverture' => '18:00', 'fermeture' => '00:00', 'ferme' => false];

        return [
            'lundi' => ['ouverture' => null, 'fermeture' => null, 'ferme' => true],
            'mardi' => $soir,
            'mercredi' => $soir,
            'jeudi' => $soir,
            'vendredi' => ['ouverture' => '18:00', 'fermeture' => '02:00', 'ferme' => false],
            'samedi' => ['ouverture' => '12:00', 'fermeture' => '02:00', 'ferme' => false],
            'dimanche' => ['ouverture' => '12:00', 'fermeture' => '23:00', 'ferme' => false],
        ];
    }

    /**
     * Passe par le VRAI service (voir MettreAJourLogoEtablissement), pas une
     * ligne "medias" fabriquée à la main : c'est justement l'écart entre des
     * données de démo appauvries et la réalité qui a laissé passer le bug du
     * logo super-admin sans qu'aucun test ne le voie.
     */
    private function poserLogo(Etablissement $etablissement, string $nomFichier): void
    {
        app(MettreAJourLogoEtablissement::class)->remplacer(
            $etablissement,
            UploadedFile::fake()->image($nomFichier, $this->tailleSourceImage(), $this->tailleSourceImage()),
        );
    }

    /**
     * Mêmes étapes que MediaProduitController::store() (génération des trois
     * formats, ligne "medias", rattachement ordonné) — il n'existe pas de
     * service dédié à extraire pour l'instant, cette méthode en est le
     * pendant pour les seeders plutôt qu'une ligne fabriquée à la main.
     */
    private function ajouterPhotos(Produit $produit, int $nombre): void
    {
        $disque = (string) config('medias.disque');
        $generateur = app(GenerateurVariantesImage::class);

        for ($i = 0; $i < $nombre; $i++) {
            $variantes = $generateur->generer(
                UploadedFile::fake()->image("produit-{$produit->id}-{$i}.jpg", $this->tailleSourceImage(), $this->tailleSourceImage()),
                $disque,
            );

            // etablissement_id n'est volontairement pas fillable sur Media
            // (voir Media::$fillable) : passer par la relation du produit,
            // pas par Media::create(), est le seul moyen de le renseigner
            // sans contourner cette règle du projet.
            $media = $produit->etablissement->medias()->create([
                'disque' => $disque,
                'chemin' => $variantes['moyenne']['webp'],
                'url' => Storage::disk($disque)->url($variantes['moyenne']['webp']),
                'type_mime' => 'image/webp',
                'taille' => $variantes['moyenne']['taille_webp'],
                'metadonnees_json' => ['variantes' => $variantes],
            ]);

            $produit->medias()->attach($media->id, ['ordre' => $i, 'est_principal' => $i === 0]);
        }
    }

    /**
     * mode_stock n'est jamais forcé ici : il est déduit du type de
     * l'établissement par Produit::booted(), exactement comme en
     * production. Le catalogue couvre volontairement les cas réels d'une
     * boutique plutôt que des produits tous identiques : plusieurs photos,
     * aucune photo, épuisé, archivé — voir la docblock de la classe.
     */
    private function peuplerBoutique(Etablissement $chezAwa): void
    {
        [$categorieA, $categorieB] = Categorie::factory()->for($chezAwa)->count(2)->create();

        $avecPlusieursPhotos = Produit::factory()->for($chezAwa)->publie()->create(['categorie_id' => $categorieA->id]);
        $this->ajouterPhotos($avecPlusieursPhotos, 3);

        // Sans aucune photo : cas réel et fréquent juste après la création
        // d'un produit, avant que le commerçant n'ait eu le temps d'en
        // ajouter une.
        Produit::factory()->for($chezAwa)->publie()->create([
            'categorie_id' => $categorieB->id,
            'quantite_stock' => 1,
        ]);

        $avecVariantes = Produit::factory()->for($chezAwa)->publie()->avecVariantes(2)->create([
            'categorie_id' => $categorieA->id,
        ]);
        $this->ajouterPhotos($avecVariantes, 1);

        Produit::factory()->for($chezAwa)->publie()->epuise()->create(['categorie_id' => $categorieB->id]);

        $archive = Produit::factory()->for($chezAwa)->create([
            'categorie_id' => $categorieA->id,
            'statut' => StatutProduit::Archive,
        ]);
        $this->ajouterPhotos($archive, 1);
    }

    private function peuplerRestaurant(Etablissement $maquisDuPort): void
    {
        [$categorieA, $categorieB] = Categorie::factory()->for($maquisDuPort)->count(2)->create();

        $avecPlusieursPhotos = Produit::factory()->for($maquisDuPort)->publie()->create(['categorie_id' => $categorieA->id]);
        $this->ajouterPhotos($avecPlusieursPhotos, 2);

        Produit::factory()->for($maquisDuPort)->publie()->create(['categorie_id' => $categorieB->id]);

        $avecUnePhoto = Produit::factory()->for($maquisDuPort)->publie()->create(['categorie_id' => $categorieA->id]);
        $this->ajouterPhotos($avecUnePhoto, 1);

        Produit::factory()->for($maquisDuPort)->publie()->epuise()->create(['categorie_id' => $categorieB->id]);

        $archive = Produit::factory()->for($maquisDuPort)->create([
            'categorie_id' => $categorieB->id,
            'statut' => StatutProduit::Archive,
        ]);
        $this->ajouterPhotos($archive, 1);
    }
}
