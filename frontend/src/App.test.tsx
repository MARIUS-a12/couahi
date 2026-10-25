/**
 * Vérifie que les deux mondes de App.tsx (vitrine publique, back-office
 * protégé) restent des branches sœurs indépendantes : une URL /admin/*
 * sans session redirige vers /admin/connexion (jamais vers la vitrine), et
 * une URL publique s'affiche sans jamais interroger la session.
 *
 * /api/moi est simulé via un mock de useMoi plutôt qu'un serveur HTTP : ce
 * fichier teste le ROUTAGE, pas les écrans eux-mêmes (déjà couverts côté
 * API par les suites backend). Les appels de la vitrine (useProduitsVitrine
 * etc.) sont eux aussi simulés, pour que chaque test reste synchrone et
 * indépendant d'un vrai réseau.
 *
 * createMemoryRouter (pas createBrowserRouter, celui de App.tsx) : chaque
 * test construit son propre routeur isolé à partir de la même liste de
 * routes (routes, exportée par App.tsx), sans dépendre de window.history ni
 * d'un routeur singleton partagé entre les tests.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { routes } from './routes'

const { useMoiMock } = vi.hoisted(() => ({ useMoiMock: vi.fn() }))

vi.mock('./hooks/useMoi', () => ({
  CLE_MOI: ['moi'],
  useMoi: () => useMoiMock(),
}))

vi.mock('./api/vitrine', () => ({
  recupererProduitsVitrine: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 24, total: 0 },
  }),
  recupererProduitVitrine: vi.fn().mockResolvedValue({
    id: 1,
    nom: 'Produit test',
    description: null,
    prix: 1000,
    categorie: null,
    disponible: true,
    photos: [],
    options: [],
    variantes: [],
  }),
  recupererCategoriesVitrine: vi.fn().mockResolvedValue([]),
  recupererEtablissementVitrine: vi.fn().mockResolvedValue({
    nom: 'Boutique test',
    type: 'boutique',
    logo: null,
    numero_whatsapp: null,
    couleur_accent: '#146c43',
  }),
  genererLienWhatsapp: vi.fn(),
}))

/**
 * Session absente : c'est exactement l'état d'un visiteur non connecté,
 * celui que les quatre tests de ce fichier vérifient.
 */
function simulerSessionAbsente(): void {
  useMoiMock.mockReturnValue({ data: undefined, isPending: false, isError: true })
}

function afficherRoute(chemin: string) {
  const router = createMemoryRouter(routes, { initialEntries: [chemin] })
  const queryClient = new QueryClient()

  const resultat = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )

  return { ...resultat, router }
}

beforeEach(() => {
  useMoiMock.mockReset()
})

afterEach(() => {
  cleanup()
})

describe('routage : vitrine publique et back-office sont deux mondes indépendants', () => {
  it('redirige /admin/produits vers /admin/connexion sans session', async () => {
    simulerSessionAbsente()

    const { router } = afficherRoute('/admin/produits')

    expect(await screen.findByRole('heading', { name: 'Back-office' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/admin/connexion')
  })

  it("affiche la vitrine sur / sans session, sans jamais appeler la garde d'authentification", async () => {
    simulerSessionAbsente()

    afficherRoute('/')

    expect(await screen.findByPlaceholderText('Rechercher un produit…')).toBeInTheDocument()
    expect(useMoiMock).not.toHaveBeenCalled()
  })

  it('affiche le formulaire de connexion sur /admin/connexion sans session, sans redirection', async () => {
    simulerSessionAbsente()

    const { router } = afficherRoute('/admin/connexion')

    expect(await screen.findByRole('heading', { name: 'Back-office' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/admin/connexion')
  })

  it('affiche la fiche produit sur /produit/:id sans session', async () => {
    simulerSessionAbsente()

    afficherRoute('/produit/1')

    expect(await screen.findByText('Produit test')).toBeInTheDocument()
    expect(useMoiMock).not.toHaveBeenCalled()
  })
})
