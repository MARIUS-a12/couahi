<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 1 — test 4 : Yao (opérateur) n'a pas gerer_parametres ni
 * voir_statistiques. Lot F : sans voir_statistiques, les comptes des
 * commandes lui restent, mais aucun montant n'est dans la réponse.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    public function test_4_operateur_voit_les_commandes_mais_pas_les_parametres_ni_les_montants(): void
    {
        $this->seed();

        $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/connexion', [
            'email' => 'yao@maquis-du-port.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/parametres')
            ->assertStatus(403);

        $statistiques = $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/commandes/statistiques')
            ->assertStatus(200);
        $this->assertSame(['commandes_du_jour', 'en_attente', 'en_attente_payees'], array_keys($statistiques->json()));
        $this->assertStringNotContainsString('chiffre_affaires', $statistiques->getContent());
        $this->assertStringNotContainsString('panier_moyen', $statistiques->getContent());

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/commandes')
            ->assertStatus(200);
    }

    public function test_ladministrateur_voit_les_montants(): void
    {
        $this->seed();

        $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/connexion', [
            'email' => 'adjoua@maquis-du-port.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/commandes/statistiques')
            ->assertStatus(200)
            ->assertJsonStructure(['commandes_du_jour', 'en_attente', 'en_attente_payees', 'chiffre_affaires_jour', 'panier_moyen_mois']);
    }

    public function test_la_route_des_statistiques_sans_contenu_nexiste_plus(): void
    {
        $this->seed();

        $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/connexion', [
            'email' => 'adjoua@maquis-du-port.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/statistiques')
            ->assertStatus(404);
    }
}
