/**
 * Lot C — l'écran de saisie manuelle : le canal décide du formulaire, le
 * comptoir encaisse en trois touchers depuis « Vendre », aucun prix n'est
 * jamais envoyé, et un doublon avertit sans bloquer. Lot F : les canaux
 * proposés suivent les permissions de /api/moi.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ArticleVendable } from '../api/commandesManuelles'
import { reconnaitreReference, rechercherArticles, recupererZonesProposees, saisirCommande, trouverClient } from '../api/commandesManuelles'
import { PageNouvelleCommande } from './PageNouvelleCommande'

let permissions: string[] = []
vi.mock('../hooks/useMoi', () => ({ useMoi: () => ({ data: { permissions } }) }))

vi.mock('../api/commandesManuelles', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api/commandesManuelles')>()),
  reconnaitreReference: vi.fn(),
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
    // Opérateur et caissier à la fois : les trois canaux.
    permissions = ['creer_commandes', 'encaisser']
    vi.mocked(rechercherArticles).mockReset().mockResolvedValue([pagne, sandale45])
    vi.mocked(recupererZonesProposees).mockReset().mockResolvedValue({ zones: [{ id: 1, nom: 'Cocody', frais: 1500 }], autreCommune: null })
    vi.mocked(trouverClient).mockReset().mockResolvedValue(null)
    vi.mocked(reconnaitreReference).mockReset().mockResolvedValue(null)
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

  it('depuis une demande WhatsApp : canal, article et référence déjà posés, le client à saisir', async () => {
    vi.mocked(rechercherArticles).mockResolvedValue([sandale45])
    afficher('/admin/commandes/nouvelle?canal=whatsapp&produit=20&variante=45&reference=AB12CD34')

    expect(await screen.findByRole('list', { name: 'Commande' })).toHaveTextContent('45 / Noir')
    expect(canal('WhatsApp')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByLabelText(/^Référence WhatsApp/)).toHaveValue('AB12CD34')
    expect(screen.getByLabelText(/^Téléphone/)).toHaveValue('')
  })

  it('une demande déjà transformée : un refus avec la commande, rien à confirmer', async () => {
    vi.mocked(saisirCommande).mockRejectedValue(new AxiosError('409', '409', undefined, undefined, {
      status: 409,
      data: { message: 'Cette demande est déjà devenue la commande CMD-000007.', deja_transformee: { id: 7, numero: 'CMD-000007' } },
      statusText: '',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }))
    afficher('/admin/commandes/nouvelle?canal=comptoir&produit=7&reference=AB12CD34')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(screen.getByRole('button', { name: /Encaisser/ }))

    expect(await screen.findByRole('link', { name: 'CMD-000007' })).toHaveAttribute('href', '/admin/commandes/7')
    expect(screen.getByRole('alert')).toHaveTextContent("Une demande ne devient qu'une seule commande.")
    expect(screen.queryByRole('button', { name: 'Créer quand même' })).not.toBeInTheDocument()
  })

  it('« Coller une référence » est caché derrière un lien', async () => {
    afficher()
    expect(screen.queryByLabelText(/^Référence WhatsApp/)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    expect(screen.getByLabelText(/^Référence WhatsApp/)).toBeInTheDocument()
  })

  it('un bouton grisé dit pourquoi : aucun article', async () => {
    afficher()
    fireEvent.click(canal('WhatsApp'))

    const bouton = screen.getByRole('button', { name: 'Enregistrer la commande' })
    expect(bouton).toBeDisabled()
    expect(bouton).toHaveAccessibleDescription('Ajoutez au moins un article pour enregistrer.')
    fireEvent.click(await within(screen.getByRole('list', { name: 'Résultats' })).findByText('Pagne wax'))
    expect(screen.queryByText('Ajoutez au moins un article pour enregistrer.')).not.toBeInTheDocument()
  })

  it('WhatsApp et téléphone : seuls le numéro et la commune sont obligatoires', async () => {
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(canal('Téléphone'))

    expect(screen.getByLabelText(/^Téléphone/)).toBeRequired()
    expect(screen.getByLabelText(/^Commune/)).toBeRequired()
    expect(screen.getByLabelText(/^Nom/)).not.toBeRequired()
    expect(screen.getByLabelText(/^Quartier/)).not.toBeRequired()
    expect(screen.getByLabelText(/^Nom/)).toHaveAccessibleName('Nom (facultatif)')
  })

  it('une référence collée ajoute l’article de la demande, une seule fois', async () => {
    vi.mocked(reconnaitreReference).mockResolvedValue({ code: 'AB12CD34', article: sandale45, commande: null })
    afficher()
    fireEvent.click(canal('WhatsApp'))
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    fireEvent.change(screen.getByLabelText(/^Référence WhatsApp/), { target: { value: 'Bonjour, je veux ça [REF:AB12CD34]' } })

    expect(await screen.findByRole('list', { name: 'Commande' })).toHaveTextContent('45 / Noir')
    expect(reconnaitreReference).toHaveBeenCalledWith('AB12CD34')
    expect(screen.getByText('Article de la demande ajouté : Sandales — 45 / Noir.')).toBeInTheDocument()
  })

  it('une référence collée n’ajoute pas un article déjà dans la commande', async () => {
    vi.mocked(reconnaitreReference).mockResolvedValue({ code: 'AB12CD34', article: sandale45, commande: null })
    afficher()
    fireEvent.click(canal('WhatsApp'))
    fireEvent.click(await within(screen.getByRole('list', { name: 'Résultats' })).findByText('Sandales'))
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    fireEvent.change(screen.getByLabelText(/^Référence WhatsApp/), { target: { value: '[REF:AB12CD34]' } })

    expect(await screen.findByText(/Article de la demande ajouté/)).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: 'Commande' })).getAllByRole('listitem')).toHaveLength(1)
  })

  it('une référence collée déjà devenue commande : le refus tout de suite, sans article', async () => {
    vi.mocked(reconnaitreReference).mockResolvedValue({ code: 'AB12CD34', article: sandale45, commande: { id: 7, numero: 'CMD-000007' } })
    afficher()
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    fireEvent.change(screen.getByLabelText(/^Référence WhatsApp/), { target: { value: 'AB12CD34' } })

    expect(await screen.findByRole('link', { name: 'CMD-000007' })).toHaveAttribute('href', '/admin/commandes/7')
    expect(screen.queryByRole('list', { name: 'Commande' })).not.toBeInTheDocument()
  })

  it('une référence inconnue, ou sans article vendable, le dit sous le champ', async () => {
    afficher()
    fireEvent.click(screen.getByRole('button', { name: 'Coller une référence WhatsApp' }))
    fireEvent.change(screen.getByLabelText(/^Référence WhatsApp/), { target: { value: 'ZZZZZZZZ' } })
    expect(await screen.findByText('Cette référence n’existe pas dans votre boutique.')).toBeInTheDocument()

    vi.mocked(reconnaitreReference).mockResolvedValue({ code: 'AB12CD34', article: null, commande: null })
    fireEvent.change(screen.getByLabelText(/^Référence WhatsApp/), { target: { value: 'AB12CD34' } })
    expect(await screen.findByText(/Choisissez l’article dans la liste/)).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Commande' })).not.toBeInTheDocument()
  })

  it('depuis « Transformer en commande », la référence n’est pas redemandée', async () => {
    vi.mocked(rechercherArticles).mockResolvedValue([sandale45])
    afficher('/admin/commandes/nouvelle?canal=whatsapp&produit=20&variante=45&reference=AB12CD34')
    await screen.findByRole('list', { name: 'Commande' })

    expect(reconnaitreReference).not.toHaveBeenCalled()
  })

  it('« Autre commune » à frais fixes : l’option et le total les comptent, comme la vitrine', async () => {
    vi.mocked(recupererZonesProposees).mockResolvedValue({ zones: [{ id: 1, nom: 'Cocody', frais: 1500 }], autreCommune: { frais: 3000 } })
    afficher('/admin/commandes/nouvelle?produit=7')
    await screen.findByRole('list', { name: 'Commande' })
    fireEvent.click(canal('Téléphone'))

    expect(await screen.findByRole('option', { name: /^Autre commune — 3\s000\sFCFA$/ })).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Commune/), { target: { value: '__autre__' } })

    expect(screen.getByText(/^Livraison : 3\s000\sFCFA$/)).toBeInTheDocument()
    expect(screen.getByText(/^8\s000\sFCFA$/)).toBeInTheDocument()
  })

  it('un caissier seul ne voit que Comptoir, déjà choisi, et rien à coller', async () => {
    permissions = ['encaisser']
    afficher('/admin/commandes/nouvelle?produit=7')

    expect(await screen.findByRole('list', { name: 'Commande' })).toHaveTextContent('Pagne wax')
    expect(canal('Comptoir')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.queryByRole('button', { name: 'WhatsApp' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Téléphone' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Coller une référence WhatsApp' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Encaisser 5\s000\sFCFA/ })).toBeEnabled()
    expect(recupererZonesProposees).not.toHaveBeenCalled()
  })

  it('sans encaisser, pas de Comptoir', async () => {
    permissions = ['creer_commandes']
    afficher()

    expect(await screen.findByRole('button', { name: 'WhatsApp' })).toBeInTheDocument()
    expect(canal('Téléphone')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Comptoir' })).not.toBeInTheDocument()
  })
})
