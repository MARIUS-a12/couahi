/**
 * Ce que l'équipe de la plateforme a consulté des données commerciales de
 * l'établissement courant (commandes, clients, chiffre d'affaires) : qui,
 * quand, quelle page — jamais le contenu vu. Lecture seule, réservée à
 * gerer_parametres.
 */
import { client } from '../lib/client'

export type ConsultationPlateforme = {
  id: number
  qui: string
  page: 'commandes' | 'detail_commande' | 'clients' | 'chiffre_affaires'
  page_libelle: string
  consulte_le: string
}

export type HistoriqueConsultations = {
  data: ConsultationPlateforme[]
  total: number
}

export async function recupererConsultationsPlateforme(): Promise<HistoriqueConsultations> {
  const { data } = await client.get<HistoriqueConsultations>('/api/parametres/consultations-plateforme')

  return data
}
