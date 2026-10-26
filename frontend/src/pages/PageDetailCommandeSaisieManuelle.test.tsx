/**
 * Lot C — le détail d'une commande saisie au back-office : la vente au
 * comptoir n'a ni client ni livraison, et l'on voit qui l'a saisie.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { CommandeDetail } from '../api/commandes'
import { PageDetailCommande } from './PageDetailCommande'

function commande(surcharges: Partial<CommandeDetail>): CommandeDetail {
  return {
    id: 5,
    numero: 'CMD-000001',
    statut: 'livree',
    statut_libelle: 'Livrée',
    canal: 'comptoir',
    canal_libelle: 'Comptoir',
    sous_total: 15000,
    frais_livraison: 0,
    total: 15000,
    note: null,
    created_at: '2026-10-26T02:39:22Z',
    nombre_articles: 1,
    en_retard: false,
    mode_paiement: 'especes',
    stock_reserve: null,
    paiement: {
      confirme: true,
      confirme_le: '2026-10-26T02:39:22Z',
      confirme_par: 'Fatou Diallo',
      montant_recu: 15000,
      ecart: 0,
      reference: null,
      en_retard: false,
      a_traiter_en_urgence: false,
      partiel: false,
      reste_du: null,
      partielle_a_decider: false,
      a_rembourser: null,
      remboursement: null,
    },
    zone_livraison: null,
    commune: null,
    quartier: null,
    client: null,
    cree_par: 'Fatou Diallo',
    lignes: [],
    historique: [],
    ...surcharges,
  } as CommandeDetail
}

function afficher(detail: CommandeDetail) {
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  client.setQueryData(['commande', detail.id], detail)
  client.setQueryData(['moi'], { permissions: ['gerer_commandes'], etablissement: { id: 3, nom: 'Wax Adjamé' } })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/admin/commandes/${detail.id}`]}>
        <Routes>
          <Route path="/admin/commandes/:id" element={<PageDetailCommande />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('détail d’une commande saisie au back-office', () => {
  it('comptoir : client de passage, saisie signée, aucune ligne de livraison', () => {
    afficher(commande({}))

    expect(screen.getByText('Client de passage')).toBeInTheDocument()
    expect(screen.getByText(/Saisie par Fatou Diallo — Comptoir/)).toBeInTheDocument()
    expect(screen.queryByText(/^Livraison/)).not.toBeInTheDocument()
    expect(screen.queryByText('À convenir avec la boutique')).not.toBeInTheDocument()
  })

  it('vitrine sans zone : la livraison reste « à convenir »', () => {
    afficher(commande({
      statut: 'attente_paiement',
      statut_libelle: 'En attente',
      canal: 'web',
      canal_libelle: 'Vitrine',
      cree_par: null,
      commune: 'Bingerville',
      quartier: 'Centre',
      client: { nom: 'Awa', telephone: '+2250701020304' },
    }))

    expect(screen.getByText('À convenir avec la boutique')).toBeInTheDocument()
  })
})
