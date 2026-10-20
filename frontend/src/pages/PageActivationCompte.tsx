/**
 * Correctif Étape 10 — /admin/activation : publique, hors de RouteProtegee
 * (comme /admin/connexion, voir routes.tsx) — un employé qui vient d'être
 * créé n'a aucune session. Trois champs (email, code, nouveau mot de passe
 * + confirmation) ; à la réussite, l'API connecte directement (voir
 * ActivationController) et cette page navigue vers /admin, où
 * PageAccueilAdmin choisit la page réelle selon les permissions.
 *
 * Le refus (code invalide, expiré ou déjà utilisé) est VOLONTAIREMENT un
 * message générique unique côté API — cet écran l'affiche tel quel, sans
 * jamais tenter de deviner lequel des cas s'est produit.
 */
import { useState } from 'react'
import type { FormEvent } from 'react'
import axios from 'axios'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { activerCompte } from '../api/activation'
import { ChampTexte } from '../components/ChampTexte'
import { EtatBouton } from '../components/EtatBouton'
import { IndicateurForceMotDePasse } from '../components/IndicateurForceMotDePasse'
import { CLE_MOI } from '../hooks/useMoi'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { reinitialiserGardeRedirection } from '../lib/gardeRedirectionConnexion'
import { useTitreDocument } from '../hooks/useTitreDocument'

function messageErreurGenerique(erreur: unknown): string | null {
  if (axios.isAxiosError(erreur)) {
    // Voir LimiteurEmailEtIp côté API : le message inclut déjà le temps
    // d'attente restant en minutes, en français — montré tel quel.
    if (erreur.response?.status === 429) {
      const messageBlocage = erreur.response?.data?.message
      return typeof messageBlocage === 'string' ? messageBlocage : 'Trop de tentatives. Réessayez plus tard.'
    }
    if (erreur.response?.status === 422) {
      return null
    }
  }

  return 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.'
}

export function PageActivationCompte() {
  useTitreDocument('Activation du compte · Back-office')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [nouveauMotDePasse, setNouveauMotDePasse] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const activation = useMutation({
    mutationFn: () =>
      activerCompte({
        email,
        code,
        nouveauMotDePasse,
        nouveauMotDePasseConfirmation: confirmation,
      }),
    onSuccess: async () => {
      reinitialiserGardeRedirection()
      await queryClient.invalidateQueries({ queryKey: CLE_MOI })
      navigate('/admin', { replace: true })
    },
    onError: (erreur) => setErreurs(extraireErreursChamps(erreur)),
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    activation.mutate()
  }

  const messageGenerique = activation.isError ? messageErreurGenerique(activation.error) : null

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <form onSubmit={soumettre} className="w-full max-w-sm border border-bordure bg-surface p-4 sm:p-5">
        <h1 className="mb-1 text-center text-titre-page font-semibold text-texte">Activer mon compte</h1>
        <p className="mb-6 text-center text-petit text-texte-secondaire">
          Entrez le code qui vous a été transmis et choisissez votre mot de passe.
        </p>

        <div className="space-y-4">
          <ChampTexte
            id="email"
            label="Email"
            type="email"
            autoComplete="username"
            valeur={email}
            onChange={setEmail}
            erreur={erreurs.email}
            requis
          />
          <ChampTexte
            id="code"
            label="Code d'activation"
            placeholder="ABCD2345"
            autoComplete="off"
            maxLength={8}
            valeur={code}
            onChange={(valeur) => setCode(valeur.toUpperCase())}
            erreur={erreurs.code}
            requis
          />
          <div>
            <ChampTexte
              id="nouveau_mot_de_passe"
              label="Nouveau mot de passe"
              type="password"
              autoComplete="new-password"
              valeur={nouveauMotDePasse}
              onChange={setNouveauMotDePasse}
              erreur={erreurs.nouveau_mot_de_passe}
              requis
            />
            <IndicateurForceMotDePasse motDePasse={nouveauMotDePasse} />
          </div>
          <ChampTexte
            id="nouveau_mot_de_passe_confirmation"
            label="Confirmer le mot de passe"
            type="password"
            autoComplete="new-password"
            valeur={confirmation}
            onChange={setConfirmation}
            requis
          />
        </div>

        {messageGenerique && (
          <p role="alert" className="animate-entree-haut mt-4 text-petit text-danger">
            {messageGenerique}
          </p>
        )}

        <button
          type="submit"
          disabled={activation.isPending}
          className="mt-6 h-11 w-full cursor-pointer rounded bg-primaire text-corps font-medium text-surface transition-colors hover:bg-primaire-fonce active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <EtatBouton chargement={activation.isPending}>Activer mon compte</EtatBouton>
        </button>
      </form>
    </div>
  )
}
