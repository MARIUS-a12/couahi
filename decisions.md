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

## 2026-10-20 — L'aperçu d'un lien de vitrine est posé dans le HTML par le serveur

WhatsApp et Facebook ne lisent que le HTML renvoyé, jamais ce que React
affiche ensuite : le titre, la description et l'image d'aperçu (Open
Graph) sont donc injectés dans index.html avant l'envoi, par un greffon
du serveur Vite (vite/metadonneesPartage.ts), à partir d'une route de
l'API (GET /api/vitrine/partage) qui reste la seule source de vérité.
Alternative écartée : poser ces balises depuis React (document.title,
react-helmet). Écartée parce qu'aucun robot d'aperçu n'exécute le
JavaScript : l'aperçu montrerait toujours le titre par défaut.
Le greffon couvre vite dev et vite preview. En production, le serveur
qui renverra index.html devra faire la même injection (même route
d'API) ; APP_URL devra être l'adresse publique, sans quoi og:image
pointerait vers une adresse que WhatsApp ne peut pas joindre.

## 2026-10-20 — Les commandes sont numérotées par établissement, sur une ligne de compteur

Chaque établissement a sa ligne dans compteurs_numero_commande ; le
numéro s'obtient par un seul INSERT … ON CONFLICT DO UPDATE … RETURNING,
pris en DERNIER dans la transaction de CreerCommande (après les verrous
de stock), suivi seulement de quelques INSERT. La première commande d'un
nouveau commerçant est CMD-000001. Alternative écartée : garder la
séquence PostgreSQL globale commandes_numero_seq. Elle ne bloque jamais,
mais elle est commune à toute la plateforme — un nouveau commerçant
commençait à CMD-000017 et voyait l'activité des autres — et elle laisse
des trous (un numéro tiré par une commande qui échoue n'est jamais
rendu) ; elle survivait en plus à migrate:fresh. Une séquence par
établissement a aussi été écartée : un objet de schéma créé à chaque
nouvelle boutique, avec les mêmes trous.
Limite connue : toutes les commandes SIMULTANÉES d'une même boutique se
sérialisent sur sa ligne de compteur, de la prise du numéro au commit.
Mesuré en développement (Windows, PostgreSQL local, commande de 3
lignes, hors coût du commit) : verrou tenu 10 ms en médiane, 15 ms au
90e centile — soit un plafond théorique de l'ordre de 60 à 100 commandes
par seconde pour UNE boutique ; les boutiques entre elles ne s'attendent
jamais. Sans conséquence à l'échelle d'un maquis ou d'une boutique de
quartier. À réexaminer quand une seule boutique dépasse régulièrement une
dizaine de commandes par seconde en pointe (environ 600 à la minute), ou
dès que des commandes échouent sur lock_timeout. Pistes alors : réserver
le numéro hors de la transaction (au prix de trous), ou numéroter après
coup par lots. Règle qui découle de cette décision : rien de lent (appel
réseau, envoi de message, traitement d'image) ne doit s'exécuter dans la
transaction de CreerCommande après la prise du numéro.

## 2026-10-21 — Un paiement partiel n'est jamais « payée », et le remboursement se constate à la main

Un montant reçu inférieur au total donne un état à part, « Payée
partiellement », avec le reste dû, en orange et jamais dans le vert du
paiement complet. Tant que la commande n'est pas acceptée, elle est
signalée en priorité dans la liste, sans attendre 2 heures : le client a
versé de l'argent, la commande n'expire plus, une décision est due
(relancer, accepter quand même, ou annuler et rembourser). Alternative
écartée : traiter tout montant confirmé comme un paiement, l'écart étant
seulement noté. Écartée parce que c'est exactement ce qui faisait croire
au commerçant qu'il était réglé alors qu'il lui manquait un tiers du prix.
Le remboursement d'une commande annulée ou expirée après paiement est
enregistré par le commerçant (montant, référence facultative), comme la
confirmation du paiement : aucune intégration ne rend l'argent seule. Il
solde la mention « À rembourser » sans rien effacer ; l'historique et le
journal d'audit gardent qui, quand et combien. Alternative écartée :
laisser la mention jusqu'à ce que la commande sorte de la liste. Écartée
parce qu'une alerte qu'on ne peut jamais éteindre finit par ne plus être
lue.

## 2026-10-21 — Le commerçant voit ce que la plateforme a consulté de ses données commerciales

Chaque consultation, par le super-admin, des commandes, des clients ou du
chiffre d'affaires d'un établissement est notée (qui, quand, quelle page)
et montrée au commerçant dans son propre back-office. Ce qui a été vu
n'est jamais noté, pas même le numéro de la commande ouverte : le journal
dit qu'on a regardé, il ne recopie pas ce qu'on a regardé. Alternative
écartée : un journal interne, lisible par la plateforme seule. Écartée
parce qu'une surveillance que le surveillé ne voit pas ne protège que
celui qui surveille ; c'est la visibilité qui en fait de la transparence.
Les pages d'administration (fiche, identité, abonnement, configuration de
paiement) ne sont pas notées : elles ne contiennent pas l'activité du
commerçant, et les noter noierait les consultations qui comptent.
Si la trace ne peut pas être écrite, les données ne sont pas envoyées.
Une même page rechargée dans les 10 minutes n'ajoute pas de ligne, pour
qu'un écran qui se rafraîchit seul ne remplisse pas l'historique.

## 2026-10-22 — Dans un message WhatsApp, seule la référence fait foi

Le message pré-rempli par la vitrine contient le nom du produit, le prix
et une référence [REF:XXXXXXXX]. C'est un message WhatsApp ordinaire : le
client peut en réécrire chaque mot avant de l'envoyer, prix compris.
Règle absolue : seule la référence fait foi. Le produit, la variante, le
prix et la quantité sont résolus côté serveur à partir d'elle et de la
base, jamais lus dans le texte du message ; le prix est celui de la base
au moment de la résolution, pas celui affiché dans le message. Une
référence absente, inconnue, expirée ou ambiguë ne résout rien.
Alternative écartée : lire le prix et l'article dans le texte, ou
comparer le texte à la base et ne refuser qu'en cas d'écart. Écartée
parce que le texte est entièrement sous le contrôle du client : s'y fier,
même partiellement, revient à lui laisser fixer son prix.
Cela vaut dès aujourd'hui pour toute résolution de référence, et vaudra
pour l'IA WhatsApp : elle n'accepte jamais un montant, une quantité ou un
article énoncés dans la conversation sans les avoir vérifiés en base. Un
prix proposé par le client n'est qu'une demande de remise, soumise au
commerçant, jamais appliquée par l'IA.

## 2026-10-23 — La génération de lien WhatsApp est idempotente par session

Un même visiteur qui redemande un lien pour le même article dans les 10
minutes reçoit la même référence, sans nouvelle ligne. Le visiteur est
identifié par sa session (l'IP seulement à défaut), et seule l'empreinte
de son identifiant est enregistrée. Alternative écartée : une limite
stricte par IP (20 par heure). Écartée parce que derrière une IP
d'opérateur mobile se trouvent des centaines d'abonnés sans lien : la
limite bloquerait des clients légitimes sans empêcher un script de remplir
la table, qui alimente l'écran de travail du commerçant. Un plafond large
(60 par heure par IP et par établissement) reste en secours. Le cookie de
session reste propre à chaque sous-domaine : un cookie partagé entre
boutiques authentifierait l'administrateur de l'une sur le back-office
d'une autre, et la déduplication n'a besoin que de la boutique visitée.

## 2026-10-23 — Le cookie de session est propre à chaque sous-domaine (SESSION_DOMAIN=null)

Le cookie de session n'est posé que pour l'hôte exact de la requête :
chaque boutique (chez-awa.*, maquis-du-port.*) a le sien, et l'espace du
super-admin aussi. Alternative écartée : un domaine parent (.localhost en
développement, .couahi.ci en production), qui partagerait un même cookie
entre tous les sous-domaines. Écartée parce qu'un cookie partagé
authentifierait l'administrateur d'une boutique sur le back-office d'une
autre : une session ouverte sur chez-awa serait présentée telle quelle à
maquis-du-port. Ce n'est pas un bug à corriger, en production pas plus
qu'en développement : un problème de cookie entre sous-domaines se règle
autrement, jamais en élargissant SESSION_DOMAIN. Les fonctions qui ont
besoin d'identifier un visiteur (la déduplication des liens WhatsApp) le
font à l'intérieur d'une seule boutique, où ce cookie suffit.


## 2026-10-23 — Le mode de stock se change tant qu'aucune commande en cours n'en dépend

Le mode de stock d'un produit (quantité suivie, ou disponibilité manuelle)
naît du type de l'établissement, et le commerçant peut ensuite le changer
sur la fiche du produit : une boutique vend parfois du sur-mesure, un
restaurant des boissons en bouteille. Le changement est refusé tant
qu'une commande en cours contient le produit (en attente de paiement, ou
avec une réservation active sur lui ou ses variantes). La vraie raison du
verrou est la cohérence des compteurs. Dans un sens, une réservation
active a déjà augmenté quantite_reservee ; consommée ou libérée après la
bascule, elle toucherait des colonnes que le nouveau mode ne lit plus.
Dans l'autre, une commande en attente en disponibilité manuelle n'a rien
réservé ; confirmée après la bascule, elle ne décompterait rien du stock,
puisque la confirmation ne consomme que des réservations. La condition
porte donc sur les commandes en cours, pas sur les seules réservations,
qui ne couvrent que le premier sens.
Alternative écartée : le mode figé à la création (la règle d'origine,
jamais justifiée par écrit). Écartée parce qu'elle liait le stock d'un
produit au métier de la boutique, et qu'un type mal choisi condamnait
tout le catalogue. Une commande qui a lu l'ancien mode à l'instant même
de la bascule peut encore aboutir selon lui ; l'écart reste borné à
cette commande et se voit dans le stock, ce qui ne justifiait pas de
verrouiller chaque produit à chaque commande.

## 2026-10-23 — Différé au lot restauration, en connaissance de cause

Le lancement se fait avec la boutique. Les besoins propres au restaurant
sont reportés au lot restauration. Ils sont consignés ici pour qu'on ne
les redécouvre pas comme des oublis :
- Suppléments et options payantes sur un plat. Les variantes ne
  permettent qu'un seul choix exclusif par ligne.
- Statuts propres au restaurant (en préparation, servie). Le parcours est
  le même que la boutique : en attente, confirmée, prête, livrée.
- Commandes bloquées hors horaires. Les horaires ne servent qu'à
  l'affichage : une commande à 3 h du matin est acceptée.
- Sur place ou à emporter. La commune et le quartier sont exigés de tous.
- Remise à « disponible » chaque matin des plats épuisés. Rien ne le
  fait aujourd'hui ; c'est pourquoi l'interrupteur dit « Disponible » et
  non plus « Disponible aujourd'hui », qui promettait une remise à zéro
  inexistante.
- Plusieurs plages horaires par jour (midi et soir). Une seule est
  acceptée.
- Un délai d'expiration propre au restaurant.

Ce dernier point est un bug, pas une évolution souhaitable : une
commande de restaurant passée sur la vitrine expire 15 minutes après sa
création, comme une commande de boutique, souvent avant que le
commerçant ne l'ait vue. Il est différé avec le lot restauration, pas
oublié.
Résolu pour la boutique le 25 octobre (voir plus bas) ; le restaurant
garde ses 15 minutes jusqu'au lot restauration.

## 2026-10-23 — Le type d'un établissement se corrige tant qu'il n'a aucun produit

Le super-admin peut changer le type (boutique ou restaurant) d'un
établissement tant que celui-ci n'a aucun produit, archivés compris. Le
type ne décide que du mode de stock par défaut des produits créés
ensuite : sans produit, le corriger ne change rien d'existant.
Alternative écartée : le permettre à tout moment, en basculant le mode de
tous les produits. Écartée parce que ce serait un changement de mode en
masse, avec les mêmes risques sur les commandes en cours que le
changement d'un seul produit, multipliés par le catalogue. Une fois le
premier produit créé, le mode se change produit par produit.

## 2026-10-23 — Pas de table d'options sur les lignes de commande avant les suppléments

Les lignes de commande n'auront de table fille d'options (suppléments,
garnitures) qu'avec les suppléments eux-mêmes. Alternative écartée : la
créer dès maintenant, vide, par précaution. Écartée parce qu'elle
n'achète rien : le jour des suppléments, il faudra de toute façon sommer
les options dans le calcul du total, et une table vide ne l'évite pas.
Elle dériverait en attendant, sans code ni test pour la tenir à jour.

## 2026-10-25 — Une variante est une combinaison de valeurs, jamais un produit cartésien

Un produit a des options (Taille, Couleur, Pointure, Contenance), chaque
option des valeurs, et une variante est une combinaison d'une valeur par
option, avec son propre stock, son prix facultatif et son image. Une
variante n'existe que si le commerçant l'a créée : la sandale qui n'existe
qu'en noir au 39 a 12 variantes, pas les 14 de toutes les combinaisons.
Un produit sans option n'a aucune variante et porte son propre stock,
comme avant.
- Les libellés sont libres : aucune liste imposée. Le nom de l'option et
  l'ordre d'affichage restent structurés. L'ordre est un entier choisi par
  le commerçant, jamais un tri par libellé (« 39, 4, 40 », « L, M, S, XL »)
  ni une conversion en nombre (« 40.5 », « 7½ », « XL »).
- La signature d'une variante est la liste triée des identifiants de ses
  valeurs, jamais leurs libellés : renommer « M » en « Moyen » ne change
  ni la signature, ni l'unicité, ni les variantes. L'unicité ne porte que
  sur les variantes actives, pour qu'une variante archivée ne bloque pas à
  vie la recréation de sa combinaison.
- Ajouter une option à un produit qui a déjà des variantes exige de dire
  quelle valeur elles prennent (« vos M, L, XL sont de quelle couleur ? »),
  dans une seule transaction. Elles gardent leur identifiant, leur stock,
  leurs réservations et leurs commandes.
- Une valeur portée par une variante ne s'efface pas : elle s'archive, et
  ses variantes deviennent inactives, conservées avec leur stock. Une
  option ne se retire que si ses variantes actives n'en utilisent qu'une
  valeur ; sinon le refus nomme les variantes qui deviendraient
  identiques. Une variante qui a servi n'est jamais supprimée : les lignes
  de commande pointent vers elle et gardent le texte du jour de la vente.
- Une variante inactive ne se vend jamais, même désignée par une requête
  forgée : elle reste en base pour l'historique.
- L'image d'une variante est une des photos de son produit, pas un fichier
  à part : le commerçant dépose ses photos une fois, puis les rattache.
- Le nom d'une variante reste une colonne, libellé d'affichage dérivé de
  ses valeurs (« 39 / Noir »), recopié dans les lignes de commande.
Alternatives écartées : des variantes à nom libre, qui se rempliraient de
« M bleu », « Bleu - M », « m/bleu » et qu'il faudrait un jour reprendre
produit par produit en analysant du texte ; les dimensions en JSON sur la
variante (l'ancienne colonne attributs_json, jamais utilisée, supprimée),
que la base ne sait ni contraindre ni indexer ; la génération de toutes
les combinaisons, qui crée des variantes fantômes.

## 2026-10-25 — Les catégories ont deux niveaux, garantis par la base

Une catégorie peut avoir un parent (« Chaussures > Sandales »), qui est
toujours une racine du même établissement : jamais de troisième niveau.
La base le garantit et non le seul code. Un CHECK ne peut pas lire la
ligne parente : l'enfant recopie donc ce qu'il exige de son parent (être
une racine), et une clé étrangère composée le vérifie. Une catégorie qui
a des enfants ne peut, de ce fait, plus devenir elle-même enfant.
Alternative écartée : une profondeur libre. Écartée parce que deux niveaux
suffisent à une boutique, et qu'une arborescence profonde se navigue mal
sur un téléphone, où se trouvent la plupart des clients.

## 2026-10-25 — Un seul service compose les variantes, et une commande le vérifie

« Chaque variante porte une valeur pour chaque option de son produit » ne
s'exprime pas en contrainte SQL. Cette garantie repose donc sur deux
choses. D'abord, un seul code compose une variante (options, valeurs,
liens, signature, nom d'affichage) : GererVariantes. Aucun contrôleur,
aucune commande artisan, aucun seeder n'écrit ces lignes directement, et
un test parcourt le code pour le vérifier. Ensuite, la commande
catalogue:verifier-variantes, en lecture seule, signale toute variante à
qui il manque une valeur, ou dont la signature ou le nom ne suit plus ses
valeurs. Elle se lance en production sans ouvrir la base.
Le nom d'affichage d'une variante (« 39 / Noir ») suit ses valeurs : ce
service le recalcule quand une valeur est renommée ou une option ajoutée.
Le libellé recopié sur une ligne de commande, lui, est gelé pour
toujours : c'est un document comptable. Une commande qui a vendu un
« 39 / Noir » le dit encore après que « Noir » a été renommé « Noir mat ».
Hors champ, assumé : les migrations, instantanés historiques figés, et
les factories de test, qui doivent pouvoir fabriquer des états invalides
pour prouver qu'ils sont refusés.

## 2026-10-25 — Le harnais PostgreSQL recopie une méthode de Laravel

RefreshDatabase ne migre qu'une fois par processus, toutes bases
confondues. Dans la suite complète, les tests SQLite passaient d'abord :
la base PostgreSQL de test n'était jamais remigrée, depuis la création du
harnais (23 septembre). PostgresTestCase recopie donc
RefreshDatabase::refreshTestDatabase() de Laravel 13.33.0 avec son propre
drapeau. Écart à la règle « ne pas explorer vendor/ » : la méthode y a été
lue pour en reprendre exactement le déroulé. Le risque est qu'une mise à
jour de Laravel renomme cette méthode et rende la surcharge inopérante
sans erreur ; RefreshDatabaseInchangeTest casse dans ce cas.
Alternative écartée : une base PostgreSQL migrée à part, par un script,
avant la suite. Écartée parce qu'elle redonne exactement le défaut
corrigé : une suite qui tourne sur un schéma qu'elle n'a pas construit.

## 2026-10-25 — Le seed par test est une optimisation connue, différée

Chaque test qui a besoin de la démo refait un seed complet : 250 appels
dans la suite, à environ 516 ms chacun. Toute donnée de démo ajoutée est
donc multipliée par 250. B1 l'a montré : les sandales, avec leurs
options, valeurs et photos, ont fait passer un seed de 193 à 307 requêtes
et la suite de 146 s à 210 s (mesuré dans une même série). C'est le vrai
levier le jour où la suite approchera le seuil de 8 minutes : seeder une
fois par processus, puis isoler chaque test dans une transaction, ou ne
seeder que l'établissement dont le test a besoin.
Volontairement différé : à 230 s, la suite est loin du seuil, et ce
changement touche la façon dont 250 tests préparent leurs données. Il
mérite son propre lot, mesuré avant et après, pas une retouche en
passant.

## 2026-10-25 — L'écran des variantes enregistre au fil de l'eau, la grille par lot

Sur l'écran des variantes, chaque champ s'enregistre seul (stock, prix,
libellé), comme les photos, sans passer par le bouton du produit. La
grille des combinaisons, elle, est un brouillon qu'on applique d'un coup,
avec un stock de départ commun. Cocher 12 cases ne crée pas 12 variantes
une à une avec 12 attentes réseau. Un brouillon non appliqué et un envoi
en cours empêchent de quitter la page, et un bandeau dit « Enregistrement… »
puis « Tout est enregistré. ». Sans ce bandeau, un champ affichait ce que
la commerçante avait tapé alors que l'envoi n'avait pas abouti : partie
trop tôt, elle retrouvait 0 sans comprendre. Les essais à la main l'ont
montré. La photo d'un groupe (les 7 sandales noires) part en une seule
requête. Le glisser-déposer suit les événements pointer, qui marchent au
doigt, et non le glisser-déposer HTML5, inopérant sur la plupart des
téléphones ; les flèches du clavier en sont l'équivalent.
Alternative écartée : tout enregistrer avec le bouton du produit.
Écartée parce qu'une variante doit exister côté serveur pour recevoir
une photo ou une réservation, et qu'un formulaire de 12 lignes perdu d'un
coup sur un téléphone qui se met en veille coûte plus cher qu'un champ.

## 2026-10-25 — La limite de connexion ne compte que les échecs

/connexion et /activation passaient par le middleware « throttle » de
Laravel, qui compte chaque requête, réussie ou non. Une commerçante qui
se connectait le matin sur son téléphone, à midi sur celui de son employé,
puis se trompait deux fois de mot de passe, était enfermée dehors une
heure. Le limiteur (LimiteurEmailEtIp, désormais un middleware) compte
les seuls échecs, 5 par heure et par email, 30 par IP. Une réussite remet
le compteur de l'email à zéro, et ne rend à l'IP que l'unité qu'elle avait
prise : un compte valide ne doit pas effacer les échecs que d'autres ont
accumulés depuis la même adresse. Le compteur est incrémenté avant le
traitement puis rendu en cas de réussite, pour qu'une rafale de requêtes
parallèles ne passe pas la vérification avant d'être comptée. Une fois la
limite atteinte, même le bon mot de passe est refusé jusqu'à la fin de la
fenêtre.
Alternative écartée : garder « throttle » et vider le compteur dans le
contrôleur après une réussite. Écartée parce que la règle tiendrait alors
en deux endroits, et que /activation l'aurait oubliée.

## 2026-10-25 — La grille des combinaisons : vérifiée jusqu'à 5 × 6, pas au-delà de deux options

Vérifiée à la main à 390 px, au doigt, avec un t-shirt en 5 tailles et
6 coloris : les 6 colonnes tiennent sans défilement horizontal, en cases
de 44 px, une fois les en-têtes resserrés (les mots ne se coupent qu'aux
espaces). Au-delà, ou sur un écran de 360 px, la grille défile
horizontalement, colonne des tailles fixée. Ce défilement a révélé un
défaut : un balayage vers la droite, grille déjà calée à gauche,
remontait au navigateur, qui le lisait comme « page précédente » et
emportait le brouillon. overscroll-behavior-x n'y a rien fait, ni sur la
grille ni sur la racine, quand le geste part de la grille. Le balayage
qui bute sur un bord est donc annulé en JavaScript (useBalayageRetenu),
et seulement celui-là : un balayage vertical fait toujours défiler la
page.
Un produit à trois options (taille, coloris, longueur…) n'est ni testé
ni garanti : la grille met alors le produit des deux premières en
lignes, et personne ne l'a regardé sur un téléphone. Aucun commerçant
n'en a besoin aujourd'hui ; c'est à reprendre avant d'en vendre un.
Question ouverte à cette date : un produit était limité à 5 photos, et un
t-shirt en 6 coloris ne pouvait pas avoir une photo par coloris. Tranchée
plus bas (15 photos).

## 2026-10-25 — Une suite de nombres s'ajoute d'un coup, en une requête

« De 39 à 45 » crée 39, 40… 45 dans l'ordre de saisie. Seulement des
nombres entiers, 50 au plus, et le lien n'est proposé qu'à une option
vide ou déjà toute numérique : « Taille unique » ou « Bleu ciel » n'ont
rien à voir avec une plage. Les valeurs partent en une seule requête,
tout ou rien, comme « Tout ajouter » parmi les suggestions (qui envoyait
jusque-là une requête par valeur et pouvait s'arrêter au milieu). Une
valeur déjà présente est sautée ; une valeur archivée refuse l'ensemble,
avec le message qui invite à la restaurer.

## 2026-10-25 — Après sa création, un produit s'ouvre sur sa fiche, avec la suite à faire

Créer un produit ramenait à la liste : pour ajouter photos et variantes,
il fallait le retrouver et le rouvrir, sans que rien ne le dise. La
création mène désormais sur la fiche du produit, avec une ligne qui
donne la suite dans l'ordre imposé par l'écran : les photos, puis les
variantes, puis la photo de chaque coloris (choisie parmi les photos).
L'écran n'est pas réorganisé. La modification, elle, ramène toujours à
la liste.

## 2026-10-25 — Un produit peut avoir 15 photos, pas 5

Le prêt-à-porter est le premier métier visé : un t-shirt en 6 coloris doit
pouvoir montrer une photo par coloris, et garder des vues de détail. Le
coût a été mesuré avant de décider. Une photo est stockée en 6 fichiers
(trois tailles, chacune en WebP et en JPEG ; l'original n'est pas gardé).
Sur de vraies photographies passées dans le vrai générateur, cela fait de
58 à 170 Ko par photo, soit 0,9 à 2,5 Mo pour un produit à 15 photos. Le
plafond théorique (une image de bruit pur, qu'aucun appareil ne produit)
est de 1,2 Mo par photo. Le client, lui, ne télécharge que la photo
affichée et les vignettes, chargées à la demande. Le JPEG pèse environ deux
tiers du total alors qu'il ne sert qu'aux navigateurs sans WebP : c'est là
qu'on gagnerait de la place, si un jour il le faut.
Alternative écartée : garder 5 photos, et laisser une seule photo servir
plusieurs coloris. Écartée parce qu'elle contredit l'écran des variantes
qu'on vient de construire, où chaque coloris choisit sa photo.

## 2026-10-25 — Le sélecteur de la vitrine n'envoie que l'identifiant de la variante

Le client choisit valeur par valeur (39, puis Noir), mais le panier, la
commande et le lien WhatsApp ne reçoivent que l'identifiant de la
variante désignée. Le serveur vérifie que cette variante appartient au
produit, qu'elle est active, et qu'un produit à variantes n'est jamais
acheté sans elle. La vérification du panier applique désormais la même
règle que la commande : avant, une ligne sans variante comptait au prix du
produit, pour un total que la commande refusait ensuite.
Alternative écartée : envoyer les valeurs choisies et laisser le serveur
retrouver la combinaison. Écartée parce qu'elle ouvre un second chemin
d'achat à protéger, alors que l'identifiant de variante est déjà celui que
toutes les routes savent refuser.

## 2026-10-25 — Une combinaison impossible est grisée, jamais cachée ; la photo suit sans réglage

Sur la fiche, le 39 reste visible quand le marron est choisi, grisé, avec
une phrase qui le dit : le client doit savoir que la pointure existe, pas
dans ce coloris. Une combinaison épuisée, elle, reste choisissable : on en
voit le prix, et les boutons d'achat disent qu'elle est épuisée.
Alternative écartée : masquer les valeurs impossibles. Écartée parce qu'un
client qui ne voit plus son 39 conclut que la boutique ne l'a pas, et
part.
La photo principale change dès que toutes les variantes encore possibles
ont la même photo : choisir « Noir » suffit, choisir « 41 », qui existe
dans les deux coloris, ne change rien.
Alternative écartée : faire désigner au commerçant l'option « couleur »
du produit. Écartée parce que ce serait un réglage de plus pour lui, et
que la photo déjà attribuée à chaque variante donne la même réponse sans
qu'il ait rien à faire.
À 390 px, la grande photo est un écran au-dessus du sélecteur : le client
ne la voyait pas changer au moment où il touchait le coloris. Une
vignette de la photo choisie s'affiche donc à côté de « Coloris : Gris
chiné », seulement sur la ligne dont chaque valeur désigne une photo.
Alternative écartée : remonter la page jusqu'à la photo à chaque choix.
Écartée parce qu'elle arracherait le client au sélecteur alors qu'il lui
reste la taille à choisir.

## 2026-10-25 — Une photo est réduite deux fois : sur le téléphone par confort, sur le serveur par garantie

Une photo de téléphone de 12 mégapixels faisait échouer l'envoi : décodée,
elle occupe environ 48 Mo, et le serveur la recopiait pour chacun des
trois formats, au-delà des 128 Mo d'une requête PHP. Le serveur décode
désormais l'original une seule fois, le réduit une seule fois, et tire
les six fichiers de cette version réduite. Au-delà de 16 mégapixels, il
refuse avant même de décoder, avec un message qui dit au commerçant quoi
faire. Ce plafond est mesuré, pas choisi : GD coûte environ 5 octets par
pixel, 20 mégapixels échouaient sur un vrai serveur réglé 16 Mo sous la
production, 16 passent avec 24 Mo de marge.
Le téléphone, lui, réduit la photo avant l'envoi (le plus petit côté à
1200 px, environ 150 à 400 Ko) : envoyer 5 Mo pour en garder 300 Ko,
c'est faire payer au commerçant, en data mobile, ce qui est jeté à
l'arrivée. Le navigateur redresse aussi au passage un portrait pris au
téléphone.
Alternatives écartées : l'une ou l'autre seule. Le téléphone seul ne
protège de rien (un ordinateur, un navigateur ancien où la réduction
échoue, une requête forgée envoient l'original). Le serveur seul fait
payer la data. Relever la mémoire de PHP pour cette route a aussi été
écarté : un plafond qui dépend d'un réglage du serveur se perd au
premier changement d'hébergeur, et 48 mégapixels demanderaient 250 Mo
par envoi.

## 2026-10-25 — La livraison se facture par commune, sur un choix explicite du commerçant

Le commerçant choisit : facturer la livraison selon la commune (ses zones
et leurs frais), ou ne pas la facturer sur le site. Sans facturation, le
client tape sa commune et lit « Livraison : à convenir avec la boutique ».
Une zone à 0 FCFA s'affiche « Offerte ».
Alternative écartée : déduire le choix de la seule présence de zones.
Écartée parce que « aucune zone » voulait alors dire à la fois « je ne
facture pas » et « je n'ai pas encore configuré », et qu'un commerçant qui
suspend la facturation aurait dû effacer ses zones pour la reprendre plus
tard.
Alternative écartée : afficher « 0 FCFA » quand rien n'est facturé. C'est
ce que faisait la vitrine. Écartée parce que le client lit une livraison
gratuite et découvre les frais à la porte.
Une zone déjà portée par une commande est archivée, jamais effacée : la
commande garde le lien vers la zone qui l'a facturée. Les frais, eux, sont
copiés sur la commande : les modifier ne change aucune commande passée.
Une seule méthode décide des zones proposées, et la vitrine, la
validation de la commande et le calcul des frais la lisent toutes les
trois : une zone que l'écran ne propose pas ne peut pas être facturée par
une requête forgée.

## 2026-10-25 — Un 419 redemande un jeton CSRF et rejoue la requête une fois

Un cookie XSRF-TOKEN encore présent mais périmé (session perdue côté
serveur) faisait refuser chaque écriture, connexion comprise, et le
commerçant lisait « Impossible de contacter le serveur » jusqu'à vider ses
cookies. Le client HTTP redemande un jeton et rejoue la requête, une seule
fois. Alternative écartée : redemander un jeton avant chaque écriture.
Écartée parce qu'elle double chaque requête pour un cas rare ; un second
419 reste une vraie erreur, montrée comme telle.

## 2026-10-25 — « Autre commune » : une commune hors liste passe, livraison à convenir

Quand la boutique facture par commune, un client dont la commune n'est pas
dans la liste choisit « Autre commune » et la saisit. Aucune zone, aucun
frais : la livraison est à convenir, le récapitulatif le dit sous le
total, et la boutique l'appelle. Taper une commune de la liste y est
refusé, pour qu'« Autre » ne serve pas à éviter des frais connus.
Alternative écartée : n'accepter que les communes de la liste. Écartée
parce que perdre un client de Bingerville coûte plus qu'un total sans
frais de livraison.

## 2026-10-25 — Une commande de la vitrine d'une boutique expire au bout de 24 heures

Au lieu de 15 minutes, comme le Mobile Money. Un commerçant seul dans sa
boutique, occupé avec un client, ne voyait pas la commande à temps : elle
expirait, son stock était relâché, et le client attendait pour rien.
Alternative écartée : un délai court avec une alerte au commerçant.
Écartée parce qu'aucune alerte n'existe aujourd'hui, et qu'une commande
perdue coûte plus que du stock réservé une journée. Le restaurant reste à
15 minutes : un plat ne se réserve pas un jour, son délai propre viendra
avec le lot restauration.

## 2026-10-26 — Au comptoir, « Livrée » veut dire marchandise remise

Une vente au comptoir est terminée quand elle est saisie : l'argent est
encaissé, l'article est parti avec le client. Elle est donc créée,
confirmée (sa réservation consommée), passée à « Livrée » et payée en
espèces, dans une seule transaction : si une étape échoue, rien n'est
écrit. « Livrée » désigne la marchandise remise au client, ce qui est
littéralement vrai au comptoir ; ce n'est pas une livraison à domicile, et
le détail de la commande n'affiche aucune ligne de livraison. Alternative
écartée : un statut « Vendue » propre au comptoir. Écartée parce qu'il
aurait fallu l'ajouter à chaque filtre, chaque chiffre et chaque
transition, pour dire la même chose que « Livrée ». Une vente au comptoir
n'est jamais à traiter et n'expire jamais.

## 2026-10-26 — Une vente au comptoir se fait sans client

Le client de passage ne laisse ni nom ni numéro : la commande est créée
sans client (client_id vide), affichée « Client de passage ». Un habitué
peut être identifié par un lien discret. Alternative écartée : un client
fictif « Comptoir » partagé. Écartée parce qu'il fausserait le nombre de
clients, l'historique d'achat et le repérage des doublons par téléphone.

## 2026-10-26 — La saisie manuelle passe par CreerCommande, jamais à côté

Comptoir, téléphone et WhatsApp appellent le même CreerCommande que la
vitrine, étendu (client facultatif, canal, auteur, référence WhatsApp),
puis les services existants de confirmation, de transition et de
paiement. Les prix sont relus en base ; un prix envoyé par l'écran n'est
même pas lu, et il n'existe pas de remise. Une commande saisie garde qui
l'a créée, par quel canal et quand ; chaque paiement garde qui l'a reçu.
Une commande au téléphone ou sur WhatsApp est confirmée à la création
(le commerçant a parlé au client et vu son stock), non payée, et
n'expire jamais. Un produit en brouillon se vend : il est en rayon même
s'il n'est pas encore en ligne ; un produit archivé, non. Alternative
écartée : un service propre à la saisie manuelle. Écartée parce que deux chemins de création finissent toujours
par diverger sur le stock ou les prix.

## 2026-10-26 — Un doublon possible avertit, il ne bloque pas

Même téléphone avec une commande en cours de moins de 24 heures, ou
référence WhatsApp déjà utilisée : le serveur répond 409 avec les
commandes concernées, l'écran les montre avec leur lien, et « Créer quand
même » renvoie la saisie avec une confirmation. Alternative écartée :
refuser. Écartée parce qu'un client commande parfois deux fois dans la
journée, et qu'un commerçant bloqué ressaisirait en changeant le numéro.

## 2026-10-26 — Saisir une commande est une permission à part

creer_commandes, distincte de gerer_commandes : l'administrateur,
l'opérateur et le caissier l'ont, le gestionnaire de stock non. Le
message de stock insuffisant du back-office nomme l'article, la quantité
demandée et la quantité disponible ; celui de la vitrine ne change pas et
ne révèle pas le stock exact.

## 2026-10-27 — Une virgule sépare des valeurs d'option

« blanc, noir » tapé dans le champ d'une option crée deux valeurs. Un
commerçant écrit naturellement une liste ; il se retrouvait avec une seule
valeur nommée « blanc, noir ». L'écran découpe et l'annonce (« 2 valeurs :
blanc, noir »), et le serveur découpe aussi, pour qu'aucun écran ne puisse
recréer le piège. La casse tapée est gardée : « xl » ne doit pas devenir
« Xl ». Alternative écartée : refuser la virgule. Écartée parce qu'elle
oblige à recommencer, alors que l'intention est claire.

## 2026-10-27 — Rien n'est « enregistré » tant qu'un texte saisi ne l'est pas

Le bandeau de l'écran des variantes disait « Tout est enregistré » alors
qu'un champ contenait encore une valeur tapée et non ajoutée, ou que des
cases cochées n'étaient pas créées. Il dit maintenant « Pas encore
enregistré », avec quoi et quel bouton toucher. Le même principe que pour
le stock en B2 : un écran ne rassure jamais sur ce qu'il n'a pas écrit.

## 2026-10-27 — Le bouton du produit n'a pas l'air d'enregistrer les variantes

« Enregistrer les modifications » n'enregistre que le produit. Tant que la
grille a des cases cochées non créées, ou qu'un texte d'option n'est pas
ajouté, il cesse d'être vert, une phrase au-dessus dit qu'il n'enregistre
pas les variantes, et le toucher ramène à la grille au lieu de quitter la
page (ce qui perdait la grille). Alternative écartée : que ce bouton crée
aussi les variantes cochées. Écartée parce que la grille demande un stock
de départ et une confirmation avant de retirer une variante vendue : les
faire passer en silence par le bouton du produit cacherait ces choix.

## 2026-10-27 — Des options sans variante sont signalées

Options et valeurs définies, aucune variante créée : la vitrine montre un
produit simple, sans choix, et rien ne disait pourquoi. L'écran le dit :
« Vous avez défini Taille et Couleur, mais aucune variante n'est créée :
cochez les combinaisons ci-dessous. »

## 2026-10-27 — Le prix barré ne se compare qu'à un prix exact

Le prix barré, déjà saisi au back-office, s'affiche maintenant sur la
vitrine, seulement s'il dépasse le prix affiché. À côté de « À partir de »,
il ne s'affiche pas : on ne saurait pas quelle variante est en promotion.
Sur la fiche, il apparaît dès que le choix est complet, et jamais pour une
variante plus chère que lui. Le panier et la commande n'en tiennent aucun
compte : le prix payé est toujours celui de la base.

## 2026-10-28 — Une demande WhatsApp n'est pas une commande

Un clic sur « Commander sur WhatsApp » crée une référence, que le
commerçant voit désormais comme une demande (nouvelle, traitée, ignorée).
Elle ne réserve aucun stock, n'a pas de numéro, n'a aucune ligne dans
commandes et ne compte dans aucun chiffre ; un test vérifie que toutes les
statistiques restent strictement identiques. Les demandes vivent dans
references_whatsapp (statut, date du changement), sans table nouvelle : la
référence EST la demande. Alternative écartée : créer une commande « en
attente » au clic. Écartée parce qu'on ne sait rien du client au clic
(wa.me ne renvoie rien), et qu'une commande fantôme réserverait du stock et
gonflerait les chiffres pour un client qui n'écrira peut-être jamais.

## 2026-10-28 — Au clic, on ne sait rien du client : la page ne l'invente pas

La page « Demandes WhatsApp » ne montre que le produit, la variante, l'heure
du clic, la référence et le statut. Aucune colonne nom ni téléphone : elles
resteraient vides pour toujours et passeraient pour un bug. Le client se
saisit au moment de transformer la demande en commande.

## 2026-10-28 — Une demande ne devient qu'une seule commande

Transformer une demande passe par la saisie manuelle du lot C, déjà
remplie (canal WhatsApp, produit, variante, référence). Dans la même
transaction, la demande passe « traitée » ; si la création échoue, elle
reste comme elle était. Une demande déjà transformée est REFUSÉE (409, avec
la commande), même par requête forgée : sa ligne est verrouillée le temps
de la transaction, et un index unique sur commandes.reference_whatsapp_id
tient même en cas de course. Cela remplace la règle du lot C, où une
référence déjà utilisée n'était qu'un avertissement qu'on pouvait
confirmer. La même saisie rejouée (même clé d'idempotence) retrouve sa
commande. Une demande devenue commande reste « traitée » : l'ignorer
contredirait la commande.

## 2026-10-28 — Gérer les demandes WhatsApp est une permission à part

gerer_demandes_whatsapp, distincte des permissions des commandes :
répondre à des clients et toucher au stock ne sont pas le même droit.
L'administrateur, l'opérateur et le caissier l'ont ; le gestionnaire de
stock non. Transformer une demande demande en plus creer_commandes.

## 2026-10-28 — Au téléphone et sur WhatsApp, seuls le numéro et la commune sont obligatoires

Sans numéro on ne livre pas, et la commune fixe les frais. Le nom et le
quartier, le commerçant ne les a pas toujours (un message WhatsApp n'en
dit souvent rien) : les exiger le pousserait à inventer. Un client peut
donc exister sans nom (clients.nom devient facultatif) ; la commande
l'affiche « Sans nom », jamais un faux nom. Un nom laissé vide n'efface pas
celui d'un client connu. La vitrine exige toujours le nom, et au comptoir
un habitué qu'on identifie aussi.

## 2026-10-28 — Une référence collée fait comme « Transformer en commande »

Le geste naturel du commerçant est de recopier le message WhatsApp reçu.
Collée dans une saisie neuve, la référence ajoute l'article de la demande
(sauf s'il est épuisé, retiré, ou si la demande ne dit pas quelle variante)
et une demande déjà devenue commande est refusée dès le collage, pas
seulement à l'enregistrement. Le refus serveur reste la seule garantie.

## 2026-10-29 — « Autre commune » peut avoir des frais fixes

Le commerçant peut fixer un montant pour toute commune absente de sa
liste. Sans montant, « Autre commune » reste à convenir, comme avant.
C'est une zone de livraison comme les autres, marquée autre_commune (une
seule active par boutique), plutôt qu'un réglage à part : une commande
garde ainsi le lien vers la zone qui l'a facturée et sa copie des frais,
et retirer ces frais archive la zone si une commande l'a déjà utilisée.
Elle n'apparaît jamais dans la liste des communes, et ne se renomme ni ne
se retire par les routes des communes. Une commune de la liste tapée en
« Autre » reste refusée. Sans aucune commune listée, ces frais valent pour
toutes : c'est un forfait de livraison.
