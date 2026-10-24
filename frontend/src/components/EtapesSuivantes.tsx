/**
 * Affichée une fois, juste après la création d'un produit : le formulaire
 * mène alors sur la fiche du produit (plus sur la liste), et cette ligne
 * dit quoi faire ensuite, dans l'ordre. L'ordre est imposé par l'écran —
 * les photos d'abord, puisque chaque coloris en choisit une parmi elles —
 * et c'est son absence, pas la structure, qui faisait hésiter la première
 * fois. Pas de fermeture automatique, contrairement à BandeauSucces : on la
 * relit en cours de route.
 */
import { X } from 'lucide-react'

export function EtapesSuivantes({ nom, publie, onFermer }: { nom: string; publie: boolean; onFermer: () => void }) {
  return (
    <div role="status" className="animate-entree-haut border border-succes bg-succes/10 px-4 py-3 text-corps text-texte">
      <div className="flex items-start justify-between gap-3">
        <div className="space-y-1">
          <p className="font-semibold text-succes">« {nom} » est enregistré. Pour la suite, dans l'ordre :</p>
          <ol className="list-decimal space-y-1 pl-5">
            <li>ajoutez ses photos, plus bas dans « Photos » ;</li>
            <li>
              s'il existe en plusieurs tailles ou coloris, ajoutez-les dans « Variantes », puis cochez les combinaisons que vous avez
              en stock ;
            </li>
            <li>choisissez enfin la photo de chaque coloris.</li>
          </ol>
          <p className="text-petit text-texte-secondaire">
            {publie
              ? 'Sans tailles ni coloris, les photos suffisent : il est déjà en vente.'
              : 'Il est en brouillon : passez-le en « Publié », plus bas, quand il est prêt.'}
          </p>
        </div>
        <button
          type="button"
          onClick={onFermer}
          aria-label="Fermer"
          className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded text-succes hover:bg-succes/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire"
        >
          <X aria-hidden="true" size={20} strokeWidth={1.5} />
        </button>
      </div>
    </div>
  )
}
