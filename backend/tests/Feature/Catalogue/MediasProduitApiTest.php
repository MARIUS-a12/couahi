<?php

namespace Tests\Feature\Catalogue;

use App\Models\Etablissement;
use App\Models\Media;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Étape 5 — photos de produits, les 7 scénarios de la spec.
 *
 * Réutilise les établissements et comptes de démo de l'Étape 1 : chez-awa
 * (admin awa@chez-awa.test, gerer_catalogue), maquis-du-port (admin
 * adjoua@maquis-du-port.test, gerer_catalogue ; opérateur
 * yao@maquis-du-port.test, sans gerer_catalogue) — voir
 * EtablissementsDemoSeeder / UtilisateursDemoSeeder.
 */
class MediasProduitApiTest extends TestCase
{
    use RefreshDatabase;

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecte(string $hote, string $email): static
    {
        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => $email,
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        return $this;
    }

    /**
     * "Sans aucun média" explicitement : EtablissementsDemoSeeder peuple
     * désormais le catalogue de démo avec des produits qui en ont déjà (voir
     * sa docblock) — un simple "le premier produit venu" ne garantirait plus
     * la toile vierge dont ces tests ont besoin pour compter leurs propres
     * envois sans interférence.
     */
    private function produitDe(string $slugEtablissement): Produit
    {
        $etablissement = Etablissement::where('slug', $slugEtablissement)->firstOrFail();

        return Produit::pourTousEtablissements()
            ->where('etablissement_id', $etablissement->id)
            // whereDoesntHave avec callback (pas doesntHave()) : la
            // sous-requête interroge Media, lui aussi tenant-scopé, alors
            // qu'aucun contexte ambiant n'existe ici (code de test, hors
            // d'une requête HTTP) — même contournement explicite que
            // pourTousEtablissements() ci-dessus, pour la même raison.
            ->whereDoesntHave('medias', fn ($requete) => $requete->pourTousEtablissements())
            ->firstOrFail();
    }

    public function test_1_envoi_reussi_genere_les_trois_formats(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produit = $this->produitDe('chez-awa');

        $reponse = $this->depuis('chez-awa.localhost')->post(
            "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
            ['photo' => UploadedFile::fake()->image('photo.jpg', 2000, 2000)],
        );

        $reponse->assertStatus(201);
        $reponse->assertJsonPath('data.ordre', 0);
        $reponse->assertJsonPath('data.est_principal', true);

        foreach (['vignette', 'moyenne', 'grande'] as $format) {
            $reponse->assertJsonPath(
                "data.{$format}.webp",
                fn ($url) => is_string($url) && $url !== '',
            );
            $reponse->assertJsonPath(
                "data.{$format}.jpg",
                fn ($url) => is_string($url) && $url !== '',
            );
        }

        $media = Media::pourTousEtablissements()->findOrFail($reponse->json('data.id'));
        $cheminMoyenWebp = $media->metadonnees_json['variantes']['moyenne']['webp'];

        Storage::disk('public')->assertExists($cheminMoyenWebp);
        $this->assertLessThan(200 * 1024, Storage::disk('public')->size($cheminMoyenWebp));
    }

    /**
     * Un média de maquis-du-port ne peut pas être rattaché à un produit de
     * chez-awa : ni par le réordonnancement (l'id étranger n'appartient
     * jamais à l'ensemble des photos du produit d'awa, donc la validation le
     * rejette), ni par une suppression directe (la portée tenant masque le
     * média à quiconque n'est pas sur son établissement — la liaison de
     * route échoue avant même le contrôleur).
     *
     * Le média étranger est créé directement (factory + attach), sans passer
     * par un envoi HTTP sur maquis-du-port : chaque test de ce fichier ne se
     * connecte qu'une seule fois, comme partout ailleurs dans ce dossier —
     * la session Sanctum d'un test n'est pas conçue pour changer d'compte en
     * cours de route.
     */
    public function test_2_isolation_media_dun_autre_etablissement(): void
    {
        Storage::fake('public');
        $this->seed();

        $produitMaquis = $this->produitDe('maquis-du-port');
        $mediaMaquis = Media::factory()->create([
            'etablissement_id' => $produitMaquis->etablissement_id,
            'metadonnees_json' => ['variantes' => $this->variantesFactices()],
        ]);
        $produitMaquis->medias()->attach($mediaMaquis->id, ['ordre' => 0, 'est_principal' => true]);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produitAwa = $this->produitDe('chez-awa');

        $this->depuis('chez-awa.localhost')->putJson(
            "http://chez-awa.localhost:8000/api/produits/{$produitAwa->id}/medias/ordre",
            ['ordre' => [$mediaMaquis->id]],
        )->assertStatus(422);

        $this->depuis('chez-awa.localhost')->deleteJson(
            "http://chez-awa.localhost:8000/api/produits/{$produitAwa->id}/medias/{$mediaMaquis->id}",
        )->assertStatus(404);
    }

