/**
 * Lot F — la phrase en direct sous les cases des rôles : ce que le membre
 * pourra et ne pourra pas faire. Elle se calcule depuis les permissions
 * que l'API renvoie pour chaque rôle (/api/equipe/roles), jamais depuis le
 * nom des rôles : un rôle modifié en base change la phrase sans toucher à
 * ce fichier. Seul le texte de chaque permission vit ici.
 *
 * voir_statistiques est ignorée : elle commandera le tableau de bord (lot
 * H), et la phrase ne promet rien qu'aucun écran ne tient encore.
 */

const IGNOREES = new Set(['voir_statistiques'])

/** Dans l'ordre où la phrase les cite. */
const TEXTES: Record<string, string> = {
  voir_catalogue: 'consulter le catalogue',
  gerer_catalogue: 'créer et modifier les produits et les catégories',
  gerer_stock: 'ajuster les quantités en stock',
  voir_commandes: 'voir les commandes pour les préparer',
  traiter_commandes: 'confirmer les commandes, les marquer prêtes puis livrées, et annuler celles qui ne sont pas payées',
  creer_commandes: 'saisir les commandes reçues sur WhatsApp ou par téléphone',
  gerer_demandes_whatsapp: 'traiter les demandes WhatsApp',
  encaisser: 'vendre au comptoir et confirmer les paiements reçus',
  annuler_payees: 'annuler une commande payée et rembourser le client',
  gerer_parametres: 'modifier les réglages de la boutique : identité, livraison, paiement',
  gerer_equipe: "gérer l'équipe",
}

/** gerer_catalogue permet déjà de consulter : le redire serait du bruit. */
const IMPLIQUEES: Record<string, string> = { voir_catalogue: 'gerer_catalogue' }

export type RoleAvecPermissions = { id: number; permissions: string[] }

export type DroitsMembre = { pourra: string[]; nePourraPas: string[] }

export function droitsDuMembre(roles: RoleAvecPermissions[], rolesCoches: number[]): DroitsMembre {
  const siennes = new Set(roles.filter((role) => rolesCoches.includes(role.id)).flatMap((role) => role.permissions))
  const toutes = new Set(roles.flatMap((role) => role.permissions))
  const ordre = [...Object.keys(TEXTES), ...[...toutes].filter((permission) => !(permission in TEXTES)).sort()]

  const pourra: string[] = []
  const nePourraPas: string[] = []
  for (const permission of ordre) {
    if (!toutes.has(permission) || IGNOREES.has(permission)) {
      continue
    }
    const texte = TEXTES[permission] ?? permission.replaceAll('_', ' ')
    if (siennes.has(permission)) {
      if (!siennes.has(IMPLIQUEES[permission] ?? '')) {
        pourra.push(texte)
      }
    } else if (!(IMPLIQUEES[permission] && siennes.has(IMPLIQUEES[permission]))) {
      nePourraPas.push(texte)
    }
  }

  return { pourra, nePourraPas }
}

/**
 * « a ; b ; c » : plusieurs actions contiennent déjà des virgules (« les
 * marquer prêtes puis livrées, et annuler… ») ; séparées par des virgules,
 * on ne saurait plus où l'une finit.
 */
export function enumerer(elements: string[]): string {
  return elements.join(' ; ')
}
