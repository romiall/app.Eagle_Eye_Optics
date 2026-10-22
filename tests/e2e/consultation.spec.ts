import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * Consultation clinique réelle (C01, AT02, AT18, premier jalon EN LIGNE et sans contrôle de paiement) :
 * l'accueil enregistre une arrivée, la personne en charge de l'examen la démarre depuis la file du jour,
 * remplit les trois étapes disponibles (motif et lunettes, antécédents, conclusion et orientation), voit
 * « Enregistré sur le serveur » après la réponse du serveur, et retrouve ses saisies après rechargement.
 * L'accueil n'a aucun accès clinique. Le serveur et la base sont ceux de l'application construite.
 * Tout est fictif.
 */

async function signIn(page: Page, role: string): Promise<void> {
  const account = demoAccount(role)
  await page.goto('/connexion')
  await page.getByLabel('Identifiant').fill(account.login)
  await page.getByLabel('Mot de passe').fill(account.password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

/** Change de compte sans autre état partagé : cookies effacés, puis connexion du profil demandé. */
async function switchTo(page: Page, context: BrowserContext, role: string): Promise<void> {
  await context.clearCookies()
  await page.goto('/connexion')
  await signIn(page, role)
}

async function registerArrival(page: Page, lastName: string, phone: string): Promise<void> {
  await page.goto('/patients/nouveau')
  await page.getByLabel('Nom (obligatoire)').fill(lastName)
  await page.getByLabel('Prénom(s)').fill('Test')
  await page.getByLabel('Sexe').selectOption({ label: 'Féminin' })
  await page.getByLabel('Date de naissance', { exact: true }).fill('12/09/1990')
  await page.getByLabel('Téléphone principal').fill(phone)
  await page.getByRole('button', { name: 'Enregistrer le patient' }).click()
  await expect(page).toHaveURL(/\/patients\/[0-9A-HJKMNP-TV-Z]{26}$/)

  await page.getByRole('link', { name: 'Enregistrer une arrivée' }).click()
  // Le patient est présélectionné depuis la fiche : attendre son identité avant d'envoyer le formulaire.
  await expect(page).toHaveURL(/\/aujourdhui\/arrivee\?patient=/)
  await expect(page.getByRole('main')).toContainText(lastName)
  await page.getByLabel('Motif de la visite').selectOption({ label: 'Examen de vue' })
  // Le marqueur de paiement apparaît au choix du motif et déplace le bouton : attendre qu'il soit là.
  await expect(page.getByRole('main')).toContainText('Le contrôle du paiement de l’examen')
  await page.getByLabel('Note administrative (facultative)').fill('Vient pour un examen')
  await page.getByRole('button', { name: 'Enregistrer l’arrivée' }).click()
  await expect(page).toHaveURL(/\/aujourdhui$/)
}

async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
}

test.describe('consultation (C01) sur la vraie application', () => {
  test('démarrer l’examen depuis la file, remplir trois étapes, retrouver les saisies après rechargement', async ({
    page,
    context,
  }) => {
    // Parcours long (deux comptes, trois étapes, rechargement) sur un poste de test parfois lent.
    test.setTimeout(180_000)
    await signIn(page, 'RECEPTION')
    await registerArrival(page, 'Consultation Parcours', '+237 600 00 02 22')

    // L'accueil voit la file mais n'a aucune action d'examen ni aucune donnée clinique.
    const queue = page.getByRole('main')
    await expect(queue.getByRole('link', { name: /Consultation Parcours/ }).first()).toBeVisible()
    await expect(queue.getByRole('button', { name: /Démarrer l’examen/ })).toHaveCount(0)
    await expect(queue.getByRole('link', { name: /examen/i })).toHaveCount(0)

    await switchTo(page, context, 'CLINICIAN')
    await page.goto('/aujourdhui')
    const row = page.getByRole('main')
    await expect(row.getByRole('link', { name: /Consultation Parcours/ }).first()).toBeVisible()
    // Le contrôle du paiement n'existe pas encore : le marqueur le dit, et ne bloque pas le démarrage.
    await expect(row).toContainText('Paiement de l’examen non contrôlé')
    await row.getByRole('button', { name: /Démarrer l’examen de .*Consultation Parcours/ }).click()

    await expect(page).toHaveURL(/\/consultations\/[0-9A-HJKMNP-TV-Z]{26}$/)
    const consultationUrl = page.url()
    await expect(page.getByRole('heading', { level: 1, name: 'Consultation' })).toBeVisible()
    await expect(page.getByRole('main')).toContainText('Consultation Parcours')
    await expect(page.getByRole('heading', { level: 2, name: /Étape 1 sur 3/ })).toBeVisible()

    // Étape 1 : motif et lunettes.
    await page.getByLabel('Baisse de vision').check()
    await page.getByLabel('Vision floue').check()
    await page.getByLabel('Plaintes et symptômes').fill('Gêne en lecture depuis un mois')
    await page
      .getByRole('group', { name: 'Porte actuellement des lunettes' })
      .getByLabel('Oui')
      .check()
    await page.getByLabel('Depuis combien de temps').fill('Deux ans')
    await page.getByRole('button', { name: 'Suivant' }).click()

    // Étape 2 : antécédents (« Aucun connu » est un choix à part, distinct de « non renseigné »).
    await expect(page.getByRole('heading', { level: 2, name: /Étape 2 sur 3/ })).toBeVisible()
    await expect(page.getByText('Enregistré sur le serveur').first()).toBeVisible()
    await page.getByRole('group', { name: 'Antécédents oculaires' }).getByLabel('Glaucome').check()
    await page
      .getByRole('group', { name: 'Antécédents médicaux' })
      .getByLabel('Aucun connu')
      .check()
    await page.getByRole('button', { name: 'Suivant' }).click()

    // Étape 3 : conclusion et orientation (le niveau d'urgence est obligatoire avec une orientation).
    await expect(page.getByRole('heading', { level: 2, name: /Étape 3 sur 3/ })).toBeVisible()
    await page.getByLabel('Conclusion', { exact: true }).fill('Examen de contrôle à poursuivre')
    await page
      .getByRole('group', { name: 'Orientation vers un ophtalmologiste' })
      .getByLabel('Oui')
      .check()
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
    // Sans niveau d'urgence, l'écran refuse l'envoi et le dit avant tout appel au serveur.
    await expect(page.getByText('Certaines informations sont à corriger')).toBeVisible()
    await page.getByLabel('Niveau d’urgence').selectOption({ label: 'Consultation recommandée' })
    await page
      .getByRole('group', { name: 'Motifs de l’orientation' })
      .getByLabel('Suspicion de glaucome')
      .check()
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
    await expect(page.getByText(/Enregistré sur le serveur à/)).toBeVisible()

    // Ce que cette version ne propose pas encore n'est ni simulé ni dit « terminé ».
    const text = (await page.getByRole('main').innerText()).toLowerCase()
    expect(text).toContain('prochaine version')
    expect(text).not.toContain('examen terminé')
    await expect(page.getByRole('button', { name: /Terminer/ })).toHaveCount(0)

    // Après rechargement complet : tout vient du serveur.
    await page.goto(consultationUrl)
    await expect(page.getByRole('heading', { level: 2, name: /Étape 1 sur 3/ })).toBeVisible()
    await expect(page.getByLabel('Baisse de vision')).toBeChecked()
    await expect(page.getByLabel('Vision floue')).toBeChecked()
    await expect(page.getByLabel('Plaintes et symptômes')).toHaveValue(
      'Gêne en lecture depuis un mois',
    )
    await expect(page.getByLabel('Depuis combien de temps')).toHaveValue('Deux ans')
    await page
      .getByRole('button', { name: /Antécédents/ })
      .first()
      .click()
    await expect(
      page.getByRole('group', { name: 'Antécédents oculaires' }).getByLabel('Glaucome'),
    ).toBeChecked()
    await expect(
      page.getByRole('group', { name: 'Antécédents médicaux' }).getByLabel('Aucun connu'),
    ).toBeChecked()
    await page
      .getByRole('button', { name: /Conclusion et orientation/ })
      .first()
      .click()
    await expect(page.getByLabel('Conclusion', { exact: true })).toHaveValue(
      'Examen de contrôle à poursuivre',
    )
    await expect(page.getByLabel('Niveau d’urgence')).toHaveValue('RECOMMENDED')

    // La file propose maintenant de reprendre l'examen, sans en démarrer un second.
    await page.goto('/aujourdhui')
    await expect(
      page.getByRole('link', { name: /Reprendre l’examen de .*Consultation Parcours/ }),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: /Démarrer l’examen/ })).toHaveCount(0)

    // L'accueil n'a aucun accès clinique, y compris à une consultation qui existe.
    await switchTo(page, context, 'RECEPTION')
    await page.goto(consultationUrl)
    await expect(page.getByText('Accès refusé').first()).toBeVisible()
    await expect(page.getByLabel('Plaintes et symptômes')).toHaveCount(0)
    await page.goto('/aujourdhui')
    await expect(
      page
        .getByRole('main')
        .getByRole('link', { name: /Consultation Parcours/ })
        .first(),
    ).toBeVisible()
    await expect(page.getByRole('main').getByRole('link', { name: /examen/i })).toHaveCount(0)
  })

  test('téléphone 360 px : la consultation ne déborde pas', async ({ page, context }) => {
    await page.setViewportSize({ width: 360, height: 740 })
    await signIn(page, 'RECEPTION')
    await registerArrival(page, 'Mobile Parcours', '+237 600 00 03 33')

    await switchTo(page, context, 'CLINICIAN')
    await page.goto('/aujourdhui')
    await page
      .getByRole('main')
      .getByRole('button', { name: /Démarrer l’examen de .*Mobile Parcours/ })
      .click()
    await expect(page).toHaveURL(/\/consultations\//)
    await expect(page.getByRole('heading', { level: 2, name: /Étape 1 sur 3/ })).toBeVisible()
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0)

    await page
      .getByRole('button', { name: /Conclusion et orientation/ })
      .first()
      .click()
    await expect(page.getByRole('heading', { level: 2, name: /Étape 3 sur 3/ })).toBeVisible()
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0)
  })
})
