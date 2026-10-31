# Couahi — instructions du projet

Ce fichier vaut pour tout le dépôt, backend/ comme frontend/, et se charge
d'où qu'on lance Claude Code. Mais Claude Code se lance depuis la racine :
les réglages (.claude/settings.json), le blocage des fichiers .env, les
règles de sécurité et le skill fin-de-lot ne sont actifs que lancés de là.
Lancé depuis backend/ ou frontend/, ces règles-ci se chargent, ces
protections non.

Les consignes Laravel générées par Boost restent dans backend/CLAUDE.md (et
backend/AGENTS.md pour les autres agents). Quand elles divergent de ce
fichier, ce fichier l'emporte. Divergences connues :
- Boost déconseille de vérifier dans tinker ce que les tests couvrent ; ici,
  après une migration, on vérifie à la main un parcours réel (voir plus bas).
- Boost fait lancer les tests les plus étroits ; ici, la suite complète se
  lance avant chaque commit.
- Boost parle de Laravel Cloud et d'un skill deploying-to-cloud : ne
  s'applique pas, la mise en ligne se fait sur notre propre serveur (voir
  ETAT.md, « À faire pour la mise en ligne »).

Règles de sécurité : @.claude/claude-security-guidance.md

Fin de lot : suivre le skill fin-de-lot (.claude/skills/fin-de-lot/SKILL.md).

## Fichiers et secrets interdits
Ne jamais lire, ouvrir, modifier ni afficher un fichier .env, où qu'il soit
(backend/.env, frontend/.env, .env.local, .env.production…). .env.example se
lit et se modifie pour documenter une variable nouvelle, mais ne contient
JAMAIS une vraie valeur : des clés vides ou des exemples évidents (null,
exemple-…). La valeur réelle, l'utilisateur la met lui-même dans son .env.

Aucune commande n'affiche jamais la valeur d'un mot de passe, d'une clé ou
d'un jeton : ni variables d'environnement (printenv, env), ni phpinfo, ni
config:show sur un groupe qui en contient un, ni tinker qui affiche env(),
config() ou une colonne password, ni un test, un journal ou un rapport. Un
secret dont une commande a besoin passe de la configuration à la commande
sans s'afficher (exemple : la sauvegarde du skill fin-de-lot). Pour vérifier
un mot de passe, Hash::check, et n'afficher que OK ou FAUX.

Le hook .claude/hooks/bloquer-env.mjs bloque la lecture et la modification
des fichiers .env et ces détours évidents ; s'il tombe en panne, il bloque
tout. Il ne lit que le texte d'une commande, il ne sait pas ce qu'elle fera.
Il n'empêchera jamais :
- un nom de fichier construit ou masqué (cat .e?v, base64, concaténation) ;
- un programme qui lit le secret lui-même et l'affiche : un script écrit
  puis lancé, un test qui fait echo, un secret rangé dans une variable puis
  affiché sous un autre nom ;
- une page d'erreur en mode debug, un journal (storage/logs), une réponse
  HTTP qui contiendrait un secret ;
- un secret recopié ailleurs que dans .env (un fichier quelconque, le
  presse-papiers, un message) ;
- une session lancée ailleurs qu'à la racine, où il n'est pas chargé.
La règle reste donc la vraie protection, le hook rattrape l'inattention.
Toute modification du hook se prépare dans une copie, passe le banc
d'essai, puis remplace le fichier d'un coup : une version à moitié écrite
bloque tout, y compris sa propre correction.

Ne pas explorer vendor/, node_modules/ ni backend/storage/logs/.

## Outils du serveur MCP de Boost
Cinq outils seulement, liste validée par l'utilisateur le 31/10 :
search-docs, application-info, database-connections, database-schema,
get-absolute-url. Les six autres (database-query, tinker,
read-log-entries, last-error, browser-logs, record-rule) sont refusés
trois fois : exclus dans backend/config/boost.php, refusés dans
.claude/settings.json, et hors de la liste blanche du hook, fermée par
défaut (un outil ajouté par une future version de Boost est refusé). Les
utiliser, même à la demande, est refusé : en ajouter un à la liste demande
l'accord écrit de l'utilisateur, aux trois endroits.

search-docs envoie le texte cherché à boost.laravel.com : jamais une
donnée de client, un secret ni un extrait de la base dans une recherche.
Seulement des termes techniques (« rate limiting », « eloquent scopes »).

## Invariant d'isolation
L'isolation multi-établissements repose sur les événements Eloquent.
Interdits sans validation explicite, sur une table métier :
Model::insert() en masse, DB::table() et les requêtes brutes. Interdit
aussi : WithoutModelEvents dans les seeders comme dans les tests.
Admis : DB::table() sur une table qui n'est pas métier (sessions) ; dans
un test, une écriture ou une lecture brute dont le but est de contourner
le modèle, pour prouver que la base refuse un état invalide ou qu'une
valeur est bien chiffrée en base.
Les exceptions à la portée d'établissement sur une route web sont nommées
dans decisions.md (31/10) ; une nouvelle demande l'accord écrit de
l'utilisateur.

