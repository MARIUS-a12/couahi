/**
 * Les pièges de l'écran des variantes, relevés sur un vrai produit créé à
 * la main (« pantalon homme ») : la virgule, le bandeau qui rassurait à
 * tort, et des options sans aucune variante sans que rien ne le dise.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { StructureVariantes, ValeurOption } from '../api/variantes'
import { ajouterValeur, ajouterValeurs, recupererSuggestions, recupererVariantes } from '../api/variantes'
import { SectionVariantes } from './SectionVariantes'

vi.mock('../api/variantes', async (original) => ({
  ...(await original<typeof import('../api/variantes')>()),
  recupererVariantes: vi.fn(),
  recupererSuggestions: vi.fn(),
  ajouterValeur: vi.fn(),
  ajouterValeurs: vi.fn(),
}))

function valeur(id: number, libelle: string): ValeurOption {
  return { id, libelle, ordre: id, archivee: false, nombre_variantes: 0, stock_total: 0, nombre_commandes: 0 }
}

const pantalon: StructureVariantes = {
  produit_id: 30,
  mode_stock: 'compte',
  prix_produit: 12000,
  options: [
    { id: 1, nom: 'Taille', ordre: 1, valeurs: [valeur(11, 'M'), valeur(12, 'L')] },
    { id: 2, nom: 'Couleur', ordre: 2, valeurs: [valeur(21, 'Bleu')] },
  ],
  variantes: [],
}

function afficher(structure: StructureVariantes = pantalon) {
  vi.mocked(recupererVariantes).mockResolvedValue(structure)
  const onNonEnregistre = vi.fn()
  const onEcrituresEnCours = vi.fn()
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SectionVariantes produitId={30} photos={[]} onNonEnregistre={onNonEnregistre} onEcrituresEnCours={onEcrituresEnCours} />
    </QueryClientProvider>,
  )
  return { onNonEnregistre, onEcrituresEnCours }
}

const champCouleur = () => screen.getByLabelText('Nouvelle valeur de Couleur')

describe('écran des variantes', () => {
  beforeEach(() => {
    vi.mocked(recupererSuggestions).mockReset().mockResolvedValue([])
    vi.mocked(ajouterValeur).mockReset().mockResolvedValue({ data: pantalon })
    vi.mocked(ajouterValeurs).mockReset().mockResolvedValue({ data: pantalon })
  })

  it('« blanc, noir » donne deux valeurs, en une requête', async () => {
    afficher()
    fireEvent.change(await screen.findByLabelText('Nouvelle valeur de Couleur'), { target: { value: 'blanc, noir' } })

    expect(screen.getByText('2 valeurs : blanc, noir.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter les 2' }))

    await waitFor(() => expect(ajouterValeurs).toHaveBeenCalledWith(30, 2, ['blanc', 'noir']))
    expect(ajouterValeur).not.toHaveBeenCalled()
  })

  it('un texte tapé mais pas ajouté : le bandeau ne dit jamais « Tout est enregistré »', async () => {
    const { onNonEnregistre, onEcrituresEnCours } = afficher({
      ...pantalon,
      variantes: [{ id: 1, nom: 'M / Bleu', valeur_ids: [11, 21], quantite_stock: 3, quantite_reservee: 0, prix: null, media_id: null, disponible: true, active: true, nombre_commandes: 0 }],
    })
    // Une première écriture réussie : c'est elle qui faisait apparaître « Tout est enregistré ».
    fireEvent.change(await screen.findByLabelText('Nouvelle valeur de Couleur'), { target: { value: 'Rouge' } })
    fireEvent.keyDown(champCouleur(), { key: 'Enter' })
    await waitFor(() => expect(ajouterValeur).toHaveBeenCalledWith(30, 2, 'Rouge'))
    expect(await screen.findByText('Tout est enregistré.')).toBeInTheDocument()

    fireEvent.change(champCouleur(), { target: { value: 'blanc, noir' } })

    expect(screen.queryByText('Tout est enregistré.')).not.toBeInTheDocument()
    expect(screen.getByText('Pas encore enregistré')).toBeInTheDocument()
    expect(screen.getByText(/« blanc, noir » n'est pas encore ajouté à Couleur/)).toBeInTheDocument()
    await waitFor(() => expect(onNonEnregistre).toHaveBeenLastCalledWith(expect.stringContaining('« blanc, noir »')))
    expect(onEcrituresEnCours).toHaveBeenLastCalledWith(1)

    fireEvent.change(champCouleur(), { target: { value: '' } })
    expect(await screen.findByText('Tout est enregistré.')).toBeInTheDocument()
    await waitFor(() => expect(onNonEnregistre).toHaveBeenLastCalledWith(null))
  })

  it('un nom d’option tapé mais pas ajouté est signalé, puis oublié si on annule', async () => {
    const { onNonEnregistre } = afficher()
    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une option' }))
    fireEvent.change(screen.getByLabelText("Nom de l'option"), { target: { value: 'Longueur' } })

    expect(screen.getByText("L'option « Longueur » n'est pas encore ajoutée : touchez « Ajouter l'option ».")).toBeInTheDocument()
    await waitFor(() => expect(onNonEnregistre).toHaveBeenLastCalledWith(expect.stringContaining('« Longueur »')))

    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByText(/« Longueur »/)).not.toBeInTheDocument()
    await waitFor(() => expect(onNonEnregistre).toHaveBeenLastCalledWith(null))
  })

  it('des options sans aucune variante : le back-office le dit', async () => {
    afficher()

    expect(
      await screen.findByText(/Vous avez défini Taille et Couleur, mais aucune variante n'est créée : cochez les combinaisons ci-dessous\./),
    ).toBeInTheDocument()
  })

  it('une variante créée : plus d’avertissement', async () => {
    afficher({
      ...pantalon,
      variantes: [{ id: 1, nom: 'M / Bleu', valeur_ids: [11, 21], quantite_stock: 3, quantite_reservee: 0, prix: null, media_id: null, disponible: true, active: true, nombre_commandes: 0 }],
    })

    await screen.findByLabelText('Nouvelle valeur de Couleur')
    expect(screen.queryByText(/aucune variante n'est créée/)).not.toBeInTheDocument()
  })

  it('des cases cochées non créées sont signalées au formulaire', async () => {
    const { onNonEnregistre } = afficher()
    fireEvent.click(await screen.findByRole('checkbox', { name: 'M / Bleu' }))

    expect(screen.getByText(/1 combinaison cochée n’est pas encore créée : touchez « Créer la variante »/, { selector: 'div[role=status] p' })).toBeInTheDocument()
    await waitFor(() => expect(onNonEnregistre).toHaveBeenLastCalledWith(expect.stringContaining('Créer la variante')))
    expect(screen.queryByText(/aucune variante n'est créée/)).not.toBeInTheDocument()
  })
})
