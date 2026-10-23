/**
 * La couleur d'accent par défaut d'un établissement est définie côté
 * serveur (CouleurAccent::PAR_DEFAUT) et transmise par l'API
 * (couleur_accent_par_defaut) : aucun composant ne la recopie. (Le jeton
 * de thème --color-primaire d'index.css porte la même valeur, sans lien
 * avec elle ; Vitest ne lit pas le contenu brut des .css, il n'est donc
 * pas couvert ici.)
 */
import { describe, expect, it } from 'vitest'

const sources = import.meta.glob(['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe("couleur d'accent par défaut", () => {
  it("n'est écrite en dur dans aucun composant", () => {
    expect(Object.keys(sources).length).toBeGreaterThan(50)
    expect(Object.keys(sources).filter((fichier) => /146c43/i.test(sources[fichier]))).toEqual([])
  })
})
