<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesEtPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'voir_catalogue' => 'Voir le catalogue',
        'gerer_catalogue' => 'Gérer le catalogue',
        // Étape 10 : distincte de gerer_catalogue — autorise UNIQUEMENT la
        // modification des quantités en stock, jamais un prix, un nom, ni la
        // publication/archivage d'un produit (voir ProduitPolicy::ajusterStock
        // et AjusterStockProduitRequest, qui n'accepte que ce seul champ).
        'gerer_stock' => 'Gérer le stock',
        'voir_commandes' => 'Voir les commandes',
        // Lot F : gerer_commandes découpé en trois, voir decisions.md.
        // Confirmer, marquer prête, marquer livrée, annuler une commande
        // qui n'a reçu AUCUN paiement.
        'traiter_commandes' => 'Traiter les commandes',
        // Vendre au comptoir et confirmer un paiement reçu.
        'encaisser' => 'Encaisser',
        // Annuler une commande qui a reçu un paiement, même partiel, et
        // rembourser : sinon un caissier vend, garde l'argent, puis annule.
        'annuler_payees' => 'Annuler les commandes payées et rembourser',
        // Lot C : saisir une commande WhatsApp ou téléphone. Le comptoir
        // demande encaisser, pas cette permission.
        'creer_commandes' => 'Saisir des commandes',
        // Lot D : voir et traiter les demandes WhatsApp. Distincte des
        // commandes : répondre à des clients et toucher au stock ne sont
        // pas le même droit.
        'gerer_demandes_whatsapp' => 'Gérer les demandes WhatsApp',
        // Les montants du tableau de bord (chiffre d'affaires, panier
        // moyen) : voir CommandeController::statistiques.
        'voir_statistiques' => 'Voir les statistiques',
        'gerer_parametres' => 'Gérer les paramètres',
        // Étape 10 : page /admin/equipe, réservée au seul rôle
        // admin_etablissement (voir EtablissementUtilisateurPolicy).
        'gerer_equipe' => "Gérer l'équipe",
    ];

    private const ROLES = [
        'super_admin' => 'Super administrateur',
        'admin_etablissement' => "Administrateur d'établissement",
        'operateur' => 'Opérateur',
        'caissier' => 'Caissier',
        'gestionnaire_stock' => 'Gestionnaire de stock',
    ];

    /**
     * Libellé affiché sous le nom de la boutique dans la barre latérale du
     * back-office ("Espace {libellé}") — voir SessionController::reponseMoi
     * et BarreLaterale côté frontend, qui ne connaît plus aucun rôle par son
     * nom : tout nouveau rôle ajouté ICI y apparaît sans y toucher.
     */
    private const LIBELLES_ESPACE = [
        'super_admin' => 'Administration',
        'admin_etablissement' => 'Administrateur',
        'operateur' => 'Opérateur',
        'caissier' => 'Caisse',
        'gestionnaire_stock' => 'Stock',
    ];

    /**
     * super_admin et admin_etablissement ont les mêmes permissions, mais
     * restent deux rôles distincts : super_admin n'est jamais rattaché à un
     * établissement (voir EtablissementUtilisateur) et contourne les
     * policies via Gate::before, indépendamment de cette table.
     *
     * gerer_catalogue implique déjà de pouvoir lire (voir ProduitPolicy /
     * CategoriePolicy) : voir_catalogue n'a donc besoin d'être listé
     * explicitement que pour les rôles qui consultent sans modifier.
     */
    private const ATTRIBUTIONS = [
        'super_admin' => [
            'voir_catalogue', 'gerer_catalogue', 'gerer_stock', 'voir_commandes', 'traiter_commandes', 'encaisser', 'annuler_payees',
            'creer_commandes', 'gerer_demandes_whatsapp', 'voir_statistiques', 'gerer_parametres', 'gerer_equipe',
        ],
        'admin_etablissement' => [
            'voir_catalogue', 'gerer_catalogue', 'gerer_stock', 'voir_commandes', 'traiter_commandes', 'encaisser', 'annuler_payees',
            'creer_commandes', 'gerer_demandes_whatsapp', 'voir_statistiques', 'gerer_parametres', 'gerer_equipe',
        ],
        // Prend les commandes WhatsApp et téléphone et les fait avancer,
        // sans toucher à l'argent.
        'operateur' => ['voir_catalogue', 'voir_commandes', 'traiter_commandes', 'creer_commandes', 'gerer_demandes_whatsapp'],
        // Vend au comptoir et confirme les paiements reçus.
        'caissier' => ['voir_catalogue', 'voir_commandes', 'encaisser'],
        // Ajuste les quantités, ne crée ni ne modifie les produits eux-mêmes.
        // Voit les commandes, en lecture seule, pour les préparer.
        'gestionnaire_stock' => ['voir_catalogue', 'gerer_stock', 'voir_commandes'],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $libelle, string $nom) => Permission::updateOrCreate(['nom' => $nom], ['libelle' => $libelle])
        );

        foreach (self::ROLES as $nomRole => $libelleRole) {
            $role = Role::updateOrCreate(
                ['nom' => $nomRole],
                ['libelle' => $libelleRole, 'libelle_espace' => self::LIBELLES_ESPACE[$nomRole] ?? null],
            );

            $role->permissions()->sync(
                $permissions->only(self::ATTRIBUTIONS[$nomRole])->pluck('id')
            );
        }

        // Lot F : gerer_clients et gerer_integrations ne commandaient
        // aucun écran, gerer_commandes est découpé. Une permission retirée
        // de la liste disparaît, ses attributions avec elle (cascade).
        Permission::whereNotIn('nom', array_keys(self::PERMISSIONS))->delete();
    }
}
