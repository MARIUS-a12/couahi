<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 12 — abonnement des commerçants à la plateforme : UNE ligne par
 * établissement (etablissement_id unique), jamais une ligne par mois — le
 * détail mois par mois vit dans "paiements_abonnement". Montants en
 * entiers (francs CFA, sans subdivision en usage) : un nombre à virgule
 * finirait tôt ou tard par un arrondi faux sur une somme d'argent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->unique()->constrained('etablissements')->cascadeOnDelete();
            $table->unsignedInteger('montant_mensuel');
            $table->date('date_echeance');
            $table->string('statut')->default('essai');
            $table->unsignedSmallInteger('jours_de_grace')->default(7);
            $table->timestamps();

            $table->index(['statut', 'date_echeance']);
        });

        Schema::create('paiements_abonnement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->unsignedInteger('montant');
            $table->date('periode_debut');
            $table->date('periode_fin');
            $table->string('moyen');
            $table->string('reference')->nullable();
            // Nul quand le commerçant a payé lui-même en ligne ; renseigné
            // quand le super-admin enregistre un paiement reçu hors ligne.
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paye_le');
            $table->timestamps();

            $table->index(['etablissement_id', 'paye_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_abonnement');
        Schema::dropIfExists('abonnements');
    }
};
