import { describe, expect, it } from 'vitest'
import { optionNumerique, valeursDePlage } from './plage'

describe('valeursDePlage', () => {
  it('crée les valeurs intermédiaires, dans l’ordre, bornes comprises', () => {
    expect(valeursDePlage('39', '45')).toEqual({ valeurs: ['39', '40', '41', '42', '43', '44', '45'] })
  })

  it('suit l’ordre de saisie quand la fin est plus petite', () => {
    expect(valeursDePlage(' 3 ', '1')).toEqual({ valeurs: ['3', '2', '1'] })
  })

  it('refuse ce qui n’est pas un nombre entier', () => {
    expect(valeursDePlage('38,5', '42')).toHaveProperty('erreur')
    expect(valeursDePlage('S', 'XL')).toHaveProperty('erreur')
  })

  it('refuse plus de 50 valeurs, avec le nombre que cela ferait', () => {
    expect(valeursDePlage('1', '50')).toHaveProperty('valeurs')
    expect(valeursDePlage('1', '100000')).toEqual({ erreur: 'Cela ferait 100000 valeurs : 50 au plus à la fois.' })
  })
})

describe('optionNumerique', () => {
  it('ne propose la plage qu’à une option vide ou purement numérique', () => {
    expect(optionNumerique([])).toBe(true)
    expect(optionNumerique(['39', '40'])).toBe(true)
    expect(optionNumerique(['39', 'Taille unique'])).toBe(false)
    expect(optionNumerique(['Bleu ciel'])).toBe(false)
  })
})
