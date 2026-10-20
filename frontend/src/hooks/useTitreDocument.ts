/**
 * Pose le titre de l'onglet du navigateur. null = rien encore à afficher
 * (données en chargement) : le titre précédent reste en place plutôt que de
 * clignoter vers un texte générique.
 *
 * Le titre posé par le SERVEUR (voir le greffon Vite "metadonneesPartage")
 * vaut déjà le bon nom dès le premier octet sur une vitrine ; ce hook prend
 * le relais lors des navigations internes, que le serveur ne voit jamais.
 */
import { useEffect } from 'react'

export function useTitreDocument(titre: string | null) {
  useEffect(() => {
    if (titre !== null) {
      document.title = titre
    }
  }, [titre])
}
