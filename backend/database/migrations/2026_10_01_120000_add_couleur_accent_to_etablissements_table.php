<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Couleur d'accent propre à la vitrine de chaque établissement (voir Étape
 * 6A bis — identité visuelle) : nulle par défaut, auquel cas la vitrine
 * retombe sur CouleurAccent::PAR_DEFAUT (voir EtablissementVitrineResource).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('couleur_accent', 7)->nullable()->after('telephone');
        });

        // Voir 2026_09_22_190100_add_garde_fous_produits_et_variantes_produit_table
        // pour l'explication de cette garde : les CHECK ne sont pas
        // exprimables via le Schema Builder, et cette syntaxe est propre à
        // PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE etablissements ADD CONSTRAINT etablissements_couleur_accent_valide CHECK (couleur_accent IS NULL OR couleur_accent ~ '^#[0-9a-fA-F]{6}$')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE etablissements DROP CONSTRAINT etablissements_couleur_accent_valide');
        }

        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn('couleur_accent');
        });
    }
};
