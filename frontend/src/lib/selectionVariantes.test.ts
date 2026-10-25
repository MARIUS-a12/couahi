import { describe, expect, it } from 'vitest'
import type { VarianteVitrineDetail } from '../api/vitrine'
import { basculerValeur, etatValeur, photoDeLaLigne, photoDeSelection, varianteDeSelection, variantesCompatibles } from './selectionVariantes'

// Les sandales de la démo : Pointure (option 1) × Coloris (option 2). Le
// noir existe du 39 au 45, le marron du 40 au 44 ; le 42 noir est épuisé.
const POINTURE = 1
const COLORIS = 2
const NOIR = 100
const MARRON = 101
const PHOTO_NOIR = 7
const PHOTO_MARRON = 8

function sandales(): VarianteVitrineDetail[] {
  const variantes: VarianteVitrineDetail[] = []
  for (const pointure of [39, 40, 41, 42, 43, 44, 45]) {
    variantes.push({ id: pointure, nom: `${pointure} / Noir`, prix: pointure === 45 ? 19500 : 18000, disponible: pointure !== 42, valeurs: [pointure, NOIR], photo_id: PHOTO_NOIR })
  }
  for (const pointure of [40, 41, 42, 43, 44]) {
    variantes.push({ id: 1000 + pointure, nom: `${pointure} / Marron`, prix: 18000, disponible: true, valeurs: [pointure, MARRON], photo_id: PHOTO_MARRON })
  }
  return variantes
}

describe('sélecteur par dimension', () => {
  it('une valeur sans combinaison avec le choix en cours est inexistante, pas cachée', () => {
    const selection = { [COLORIS]: MARRON }

    expect(etatValeur(sandales(), selection, POINTURE, 39)).toBe('inexistante')
    expect(etatValeur(sandales(), selection, POINTURE, 45)).toBe('inexistante')
    expect(etatValeur(sandales(), selection, POINTURE, 40)).toBe('disponible')
  })

  it('sans aucun choix, toute valeur portée par une variante existe', () => {
    for (const pointure of [39, 40, 41, 42, 43, 44, 45]) {
      expect(etatValeur(sandales(), {}, POINTURE, pointure)).not.toBe('inexistante')
    }
  })

  it('une valeur se juge contre les AUTRES lignes, jamais contre la valeur de sa propre ligne', () => {
    // Le 40 est choisi : le 39 reste possible (en noir), il remplacerait le 40.
    expect(etatValeur(sandales(), { [POINTURE]: 40 }, POINTURE, 39)).toBe('disponible')
    // Le 39 choisi : le marron n'existe pas en 39.
    expect(etatValeur(sandales(), { [POINTURE]: 39 }, COLORIS, MARRON)).toBe('inexistante')
  })

  it('une combinaison qui existe mais dont tout est épuisé est épuisée, pas inexistante', () => {
    expect(etatValeur(sandales(), { [COLORIS]: NOIR }, POINTURE, 42)).toBe('epuisee')
    // Le 42 existe aussi en marron, disponible : sans coloris choisi, il l'est.
    expect(etatValeur(sandales(), {}, POINTURE, 42)).toBe('disponible')
  })

  it('la variante n’est désignée qu’une fois chaque ligne remplie', () => {
    expect(varianteDeSelection(sandales(), 2, { [POINTURE]: 41 })).toBeNull()
    expect(varianteDeSelection(sandales(), 2, { [POINTURE]: 41, [COLORIS]: MARRON })?.id).toBe(1041)
    expect(varianteDeSelection([], 0, {})).toBeNull()
  })

  it('recliquer la valeur choisie la retire', () => {
    const choisie = basculerValeur({}, COLORIS, NOIR)
    expect(choisie).toEqual({ [COLORIS]: NOIR })
    expect(basculerValeur(choisie, COLORIS, MARRON)).toEqual({ [COLORIS]: MARRON })
    expect(basculerValeur(choisie, COLORIS, NOIR)).toEqual({})
  })

  it('les variantes compatibles suivent une sélection partielle', () => {
    expect(variantesCompatibles(sandales(), { [COLORIS]: MARRON })).toHaveLength(5)
    expect(variantesCompatibles(sandales(), {})).toHaveLength(12)
  })
})

describe('photo du coloris choisi', () => {
  it('le coloris seul suffit : toutes ses variantes portent la même photo', () => {
    expect(photoDeSelection(sandales(), { [COLORIS]: MARRON })).toBe(PHOTO_MARRON)
    expect(photoDeSelection(sandales(), { [COLORIS]: NOIR })).toBe(PHOTO_NOIR)
  })

  it('une pointure portée dans les deux coloris ne tranche rien', () => {
    expect(photoDeSelection(sandales(), { [POINTURE]: 41 })).toBeNull()
    expect(photoDeSelection(sandales(), {})).toBeNull()
  })

  it('une pointure qui n’existe qu’en noir montre la photo du noir', () => {
    expect(photoDeSelection(sandales(), { [POINTURE]: 45 })).toBe(PHOTO_NOIR)
  })

  it('des variantes sans photo n’en imposent aucune', () => {
    const sansPhoto = sandales().map((variante) => ({ ...variante, photo_id: null }))
    expect(photoDeSelection(sansPhoto, { [COLORIS]: NOIR })).toBeNull()
  })
})

describe('vignette à côté de la ligne choisie', () => {
  const coloris = { id: COLORIS, valeurs: [{ id: NOIR }, { id: MARRON }] }
  const pointure = { id: POINTURE, valeurs: [39, 40, 41, 42, 43, 44, 45].map((id) => ({ id })) }

  it('le coloris pilote la photo : sa vignette suit la valeur choisie', () => {
    expect(photoDeLaLigne(sandales(), coloris, { [COLORIS]: MARRON })).toBe(PHOTO_MARRON)
    expect(photoDeLaLigne(sandales(), coloris, { [COLORIS]: NOIR, [POINTURE]: 41 })).toBe(PHOTO_NOIR)
  })

  it('rien tant que la ligne n’a pas de valeur choisie', () => {
    expect(photoDeLaLigne(sandales(), coloris, { [POINTURE]: 41 })).toBeNull()
  })

  it('la pointure ne pilote pas la photo, même un 45 qui n’existe qu’en noir', () => {
    expect(photoDeLaLigne(sandales(), pointure, { [POINTURE]: 45 })).toBeNull()
  })

  it('une seule photo pour tout le produit ne pilote rien', () => {
    const memePhoto = sandales().map((variante) => ({ ...variante, photo_id: PHOTO_NOIR }))
    expect(photoDeLaLigne(memePhoto, coloris, { [COLORIS]: MARRON })).toBeNull()
  })
})
