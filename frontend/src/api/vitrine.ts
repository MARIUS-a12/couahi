/**
 * Appels HTTP de la vitrine publique (accueil + fiche produit) : aucune
 * authentification, voir DispositionVitrine. Les types reflètent
 * ProduitVitrineResource / ProduitVitrineDetailResource / etc. côté API —
 * volontairement plus pauvres que ceux du back-office (api/produits.ts) :
 * cette API ne renvoie jamais de quantité de stock ni de coût, seulement de
 * quoi afficher une boutique.
 */
import { client } from '../lib/client'

export type VarianteVitrine = {
  id: number
  nom: string
  prix: number
  disponible: boolean
}

export type FormatPhoto = {
  webp: string | null
  jpg: string | null
}

export type PhotoPrincipale = {
  vignette: FormatPhoto
  moyenne: FormatPhoto
}

export type PhotoVitrine = {
  id: number
  ordre: number
  est_principal: boolean
  vignette: FormatPhoto
  moyenne: FormatPhoto
  grande: FormatPhoto
}

export type CategorieReferencee = {
  id: number
  nom: string
}

export type ProduitVitrine = {
  id: number
  nom: string
  description: string | null
  prix: number
  categorie: CategorieReferencee | null
  disponible: boolean
  photo: PhotoPrincipale | null
  variantes: VarianteVitrine[]
}

export type ProduitVitrineDetail = {
  id: number
  nom: string
  description: string | null
  prix: number
  categorie: CategorieReferencee | null
  disponible: boolean
  photos: PhotoVitrine[]
  variantes: VarianteVitrine[]
}

export type CategorieVitrine = {
  id: number
  nom: string
}

export type EtatOuvertureVitrine = {
  ouvert: boolean
  libelle: string
}

export type EtablissementVitrine = {
  nom: string
  type: 'boutique' | 'restaurant'
  description: string | null
  logo: { petit: FormatPhoto; grand: FormatPhoto } | null
  numero_whatsapp: string | null
  telephone_fixe: string | null
  email_contact: string | null
  adresse: string | null
  horaires: Record<string, { ouverture: string | null; fermeture: string | null; ferme: boolean }> | null
  etat_ouverture: EtatOuvertureVitrine | null
  lien_facebook: string | null
  lien_instagram: string | null
  lien_tiktok: string | null
  lien_site_web: string | null
  couleur_accent: string
  // Étape 6C-1 : jamais les identifiants. Vrai seulement si CinetPay est
  // DISPONIBLE (configuré et intégration active), pas simplement configuré.
  paiement_disponible: boolean
  // Étape 13 : LE moyen proposé, jamais deux à la fois — CinetPay s'il est
  // disponible, sinon le Mobile Money, sinon aucun (voir modePaiementPropose
  // côté serveur). Seule source pour choisir le bouton à afficher.
  mode_paiement: ModePaiementVitrine | null
}

export type ModePaiementVitrine = 'cinetpay' | 'mobile_money'

