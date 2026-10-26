/**
 * Lot C — l'écran de saisie manuelle : le canal décide du formulaire, le
 * comptoir encaisse en trois touchers depuis « Vendre », aucun prix n'est
 * jamais envoyé, et un doublon avertit sans bloquer.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ArticleVendable } from '../api/commandesManuelles'
import { rechercherArticles, recupererZonesProposees, saisirCommande, trouverClient } from '../api/commandesManuelles'
import { PageNouvelleCommande } from './PageNouvelleCommande'

vi.mock('../api/commandesManuelles', () => ({
  rechercherArticles: vi.fn(),
  recupererZonesProposees: vi.fn(),
  trouverClient: vi.fn(),
  saisirCommande: vi.fn(),
}))

const pagne: ArticleVendable = { produit_id: 7, variante_id: null, nom: 'Pagne wax', variante: null, prix: 5000, stock: { mode: 'compte', disponible: 3 }, photo: null }
const sandale45: ArticleVendable = { produit_id: 20, variante_id: 45, nom: 'Sandales', variante: '45 / Noir', prix: 19500, stock: { mode: 'compte', disponible: 1 }, photo: null }

function afficher(url = '/admin/commandes/nouvelle') {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route path="/admin/commandes/nouvelle" element={<PageNouvelleCommande />} />
          <Route path="/admin/commandes/:id" element={<h1>Détail de la commande</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const canal = (nom: string) => screen.getByRole('button', { name: nom })

describe('saisie manuelle', () => {
  beforeEach(() => {
    vi.mocked(rechercherArticles).mockReset().mockResolvedValue([pagne, sandale45])
    vi.mocked(recupererZonesProposees).mockReset().mockResolvedValue([{ id: 1, nom: 'Cocody', frais: 1500 }])
    vi.mocked(trouverClient).mockReset().mockResolvedValue(null)
    vi.mocked(saisirCommande).mockReset().mockResolvedValue({ id: 12, numero: 'CMD-000012', statut: 'livree', total: 5000 })
  })

  it('comptoir depuis « Vendre » : trois touchers, aucun champ client, aucun prix envoyé', async () => {
    afficher('/admin/commandes/nouvelle?produit=7')
    // Toucher 1 : « Vendre » (l'URL). L'article est déjà là.
    expect(await screen.findByRole('list', { name: 'Commande' })).toHaveTextContent('Pagne wax')

    fireEvent.click(canal('Comptoir')) // toucher 2
    expect(screen.queryByLabelText(/^Téléphone/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/^Commune/)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Encaisser 5\s000\sFCFA/ })) // toucher 3

    await waitFor(() => expect(saisirCommande).toHaveBeenCalled())
    const envoi = vi.mocked(saisirCommande).mock.calls[0][0]
    expect(envoi).toMatchObject({ canal: 'comptoir', lignes: [{ produit_id: 7, variante_id: null, quantite: 1 }] })
    expect(envoi.client).toBeUndefined()
    expect(JSON.stringify(envoi)).not.toMatch(/prix|total/)
    expect(await screen.findByText(/Vente CMD-000012 encaissée/)).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Commande' })).not.toBeInTheDocument()
    expect(canal('Comptoir')).toHaveAttribute('aria-pressed', 'true')
  })

  it('après une vente, la liste reprend le stock du serveur (12 → 11)', async () => {
    vi.mocked(rechercherArticles)
      .mockResolvedValueOnce([{ ...pagne, stock: { mode: 'compte', disponible: 12 } }])
      .mockResolvedValue([{ ...pagne, stock: { mode: 'compte', disponible: 11 } }])
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    expect(within(screen.getByRole('list', { name: 'Résultats' })).getByText(/Stock : 12/)).toBeInTheDocument()

    fireEvent.click(canal('Comptoir'))
    fireEvent.click(screen.getByRole('button', { name: /Encaisser/ }))

    await screen.findByText(/Vente CMD-000012 encaissée/)
    expect(await within(screen.getByRole('list', { name: 'Résultats' })).findByText(/Stock : 11/)).toBeInTheDocument()
  })

  it('deux ventes au comptoir à la suite partent avec deux clés d’idempotence', async () => {
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(canal('Comptoir'))
    fireEvent.click(screen.getByRole('button', { name: /Encaisser/ }))
    await screen.findByText(/Vente CMD-000012 encaissée/)

    fireEvent.click(await within(screen.getByRole('list', { name: 'Résultats' })).findByText('Pagne wax'))
    fireEvent.click(screen.getByRole('button', { name: /Encaisser/ }))

    await waitFor(() => expect(saisirCommande).toHaveBeenCalledTimes(2))
    const [premiere, seconde] = vi.mocked(saisirCommande).mock.calls.map(([envoi]) => envoi.cle_idempotence)
    expect(seconde).not.toBe(premiere)
  })

  it('comptoir : « identifier le client » révèle téléphone et nom', async () => {
    afficher()
    fireEvent.click(canal('Comptoir'))
    fireEvent.click(screen.getByRole('button', { name: /Identifier le client/ }))

    expect(screen.getByLabelText(/^Téléphone du client/)).toBeInTheDocument()
    expect(screen.getByLabelText(/^Nom du client/)).toBeInTheDocument()
  })

  it('téléphone : le numéro en premier, un client connu préremplit nom, commune et quartier', async () => {
    vi.mocked(trouverClient).mockResolvedValue({ nom: 'Mariam Koné', commune: 'Cocody', quartier: 'Angré' })
    afficher()
    fireEvent.click(canal('Téléphone'))

    const champs = screen.getAllByRole('textbox').map((champ) => champ.id)
    expect(champs.indexOf('client.telephone')).toBeLessThan(champs.indexOf('client.nom'))
    fireEvent.change(screen.getByLabelText(/^Téléphone/), { target: { value: '07 01 02 03 04' } })

    await waitFor(() => expect(screen.getByLabelText(/^Nom/)).toHaveValue('Mariam Koné'))
    expect(screen.getByLabelText(/^Quartier/)).toHaveValue('Angré')
    expect(await screen.findByText(/Client connu/)).toBeInTheDocument()
  })

  it('ajouter un article, le total suit, les frais de la commune comptent', async () => {
    afficher()
    fireEvent.click(canal('Téléphone'))
    const resultats = await screen.findByRole('list', { name: 'Résultats' })
    fireEvent.click(await within(resultats).findByText('Pagne wax'))
    fireEvent.click(within(resultats).getByText('Pagne wax'))
    await screen.findByRole('option', { name: /Cocody/ })
    fireEvent.change(screen.getByLabelText(/^Commune/), { target: { value: 'Cocody' } })

    expect(within(screen.getByRole('list', { name: 'Commande' })).getByLabelText('Quantité')).toHaveTextContent('2')
    expect(screen.getByText(/11\s500\sFCFA/)).toBeInTheDocument()
  })

  it('on ne dépasse pas le stock disponible', async () => {
    afficher()
    const resultats = await screen.findByRole('list', { name: 'Résultats' })
    fireEvent.click(await within(resultats).findByText('Sandales'))

    expect(screen.getByRole('button', { name: 'Une de plus : Sandales' })).toBeDisabled()
  })

  it('un doublon avertit, montre la commande, et « Créer quand même » renvoie avec confirmation', async () => {
    vi.mocked(saisirCommande)
      .mockRejectedValueOnce(new AxiosError('409', '409', undefined, undefined, {
        status: 409,
        data: { doublons: [{ id: 9, numero: 'CMD-000009', statut_libelle: 'Confirmée', total: 6500, created_at: '' }] },
        statusText: '',
        headers: {},
        config: { headers: new AxiosHeaders() },
      }))
      .mockResolvedValueOnce({ id: 13, numero: 'CMD-000013', statut: 'payee', total: 5000 })
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(canal('Téléphone'))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la commande' }))

    expect(await screen.findByRole('link', { name: 'CMD-000009' })).toHaveAttribute('href', '/admin/commandes/9')
    fireEvent.click(screen.getByRole('button', { name: 'Créer quand même' }))

    await waitFor(() => expect(vi.mocked(saisirCommande).mock.calls[1]?.[0].confirmer_doublon).toBe(true))
    expect(await screen.findByText('Détail de la commande')).toBeInTheDocument()
  })

  it('le refus de stock du serveur s’affiche tel quel', async () => {
    const message = 'Stock insuffisant pour Pagne wax : 2 demandé(s), 1 disponible(s).'
    vi.mocked(saisirCommande).mockRejectedValue(new AxiosError('422', '422', undefined, undefined, {
      status: 422,
      data: { message },
      statusText: '',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }))
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(canal('Comptoir'))
    fireEvent.click(screen.getByRole('button', { name: /Encaisser/ }))

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('« Coller une référence » est caché derrière un lien', async () => {
    afficher()
    expect(screen.queryByLabelText(/^Référence WhatsApp/)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    expect(screen.getByLabelText(/^Référence WhatsApp/)).toBeInTheDocument()
  })
})
