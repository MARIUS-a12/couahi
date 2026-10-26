/**
 * Lot C — la saisie manuelle au back-office (voir
 * CommandeManuelleController côté API). Aucun prix n'est jamais envoyé :
 * le serveur relit tout en base.
 */
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

export type ClientConnu = { nom: string; commune: string | null; quartier: string | null }

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

export async function recupererZonesProposees(): Promise<ZoneProposee[]> {
  const { data } = await client.get<{ data: ZoneProposee[] }>('/api/commandes/manuelles/zones')
  return data.data
}

export async function trouverClient(telephone: string): Promise<ClientConnu | null> {
  const { data } = await client.get<{ data: ClientConnu | null }>('/api/commandes/manuelles/client', { params: { telephone } })
  return data.data
}

export async function saisirCommande(payload: CommandeManuellePayload): Promise<CommandeManuelleCreee> {
  const { data } = await client.post<{ data: CommandeManuelleCreee }>('/api/commandes/manuelles', payload)
  return data.data
}
