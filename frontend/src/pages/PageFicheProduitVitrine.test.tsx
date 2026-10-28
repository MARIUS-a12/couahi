/**
 * B3 — la fiche d'un produit à variantes : un sélecteur par dimension, une
 * valeur impossible désactivée mais visible, la photo du coloris choisi,
 * « À partir de » jusqu'au choix complet. Et un produit sans option qui
 * reste exactement comme avant : ni sélecteur, ni garde sur les boutons.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PhotoVitrine, ProduitVitrineDetail, VarianteVitrineDetail } from '../api/vitrine'
import { PageFicheProduitVitrine } from './PageFicheProduitVitrine'

let produitAffiche: ProduitVitrineDetail

vi.mock('../hooks/useProduitVitrine', () => ({
  useProduitVitrine: () => ({ data: produitAffiche, isPending: false, isError: false, error: null, refetch: vi.fn() }),
}))
vi.mock('../hooks/useEtablissementVitrine', () => ({
  useEtablissementVitrine: () => ({ data: { mode_paiement: null } }),
}))

function photo(id: number): PhotoVitrine {
  const format = { webp: null, jpg: `/medias/${id}.jpg` }
  return { id, ordre: id, est_principal: id === 7, vignette: format, moyenne: format, grande: format }
}

const NOIR = 100
const MARRON = 101

/** Les sandales de wax-adjame : le noir du 39 au 45, le marron du 40 au 44. */
function sandales(): ProduitVitrineDetail {
  const variantes: VarianteVitrineDetail[] = []
  for (const pointure of [39, 40, 41, 42, 43, 44, 45]) {
    variantes.push({ id: pointure, nom: `${pointure} / Noir`, prix: pointure === 45 ? 19500 : 18000, disponible: pointure !== 42, valeurs: [pointure, NOIR], photo_id: 7 })
  }
  for (const pointure of [40, 41, 42, 43, 44]) {
    variantes.push({ id: 1000 + pointure, nom: `${pointure} / Marron`, prix: 18000, disponible: true, valeurs: [pointure, MARRON], photo_id: 8 })
  }

  return {
    id: 20,
    nom: 'Sandales en cuir tressé',
    description: null,
    prix: 18000,
    prix_barre: null,
    categorie: null,
    disponible: true,
    photos: [photo(7), photo(8)],
    options: [
      { id: 1, nom: 'Pointure', valeurs: [39, 40, 41, 42, 43, 44, 45].map((p) => ({ id: p, libelle: String(p) })) },
      { id: 2, nom: 'Coloris', valeurs: [{ id: NOIR, libelle: 'Noir' }, { id: MARRON, libelle: 'Marron' }] },
    ],
    variantes,
  }
}

