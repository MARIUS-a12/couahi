/**
 * Lot F — ce que l'administrateur transmet à un nouveau membre : le lien
 * entier vers l'activation, à l'adresse de la boutique où il est connecté
 * (la connexion est propre à chaque boutique, le membre devra se connecter
 * là), et le message WhatsApp prêt à envoyer.
 *
 * Le code est dans le texte du message, JAMAIS dans l'adresse : une
 * adresse se retrouve dans l'historique, les aperçus de lien et les
 * journaux des serveurs.
 */

export type DestinataireCode = { nom: string; email: string }

export function lienActivation(origine: string): string {
  return `${origine}/admin/activation`
}

export function messageActivation({
  boutique,
  destinataire,
  lien,
  code,
}: {
  boutique: string
  destinataire: DestinataireCode
  lien: string
  code: string
}): string {
  return [
    `Bonjour ${destinataire.nom}, voici votre accès à l'espace de ${boutique}.`,
    '',
    `1. Ouvrez ce lien : ${lien}`,
    `2. Saisissez votre email : ${destinataire.email}`,
    `3. Saisissez ce code : ${code}`,
    '4. Choisissez votre mot de passe.',
    '',
    'Le code est valable 48 heures.',
  ].join('\n')
}

/** wa.me sans numéro : WhatsApp demande à qui l'envoyer. */
export function lienWhatsappActivation(message: string): string {
  return `https://wa.me/?text=${encodeURIComponent(message)}`
}
