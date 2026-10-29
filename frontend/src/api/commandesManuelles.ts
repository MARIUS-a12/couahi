/**
 * Lot C — la saisie manuelle au back-office (voir
 * CommandeManuelleController côté API). Aucun prix n'est jamais envoyé :
 * le serveur relit tout en base.
 */
import axios from 'axios'
import { client } from '../lib/client'

export type CanalManuel = 'comptoir' | 'whatsapp' | 'telephone'

export type ArticleVendable = {
  produit_id: number
  variante_id: number | null
  nom: string
  variante: string | null
  prix: number
  stock: { mode: 'compte'; disponible: number } | { mode: 'interrupteur'; disponible: boolean }
  photo: string | null
}

export type ZoneProposee = { id: number; nom: string; frais: number }

export type ClientConnu = { nom: string | null; commune: string | null; quartier: string | null }

export type CommandeManuellePayload = {
  canal: CanalManuel
  lignes: { produit_id: number; variante_id: number | null; quantite: number }[]
  client?: { telephone: string; nom: string }
  commune?: string
  autre_commune?: boolean
  quartier?: string
  reference?: string
  cle_idempotence: string
  confirmer_doublon?: boolean
}

export type CommandeManuelleCreee = { id: number; numero: string; statut: string; total: number }

export type DoublonPossible = { id: number; numero: string; statut_libelle: string; total: number; created_at: string }

export async function rechercherArticles(recherche: string, produit?: number | null): Promise<ArticleVendable[]> {
  const { data } = await client.get<{ data: ArticleVendable[] }>('/api/commandes/manuelles/articles', {
    params: { recherche: recherche || undefined, produit: produit ?? undefined },
  })
  return data.data
}

/** Les communes et leurs frais, et les frais fixes de « Autre commune » (null : à convenir). */
export async function recupererZonesProposees(): Promise<{ zones: ZoneProposee[]; autreCommune: { frais: number } | null }> {
  const { data } = await client.get<{ data: ZoneProposee[]; autre_commune: { frais: number } | null }>('/api/commandes/manuelles/zones')
  return { zones: data.data, autreCommune: data.autre_commune }
}

export async function trouverClient(telephone: string): Promise<ClientConnu | null> {
  const { data } = await client.get<{ data: ClientConnu | null }>('/api/commandes/manuelles/client', { params: { telephone } })
  return data.data
}

export type ReferenceReconnue = {
  code: string
  /** Nul : produit archivé, variante retirée, ou variante non précisée. */
  article: ArticleVendable | null
  commande: { id: number; numero: string } | null
}

/** Le code d'une référence collée (le message entier ou le seul code), sinon null. */
export function codeReference(collee: string): string | null {
  const trouve = collee.match(/\[REF:([A-Za-z0-9]{8})\]/)
  if (trouve) {
    return trouve[1]
  }
  return /^[A-Za-z0-9]{8}$/.test(collee.trim()) ? collee.trim() : null
}

/** Null quand la référence n'existe pas dans cette boutique. */
export async function reconnaitreReference(code: string): Promise<ReferenceReconnue | null> {
  try {
    const { data } = await client.get<{ data: ReferenceReconnue }>('/api/commandes/manuelles/reference', { params: { reference: code } })
    return data.data
  } catch (erreur) {
    if (axios.isAxiosError(erreur) && erreur.response?.status === 404) {
      return null
    }
    throw erreur
  }
}

export async function saisirCommande(payload: CommandeManuellePayload): Promise<CommandeManuelleCreee> {
  const { data } = await client.post<{ data: CommandeManuelleCreee }>('/api/commandes/manuelles', payload)
  return data.data
}