function afficher() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/produit/20']}>
        <Routes>
          <Route path="/produit/:id" element={<PageFicheProduitVitrine />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const ligne = (nom: string) => screen.getByRole('group', { name: new RegExp(`^${nom}`) })
const valeur = (nomLigne: string, libelle: string) => within(ligne(nomLigne)).getByRole('button', { name: new RegExp(`^${libelle}`) })
const photoPrincipale = () => screen.getByAltText('Sandales en cuir tressé')

describe('fiche d’un produit à variantes', () => {
  beforeEach(() => {
    produitAffiche = sandales()
  })

  it('une ligne par dimension, valeurs dans l’ordre du commerçant', () => {
    afficher()

    const lignes = screen.getAllByRole('group').map((groupe) => groupe.textContent)
    expect(lignes[0]).toMatch(/^Pointure/)
    expect(lignes[1]).toMatch(/^Coloris/)
    expect(within(ligne('Coloris')).getAllByRole('button').map((b) => b.textContent)).toEqual(['Noir', 'Marron'])
    expect(within(ligne('Pointure')).getAllByRole('button').map((b) => b.textContent)).toEqual(['39', '40', '41', '42', '43', '44', '45'])
    // Plus aucun bouton à plat « 39 / Noir ».
    expect(screen.queryByRole('button', { name: '39 / Noir' })).not.toBeInTheDocument()
  })

  it('le marron choisi désactive le 39 et le 45 sans les cacher, et le dit', () => {
    afficher()
    fireEvent.click(valeur('Coloris', 'Marron'))

    expect(valeur('Pointure', '39')).toBeDisabled()
    expect(valeur('Pointure', '45')).toBeDisabled()
    expect(valeur('Pointure', '40')).toBeEnabled()
    expect(screen.getByText(/39, 45 : n’existent pas\s+en Marron\./)).toBeInTheDocument()
  })

  it('recliquer le marron le retire et rend le 39', () => {
    afficher()
    fireEvent.click(valeur('Coloris', 'Marron'))
    fireEvent.click(valeur('Coloris', 'Marron'))

    expect(valeur('Pointure', '39')).toBeEnabled()
  })

  it('la photo principale devient celle du coloris choisi', () => {
    afficher()
    expect(photoPrincipale()).toHaveAttribute('src', '/medias/7.jpg')

    fireEvent.click(valeur('Coloris', 'Marron'))
    expect(photoPrincipale()).toHaveAttribute('src', '/medias/8.jpg')

    fireEvent.click(valeur('Coloris', 'Noir'))
    expect(photoPrincipale()).toHaveAttribute('src', '/medias/7.jpg')
  })

  it('la vignette du coloris choisi s’affiche à côté de la ligne, pas à côté de la pointure', () => {
    afficher()
    const vignetteDe = (nom: string) => ligne(nom).querySelector('img')
    expect(vignetteDe('Coloris')).toBeNull()

    fireEvent.click(valeur('Coloris', 'Marron'))
    expect(vignetteDe('Coloris')).toHaveAttribute('src', '/medias/8.jpg')

    fireEvent.click(valeur('Coloris', 'Noir'))
    fireEvent.click(valeur('Pointure', '45'))
    expect(vignetteDe('Coloris')).toHaveAttribute('src', '/medias/7.jpg')
    expect(vignetteDe('Pointure')).toBeNull()
  })

  it('« À partir de » jusqu’au choix complet, puis le prix exact', () => {
    afficher()
    expect(screen.getByText('À partir de')).toBeInTheDocument()

    fireEvent.click(valeur('Coloris', 'Noir'))
    expect(screen.getByText('À partir de')).toBeInTheDocument()

    fireEvent.click(valeur('Pointure', '45'))
    expect(screen.queryByText('À partir de')).not.toBeInTheDocument()
    expect(screen.getByText(/19\s500\sFCFA/)).toBeInTheDocument()
  })

  it('une combinaison épuisée reste choisissable et bloque l’achat', () => {
    afficher()
    fireEvent.click(valeur('Coloris', 'Noir'))
    fireEvent.click(valeur('Pointure', '42'))

    expect(screen.getByText('Cette variante est épuisée.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Ajouter au panier/ })).toBeDisabled()
  })

  it('sans choix, les boutons d’achat disent quoi choisir', () => {
    afficher()
    fireEvent.click(valeur('Coloris', 'Noir'))

    expect(screen.getByText('Choisissez d’abord ci-dessus : pointure.')).toBeInTheDocument()
  })
})

describe('prix barré', () => {
  const barre = () => document.querySelector('s')

  it('jamais à côté de « À partir de », affiché dès le choix complet', () => {
    produitAffiche = { ...sandales(), prix_barre: 19000 }
    afficher()
    expect(barre()).toBeNull()

    fireEvent.click(valeur('Coloris', 'Noir'))
    fireEvent.click(valeur('Pointure', '40'))

    expect(barre()).toHaveTextContent(/19\s000\sFCFA/)
    expect(screen.getByText('Au lieu de')).toBeInTheDocument()
  })

  it('pas pour une variante plus chère que le prix barré', () => {
    produitAffiche = { ...sandales(), prix_barre: 19000 }
    afficher()
    fireEvent.click(valeur('Coloris', 'Noir'))
    fireEvent.click(valeur('Pointure', '45'))

    expect(screen.getByText(/19\s500\sFCFA/)).toBeInTheDocument()
    expect(barre()).toBeNull()
  })

  it('un produit sans option : à côté de son prix', () => {
    produitAffiche = { ...sandales(), nom: 'Kita tissé main', prix: 45000, prix_barre: 50000, options: [], variantes: [] }
    afficher()

    expect(barre()).toHaveTextContent(/50\s000\sFCFA/)
  })
})

describe('fiche d’un produit sans option', () => {
  it('reste comme avant : aucun sélecteur, prix seul, achat direct', () => {
    produitAffiche = { ...sandales(), nom: 'Kita tissé main', prix: 45000, options: [], variantes: [] }
    afficher()

    expect(screen.queryByRole('group')).not.toBeInTheDocument()
    expect(screen.queryByText('À partir de')).not.toBeInTheDocument()
    expect(screen.queryByText(/Choisissez/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Ajouter au panier/ })).toBeEnabled()
  })
})
