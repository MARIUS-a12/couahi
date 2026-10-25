/**
 * Client HTTP central de l'app : une seule instance axios, configurée pour
 * Sanctum en mode SPA (cookies + jeton CSRF). Tous les appels API (api/*.ts)
 * passent par ici — ne pas créer d'autre instance axios ailleurs.
 */
import axios from 'axios'

/**
 * L'adresse de l'API n'est JAMAIS écrite en dur : chaque établissement a
 * son propre sous-domaine (chez-awa.localhost, maquis-du-port.localhost,
 * …), c'est lui qui détermine l'établissement, et l'API vit toujours sur
 * ce MÊME sous-domaine — seul le port diffère en développement
 * (VITE_API_PORT=8000 ; vide en production, où l'API est sur le port
 * standard). Une adresse fixe (ex. VITE_API_URL=http://chez-awa...)
 * enverrait toutes les requêtes au même établissement quel que soit le
 * sous-domaine réellement ouvert dans le navigateur — cassant le
 * multi-établissements dès qu'on ouvre le site sur un autre sous-domaine.
 */
const port = import.meta.env.VITE_API_PORT
const baseURL = `${window.location.protocol}//${window.location.hostname}${port ? `:${port}` : ''}`

export const client = axios.create({
  baseURL,
  withCredentials: true,
  headers: { Accept: 'application/json' },
  // Le frontend (port 5173) et l'API (port 8000) sont deux origines
  // distinctes même sur le même sous-domaine : sans withXSRFToken, axios
  // n'attache le cookie XSRF-TOKEN à l'en-tête X-XSRF-TOKEN que pour les
  // requêtes same-origin.
  withXSRFToken: true,
})

let cookieCsrfEnCours: Promise<unknown> | null = null

function possedeCookieCsrf(): boolean {
  return document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))
}

/**
 * Sanctum (mode SPA) exige un cookie XSRF-TOKEN déjà posé avant toute
 * requête qui modifie l'état (POST/PUT/PATCH/DELETE) : sans cet appel
 * préalable à /sanctum/csrf-cookie, la requête échoue avec 419 (jeton CSRF
 * manquant), y compris la toute première tentative de connexion.
 */
function assurerCookieCsrf() {
  if (possedeCookieCsrf()) {
    return Promise.resolve()
  }

  cookieCsrfEnCours ??= client.get('/sanctum/csrf-cookie').finally(() => {
    cookieCsrfEnCours = null
  })

  return cookieCsrfEnCours
}

client.interceptors.request.use(async (config) => {
  const methode = (config.method ?? 'get').toLowerCase()

  if (methode !== 'get' && methode !== 'head') {
    await assurerCookieCsrf()
  }

  return config
})

/**
 * Un cookie XSRF-TOKEN présent mais périmé (la session a disparu côté
 * serveur : mise en ligne, cache vidé, session expirée) fait refuser chaque
 * écriture par un 419, connexion comprise. assurerCookieCsrf() ne le
 * renouvelle pas, puisque le cookie existe. Le commerçant restait bloqué
 * sur « Impossible de contacter le serveur » jusqu'à vider ses cookies
 * (vu à 390 px sur le back-office). Un 419 redemande donc un jeton neuf et
 * rejoue la requête, une seule fois : un second 419 est une vraie erreur.
 */
client.interceptors.response.use(undefined, async (erreur) => {
  const config = erreur?.config as (typeof erreur.config & { jetonRenouvele?: boolean }) | undefined

  if (!axios.isAxiosError(erreur) || erreur.response?.status !== 419 || !config || config.jetonRenouvele) {
    throw erreur
  }

  config.jetonRenouvele = true
  await client.get('/sanctum/csrf-cookie')

  return client.request(config)
})
