/**
 * Les données sont posées directement dans le cache de la requête, comme
 * dans les autres tests d'écran : sans attente asynchrone, le test ne
 * dépend pas de la charge de la machine (une réponse simulée arrivait
 * après le délai de findByText pendant la suite complète).
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { HistoriqueConsultations } from '../api/consultationsPlateforme'
import { SectionConsultationsPlateforme } from './SectionConsultationsPlateforme'

vi.mock('../api/consultationsPlateforme', () => ({ recupererConsultationsPlateforme: vi.fn() }))

function afficher(historique: HistoriqueConsultations) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  client.setQueryData(['consultations-plateforme'], historique)

  render(
    <QueryClientProvider client={client}>
      <SectionConsultationsPlateforme />
    </QueryClientProvider>,
  )
}

describe("Consultations par l'équipe de la plateforme", () => {
  it('dit qui a consulté quelle page, et quand', () => {
    afficher({
      total: 1,
      data: [
        {
          id: 1,
          qui: 'Marius Kouame',
          page: 'commandes',
          page_libelle: 'Liste des commandes',
          consulte_le: '2026-10-21T14:30:00Z',
        },
      ],
    })

    expect(screen.getByText('Marius Kouame')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: "Consultations par l'équipe de la plateforme" })).toBeInTheDocument()
    expect(screen.getByRole('listitem')).toHaveTextContent(/Marius Kouame a consulté Liste des commandes/)
    expect(screen.getByRole('listitem')).toHaveTextContent(/21 octobre 2026/)
  })

  it("l'absence de consultation est dite en clair", () => {
    afficher({ total: 0, data: [] })

    expect(
      screen.getByText(/Personne de l'équipe de la plateforme n'a consulté vos données commerciales/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument()
  })
})