export type ProduitsVitrinePagines = {
  data: ProduitVitrine[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export type ParametresListeVitrine = {
  categorie?: number
  recherche?: string
  page?: number
}

export async function recupererProduitsVitrine(
  parametres: ParametresListeVitrine,
): Promise<ProduitsVitrinePagines> {
  const { data } = await client.get<ProduitsVitrinePagines>('/api/vitrine/produits', { params: parametres })

  return data
}

export async function recupererProduitVitrine(id: number): Promise<ProduitVitrineDetail> {
  const { data } = await client.get<{ data: ProduitVitrineDetail }>(`/api/vitrine/produits/${id}`)

  return data.data
}

export async function recupererCategoriesVitrine(): Promise<CategorieVitrine[]> {
  const { data } = await client.get<{ data: CategorieVitrine[] }>('/api/vitrine/categories')

  return data.data
}

export async function recupererEtablissementVitrine(): Promise<EtablissementVitrine> {
  const { data } = await client.get<{ data: EtablissementVitrine }>('/api/vitrine/etablissement')

  return data.data
}

/**
 * Le message envoyé sur WhatsApp (nom, variante, prix, référence) est
 * composé côté serveur — voir GenerateurLienWhatsapp — jamais reconstruit
 * ici : ce serait dupliquer une logique dont la référence opaque doit
 * rester exactement celle enregistrée en base.
 */
export async function genererLienWhatsapp(produitId: number, varianteId: number | null): Promise<string> {
  const { data } = await client.post<{ url: string }>(`/api/vitrine/produits/${produitId}/lien-whatsapp`, {
    variante_id: varianteId,
  })

  return data.url
}

// --- Étape 6B — panier et commande --------------------------------------

export type StatutLignePanier = 'disponible' | 'prix_modifie' | 'epuise' | 'retire'

export type LigneVerifiee = {
  produit_id: number
  variante_id: number | null
  quantite: number
  nom: string | null
  prix_actuel: number | null
  disponible: boolean
  statut: StatutLignePanier
}

export type ReponseVerificationPanier = {
  lignes: LigneVerifiee[]
  sous_total: number
}

/**
 * prix_vu : le dernier prix affiché à l'utilisateur pour cette ligne (voir
 * lib/panier.ts), envoyé pour que le serveur puisse signaler un changement —
 * jamais pour calculer quoi que ce soit, le total renvoyé vient toujours du
 * prix actuel relu en base.
 */
export async function verifierPanier(
  lignes: { produitId: number; varianteId: number | null; quantite: number; prixVu: number | null }[],
): Promise<ReponseVerificationPanier> {
  const { data } = await client.post<ReponseVerificationPanier>('/api/vitrine/panier/verifier', {
    lignes: lignes.map((ligne) => ({
      produit_id: ligne.produitId,
      variante_id: ligne.varianteId,
      quantite: ligne.quantite,
      prix_vu: ligne.prixVu,
    })),
  })

  return data
}

export type ZoneLivraisonVitrine = {
  id: number
  nom: string
  frais: number
  delai_estime: string | null
}

export async function recupererZonesLivraison(): Promise<ZoneLivraisonVitrine[]> {
  const { data } = await client.get<{ data: ZoneLivraisonVitrine[] }>('/api/vitrine/zones-livraison')

  return data.data
}

export type NouvelleCommandePayload = {
  lignes: { produit_id: number; variante_id: number | null; quantite: number }[]
  client: { nom: string; telephone: string }
  // Correctif livraison : remplace zone_livraison_id — "commune" EST le nom
  // de la zone choisie quand l'établissement en a, du texte libre sinon
  // (voir PageCommander). Le serveur la résout lui-même en zone pour le
  // calcul du frais, jamais un identifiant envoyé par le navigateur.
  commune: string
  quartier: string
  cle_idempotence: string
  // Étape 13 : refusé par le serveur s'il n'est pas le mode proposé.
  mode_paiement?: ModePaiementVitrine | null
}

export type CommandeCreee = {
  numero: string
  jeton: string
}

export async function creerCommandeVitrine(payload: NouvelleCommandePayload): Promise<CommandeCreee> {
  const { data } = await client.post<CommandeCreee>('/api/vitrine/commandes', payload)

  return data
}

export type LigneCommandeVitrine = {
  nom: string
  quantite: number
  prix_unitaire: number
  total: number
}

export type CommandeVitrine = {
  numero: string
  statut: string
  sous_total: number
  frais_livraison: number
  total: number
  note: string | null
  cree_le: string
  zone_livraison: { nom: string } | null
  lignes: LigneCommandeVitrine[]
  mode_paiement: 'cinetpay' | 'mobile_money' | 'especes' | null
  // Vrai SEULEMENT quand le commerçant a constaté l'argent reçu — jamais
  // déduit du statut de la commande.
  paiement_confirme: boolean
  // Montant constaté par le commerçant (null tant qu'il n'a rien confirmé).
  montant_recu: number | null
  // Paiement partiel sur une commande vivante : ce qu'il reste à envoyer.
  reste_du: number | null
  // Commande annulée ou expirée après paiement, pas encore remboursée.
  a_rembourser: number | null
  remboursement: { montant: number; le: string } | null
  // Null hors Mobile Money, si le commerçant l'a retiré depuis, ou si la
  // commande est annulée ou expirée (elle ne se paie plus).
  mobile_money: InstructionsMobileMoney | null
}

export type InstructionsMobileMoney = {
  numero: string
  numero_affiche: string
  operateur: string
  operateur_libelle: string
  beneficiaire: string
}

export async function recupererCommandeVitrine(numero: string, jeton: string): Promise<CommandeVitrine> {
  const { data } = await client.get<{ data: CommandeVitrine }>(`/api/vitrine/commandes/${numero}`, {
    params: { jeton },
  })

  return data.data
}
