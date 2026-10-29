<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Autre commune » à frais fixes : une zone à part, qui facture toute
 * commune absente de la liste du commerçant. Une seule active par
 * établissement ; sans elle, « Autre commune » reste à convenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones_livraison', function (Blueprint $table) {
            $table->boolean('autre_commune')->default(false)->after('nom');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX zones_livraison_autre_commune_unique ON zones_livraison (etablissement_id) WHERE autre_commune AND statut = 'actif'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS zones_livraison_autre_commune_unique');
        }

        Schema::table('zones_livraison', function (Blueprint $table) {
            $table->dropColumn('autre_commune');
        });
    }
};