## Avant et après toute migration
Avant : une sauvegarde pg_dump de la base de développement (commande dans
le skill fin-de-lot), et son chemin dans le rapport. Sans sauvegarde, pas
de migration. Le lot F l'a oubliée.

Après : appliquer sur la base de DÉVELOPPEMENT aussi (php artisan migrate),
pas seulement sur les bases de test. Et vérifier à la main dans tinker
qu'un parcours réel fonctionne avant de déclarer une étape terminée.

## Limitation de débit
La limitation de débit sur une route publique sensible (connexion,
activation, etc.) se fait par email ET par IP, jamais par IP seule.
Une IP seule pénalise des utilisateurs sans lien entre eux qui la
partagent (opérateurs mobiles). Voir App\Support\RateLimiting\LimiteurEmailEtIp.

Elle ne compte que les ÉCHECS, jamais une connexion réussie, qui remet
au contraire le compteur de l'email à zéro. Ne pas y remettre le
middleware "throttle" de Laravel : il compte chaque requête, et enfermait
dehors une commerçante qui s'était connectée deux fois dans la journée
puis trompée deux fois de mot de passe.

Dérogation : une route publique ANONYME (aucun email, ex. la génération
de lien WhatsApp de la vitrine) ne peut pas appliquer cette règle. Sa
protection repose alors sur l'idempotence de ce qu'elle crée (même
visiteur, même demande, peu de temps après : on renvoie l'existant, aucune
ligne de plus), le visiteur étant identifié par sa session — jamais par
l'IP d'abord, qui confond les abonnés d'un même opérateur. La limite par
IP n'y est qu'un plafond de secours large (60/heure par IP et par
établissement), pas la serrure.

## Données de démonstration réalistes
Les données de démonstration doivent ressembler aux données de
production. Un champ facultatif jamais rempli dans les seeders est un bug
qui attend son heure : le bug du logo super-admin (voir
DefinirEtablissementCible) est passé inaperçu parce qu'aucun établissement
de démo n'avait de logo, alors que les deux établissements réels en ont
un. Remplir un champ facultatif via le vrai service (le système de médias
pour une image, pas une ligne fabriquée à la main) plutôt que de le
laisser vide "pour aller plus vite" — un raccourci dans les seeders cache
exactement la classe de bug qu'un test est censé attraper.

De même, chaque configuration qu'un commerçant peut choisir a au moins un
établissement de démo qui l'utilise : aucun paiement (chez-awa,
maquis-du-port), Mobile Money seul avec du stock compté (wax-adjame).
DonneesDemoTest le vérifie.

Une factory crée ce que le service de création crée : un établissement
naît avec sa ligne de réglages du site (CreerEtablissement). Sans elle,
les établissements de démo et de test ne ressemblaient à aucun
établissement réel, et l'écran Livraison répondait 404 sur la démo
seulement.

## Mémoire et images
Tout traitement d'une image envoyée se prouve sous la limite de mémoire
de production (128 Mo), dans un processus PHP neuf : un vrai serveur
lancé avec cette limite, comme le fait EnvoiPhotoSousLimiteMemoireTest.
Jamais dans le processus PHPUnit, qui porte la mémoire de tous les tests
précédents et dont la limite ne dit rien d'une requête réelle. Une photo
de téléphone de 12 mégapixels tuait la requête d'envoi (500 muette) alors
que tous les tests passaient : ils n'envoyaient que des images de
100 pixels. Un plafond de taille se mesure avec de la marge (24 Mo sous la
limite), jamais au ras.

## Vérifier un écran dans Chrome piloté
Un Chrome sans fenêtre piloté par le protocole DevTools ne livre pas
toujours les entrées simulées (Input.dispatchTouchEvent, gestes, souris) :
après la connexion au back-office, aucune n'atteint la page, et un toucher
reste sans réponse. Avant de conclure qu'un écran est cassé, poser un
écouteur en phase de capture sur document : s'il ne reçoit rien, c'est le
pilote, pas l'écran. Dans ce cas, déclencher les boutons par
element.click() (mêmes gestionnaires, mêmes requêtes), le dire dans le
compte rendu, et faire confirmer le geste au doigt par l'utilisateur sur
un vrai téléphone. Le profil Chrome se passe en chemin complet : avec un
chemin relatif, Chrome n'ouvre jamais son port. Toute preuve d'un écran
s'accompagne du journal réseau (requête, corps, code, réponse) et des
SELECT bruts, pas seulement d'une capture.

