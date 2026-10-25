<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le choix explicite du commerçant : facturer la livraison selon la
 * commune (ses zones), ou ne pas la facturer sur le site. Faux par défaut :
 * c'est le comportement de toutes les boutiques jusqu'ici, aucune n'ayant
 * de zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres_site', function (Blueprint $table) {
            $table->boolean('livraison_par_commune')->default(false)->after('delai_preparation_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('parametres_site', function (Blueprint $table) {
            $table->dropColumn('livraison_par_commune');
        });
    }
};
