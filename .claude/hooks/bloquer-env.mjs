/**
 * Hook PreToolUse : empêche Claude de lire ou de modifier un fichier .env,
 * et d'afficher un secret par les détours évidents (variables
 * d'environnement, phpinfo, config:show sans filtre, tinker qui affiche
 * env() ou un mot de passe). Il n'ouvre aussi, du serveur MCP de Boost, que
 * les cinq outils d'une liste blanche.
 *
 * Toute modification de ce fichier se prépare dans une copie, passe le banc
 * d'essai, puis remplace ce fichier d'un coup : une version à moitié écrite
 * bloque tout, y compris sa propre correction (c'est arrivé le 31/10).
 *
 * Les secrets du projet vivent dans backend/.env et frontend/.env ; Laravel,
 * Vite et les tests les lisent eux-mêmes, Claude n'en a jamais besoin.
 * .env.example, sans secret, reste permis.
 *
 * C'est un garde-fou contre l'accident, pas une barrière contre qui
 * chercherait à le contourner : il lit le texte d'une commande, il ne sait
 * pas ce qu'elle fera (voir CLAUDE.md, « Fichiers et secrets interdits »).
 *
 * Code 2 : Claude Code refuse l'appel et montre la raison à Claude. Il
 * échoue fermé : une entrée illisible bloque ici, et la commande de
 * .claude/settings.json transforme toute autre panne (Node absent, script
 * en erreur) en code 2. Le prix : un script cassé bloque tous les outils,
 * y compris la correction de ce fichier, que l'utilisateur fait alors à la
 * main.
 */
import { readFileSync } from 'node:fs'

