<?php

namespace Tests\Feature\Catalogue;

use App\Enums\Canal;
use App\Enums\ModeStock;
use App\Enums\SourceCommande;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Services\Commandes\AnnulerCommande;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Stock\ConsommerReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot A — le mode de stock reste hérité du type de l'établissement à la
 * création, mais se change ensuite tant qu'aucune commande en cours n'en
 * dépend (voir ChangerModeStock et decisions.md).
 */
class ChangementModeStockTest extends TestCase
{
    use RefreshDatabase;

    private function connecte(string $slug, string $email): static
    {
        $this->withHeader('Referer', "http://{$slug}.localhost:8000")
            ->postJson("http://{$slug}.localhost:8000/api/connexion", ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertStatus(200);

        return $this;
    }

    private function changerMode(string $slug, Produit $produit, string $mode, array $autres = [])
    {
        return $this->putJson("http://{$slug}.localhost:8000/api/produits/{$produit->id}", ['mode_stock' => $mode, ...$autres]);
    }

    private function commander(Etablissement $etablissement, Produit $produit, ?int $varianteId = null): Commande
    {
        return app(CreerCommande::class)->executer(
            $etablissement,
            $etablissement->clients()->create(['nom' => 'Client Test']),
            [new LigneCommandeDemandee($produit->id, $varianteId, 1)],
            Canal::Web,
            SourceCommande::PanierWeb,
            'cle-'.uniqid(),
        );
    }

    private function produit(string $slug): Produit
    {
        return Produit::pourTousEtablissements()->where('slug', $slug)->firstOrFail();
    }

    public function test_sans_commande_en_cours_une_boutique_passe_un_produit_en_disponibilite_manuelle(): void
    {
        $this->seed();
        $this->connecte('chez-awa', 'awa@chez-awa.test');
        $huile = $this->produit('huile-de-palme-rouge-non-raffinee-bouteille-1-litre');

        $this->changerMode('chez-awa', $huile, 'interrupteur', ['disponible' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.mode_stock', 'interrupteur');

        $this->assertSame(ModeStock::Interrupteur, $huile->fresh()->mode_stock);
    }

    public function test_une_reservation_active_bloque_puis_lannulation_libere(): void
    {
        $this->seed();
        $this->connecte('chez-awa', 'awa@chez-awa.test');
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $huile = $this->produit('huile-de-palme-rouge-non-raffinee-bouteille-1-litre');
        $commande = $this->commander($chezAwa, $huile);

        $refus = $this->changerMode('chez-awa', $huile, 'interrupteur');
        $refus->assertStatus(422)->assertJsonValidationErrors(['mode_stock']);
        $this->assertSame(
            "Impossible de changer le mode de stock : 1 commande en cours contient ce produit. Confirmez-les ou annulez-les d'abord.",
            $refus->json('errors.mode_stock.0'),
        );
        $this->assertSame(ModeStock::Compte, $huile->fresh()->mode_stock);

        app(AnnulerCommande::class)->executer($commande, 'Test');

        $this->changerMode('chez-awa', $huile, 'interrupteur')->assertStatus(200);
        $this->assertSame(0, $huile->fresh()->quantite_reservee);
    }

    public function test_la_reservation_dune_variante_bloque_son_produit(): void
    {
        $this->seed();
        $this->connecte('chez-awa', 'awa@chez-awa.test');
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $riz = $this->produit('riz-parfume-long-grain');
        $this->commander($chezAwa, $riz, $riz->variantes()->pourTousEtablissements()->where('nom', 'Sac de 5 kg')->value('id'));

        $this->changerMode('chez-awa', $riz, 'interrupteur')->assertStatus(422)->assertJsonValidationErrors(['mode_stock']);
    }

    /**
     * Le sens inverse n'a aucune réservation à protéger : c'est la
     * commande en attente elle-même qui bloque. Confirmée après une
     * bascule vers le stock compté, elle ne décompterait rien.
     */
    public function test_au_restaurant_une_commande_en_attente_bloque_le_passage_au_stock_compte(): void
    {
        $this->seed();
        $maquis = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $admin = User::factory()->create(['password' => 'motdepasse', 'mot_de_passe_defini' => true]);
        EtablissementUtilisateur::create([
            'etablissement_id' => $maquis->id,
            'utilisateur_id' => $admin->id,
            'role_id' => Role::where('nom', 'admin_etablissement')->value('id'),
            'statut' => 'actif',
        ]);
        $this->connecte('maquis-du-port', $admin->email);
        $garba = $this->produit('garba');
        $commande = $this->commander($maquis, $garba);
        $this->assertSame(0, $commande->reservations()->pourTousEtablissements()->count(), 'aucune réservation en disponibilité manuelle');

        $this->changerMode('maquis-du-port', $garba, 'compte', ['quantite_stock' => 20])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode_stock']);
        $this->assertSame(ModeStock::Interrupteur, $garba->fresh()->mode_stock);

        app(ConsommerReservation::class)->executer($commande);

        $this->changerMode('maquis-du-port', $garba, 'compte', ['quantite_stock' => 20])->assertStatus(200);
        $this->assertSame([ModeStock::Compte, 20], [$garba->fresh()->mode_stock, $garba->fresh()->quantite_stock]);
    }

    public function test_renvoyer_le_mode_actuel_nest_jamais_bloque(): void
    {
        $this->seed();
        $this->connecte('chez-awa', 'awa@chez-awa.test');
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $huile = $this->produit('huile-de-palme-rouge-non-raffinee-bouteille-1-litre');
        $this->commander($chezAwa, $huile);

        $this->changerMode('chez-awa', $huile, 'compte', ['prix' => 2600])->assertStatus(200);
        $this->assertSame(2600, $huile->fresh()->prix);
    }

    public function test_un_mode_inconnu_est_refuse(): void
    {
        $this->seed();
        $this->connecte('chez-awa', 'awa@chez-awa.test');

        $this->changerMode('chez-awa', $this->produit('riz-parfume-long-grain'), 'au-poids')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode_stock']);
    }
}
