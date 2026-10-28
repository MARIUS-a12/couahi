import { describe, expect, it } from 'vitest'
import { tempsRelatif } from './tempsRelatif'

const maintenant = new Date('2026-10-28T12:00:00Z')
const ilYa = (secondes: number) => new Date(maintenant.getTime() - secondes * 1000)

describe('tempsRelatif', () => {
  it.each([
    [20, 'à l’instant'],
    [12 * 60, 'il y a 12 min'],
    [3 * 3600 + 120, 'il y a 3 h'],
    [90 * 60, 'il y a 1 h'],
    [30 * 3600, 'hier'],
    [8 * 86400, 'il y a 8 jours'],
  ])('%i s → %s', (secondes, attendu) => {
    expect(tempsRelatif(ilYa(secondes), maintenant)).toBe(attendu)
  })

  it('une date future (horloges décalées) reste « à l’instant »', () => {
    expect(tempsRelatif(ilYa(-30), maintenant)).toBe('à l’instant')
  })
})
