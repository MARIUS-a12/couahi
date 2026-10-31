---
name: fin-de-lot
description: Routine de fin de lot de Couahi — sauvegarde avant migration, suite complète chronométrée, mutations, audits de dépendances, revue de sécurité, ETAT.md et decisions.md, preuves à 390 px, instructions de vérification pour l'utilisateur. À suivre avant de déclarer un lot terminé, et dès qu'un lot va lancer une migration.
---

# Fin de lot

Un lot n'est terminé que lorsque chaque étape a sa preuve dans le rapport.
Une étape sautée ou impossible se dit telle quelle, avec la raison. Jamais
« vert » sans la sortie qui le montre.

## 0. Avant toute migration : la sauvegarde (non négociable)

Avant le premier `php artisan migrate` du lot sur la base de développement,
et de nouveau avant une migration suivante si des données ont changé. Pas
de sauvegarde, pas de migration. Le lot F l'a oubliée.

Le mot de passe de la base est dans backend/.env, que Claude ne lit pas :
c'est PHP qui le lit, et il ne s'affiche jamais. La commande se lance depuis
le dossier des sauvegardes, hors du dépôt :

    mkdir -p ~/sauvegardes-couahi && cd ~/sauvegardes-couahi && php "C:/projet saas hk studio/backend/artisan" tinker --execute '$c = config("database.connections.pgsql"); $f = getcwd()."/".$c["database"]."-".now()->format("Y-m-d-His").".dump"; $r = Illuminate\Support\Facades\Process::env(["PGPASSWORD" => $c["password"]])->run(["C:/Program Files/PostgreSQL/18/bin/pg_dump", "-Fc", "-h", $c["host"], "-p", (string) $c["port"], "-U", $c["username"], "-f", $f, $c["database"]]); echo $r->successful() ? $f." (".filesize($f)." octets)" : "ÉCHEC : ".$r->errorOutput();'

Puis vérifier qu'elle se relit : `pg_restore --list "<chemin>" | head`.

Les sauvegardes restent hors du dépôt (~/sauvegardes-couahi) : elles
contiennent des données de clients et ne se commitent jamais.
Rapport : chemin, taille, heure, et la commande de restauration, que seul
l'utilisateur lance : `pg_restore --clean --if-exists -d <base> "<chemin>"`.
Une migration lancée sans sauvegarde se dit en tête du rapport.

## 1. Suite complète chronométrée

- backend/ : `time php artisan test` (Postgres compris).
- frontend/ : `time npm test`, puis `npm run lint` et `npm run build`.

Rapport : les sorties brutes (totaux, échecs) et la durée réelle donnée par
`time`, pas celle affichée par PHPUnit. Au-delà de 8 minutes, appliquer la
règle « Durée de la suite de tests » du CLAUDE.md. Un test rouge : le lot
n'est pas terminé. Jamais un test supprimé ou sauté pour passer.

## 2. Mutations sur chaque garantie du lot

Lister les garanties du lot : un droit, un refus, une condition dans un
UPDATE, un montant relu en base… Pour chacune : casser le code qui la porte,
lancer les tests qui la couvrent, constater qu'au moins un devient rouge,
restaurer. Restaurer depuis la copie lue avant la mutation, jamais par
`git checkout`. Après restauration, `git diff --stat` doit être identique à
avant. Rapport : un tableau garantie / mutation / test devenu rouge /
restauré. Une mutation qui laisse tout vert est un trou : écrire le test
manquant, puis refaire la mutation.

## 3. Dépendances

`composer audit` (backend/) et `npm audit` (frontend/), sorties brutes. Pour
chaque faille : la nommer, dire si notre code l'atteint, proposer la
correction. Aucune mise à jour de dépendance sans accord.

## 4. Revue de sécurité

Lancer le skill security-review sur les changements du lot, avant leur
commit. Rapport : ses conclusions brutes, puis pour chacune « corrigé
(comment) » ou « écarté (pourquoi) ». Reprendre aussi les avertissements du
plugin security-guidance apparus pendant le lot.

## 5. ETAT.md et decisions.md

ETAT.md : selon la règle « Documentation » du CLAUDE.md, d'après le code
vérifié ; la date et le lot en tête. decisions.md : une entrée par choix non
évident du lot (date, décision, pourquoi, alternative écartée). Une entrée
ancienne que le lot rend fausse reçoit « Remplacé le JJ/MM, voir plus bas » :
on ne réécrit pas l'histoire.

## 6. Preuves à 390 px

Chaque écran touché par le lot, à 390 px de large, dans Chrome piloté (voir
« Vérifier un écran dans Chrome piloté » dans CLAUDE.md) : la capture, le
journal réseau (requête, corps, code, réponse) et les SELECT bruts qui
montrent l'effet en base. Les captures vont dans le scratchpad de la
session, jamais dans le dépôt. Un geste que le pilote n'a pas pu livrer se
dit, et passe dans les instructions de l'étape 7.

## 7. Instructions de vérification pour l'utilisateur

Numérotées, sans rien supposer :
- Le lien exact (sous-domaine et port, par exemple
  http://chez-awa.localhost:5173/admin), vérifié : il répond.
- Le compte et le mot de passe, VÉRIFIÉS sur la base de développement avant
  l'envoi. Le lot F a donné un mot de passe faux. Depuis backend/ :

      php artisan tinker --execute '$ok = Illuminate\Support\Facades\Hash::check("motdepasse", App\Models\User::where("email", "x@exemple.test")->value("password")); echo $ok ? "OK" : "FAUX";'

  N'afficher que OK ou FAUX, jamais le hash. Un membre activé par code a
  choisi son propre mot de passe : s'il est inconnu, prendre un autre compte
  ou le dire.
- Chaque bouton à toucher, avec son libellé exact.
- Ce qu'il doit voir après chaque geste (texte, nombre, couleur), et à quoi
  il reconnaît un échec.
- Les gestes tactiles, sur un vrai téléphone.

## 8. Commit, puis arrêt

Commiter une fois la suite verte et les étapes 0 à 7 faites. Ne jamais
pousser : le push attend la validation ÉCRITE de l'utilisateur, après sa
vérification. Un « fais le dépôt » qui arrive avant sa vérification se
signale ; on ne pousse pas. Un défaut trouvé à sa vérification passe avant
le lot suivant.

## Le rapport

Dans cet ordre : sauvegarde, suite (sorties, durées), mutations, audits,
revue de sécurité, ce qui a changé dans ETAT.md et decisions.md, preuves
390 px, instructions de vérification. Ni secret, ni donnée de client réel :
des données de démonstration seulement.
