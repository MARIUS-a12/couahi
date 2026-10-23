<?php

namespace Tests\Feature\Vitrine;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Génération idempotente du lien WhatsApp et plafond de secours (voir
 * GenerateurLienWhatsapp et AGENTS.md, dérogation à la règle email + IP).
 * Le visiteur est identifié par sa session. Le client de test de Laravel ne
 * rejoue PAS le cookie de session d'une requête à l'autre, et postJson
 * n'envoie aucun cookie sans withCredentials() (chaque appel ouvrirait une
 * session neuve, la déduplication ne serait jamais exercée) : cliquer()
 * renvoie donc le cookie reçu, comme le fait le navigateur (preuve faite
 * dans Chrome : deux clics portent le même identifiant de session côté
 * serveur). Un autre visiteur = sans ce cookie.
 */
class LienWhatsappDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'wax-adjame.localhost';

    private function produit(): Produit
    {
        return Produit::pourTousEtablissements()
            ->where('etablissement_id', Etablissement::where('slug', 'wax-adjame')->value('id'))
            ->where('slug', 'pagne-wax-hollandais-6-yards')
            ->firstOrFail();
    }

    private function autreProduit(): Produit
    {
        return Produit::pourTousEtablissements()
            ->where('etablissement_id', Etablissement::where('slug', 'wax-adjame')->value('id'))
            ->where('slug', 'bazin-riche-brode')
            ->firstOrFail();
    }

    /** Le cookie de session de CE visiteur, renvoyé à chaque clic comme le fait le navigateur. */
    private ?string $session = null;

    private function cliquer(Produit $produit)
    {
        $nom = config('session.cookie');
        // withCredentials : sans lui, postJson n'envoie AUCUN cookie —
        // l'équivalent exact du withCredentials d'axios côté vitrine.
        $requete = $this->withCredentials()->withHeader('Referer', 'http://'.self::HOTE.':5173');
        if ($this->session !== null) {
            $requete = $requete->withCookie($nom, $this->session);
        }

        $reponse = $requete->postJson('http://'.self::HOTE.":8000/api/vitrine/produits/{$produit->id}/lien-whatsapp");
        $this->session = $reponse->getCookie($nom)?->getValue() ?? $this->session;

        return $reponse;
    }

    private function reference($reponse): string
    {
        preg_match('/\[REF:([A-Za-z0-9]{8})\]/', rawurldecode($reponse->assertStatus(200)->json('url')), $m);

        return $m[1];
    }

    private function nombreDeReferences(): int
    {
        return ReferenceWhatsapp::pourTousEtablissements()->count();
    }

    public function test_deux_clics_identiques_a_moins_de_10_minutes_ne_creent_quune_reference(): void
    {
        $this->seed();
        $avant = $this->nombreDeReferences();

        $premiere = $this->reference($this->cliquer($this->produit()));
        $this->travel(9)->minutes();
        $seconde = $this->reference($this->cliquer($this->produit()));

        $this->assertSame($premiere, $seconde);
        $this->assertSame($avant + 1, $this->nombreDeReferences());

        // L'empreinte, jamais l'identifiant de session lui-même.
        $cle = ReferenceWhatsapp::pourTousEtablissements()->where('code', $premiere)->value('cle_visiteur');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cle);
    }

    public function test_un_autre_produit_cree_bien_une_nouvelle_reference(): void
    {
        $this->seed();
        $avant = $this->nombreDeReferences();

        $premiere = $this->reference($this->cliquer($this->produit()));
        $autre = $this->reference($this->cliquer($this->autreProduit()));

        $this->assertNotSame($premiere, $autre);
        $this->assertSame($avant + 2, $this->nombreDeReferences());
    }

    public function test_apres_10_minutes_le_meme_clic_cree_une_nouvelle_reference(): void
    {
        $this->seed();

        $premiere = $this->reference($this->cliquer($this->produit()));
        $this->travel(11)->minutes();

        $this->assertNotSame($premiere, $this->reference($this->cliquer($this->produit())));
    }

    public function test_un_autre_visiteur_obtient_sa_propre_reference(): void
    {
        $this->seed();

        $premiere = $this->reference($this->cliquer($this->produit()));

        // Un autre navigateur : sans le cookie du premier, donc une session neuve.
        $this->session = null;
        $this->defaultCookies = [];
        $seconde = $this->reference($this->cliquer($this->produit()));

        $this->assertNotSame($premiere, $seconde);
    }

    public function test_le_61e_appel_dans_lheure_renvoie_429(): void
    {
        $this->seed();
        $produit = $this->produit();

        for ($appel = 1; $appel <= 60; $appel++) {
            $this->cliquer($produit)->assertStatus(200);
        }

        $this->cliquer($produit)->assertStatus(429);
        // Les 60 appels n'ont créé qu'une seule demande pour le commerçant.
        $this->assertSame(1, ReferenceWhatsapp::pourTousEtablissements()->where('produit_id', $produit->id)->count());
    }
}
