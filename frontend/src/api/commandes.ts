/**
 * Gestion des commandes du back-office (Étape 9). Les types reflètent
 * CommandeResource côté API : une seule Resource pour la liste (légère) et
 * le détail (lignes + historique), voir sa docblock.
 */
import { client } from '../lib/client'

export type StatutCommande = 'attente_paiement' | 'payee' | 'prete' | 'livree' | 'expiree' | 'annulee'
export type CanalCommande = 'web' | 'whatsapp'

export type ClientCommande = {
  nom: string
  telephone: string
}

export type Commande = {
  id: number
  numero: string
  statut: StatutCommande
  statut_libelle: string
  canal: CanalCommande
  sous_total: number
  frais_livraison: number
  total: number
  note: string | null
  created_at: string
  nombre_articles: number
  en_retard: boolean
  client?: ClientCommande
  // Étape 13 — le paiement a sa propre machine d'état, distincte du statut.
  mode_paiement: 'cinetpay' | 'mobile_money' | 'especes' | null
  paiement: PaiementCommande
}

export type PaiementCommande = {
  confirme: boolean
  confirme_le: string | null
  confirme_par?: string | null
  montant_recu: number | null
  // montant_recu - total : négatif quand le client a envoyé moins.
  ecart: number | null
  reference: string | null
  en_retard: boolean
}

export type LigneCommande = {
  id: number
  nom: string
  variante: string | null
  photo: { webp: string | null; jpg: string | null } | null
  prix_unitaire: number
  quantite: number
  total: number
}

export type EntreeHistoriqueCommande = {
  ancien_statut: string | null
  nouveau_statut: string
  utilisateur: string | null
  motif: string | null
  // "paiement_confirme" pour une entrée qui n'est pas un changement de
  // statut (Étape 13) ; null pour toutes les autres.
  evenement: string | null
  date: string
}

export type CommandeDetail = Commande & {
  zone_livraison: { nom: string; frais: number } | null
  // Correctif livraison : null pour toute commande antérieure à ces
  // colonnes — jamais une chaîne vide ni "non renseigné" à afficher à la
  // place (voir PageDetailCommande).
  commune: string | null
  quartier: string | null
  lignes: LigneCommande[]
  historique: EntreeHistoriqueCommande[]
}

export type ParametresListeCommandes = {
  recherche?: string
  statut?: StatutCommande
  page?: number
}

export type CommandesPaginees = {
  data: Commande[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export async function recupererCommandes(parametres: ParametresListeCommandes): Promise<CommandesPaginees> {
  const { data } = await client.get<CommandesPaginees>('/api/commandes', { params: parametres })

  return data
}

export async function recupererCommande(id: number): Promise<CommandeDetail> {
  const { data } = await client.get<{ data: CommandeDetail }>(`/api/commandes/${id}`)

  return data.data
}

export type StatistiquesCommandes = {
  commandes_du_jour: number
  en_attente: number
  chiffre_affaires_jour: number
  panier_moyen_mois: number
}

export async function recupererStatistiquesCommandes(): Promise<StatistiquesCommandes> {
  const { data } = await client.get<StatistiquesCommandes>('/api/commandes/statistiques')

  return data
}

async function transitionner(id: number, action: string): Promise<CommandeDetail> {
  const { data } = await client.patch<{ data: CommandeDetail }>(`/api/commandes/${id}/${action}`)

  return data.data
}

export const confirmerCommande = (id: number) => transitionner(id, 'confirmer')
export const marquerCommandePrete = (id: number) => transitionner(id, 'marquer-prete')
export const marquerCommandeLivree = (id: number) => transitionner(id, 'marquer-livree')

export type MotifAnnulation =
  | { motif_type: 'article_indisponible' | 'client_injoignable' | 'client_annule' }
  | { motif_type: 'autre'; motif_autre: string }

export async function annulerCommande(id: number, motif: MotifAnnulation): Promise<CommandeDetail> {
  const { data } = await client.post<{ data: CommandeDetail }>(`/api/commandes/${id}/annuler`, motif)

  return data.data
}

export async function confirmerPaiementCommande(
  id: number,
  payload: { montant_recu: number; reference: string | null },
): Promise<CommandeDetail> {
  const { data } = await client.post<{ data: CommandeDetail }>(`/api/commandes/${id}/confirmer-paiement`, payload)

  return data.data
}
