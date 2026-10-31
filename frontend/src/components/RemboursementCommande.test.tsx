/**
 * Étape 13 — une commande Mobile Money déjà payée puis annulée : le
 * commerçant doit lire qu'il a de l'argent à rendre, AVANT de confirmer
 * l'annulation (panneau) et APRÈS (section Paiement du détail, d'après
 * paiement.a_rembourser venu de l'API).
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CommandeDetail } from '../api/commandes'
import { PanneauAnnulationCommande } from './PanneauAnnulationCommande'
import { SectionPaiementCommande } from './SectionPaiementCommande'

function commandeAnnuleeApresPaiement(): CommandeDetail {
  return {
    id: 12,
    numero: 'CMD-000012',
    statut: 'annulee',
    statut_libelle: 'Annulée',
    canal: 'web',
    sous_total: 5947,
    frais_livraison: 1000,
    total: 6947,
    note: null,
    created_at: '2026-10-19T23:24:00Z',
    nombre_articles: 1,
    en_retard: false,
    mode_paiement: 'mobile_money',
    stock_reserve: null,
    paiement: {
      confirme: true,
      confirme_le: '2026-10-19T23:25:00Z',
      confirme_par: 'Yao Kouadio',
      montant_recu: 6900,
      ecart: -47,
      reference: 'WV-2610-88213',
      en_retard: false,
      a_traiter_en_urgence: false,
      partiel: true,
      reste_du: null,
      partielle_a_decider: false,
      a_rembourser: 6900,
      remboursement: null,
    },
    zone_livraison: null,
    commune: null,
    quartier: null,
    lignes: [],
    historique: [],
  }
}

describe('avertissement de remboursement', () => {
  it("le panneau d'annulation prévient que le client a déjà payé", () => {
    render(
      <PanneauAnnulationCommande
        numero="CMD-000012"
        montantPaye={6900}
        enCours={false}
        erreur={null}
        onConfirmer={vi.fn()}
        onFermer={vi.fn()}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent(/Le client a déjà payé 6\s900 FCFA : pensez à le rembourser\./)
  })

  it("le panneau d'annulation ne dit rien d'un remboursement sans paiement", () => {
    render(
      <PanneauAnnulationCommande numero="CMD-000012" enCours={false} erreur={null} onConfirmer={vi.fn()} onFermer={vi.fn()} />,
    )

    expect(screen.queryByText(/rembourser/)).not.toBeInTheDocument()
  })

  it('une commande payée puis annulée affiche le montant à rembourser dans sa section Paiement', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <SectionPaiementCommande commande={commandeAnnuleeApresPaiement()} peutEncaisser peutRembourser onMiseAJour={vi.fn()} />
      </QueryClientProvider>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent(
      /Commande annulée après paiement : le client a payé 6\s900 FCFA, pensez à le rembourser\./,
    )
  })
})
