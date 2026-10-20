/**
 * Greffon Vite : sert le HTML des pages de VITRINE avec leur titre et leurs
 * métadonnées d'aperçu déjà posés (voir src/lib/metadonneesPartage.ts pour
 * le pourquoi). Actif en développement (vite dev) comme sur le build servi
 * par vite preview ; le contenu vient de l'API (GET
 * /api/vitrine/partage?chemin=…), seule source de vérité, résolue par le
 * sous-domaine comme toute la vitrine.
 *
 * Le back-office (/admin, /etablissements) n'est jamais touché : ses pages
 * ne se partagent pas, et leur titre est posé par React. Si l'API ne répond
 * pas (2 s au plus), la page part sans métadonnées plutôt que d'attendre :
 * un aperçu manquant vaut mieux qu'une vitrine lente.
 */
import fs from 'node:fs/promises'
import path from 'node:path'
import type { Connect, Plugin } from 'vite'
import type { MetadonneesPartage } from '../src/lib/metadonneesPartage.ts'
import { injecterMetadonnees } from '../src/lib/metadonneesPartage.ts'

const PREFIXES_HORS_VITRINE = ['/admin', '/etablissements', '/@', '/src/', '/node_modules/', '/api/']

function estPageVitrine(requete: Connect.IncomingMessage): boolean {
  const chemin = (requete.url ?? '/').split('?')[0]
  // Les robots d'aperçu (WhatsApp, Facebook) envoient "*/*", voire rien :
  // exiger "text/html" les priverait justement de l'aperçu.
  const accept = requete.headers.accept ?? '*/*'

  return (
    requete.method === 'GET' &&
    (accept.includes('text/html') || accept.includes('*/*')) &&
    !PREFIXES_HORS_VITRINE.some((prefixe) => chemin.startsWith(prefixe)) &&
    // Un fichier (favicon.svg, assets/x.js...) n'est jamais une page.
    !path.posix.basename(chemin).includes('.')
  )
}

async function recuperer(hote: string, portApi: string, chemin: string): Promise<MetadonneesPartage | null> {
  const nomHote = hote.split(':')[0]

  try {
    const reponse = await fetch(
      `http://${nomHote}${portApi ? `:${portApi}` : ''}/api/vitrine/partage?chemin=${encodeURIComponent(chemin)}`,
      { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(2000) },
    )

    return reponse.ok ? ((await reponse.json()) as { data: MetadonneesPartage }).data : null
  } catch {
    return null
  }
}

export function metadonneesPartage(portApi: string): Plugin {
  let racine = process.cwd()

  function intergiciel(transformer: ((url: string, html: string) => Promise<string>) | null, fichierIndex: () => string) {
    return async (requete: Connect.IncomingMessage, reponse: import('node:http').ServerResponse, suivant: Connect.NextFunction) => {
      if (!estPageVitrine(requete)) {
        return suivant()
      }

      try {
        const url = requete.url ?? '/'
        const chemin = url.split('?')[0]
        let html = await fs.readFile(fichierIndex(), 'utf-8')

        if (transformer) {
          html = await transformer(url, html)
        }

        const hote = requete.headers.host ?? 'localhost'
        const meta = await recuperer(hote, portApi, chemin)

        if (meta) {
          const protocole = (requete.headers['x-forwarded-proto'] as string | undefined) ?? 'http'
          html = injecterMetadonnees(html, meta, `${protocole}://${hote}${url}`)
        }

        reponse.statusCode = 200
        reponse.setHeader('Content-Type', 'text/html; charset=utf-8')
        reponse.end(html)
      } catch (erreur) {
        suivant(erreur)
      }
    }
  }

  return {
    name: 'metadonnees-partage',
    configResolved(config) {
      racine = config.root
    },
    configureServer(serveur) {
      serveur.middlewares.use(
        intergiciel(
          (url, html) => serveur.transformIndexHtml(url, html),
          () => path.join(racine, 'index.html'),
        ),
      )
    },
    configurePreviewServer(serveur) {
      serveur.middlewares.use(intergiciel(null, () => path.join(racine, serveur.config.build.outDir, 'index.html')))
    },
  }
}