## Tâches planifiées
Toute tâche planifiée a au moins un test qui l'exécute PAR LE
PLANIFICATEUR (schedule:run), jamais seulement par appel direct à son
code. Un test qui appelle la tâche à la main prouve qu'elle fonctionne,
pas qu'elle est branchée : la tâche d'expiration des commandes avait des
tests verts et n'avait pourtant jamais été exécutée une seule fois par le
planificateur, qui affichait « DONE » chaque minute. Toute nouvelle tâche
s'ajoute aussi à l'inventaire d'ExecutionParLePlanificateurTest.

## Durée de la suite de tests
La suite complète (`php artisan test`, Postgres compris) se lance en
entier avant chaque commit. Au-delà de 8 minutes de durée réelle
(chronométrée, pas le chiffre affiché par le rapport), on traite la
lenteur avant d'ouvrir un nouveau lot : une suite trop longue finit par ne
plus être lancée, et c'est là que les régressions passent. On mesure avant
de corriger (la lenteur venait des écritures de fichiers, pas de ce qu'on
croyait), et on ne supprime jamais un test pour gagner du temps.

Une durée ne se compare qu'à l'intérieur d'une même série de mesures :
même machine, même jour, même emplacement, rien d'autre qui tourne. Le
même commit a donné 87 s puis 153 s dans la même journée. Deux chiffres
pris à des jours différents ne disent rien d'une dérive, ni dans un sens
ni dans l'autre. Pour savoir si un changement ralentit la suite, on
chronomètre l'avant et l'après l'un après l'autre, dans la même série.

## Montants en francs CFA
Tout montant d'argent est un entier, en francs CFA : colonne entière en
base, règle "integer" en validation, jamais un float ni un decimal. Le
franc CFA n'a pas de subdivision en usage, et un nombre à virgule finit
toujours par produire un arrondi faux sur une somme d'argent.

## Consultations par la plateforme
Toute route du super-admin qui montre les données commerciales d'un
établissement (commandes, clients, chiffre d'affaires) est journalisée
pour le commerçant, qui le voit dans son back-office. Une nouvelle route
super-admin sur un établissement est soit journalisée, soit déclarée
administrative dans ConsultationsPlateformeTest, en sachant que la
déclarer administrative revient à affirmer qu'elle ne montre aucune de
ces données. Jamais le contenu consulté dans la trace.

## Messages WhatsApp : seule la référence fait foi
Un prix, une quantité, une variante ou un article ne sont jamais lus dans
le texte d'un message WhatsApp ni dans une conversation : ils viennent de
la base, via la référence. Cela vaut pour l'IA WhatsApp : un montant
proposé par le client est une demande de remise à soumettre au
commerçant, jamais un prix à appliquer. Tout test de résolution part d'un
message falsifié, pas d'un message intact.

## Cache de la vitrine
Les réponses publiques de la vitrine sont mises en cache 60 secondes.
Toute modification de la forme d'une de ces réponses (un champ ajouté,
retiré ou renommé) incrémente le numéro de format de CacheVitrine dans le
même commit. Sinon, juste après la mise en ligne, la nouvelle interface
reçoit l'ancienne forme pendant une minute : en B3, une fiche sans le
nouveau champ faisait tomber la page.

## Droits qui dépendent de l'état d'une ligne
Quand un droit dépend de l'état de ce qu'on modifie (annuler une commande
seulement si elle n'a reçu aucun paiement, retirer un administrateur
seulement s'il en reste un autre), la condition se revérifie au moment
d'écrire : dans le WHERE de l'UPDATE, ou sous un verrou pris avant de
relire. La vérification faite avant, dans la policy, lit un état qui a pu
changer entre-temps. Chaque garde de ce genre a son test de vraie
concurrence dans la suite Postgres.

## Tests de mutation
Un fichier muté se restaure depuis sa copie lue avant la mutation,
jamais par git checkout : celui-ci remet la version du dernier commit et
efface sans bruit le travail en cours sur ce fichier.

## Documentation
Aucun lot n'est terminé tant qu'ETAT.md, à la racine, n'est pas à jour.
Il répond à « qu'est-ce que l'application sait faire aujourd'hui » en
trois sections : ce qui fonctionne, ce qui existe à moitié (avec ce qui
manque), ce qui n'existe pas encore (prochain lot en tête). En français
simple, sans détail technique ni numéro de commit, et rempli d'après ce
que le code fait réellement, vérifié, jamais d'après ce qu'on suppose :
sans lui, chaque session repartait à l'aveugle, et une fonctionnalité
existante a déjà été crue absente.

Voir decisions.md à la racine pour le pourquoi des choix d'architecture
non évidents. Ce fichier (CLAUDE.md) ne contient que des règles de
méthode, jamais une description du code (routes, permissions, tables) :
ce contenu se périme et devient faux dès le prochain changement.
