/**
 * Étape 13 — un seul bouton de paiement, selon le mode proposé par
 * l'établissement (calculé côté serveur, voir modePaiementPropose) : jamais
 * "Payer maintenant" et "Payer par Mobile Money" ensemble, et aucun bouton
 * de paiement quand rien n'est configuré — le panier et WhatsApp restent.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { ModePaiementVitrine } from '../api/vitrine'
import { BoutonsAchatVitrine } from './BoutonsAchatVitrine'

function afficher(modePaiement: ModePaiementVitrine | null) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/produit/pagne']}>
        <Routes>
          <Route
            path="/produit/pagne"
            element={
              <BoutonsAchatVitrine
                produitId={1}
                prix={20309}
                disponible
                variantes={[]}
                varianteChoisie={null}
                modePaiement={modePaiement}
              />
            }
          />
          <Route path="/commander" element={<h1>Passer la commande</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function boutonsDePaiement(): string[] {
  return screen
    .getAllByRole('button')
    .map((bouton) => bouton.textContent ?? '')
    .filter((texte) => texte.startsWith('Payer'))
}

describe('boutons de paiement de la fiche produit', () => {
  it('sans aucune configuration : aucun bouton de paiement, le panier et WhatsApp restent', () => {
    afficher(null)

    expect(boutonsDePaiement()).toEqual([])
    expect(screen.getByRole('button', { name: /Commander sur WhatsApp/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Ajouter au panier/ })).toBeInTheDocument()
  })

  it('avec CinetPay : "Payer maintenant", et pas le Mobile Money', () => {
    afficher('cinetpay')

    expect(boutonsDePaiement()).toEqual(['Payer maintenant'])
  })

  it('avec seulement le Mobile Money : "Payer par Mobile Money"', () => {
    afficher('mobile_money')

    expect(boutonsDePaiement()).toEqual(['Payer par Mobile Money'])
  })

  it('"Payer maintenant" mène à la validation de commande, jamais à un panneau "bientôt disponible"', () => {
    afficher('cinetpay')

    fireEvent.click(screen.getByRole('button', { name: /Payer maintenant/ }))

    expect(screen.getByRole('heading', { name: 'Passer la commande' })).toBeInTheDocument()
    expect(screen.queryByText(/bientôt disponible/i)).not.toBeInTheDocument()
  })
})
