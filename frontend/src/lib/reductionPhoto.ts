/**
 * Réduit une photo sur le téléphone avant de l'envoyer. Une photo de
 * téléphone pèse 3 à 8 Mo ; le serveur n'en garde que des formats de
 * 1200 px au plus (environ 300 Ko au total). Envoyer l'original, c'est
 * faire payer au commerçant, en data mobile, des mégaoctets jetés à
 * l'arrivée.
 *
 * Confort seulement, jamais garantie : le serveur accepte toujours
 * l'original et se protège seul (voir GenerateurVariantesImage). Au
 * moindre échec (navigateur ancien, format que le navigateur ne décode pas
 * comme le HEIC), l'original part tel quel.
 *
 * createImageBitmap applique l'orientation EXIF de la photo : un portrait
 * pris au téléphone arrive droit, ce que le serveur sans l'extension exif
 * ne sait pas faire.
 */

/** Le plus petit côté gardé : celui du plus grand format du serveur. */
export const COTE_MIN_CIBLE = 1200

export const QUALITE_JPEG = 0.82

/** null : déjà assez petite, rien à réduire (jamais d'agrandissement). */
export function dimensionsReduites(
  largeur: number,
  hauteur: number,
  coteMin = COTE_MIN_CIBLE,
): { largeur: number; hauteur: number } | null {
  const facteur = coteMin / Math.min(largeur, hauteur)
  if (facteur >= 1) {
    return null
  }

  return { largeur: Math.round(largeur * facteur), hauteur: Math.round(hauteur * facteur) }
}

export async function reduirePhoto(fichier: File): Promise<File> {
  try {
    const image = await createImageBitmap(fichier)
    const dimensions = dimensionsReduites(image.width, image.height)
    if (!dimensions) {
      image.close()
      return fichier
    }

    const toile = document.createElement('canvas')
    toile.width = dimensions.largeur
    toile.height = dimensions.hauteur
    const contexte = toile.getContext('2d')
    if (!contexte) {
      image.close()
      return fichier
    }
    contexte.drawImage(image, 0, 0, dimensions.largeur, dimensions.hauteur)
    image.close()

    // Un PNG reste un PNG : un logo transparent perdrait sa transparence en JPEG.
    const type = fichier.type === 'image/png' ? 'image/png' : 'image/jpeg'
    const reduite = await new Promise<Blob | null>((resoudre) => toile.toBlob(resoudre, type, QUALITE_JPEG))
    if (!reduite || reduite.size >= fichier.size) {
      return fichier
    }

    const extension = type === 'image/png' ? '.png' : '.jpg'
    return new File([reduite], fichier.name.replace(/\.[^.]*$/, '') + extension, { type })
  } catch {
    return fichier
  }
}
