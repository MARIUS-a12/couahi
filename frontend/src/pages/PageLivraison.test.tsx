/**
 * L'écran « Livraison » du commerçant, puis ce que le client en voit au
 * moment de commander : jamais « 0 FCFA » quand la boutique ne facture pas
 * la livraison, qu'il prendrait pour une livraison gratuite.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Livraison } from '../api/livraison'
import { ajouterZone, choisirFacturation, recupererLivraison, retirerZone } from '../api/livraison'
import { recupererZonesLivraison } from '../api/vitrine'
import { libelleLivraisonCommande } from '../lib/livraison'
import { PageCommander } from './PageCommander'
import { PageLivraison } from './PageLivraison'

vi.mock('../api/livraison', () => ({
  recupererLivraison: vi.fn(),
  choisirFacturation: vi.fn(),
  ajouterZone: vi.fn(),
  modifierZone: vi.fn(),
  retirerZone: vi.fn(),
}))
vi.mock('../api/vitrine', () => ({ recupererZonesLivraison: vi.fn(), creerCommandeVitrine: vi.fn() }))
vi.mock('../hooks/usePanier', () => ({
  usePanier: () => ({ lignes: [{ produitId: 2, varianteId: null, quantite: 1, prixVu: 500 }], vider: vi.fn() }),
}))
vi.mock('../hooks/useVerifierPanier', () => ({
  useVerifierPanier: () => ({ data: { sous_total: 500, lignes: [{ produit_id: 2, variante_id: null, quantite: 1, nom: 'Attiéké', prix_actuel: 500, statut: 'disponible' }] }, isPending: false }),
}))
vi.mock('../hooks/useEtablissementVitrine', () => ({ useEtablissementVitrine: () => ({ data: { mode_paiement: null } }) }))

function afficher(element: React.ReactNode) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>{element}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const sansFacturation: Livraison = { par_commune: false, zones: [] }
const avecZones: Livraison = {
  par_commune: true,
  zones: [
    { id: 1, nom: 'Adjamé', frais: 0 },
    { id: 2, nom: 'Cocody', frais: 1500 },
  ],
}

describe('écran Livraison', () => {
  beforeEach(() => {
    vi.mocked(recupererLivraison).mockReset()
  })

  it('sans facturation : le choix est coché, et aucune liste de communes', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue(sansFacturation)
    afficher(<PageLivraison />)

    expect(await screen.findByLabelText(/Je ne facture pas la livraison/)).toBeChecked()
    expect(screen.queryByText('Vos communes')).not.toBeInTheDocument()
  })

  it('choisir de facturer par commune enregistre aussitôt, et prévient tant qu’aucune commune n’existe', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue(sansFacturation)
    vi.mocked(choisirFacturation).mockResolvedValue({ par_commune: true, zones: [] })
    afficher(<PageLivraison />)

    fireEvent.click(await screen.findByLabelText(/Je facture la livraison selon la commune/))

    await waitFor(() => expect(choisirFacturation).toHaveBeenCalledWith(true))
    expect(await screen.findByText(/Ajoutez au moins une commune/)).toBeInTheDocument()
  })

  it('les communes avec leurs frais, « Offerte » à 0 FCFA', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue(avecZones)
    afficher(<PageLivraison />)

    const liste = await screen.findByRole('list')
    expect(within(liste).getByText('Adjamé').parentElement).toHaveTextContent('Offerte')
    expect(within(liste).getByText('Cocody').parentElement).toHaveTextContent(/1\s500\sFCFA/)
  })

  it('ajouter une commune envoie le nom et les frais, et affiche la nouvelle liste', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue({ par_commune: true, zones: [] })
    vi.mocked(ajouterZone).mockResolvedValue({ par_commune: true, zones: [{ id: 3, nom: 'Yopougon', frais: 2000 }] })
    afficher(<PageLivraison />)

    fireEvent.change(await screen.findByLabelText(/^Commune/), { target: { value: 'Yopougon' } })
    fireEvent.change(screen.getByLabelText(/^Frais de livraison/), { target: { value: '2000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    await waitFor(() => expect(ajouterZone).toHaveBeenCalledWith({ nom: 'Yopougon', frais: 2000 }))
    expect(await screen.findByText('Yopougon')).toBeInTheDocument()
  })

  it('sans frais saisis, rien ne part et le champ le dit', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue({ par_commune: true, zones: [] })
    vi.mocked(ajouterZone).mockClear()
    afficher(<PageLivraison />)

    fireEvent.change(await screen.findByLabelText(/^Commune/), { target: { value: 'Yopougon' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByText('Indiquez les frais de livraison (0 si elle est offerte).')).toBeInTheDocument()
    expect(ajouterZone).not.toHaveBeenCalled()
  })

  it('retirer une commune demande confirmation', async () => {
    vi.mocked(recupererLivraison).mockResolvedValue(avecZones)
    vi.mocked(retirerZone).mockResolvedValue({ par_commune: true, zones: [avecZones.zones[0]] })
    const confirmer = vi.spyOn(window, 'confirm').mockReturnValue(true)
    afficher(<PageLivraison />)

    fireEvent.click(await screen.findByRole('button', { name: 'Retirer Cocody' }))

    expect(confirmer).toHaveBeenCalled()
    await waitFor(() => expect(retirerZone).toHaveBeenCalledWith(2))
    confirmer.mockRestore()
  })
})

describe('ce que le client voit en face de « Livraison »', () => {
  it('une boutique qui ne facture pas : « À convenir », jamais 0 FCFA', async () => {
    vi.mocked(recupererZonesLivraison).mockResolvedValue([])
    afficher(<PageCommander />)

    expect(await screen.findByText('À convenir avec la boutique')).toBeInTheDocument()
    expect(screen.queryByText('0 FCFA')).not.toBeInTheDocument()
  })

  it('une boutique qui facture : « Selon la commune », puis les frais de la commune choisie', async () => {
    vi.mocked(recupererZonesLivraison).mockResolvedValue([
      { id: 1, nom: 'Adjamé', frais: 0 },
      { id: 2, nom: 'Cocody', frais: 1500 },
    ] as never)
    afficher(<PageCommander />)

    expect(await screen.findByText('Selon la commune')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Adjamé — Offerte' })).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Commune/), { target: { value: 'Adjamé' } })
    expect(screen.getAllByText('Offerte').length).toBeGreaterThan(0)
  })

  it('« Autre commune » : saisie libre, livraison à convenir, et le total dit qu’il ne l’inclut pas', async () => {
    vi.mocked(recupererZonesLivraison).mockResolvedValue([{ id: 2, nom: 'Cocody', frais: 1500 }] as never)
    afficher(<PageCommander />)

    await screen.findByText('Selon la commune')
    expect(screen.queryByText(/n’inclut pas la livraison/)).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Commune/), { target: { value: '__autre__' } })

    expect(screen.getByLabelText(/^Votre commune/)).toBeInTheDocument()
    expect(screen.getByText('À convenir avec la boutique')).toBeInTheDocument()
    expect(screen.getByText(/Ce total n’inclut pas la livraison/)).toBeInTheDocument()
  })

  it('une boutique qui ne facture pas le dit aussi sous le total', async () => {
    vi.mocked(recupererZonesLivraison).mockResolvedValue([])
    afficher(<PageCommander />)

    expect(await screen.findByText(/Ce total n’inclut pas la livraison/)).toBeInTheDocument()
  })

  it('une commande passée sans zone se règle avec la boutique ; une zone à 0 est offerte', () => {
    expect(libelleLivraisonCommande(null, 0)).toBe('À convenir avec la boutique')
    expect(libelleLivraisonCommande({ nom: 'Adjamé' }, 0)).toBe('Offerte')
    expect(libelleLivraisonCommande({ nom: 'Cocody' }, 1500)).toMatch(/1\s500\sFCFA/)
  })
})
