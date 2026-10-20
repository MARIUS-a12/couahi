import { describe, expect, it } from 'vitest'
import { injecterMetadonnees } from './metadonneesPartage'

const INDEX = `<!doctype html>
<html lang="fr">
  <head>
    <meta charset="UTF-8" />
    <title>Boutique en ligne</title>
  </head>
  <body><div id="root"></div></body>
</html>`

describe('métadonnées de partage', () => {
  it("remplace le titre par défaut et pose l'aperçu Open Graph", () => {
    const html = injecterMetadonnees(
      INDEX,
      { titre: 'Wax Adjamé', description: "Pagnes du marché d'Adjamé.", image: 'http://localhost:8000/storage/medias/logo.jpg' },
      'http://wax-adjame.localhost:5173/',
    )

    expect(html.match(/<title>/g)).toHaveLength(1)
    expect(html).toContain('<title>Wax Adjamé</title>')
    expect(html).toContain('<meta property="og:title" content="Wax Adjamé" />')
    expect(html).toContain(`<meta property="og:description" content="Pagnes du marché d'Adjamé." />`)
    expect(html).toContain('<meta property="og:image" content="http://localhost:8000/storage/medias/logo.jpg" />')
    expect(html).toContain('<meta property="og:url" content="http://wax-adjame.localhost:5173/" />')
  })

  it("sans logo, pas de balise og:image", () => {
    const html = injecterMetadonnees(INDEX, { titre: 'Chez Awa', description: 'Épicerie', image: null }, 'http://x/')

    expect(html).not.toContain('og:image')
  })

  it('échappe ce que le commerçant a saisi : un nom ne ferme jamais une balise', () => {
    const html = injecterMetadonnees(
      INDEX,
      { titre: '</title><script>alert(1)</script>', description: 'a" onload="x', image: null },
      'http://x/',
    )

    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html).toContain('&lt;/title&gt;&lt;script&gt;')
    expect(html).toContain('content="a&quot; onload=&quot;x"')
  })
})
