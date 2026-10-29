<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une commande prise au téléphone ou sur WhatsApp n'exige que le numéro et
 * la commune : le commerçant ne connaît pas toujours le nom du client. Le
 * client existe alors sans nom ; la vitrine, elle, l'exige toujours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('nom')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Un client resté sans nom reçoit son numéro, pour revenir à NOT NULL.
        DB::statement('UPDATE clients SET nom = telephone WHERE nom IS NULL');

        Schema::table('clients', function (Blueprint $table) {
            $table->string('nom')->nullable(false)->change();
        });
    }
};
