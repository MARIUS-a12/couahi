import { describe, expect, it } from 'vitest'
import { prixAnnonce } from './prixVitrine'

const v = (prix: number, disponible = true) => ({ id: prix, nom: String(prix), prix, disponible })

describe('prix annoncé avant le choix d’une variante', () => {
  it('sans variante : le prix du produit, sans mention', () => {
    expect(prixAnnonce(15000, [])).toEqual({ montant: 15000, aPartirDe: false })
  })

  it('variantes à prix différents : « à partir de » la moins chère', () => {
    expect(prixAnnonce(1000, [v(1000), v(4500), v(21000)])).toEqual({ montant: 1000, aPartirDe: true })
  })

  it('variantes toutes au même prix : le prix seul, la mention n’apporterait rien', () => {
    expect(prixAnnonce(2000, [v(2500), v(2500)])).toEqual({ montant: 2500, aPartirDe: false })
  })

  it('jamais le prix d’une variante épuisée tant qu’une autre est disponible', () => {
    expect(prixAnnonce(6000, [v(6000, false), v(14000), v(26000)])).toEqual({ montant: 14000, aPartirDe: true })
  })

  it('toutes épuisées : la moins chère de toutes, toujours annoncée « à partir de »', () => {
    expect(prixAnnonce(6000, [v(6000, false), v(14000, false)])).toEqual({ montant: 6000, aPartirDe: true })
  })
})
