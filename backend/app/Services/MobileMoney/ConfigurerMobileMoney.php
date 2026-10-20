<?php

namespace App\Services\MobileMoney;

use App\Enums\OperateurMobileMoney;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\User;
use App\Support\Telephone\NormaliseurTelephone;
use App\Support\Vitrine\CacheVitrine;

/**
 * Pose ou retire les trois champs Mobile Money ENSEMBLE (jamais un seul),
 * journalise, et invalide le cache vitrine : le bouton "Payer par Mobile
 * Money" doit apparaître ou disparaître sans attendre son expiration. Le
 * numéro est normalisé ici ("0X XX XX XX XX" et "+225…" donnent la même
 * valeur stockée), exactement comme celui d'un client.
 */
class ConfigurerMobileMoney
{
    public function configurer(
        Etablissement $etablissement,
        string $numero,
        OperateurMobileMoney $operateur,
        string $beneficiaire,
        User $utilisateur,
        ?string $adresseIp,
    ): Etablissement {
        $etablissement->mm_numero = NormaliseurTelephone::normaliser($numero);
        $etablissement->mm_operateur = $operateur;
        $etablissement->mm_beneficiaire = $beneficiaire;
        $etablissement->save();

        $this->journaliser('mobile_money_configure', $etablissement, $utilisateur, $adresseIp, [
            'operateur' => $operateur->value,
            'numero' => $etablissement->mm_numero,
            'beneficiaire' => $beneficiaire,
        ]);

        return $etablissement;
    }

    public function retirer(Etablissement $etablissement, User $utilisateur, ?string $adresseIp): void
    {
        $etablissement->mm_numero = null;
        $etablissement->mm_operateur = null;
        $etablissement->mm_beneficiaire = null;
        $etablissement->save();

        $this->journaliser('mobile_money_retire', $etablissement, $utilisateur, $adresseIp, []);
    }

    /**
     * Le numéro et le nom sont journalisés en clair : ce ne sont pas des
     * secrets (chaque client les voit), et savoir QUEL numéro était annoncé
     * à quelle date est précisément ce qu'on cherchera après un litige.
     *
     * @param  array<string, mixed>  $details
     */
    private function journaliser(string $action, Etablissement $etablissement, User $utilisateur, ?string $adresseIp, array $details): void
    {
        JournalAudit::create([
            'utilisateur_id' => $utilisateur->id,
            'action' => $action,
            'details' => ['etablissement_id' => $etablissement->id, ...$details],
            'adresse_ip' => $adresseIp,
        ]);

        CacheVitrine::invalider($etablissement->id);
    }
}
