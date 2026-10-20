<?php

namespace Tests\Feature\Vitrine;

use App\Enums\StatutProduit;
use App\Models\Etablissement;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aperçu d'un lien de vitrine partagé sur WhatsApp : nom de la boutique,
 * description, logo ; sur une fiche produit, le produit d'abord. Jamais un
 * produit qui ne se vend pas sur CE sous-domaine.
 */
class PartageVitrineTest extends TestCase
{
    use RefreshDatabase;

    private function partage(string $hote, string $chemin): array
    {
        return $this->getJson("http://{$hote}:8000/api/vitrine/partage?chemin=".urlencode($chemin))->assertStatus(200)->json('data');
    }

    private function produit(string $slugEtablissement, string $slugProduit): Produit
    {
        $etablissement = Etablissement::where('slug', $slugEtablissement)->firstOrFail();

        return Produit::pourTousEtablissements()->where('etablissement_id', $etablissement->id)->where('slug', $slugProduit)->firstOrFail();
    }

    public function test_accueil_nom_description_et_logo_de_la_boutique(): void
    {
        $this->seed();

        $apercu = $this->partage('wax-adjame.localhost', '/');

        $this->assertSame('Wax Adjamé', $apercu['titre']);
        $this->assertStringStartsWith('Pagnes wax, bazin et kita', $apercu['description']);
        $this->assertStringEndsWith('-grand.jpg', $apercu['image']);
    }

    public function test_fiche_produit_nom_du_produit_puis_de_la_boutique_et_sa_photo(): void
    {
        $this->seed();
        $produit = $this->produit('wax-adjame', 'pagne-wax-hollandais-6-yards');

        $apercu = $this->partage('wax-adjame.localhost', "/produit/{$produit->id}");

        $this->assertSame('Pagne wax hollandais 6 yards — Wax Adjamé', $apercu['titre']);
        $this->assertStringStartsWith('Wax hollandais véritable', $apercu['description']);
        $this->assertStringEndsWith('-moyenne.jpg', $apercu['image']);
    }

    public function test_un_produit_sans_photo_montre_le_logo(): void
    {
        $this->seed();
        $produit = $this->produit('wax-adjame', 'bazin-riche-brode');

        $this->assertStringEndsWith('-grand.jpg', $this->partage('wax-adjame.localhost', "/produit/{$produit->id}")['image']);
    }

    public function test_un_produit_archive_ou_dune_autre_boutique_retombe_sur_la_boutique(): void
    {
        $this->seed();
        $archive = $this->produit('wax-adjame', 'wax-collection-tabaski-2025');
        $this->assertSame(StatutProduit::Archive, $archive->statut);
        $produitChezAwa = Produit::pourTousEtablissements()
            ->where('etablissement_id', Etablissement::where('slug', 'chez-awa')->value('id'))
            ->where('statut', StatutProduit::Publie)
            ->firstOrFail();

        $this->assertSame('Wax Adjamé', $this->partage('wax-adjame.localhost', "/produit/{$archive->id}")['titre']);
        $this->assertSame('Wax Adjamé', $this->partage('wax-adjame.localhost', "/produit/{$produitChezAwa->id}")['titre']);
    }
}
