<?php

namespace Database\Seeders;

use App\Enums\OperateurMobileMoney;
use App\Enums\StatutProduit;
use App\Models\Categorie;
use App\Models\Domaine;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\VarianteProduit;
use App\Services\Etablissements\MettreAJourLogoEtablissement;
use Illuminate\Database\Seeder;
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
 * photo, épuisé, archivé) plutôt que des produits tous identiques. Les
 * images sont lisibles (voir ImagesDemo : logo aux initiales sur la couleur
 * de marque, photo portant le nom du produit), jamais des carrés noirs : on
 * doit pouvoir juger du rendu réel d'une vitrine.
 *
 * Les trois configurations de paiement sont couvertes : chez-awa et
 * maquis-du-port n'en ont aucune (panier et WhatsApp seulement), Wax Adjamé
 * n'a que le Mobile Money, avec un catalogue en STOCK COMPTÉ — le cas qui a
 * révélé que la tâche d'expiration ne s'exécutait jamais (une commande
 * Mobile Money retient du stock compté, que rien ne relâchait). CinetPay
 * n'est volontairement configuré nulle part : de faux identifiants dans un
 * seeder finissent un jour par être pris pour des vrais.
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

        // Créée en dernier : les identifiants des deux premiers
        // établissements, et de leurs produits, restent ceux qu'ils ont
        // toujours eus.
        $waxAdjame = Etablissement::factory()->boutique()->create([
            'nom' => 'Wax Adjamé',
            'slug' => 'wax-adjame',
            'description' => "Pagnes wax, bazin et kita à la pièce, directement depuis le marché d'Adjamé. Livraison dans tout Abidjan.",
            'couleur_accent' => '#9A3412',
            'telephone_whatsapp' => '+2250758123456',
            'telephone_fixe' => '+2252720371122',
            'email_contact' => 'contact@wax-adjame.example.test',
            'adresse' => "Grand marché d'Adjamé, allée des tissus, Abidjan",
            'horaires' => $this->horairesMarche(),
            'lien_facebook' => 'https://facebook.com/waxadjame',
            'lien_instagram' => 'https://instagram.com/wax.adjame',
            'lien_tiktok' => 'https://tiktok.com/@wax.adjame',
            'lien_site_web' => null,
            // Mobile Money seul, assigné directement : passer par
            // ConfigurerMobileMoney écrirait une ligne de journal d'audit
            // sans auteur. Numéro déjà au format stocké (E.164).
            'mm_numero' => '+2250758123456',
            'mm_operateur' => OperateurMobileMoney::OrangeMoney,
            'mm_beneficiaire' => 'DIALLO FATOU AMINATA',
        ]);

        Domaine::factory()->for($waxAdjame)->create([
            'hote' => 'wax-adjame.localhost',
            'est_principal' => true,
        ]);

        $this->poserLogo($chezAwa, 'logo-chez-awa.jpg');
        $this->poserLogo($maquisDuPort, 'logo-maquis-du-port.jpg');
        $this->poserLogo($waxAdjame, 'logo-wax-adjame.jpg');

        $this->peuplerBoutique($chezAwa);
        $this->peuplerRestaurant($maquisDuPort);
        $this->peuplerTissus($waxAdjame);
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

    /** Horaires de marché : tôt le matin, et le dimanche matin seulement. */
    private function horairesMarche(): array
    {
        $jour = ['ouverture' => '07:30', 'fermeture' => '18:30', 'ferme' => false];

        return [
            'lundi' => $jour,
            'mardi' => $jour,
            'mercredi' => $jour,
            'jeudi' => $jour,
            'vendredi' => $jour,
            'samedi' => $jour,
            'dimanche' => ['ouverture' => '09:00', 'fermeture' => '13:00', 'ferme' => false],
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
        // Le vrai service, avec le générateur de démo qui ne ré-encode pas
        // une image déjà produite dans ce processus (voir sa docblock).
        (new MettreAJourLogoEtablissement(new GenerateurVariantesImageDemo))->remplacer(
            $etablissement,
            (new ImagesDemo($this->tailleSourceImage()))->logo($etablissement->nom, $etablissement->couleur_accent, $nomFichier),
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
        $generateur = new GenerateurVariantesImageDemo;

        for ($i = 0; $i < $nombre; $i++) {
            $variantes = $generateur->generer(
                (new ImagesDemo($this->tailleSourceImage()))->photoProduit($produit->nom, $i, "produit-{$produit->id}-{$i}.jpg"),
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
     * L'épicerie d'Awa : de vrais produits plutôt que du faux latin (voir
     * AGENTS.md, données de démo réalistes). mode_stock n'est jamais forcé
     * ici : il est déduit du type de l'établissement par Produit::booted(),
     * exactement comme en production. Les cas limites sont dans les données,
     * pas découverts en production : nom long et accentué, plusieurs
     * photos, aucune photo, variantes, épuisé, archivé. Quatre produits
     * publiés et un archivé : des tests comptent sur cette forme.
     */
    private function peuplerBoutique(Etablissement $chezAwa): void
    {
        $epicerie = Categorie::factory()->for($chezAwa)->create(['nom' => 'Épicerie', 'slug' => 'epicerie', 'ordre' => 1]);
        $frais = Categorie::factory()->for($chezAwa)->create(['nom' => 'Produits frais', 'slug' => 'produits-frais', 'ordre' => 2]);

        $avecPlusieursPhotos = Produit::factory()->for($chezAwa)->publie()->create([
            'categorie_id' => $epicerie->id,
            'nom' => 'Huile de palme rouge non raffinée, pressée au village — bouteille de 1 litre',
            'slug' => 'huile-de-palme-rouge-non-raffinee-bouteille-1-litre',
            'description' => 'Huile de palme artisanale de Dabou, pressée à froid. Idéale pour la sauce graine et le kedjenou.',
            'prix' => 1500,
            'quantite_stock' => 24,
        ]);
        $this->ajouterPhotos($avecPlusieursPhotos, 3);

        // Sans aucune photo, et une seule pièce : cas réel et fréquent juste
        // après la création d'un produit, avant que le commerçant n'ait eu
        // le temps d'en ajouter une.
        Produit::factory()->for($chezAwa)->publie()->create([
            'categorie_id' => $frais->id,
            'nom' => 'Attiéké frais du jour',
            'slug' => 'attieke-frais-du-jour',
            'description' => 'Sachet de 500 g, livré chaque matin depuis Jacqueville.',
            'prix' => 500,
            'quantite_stock' => 1,
        ]);

        $avecVariantes = Produit::factory()->for($chezAwa)->publie()->create([
            'categorie_id' => $epicerie->id,
            'nom' => 'Riz parfumé long grain',
            'slug' => 'riz-parfume-long-grain',
            'description' => 'Riz parfumé, grain long. Au kilo, en sac de 5 kg ou en sac de 25 kg.',
            'prix' => 1000,
            'quantite_stock' => 0,
        ]);
        foreach ([['1 kg', 1000, 40], ['Sac de 5 kg', 4500, 12], ['Sac de 25 kg', 21000, 3]] as [$nom, $prix, $stock]) {
            VarianteProduit::factory()->pourProduit($avecVariantes)->create([
                'nom' => $nom,
                'prix' => $prix,
                'quantite_stock' => $stock,
            ]);
        }
        $this->ajouterPhotos($avecVariantes, 1);

        Produit::factory()->for($chezAwa)->publie()->epuise()->create([
            'categorie_id' => $frais->id,
            'nom' => 'Banane plantain mûre (le régime)',
            'slug' => 'banane-plantain-mure-le-regime',
            'description' => "Régime de 10 à 12 doigts, pour l'alloco ou le foutou. Arrivage le mardi et le vendredi.",
            'prix' => 2500,
        ]);

        $archive = Produit::factory()->for($chezAwa)->create([
            'categorie_id' => $frais->id,
            'nom' => 'Lait caillé maison, bouteille de 50 cl',
            'slug' => 'lait-caille-maison-50-cl',
            'prix' => 700,
            'statut' => StatutProduit::Archive,
        ]);
        $this->ajouterPhotos($archive, 1);
    }

    /**
     * La carte d'un maquis d'Abidjan, prix de quartier (500 à 3 500 FCFA).
     * Restaurant : stock en mode interrupteur (déduit du type, jamais
     * forcé), une rupture s'y marque par "disponible" à faux. Couvre les
     * mêmes cas limites que l'épicerie : nom long et accentué sans photo,
     * variantes, rupture, archivé.
     */
    private function peuplerRestaurant(Etablissement $maquisDuPort): void
    {
        $plats = Categorie::factory()->for($maquisDuPort)->create(['nom' => 'Plats', 'slug' => 'plats', 'ordre' => 1]);
        $boissons = Categorie::factory()->for($maquisDuPort)->create(['nom' => 'Boissons', 'slug' => 'boissons', 'ordre' => 2]);

        $plat = fn (array $attributs) => Produit::factory()->for($maquisDuPort)->publie()->create(['categorie_id' => $plats->id, ...$attributs]);

        $this->ajouterPhotos($plat([
            'nom' => 'Attiéké poisson braisé',
            'slug' => 'attieke-poisson-braise',
            'description' => 'Carpe braisée au feu de bois, attiéké, oignons et piment frais.',
            'prix' => 3000,
        ]), 2);

        $this->ajouterPhotos($plat([
            'nom' => 'Alloco poulet',
            'slug' => 'alloco-poulet',
            'description' => 'Banane plantain frite et cuisse de poulet braisée, sauce tomate pimentée.',
            'prix' => 2500,
        ]), 1);

        $this->ajouterPhotos($plat([
            'nom' => 'Garba',
            'slug' => 'garba',
            'description' => 'Attiéké et thon frit, piment et oignon cru. Le classique de midi.',
            'prix' => 1000,
        ]), 1);

        $poulet = $plat([
            'nom' => 'Poulet braisé',
            'slug' => 'poulet-braise',
            'description' => 'Poulet bicyclette mariné et braisé, servi avec attiéké.',
            'prix' => 1500,
        ]);
        foreach ([['Quart', 1500], ['Demi', 3000]] as [$nom, $prix]) {
            VarianteProduit::factory()->pourProduit($poulet)->create(['nom' => $nom, 'prix' => $prix]);
        }
        $this->ajouterPhotos($poulet, 1);

        // Nom long et accentué, et aucune photo : les deux cas limites
        // d'affichage d'une carte réelle, réunis sur un même plat.
        $plat([
            'nom' => "Kedjenou de poulet à l'étouffée, cuit au canari, servi avec attiéké ou riz blanc",
            'slug' => 'kedjenou-de-poulet',
            'description' => "Poulet mijoté dans son jus avec tomates, aubergines et piment, sans une goutte d'eau.",
            'prix' => 3500,
        ]);

        $this->ajouterPhotos(Produit::factory()->for($maquisDuPort)->publie()->epuise()->create([
            'categorie_id' => $plats->id,
            'nom' => 'Riz sauce graine',
            'slug' => 'riz-sauce-graine',
            'description' => 'Sauce graine au poisson fumé et à la viande de bœuf. Servi le vendredi seulement.',
            'prix' => 2000,
        ]), 1);

        $boissonsCarte = [
            ['Jus de bissap', 'jus-de-bissap', "Fleurs d'hibiscus, menthe et sucre de canne, servi bien frais."],
            ['Jus de gingembre', 'jus-de-gingembre', 'Gingembre frais pressé et citron. Ça pique.'],
        ];
        foreach ($boissonsCarte as [$nom, $slug, $description]) {
            $this->ajouterPhotos(Produit::factory()->for($maquisDuPort)->publie()->create([
                'categorie_id' => $boissons->id,
                'nom' => $nom,
                'slug' => $slug,
                'description' => $description,
                'prix' => 500,
            ]), 1);
        }

        $archive = Produit::factory()->for($maquisDuPort)->create([
            'categorie_id' => $plats->id,
            'nom' => 'Placali sauce kopè',
            'slug' => 'placali-sauce-kope',
            'prix' => 2000,
            'statut' => StatutProduit::Archive,
        ]);
        $this->ajouterPhotos($archive, 1);
    }

    /**
     * De vrais noms de tissus plutôt que du faux latin : c'est la vitrine
     * du parcours Mobile Money. Tout est en stock compté (déduit du type
     * boutique, jamais forcé) et couvre les mêmes cas que chez-awa :
     * plusieurs photos, aucune photo, variantes, épuisé, archivé.
     */
    private function peuplerTissus(Etablissement $waxAdjame): void
    {
        $wax = Categorie::factory()->for($waxAdjame)->create(['nom' => 'Pagnes wax', 'slug' => 'pagnes-wax', 'ordre' => 1]);
        $bazin = Categorie::factory()->for($waxAdjame)->create(['nom' => 'Bazin et kita', 'slug' => 'bazin-et-kita', 'ordre' => 2]);

        $avecPlusieursPhotos = Produit::factory()->for($waxAdjame)->publie()->create([
            'categorie_id' => $wax->id,
            'nom' => 'Pagne wax hollandais 6 yards',
            'slug' => 'pagne-wax-hollandais-6-yards',
            'description' => 'Wax hollandais véritable, motif « Fleurs de mariage ». Coton 100 %, couleurs garanties au lavage.',
            'prix' => 15000,
            'quantite_stock' => 12,
        ]);
        $this->ajouterPhotos($avecPlusieursPhotos, 3);

        // Sans photo, et presque épuisé : le cas où une seule commande
        // Mobile Money non payée bloque tout le stock restant.
        Produit::factory()->for($waxAdjame)->publie()->create([
            'categorie_id' => $bazin->id,
            'nom' => 'Bazin riche brodé',
            'slug' => 'bazin-riche-brode',
            'description' => 'Bazin riche teint à la main, broderie dorée au col. Vendu par pièce de 5 mètres.',
            'prix' => 35000,
            'quantite_stock' => 2,
        ]);

        $avecVariantes = Produit::factory()->for($waxAdjame)->publie()->create([
            'categorie_id' => $wax->id,
            'nom' => 'Wax imprimé « Bouche de Sika »',
            'slug' => 'wax-imprime-bouche-de-sika',
            'description' => 'Le même motif en trois longueurs : 2 yards pour une jupe, 6 pour une tenue, 12 pour un couple.',
            'prix' => 6000,
            'quantite_stock' => 0,
        ]);
        foreach ([['2 yards', 6000, 8], ['6 yards', 14000, 5], ['12 yards', 26000, 1]] as [$nom, $prix, $stock]) {
            VarianteProduit::factory()->pourProduit($avecVariantes)->create([
                'nom' => $nom,
                'prix' => $prix,
                'quantite_stock' => $stock,
            ]);
        }
        $this->ajouterPhotos($avecVariantes, 1);

        Produit::factory()->for($waxAdjame)->publie()->epuise()->create([
            'categorie_id' => $bazin->id,
            'nom' => 'Kita tissé main',
            'slug' => 'kita-tisse-main',
            'description' => 'Pagne kita baoulé tissé à la main. Arrivages irréguliers.',
            'prix' => 45000,
        ]);

        $archive = Produit::factory()->for($waxAdjame)->create([
            'categorie_id' => $wax->id,
            'nom' => 'Wax collection Tabaski 2025',
            'slug' => 'wax-collection-tabaski-2025',
            'prix' => 12000,
            'statut' => StatutProduit::Archive,
        ]);
        $this->ajouterPhotos($archive, 1);
    }
}
