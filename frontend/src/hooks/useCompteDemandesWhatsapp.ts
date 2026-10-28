/**
 * Lot D — le nombre de demandes WhatsApp nouvelles, pour la pastille du
 * menu. Rafraîchi toutes les 60 secondes, comme celle des commandes (voir
 * useStatistiquesCommandes), et après chaque changement de statut.
 */
import { useQuery } from '@tanstack/react-query'
import { compterDemandesNouvelles } from '../api/demandesWhatsapp'

export function useCompteDemandesWhatsapp(actif: boolean) {
  return useQuery({
    queryKey: ['demandes-whatsapp-compte'],
    queryFn: compterDemandesNouvelles,
    refetchInterval: 60 * 1000,
    enabled: actif,
  })
}
