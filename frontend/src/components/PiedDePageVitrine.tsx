/**
 * Pied de page de la vitrine : adresse, horaires du jour, téléphone, liens
 * sociaux — seules les informations renseignées s'affichent, jamais une
 * ligne vide ni un "non renseigné" (voir Étape 6A ter). Liens sociaux en
 * nouvel onglet avec rel="noopener noreferrer" : ce sont des domaines
 * tiers, jamais la confiance de la vitrine elle-même.
 */
import { Clock, ExternalLink, MapPin, Phone } from 'lucide-react'
import type { EtablissementVitrine } from '../api/vitrine'

function LienSocial({ href, libelle }: { href: string; libelle: string }) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="flex min-h-11 py-2 text-center leading-snug items-center gap-1.5 text-corps text-texte-secondaire transition-colors hover:text-texte focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
    >
      <ExternalLink aria-hidden="true" size={16} strokeWidth={1.5} />
      {libelle}
    </a>
  )
}

export function PiedDePageVitrine({ etablissement }: { etablissement: EtablissementVitrine }) {
  const { adresse, etat_ouverture, telephone_fixe, lien_facebook, lien_instagram, lien_tiktok, lien_site_web } =
    etablissement

  const aDuContenu =
    adresse || etat_ouverture || telephone_fixe || lien_facebook || lien_instagram || lien_tiktok || lien_site_web

  if (!aDuContenu) {
    return null
  }

  return (
    <footer className="border-t border-bordure bg-surface">
      <div className="mx-auto max-w-6xl space-y-3 px-4 py-6">
        {adresse && (
          <p className="flex items-center gap-1.5 text-corps text-texte-secondaire">
            <MapPin aria-hidden="true" size={16} strokeWidth={1.5} />
            {adresse}
          </p>
        )}

        {etat_ouverture && (
          <p className="flex items-center gap-1.5 text-corps text-texte-secondaire">
            <Clock aria-hidden="true" size={16} strokeWidth={1.5} />
            {etat_ouverture.libelle}
          </p>
        )}

        {telephone_fixe && (
          <p className="flex items-center gap-1.5 text-corps text-texte-secondaire">
            <Phone aria-hidden="true" size={16} strokeWidth={1.5} />
            {telephone_fixe}
          </p>
        )}

        {(lien_facebook || lien_instagram || lien_tiktok || lien_site_web) && (
          <div className="flex flex-wrap gap-4 pt-1">
            {lien_facebook && <LienSocial href={lien_facebook} libelle="Facebook" />}
            {lien_instagram && <LienSocial href={lien_instagram} libelle="Instagram" />}
            {lien_tiktok && <LienSocial href={lien_tiktok} libelle="TikTok" />}
            {lien_site_web && <LienSocial href={lien_site_web} libelle="Site web" />}
          </div>
        )}
      </div>
    </footer>
  )
}
