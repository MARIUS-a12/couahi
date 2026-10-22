<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace des consultations, par le super-admin, des données commerciales
 * d'un établissement (voir JournaliserConsultationPlateforme) — lue par le
 * commerçant lui-même. Qui, quand, quelle page : jamais ce qui a été vu.
 *
 * Le nom est recopié (nom_utilisateur) en plus de la clé : le commerçant
 * doit toujours savoir QUI a regardé, même après la suppression du compte
 * de la plateforme qui l'a fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations_plateforme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nom_utilisateur');
            $table->string('page');
            $table->timestamp('consulte_le')->useCurrent();

            $table->index(['etablissement_id', 'consulte_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_plateforme');
    }
};
