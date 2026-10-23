<?php

use App\Http\Controllers\Api\AbonnementController;
use App\Http\Controllers\Api\ActivationController;
use App\Http\Controllers\Api\CategorieController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\CompteController;
use App\Http\Controllers\Api\ConsultationPlateformeController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\IdentiteEtablissementController;
use App\Http\Controllers\Api\MediaProduitController;
use App\Http\Controllers\Api\MembreEquipeController;
use App\Http\Controllers\Api\MobileMoneyEtablissementController;
use App\Http\Controllers\Api\PaiementEtablissementController;
use App\Http\Controllers\Api\ParametresController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StatistiquesController;
use App\Http\Controllers\Api\Vitrine\VitrineCategorieController;
use App\Http\Controllers\Api\Vitrine\VitrineCommandeController;
use App\Http\Controllers\Api\Vitrine\VitrineEtablissementController;
use App\Http\Controllers\Api\Vitrine\VitrinePanierController;
use App\Http\Controllers\Api\Vitrine\VitrineProduitController;
use App\Http\Controllers\Api\Vitrine\VitrineZoneLivraisonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Pas de "resoudre.etablissement" ici : SessionController résout lui-même le
// domaine, SANS lever sur un établissement inactif (voir sa docblock) — la
// version stricte du middleware romprait l'uniformité des 4 échecs de
// connexion (voir Étape 1, exigence n°7).
// Correctif limitation de débit : "throttle:5,1" (5/minute, par IP seule)
// bloquait des abonnés mobiles ivoiriens sans lien entre eux, partageant la
// même IP. Remplacé par le limiteur nommé "connexion" (voir
// LimiteurEmailEtIp) : 5/heure par email, 30/heure par IP.
Route::post('/connexion', [SessionController::class, 'store'])->middleware('throttle:connexion');

// Correctif activation par code (Étape 10) — publique, sans session : un
// employé qui vient d'être créé n'en a aucune. "resoudre.etablissement"
// (non la résolution ad hoc de SessionController) scope la recherche de
// code au SOUS-DOMAINE visité, exactement comme la vitrine ci-dessous — un
// code valide pour un autre établissement y est donc invisible, jamais une
// question de correspondance par email seul. Même limiteur combiné que
// /connexion ci-dessus, voir LimiteurEmailEtIp.
Route::middleware('resoudre.etablissement')->post('/activation', [ActivationController::class, 'activer'])
    ->middleware('throttle:activation');