// .env et .env.<suffixe>, sauf .env.example ; précédé d'un séparateur de
// chemin ou de commande, suivi de la fin, d'un séparateur ou d'un joker.
const FICHIER_ENV = /(^|[\\/\s'"`=:;|&<>(])\.env(?!\.example\b)(\.[\w.-]+)?(?=$|[\s'"`;|&<>)\\/*?\]])/i

// Commandes qui affichent tout l'environnement du processus.
const VIDAGE_ENVIRONNEMENT = [
  /\bprintenv\b/i,
  /(^|[;&|(\n]\s*)env\s*($|[;&|>)\n])/,
  /(^|[;&|(\n]\s*)(export|set)\s*($|[;&|>)\n])/,
  /\bexport\s+-p\b/,
  /\bdeclare\s+-[a-zA-Z]*[xp]/,
  /\bcompgen\s+-[ve]\b/,
  /\/proc\/[\w]+\/environ\b/,
  /\b(Get-ChildItem|gci|ls|dir|Get-Item)\s+env:/i,
  /\$env:\w+\b(?!\s*=)/i,
  /\[(System\.)?Environment\]::GetEnvironmentVariables?\b/i,
  /\bphpinfo\b/i,
  /\bphp(\.exe)?\s+(-\S+\s+)*-i\b/,
  /\benv:decrypt\b/,
]

// Mots qui désignent un secret, dans une clé de configuration ou dans une
// instruction PHP qui affiche.
const MOT_SECRET = /(password|passwd|pwd|secret|token|api[_-]?key|cle[_-]?api|\bkey\b|credential|dsn|cinetpay)/i
const MOT_SECRET_CLE = /(pass|pwd|secret|token|key|cle|credential|dsn|url|auth|cinetpay)/i

// Dernier segment d'une clé de configuration qui désigne un groupe où vit
// un secret : config:show sur ce groupe l'afficherait.
const GROUPES_AVEC_SECRET = new Set([
  'connections', 'pgsql', 'mysql', 'mariadb', 'sqlsrv', 'sqlite', 'redis', 'default',
  'cache', 'options', 'stores', 'memcached', 'dynamodb', 'servers', 'disks', 's3',
  'mailers', 'smtp', 'postmark', 'resend', 'ses', 'sqs', 'channels', 'slack',
  'papertrail', 'notifications', 'broadcasting', 'reverb', 'pusher', 'ably',
])

// Liste blanche des outils du serveur MCP de Boost, validée par
// l'utilisateur le 31/10. Fermée par défaut : un outil absent, y compris un
// outil ajouté par une future version de Boost, est refusé. Les six refusés
// (database-query, tinker, read-log-entries, last-error, browser-logs,
// record-rule) lisent toutes les boutiques, les journaux, exécutent du code
// ou écrivent des règles ailleurs que dans CLAUDE.md.
const PREFIXE_BOOST = 'mcp__laravel-boost__'
const OUTILS_BOOST_AUTORISES = new Set([
  'search-docs', 'application-info', 'database-connections', 'database-schema', 'get-absolute-url',
])

const AFFICHAGE_PHP = /\b(echo|print|printf|print_r|var_dump|var_export|dump|dd|Log::\w+)\b/

function refuser(raison) {
  process.stderr.write(`Bloqué : ${raison} Aucun secret ne s'affiche : ni fichier .env, ni variable d'environnement, ni mot de passe, clé ou jeton (CLAUDE.md, « Fichiers et secrets interdits »). Si la valeur est vraiment nécessaire, demander à l'utilisateur.\n`)
  process.exit(2)
}

function verifierConfigShow(commande) {
  for (const occurrence of commande.matchAll(/config:show\b([^;|&>\n]*)/g)) {
    const arguments_ = occurrence[1].trim().split(/\s+/).filter((mot) => mot && !mot.startsWith('-'))
    const cle = (arguments_[0] ?? '').replace(/^['"]|['"]$/g, '')
    const dernierSegment = cle.split('.').pop() ?? ''

    if (!cle.includes('.')) {
      refuser(`config:show sans clé précise (« ${cle || 'aucune'} ») afficherait tout un fichier de configuration, secrets compris.`)
    }
    if (MOT_SECRET_CLE.test(cle) || GROUPES_AVEC_SECRET.has(dernierSegment.toLowerCase())) {
      refuser(`config:show ${cle} afficherait un secret, ou un groupe qui en contient un.`)
    }
  }
}

function verifierCodePhp(commande) {
  if (!/\btinker\b|\bphp(\.exe)?\s+(-\S+\s+)*-r\b/.test(commande)) {
    return
  }
  if (/(?<![:>\w])env\s*\(|\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b|\bDotenv\b|\bconfig\s*\(\s*\)/i.test(commande)) {
    refuser('du code PHP qui lit l\'environnement (env(), getenv(), $_ENV, $_SERVER, config() entier).')
  }
  for (const instruction of commande.split(';')) {
    if (AFFICHAGE_PHP.test(instruction) && (/\bconfig\s*\(/.test(instruction) || MOT_SECRET.test(instruction))) {
      refuser('du code PHP qui affiche une valeur de configuration ou un secret.')
    }
  }
}

let entree
try {
  entree = JSON.parse(readFileSync(0, 'utf8'))
} catch {
  refuser('hook bloquer-env : entrée illisible, appel refusé par prudence.')
}

const outil = entree.tool_name
const params = entree.tool_input ?? {}

if (outil === 'Bash' || outil === 'PowerShell') {
  const commande = String(params.command ?? '')

  if (FICHIER_ENV.test(commande)) {
    refuser(`${outil} sur un fichier .env.`)
  }
  for (const motif of VIDAGE_ENVIRONNEMENT) {
    if (motif.test(commande)) {
      refuser(`${outil} afficherait des variables d'environnement ou la configuration de PHP.`)
    }
  }
  verifierConfigShow(commande)
  verifierCodePhp(commande)
} else if (outil.startsWith(PREFIXE_BOOST)) {
  if (!OUTILS_BOOST_AUTORISES.has(outil.slice(PREFIXE_BOOST.length))) {
    refuser(`l'outil Boost « ${outil.slice(PREFIXE_BOOST.length)} » n'est pas dans la liste des cinq autorisés (CLAUDE.md, « Outils du serveur MCP de Boost »).`)
  }
} else {
  const cibles = [params.file_path, params.notebook_path, params.path, params.glob]

  if (cibles.some((cible) => typeof cible === 'string' && FICHIER_ENV.test(cible))) {
    refuser(`${outil} sur un fichier .env.`)
  }
}
