# Ce que l'application sait faire aujourd'hui

Mis à jour à la fin de chaque lot. Ce document dit le quoi ; le pourquoi
des choix est dans `decisions.md`.

Dernière mise à jour : 28 octobre 2026, lot D (demandes WhatsApp).

## Ce qui existe et fonctionne

**La vitrine du client**
- Chaque commerce a sa boutique en ligne à sa propre adresse, à son nom et avec son logo ; le nom de la plateforme n'y apparaît jamais.
- La page d'accueil montre les produits en vente, avec une recherche par nom et un filtre par catégorie.
- La fiche d'un produit montre ses photos, sa description, son prix et sa disponibilité.
- Pour un produit à variantes, le client choisit dimension par dimension : une ligne « Pointure », une ligne « Coloris », dans l'ordre fixé par le commerçant.
- Une combinaison qui n'existe pas est grisée sans être cachée, et une phrase l'explique (« 39, 45 : n'existent pas en Marron »). Une combinaison épuisée reste visible et le dit.
- La photo principale devient celle du coloris choisi, et une petite vignette de ce coloris s'affiche à côté de « Coloris : Gris chiné », pour que le client la voie changer même quand la grande photo est hors de l'écran.
- Le prix s'affiche « À partir de » tant que le choix n'est pas complet, puis devient le prix exact.
- Le prix barré saisi par le commerçant s'affiche à côté d'un prix exact, sur la carte et sur la fiche, seulement s'il est plus élevé ; jamais à côté de « À partir de ».
- Un produit sans variante s'affiche simplement, sans sélecteur.
- Le panier est gardé sur le téléphone du client. Avant la commande, les prix et le stock sont revérifiés, et un article devenu indisponible est signalé.
- Le client commande avec son nom, son téléphone, sa commune et son quartier, puis reçoit une page de confirmation avec le numéro de sa commande.
- Livraison : si la boutique la facture, le client choisit sa commune dans la liste et ses frais s'ajoutent au total (« Offerte » quand ils sont à 0). Une commune absente de la liste se choisit par « Autre commune » : saisie libre, et le client paie les frais fixes que le commerçant a pu fixer pour toute autre commune (sinon livraison à convenir). Quand rien n'est facturé, le client lit « Livraison : à convenir avec la boutique », jamais « 0 FCFA », et une phrase sous le total dit qu'il n'inclut pas la livraison.
- « Commander sur WhatsApp » ouvre WhatsApp avec un message pré-rempli. Le serveur relit la référence du message, jamais le prix tapé dedans.
- Paiement par Mobile Money : le client reçoit le numéro à payer, et le commerçant confirme lui-même l'argent reçu.
- Un lien de boutique partagé sur WhatsApp ou Facebook s'affiche avec le nom, la description et une image.
- La vitrine d'un commerce dont l'abonnement est impayé est coupée, mais son back-office reste ouvert.

**Le back-office du commerçant**
- Connexion sécurisée. Seules les erreurs de mot de passe comptent pour un blocage, jamais les connexions réussies. Une session perdue côté serveur ne bloque plus la connexion suivante.
- Un employé active son compte avec un code donné par l'administrateur.
- Produits : création, modification, brouillon ou publié, archivage (jamais d'effacement).
- Photos : jusqu'à 15 par produit, avec un ordre réglable ; la première est la photo principale. Au téléphone, le bouton propose la galerie ou l'appareil photo.
- Une photo prise au téléphone (12 mégapixels, plusieurs Mo) est réduite sur le téléphone avant l'envoi, autour de 150 à 400 Ko, pour épargner la data mobile ; le logo aussi. Une photo trop grande pour le serveur (au-delà de 16 mégapixels, depuis un ordinateur) est refusée avec un message qui dit quoi faire.
- Variantes : dimensions et valeurs dans un ordre réglable, grille des combinaisons au doigt, plage de pointures en un geste (de 39 à 45), prix et stock propres à chaque variante, photo par coloris.
- « blanc, noir » tapé dans le champ d'une option crée deux valeurs. Le bandeau dit « Pas encore enregistré » tant qu'un texte tapé ou des cases cochées ne le sont pas ; le bouton « Enregistrer les modifications » cesse alors d'être vert, dit qu'il n'enregistre pas les variantes, et ne quitte pas la page. Des options sans aucune variante créée sont signalées.
- Stock compté à l'unité, ou simple interrupteur « Disponible » ; le mode se change tant qu'aucune commande en cours n'en dépend.
- Une commande réserve son stock. Une commande de la vitrine non traitée expire au bout de 24 heures (15 minutes pour un restaurant), et son stock est alors rendu.
- Catégories sur deux niveaux (Chaussures › Sandales).
- Commandes : liste filtrée par statut et détail. On les fait passer de confirmée à prête puis livrée, ou on les annule (le stock revient).
- Saisie manuelle d'une commande : bouton « Nouvelle commande » sur la page Commandes, et « Vendre » sur la liste des produits et sur la fiche d'un produit, qui ouvre la saisie avec ce produit déjà choisi. Le premier choix est le canal : Comptoir, WhatsApp ou Téléphone.
- Au comptoir : des articles seulement, sans client ni livraison. « Encaisser » termine la vente d'un coup : livrée, payée en espèces, stock décompté. Trois touchers depuis « Vendre ». La liste l'affiche « Client de passage » ; un habitué peut être identifié par un lien discret.
- Au téléphone et sur WhatsApp : seuls le numéro et la commune sont obligatoires ; le nom et le quartier sont facultatifs (la commande affiche alors « Sans nom »). Un client connu préremplit son nom, sa commune et son quartier, et un nom laissé vide n'efface pas le sien. Mêmes communes et mêmes frais que la vitrine, « Autre commune » et ses frais fixes compris. La commande est confirmée, non payée, et n'expire jamais.
- Recherche dans tout le catalogue de la boutique (brouillons compris, archivés exclus) avec variante, prix et stock disponible ; on ne dépasse pas le stock, le total suit en direct. Une référence WhatsApp (ou le message entier) se colle derrière un lien : l'article de la demande s'ajoute tout seul, et une demande déjà devenue commande est refusée tout de suite, avec le lien. Un bouton grisé dit pourquoi (« Ajoutez au moins un article »).
- Les prix viennent toujours de la base, sans remise. Un stock insuffisant est expliqué au back-office (article, demandé, disponible). Un doublon possible (même numéro avec une commande en cours de moins de 24 heures) avertit, avec le lien, sans bloquer.
- Demandes WhatsApp : chaque clic sur « Commander sur WhatsApp » de la vitrine apparaît dans la page « Demandes WhatsApp », avec le produit, la variante et sa photo, l'heure du clic (« il y a 12 min », la date exacte au toucher), la référence à copier et son statut. Rien sur le client : au clic, on ne sait rien de lui. Une référence de plus de 7 jours est signalée « expirée ». Le menu affiche le nombre de demandes nouvelles.
- Une demande se marque traitée ou ignorée, ou se transforme en commande : la saisie manuelle s'ouvre déjà remplie (canal WhatsApp, produit, variante, référence), le commerçant saisit le client, et la demande passe traitée avec le lien vers sa commande. Une demande ne devient qu'une seule commande. Une demande ne réserve rien et ne compte dans aucun chiffre.
- Chaque commande saisie garde qui l'a créée, par quel canal et quand ; chaque paiement garde qui l'a reçu. Saisir une commande demande la permission « Saisir des commandes » : administrateur, opérateur et caissier l'ont, le gestionnaire de stock non.
- Paiements : paiement partiel enregistré tel quel, remboursement constaté à la main.
- Tableau de bord avec les chiffres des commandes.
- Équipe : ajout de membres avec un rôle, désactivation, nouveau code d'activation.
- Identité du commerce : nom, logo, description, coordonnées, horaires (affichés, sans bloquer les commandes).
- Livraison : le commerçant choisit de facturer la livraison selon la commune ou de ne pas la facturer sur le site, et tient la liste de ses communes avec leurs frais (0 pour offerte). Il peut fixer un montant pour « toute autre commune » : toute commune hors de sa liste le paie, à la vitrine comme en saisie manuelle ; sans montant, elle reste à convenir. Changer des frais ne touche aucune commande passée.
- Mon compte : profil et mot de passe.
- Le commerçant voit chaque consultation de ses données commerciales par l'équipe de la plateforme.

**L'espace de la plateforme (super-admin)**
- Création d'un commerce avec son adresse, suspension, réactivation, suppression.
- Correction du type (boutique ou restaurant) tant que le commerce n'a aucun produit.
- Dépannage de l'identité, du paiement et du Mobile Money d'un commerce.
- Abonnement : réglages, et enregistrement d'un paiement reçu.

## Ce qui existe à moitié

Plus rien de bloquant pour la mise en ligne parmi ce qui existe : les photos de téléphone et les zones de livraison sont corrigées.

- **Photos envoyées depuis un ordinateur** : elles ne passent pas par la réduction du navigateur ; sans l'extension exif sur le serveur (voir la liste de mise en ligne), un portrait peut apparaître couché.
- **CinetPay** : un commerce peut enregistrer ses identifiants, gardés chiffrés, mais aucun paiement CinetPay n'est encore possible. Le bouton « Payer maintenant » reste donc caché.
- **Restaurants** : ils fonctionnent comme une boutique. Une commande de restaurant expire 15 minutes après sa création, souvent avant d'avoir été vue ; ce bug est connu et reporté avec le lot restauration.
- **Produits à trois dimensions** (taille, coloris, longueur) : enregistrables, mais ni la grille du back-office ni le sélecteur de la vitrine n'ont été vérifiés sur téléphone dans ce cas.

## Ce qui n'existe pas encore

1. **Prochain : ce qui a été décidé pour après le lot D, rien de codé encore :**
   - **Rôles cumulables** : une personne peut avoir plusieurs rôles, et ses droits s'additionnent. Une phrase en direct explique ce qu'elle pourra faire, avec une case « voit les chiffres ». Au moins un rôle par personne, et toujours un administrateur actif.
   - **Tableau de bord** : vendu, encaissé, reste à encaisser. Le caissier voit sa propre caisse du jour ; seuls l'administrateur, ou la personne qui a la case « voit les chiffres », voient les chiffres de la boutique.
   - **Paiements** : paiement à la livraison facultatif, frais de livraison payés d'avance facultatifs, plusieurs numéros Mobile Money. Le rapprochement se fait par le numéro de l'expéditeur, avec une case « je paierai depuis un autre numéro », jamais par capture d'écran.
   - **Équipe** : l'écran d'activation n'affiche qu'un chemin incomplet, « /admin/activation ». Il doit afficher le lien complet et un bouton pour l'envoyer par WhatsApp.
2. **Lot E** : les tests de non-régression de la vitrine.
3. **Mise en ligne** : serveur, domaine couahi.ci, DNS et certificat valable pour toutes les adresses des boutiques.

Reporté : tout le lot restauration, c'est-à-dire les suppléments payants sur un plat, les commandes bloquées hors horaires, des statuts propres au restaurant, la remise à « disponible » des plats chaque matin et une expiration adaptée.

Bloqué hors de nous : le paiement CinetPay, qui attend le RCCM, et l'IA WhatsApp, qui attend le compte Meta.

## À faire pour la mise en ligne

Ce qui doit être réglé sur le serveur ou autour, en plus du code. On y ajoute au fil des lots.

- Installer l'extension PHP exif : sans elle, une photo envoyée depuis un ordinateur peut apparaître couchée.
