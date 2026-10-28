/**
 * Deux boutons verts sur le même écran : « Créer les N variantes » dans la
 * grille, « Enregistrer les modifications » en bas. Le second n'enregistre
 * pas les variantes ; tant qu'il en reste à créer, il ne doit ni en avoir
 * l'air, ni quitter la page en perdant la grille.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Produit } from '../api/produits'
import { modifierProduit } from '../api/produits'
import type { StructureVariantes } from '../api/variantes'
import { recupererSuggestions, recupererVariantes } from '../api/variantes'
import { PageFormulaireProduit } from './PageFormulaireProduit'

vi.mock('../api/variantes', async (original) => ({
  ...(await original<typeof import('../api/variantes')>()),
  recupererVariantes: vi.fn(),
  recupererSuggestions: vi.fn(),
}))
vi.mock('../api/produits', async (original) => ({
  ...(await original<typeof import('../api/produits')>()),
  modifierProduit: vi.fn(),
}))

const valeur = (id: number, libelle: string) => ({ id, libelle, ordre: id, archivee: false, nombre_variantes: 0, stock_total: 0, nombre_commandes: 0 })

const structure: StructureVariantes = {
  produit_id: 30,
  mode_stock: 'compte',
  prix_produit: 12000,
  options: [
    { id: 1, nom: 'Taille', ordre: 1, valeurs: [valeur(11, 'M')] },
    { id: 2, nom: 'Couleur', ordre: 2, valeurs: [valeur(21, 'Bleu'), valeur(22, 'Noir')] },
  ],
  variantes: [],
}

const produit = {
  id: 30,
  categorie_id: null,
  nom: 'Pantalon homme',
  slug: 'pantalon-homme',
  description: null,
  reference: null,
  prix: 12000,
  prix_barre: null,
  mode_stock: 'compte',
  quantite_stock: 0,
  quantite_reservee: 0,
  disponible: true,
  stock_variantes: null,
  statut: 'brouillon',
  publie_le: null,
  medias: [],
  created_at: '2026-10-27T10:00:00Z',
  updated_at: '2026-10-27T10:00:00Z',
} as Produit

function afficher() {
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  client.setQueryData(['moi'], { permissions: ['gerer_catalogue'], etablissement: { id: 3, nom: 'Wax Adjamé', type: 'boutique' } })
  client.setQueryData(['produit', 30], produit)
  client.setQueryData(['categories'], [])
  const router = createMemoryRouter(
    [
      { path: '/admin/produits/:id/modifier', element: <PageFormulaireProduit /> },
      { path: '/admin/produits', element: <h1>Liste des produits</h1> },
    ],
    { initialEntries: ['/admin/produits/30/modifier'] },
  )
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
}

const boutonProduit = () => screen.getByRole('button', { name: 'Enregistrer les modifications' })

describe('le bouton du produit et la grille des variantes', () => {
  beforeEach(() => {
    vi.mocked(recupererVariantes).mockReset().mockResolvedValue(structure)
    vi.mocked(recupererSuggestions).mockReset().mockResolvedValue([])
    vi.mocked(modifierProduit).mockReset().mockResolvedValue(produit)
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('cases cochées non créées : le bouton du bas ne sauve pas, ne quitte pas, et le dit', async () => {
    afficher()
    fireEvent.click(await screen.findByRole('checkbox', { name: 'M / Noir' }))

    expect(boutonProduit().className).not.toMatch(/bg-primaire/)
    expect(screen.getByRole('button', { name: 'Créer la variante' }).className).toMatch(/bg-primaire/)
    fireEvent.click(boutonProduit())

    expect(await screen.findByRole('alert')).toHaveTextContent(/Variantes pas encore enregistrées : ce bouton ne les enregistre pas\..*Créer la variante/)
    expect(modifierProduit).not.toHaveBeenCalled()
    expect(screen.queryByText('Liste des produits')).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'M / Noir' })).toHaveAttribute('aria-checked', 'true')
  })

  it('rien en attente : le bouton du bas enregistre comme avant', async () => {
    afficher()
    await screen.findByRole('checkbox', { name: 'M / Noir' })

    expect(boutonProduit().className).toMatch(/bg-primaire/)
    fireEvent.click(boutonProduit())

    await waitFor(() => expect(modifierProduit).toHaveBeenCalled())
  })
})
