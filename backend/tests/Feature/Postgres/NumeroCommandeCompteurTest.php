<?php

namespace Tests\Feature\Postgres;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\GenererNumeroCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PostgresTestCase;

/**
 * Anciennement NumeroCommandeSequenceTest : la séquence globale
 * commandes_numero_seq a été remplacée par un compteur par établissement
 * (voir GenererNumeroCommande). Mêmes deux vérifications, sur le vrai
 * PostgreSQL : numéros formatés et consécutifs, deux commandes distinctes.
 */
class NumeroCommandeCompteurTest extends PostgresTestCase
{
    public function test_le_compteur_produit_des_numeros_formates_et_consecutifs(): void
    {
        $etablissement = Etablissement::factory()->boutique()->create();
        $generateur = app(GenererNumeroCommande::class);

        [$premier, $second] = DB::transaction(fn () => [$generateur->executer($etablissement->id), $generateur->executer($etablissement->id)]);

        $this->assertSame('CMD-000001', $premier);
        $this->assertSame('CMD-000002', $second);
    }

    public function test_deux_commandes_recoivent_deux_numeros_distincts(): void
    {
        $etablissement = Etablissement::factory()->boutique()->create();
        $client = $etablissement->clients()->create(['nom' => 'Client Test']);
        $produit = Produit::factory()->for($etablissement)->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $service = app(CreerCommande::class);

        $premiere = $service->executer(
            $etablissement, $client, [new LigneCommandeDemandee($produit->id, null, 1)],
            Canal::Web, SourceCommande::PanierWeb, 'cle-numero-1',
        );
        $seconde = $service->executer(
            $etablissement, $client, [new LigneCommandeDemandee($produit->id, null, 1)],
            Canal::Web, SourceCommande::PanierWeb, 'cle-numero-2',
        );

        $this->assertSame('CMD-000001', $premiere->numero);
        $this->assertSame('CMD-000002', $seconde->numero);
    }

    public function test_la_sequence_globale_nexiste_plus(): void
    {
        $this->assertNull(DB::selectOne("select to_regclass('commandes_numero_seq') as seq")->seq);
        $this->assertTrue(Schema::hasTable('compteurs_numero_commande'));
    }
}
