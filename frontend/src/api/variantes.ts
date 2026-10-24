/**
 * Options, valeurs et combinaisons d'un produit (B2). Reflète
 * StructureVariantesResource côté API : chaque écriture renvoie la
 * structure complète, que l'écran substitue à la précédente — jamais de
 * recalcul local qui pourrait diverger du serveur.
 */
import { client } from '../lib/client'
import type { ModeStock } from './produits'

export type ValeurOption = {
  id: number
  libelle: string
  ordre: number
  archivee: boolean
  nombre_variantes: number
  stock_total: number
  nombre_commandes: number
}

export type OptionProduit = {
  id: number
  nom: string
  ordre: number
  valeurs: ValeurOption[]
}

export type Variante = {
  id: number
  nom: string
  valeur_ids: number[]
  quantite_stock: number
  quantite_reservee: number
  prix: number | null
  media_id: number | null
  disponible: boolean
  active: boolean
  nombre_commandes: number
}

export type StructureVariantes = {
  produit_id: number
  mode_stock: ModeStock
  prix_produit: number
  options: OptionProduit[]
  variantes: Variante[]
}

export type Suggestion = { nom: string; valeurs: string[] }

export type NouvelleCombinaison = {
  valeur_ids: number[]
  quantite_stock?: number
  media_id?: number | null
}

export type ModificationVariante = Partial<Pick<Variante, 'quantite_stock' | 'prix' | 'media_id' | 'disponible'>> & {
  statut?: 'actif' | 'inactif'
}

type Reponse = { data: StructureVariantes; resultat?: 'archivee' | 'supprimee' }

const base = (produitId: number) => `/api/produits/${produitId}`

export async function recupererVariantes(produitId: number): Promise<StructureVariantes> {
  const { data } = await client.get<Reponse>(`${base(produitId)}/variantes`)

  return data.data
}

export async function recupererSuggestions(): Promise<Suggestion[]> {
  const { data } = await client.get<{ data: Suggestion[] }>('/api/catalogue/suggestions-variantes')

  return data.data
}

export async function ajouterOption(produitId: number, nom: string, valeurDesVariantesExistantes?: string): Promise<Reponse> {
  const { data } = await client.post<Reponse>(`${base(produitId)}/options`, {
    nom,
    valeur_des_variantes_existantes: valeurDesVariantesExistantes || null,
  })

  return data
}

export async function renommerOption(produitId: number, optionId: number, nom: string): Promise<Reponse> {
  return (await client.patch<Reponse>(`${base(produitId)}/options/${optionId}`, { nom })).data
}

export async function reordonnerOptions(produitId: number, ordre: number[]): Promise<Reponse> {
  return (await client.put<Reponse>(`${base(produitId)}/options/ordre`, { ordre })).data
}

export async function retirerOption(produitId: number, optionId: number): Promise<Reponse> {
  return (await client.delete<Reponse>(`${base(produitId)}/options/${optionId}`)).data
}

export async function ajouterValeur(produitId: number, optionId: number, libelle: string): Promise<Reponse> {
  return (await client.post<Reponse>(`${base(produitId)}/options/${optionId}/valeurs`, { libelle })).data
}

export async function reordonnerValeurs(produitId: number, optionId: number, ordre: number[]): Promise<Reponse> {
  return (await client.put<Reponse>(`${base(produitId)}/options/${optionId}/valeurs/ordre`, { ordre })).data
}

export async function renommerValeur(produitId: number, valeurId: number, libelle: string): Promise<Reponse> {
  return (await client.patch<Reponse>(`${base(produitId)}/valeurs/${valeurId}`, { libelle })).data
}

export async function archiverValeur(produitId: number, valeurId: number): Promise<Reponse> {
  return (await client.delete<Reponse>(`${base(produitId)}/valeurs/${valeurId}`)).data
}

export async function restaurerValeur(produitId: number, valeurId: number): Promise<Reponse> {
  return (await client.post<Reponse>(`${base(produitId)}/valeurs/${valeurId}/restaurer`)).data
}

export async function creerVariantes(produitId: number, combinaisons: NouvelleCombinaison[]): Promise<Reponse> {
  return (await client.post<Reponse>(`${base(produitId)}/variantes`, { combinaisons })).data
}

export async function modifierVariante(produitId: number, varianteId: number, modification: ModificationVariante): Promise<Reponse> {
  return (await client.patch<Reponse>(`${base(produitId)}/variantes/${varianteId}`, modification)).data
}

export async function attribuerImage(produitId: number, varianteIds: number[], mediaId: number | null): Promise<Reponse> {
  return (await client.put<Reponse>(`${base(produitId)}/variantes/photo`, { variante_ids: varianteIds, media_id: mediaId })).data
}

export async function retirerVariante(produitId: number, varianteId: number): Promise<Reponse> {
  return (await client.delete<Reponse>(`${base(produitId)}/variantes/${varianteId}`)).data
}