    /**
     * @return array<string, array{webp: string, jpg: string}>
     */
    private function variantesFactices(): array
    {
        $variantes = [];

        foreach (['vignette', 'moyenne', 'grande'] as $nom) {
            $variantes[$nom] = ['webp' => "medias/factice-{$nom}.webp", 'jpg' => "medias/factice-{$nom}.jpg"];
        }

        return $variantes;
    }

    public function test_3_fichier_non_image_renomme_en_jpg_refuse(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produit = $this->produitDe('chez-awa');

        $reponse = $this->depuis('chez-awa.localhost')->post(
            "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
            ['photo' => UploadedFile::fake()->createWithContent('malware.jpg', 'ceci est du texte, pas une image')],
        );

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors('photo');
    }

    public function test_4_fichier_trop_lourd_refuse_avec_message_clair(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produit = $this->produitDe('chez-awa');

        $reponse = $this->depuis('chez-awa.localhost')->post(
            "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
            ['photo' => UploadedFile::fake()->image('photo.jpg', 10, 10)->size(8193)],
        );

        $reponse->assertStatus(422);
        $reponse->assertJsonPath('errors.photo.0', 'La photo dépasse la taille maximale autorisée (8 Mo).');
    }

    public function test_5_suppression_retire_le_fichier_du_disque(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produit = $this->produitDe('chez-awa');

        $envoi = $this->depuis('chez-awa.localhost')->post(
            "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
            ['photo' => UploadedFile::fake()->image('photo.jpg', 400, 400)],
        );
        $envoi->assertStatus(201);
        $mediaId = $envoi->json('data.id');

        $media = Media::pourTousEtablissements()->findOrFail($mediaId);
        $cheminVignetteWebp = $media->metadonnees_json['variantes']['vignette']['webp'];
        Storage::disk('public')->assertExists($cheminVignetteWebp);

        $this->depuis('chez-awa.localhost')
            ->deleteJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias/{$mediaId}")
            ->assertStatus(204);

        Storage::disk('public')->assertMissing($cheminVignetteWebp);
        $this->assertNull(Media::pourTousEtablissements()->find($mediaId));
    }

    public function test_6_sixieme_envoi_refuse(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $produit = $this->produitDe('chez-awa');

        for ($i = 1; $i <= 5; $i++) {
            $this->depuis('chez-awa.localhost')->post(
                "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
                ['photo' => UploadedFile::fake()->image("photo-{$i}.jpg", 100, 100)],
            )->assertStatus(201);
        }

        $sixieme = $this->depuis('chez-awa.localhost')->post(
            "http://chez-awa.localhost:8000/api/produits/{$produit->id}/medias",
            ['photo' => UploadedFile::fake()->image('photo-6.jpg', 100, 100)],
        );
        $sixieme->assertStatus(422);

        $lecture = $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}");
        $lecture->assertStatus(200);
        $this->assertCount(5, $lecture->json('data.medias'));
    }

    /**
     * La photo déjà en place est créée directement (factory + attach), pas
     * via un envoi HTTP préalable en tant qu'admin : un seul compte se
     * connecte dans ce test, comme partout ailleurs dans ce dossier (voir
     * test_2 ci-dessus pour la même remarque).
     */
    public function test_7_operateur_403_sur_envoi_et_suppression(): void
    {
        Storage::fake('public');
        $this->seed();

        $produitMaquis = $this->produitDe('maquis-du-port');
        $media = Media::factory()->create([
            'etablissement_id' => $produitMaquis->etablissement_id,
            'metadonnees_json' => ['variantes' => $this->variantesFactices()],
        ]);
        $produitMaquis->medias()->attach($media->id, ['ordre' => 0, 'est_principal' => true]);

        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')->post(
            "http://maquis-du-port.localhost:8000/api/produits/{$produitMaquis->id}/medias",
            ['photo' => UploadedFile::fake()->image('photo.jpg', 200, 200)],
        )->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->deleteJson("http://maquis-du-port.localhost:8000/api/produits/{$produitMaquis->id}/medias/{$media->id}")
            ->assertStatus(403);
    }
}
