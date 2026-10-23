<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le type d'un établissement n'était garanti que par la validation du
 * formulaire de création (StoreEtablissementRequest) : une écriture qui la
 * contourne (seeder, tinker, futur import) pouvait poser « epicerie », et
 * Etablissement::estRestaurant() l'aurait traité en boutique sans rien dire.
 *
 * Même limite que les autres CHECK du projet (voir la migration de
 * garde-fous des produits) : PostgreSQL seulement, prouvé par
 * Tests\Feature\Postgres\ContraintesEtablissementsTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE etablissements ADD CONSTRAINT etablissements_type_valide CHECK (type IN ('boutique', 'restaurant'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE etablissements DROP CONSTRAINT etablissements_type_valide');
    }
};
