/**
 * L'écran « Livraison » du commerçant (voir LivraisonController côté API).
 * Chaque écriture renvoie l'écran entier : l'interface remplace sa copie,
 * sans réconcilier deux listes.
 */
import { client } from '../lib/client'

export type ZoneLivraison = {
  id: number
  nom: string
  /** En francs CFA entiers ; 0 : livraison offerte dans cette commune. */
  frais: number
}

export type Livraison = {
  par_commune: boolean
  zones: ZoneLivraison[]
}

export type ZoneLivraisonPayload = { nom: string; frais: number }

export async function recupererLivraison(): Promise<Livraison> {
  const { data } = await client.get<{ data: Livraison }>('/api/parametres/livraison')
  return data.data
}

export async function choisirFacturation(parCommune: boolean): Promise<Livraison> {
  const { data } = await client.put<{ data: Livraison }>('/api/parametres/livraison', { par_commune: parCommune })
  return data.data
}

export async function ajouterZone(zone: ZoneLivraisonPayload): Promise<Livraison> {
  const { data } = await client.post<{ data: Livraison }>('/api/parametres/livraison/zones', zone)
  return data.data
}

export async function modifierZone(id: number, zone: ZoneLivraisonPayload): Promise<Livraison> {
  const { data } = await client.put<{ data: Livraison }>(`/api/parametres/livraison/zones/${id}`, zone)
  return data.data
}

export async function retirerZone(id: number): Promise<Livraison> {
  const { data } = await client.delete<{ data: Livraison }>(`/api/parametres/livraison/zones/${id}`)
  return data.data
}
