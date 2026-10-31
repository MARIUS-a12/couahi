<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Domaine;
use App\Models\Etablissement;
use App\Models\ParametreSite;
use App\Models\User;
use App\Services\Etablissements\CreerEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreationEtablissementTest extends TestCase
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

    private function payloadValide(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'Boutique Test',
            'type' => 'boutique',
            'sous_domaine' => 'boutique-test',
            'email' => 'contact@boutique-test.test',
            'telephone' => '+225 00 00 00 00',
            'nom_administrateur' => 'Nouvel Admin',
            'email_administrateur' => 'admin@boutique-test.test',
        ], $surcharge);
    }

    public function test_1_creation_complete_cree_les_cinq_elements(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $reponse = $this->depuis('admin.localhost')->postJson(
            'http://admin.localhost:8000/api/etablissements',
            $this->payloadValide(),
        );

        $reponse->assertStatus(201);
        $reponse->assertJsonPath('data.nom', 'Boutique Test');
        $this->assertNotEmpty($reponse->json('mot_de_passe_genere'));

        $etablissement = Etablissement::where('slug', 'boutique-test')->firstOrFail();
        $this->assertSame('boutique', $etablissement->type);

        $domaine = Domaine::where('etablissement_id', $etablissement->id)->firstOrFail();
        $this->assertSame('boutique-test.localhost', $domaine->hote);
        $this->assertTrue($domaine->est_principal);
        $this->assertNotNull($domaine->verifie_le);

        $this->assertNotNull(ParametreSite::pourTousEtablissements()->where('etablissement_id', $etablissement->id)->first());

        $administrateur = User::where('email', 'admin@boutique-test.test')->firstOrFail();
        $appartenance = $etablissement->appartenances()->where('utilisateur_id', $administrateur->id)->firstOrFail();
        $this->assertSame(['admin_etablissement'], $appartenance->roles->pluck('nom')->all());
    }

    /**
     * L'email admin@... existe déjà (créé hors du flux normal, pour
     * simuler une double soumission concurrente qui passerait la
     * validation avant l'INSERT) : la création doit échouer sans laisser
     * ni établissement ni domaine derrière elle.
     */
    public function test_2_echec_en_cours_de_route_nannule_rien_de_partiel(): void
    {
        $this->seed();

        User::factory()->create(['email' => 'admin@boutique-ratee.test']);

        try {
            app(CreerEtablissement::class)->executer(
                nom: 'Boutique Ratee',
                type: 'boutique',
                sousDomaine: 'boutique-ratee',
                email: null,
                telephone: null,
                nomAdministrateur: 'X',
                emailAdministrateur: 'admin@boutique-ratee.test',
            );
            $this->fail('Une exception était attendue (email administrateur déjà pris).');
        } catch (\Throwable) {
            // Attendu : la transaction doit avoir échoué.
        }

        $this->assertNull(Etablissement::where('slug', 'boutique-ratee')->first());
        $this->assertNull(Domaine::where('hote', 'boutique-ratee.localhost')->first());
    }

    public function test_3_sous_domaine_deja_pris_est_refuse(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $this->depuis('admin.localhost')
            ->postJson('http://admin.localhost:8000/api/etablissements', $this->payloadValide())
            ->assertStatus(201);

        $doublon = $this->depuis('admin.localhost')->postJson(
            'http://admin.localhost:8000/api/etablissements',
            $this->payloadValide(['email_administrateur' => 'autre@boutique-test.test']),
        );

        $doublon->assertStatus(422);
        $doublon->assertJsonValidationErrors(['sous_domaine']);
    }

    public function test_4_sous_domaine_reserve_est_refuse(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        foreach (['admin', 'api', 'www'] as $motReserve) {
            $reponse = $this->depuis('admin.localhost')->postJson(
                'http://admin.localhost:8000/api/etablissements',
                $this->payloadValide(['sous_domaine' => $motReserve, 'email_administrateur' => "admin@{$motReserve}.test"]),
            );

            $reponse->assertStatus(422);
            $reponse->assertJsonValidationErrors(['sous_domaine']);
        }
    }

    public function test_5_admin_etablissement_recoit_403(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/etablissements')
            ->assertStatus(403);

        $this->depuis('chez-awa.localhost')
            ->postJson('http://chez-awa.localhost:8000/api/etablissements', $this->payloadValide())
            ->assertStatus(403);
    }

    public function test_6_etablissement_suspendu_bloque_connexion_et_renvoie_404(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $creation = $this->depuis('admin.localhost')->postJson(
            'http://admin.localhost:8000/api/etablissements',
            $this->payloadValide(),
        );
        $id = $creation->json('data.id');
        $motDePasse = $creation->json('mot_de_passe_genere');

        $this->depuis('admin.localhost')
            ->postJson("http://admin.localhost:8000/api/etablissements/{$id}/suspendre")
            ->assertStatus(200)
            ->assertJsonPath('data.statut', 'inactif');

        // L'administrateur nouvellement créé ne peut plus se connecter.
        $connexion = $this->depuis('boutique-test.localhost')->postJson(
            'http://boutique-test.localhost:8000/api/connexion',
            ['email' => 'admin@boutique-test.test', 'mot_de_passe' => $motDePasse],
        );
        $connexion->assertStatus(422);
        $connexion->assertJsonPath('errors.email.0', 'Identifiants invalides.');

        // Le site public de cet établissement répond 404.
        $this->depuis('boutique-test.localhost')
            ->getJson('http://boutique-test.localhost:8000/api/moi')
            ->assertStatus(404);
    }

    public function test_7_mot_de_passe_genere_nest_jamais_renvoye_ailleurs(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $creation = $this->depuis('admin.localhost')->postJson(
            'http://admin.localhost:8000/api/etablissements',
            $this->payloadValide(),
        );
        $id = $creation->json('data.id');
        $motDePasse = $creation->json('mot_de_passe_genere');

        $liste = $this->depuis('admin.localhost')->getJson('http://admin.localhost:8000/api/etablissements');
        $liste->assertStatus(200);
        $this->assertStringNotContainsString($motDePasse, $liste->getContent());

        $fiche = $this->depuis('admin.localhost')->getJson("http://admin.localhost:8000/api/etablissements/{$id}");
        $fiche->assertStatus(200);
        $this->assertStringNotContainsString($motDePasse, $fiche->getContent());
    }
}
