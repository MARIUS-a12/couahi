/**
 * Injecte dans le HTML de la vitrine le titre et les métadonnées d'aperçu
 * (Open Graph) d'un lien partagé. WhatsApp, Facebook et consorts ne lisent
 * que le HTML renvoyé par le serveur, jamais ce que React affiche ensuite :
 * c'est la seule façon pour qu'un lien partagé montre le nom de la boutique,
 * sa description et son logo. Fonction pure — appelée par le greffon Vite
 * (vite/metadonneesPartage.ts), testée seule.
 *
 * Les valeurs viennent de l'API (MetadonneesPartage côté serveur), donc du
 * commerçant : tout est échappé, un nom de boutique ne doit jamais pouvoir
 * fermer une balise.
 */
export type MetadonneesPartage = {
  titre: string
  description: string
  image: string | null
}

function echapper(valeur: string): string {
  return valeur.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

export function injecterMetadonnees(html: string, meta: MetadonneesPartage, url: string): string {
  const balises = [
    `<title>${echapper(meta.titre)}</title>`,
    `<meta name="description" content="${echapper(meta.description)}" />`,
    `<meta property="og:type" content="website" />`,
    `<meta property="og:title" content="${echapper(meta.titre)}" />`,
    `<meta property="og:description" content="${echapper(meta.description)}" />`,
    `<meta property="og:url" content="${echapper(url)}" />`,
    ...(meta.image
      ? [`<meta property="og:image" content="${echapper(meta.image)}" />`, `<meta name="twitter:card" content="summary" />`]
      : []),
  ].join('\n    ')

  // Le <title> par défaut de index.html est remplacé, jamais doublé.
  return html.replace(/\s*<title>[\s\S]*?<\/title>/, '').replace('</head>', `    ${balises}\n  </head>`)
}
