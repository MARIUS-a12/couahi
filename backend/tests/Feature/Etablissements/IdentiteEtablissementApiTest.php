<?php

namespace Tests\Feature\Etablissements;

use App\Models\Etablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 6A ter — identité d'établissement, deux chemins (commerçant,
 * super-admin) sur le MÊME contrôleur/service (voir
 * IdentiteEtablissementController). Réutilise les comptes de démo (voir
 * UtilisateursDemoSeeder) : Awa est admin de chez-awa, Yao n'est qu'opérateur
 * sur maquis-du-port, Marius est le super-admin.
 */
class IdentiteEtablissementApiTest extends TestCase
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

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    private function maquisDuPort(): Etablissement
    {
        return Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
    }

    public function test_1_couleur_insuffisante_refusee_variante_proposee_passe(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $refus = $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'couleur_accent' => '#FFFF00',
            ]);

        $refus->assertStatus(422);
        $suggestion = $refus->json('couleur_accent_suggeree');
        $this->assertNotNull($suggestion);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $suggestion);

        $accepte = $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'couleur_accent' => $suggestion,
            ]);

        $accepte->assertStatus(200);
        $this->assertSame($suggestion, $this->chezAwa()->couleur_accent);
    }

    public function test_2_lien_facebook_vers_autre_domaine_refuse(): void
    {
        $this->seed();
        $lienAvant = $this->chezAwa()->lien_facebook;
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'lien_facebook' => 'https://evil-phishing.com/chez-awa',
            ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['lien_facebook']);
        // Chez Awa a désormais un vrai lien Facebook de démo (voir
        // EtablissementsDemoSeeder) : la preuve que le refus n'a rien
        // changé est qu'il reste EXACTEMENT celui d'avant, pas qu'il soit
        // vide.
        $this->assertSame($lienAvant, $this->chezAwa()->lien_facebook);
    }

    public function test_3_url_http_stockee_en_https(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'lien_site_web' => 'http://chez-awa.example.com',
            ])
            ->assertStatus(200);

        $this->assertSame('https://chez-awa.example.com', $this->chezAwa()->lien_site_web);
    }

    public function test_4_operateur_recoit_403_sur_patch_commercant(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->patchJson('http://maquis-du-port.localhost:8000/api/parametres/etablissement', [
                'description' => 'Tentative sans la permission.',
            ])
            ->assertStatus(403);
    }

    public function test_5_admin_etablissement_a_ne_peut_pas_modifier_b_via_route_super_admin(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $maquisDuPort = $this->maquisDuPort();

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/etablissements/{$maquisDuPort->id}/identite", [
                'description' => 'Awa tente de modifier un autre établissement.',
            ])
            ->assertStatus(403);

        // Maquis du Port a désormais une vraie description de démo (voir
        // EtablissementsDemoSeeder) : la preuve que le refus n'a rien
        // changé est qu'elle reste EXACTEMENT celle d'avant, pas qu'elle
        // soit vide.
        $this->assertSame($maquisDuPort->description, $maquisDuPort->fresh()->description);
    }

    public function test_6_super_admin_peut_modifier_nimporte_quel_etablissement(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');
        $chezAwa = $this->chezAwa();
        $maquisDuPort = $this->maquisDuPort();

        $this->depuis('admin.localhost')
            ->patchJson("http://admin.localhost:8000/api/etablissements/{$chezAwa->id}/identite", [
                'description' => 'Description posée par le super-admin.',
            ])
            ->assertStatus(200);

        $this->depuis('admin.localhost')
            ->patchJson("http://admin.localhost:8000/api/etablissements/{$maquisDuPort->id}/identite", [
                'description' => 'Autre établissement, même super-admin.',
            ])
            ->assertStatus(200);

        $this->assertSame('Description posée par le super-admin.', $chezAwa->fresh()->description);
        $this->assertSame('Autre établissement, même super-admin.', $maquisDuPort->fresh()->description);
    }

    public function test_7_admin_etablissement_recoit_403_sur_route_super_admin_pour_son_propre_etablissement(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $chezAwa = $this->chezAwa();

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/etablissements/{$chezAwa->id}/identite", [
                'description' => 'Même sur son propre établissement, cette route reste super-admin.',
            ])
            ->assertStatus(403);
    }

    /**
     * "Les deux chemins produisent exactement le même résultat" est vérifié
     * en deux tests plutôt qu'un seul qui basculerait de session en cours de
     * route : le client de test ne supporte pas de façon fiable deux
     * connexions successives à des hôtes différents dans un même test (rien
     * à voir avec le comportement réel — un navigateur isole déjà chaque
     * onglet). Chacun vérifie indépendamment que SA transformation
     * normalisée (https forcé, téléphone canonique) donne exactement le même
     * résultat que l'autre — même assertion littérale des deux côtés.
     */
    public function test_8a_chemin_commercant_normalise_les_donnees(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'description' => 'Boutique de quartier, produits frais tous les jours.',
                'telephone_whatsapp' => '0701020304',
                'adresse' => 'Cocody, Abidjan',
                'lien_site_web' => 'http://exemple.test',
            ])
            ->assertStatus(200);

        $chezAwa = $this->chezAwa()->fresh();
        $this->assertSame('Boutique de quartier, produits frais tous les jours.', $chezAwa->description);
        $this->assertSame('+2250701020304', $chezAwa->telephone_whatsapp);
        $this->assertSame('Cocody, Abidjan', $chezAwa->adresse);
        $this->assertSame('https://exemple.test', $chezAwa->lien_site_web);
    }

    public function test_8b_chemin_super_admin_normalise_les_donnees_identiquement(): void
    {
        $this->seed();
        $maquisDuPort = $this->maquisDuPort();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $this->depuis('admin.localhost')
            ->patchJson("http://admin.localhost:8000/api/etablissements/{$maquisDuPort->id}/identite", [
                'description' => 'Boutique de quartier, produits frais tous les jours.',
                'telephone_whatsapp' => '0701020304',
                'adresse' => 'Cocody, Abidjan',
                'lien_site_web' => 'http://exemple.test',
            ])
            ->assertStatus(200);

        $maquisFraiche = $maquisDuPort->fresh();
        $this->assertSame('Boutique de quartier, produits frais tous les jours.', $maquisFraiche->description);
        $this->assertSame('+2250701020304', $maquisFraiche->telephone_whatsapp);
        $this->assertSame('Cocody, Abidjan', $maquisFraiche->adresse);
        $this->assertSame('https://exemple.test', $maquisFraiche->lien_site_web);
    }

    public function test_9_vitrine_ne_renvoie_que_les_champs_renseignes(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();

        // Chez Awa a désormais une identité de démo complète (voir
        // EtablissementsDemoSeeder) : ce test vérifie précisément le
        // comportement "champ non renseigné" côté vitrine, qui a donc
        // besoin d'un établissement délibérément incomplet — recréé ici,
        // indépendamment de ce que le seeder remplit par défaut.
        $chezAwa->update([
            'adresse' => null,
            'lien_facebook' => null,
            'logo_media_id' => null,
            'couleur_accent' => null,
        ]);

        $avant = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->json('data');

        $this->assertNull($avant['adresse']);
        $this->assertNull($avant['lien_facebook']);
        $this->assertNull($avant['logo']);
        $this->assertSame('#146c43', $avant['couleur_accent']);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'adresse' => 'Rue des Jardins, Abidjan',
                'couleur_accent' => '#1D4ED8',
            ])
            ->assertStatus(200);

        $apres = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->json('data');

        $this->assertSame('Rue des Jardins, Abidjan', $apres['adresse']);
        $this->assertSame('#1D4ED8', $apres['couleur_accent']);
        $this->assertNull($apres['lien_facebook']);
    }

    public function test_10_cache_vitrine_invalide_apres_modification(): void
    {
        $this->seed();
        $descriptionAvant = $this->chezAwa()->description;

        $avant = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->json('data.description');
        // La description de démo (voir EtablissementsDemoSeeder) doit déjà
        // apparaître ici : ce test porte sur l'invalidation du cache après
        // modification, pas sur un établissement vierge.
        $this->assertSame($descriptionAvant, $avant);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')
            ->patchJson('http://chez-awa.localhost:8000/api/parametres/etablissement', [
                'description' => 'Fraîchement mis à jour.',
            ])
            ->assertStatus(200);

        $apres = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->json('data.description');

        $this->assertSame('Fraîchement mis à jour.', $apres);
    }

    /**
     * Les deux étapes (avant/après) passent par la MÊME session super-admin
     * et la route super-admin pour écrire — voir test_8a/test_8b pour la
     * raison de ne jamais basculer d'hôte de connexion en cours de test. Ce
     * que ce test vérifie (la pastille suit l'état réel des données) ne
     * dépend pas de QUEL chemin a écrit ces données, déjà couvert ailleurs.
     */
    public function test_12_pastille_vitrine_incomplete_apparait_et_disparait(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();

        // Chez Awa a désormais une identité de démo complète (voir
        // EtablissementsDemoSeeder) : ce test vérifie précisément que la
        // pastille "incomplet" apparaît puis disparaît à mesure que les
        // champs se remplissent, ce qui a donc besoin de partir d'un
        // établissement délibérément incomplet.
        $chezAwa->update([
            'couleur_accent' => null,
            'telephone_whatsapp' => null,
            'horaires' => null,
            'logo_media_id' => null,
        ]);

        $this->connecte('admin.localhost', 'super@plateforme.test');

        $liste = $this->depuis('admin.localhost')->getJson('http://admin.localhost:8000/api/etablissements')->json('data');
        $awaDansListe = collect($liste)->firstWhere('id', $chezAwa->id);
        $this->assertNotEmpty($awaDansListe['identite_champs_manquants']);
        $this->assertContains('logo', $awaDansListe['identite_champs_manquants']);
        $this->assertContains('couleur', $awaDansListe['identite_champs_manquants']);
        $this->assertContains('whatsapp', $awaDansListe['identite_champs_manquants']);
        $this->assertContains('horaires', $awaDansListe['identite_champs_manquants']);

        $this->depuis('admin.localhost')
            ->patchJson("http://admin.localhost:8000/api/etablissements/{$chezAwa->id}/identite", [
                'couleur_accent' => '#146c43',
                'telephone_whatsapp' => '0701020304',
                'horaires' => [
                    'lundi' => ['ouverture' => '08:00', 'fermeture' => '19:00', 'ferme' => false],
                ],
            ])
            ->assertStatus(200);

        $listeApres = $this->depuis('admin.localhost')->getJson('http://admin.localhost:8000/api/etablissements')->json('data');
        $awaApres = collect($listeApres)->firstWhere('id', $chezAwa->id);
        // Le logo n'a pas été posé dans ce test : seul "logo" doit rester.
        $this->assertSame(['logo'], $awaApres['identite_champs_manquants']);
    }
}
