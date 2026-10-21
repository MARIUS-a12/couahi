<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remboursement d'une commande annulée ou expirée après paiement :
     * constaté à la main par le commerçant, comme le paiement lui-même
     * (voir EnregistrerRemboursementCommande). Montant en francs CFA entier
     * (voir AGENTS.md).
     */
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->timestamp('rembourse_le')->nullable();
            $table->foreignId('rembourse_par')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('remboursement_montant')->nullable();
            $table->string('remboursement_reference', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rembourse_par');
            $table->dropColumn(['rembourse_le', 'remboursement_montant', 'remboursement_reference']);
        });
    }
};
