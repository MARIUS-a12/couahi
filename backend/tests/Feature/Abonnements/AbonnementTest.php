<?php

namespace Tests\Feature\Abonnements;

use App\Enums\StatutAbonnement;
use App\Models\Abonnement;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Media;
use App\Models\PaiementAbonnement;
use App\Models\Produit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Étape 12 — abonnement des commerçants. Réutilise les comptes de démo
 * (voir UtilisateursDemoSeeder) : Awa est admin de chez-awa, Yao n'est
 * qu'opérateur sur maquis-du-port, super@plateforme.test est le
 * super-admin. Les dates sont posées directement sur l'abonnement puis la
 * tâche quotidienne est lancée, exactement comme elle tournera la nuit.
 */
class AbonnementTest extends TestCase
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

    private function hoteSuperAdmin(): string
    {
        return config('tenancy.hote_super_admin');
    }

    private function etablissement(string $slug): Etablissement
    {
        return Etablissement::where('slug', $slug)->firstOrFail();
    }

    private function abonnementDe(string $slug): Abonnement
    {
        return Abonnement::pourTousEtablissements()
            ->where('etablissement_id', $this->etablissement($slug)->id)
            ->firstOrFail();
    }

    /** Échéance dépassée de $jours jours, puis passage de la tâche quotidienne. */
    private function echeanceDepasseeDe(string $slug, int $jours): Abonnement
    {
        $abonnement = $this->abonnementDe($slug);
        $abonnement->update(['date_echeance' => CarbonImmutable::today()->subDays($jours)]);

        $this->artisan('abonnements:actualiser-statuts')->assertSuccessful();

        return $abonnement->fresh();
    }

    private function vitrine(string $hote = 'chez-awa.localhost', string $chemin = 'etablissement')
    {
        return $this->getJson("http://{$hote}:8000/api/vitrine/{$chemin}");
    }

    private function payloadPaiement(array $surcharge = []): array
    {
        $debut = CarbonImmutable::today();

        return array_merge([
            'montant' => 10000,
            'periode_debut' => $debut->toDateString(),
            'periode_fin' => $debut->addMonthNoOverflow()->subDay()->toDateString(),
            'moyen' => 'mobile_money',
            'reference' => 'MP241019.1234.A56789',
        ], $surcharge);
    }

    private function enregistrerPaiement(string $slug, array $payload)
    {
        $hote = $this->hoteSuperAdmin();
        $id = $this->etablissement($slug)->id;

        return $this->depuis($hote)->postJson("http://{$hote}:8000/api/etablissements/{$id}/abonnement/paiements", $payload);
    }

    public function test_1_un_etablissement_cree_recoit_un_abonnement_en_essai_a_30_jours(): void
    {
        $this->seed();
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');

        $this->depuis($this->hoteSuperAdmin())->postJson("http://{$this->hoteSuperAdmin()}:8000/api/etablissements", [
            'nom' => 'Boutique Neuve',
            'type' => 'boutique',
            'sous_domaine' => 'boutique-neuve',
            'nom_administrateur' => 'Admin Neuf',
            'email_administrateur' => 'admin@boutique-neuve.test',
        ])->assertStatus(201);

        $abonnement = $this->abonnementDe('boutique-neuve');

        $this->assertSame(StatutAbonnement::Essai, $abonnement->statut);
        $this->assertSame(CarbonImmutable::today()->addDays(30)->toDateString(), $abonnement->date_echeance->toDateString());
        $this->assertSame(7, $abonnement->jours_de_grace);
    }

    public function test_2_echeance_depassee_bascule_en_retard_sans_couper_la_vitrine(): void
    {
        $this->seed();

        $abonnement = $this->echeanceDepasseeDe('chez-awa', 1);

        $this->assertSame(StatutAbonnement::EnRetard, $abonnement->statut);
        $this->vitrine()->assertStatus(200);
        $this->vitrine(chemin: 'produits')->assertStatus(200);
    }

    public function test_3_delai_de_grace_epuise_bascule_en_suspendu_et_la_vitrine_renvoie_404(): void
    {
        $this->seed();

        // Grâce de 7 jours : l'échéance + 7 est encore le dernier jour en ligne.
        $this->assertSame(StatutAbonnement::EnRetard, $this->echeanceDepasseeDe('chez-awa', 7)->statut);
        $this->vitrine()->assertStatus(200);

        $this->assertSame(StatutAbonnement::Suspendu, $this->echeanceDepasseeDe('chez-awa', 8)->statut);
        $this->vitrine()->assertStatus(404);
        $this->vitrine(chemin: 'produits')->assertStatus(404);

        // L'autre établissement n'est pas touché.
        $this->vitrine('maquis-du-port.localhost')->assertStatus(200);
    }

    public function test_4_un_commercant_suspendu_peut_toujours_se_connecter_a_son_back_office(): void
    {
        $this->seed();
        $this->echeanceDepasseeDe('chez-awa', 30);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/moi')
            ->assertStatus(200)
            ->assertJsonPath('abonnement.statut', 'suspendu');
        $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/produits')->assertStatus(200);
        $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/abonnement')
            ->assertStatus(200)
            ->assertJsonPath('data.statut', 'suspendu');
    }

    public function test_5_enregistrer_un_paiement_reactive_et_decale_lecheance_de_la_bonne_duree(): void
    {
        $this->seed();
        $this->echeanceDepasseeDe('chez-awa', 30);
        $this->vitrine()->assertStatus(404);

        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $payload = $this->payloadPaiement();

        $this->enregistrerPaiement('chez-awa', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.date_echeance', $payload['periode_fin'])
            ->assertJsonPath('paiements.0.montant', 10000);

        $abonnement = $this->abonnementDe('chez-awa');
        $this->assertSame(StatutAbonnement::Actif, $abonnement->statut);
        $this->assertSame($payload['periode_fin'], $abonnement->date_echeance->toDateString());
        $this->vitrine()->assertStatus(200);

        // Un second mois enchaîné décale l'échéance d'exactement un mois de plus.
        $debut = $abonnement->date_echeance->addDay();
        $this->enregistrerPaiement('chez-awa', $this->payloadPaiement([
            'periode_debut' => $debut->toDateString(),
            'periode_fin' => $debut->addMonthNoOverflow()->subDay()->toDateString(),
        ]))->assertStatus(201);
        $this->assertSame(
            $debut->addMonthNoOverflow()->subDay()->toDateString(),
            $this->abonnementDe('chez-awa')->date_echeance->toDateString(),
        );

        $journal = JournalAudit::where('action', 'abonnement_paiement_enregistre')->latest('id')->firstOrFail();
        $this->assertSame($this->etablissement('chez-awa')->id, $journal->details['etablissement_id']);
        $this->assertNotNull($journal->utilisateur_id);
        $this->assertSame(2, JournalAudit::where('action', 'abonnement_echeance_modifiee')->count());
    }

    public function test_6_aucune_donnee_nest_perdue_par_une_suspension_puis_une_reactivation(): void
    {
        $this->seed();
        $etablissement = $this->etablissement('chez-awa');
        $compter = fn () => [
            'produits' => Produit::pourTousEtablissements()->where('etablissement_id', $etablissement->id)->count(),
            'categories' => Categorie::pourTousEtablissements()->where('etablissement_id', $etablissement->id)->count(),
            'medias' => Media::pourTousEtablissements()->where('etablissement_id', $etablissement->id)->count(),
            'membres' => $etablissement->appartenances()->count(),
            'logo' => $etablissement->fresh()->logo_media_id,
        ];
        $avant = $compter();
        $this->assertGreaterThan(0, $avant['produits']);
        $this->assertGreaterThan(0, $avant['medias']);

        $this->echeanceDepasseeDe('chez-awa', 30);
        $this->assertSame($avant, $compter());

        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->enregistrerPaiement('chez-awa', $this->payloadPaiement())->assertStatus(201);

        $this->assertSame($avant, $compter());
        $this->vitrine(chemin: 'produits')->assertStatus(200)->assertJsonCount(4, 'data');
    }

    public function test_7_la_tache_quotidienne_lancee_deux_fois_ne_change_rien_la_seconde_fois(): void
    {
        $this->seed();
        $abonnement = $this->abonnementDe('chez-awa');
        $abonnement->update(['date_echeance' => CarbonImmutable::today()->subDays(3)]);

        $this->artisan('abonnements:actualiser-statuts')
            ->expectsOutputToContain('1 abonnement(s) ont changé de statut')
            ->assertSuccessful();
        $apresPremiere = $abonnement->fresh();
        $journaux = JournalAudit::count();

        $this->artisan('abonnements:actualiser-statuts')
            ->expectsOutputToContain('0 abonnement(s) ont changé de statut')
            ->assertSuccessful();
        $apresSeconde = $abonnement->fresh();

        $this->assertSame(StatutAbonnement::EnRetard, $apresSeconde->statut);
        $this->assertSame($apresPremiere->date_echeance->toDateString(), $apresSeconde->date_echeance->toDateString());
        $this->assertSame($apresPremiere->updated_at->toIso8601String(), $apresSeconde->updated_at->toIso8601String());
        $this->assertSame($journaux, JournalAudit::count());
    }

    public function test_8_un_operateur_recoit_403_sur_la_page_dabonnement(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/abonnement')
            ->assertStatus(403);
    }

    public function test_9_un_admin_de_a_ne_voit_pas_labonnement_de_b(): void
    {
        $this->seed();
        $this->abonnementDe('maquis-du-port')->update(['montant_mensuel' => 25000]);
        $this->abonnementDe('chez-awa')->update(['montant_mensuel' => 15000]);
        $maquisDuPort = $this->etablissement('maquis-du-port');

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/abonnement')
            ->assertStatus(200)
            ->assertJsonPath('data.montant_mensuel', 15000);

        $hote = $this->hoteSuperAdmin();
        $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements/{$maquisDuPort->id}/abonnement")->assertStatus(403);
        $this->depuis($hote)->postJson(
            "http://{$hote}:8000/api/etablissements/{$maquisDuPort->id}/abonnement/paiements",
            $this->payloadPaiement(),
        )->assertStatus(403);
        $this->assertSame(0, PaiementAbonnement::pourTousEtablissements()->count());
    }

    public function test_10_le_montant_est_stocke_en_entier_jamais_en_nombre_a_virgule(): void
    {
        $this->seed();
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $hote = $this->hoteSuperAdmin();
        $id = $this->etablissement('chez-awa')->id;

        $this->assertSame('integer', Schema::getColumnType('abonnements', 'montant_mensuel'));
        $this->assertSame('integer', Schema::getColumnType('paiements_abonnement', 'montant'));

        $this->enregistrerPaiement('chez-awa', $this->payloadPaiement(['montant' => 15000.5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['montant']);
        $this->depuis($hote)->patchJson("http://{$hote}:8000/api/etablissements/{$id}/abonnement", [
            'montant_mensuel' => 12500.75,
            'jours_de_grace' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors(['montant_mensuel']);

        $this->enregistrerPaiement('chez-awa', $this->payloadPaiement(['montant' => '15000']))->assertStatus(201);

        $paiement = PaiementAbonnement::pourTousEtablissements()->firstOrFail();
        $this->assertSame(15000, $paiement->montant);
        $this->assertSame(15000, $paiement->fresh()->getRawOriginal('montant') + 0);
        $this->assertIsInt($this->abonnementDe('chez-awa')->montant_mensuel);
    }

    public function test_le_super_admin_regle_le_montant_et_les_jours_de_grace_avec_journalisation(): void
    {
        $this->seed();
        $this->echeanceDepasseeDe('chez-awa', 10);
        $this->vitrine()->assertStatus(404);
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $hote = $this->hoteSuperAdmin();
        $id = $this->etablissement('chez-awa')->id;

        // Allonger la grâce au-delà du retard remet la vitrine en ligne
        // tout de suite, sans attendre la tâche de la nuit.
        $this->depuis($hote)->patchJson("http://{$hote}:8000/api/etablissements/{$id}/abonnement", [
            'montant_mensuel' => 12000,
            'jours_de_grace' => 15,
        ])->assertStatus(200)
            ->assertJsonPath('data.montant_mensuel', 12000)
            ->assertJsonPath('data.statut', 'en_retard');

        $this->vitrine()->assertStatus(200);
        $journal = JournalAudit::where('action', 'abonnement_reglages_modifies')->firstOrFail();
        $this->assertSame(7, $journal->details['avant']['jours_de_grace']);
        $this->assertSame(15, $journal->details['apres']['jours_de_grace']);
    }

    public function test_un_paiement_manuel_ne_peut_pas_se_declarer_en_ligne(): void
    {
        $this->seed();
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');

        $this->enregistrerPaiement('chez-awa', $this->payloadPaiement(['moyen' => 'en_ligne']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['moyen']);
    }

    public function test_la_liste_super_admin_expose_le_statut_et_lecheance(): void
    {
        $this->seed();
        $this->echeanceDepasseeDe('chez-awa', 2);
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $hote = $this->hoteSuperAdmin();

        $reponse = $this->depuis($hote)->getJson("http://{$hote}:8000/api/etablissements")->assertStatus(200);

        $chezAwa = collect($reponse->json('data'))->firstWhere('nom', 'Chez Awa');
        $this->assertSame('en_retard', $chezAwa['abonnement']['statut']);
        $this->assertSame(CarbonImmutable::today()->subDays(2)->toDateString(), $chezAwa['abonnement']['date_echeance']);
    }
}
