import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { HistoriqueConsultations } from '../api/consultationsPlateforme'
import { recupererConsultationsPlateforme } from '../api/consultationsPlateforme'
import { SectionConsultationsPlateforme } from './SectionConsultationsPlateforme'

vi.mock('../api/consultationsPlateforme', () => ({ recupererConsultationsPlateforme: vi.fn() }))

function afficher(historique: HistoriqueConsultations) {
  vi.mocked(recupererConsultationsPlateforme).mockResolvedValue(historique)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={client}>
      <SectionConsultationsPlateforme />
    </QueryClientProvider>,
  )
}

describe("Consultations par l'équipe de la plateforme", () => {
  it('dit qui a consulté quelle page, et quand', async () => {
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

    expect(await screen.findByText('Marius Kouame')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: "Consultations par l'équipe de la plateforme" })).toBeInTheDocument()
    expect(screen.getByRole('listitem')).toHaveTextContent(/Marius Kouame a consulté Liste des commandes/)
    expect(screen.getByRole('listitem')).toHaveTextContent(/21 octobre 2026/)
  })

  it("l'absence de consultation est dite en clair", async () => {
    afficher({ total: 0, data: [] })

    expect(
      await screen.findByText(/Personne de l'équipe de la plateforme n'a consulté vos données commerciales/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument()
  })
})
