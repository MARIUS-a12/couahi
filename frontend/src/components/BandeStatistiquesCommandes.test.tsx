/**
 * Lot F — sans voir_statistiques, le serveur n'envoie aucun montant : les
 * cartes du chiffre d'affaires et du panier moyen disparaissent, jamais un
 * faux « 0 FCFA ».
 */
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BandeStatistiquesCommandes } from './BandeStatistiquesCommandes'

let permissions: string[] = []
let statistiques: Record<string, number> = {}
vi.mock('../hooks/useMoi', () => ({ useMoi: () => ({ data: { permissions } }) }))
vi.mock('../hooks/useStatistiquesCommandes', () => ({ useStatistiquesCommandes: () => ({ data: statistiques, isPending: false }) }))

describe('bande des statistiques de commandes', () => {
  it("sans voir_statistiques : les comptes, aucun montant", () => {
    permissions = ['voir_commandes']
    statistiques = { commandes_du_jour: 4, en_attente: 2, en_attente_payees: 0 }
    render(<BandeStatistiquesCommandes />)

    expect(screen.getByText('Commandes du jour')).toBeInTheDocument()
    expect(screen.queryByText("Chiffre d'affaires du jour")).not.toBeInTheDocument()
    expect(screen.queryByText('Panier moyen du mois')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/FCFA/)
  })

  it('avec voir_statistiques : les montants', () => {
    permissions = ['voir_commandes', 'voir_statistiques']
    statistiques = { commandes_du_jour: 4, en_attente: 2, en_attente_payees: 0, chiffre_affaires_jour: 31000, panier_moyen_mois: 15500 }
    render(<BandeStatistiquesCommandes />)

    expect(screen.getByText(/31\s000 FCFA/)).toBeInTheDocument()
    expect(screen.getByText(/15\s500 FCFA/)).toBeInTheDocument()
  })
})
