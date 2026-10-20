<?php

namespace Tests\Feature;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutAbonnement;
use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Models\Abonnement;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Support\Tenancy\ContexteEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Chaque tâche planifiée exécutée PAR LE PLANIFICATEUR (schedule:run),
 * jamais par un appel direct à son code : la tâche d'expiration des
 * commandes avait des tests qui appelaient handle() à la main, tous verts,
 * alors que le planificateur ne l'avait jamais exécutée une seule fois
 * (voir LibererReservationsExpirees). Un test direct prouve que le code
 * marche, pas qu'il est branché. Règle inscrite dans AGENTS.md.
 */
class ExecutionParLePlanificateurTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Inventaire : ajouter une tâche planifiée sans l'ajouter ici (et sans
     * lui écrire son test par le planificateur) fait échouer ce test.
     */
    public function test_toutes_les_taches_planifiees_sont_connues_et_testees_ici(): void
    {
        $noms = collect(app(Schedule::class)->events())
            ->map(fn (Event $evenement) => $evenement->description)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'abonnements:actualiser-statuts',
            'commandes:liberer-reservations-expirees',
        ], $noms);
    }

    public function test_le_planificateur_expire_une_commande_echue_et_libere_son_stock(): void
    {
        $this->seed();
        $etablissement = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        app(ContexteEtablissement::class)->definir($etablissement);
        $produit = Produit::factory()->for($etablissement)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $commande = app(CreerCommande::class)->executer(
            $etablissement,
            $etablissement->clients()->create(['nom' => 'Client Test']),
            [new LigneCommandeDemandee($produit->id, null, 2)],
            Canal::Web,
            SourceCommande::PanierWeb,
            'cle-planificateur',
        );
        app(ContexteEtablissement::class)->definir(null);
        $this->assertSame(2, $produit->fresh()->quantite_reservee);

        // 16 minutes plus tard, à une heure où seule cette tâche est due.
        Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
        $commande->forceFill(['expire_le' => now()->subMinute()])->save();
        $commande->reservations()->pourTousEtablissements()->update(['expire_le' => now()->subMinute()]);

        $this->artisan('schedule:run')->assertSuccessful();

        $fraiche = Commande::pourTousEtablissements()->findOrFail($commande->id);
        $this->assertSame(StatutCommande::Expiree, $fraiche->statut);
        $this->assertSame(0, $produit->fresh()->quantite_reservee);
        $this->assertSame(StatutReservation::Liberee, $fraiche->reservations()->pourTousEtablissements()->first()->statut);
    }

    public function test_le_planificateur_actualise_les_abonnements_a_00h05(): void
    {
        $this->seed();
        $abonnement = Abonnement::pourTousEtablissements()
            ->where('etablissement_id', Etablissement::where('slug', 'chez-awa')->value('id'))
            ->firstOrFail();

        Date::setTestNow(CarbonImmutable::parse('2026-10-20 00:05:00'));
        $abonnement->update(['date_echeance' => CarbonImmutable::parse('2026-10-18')]);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(StatutAbonnement::EnRetard, $abonnement->fresh()->statut);
    }

    public function test_hors_de_son_horaire_la_tache_des_abonnements_ne_tourne_pas(): void
    {
        $this->seed();
        $abonnement = Abonnement::pourTousEtablissements()
            ->where('etablissement_id', Etablissement::where('slug', 'chez-awa')->value('id'))
            ->firstOrFail();

        Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
        $abonnement->update(['date_echeance' => CarbonImmutable::parse('2026-10-18')]);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(StatutAbonnement::Essai, $abonnement->fresh()->statut);
    }
}
