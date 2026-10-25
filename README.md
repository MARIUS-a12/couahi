# Couahi

Plateforme de boutiques en ligne pour commerçants d'Abidjan : chaque
établissement (boutique ou restaurant) a sa vitrine publique sur son propre
sous-domaine, et son back-office pour gérer catalogue, stock, commandes,
équipe et paiements. L'équipe de la plateforme administre les
établissements depuis un espace super-admin séparé.

**Principe de nom :** Couahi est invisible pour le client final. Un client
qui achète chez Awa voit « Chez Awa », jamais « Couahi ». Le nom n'apparaît
que dans l'espace super-admin, sur le site de la plateforme et dans les
échanges avec les commerçants abonnés.

## Organisation du dépôt

| Dossier | Contenu |
|---|---|
| `backend/` | API et règles métier : Laravel 13, PHP 8.4, PostgreSQL |
| `frontend/` | Vitrine publique, back-office et espace super-admin : React 19, Vite, TypeScript, TanStack Query, Tailwind 4 |
| `ETAT.md` | Ce que l'application sait faire aujourd'hui, ce qui existe à moitié, et la suite prévue |
| `decisions.md` | Journal des choix d'architecture non évidents : le pourquoi, et l'alternative écartée |
| `backend/AGENTS.md` | Règles de travail sur ce projet (méthode, pas description du code) |

Avant de modifier quoi que ce soit d'important, lire `backend/AGENTS.md`
puis les entrées de `decisions.md` qui touchent au sujet.

## Fonctionnement multi-établissements

L'établissement est déterminé par le sous-domaine visité
(`chez-awa.localhost`, `maquis-du-port.localhost`...) : toutes les données
d'un établissement sont filtrées automatiquement par ce contexte. L'espace
super-admin vit sur son propre hôte (`admin.localhost` en développement,
voir `HOTE_SUPER_ADMIN`), sans établissement courant. Chaque sous-domaine a
son propre cookie de session, volontairement (voir decisions.md).

## Installation en développement

Prérequis : PHP 8.4 avec les extensions `gd` et `pdo_pgsql`, Composer,
Node 22, PostgreSQL avec deux bases : celle de développement, et
`saas_boutiques_test` pour la suite de tests Postgres. L'extension
`exif` est recommandée, et nécessaire en production : sans elle, une
photo envoyée sans passer par la réduction du navigateur (depuis un
ordinateur, par exemple) garde son orientation d'origine et peut
apparaître couchée.

Backend :

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Renseigner dans .env la connexion PostgreSQL (DB_CONNECTION=pgsql,
# DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD) :
# .env.example propose encore sqlite, mais le projet tourne sur PostgreSQL.
php artisan migrate:fresh --seed
php artisan storage:link
```

Frontend :

```bash
cd frontend
npm install
cp .env.example .env    # VITE_API_PORT=8000
```

Lancer, chacun dans son terminal :

```bash
cd backend  && php artisan serve          # API sur le port 8000
cd backend  && php artisan schedule:work  # tâches planifiées (expiration des commandes, abonnements)
cd frontend && npm run dev                # vitrine et back-office sur le port 5173
```

Sans `schedule:work`, les commandes non payées n'expirent jamais et leur
stock reste réservé.

## Données de démonstration

`php artisan migrate:fresh --seed` crée trois établissements, choisis pour
couvrir chaque configuration réelle :

| Établissement | Type | Paiement |
|---|---|---|
| chez-awa | épicerie (boutique) | aucun : panier et WhatsApp |
| maquis-du-port | restaurant | aucun : panier et WhatsApp |
| wax-adjame | tissus (boutique, stock compté) | Mobile Money |

Comptes (mot de passe `motdepasse` pour tous) :

| Compte | Rôle | Où se connecter |
|---|---|---|
| super@plateforme.test | super-admin | http://admin.localhost:5173/admin/connexion |
| awa@chez-awa.test | administratrice de chez-awa | http://chez-awa.localhost:5173/admin/connexion |
| adjoua@maquis-du-port.test | administratrice de maquis-du-port | http://maquis-du-port.localhost:5173/admin/connexion |
| yao@maquis-du-port.test | opérateur de maquis-du-port | http://maquis-du-port.localhost:5173/admin/connexion |
| fatou@wax-adjame.test | administratrice de wax-adjame | http://wax-adjame.localhost:5173/admin/connexion |

Les vitrines sont à la racine de chaque sous-domaine, par exemple
http://chez-awa.localhost:5173/.

## Tests

```bash
cd backend  && php artisan test                     # suite complète, Postgres compris (~75 s)
cd frontend && npm test && npx tsc -b && npx eslint src
```

`php artisan test` exige une base PostgreSQL `saas_boutiques_test`
accessible : les contraintes, les verrous et les courses entre processus
n'y sont vérifiés que contre un vrai PostgreSQL. La suite se lance en
entier avant chaque commit ; au-delà de 8 minutes réelles, on traite la
lenteur avant d'ouvrir un nouveau lot (voir `backend/AGENTS.md`).
