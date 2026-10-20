<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numérotation des commandes PAR ÉTABLISSEMENT (voir
     * GenererNumeroCommande et decisions.md) : une ligne de compteur par
     * établissement remplace la séquence globale commandes_numero_seq, qui
     * faisait commencer un nouveau commerçant à CMD-000017 et lui révélait
     * l'activité des autres boutiques.
     *
     * Les compteurs partent du plus grand numéro DÉJÀ attribué dans chaque
     * établissement : aucune commande existante n'est renumérotée (ces
     * numéros ont été donnés aux clients), et l'unicité (etablissement_id,
     * numero) ne peut pas être prise en défaut par une reprise à 1.
     *
     * Une table ordinaire, contrairement à la séquence : migrate:fresh
     * l'efface comme les autres, la numérotation repart donc bien de 1.
     */
    public function up(): void
    {
        Schema::create('compteurs_numero_commande', function (Blueprint $table) {
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->unsignedInteger('dernier');
            // La clé de "on conflict" dans GenererNumeroCommande.
            $table->primary('etablissement_id');
        });

        $plusGrands = [];

        foreach (DB::table('commandes')->select('etablissement_id', 'numero')->cursor() as $commande) {
            $valeur = (int) substr($commande->numero, strlen('CMD-'));
            $plusGrands[$commande->etablissement_id] = max($plusGrands[$commande->etablissement_id] ?? 0, $valeur);
        }

        foreach ($plusGrands as $etablissementId => $dernier) {
            DB::table('compteurs_numero_commande')->insert(['etablissement_id' => $etablissementId, 'dernier' => $dernier]);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS commandes_numero_seq');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Repart au-delà du plus grand compteur : jamais un numéro déjà
            // donné, quel que soit l'établissement.
            $depart = (int) DB::table('compteurs_numero_commande')->max('dernier') + 1;
            DB::statement("CREATE SEQUENCE IF NOT EXISTS commandes_numero_seq AS integer START WITH {$depart}");
        }

        Schema::dropIfExists('compteurs_numero_commande');
    }
};
