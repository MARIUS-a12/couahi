/**
 * La structure des variantes d'un produit et ses écritures. Chaque
 * écriture renvoie la structure complète : on la pose telle quelle dans le
 * cache (aucun recalcul local).
 *
 * Les écritures en cours sont comptées (enAttente) : l'écran affiche
 * « Enregistrement… » tant qu'il en reste, et le formulaire empêche de
 * quitter la page — un champ affiche ce que le commerçant a tapé, et
 * partir avant la fin de l'envoi le perdrait sans qu'il le sache.
 *
 * Seule la liste des produits (pastille de stock) est invalidée à chaque
 * écriture ; les suggestions ne le sont que quand une option ou une
 * valeur change (structurel). Recharger la fiche à chaque champ coûtait
 * deux requêtes de plus par geste.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { recupererSuggestions, recupererVariantes } from '../api/variantes'
import type { StructureVariantes } from '../api/variantes'
import { extraireErreursChamps } from '../lib/erreursValidation'

type Reponse = { data: StructureVariantes; resultat?: 'archivee' | 'supprimee' }

export function useVariantes(produitId: number) {
  const queryClient = useQueryClient()
  const requete = useQuery({ queryKey: ['variantes', produitId], queryFn: () => recupererVariantes(produitId) })
  const suggestions = useQuery({ queryKey: ['suggestions-variantes'], queryFn: recupererSuggestions, staleTime: 60_000 })
  const [enAttente, setEnAttente] = useState(0)
  const [erreur, setErreur] = useState<string | null>(null)

  /**
   * Exécute une écriture : renvoie le résultat éventuel (« archivee » /
   * « supprimee »), ou null en cas d'échec — le message est alors dans
   * `erreur`, déjà rédigé par le serveur.
   */
  async function ecrire(
    action: () => Promise<Reponse>,
    { structurel = false }: { structurel?: boolean } = {},
  ): Promise<{ resultat?: 'archivee' | 'supprimee' } | null> {
    setEnAttente((n) => n + 1)
    setErreur(null)

    try {
      const reponse = await action()
      queryClient.setQueryData(['variantes', produitId], reponse.data)
      queryClient.invalidateQueries({ queryKey: ['produits'] })
      if (structurel) {
        queryClient.invalidateQueries({ queryKey: ['suggestions-variantes'] })
      }

      return { resultat: reponse.resultat }
    } catch (e) {
      const champs = Object.values(extraireErreursChamps(e))
      setErreur(champs[0] ?? "L'enregistrement a échoué. Vérifiez votre connexion et réessayez.")

      return null
    } finally {
      setEnAttente((n) => n - 1)
    }
  }

  return {
    requete,
    suggestions: suggestions.data ?? [],
    ecrire,
    enCours: enAttente > 0,
    enAttente,
    erreur,
    effacerErreur: () => setErreur(null),
  }
}
