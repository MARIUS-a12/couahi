/**
 * Bug — page d'identité vide pour le super-admin (voir aussi le correctif
 * backend DefinirEtablissementCible) : quand service.recuperer() échoue, le
 * composant restait bloqué dans son état "chargement" pour toujours (le
 * chemin d'erreur ne retombait jamais chargement à false), affichant un
 * squelette indéfiniment au lieu du message d'erreur déjà prévu. Ce test
 * verrouille le comportement attendu : un état d'erreur lisible, avec un
 * bouton pour réessayer — jamais une page bloquée en chargement. Le
 * nettoyage du DOM entre les tests est déjà géré globalement par
 * src/tests/setup.ts.
 */
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { FormulaireIdentiteEtablissement } from './FormulaireIdentiteEtablissement'
import type { ServiceIdentite } from '../api/identite'

function serviceEnEchec(): ServiceIdentite {
  return {
    recuperer: vi.fn().mockRejectedValue(new Error('échec réseau simulé')),
    mettreAJour: vi.fn(),
    uploaderLogo: vi.fn(),
    supprimerLogo: vi.fn(),
  }
}

describe("FormulaireIdentiteEtablissement — état d'erreur de chargement", () => {
  it("affiche l'état d'erreur avec un bouton Réessayer quand le chargement échoue, jamais une page bloquée en chargement", async () => {
    const service = serviceEnEchec()

    render(<FormulaireIdentiteEtablissement service={service} />)

    const boutonReessayer = await screen.findByRole('button', { name: 'Réessayer' })
    expect(boutonReessayer).toBeInTheDocument()
  })

  it('réessaie un appel à service.recuperer() quand on clique sur Réessayer', async () => {
    const service = serviceEnEchec()

    render(<FormulaireIdentiteEtablissement service={service} />)

    const boutonReessayer = await screen.findByRole('button', { name: 'Réessayer' })
    boutonReessayer.click()

    expect(service.recuperer).toHaveBeenCalledTimes(2)
  })
})
