/**
 * Lot D — les demandes WhatsApp (DemandeWhatsappController côté API). Au
 * clic sur wa.me, on ne sait rien du client : aucun nom, aucun téléphone,
 * et ces types n'en prévoient aucun.
 */
import { client } from '../lib/client'

export type StatutDemande = 'nouvelle' | 'traitee' | 'ignoree'

export type DemandeWhatsapp = {
  id: number
  code: string
  reference: string
  produit: { id: number; nom: string | null }
  variante: { id: number; nom: string } | null
  photo: string | null
  cree_le: string
  expire_le: string
  expiree: boolean
  statut: StatutDemande
  statut_libelle: string
  statut_change_le: string | null
  commande: { id: number; numero: string } | null
}

export type DemandesPaginees = {
  data: DemandeWhatsapp[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export async function listerDemandes(statut: StatutDemande, page: number): Promise<DemandesPaginees> {
  const { data } = await client.get<DemandesPaginees>('/api/demandes-whatsapp', { params: { statut, page } })

  return data
}

export async function compterDemandesNouvelles(): Promise<number> {
  const { data } = await client.get<{ data: { nouvelles: number } }>('/api/demandes-whatsapp/compte')

  return data.data.nouvelles
}

export async function changerStatutDemande(id: number, statut: 'traitee' | 'ignoree'): Promise<DemandeWhatsapp> {
  const { data } = await client.patch<{ data: DemandeWhatsapp }>(`/api/demandes-whatsapp/${id}`, { statut })

  return data.data
}
