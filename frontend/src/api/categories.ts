/**
 * Lecture, création et archivage des catégories de l'établissement courant.
 * Pas de renommage ici : l'écran Catégories (PageCategories) ne le propose
 * pas, cette étape ne le demande pas.
 */
import { client } from '../lib/client'

export type Categorie = {
  id: number
  nom: string
  slug: string
  description: string | null
  ordre: number
  statut: 'actif' | 'inactif'
  // Deux niveaux au plus : le parent est toujours une catégorie principale
  // (garanti par la base, voir la migration ajouter_parent_aux_categories).
  parent_id: number | null
  // Absent tant que le contrôleur ne l'a pas chargé (voir whenCounted côté
  // API) — en pratique toujours présent ici, /api/categories le charge
  // systématiquement.
  produits_count?: number
  created_at: string
  updated_at: string
}

export async function recupererCategories(): Promise<Categorie[]> {
  const { data } = await client.get<{ data: Categorie[] }>('/api/categories')

  return data.data
}

export async function creerCategorie(nom: string, parentId: number | null = null): Promise<Categorie> {
  const { data } = await client.post<{ data: Categorie }>('/api/categories', { nom, parent_id: parentId })

  return data.data
}

export async function changerParentCategorie(id: number, parentId: number | null): Promise<Categorie> {
  const { data } = await client.put<{ data: Categorie }>(`/api/categories/${id}`, { parent_id: parentId })

  return data.data
}

/** « Chaussures › Sandales » pour une sous-catégorie, le nom seul sinon. */
export function cheminCategorie(categorie: Pick<Categorie, 'nom' | 'parent_id'>, toutes: Categorie[]): string {
  const parent = categorie.parent_id === null ? undefined : toutes.find((autre) => autre.id === categorie.parent_id)

  return parent ? `${parent.nom} › ${categorie.nom}` : categorie.nom
}

/**
 * Archive (statut "inactif"), ne supprime rien : les produits de la
 * catégorie restent intacts, seulement détachés visuellement de la liste
 * des catégories actives.
 */
export async function archiverCategorie(id: number): Promise<void> {
  await client.delete(`/api/categories/${id}`)
}

/**
 * Repasse une catégorie archivée en actif. Réutilise l'endpoint de
 * modification existant (statut seul — UpdateCategorieRequest l'accepte en
 * mise à jour partielle) : pas de route dédiée nécessaire, contrairement à
 * un produit dont le statut "archive" a des règles propres.
 */
export async function reactiverCategorie(id: number): Promise<Categorie> {
  const { data } = await client.put<{ data: Categorie }>(`/api/categories/${id}`, { statut: 'actif' })

  return data.data
}
