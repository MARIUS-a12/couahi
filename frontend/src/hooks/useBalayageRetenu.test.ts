import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useBalayageRetenu } from './useBalayageRetenu'

function conteneur(scrollLeft: number) {
  const element = document.createElement('div')
  Object.defineProperty(element, 'clientWidth', { value: 358 })
  Object.defineProperty(element, 'scrollWidth', { value: 393 })
  element.scrollLeft = scrollLeft
  const { result } = renderHook(() => useBalayageRetenu<HTMLDivElement>())
  result.current(element)

  return element
}

function toucher(element: HTMLElement, type: 'touchstart' | 'touchmove', x: number, y: number) {
  const evenement = new Event(type, { cancelable: true, bubbles: true })
  Object.defineProperty(evenement, 'touches', { value: [{ clientX: x, clientY: y }] })
  element.dispatchEvent(evenement)

  return evenement.defaultPrevented
}

function balayer(element: HTMLElement, dx: number, dy: number) {
  toucher(element, 'touchstart', 200, 300)

  return [1, 2, 3].map((i) => toucher(element, 'touchmove', 200 + (dx * i) / 3, 300 + (dy * i) / 3)).some(Boolean)
}

describe('useBalayageRetenu', () => {
  it('retient le balayage vers la droite quand la grille est déjà calée à gauche (sinon : page précédente)', () => {
    expect(balayer(conteneur(0), 120, 4)).toBe(true)
  })

  it('laisse la grille défiler quand elle le peut', () => {
    expect(balayer(conteneur(0), -120, 4)).toBe(false)
    expect(balayer(conteneur(20), 120, 4)).toBe(false)
  })

  it('retient le balayage vers la gauche au bord droit', () => {
    expect(balayer(conteneur(35), -120, 4)).toBe(true)
  })

  it('ne touche jamais un balayage vertical : la page défile', () => {
    expect(balayer(conteneur(0), 10, 200)).toBe(false)
  })
})
