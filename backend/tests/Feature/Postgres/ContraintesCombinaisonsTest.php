<?php

namespace Tests\Feature\Postgres;

use App\Enums\StatutVariante;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

/**
 * B1 — ce que la base PostgreSQL refuse seule, sans compter sur le code :
 * catégories à deux niveaux au plus (clé composée + CHECK), unicité à la
 * casse près, unicité des combinaisons actives, valeur utilisée
 * inefaçable. Chaque cas tente réellement l'écriture interdite.
 */
class ContraintesCombinaisonsTest extends PostgresTestCase
{
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->etablissement = Etablissement::factory()->boutique()->create();
        app(ContexteEtablissement::class)->definir($this->etablissement);
    }

    private function refusee(callable $ecriture, string $codeSql): void
    {
        try {
            DB::transaction($ecriture);
            $this->fail("L'écriture aurait dû être refusée (SQLSTATE {$codeSql}).");
        } catch (QueryException $e) {
            $this->assertSame($codeSql, $e->getCode(), $e->getMessage());
        }
    }

    private function categorie(string $nom, ?int $parentId = null): Categorie
    {
        return Categorie::factory()->for($this->etablissement)->create(['nom' => $nom, 'slug' => str($nom)->slug()->value(), 'parent_id' => $parentId]);
    }

    public function test_une_sous_categorie_est_acceptee_mais_jamais_un_troisieme_niveau(): void
    {
        $chaussures = $this->categorie('Chaussures');
        $sandales = $this->categorie('Sandales', $chaussures->id);
        $this->assertSame($chaussures->id, $sandales->parent->id);

        $this->refusee(fn () => $this->categorie('Tongs', $sandales->id), '23503');
    }

    public function test_une_categorie_qui_a_des_enfants_ne_peut_pas_devenir_enfant(): void
    {
        $chaussures = $this->categorie('Chaussures');
        $this->categorie('Sandales', $chaussures->id);
        $mode = $this->categorie('Mode');

        $this->refusee(fn () => $chaussures->update(['parent_id' => $mode->id]), '23503');
    }

    public function test_le_parent_est_du_meme_etablissement(): void
    {
        $ailleurs = Categorie::factory()->for(Etablissement::factory()->boutique()->create())->create(['nom' => 'Ailleurs', 'slug' => 'ailleurs']);

        $this->refusee(fn () => $this->categorie('Sandales', $ailleurs->id), '23503');
    }

    /**
     * Ces écritures contournent le modèle (mise à jour par requête, sans
     * l'événement saving qui calcule est_racine) : c'est la base seule qui
     * refuse.
     */
    public function test_les_colonnes_de_niveau_ne_peuvent_pas_mentir(): void
    {
        $chaussures = $this->categorie('Chaussures');

        $this->refusee(fn () => Categorie::whereKey($chaussures->id)->update(['parent_id' => $chaussures->id, 'est_racine' => false, 'parent_est_racine' => true]), '23514');
        $this->refusee(fn () => Categorie::whereKey($chaussures->id)->update(['est_racine' => false]), '23514');
        $this->refusee(fn () => Categorie::whereKey($this->categorie('Mode')->id)->update(['parent_id' => $chaussures->id, 'est_racine' => false, 'parent_est_racine' => null]), '23514');
    }

    public function test_unicite_a_la_casse_pres_et_des_combinaisons_actives(): void
    {
        $produit = Produit::factory()->for($this->etablissement)->create();
        $taille = OptionProduit::create(['produit_id' => $produit->id, 'nom' => 'Taille', 'ordre' => 1]);
        $m = ValeurOption::create(['option_id' => $taille->id, 'libelle' => 'M', 'ordre' => 1]);

        $this->refusee(fn () => OptionProduit::create(['produit_id' => $produit->id, 'nom' => 'TAILLE', 'ordre' => 2]), '23505');
        $this->refusee(fn () => ValeurOption::create(['option_id' => $taille->id, 'libelle' => 'm', 'ordre' => 2]), '23505');

        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M', 'statut' => StatutVariante::Inactif]);
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);
        $this->refusee(fn () => VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M bis']), '23505');

        $this->refusee(fn () => $m->delete(), '23503');
    }

    public function test_une_variante_sans_signature_est_refusee(): void
    {
        $produit = Produit::factory()->for($this->etablissement)->create();

        $this->refusee(fn () => VarianteProduit::factory()->pourProduit($produit)->create(['nom' => 'M', 'signature' => null]), '23502');
    }
}
