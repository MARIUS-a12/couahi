<?php

namespace Tests\Feature\SuperAdmin;

use App\Exceptions\EtablissementNonResoluException;
use App\Models\Etablissement;
use App\Models\Media;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug — page d'identité vide pour le super-admin : sur son hôte dédié,
 * ResoudreEtablissement ne pose aucun contexte de tenancy, donc toute
 * donnée scopée d'UN établissement précis (son logo, ici) levait
 * EtablissementNonResoluException (400) dès qu'il en possédait un — les
 * établissements de démo n'en ayant jamais, ce chemin n'était exercé par
 * aucun test existant. Voir DefinirEtablissementCible, le middleware qui
 * pose maintenant ce contexte sur les routes "/etablissements/{etablissement}/...".
 */
class DefinirEtablissementCibleTest extends TestCase
{
    use RefreshDatabase;

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecteSuperAdmin(): static
    {
        $hote = config('tenancy.hote_super_admin');

        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => 'super@plateforme.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        return $this;
    }

    private function poserLogo(Etablissement $etablissement, string $prefixe): void
    {
        $media = Media::factory()->for($etablissement)->create([
            'disque' => 'public',
            'metadonnees_json' => [
                'variantes' => [
                    'petit' => ['webp' => "{$prefixe}-petit.webp", 'jpg' => "{$prefixe}-petit.jpg"],
                    'grand' => ['webp' => "{$prefixe}-grand.webp", 'jpg' => "{$prefixe}-grand.jpg"],
                ],
            ],
        ]);

        $etablissement->update(['logo_media_id' => $media->id]);
    }

    public function test_1_super_admin_lit_lidentite_dun_etablissement_avec_logo_sans_erreur(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->poserLogo($chezAwa, 'logo-chez-awa');

        $this->connecteSuperAdmin();
        $hote = config('tenancy.hote_super_admin');

        $reponse = $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}/identite");

        $reponse->assertStatus(200);
        $this->assertStringContainsString('logo-chez-awa-petit.webp', $reponse->json('data.logo.petit.webp'));
    }

    public function test_2_super_admin_sur_un_identifiant_inexistant_recoit_404(): void
    {
        $this->seed();
        $this->connecteSuperAdmin();
        $hote = config('tenancy.hote_super_admin');

        $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements/999999/identite")
            ->assertStatus(404);
    }

    /**
     * Preuve que le contexte posé sur A n'est ni absent (ce qui lèverait,
     * voir test_1) ni élargi à tout le monde : la réponse pour A ne
     * contient JAMAIS le logo de B, malgré les deux lignes "medias"
     * présentes en base au même moment.
     */
    public function test_3_une_requete_sur_a_ne_renvoie_jamais_les_donnees_de_b(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $this->poserLogo($chezAwa, 'logo-chez-awa');
        $this->poserLogo($maquisDuPort, 'logo-maquis-du-port');

        $this->connecteSuperAdmin();
        $hote = config('tenancy.hote_super_admin');

        $reponse = $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}/identite");

        $reponse->assertStatus(200);
        $this->assertStringContainsString('logo-chez-awa-petit.webp', $reponse->json('data.logo.petit.webp'));
        $this->assertStringNotContainsString('logo-maquis-du-port', $reponse->json('data.logo.petit.webp'));
    }

    /**
     * Le contexte posé par DefinirEtablissementCible ne doit pas survivre à
     * la requête : ContexteEtablissement est un singleton de conteneur, et
     * ce même processus PHP traite plusieurs requêtes à la suite dans ce
     * test, exactement comme sous Octane. Juste après la requête ci-dessus,
     * toute lecture d'un modèle scopé SANS contexte ambiant doit donc
     * échouer à nouveau — la preuve qu'aucun résidu de "chez-awa" ne traîne.
     */
    public function test_4_le_contexte_est_relache_apres_la_requete(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->poserLogo($chezAwa, 'logo-chez-awa');

        $this->connecteSuperAdmin();
        $hote = config('tenancy.hote_super_admin');

        $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}/identite")
            ->assertStatus(200);

        $this->assertFalse(app(ContexteEtablissement::class)->estDefini());
        $this->expectException(EtablissementNonResoluException::class);
        Media::query()->first();
    }

    /**
     * Les deux contournements qui existaient avant ce correctif
     * (EtablissementDetailResource, SupprimerEtablissement) sont retirés :
     * les deux routes qui en dépendaient posent maintenant le contexte via
     * DefinirEtablissementCible (ou explicitement dans
     * EtablissementController::store(), seule route super-admin ciblant un
     * établissement SANS {etablissement} dans l'URL puisqu'il vient d'être
     * créé) au lieu de contourner le scope.
     */
    public function test_5_aucun_contournement_pourtousetablissements_dans_les_fichiers_corriges(): void
    {
        $resource = file_get_contents(app_path('Http/Resources/EtablissementDetailResource.php'));
        $service = file_get_contents(app_path('Services/Etablissements/SupprimerEtablissement.php'));

        $this->assertStringNotContainsString('pourTousEtablissements', $resource);
        $this->assertStringNotContainsString('pourTousEtablissements', $service);
    }
}
