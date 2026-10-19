/**
 * Verrouille ce que chaque rôle voit dans la navigation du back-office —
 * menu utilisateur (MenuUtilisateur) ET barre latérale (BarreLaterale) — et
 * qu'aucune entrée ne mène à une route absente de routes.tsx (c'est-à-dire
 * à l'attrape-tout "/admin/*" ou "*").
 *
 * Les permissions ci-dessous sont celles que /api/moi renvoie réellement
 * pour admin_etablissement et operateur (voir RolesEtPermissionsSeeder) :
 * un renommage côté API ou côté filtre React fait échouer ce test au lieu
 * de faire disparaître une entrée sans erreur. Que la page atteinte ne
 * réponde jamais 403 est vérifié côté API par les suites backend
 * (PaiementEtablissementApiTest, IdentiteEtablissementApiTest,
 * EquipeApiTest).
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { createMemoryRouter, matchRoutes, RouterProvider } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Moi } from '../api/auth'
import { routes } from '../routes'
import { BarreLaterale } from './BarreLaterale'
import { MenuUtilisateur } from './MenuUtilisateur'

vi.mock('../hooks/useStatistiquesCommandes', () => ({
  useStatistiquesCommandes: () => ({ data: undefined }),
}))

const PERMISSIONS_ADMIN_ETABLISSEMENT = [
  'voir_catalogue',
  'gerer_catalogue',
  'voir_commandes',
  'gerer_commandes',
  'gerer_clients',
  'voir_statistiques',
  'gerer_parametres',
  'gerer_integrations',
  'gerer_stock',
  'gerer_equipe',
]
const PERMISSIONS_OPERATEUR = ['voir_catalogue', 'voir_commandes', 'gerer_commandes', 'gerer_clients']

function moiAvec(role: string, permissions: string[]): Moi {
  return {
    utilisateur: { id: 1, nom: 'Test', email: 'test@exemple.test' },
    etablissement: { id: 1, nom: 'Boutique test', type: 'boutique', statut: 'actif' },
    role,
    role_libelle_espace: null,
    permissions,
  } as Moi
}

/**
 * Rend la navigation sur /admin/commandes ; toute autre adresse tombe sur
 * un écran vide qui laisse lire router.state.location sans rendre la vraie
 * page (dont les appels réseau sont hors sujet ici).
 */
function afficherNavigation(moi: Moi) {
  const router = createMemoryRouter(
    [
      {
        path: '/admin/commandes',
        element: (
          <>
            <BarreLaterale moi={moi} ouvertMobile={false} onFermerMobile={() => {}} />
            <MenuUtilisateur moi={moi} />
          </>
        ),
      },
      { path: '*', element: null },
    ],
    { initialEntries: ['/admin/commandes'] },
  )

  render(
    <QueryClientProvider client={new QueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )

  return router
}

function ouvrirMenu() {
  if (!screen.queryByRole('menu')) {
    fireEvent.click(screen.getByRole('button', { name: /Test/ }))
  }
}

function libellesDuMenu(): string[] {
  ouvrirMenu()
  return screen
    .getAllByRole('menuitem')
    .map((item) => item.textContent ?? '')
    .filter((libelle) => libelle !== 'Se déconnecter')
}

function libellesDeLaBarre(): string[] {
  return screen.getAllByRole('link').map((lien) => lien.textContent ?? '')
}

/** Adresses atteintes par chaque entrée : liens de la barre, clic sur chaque item du menu. */
async function cheminsDesEntrees(moi: Moi): Promise<string[]> {
  const router = afficherNavigation(moi)
  const chemins = screen.getAllByRole('link').map((lien) => lien.getAttribute('href') ?? '')

  for (const libelle of libellesDuMenu()) {
    // Le menu s'est refermé au clic précédent (et la navigation a démonté
    // la page) : on repart de /admin/commandes pour chaque entrée.
    await act(() => router.navigate('/admin/commandes'))
    ouvrirMenu()
    fireEvent.click(screen.getByRole('menuitem', { name: libelle }))
    chemins.push(router.state.location.pathname)
  }

  return chemins
}

function routeDeclaree(chemin: string): string | undefined {
  return matchRoutes(routes, chemin)?.at(-1)?.route.path
}

describe('navigation du back-office selon les permissions renvoyées par /api/moi', () => {
  it("un administrateur d'établissement voit Mon compte, Identité de la boutique, Paiement, Abonnement, et Équipe dans la barre", () => {
    afficherNavigation(moiAvec('admin_etablissement', PERMISSIONS_ADMIN_ETABLISSEMENT))

    expect(libellesDeLaBarre()).toEqual(['Produits', 'Commandes', 'Catégories', 'Équipe'])
    expect(libellesDuMenu()).toEqual(['Mon compte', 'Identité de la boutique', 'Paiement', 'Abonnement'])
  })

  it('un opérateur ne voit que Mon compte dans le menu, et pas Équipe dans la barre', () => {
    afficherNavigation(moiAvec('operateur', PERMISSIONS_OPERATEUR))

    expect(libellesDeLaBarre()).toEqual(['Produits', 'Commandes', 'Catégories'])
    expect(libellesDuMenu()).toEqual(['Mon compte'])
  })

  it.each([
    ['admin_etablissement', PERMISSIONS_ADMIN_ETABLISSEMENT],
    ['operateur', PERMISSIONS_OPERATEUR],
  ])('aucune entrée visible par %s ne mène à une route inexistante', async (role, permissions) => {
    const chemins = await cheminsDesEntrees(moiAvec(role, permissions))

    expect(chemins.length).toBeGreaterThan(0)
    for (const chemin of chemins) {
      expect([chemin, routeDeclaree(chemin)]).toEqual([chemin, chemin])
    }
  })
})
