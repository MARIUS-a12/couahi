<?php

namespace Tests\Feature\Vitrine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couahi est invisible pour le client final : les cookies qu'une boutique
 * pose dans son navigateur ne portent ni le nom de la plateforme, ni celui
 * du cadre technique. Le nom du cookie de session ne dérive plus de
 * APP_NAME (voir config/session.php).
 */
class CookiesNeutresTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_boutique_ne_pose_aucun_cookie_qui_trahisse_la_plateforme(): void
    {
        $this->seed();
        config(['app.name' => 'Couahi']);

        $reponse = $this->withHeader('Referer', 'http://chez-awa.localhost:5173')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->assertStatus(200);

        $noms = array_map(fn ($cookie) => $cookie->getName(), $reponse->headers->getCookies());

        $this->assertContains('session', $noms, 'la vitrine ouvre bien une session');
        foreach ($noms as $nom) {
            $this->assertDoesNotMatchRegularExpression('/couahi|laravel/i', $nom);
        }
    }
}
