# Ce que l'application sait faire aujourd'hui

Mis à jour à la fin de chaque lot. Ce document dit le quoi ; le pourquoi
des choix est dans `decisions.md`.

Dernière mise à jour : 25 octobre 2026, fin du lot B3.

## Ce qui existe et fonctionne

**La vitrine du client**
- Chaque commerce a sa boutique en ligne à sa propre adresse, à son nom et avec son logo ; le nom de la plateforme n'y apparaît jamais.
- La page d'accueil montre les produits en vente, avec une recherche par nom et un filtre par catégorie.
- La fiche d'un produit montre ses photos, sa description, son prix et sa disponibilité.
- Pour un produit à variantes, le client choisit dimension par dimension : une ligne « Pointure », une ligne « Coloris », dans l'ordre fixé par le commerçant.
- Une combinaison qui n'existe pas est grisée sans être cachée, et une phrase l'explique (« 39, 45 : n'existent pas en Marron »). Une combinaison épuisée reste visible et le dit.
- La photo principale devient celle du coloris choisi, et une petite vignette de ce coloris s'affiche à côté de « Coloris : Gris chiné », pour que le client la voie changer même quand la grande photo est hors de l'écran.
- Le prix s'affiche « À partir de » tant que le choix n'est pas complet, puis devient le prix exact.
- Un produit sans variante s'affiche simplement, sans sélecteur.
- Le panier est gardé sur le téléphone du client. Avant la commande, les prix et le stock sont revérifiés, et un article devenu indisponible est signalé.
- Le client commande avec son nom, son téléphone, sa commune et son quartier, puis reçoit une page de confirmation avec le numéro de sa commande.
- « Commander sur WhatsApp » ouvre WhatsApp avec un message pré-rempli. Le serveur relit la référence du message, jamais le prix tapé dedans.
- Paiement par Mobile Money : le client reçoit le numéro à payer, et le commerçant confirme lui-même l'argent reçu.
- Un lien de boutique partagé sur WhatsApp ou Facebook s'affiche avec le nom, la description et une image.
- La vitrine d'un commerce dont l'abonnement est impayé est coupée, mais son back-office reste ouvert.

**Le back-office du commerçant**
- Connexion sécurisée. Seules les erreurs de mot de passe comptent pour un blocage, jamais les connexions réussies.
- Un employé active son compte avec un code donné par l'administrateur.
- Produits : création, modification, brouillon ou publié, archivage (jamais d'effacement).
- Photos : jusqu'à 15 par produit, avec un ordre réglable ; la première est la photo principale.
- Variantes : dimensions et valeurs dans un ordre réglable, grille des combinaisons au doigt, plage de pointures en un geste (de 39 à 45), prix et stock propres à chaque variante, photo par coloris.
- Stock compté à l'unité, ou simple interrupteur « Disponible » ; le mode se change tant qu'aucune commande en cours n'en dépend.
- Une commande réserve son stock. Une commande non payée expire au bout de 15 minutes, ou de 24 heures en Mobile Money, et son stock est alors rendu.
- Catégories sur deux niveaux (Chaussures › Sandales).
- Commandes : liste filtrée par statut et détail. On les fait passer de confirmée à prête puis livrée, ou on les annule (le stock revient).
- Paiements : paiement partiel enregistré tel quel, remboursement constaté à la main.
- Tableau de bord avec les chiffres des commandes.
- Équipe : ajout de membres avec un rôle, désactivation, nouveau code d'activation.
- Identité du commerce : nom, logo, description, coordonnées, horaires (affichés, sans bloquer les commandes).
- Mon compte : profil et mot de passe.
- Le commerçant voit chaque consultation de ses données commerciales par l'équipe de la plateforme.

**L'espace de la plateforme (super-admin)**
- Création d'un commerce avec son adresse, suspension, réactivation, suppression.
- Correction du type (boutique ou restaurant) tant que le commerce n'a aucun produit.
- Dépannage de l'identité, du paiement et du Mobile Money d'un commerce.
- Abonnement : réglages, et enregistrement d'un paiement reçu.

## Ce qui existe à moitié

**Bloquant pour la mise en ligne, tant que ce n'est pas corrigé :**
- **Photos prises au téléphone** : une photo de 12 mégapixels, le format courant d'un téléphone, fait échouer l'envoi. Seules passent les photos plus petites (environ 2 mégapixels).
- **Zones de livraison** : la vitrine sait proposer une liste de communes avec des frais, mais aucun écran ne permet de créer ces zones. Seules les boutiques de démonstration en ont. Reste à vérifier dans un vrai navigateur ce que voit le client d'une boutique sans zone.

**Le reste :**
- **CinetPay** : un commerce peut enregistrer ses identifiants, gardés chiffrés, mais aucun paiement CinetPay n'est encore possible. Le bouton « Payer maintenant » reste donc caché.
- **Demandes WhatsApp** : chaque clic sur « Commander sur WhatsApp » laisse une trace que le serveur sait relire, mais aucun écran ne les montre encore au commerçant (lot D).
- **Restaurants** : ils fonctionnent comme une boutique. Une commande de restaurant expire 15 minutes après sa création, souvent avant d'avoir été vue ; ce bug est connu et reporté avec le lot restauration.
- **Produits à trois dimensions** (taille, coloris, longueur) : enregistrables, mais ni la grille du back-office ni le sélecteur de la vitrine n'ont été vérifiés sur téléphone dans ce cas.

## Ce qui n'existe pas encore

1. **Prochain : les photos de 12 mégapixels**, corrigées côté serveur (garantie) puis côté téléphone (réduction avant l'envoi, pour la data mobile).
2. **L'écran des zones de livraison** : le commerçant crée ses zones et leurs frais, ou choisit de ne facturer aucune livraison. Avant le lot C, qui utilise les mêmes communes et les mêmes frais.
3. **Lot C : saisie manuelle des commandes au back-office**, pour une commande reçue par téléphone ou en boutique.
4. **Lot D** : la liste des demandes WhatsApp, pour que le commerçant voie qui a demandé quoi.
5. **Lot E** : les tests de non-régression de la vitrine.
6. **Mise en ligne** : serveur, domaine couahi.ci, DNS et certificat valable pour toutes les adresses des boutiques.

Reporté : tout le lot restauration, c'est-à-dire les suppléments payants sur un plat, les commandes bloquées hors horaires, des statuts propres au restaurant, la remise à « disponible » des plats chaque matin et une expiration adaptée.

Bloqué hors de nous : le paiement CinetPay, qui attend le RCCM, et l'IA WhatsApp, qui attend le compte Meta.
