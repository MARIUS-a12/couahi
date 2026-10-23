/**
 * Étape 13 — un seul bouton de paiement, selon le mode proposé par
 * l'établissement (calculé côté serveur, voir modePaiementPropose) : jamais
 * "Payer maintenant" et "Payer par Mobile Money" ensemble, et aucun bouton
 * de paiement quand rien n'est configuré — le panier et WhatsApp restent.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { ModePaiementVitrine, VarianteVitrine } from '../api/vitrine'
import { lireLignesPanier } from '../lib/panier'
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
                onVarianteManquante={() => {}}
              />
            }
          />
          <Route path="/commander" element={<h1>Passer la commande</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const VARIANTES: VarianteVitrine[] = [
  { id: 11, nom: '1 kg', prix: 1000, disponible: true },
  { id: 12, nom: 'Sac de 25 kg', prix: 21000, disponible: true },
]

/** Produit à variantes, aucune choisie : les boutons doivent guider, pas se taire. */
function afficherSansVariante(onVarianteManquante: () => void) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/produit/riz']}>
        <Routes>
          <Route
            path="/produit/riz"
            element={
              <BoutonsAchatVitrine
                produitId={3}
                prix={1000}
                disponible
                variantes={VARIANTES}
                varianteChoisie={null}
                modePaiement="mobile_money"
                onVarianteManquante={onVarianteManquante}
              />
            }
          />
          <Route path="/commander" element={<h1>Passer la commande</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe("boutons d'achat sans variante choisie", () => {
  it('restent actifs, et chaque clic guide vers les variantes sans rien commander', () => {
    window.localStorage.clear()
    const guider = vi.fn()
    const ouvrir = vi.spyOn(window, 'open').mockImplementation(() => null)
    afficherSansVariante(guider)

    for (const nom of [/Payer par Mobile Money/, /Commander sur WhatsApp/, /Ajouter au panier/]) {
      const bouton = screen.getByRole('button', { name: nom })
      expect(bouton).toBeEnabled()
      fireEvent.click(bouton)
    }

    expect(guider).toHaveBeenCalledTimes(3)
    expect(screen.queryByRole('heading', { name: 'Passer la commande' })).not.toBeInTheDocument()
    expect(ouvrir).not.toHaveBeenCalled()
    expect(lireLignesPanier()).toEqual([])
    ouvrir.mockRestore()
  })

  it('le message est au-dessus des boutons et leur est lié par aria-describedby', () => {
    afficherSansVariante(() => {})

    const message = screen.getByText('Choisissez une variante ci-dessus pour continuer.')
    for (const bouton of screen.getAllByRole('button')) {
      expect(bouton).toHaveAccessibleDescription('Choisissez une variante ci-dessus pour continuer.')
      expect(message.compareDocumentPosition(bouton) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    }
  })
})

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
