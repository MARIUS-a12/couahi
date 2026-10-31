/**
 * Le cas CMD-000002 de la démo, côté écran : 10 000 FCFA reçus sur 15 000,
 * puis commande annulée. Jamais "Payée" pour un paiement partiel, jamais de
 * remerciement sur une commande annulée, jamais "NaN FCFA", et aucune
 * action de transition sur un état terminal.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { client as clientHttp, MESSAGE_DROITS_CHANGES } from '../lib/client'
import type { CommandeDetail, PaiementCommande } from '../api/commandes'
import type { CommandeVitrine } from '../api/vitrine'
import { formaterMontant } from '../lib/formatage'
import { PageConfirmationCommande } from '../pages/PageConfirmationCommande'
import { PageDetailCommande } from '../pages/PageDetailCommande'
import { PastillePaiement } from './PastillePaiement'

function paiement(surcharges: Partial<PaiementCommande> = {}): PaiementCommande {
  return {
    confirme: true,
    confirme_le: '2026-10-20T21:00:00Z',
    confirme_par: 'Fatou Diallo',
    montant_recu: 10000,
    ecart: -5000,
    reference: 'OM-7731-AB',
    en_retard: false,
    a_traiter_en_urgence: false,
    partiel: true,
    reste_du: 5000,
    partielle_a_decider: true,
    a_rembourser: null,
    remboursement: null,
    ...surcharges,
  }
}

function commandeDetail(statut: string, surcharges: Partial<PaiementCommande> = {}): CommandeDetail {
  return {
    id: 2,
    numero: 'CMD-000002',
    statut,
    statut_libelle: statut,
    canal: 'web',
    sous_total: 15000,
    frais_livraison: 0,
    total: 15000,
    note: null,
    created_at: '2026-10-20T21:00:00Z',
    nombre_articles: 1,
    en_retard: false,
    mode_paiement: 'mobile_money',
    stock_reserve: null,
    paiement: paiement(surcharges),
    zone_livraison: null,
    commune: 'Adjamé',
    quartier: 'Liberté',
    client: { nom: 'Bamba Aïcha', telephone: '+2250544332211' },
    lignes: [],
    historique: [],
  } as CommandeDetail
}

function clientAvec(donnees: [readonly unknown[], unknown][]): QueryClient {
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  donnees.forEach(([cle, valeur]) => client.setQueryData(cle, valeur))

  return client
}

describe('paiement partiel', () => {
  it("un paiement inférieur au total ne s'affiche jamais « Payée »", () => {
    render(<PastillePaiement commande={commandeDetail('attente_paiement')} />)

    expect(screen.getByText(/Payée partiellement — reste 5\s000 FCFA/)).toBeInTheDocument()
    expect(screen.queryByText(/^Payée$/)).not.toBeInTheDocument()
  })
})

describe('page de la cliente', () => {
  function afficherPageCliente(commande: CommandeVitrine) {
    const client = clientAvec([
      [['commande-vitrine', commande.numero, 'jeton'], commande],
      [['vitrine-etablissement'], { nom: 'Wax Adjamé', numero_whatsapp: '+2250758123456' }],
    ])

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[`/commande/${commande.numero}?jeton=jeton`]}>
          <Routes>
            <Route path="/commande/:numero" element={<PageConfirmationCommande />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  }

  const base: CommandeVitrine = {
    numero: 'CMD-000002',
    statut: 'annulee',
    sous_total: 15000,
    frais_livraison: 0,
    total: 15000,
    note: null,
    cree_le: '2026-10-20T21:00:00Z',
    zone_livraison: null,
    lignes: [],
    mode_paiement: 'mobile_money',
    paiement_confirme: true,
    montant_recu: 10000,
    reste_du: null,
    a_rembourser: 10000,
    remboursement: null,
    mobile_money: null,
  }

  it('une commande annulée avec paiement reçu annonce le remboursement dû, sans remercier', () => {
    afficherPageCliente(base)

    expect(screen.getByText(/Cette commande a été annulée/)).toBeInTheDocument()
    expect(screen.getByText(/un remboursement vous est dû/)).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent(/10\s000 FCFA/)
    expect(document.body.textContent).not.toMatch(/Merci|vous serez contacté|confirmé avoir reçu/i)

    const lien = screen.getByRole('link', { name: /Demander mon remboursement/ })
    expect(decodeURIComponent(lien.getAttribute('href') ?? '')).toContain('CMD-000002')
  })

  it('un paiement partiel sur une commande vivante indique le reste à envoyer', () => {
    afficherPageCliente({
      ...base,
      statut: 'attente_paiement',
      reste_du: 5000,
      a_rembourser: null,
      mobile_money: {
        numero: '+2250758123456',
        numero_affiche: '07 58 12 34 56',
        operateur: 'orange_money',
        operateur_libelle: 'Orange Money',
        beneficiaire: 'DIALLO FATOU AMINATA',
      },
    })

    expect(screen.getByText(/Paiement incomplet/)).toBeInTheDocument()
    expect(screen.getByText('Montant restant à envoyer')).toBeInTheDocument()
    expect(
      screen.getByText(/Votre commande sera confirmée dès que le commerçant aura reçu les 5\s000 FCFA restants\./),
    ).toBeInTheDocument()
    expect(screen.queryByText(/aura reçu votre paiement/)).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/Merci/)
  })
})

describe('détail commerçant', () => {
  function afficherDetail(commande: CommandeDetail, permissions = ['traiter_commandes', 'encaisser', 'annuler_payees']) {
    const client = clientAvec([
      [['commande', commande.id], commande],
      [['moi'], { permissions, etablissement: { id: 3, nom: 'Wax Adjamé' } }],
    ])

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[`/admin/commandes/${commande.id}`]}>
          <Routes>
            <Route path="/admin/commandes/:id" element={<PageDetailCommande />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  }

  it.each(['annulee', 'livree'])('aucune action de transition sur une commande %s', (statut) => {
    afficherDetail(commandeDetail(statut, { partiel: false, reste_du: null, partielle_a_decider: false }))

    for (const libelle of [/Confirmer la commande/, /Marquer comme prête/, /Marquer comme livrée/, /Annuler la commande/]) {
      expect(screen.queryByRole('button', { name: libelle })).not.toBeInTheDocument()
    }
    expect(screen.queryByRole('heading', { name: 'Actions' })).not.toBeInTheDocument()
  })

  // Lot F : chaque bouton suit son droit ; le serveur refuse de toute façon.
  const NON_PAYEE = { confirme: false, confirme_le: null, montant_recu: null, partiel: false, reste_du: null, partielle_a_decider: false }

  it("l'opérateur confirme et annule une commande non payée, sans pouvoir encaisser", () => {
    afficherDetail(commandeDetail('attente_paiement', NON_PAYEE), ['voir_commandes', 'traiter_commandes'])

    expect(screen.getByRole('button', { name: /Confirmer la commande/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Annuler la commande/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Confirmer le paiement reçu/ })).not.toBeInTheDocument()
  })

  it("payée, même partiellement : ni l'opérateur ni le caissier ne voient « Annuler »", () => {
    afficherDetail(commandeDetail('attente_paiement'), ['voir_commandes', 'traiter_commandes'])
    expect(screen.getByRole('button', { name: /Confirmer la commande/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Annuler la commande/ })).not.toBeInTheDocument()
  })

  it("le caissier encaisse, sans faire avancer ni annuler la commande", () => {
    afficherDetail(commandeDetail('attente_paiement', NON_PAYEE), ['voir_commandes', 'encaisser'])

    expect(screen.getByRole('button', { name: /Confirmer le paiement reçu/ })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Actions' })).not.toBeInTheDocument()
  })

  it("le gestionnaire de stock voit la commande, sans aucun bouton", () => {
    afficherDetail(commandeDetail('attente_paiement', NON_PAYEE), ['voir_catalogue', 'gerer_stock', 'voir_commandes'])

    expect(screen.getByText('CMD-000002')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Actions' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Confirmer le paiement reçu/ })).not.toBeInTheDocument()
  })

  it("seul annuler_payees solde un remboursement", () => {
    afficherDetail(commandeDetail('annulee', { reste_du: null, partielle_a_decider: false, a_rembourser: 10000 }), ['voir_commandes', 'traiter_commandes', 'encaisser'])

    expect(screen.getByText(/pensez à le rembourser/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Marquer le remboursement effectué/ })).not.toBeInTheDocument()
  })

  describe('un droit retiré pendant que la page était ouverte', () => {
    const adapterReel = clientHttp.defaults.adapter

    afterEach(() => {
      clientHttp.defaults.adapter = adapterReel
    })

    /** Le serveur répond 403 avec ce corps à toute écriture ; le reste passe. */
    function serveurQuiRefuse(corps: Record<string, unknown>) {
      const adapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
        if ((config.method ?? 'get') === 'get') {
          return { data: {}, status: 204, statusText: 'No Content', headers: {}, config }
        }
        const reponse = { data: corps, status: 403, statusText: 'Forbidden', headers: {}, config }
        throw new AxiosError('Request failed with status code 403', AxiosError.ERR_BAD_REQUEST, config, null, reponse)
      }
      clientHttp.defaults.adapter = adapter
    }

    it('le bouton encore affiché dit « Vos droits ont changé, rechargez la page »', async () => {
      // Son écran le croit encore opérateur ; le serveur sait que non.
      serveurQuiRefuse({ message: 'This action is unauthorized.' })
      afficherDetail(commandeDetail('attente_paiement', NON_PAYEE), ['voir_commandes', 'traiter_commandes'])

      fireEvent.click(screen.getByRole('button', { name: /Confirmer la commande/ }))

      expect(await screen.findByText(MESSAGE_DROITS_CHANGES)).toBeInTheDocument()
      expect(MESSAGE_DROITS_CHANGES).toBe('Vos droits ont changé, rechargez la page.')
      expect(screen.queryByText(/Impossible de contacter le serveur/)).not.toBeInTheDocument()
      expect(screen.queryByText(/unauthorized/)).not.toBeInTheDocument()
    })

    it('un refus qui porte son propre message le garde', async () => {
      serveurQuiRefuse({ message: 'Cette commande vient de recevoir un paiement : seul un administrateur peut maintenant l’annuler.' })
      afficherDetail(commandeDetail('attente_paiement', NON_PAYEE), ['voir_commandes', 'traiter_commandes'])

      fireEvent.click(screen.getByRole('button', { name: /Confirmer la commande/ }))

      expect(await screen.findByText(/vient de recevoir un paiement/)).toBeInTheDocument()
      expect(screen.queryByText(MESSAGE_DROITS_CHANGES)).not.toBeInTheDocument()
    })
  })

  it("l'avertissement de remboursement affiche le montant réellement reçu, jamais NaN", () => {
    afficherDetail(commandeDetail('annulee', { reste_du: null, partielle_a_decider: false, a_rembourser: 10000 }))

    expect(screen.getByText(/le client a payé 10\s000 FCFA, pensez à le rembourser/)).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('NaN')
    expect(screen.getByRole('button', { name: /Marquer le remboursement effectué/ })).toBeInTheDocument()
  })

  it("un remboursement enregistré remplace la mention « À rembourser »", () => {
    afficherDetail(
      commandeDetail('annulee', {
        reste_du: null,
        partielle_a_decider: false,
        a_rembourser: null,
        remboursement: { le: '2026-10-20T22:00:00Z', par: 'Fatou Diallo', montant: 10000, reference: 'OM-RB-5521' },
      }),
    )

    expect(screen.queryByText(/pensez à le rembourser/)).not.toBeInTheDocument()
    expect(screen.getByText(/Remboursement effectué : 10\s000 FCFA/)).toBeInTheDocument()
  })
})

describe('formaterMontant', () => {
  it("n'affiche jamais NaN, même avec une valeur absente", () => {
    const silence = vi.spyOn(console, 'error').mockImplementation(() => {})

    expect(formaterMontant(undefined)).toBe('— FCFA')
    expect(formaterMontant(Number.NaN)).toBe('— FCFA')
    expect(formaterMontant(null)).toBe('— FCFA')
    expect(formaterMontant(10000)).toMatch(/^10\s000 FCFA$/)

    silence.mockRestore()
  })
})
