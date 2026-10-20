<?php

namespace Tests\Feature\SuperAdmin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtablissementsApiTest extends TestCase
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

    public function test_super_admin_recoit_la_liste_des_etablissements(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $reponse = $this->depuis('admin.localhost')->getJson('http://admin.localhost:8000/api/etablissements');

        $reponse->assertStatus(200);
        $reponse->assertJsonCount(3, 'data');
        $etablissements = collect($reponse->json('data'))->keyBy('nom');
        $this->assertSame('boutique', $etablissements['Chez Awa']['type']);
        $this->assertSame('chez-awa.localhost', $etablissements['Chez Awa']['sous_domaine']);
        $this->assertSame('restaurant', $etablissements['Maquis du Port']['type']);
        $this->assertSame('maquis-du-port.localhost', $etablissements['Maquis du Port']['sous_domaine']);
        $this->assertSame('boutique', $etablissements['Wax Adjamé']['type']);
        $this->assertSame('wax-adjame.localhost', $etablissements['Wax Adjamé']['sous_domaine']);
    }

    /**
     * admin_etablissement a toutes les permissions de son propre catalogue,
     * mais la liste des établissements de la plateforme n'en fait pas
     * partie : Gate::before ne le laisse passer que s'il est super-admin.
     */
    public function test_admin_etablissement_recoit_403_sur_liste_etablissements(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/etablissements')
            ->assertStatus(403);
    }

    /**
     * Le super-admin n'appartient à aucun établissement : une requête sur un
     * modèle tenant-scopé (produits) depuis son hôte dédié ne doit jamais
     * planter en 500 — c'est une situation prévue (voir
     * EtablissementNonResoluException), pas un bug.
     */
    public function test_super_admin_recoit_400_et_non_500_sur_get_produits(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $reponse = $this->depuis('admin.localhost')->getJson('http://admin.localhost:8000/api/produits');

        $reponse->assertStatus(400);
        $this->assertStringContainsString('Aucun établissement résolu', $reponse->json('message'));
    }
}
