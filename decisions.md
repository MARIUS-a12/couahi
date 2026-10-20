# Journal des décisions

Décisions d'architecture non évidentes : la date, le choix, et surtout
pourquoi — avec l'alternative écartée et la raison de l'écarter. Jamais le
détail d'implémentation, qui vit dans le code et ses docblocks.

## 2026-10-03 — Un compte appartient à un seul établissement

Un utilisateur ne peut avoir qu'une seule appartenance (`etablissement_id`
unique sur `users`, contrainte d'unicité sur `etablissement_utilisateurs.
utilisateur_id`). Alternative écartée : autoriser un compte à appartenir à
plusieurs établissements (un utilisateur, plusieurs rattachements). Écartée
parce que ça aurait exigé un sélecteur d'établissement courant à chaque
connexion et un contrôle d'accès bien plus complexe, pour un besoin
métier qui ne se présente pas ici — un commerçant ou un employé ne
travaille jamais pour deux boutiques à la fois.

## 2026-10-03 — Chaque établissement a son propre compte CinetPay

La plateforme ne détient jamais les identifiants de paiement d'un
établissement dans un compte CinetPay partagé ; chaque établissement
configure le sien. Alternative écartée : un compte CinetPay unique pour
la plateforme, qui répartirait ensuite les fonds vers chaque commerçant.
Écartée parce que ça aurait fait de la plateforme un intermédiaire
financier sur l'argent d'autrui — avec tout ce que ça implique en
responsabilité et en complexité de réconciliation — alors qu'elle n'a
besoin que de relayer une configuration, jamais de manipuler les fonds.

## 2026-10-03 — La vérification des mots de passe compromis échoue en laissant passer

Si pwnedpasswords.com est inaccessible, lent, ou renvoie une erreur, le
mot de passe est accepté plutôt que rejeté (fail-open), après un délai
maximal de 2 secondes. Alternative écartée : fail-closed, qui bloquerait
la création ou la modification d'un compte si le service tiers est en
panne. Écartée parce qu'une dépendance externe non critique ne doit
jamais pouvoir empêcher un commerçant de travailler — la vérification
est une protection en plus, pas une condition d'accès au service.

## 2026-10-03 — Les produits sont archivés, jamais supprimés

Un produit retiré de la vente passe au statut archivé ; aucune route ne
permet sa suppression définitive. Alternative écartée : une suppression
réelle (`DELETE`) de la ligne en base. Écartée parce que des commandes
passées référencent ce produit — le supprimer casserait l'historique des
ventes et empêcherait de comprendre une commande ancienne, en plus de
rendre impossible une réactivation ultérieure.

## 2026-10-03 — Les identifiants de paiement sont chiffrés et le secret n'est jamais relu

La configuration CinetPay d'un établissement (clé API, secret) est
chiffrée en base ; une fois enregistré, le secret ne peut plus jamais être
affiché, seulement remplacé. Alternative écartée : permettre à l'admin de
revoir le secret déjà saisi (pratique pour vérifier qu'on a bien
enregistré la bonne valeur). Écartée parce qu'un secret qu'on peut relire
est un secret qu'une session compromise ou un accès non autorisé à
l'interface peut exfiltrer — la seule opération sûre sur un secret existant
est de le remplacer, jamais de le consulter

Lenteur des requêtes en développement (octobre 2026)
Décision : ne rien corriger.
Constat : 88 à 271 ms pour chaque nouvelle connexion PostgreSQL, 1 à 13 ms pour la requête elle-même. Mesuré en PDO brut, hors Laravel.
Cause : PostgreSQL sous Windows n'a pas de fork() et crée un processus système complet à chaque connexion. Coût connu, nettement plus élevé que sous Linux.
Pistes écartées : DB_HOST valait déjà 127.0.0.1, aucune résolution DNS en jeu ; désactiver SSL ne gagne que 15 à 20 %.
Pourquoi on ne corrige pas : la production tournera sous Linux, où ce coût disparaît. Un pool de connexions serait du travail pour un problème propre à la machine de développement.
À réexaminer seulement si la lenteur apparaît sur le serveur de production.

## 2026-10-19 — Un abonnement impayé coupe la vitrine, jamais le back-office

Passé le délai de grâce, seule la vitrine renvoie 404 ; le commerçant
peut toujours se connecter, consulter ses données et payer. Alternative
écartée : réutiliser le statut "inactif" de l'établissement (la suspension
décidée par le super-admin). Écartée parce que ce statut bloque aussi la
connexion : le commerçant qui veut régulariser serait enfermé dehors,
exactement celui qu'on veut voir revenir. La suspension n'efface rien,
elle masque : le paiement remet tout en ligne immédiatement.

## 2026-10-19 — Le statut d'abonnement se recalcule depuis les dates

La tâche quotidienne ne fait jamais "avancer d'un cran" un abonnement :
elle recalcule son statut à partir de l'échéance et des jours de grâce,
et ne touche jamais à l'échéance. Alternative écartée : une machine à
états incrémentale (essai → en retard → suspendu à chaque passage).
Écartée parce qu'une double exécution, un rattrapage après une panne du
planificateur ou un réglage modifié entre-temps produiraient un état
faux ; recalculer depuis les dates est idempotent par construction. Seul
un paiement enregistré déplace l'échéance.

## 2026-10-19 — Les identifiants CinetPay de la plateforme vivent dans l'environnement

Le compte CinetPay qui encaisse les abonnements est configuré dans
l'environnement du serveur, jamais en base. Alternative écartée : le
ranger en base à côté des identifiants CinetPay des établissements.
Écartée parce que ce sont deux argents différents (les ventes vont au
commerçant, les abonnements à l'exploitant de la plateforme) : les avoir
au même endroit invite à les confondre, et un secret unique de la
plateforme n'a aucune raison d'être modifiable depuis une interface web.

## 2026-10-19 — L'abonnement naît avec l'établissement, sur l'événement du modèle

L'essai de 30 jours est ouvert par l'événement "created" du modèle
Etablissement, pas dans le service de création du super-admin.
Alternative écartée : le créer dans CreerEtablissement. Écartée parce
que les seeders, les factories de test et tout futur chemin de création
produiraient alors un établissement sans abonnement — une vitrine que
rien ne coupe jamais et un trou dans la facturation.

## 2026-10-20 — Le Mobile Money est confirmé à la main, et le paiement a sa propre machine d'état

Un client qui paie par transfert Mobile Money ne déclenche rien
automatiquement : c'est le commerçant qui constate l'argent reçu et le
confirme. Alternative écartée : une intégration (CinetPay, Wave Business)
qui confirmerait seule. Écartée parce que ces services exigent un RCCM
que la plupart des commerçants visés n'ont pas — sans cette voie, leurs
clients n'auraient aucun moyen de payer depuis le site.
Cette confirmation ne change jamais le statut de la commande.
Alternative écartée : faire passer la commande en « Confirmée » à la
réception du paiement. Écartée parce que ce sont deux faits distincts :
une commande peut être acceptée avant d'être payée (paiement à la
livraison) ou payée avant d'être acceptée — les fusionner ferait mentir
l'un des deux. Le montant reçu est enregistré tel quel, même s'il diffère
du total : un client qui arrondit est un cas courant, pas une erreur.

## 2026-10-20 — Un seul moyen de paiement proposé au client

Quand CinetPay et le Mobile Money sont tous deux configurés, seul
CinetPay est proposé. Alternative écartée : laisser le client choisir.
Écartée parce qu'un choix de plus au moment de payer fait perdre des
clients ; le serveur refuse d'ailleurs un mode qui n'est pas celui
proposé, l'interface n'est pas la seule barrière.

## 2026-10-20 — Une commande Mobile Money réserve son stock 24 heures

Au lieu des 15 minutes d'une commande web ordinaire. Alternative écartée :
garder 15 minutes. Écartée parce qu'un transfert n'est constaté qu'à la
main : la commande expirerait, et son stock serait relâché, avant même
que le commerçant ait vu l'argent arriver.

## 2026-10-20 — La page de confirmation affiche le numéro Mobile Money actuel, pas celui du jour de la commande

Alternative écartée : figer une copie du numéro sur chaque commande.
Écartée parce qu'un commerçant qui change de numéro (perdu, volé, changé
d'opérateur) ne doit plus jamais recevoir d'argent sur l'ancien ; si le
Mobile Money est retiré, la page dit de ne rien envoyer et de contacter
le commerçant.

## 2026-10-20 — Les tâches planifiées s'exécutent dans le processus du planificateur

L'expiration des commandes (chaque minute) et l'actualisation des
abonnements (chaque nuit) tournent directement dans le processus de
schedule:run, sans file d'attente ni sous-processus. Alternative écartée :
une file d'attente (driver database) avec un worker permanent. Écartée
parce qu'un worker est un second processus à surveiller, en développement
comme en production, et qu'une tâche mal branchée s'y perd sans bruit —
c'est exactement ce qui est arrivé : la tâche d'expiration n'a jamais
tourné, sans une seule erreur visible, pendant trois semaines. Les
sous-processus de Schedule::command() sont écartés pour la même raison :
leur sortie part dans NUL, un échec y est invisible.
À réexaminer — et remettre une file — dès que l'une de ces conditions
apparaît : un passage de la tâche d'expiration dépasse régulièrement
20 secondes (le volume de commandes correspondant n'a pas été mesuré :
c'est la durée du passage qu'il faut surveiller) ; une tâche doit appeler un service
externe lent (envoi d'e-mails ou de SMS, appel CinetPay) ; ou
l'application tourne sur plusieurs serveurs (ajouter alors onOneServer()
au minimum). Au-delà d'une minute par passage, les exécutions se
chevaucheraient.

## 2026-10-20 — Un moyen de paiement n'est proposé que s'il fonctionne réellement

Configuré n'est pas disponible : des identifiants CinetPay enregistrés ne
suffisent plus à afficher « Payer maintenant ». Il faut aussi que
l'intégration soit active sur la plateforme (CINETPAY_ACTIF, faux par
défaut tant qu'elle n'est pas construite) ; sinon c'est le Mobile Money qui
est proposé, ou rien. Alternative écartée : garder « Payer maintenant »
avec un panneau « Paiement bientôt disponible ». Écartée parce que c'était
un cul-de-sac, et qu'il cachait le Mobile Money, qui lui fonctionne : un
bouton qui ne mène nulle part fait perdre la vente. Un indicateur
d'environnement plutôt qu'une détection automatique : le jour où
l'intégration existe, on le passe à vrai et rien d'autre ne change. Le
repli vers le Mobile Money en cas d'échec d'un appel CinetPay n'est pas
construit ; son emplacement est marqué dans VitrineCommandeController.
