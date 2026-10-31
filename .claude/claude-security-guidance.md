# Couahi — règles de sécurité propres au projet

Plateforme multi-établissements : chaque boutique a ses données, son
sous-domaine et sa session. Montants en francs CFA (XOF). Ces règles
s'ajoutent aux contrôles habituels ; chacune se vérifie dans le diff.

## Isolation par établissement
- Un modèle de table métier porte le trait AppartientAEtablissement, dont la
  portée globale filtre par l'établissement du sous-domaine. Un nouveau
  modèle métier sans ce trait est une fuite entre boutiques.
- Toute utilisation de pourTousEtablissements() ou withoutGlobalScope() hors
  des tests, seeders et factories porte un commentaire qui dit pourquoi.
  Hors web (commandes artisan, jobs comme l'expiration des commandes,
  migrations), voir tous les établissements est légitime.
- Sur une route web, pourTousEtablissements() ne sert jamais à atteindre des
  lignes que rien ne borne à l'établissement courant. Les seules exceptions
  sont nommées dans decisions.md (31/10) : la liste des établissements du
  super-admin ; LivraisonResource et les zones de livraison ; CreerCommande
  et les services de stock et de commande ; GererVariantes. Chacune est
  bornée par une relation de l'établissement ou par l'id d'une ligne déjà
  résolue dans la portée. Un appel nouveau sur une route web, hors de ces
  exceptions, est un constat à signaler : il demande l'accord écrit de
  l'utilisateur.
- Une route du super-admin sur UN établissement pose le contexte par le
  middleware definir.etablissement.cible, jamais par pourTousEtablissements().
- Un identifiant reçu dans la requête (produit, variante, zone, commande,
  membre) se résout dans la portée de l'établissement : l'id d'une autre
  boutique donne 404, pas la ligne.
- DB::table(), Model::insert() en masse et le SQL brut ne touchent pas une
  table métier hors migration : ils contournent la portée et les événements.
- Le cookie de session reste propre à chaque sous-domaine
  (SESSION_DOMAIN=null). L'élargir est une faille, pas un correctif.

## Permissions
- Chaque route et chaque action vérifient la permission côté serveur
  (policy, Gate, middleware). Masquer un bouton n'est jamais une protection.
- Une commande avec au moins un paiement enregistré, même partiel, ne
  s'annule qu'avec annuler_payees, et la condition est dans le WHERE de
  l'UPDATE qui annule, pas seulement vérifiée avant.
- Un droit qui dépend de l'état d'une ligne se revérifie au moment d'écrire
  (WHERE de l'UPDATE, ou verrou pris avant de relire).
- Une donnée réservée (chiffre d'affaires, panier moyen) est absente de la
  réponse JSON pour qui n'a pas le droit, pas seulement cachée à l'écran.

## Argent
- Prix, totaux et frais de livraison viennent toujours de la base. Un
  montant présent dans la requête n'est pas lu. Un prix écrit dans un
  message WhatsApp non plus : seule la référence fait foi.
- Les montants sont des entiers XOF : colonne entière, règle de validation
  "integer". Jamais float ni decimal.
- Une notification de paiement d'un prestataire (CinetPay, PayDunya,
  Kkiapay…) n'est jamais crue sur parole : le statut se revérifie auprès du
  prestataire avec l'identifiant de transaction, montant et devise comparés
  à la commande. Le traitement est idempotent : une notification rejouée ne
  paie pas deux fois.

## Secrets
- Aucune clé, aucun secret, aucun mot de passe réel dans le code, les tests,
  les seeders, .env.example, les journaux, les messages d'erreur ni les
  rapports. Les tests utilisent des valeurs manifestement fictives.
- Aucune commande, aucun test, aucun journal n'affiche la valeur d'un mot de
  passe, d'une clé ou d'un jeton.
- Les identifiants de paiement d'un établissement sont chiffrés en base, et
  le secret n'est jamais renvoyé par l'API. Les secrets de la plateforme
  vivent dans l'environnement, jamais en base.
- Un code d'activation ou un jeton ne passe jamais dans une URL.

## Données personnelles
- Aucun nom, téléphone, commune ni quartier de client dans les journaux
  (Log::, exceptions, journal d'audit). Une trace dit qui a fait quoi,
  jamais le contenu consulté.

## Vitrine
- Tout texte saisi par un commerçant (nom de boutique, description,
  produit, option, commune) est échappé à l'affichage : JSX ordinaire,
  jamais dangerouslySetInnerHTML, innerHTML ni {!! !!}. Le HTML posé par le
  serveur (balises d'aperçu de index.html) passe par echapper().
- Le message de stock insuffisant de la vitrine ne révèle pas le stock
  exact.
