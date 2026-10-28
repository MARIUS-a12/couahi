import { describe, expect, it } from 'vitest'
import { separerValeurs } from './valeursOption'

describe('separerValeurs', () => {
  it('une virgule sépare deux valeurs', () => {
    expect(separerValeurs('blanc, noir')).toEqual(['blanc', 'noir'])
  })

  it('vides et doublons ignorés, ordre gardé', () => {
    expect(separerValeurs(' Blanc ,, noir, blanc ,')).toEqual(['Blanc', 'noir'])
  })

  it('sans virgule, une seule valeur', () => {
    expect(separerValeurs('Gris chiné')).toEqual(['Gris chiné'])
  })
})
