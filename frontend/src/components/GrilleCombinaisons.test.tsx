/**
 * B2 — la grille des combinaisons : un en-tête coche une colonne entière,
 * rien n'est créé avant l'action explicite, le brouillon est signalé (pour
 * empêcher de quitter la page), et décocher une variante déjà vendue
 * prévient qu'elle sera archivée, pas effacée.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { StructureVariantes } from '../api/variantes'
import { creerVariantes, retirerVariante } from '../api/variantes'
import { GrilleCombinaisons } from './GrilleCombinaisons'

vi.mock('../api/variantes', () => ({
  creerVariantes: vi.fn(async () => ({ data: {} })),
  retirerVariante: vi.fn(async () => ({ data: {}, resultat: 'archivee' })),
  modifierVariante: vi.fn(async () => ({ data: {} })),
}))

const valeur = (id: number, libelle: string, ordre: number) => ({
  id,
  libelle,
  ordre,
  archivee: false,
  nombre_variantes: 0,
  stock_total: 0,
  nombre_commandes: 0,
})

const structure: StructureVariantes = {
  produit_id: 20,
  mode_stock: 'compte',
  prix_produit: 18000,
  options: [
    { id: 1, nom: 'Pointure', ordre: 1, valeurs: [valeur(11, '39', 1), valeur(12, '40', 2)] },
    { id: 2, nom: 'Coloris', ordre: 2, valeurs: [valeur(21, 'Noir', 1), valeur(22, 'Marron', 2)] },
  ],
  variantes: [
    { id: 100, nom: '39 / Noir', valeur_ids: [11, 21], quantite_stock: 2, quantite_reservee: 0, prix: null, media_id: null, disponible: true, active: true, nombre_commandes: 1 },
  ],
}

function afficher() {
  const onBrouillon = vi.fn()
  const ecrire = vi.fn(async (action: () => Promise<unknown>) => {
    await action()
    return {}
  })
  render(<GrilleCombinaisons produitId={20} structure={structure} ecrire={ecrire} enCours={false} onBrouillon={onBrouillon} />)

  return { onBrouillon, ecrire }
}

afterEach(() => {
  vi.clearAllMocks()
  vi.restoreAllMocks()
})

describe('GrilleCombinaisons', () => {
  it('un en-tête coche toute la colonne, et rien n’est créé avant l’action explicite', async () => {
    const { onBrouillon } = afficher()

    expect(screen.getByRole('checkbox', { name: '39 / Noir' })).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByRole('checkbox', { name: '39 / Marron' })).toHaveAttribute('aria-checked', 'false')

    fireEvent.click(screen.getByRole('button', { name: 'Cocher ou décocher toute la colonne Marron' }))

    expect(screen.getByRole('checkbox', { name: '39 / Marron' })).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByRole('checkbox', { name: '40 / Marron' })).toHaveAttribute('aria-checked', 'true')
    expect(creerVariantes).not.toHaveBeenCalled()
    await waitFor(() => expect(onBrouillon).toHaveBeenLastCalledWith(2))

    fireEvent.click(screen.getByRole('button', { name: 'Créer les 2 variantes' }))

    await waitFor(() =>
      expect(creerVariantes).toHaveBeenCalledWith(20, [
        { valeur_ids: [11, 22], quantite_stock: 0 },
        { valeur_ids: [12, 22], quantite_stock: 0 },
      ]),
    )
  })

  it('décocher une variante vendue prévient qu’elle sera archivée, pas effacée', async () => {
    const confirmation = vi.spyOn(window, 'confirm').mockReturnValue(true)
    afficher()

    fireEvent.click(screen.getByRole('checkbox', { name: '39 / Noir' }))
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer ces changements' }))

    expect(confirmation.mock.calls[0][0]).toContain('39 / Noir figure(nt) dans des commandes : elle(s) sera(ont) archivée(s), pas effacée(s).')
    await waitFor(() => expect(retirerVariante).toHaveBeenCalledWith(20, 100))
  })

  it('annuler la confirmation ne retire rien', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    afficher()

    fireEvent.click(screen.getByRole('checkbox', { name: '39 / Noir' }))
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer ces changements' }))

    expect(retirerVariante).not.toHaveBeenCalled()
  })
})
