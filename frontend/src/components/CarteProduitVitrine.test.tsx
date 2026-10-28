/**
 * Le prix barré sur la carte de la grille : sur la petite ligne au-dessus
 * du prix, jamais à côté de « À partir de ».
 */
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { ProduitVitrine } from '../api/vitrine'
import { CarteProduitVitrine } from './CarteProduitVitrine'

const pagne: ProduitVitrine = {
  id: 15,
  nom: 'Pagne wax hollandais 6 yards',
  description: null,
  prix: 15000,
  prix_barre: 20000,
  categorie: null,
  disponible: true,
  photo: null,
  variantes: [],
}

function afficher(produit: ProduitVitrine) {
  render(
    <MemoryRouter>
      <CarteProduitVitrine produit={produit} />
    </MemoryRouter>,
  )
}

describe('carte de la vitrine', () => {
  it('prix barré au-dessus du prix', () => {
    afficher(pagne)

    expect(document.querySelector('s')).toHaveTextContent(/20\s000\sFCFA/)
    expect(screen.getByText(/^15\s000\sFCFA$/)).toBeInTheDocument()
  })

  it('variantes à prix différents : « À partir de », sans prix barré', () => {
    afficher({
      ...pagne,
      variantes: [
        { id: 1, nom: '6 yards', prix: 15000, disponible: true },
        { id: 2, nom: '12 yards', prix: 28000, disponible: true },
      ],
    })

    expect(screen.getByText('À partir de')).toBeInTheDocument()
    expect(document.querySelector('s')).toBeNull()
  })
})
