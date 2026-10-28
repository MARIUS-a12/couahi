/**
 * « il y a 12 min » : l'heure d'un clic, lue d'un coup d'œil. La date
 * exacte reste à un toucher (voir PageDemandesWhatsapp).
 */
export function tempsRelatif(date: string | Date, maintenant: Date = new Date()): string {
  const secondes = Math.max(0, Math.round((maintenant.getTime() - new Date(date).getTime()) / 1000))
  const minutes = Math.floor(secondes / 60)
  const heures = Math.floor(minutes / 60)
  const jours = Math.floor(heures / 24)

  if (minutes < 1) {
    return 'à l’instant'
  }
  if (heures < 1) {
    return `il y a ${minutes} min`
  }
  if (jours < 1) {
    return `il y a ${heures} h`
  }
  if (jours === 1) {
    return 'hier'
  }
  return `il y a ${jours} jours`
}

export function dateExacte(date: string | Date): string {
  return new Date(date).toLocaleString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
