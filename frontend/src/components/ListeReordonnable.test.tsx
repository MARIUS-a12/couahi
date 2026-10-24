/**
 * B2 — l'ordre des valeurs se change sans souris : au clavier (flèches sur
 * la poignée), avec une annonce pour les lecteurs d'écran. Le geste au
 * doigt est prouvé dans un vrai navigateur (événements tactiles), jsdom
 * ne calculant aucune position.
 */
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ListeReordonnable } from './ListeReordonnable'

const tailles = [
  { id: 1, libelle: 'S' },
  { id: 2, libelle: 'M' },
  { id: 3, libelle: 'L' },
]

function afficher() {
  const onReordonner = vi.fn()
  render(
    <ListeReordonnable
      elements={tailles}
      libelle={(taille) => taille.libelle}
      onReordonner={onReordonner}
      rendre={(taille, poignee) => (
        <>
          {poignee}
          <span>{taille.libelle}</span>
        </>
      )}
    />,
  )

  return onReordonner
}

describe('ListeReordonnable', () => {
  it('flèche haut remonte l’élément et annonce sa nouvelle position', () => {
    const onReordonner = afficher()

    fireEvent.keyDown(screen.getByRole('button', { name: 'Déplacer L (flèches haut et bas)' }), { key: 'ArrowUp' })

    expect(onReordonner).toHaveBeenCalledWith([1, 3, 2])
    expect(screen.getByText('L : position 2 sur 3.')).toBeInTheDocument()
  })

  it('flèche bas descend l’élément', () => {
    const onReordonner = afficher()

    fireEvent.keyDown(screen.getByRole('button', { name: 'Déplacer S (flèches haut et bas)' }), { key: 'ArrowDown' })

    expect(onReordonner).toHaveBeenCalledWith([2, 1, 3])
  })

  it('aux extrémités, rien n’est envoyé', () => {
    const onReordonner = afficher()

    fireEvent.keyDown(screen.getByRole('button', { name: 'Déplacer S (flèches haut et bas)' }), { key: 'ArrowUp' })
    fireEvent.keyDown(screen.getByRole('button', { name: 'Déplacer L (flèches haut et bas)' }), { key: 'ArrowDown' })

    expect(onReordonner).not.toHaveBeenCalled()
  })
})
