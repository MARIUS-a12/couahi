/**
 * Étape 10 — page d'accueil par métier : "/admin" redirige vers la première
 * page à laquelle les permissions de l'utilisateur donnent accès, dans cet
 * ordre : Commandes (voir_commandes), puis Produits
 * (voir_catalogue/gerer_catalogue), puis Mon compte en dernier recours.
 * Montée par RouteProtegee, qui a déjà chargé /api/moi : useMoi() ici relit
 * le même résultat mis en cache, sans nouvel appel réseau.
 */
import { Navigate } from 'react-router-dom'
import { useMoi } from '../hooks/useMoi'

export function PageAccueilAdmin() {
  const { data: moi } = useMoi()

  if (!moi) {
    return null
  }

  if (moi.permissions.includes('voir_commandes')) {
    return <Navigate to="/admin/commandes" replace />
  }

  if (moi.permissions.includes('voir_catalogue') || moi.permissions.includes('gerer_catalogue')) {
    return <Navigate to="/admin/produits" replace />
  }

  return <Navigate to="/admin/compte" replace />
}