// Vitrine publique : ni "auth:sanctum" ni "resoudre.etablissement" strict au
// sens d'exiger une session — un visiteur anonyme doit pouvoir tout
// consulter. Seul "resoudre.etablissement" s'applique (isolation par
// sous-domaine, 404 si l'établissement est inactif ou inconnu), puis
// "abonnement.vitrine" (Étape 12 : 404 aussi si l'abonnement est suspendu
// — la vitrine seule, jamais le back-office, voir VerifierAbonnementVitrine).
Route::middleware(['resoudre.etablissement', 'abonnement.vitrine'])->prefix('vitrine')->group(function () {
    Route::get('/produits', [VitrineProduitController::class, 'index']);
    Route::get('/produits/{id}', [VitrineProduitController::class, 'show']);
    // Génération idempotente (voir GenerateurLienWhatsapp) + plafond de
    // secours par IP et établissement — voir AGENTS.md, dérogation à la
    // règle email + IP pour une route publique anonyme.
    Route::post('/produits/{id}/lien-whatsapp', [VitrineProduitController::class, 'lienWhatsapp'])->middleware('throttle:lien-whatsapp');
    Route::get('/categories', [VitrineCategorieController::class, 'index']);
    Route::get('/etablissement', [VitrineEtablissementController::class, 'show']);
    Route::get('/partage', [VitrineEtablissementController::class, 'partage']);

    // Étape 6B — panier et commande : le navigateur n'envoie que des
    // identifiants et des quantités, jamais un prix (voir
    // CreerCommandeVitrineRequest). "commandes/{numero}" avant tout autre
    // verbe sur "commandes" n'a pas d'ambiguïté possible ici (un seul GET).
    Route::post('/panier/verifier', [VitrinePanierController::class, 'verifier']);
    Route::get('/zones-livraison', [VitrineZoneLivraisonController::class, 'index']);
    Route::post('/commandes', [VitrineCommandeController::class, 'store']);
    Route::get('/commandes/{numero}', [VitrineCommandeController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/deconnexion', [SessionController::class, 'destroy']);
    Route::get('/moi', [SessionController::class, 'moi'])->middleware('resoudre.etablissement');

    // "Mon compte" — Étape 7 : accessible à tout utilisateur authentifié,
    // super-admin compris, donc volontairement hors de "resoudre.etablissement".
    Route::patch('/compte/mot-de-passe', [CompteController::class, 'mettreAJourMotDePasse'])->middleware('throttle:5,60');
    Route::patch('/compte/profil', [CompteController::class, 'mettreAJourProfil']);

    // Pas de "resoudre.etablissement" ici : réservées au super-admin (voir
    // EtablissementPolicy), qui n'a justement pas d'établissement courant.
    // "sous-domaine-disponible" avant "{etablissement}" : sinon ce dernier
    // capturerait le chemin littéral comme un id de route.
    Route::get('/etablissements/sous-domaine-disponible', [EtablissementController::class, 'verifierSousDomaine']);
    Route::get('/etablissements', [EtablissementController::class, 'index']);
    Route::post('/etablissements', [EtablissementController::class, 'store']);

    // Correctif lenteur/page vide — toute route super-admin ciblant UN
    // établissement précis pose le contexte de tenancy sur lui (voir
    // DefinirEtablissementCible) : sans ça, la moindre donnée scopée de cet
    // établissement (médias, commandes...) lève EtablissementNonResoluException,
    // faute de sous-domaine à résoudre sur l'hôte du super-admin. UN SEUL
    // endroit pose ce contexte — jamais pourTousEtablissements() pour
    // contourner le scope depuis une route web.
    //
    // Toute route de ce groupe qui renvoie des données commerciales de
    // l'établissement (commandes, clients, chiffre d'affaires) porte
    // "journaliser.consultation:{page}" : le commerçant voit ces
    // consultations dans son back-office. ConsultationsPlateformeTest
    // refuse toute nouvelle route ici qui n'est ni journalisée, ni
    // explicitement classée administrative.
    Route::middleware('definir.etablissement.cible')->group(function () {
        Route::get('/etablissements/{etablissement}', [EtablissementController::class, 'show']);
        Route::post('/etablissements/{etablissement}/suspendre', [EtablissementController::class, 'suspendre']);
        Route::post('/etablissements/{etablissement}/reactiver', [EtablissementController::class, 'reactiver']);
        Route::delete('/etablissements/{etablissement}', [EtablissementController::class, 'destroy']);

        // Étape 6A ter — identité (chemin super-admin, n'importe quel
        // établissement) : MÊME contrôleur, MÊME service que le chemin
        // commerçant ci-dessous, voir IdentiteEtablissementController.
        Route::get('/etablissements/{etablissement}/identite', [IdentiteEtablissementController::class, 'show']);
        Route::patch('/etablissements/{etablissement}/identite', [IdentiteEtablissementController::class, 'update']);
        Route::post('/etablissements/{etablissement}/identite/logo', [IdentiteEtablissementController::class, 'uploaderLogo']);
        Route::delete('/etablissements/{etablissement}/identite/logo', [IdentiteEtablissementController::class, 'supprimerLogo']);

        // Étape 6C-1 — paiement (chemin super-admin, pour dépanner un
        // commerçant) : MÊME contrôleur, MÊMES services que le chemin
        // commerçant ci-dessous, voir PaiementEtablissementController.
        Route::get('/etablissements/{etablissement}/paiement', [PaiementEtablissementController::class, 'show']);
        Route::put('/etablissements/{etablissement}/paiement', [PaiementEtablissementController::class, 'update']);
        Route::delete('/etablissements/{etablissement}/paiement', [PaiementEtablissementController::class, 'destroy']);

        // Étape 13 — Mobile Money (chemin super-admin), voir
        // MobileMoneyEtablissementController.
        Route::get('/etablissements/{etablissement}/mobile-money', [MobileMoneyEtablissementController::class, 'show']);
        Route::put('/etablissements/{etablissement}/mobile-money', [MobileMoneyEtablissementController::class, 'update']);
        Route::delete('/etablissements/{etablissement}/mobile-money', [MobileMoneyEtablissementController::class, 'destroy']);

        // Étape 12 — abonnement (chemin super-admin) : réglages et
        // enregistrement d'un paiement reçu, voir AbonnementController.
        Route::get('/etablissements/{etablissement}/abonnement', [AbonnementController::class, 'show']);
        Route::patch('/etablissements/{etablissement}/abonnement', [AbonnementController::class, 'update']);
        Route::post('/etablissements/{etablissement}/abonnement/paiements', [AbonnementController::class, 'enregistrerPaiement']);
    });

    Route::middleware('resoudre.etablissement')->group(function () {
        Route::get('/produits', [ProduitController::class, 'index']);
        Route::post('/produits', [ProduitController::class, 'store']);
        Route::get('/produits/{produit}', [ProduitController::class, 'show']);
        Route::put('/produits/{produit}', [ProduitController::class, 'update']);
        Route::patch('/produits/{produit}/stock', [ProduitController::class, 'ajusterStock']);
        Route::delete('/produits/{produit}', [ProduitController::class, 'destroy']);

        Route::put('/produits/{produit}/medias/ordre', [MediaProduitController::class, 'reordonner']);
        Route::post('/produits/{produit}/medias', [MediaProduitController::class, 'store']);
        Route::delete('/produits/{produit}/medias/{media}', [MediaProduitController::class, 'destroy']);

        Route::get('/categories', [CategorieController::class, 'index']);
        Route::post('/categories', [CategorieController::class, 'store']);
        Route::put('/categories/{categorie}', [CategorieController::class, 'update']);
        Route::delete('/categories/{categorie}', [CategorieController::class, 'destroy']);

        // Étape 9 — "statistiques" avant "{commande}" : sinon ce dernier
        // capturerait le chemin littéral comme un identifiant de commande
        // (même précaution que "sous-domaine-disponible" plus haut).
        Route::get('/commandes', [CommandeController::class, 'index']);
        Route::get('/commandes/statistiques', [CommandeController::class, 'statistiques']);
        Route::get('/commandes/{commande}', [CommandeController::class, 'show']);
        Route::patch('/commandes/{commande}/confirmer', [CommandeController::class, 'confirmer']);
        Route::patch('/commandes/{commande}/marquer-prete', [CommandeController::class, 'marquerPrete']);
        Route::patch('/commandes/{commande}/marquer-livree', [CommandeController::class, 'marquerLivree']);
        // Étape 13 — confirmation d'un paiement reçu : ne change PAS le
        // statut de la commande (voir ConfirmerPaiementCommande).
        Route::post('/commandes/{commande}/confirmer-paiement', [CommandeController::class, 'confirmerPaiement']);
        Route::post('/commandes/{commande}/rembourser', [CommandeController::class, 'rembourser']);
        Route::post('/commandes/{commande}/annuler', [CommandeController::class, 'annuler']);

        Route::get('/parametres', [ParametresController::class, 'index']);

        // Étape 6A ter — identité (chemin commerçant, son propre
        // établissement, résolu par le sous-domaine) : pas de paramètre de
        // route, voir IdentiteEtablissementController::resoudreCible().
        Route::get('/parametres/etablissement', [IdentiteEtablissementController::class, 'show']);
        Route::patch('/parametres/etablissement', [IdentiteEtablissementController::class, 'update']);
        Route::post('/parametres/etablissement/logo', [IdentiteEtablissementController::class, 'uploaderLogo']);
        Route::delete('/parametres/etablissement/logo', [IdentiteEtablissementController::class, 'supprimerLogo']);

        // Étape 6C-1 — paiement (chemin commerçant, son propre
        // établissement) : lecture ET écriture exigent "gerer_parametres"
        // (voir PaiementEtablissementController::resoudreCible()).
        Route::get('/parametres/paiement', [PaiementEtablissementController::class, 'show']);
        Route::put('/parametres/paiement', [PaiementEtablissementController::class, 'update']);
        Route::delete('/parametres/paiement', [PaiementEtablissementController::class, 'destroy']);

        // Étape 13 — Mobile Money (chemin commerçant), "gerer_parametres".
        Route::get('/parametres/mobile-money', [MobileMoneyEtablissementController::class, 'show']);
        Route::put('/parametres/mobile-money', [MobileMoneyEtablissementController::class, 'update']);
        Route::delete('/parametres/mobile-money', [MobileMoneyEtablissementController::class, 'destroy']);

        Route::get('/statistiques', [StatistiquesController::class, 'index']);

        // Étape 12 — abonnement (chemin commerçant, son propre
        // établissement) : lecture seule, "gerer_parametres" exigé. Hors de
        // "abonnement.vitrine" : un commerçant suspendu doit toujours y
        // accéder pour régulariser.
        Route::get('/abonnement', [AbonnementController::class, 'show']);

        // Ce que l'équipe de la plateforme a consulté de ses données
        // commerciales (voir JournaliserConsultationPlateforme).
        Route::get('/parametres/consultations-plateforme', [ConsultationPlateformeController::class, 'index']);

        // Étape 10 — équipe : réservée à gerer_equipe (voir
        // EtablissementUtilisateurPolicy), en pratique le seul
        // admin_etablissement. "roles" avant "{membre}" : même précaution
        // que "statistiques" plus haut, pour ne pas être capturé comme un
        // identifiant de membre.
        Route::get('/equipe/roles', [MembreEquipeController::class, 'roles']);
        Route::get('/equipe', [MembreEquipeController::class, 'index']);
        Route::post('/equipe', [MembreEquipeController::class, 'store']);
        Route::put('/equipe/{membre}', [MembreEquipeController::class, 'update']);
        Route::patch('/equipe/{membre}/statut', [MembreEquipeController::class, 'changerStatut']);
        Route::post('/equipe/{membre}/code-activation', [MembreEquipeController::class, 'genererCodeActivation']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
