/**
 * Lot D — la page des demandes WhatsApp : ce qu'on sait d'un clic, et rien
 * d'autre ; les trois actions ; « Transformer » qui ouvre la saisie du
 * lot C déjà remplie.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DemandeWhatsapp } from '../api/demandesWhatsapp'
import { changerStatutDemande, listerDemandes } from '../api/demandesWhatsapp'
import { PageDemandesWhatsapp } from './PageDemandesWhatsapp'

vi.mock('../api/demandesWhatsapp', () => ({
  listerDemandes: vi.fn(),
  changerStatutDemande: vi.fn(),
  compterDemandesNouvelles: vi.fn(),
}))

const ilYa = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString()

function demande(surcharges: Partial<DemandeWhatsapp> = {}): DemandeWhatsapp {
  return {
    id: 1,
    code: 'AB12CD34',
    reference: '[REF:AB12CD34]',
    produit: { id: 20, nom: 'Sandales en cuir tressé' },
    variante: { id: 45, nom: '45 / Noir' },
    photo: 'http://localhost:8000/storage/medias/noir-vignette.jpg',
    cree_le: ilYa(12),
    expire_le: ilYa(-7 * 24 * 60),
    expiree: false,
    statut: 'nouvelle',
    statut_libelle: 'Nouvelle',
    statut_change_le: null,
    commande: null,
    ...surcharges,
  }
}

const page = (data: DemandeWhatsapp[], last_page = 1) => ({ data, meta: { current_page: 1, last_page, per_page: 25, total: data.length } })

function Adresse() {
  const location = useLocation()
  return <p data-testid="adresse">{location.pathname + location.search}</p>
}

function afficher(permissions = ['gerer_demandes_whatsapp', 'creer_commandes']) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(['moi'], { permissions })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/admin/demandes-whatsapp']}>
        <Routes>
          <Route path="/admin/demandes-whatsapp" element={<PageDemandesWhatsapp />} />
          <Route path="*" element={<Adresse />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('demandes WhatsApp', () => {
  beforeEach(() => {
    vi.mocked(listerDemandes).mockReset().mockResolvedValue(page([demande()]))
    vi.mocked(changerStatutDemande).mockReset().mockResolvedValue(demande({ statut: 'traitee' }))
  })

  it('une ligne : produit, variante, photo, il y a 12 min, référence, statut — rien sur le client', async () => {
    afficher()
    const ligne = within(await screen.findByRole('listitem'))

    expect(ligne.getByText('Sandales en cuir tressé')).toBeInTheDocument()
    expect(ligne.getByText('45 / Noir')).toBeInTheDocument()
    expect(document.querySelector('img')).toHaveAttribute('src', 'http://localhost:8000/storage/medias/noir-vignette.jpg')
    expect(ligne.getByText('[REF:AB12CD34]')).toBeInTheDocument()
    expect(ligne.getByRole('button', { name: 'Copier la référence [REF:AB12CD34]' })).toBeInTheDocument()
    expect(ligne.getByText('Nouvelle')).toBeInTheDocument()
    expect(ligne.queryByText(/Téléphone|Nom du client|Email/i)).not.toBeInTheDocument()

    const heure = ligne.getByRole('button', { name: 'il y a 12 min' })
    fireEvent.click(heure)
    expect(heure).toHaveTextContent(/\d{4}/)
    expect(listerDemandes).toHaveBeenCalledWith('nouvelle', 1)
  })

  it('« Référence expirée » au-delà de 7 jours, et la transformation reste possible', async () => {
    vi.mocked(listerDemandes).mockResolvedValue(page([demande({ expiree: true, cree_le: ilYa(8 * 24 * 60) })]))
    afficher()

    expect(await screen.findByText('Référence expirée')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Transformer en commande' })).toBeInTheDocument()
  })

  it('« Transformer en commande » ouvre la saisie : canal WhatsApp, produit, variante, référence', async () => {
    afficher()
    fireEvent.click(await screen.findByRole('button', { name: 'Transformer en commande' }))

    const adresse = new URL(`http://x${screen.getByTestId('adresse').textContent}`)
    expect(adresse.pathname).toBe('/admin/commandes/nouvelle')
    expect(Object.fromEntries(adresse.searchParams)).toEqual({ canal: 'whatsapp', produit: '20', variante: '45', reference: 'AB12CD34' })
  })

  it('sans creer_commandes, pas de « Transformer »', async () => {
    afficher(['gerer_demandes_whatsapp'])
    await screen.findByRole('listitem')

    expect(screen.queryByRole('button', { name: 'Transformer en commande' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Marquer traitée' })).toBeInTheDocument()
  })

  it('« Marquer traitée » et « Ignorer » envoient le statut', async () => {
    afficher()
    fireEvent.click(await screen.findByRole('button', { name: 'Marquer traitée' }))
    await waitFor(() => expect(changerStatutDemande).toHaveBeenCalledWith(1, 'traitee'))

    fireEvent.click(screen.getByRole('button', { name: 'Ignorer' }))
    await waitFor(() => expect(changerStatutDemande).toHaveBeenCalledWith(1, 'ignoree'))
  })

  it('une demande transformée montre sa commande, sans actions', async () => {
    vi.mocked(listerDemandes).mockResolvedValue(page([demande({ statut: 'traitee', statut_libelle: 'Traitée', commande: { id: 9, numero: 'CMD-000009' } })]))
    afficher()

    expect(await screen.findByRole('link', { name: 'CMD-000009' })).toHaveAttribute('href', '/admin/commandes/9')
    expect(screen.queryByRole('button', { name: /Transformer|Marquer|Ignorer/ })).not.toBeInTheDocument()
  })

  it('le filtre donne les traitées et les ignorées ; l’état vide explique', async () => {
    vi.mocked(listerDemandes).mockResolvedValue(page([]))
    afficher()

    expect(await screen.findByText(/Quand un client touchera « Commander sur WhatsApp »/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ignorées' }))
    await waitFor(() => expect(listerDemandes).toHaveBeenLastCalledWith('ignoree', 1))
    expect(await screen.findByText('Les demandes que vous aurez ignorées apparaîtront ici.')).toBeInTheDocument()
  })
})
