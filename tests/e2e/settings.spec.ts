import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * Paramètres de la gérante et premier jour d'un compte (F05, F09, phase 1) sur la vraie application : la gérante
 * crée un compte avec un mot de passe initial GÉNÉRÉ (réauthentification demandée puis reprise), le mot de passe est
 * montré une seule fois ; la personne se connecte avec lui et le change ; l'accueil n'ouvre pas les paramètres.
 * Tout est fictif.
 */

async function signIn(page: Page, login: string, password: string): Promise<void> {
  await page.goto('/connexion')
  await page.getByLabel('Identifiant').fill(login)
  await page.getByLabel('Mot de passe').fill(password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

async function switchAccount(
  page: Page,
  context: BrowserContext,
  login: string,
  password: string,
): Promise<void> {
  await context.clearCookies()
  await signIn(page, login, password)
}

test.describe('paramètres et personnel (F09) sur la vraie application', () => {
  test('la gérante crée un compte, le mot de passe initial est montré une fois, la personne le change', async ({
    page,
    context,
  }) => {
    test.setTimeout(180_000)
    const manager = demoAccount('MANAGER')
    await signIn(page, manager.login, manager.password)

    await page.goto('/parametres')
    await page
      .getByRole('link', { name: /Personnel/ })
      .first()
      .click()
    await expect(page.getByRole('heading', { level: 1, name: 'Personnel' })).toBeVisible()
    await page.getByRole('link', { name: 'Créer un compte' }).click()

    const login = `caisse.e2e.${Date.now().toString(36)}`
    await page.getByLabel('Nom affiché').fill('Caisse Parcours E2E')
    await page.getByLabel('Identifiant de connexion').fill(login)
    await page.getByRole('group', { name: 'Profils' }).getByLabel('Caisse').check()
    await page.getByRole('button', { name: 'Générer un mot de passe' }).click()
    await page.getByRole('button', { name: 'Créer le compte' }).click()

    // La création est une opération sensible : le mot de passe de la gérante est demandé, puis elle reprend.
    // La demande arrive avec la réponse du serveur : attendre l'une des deux issues avant de décider.
    const dialog = page.getByRole('dialog', { name: 'Confirmer votre mot de passe' })
    const created = page.getByText('Compte créé sur le serveur')
    await expect(dialog.or(created).first()).toBeVisible()
    if (await dialog.isVisible()) {
      await dialog.getByLabel('Mot de passe').fill(manager.password)
      await dialog.getByRole('button', { name: 'Confirmer et reprendre' }).click()
    }
    await expect(page.getByText('Compte créé sur le serveur')).toBeVisible()
    const initial = (await page.locator('#compte-cree .font-mono').innerText()).trim()
    expect(initial).toMatch(/^[A-Za-z2-9]{16}$/)

    await page.getByRole('button', { name: 'J’ai noté, ouvrir le compte' }).click()
    await expect(page.getByText(`Compte « Caisse Parcours E2E » créé.`)).toBeVisible()
    // Fiche lue sur le serveur (titre au nom du compte) : aucune lecture en cours ne reposera le cookie de
    // session après le changement de compte ci-dessous.
    await expect(page.getByRole('heading', { level: 1, name: 'Caisse Parcours E2E' })).toBeVisible()
    expect(await page.getByRole('main').innerText()).not.toContain(initial)

    // Premier jour : la personne se connecte avec le mot de passe initial et le change.
    await switchAccount(page, context, login, initial)
    await page.getByRole('link', { name: 'Changer mon mot de passe' }).click()
    await page.getByLabel('Mot de passe actuel').fill(initial)
    await page
      .getByLabel('Nouveau mot de passe (obligatoire)', { exact: true })
      .fill('NouveauSecretE2E2026')
    await page.getByLabel('Confirmation du nouveau mot de passe').fill('NouveauSecretE2E2026')
    await page.getByRole('button', { name: 'Changer le mot de passe' }).click()
    await expect(page.getByText('Mot de passe changé sur le serveur')).toBeVisible()

    await switchAccount(page, context, login, 'NouveauSecretE2E2026')
    // La caisse n'ouvre pas les paramètres.
    await page.goto('/parametres/personnel')
    await expect(page.getByText('Accès refusé').first()).toBeVisible()
  })

  test('cabinet et documents : identité saisie par la gérante, fuseau et monnaie en lecture seule', async ({
    page,
  }) => {
    const manager = demoAccount('MANAGER')
    await signIn(page, manager.login, manager.password)
    await page.goto('/parametres/cabinet')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Cabinet et documents' }),
    ).toBeVisible()
    await expect(page.getByRole('main')).toContainText('Africa/Douala')
    await expect(page.getByRole('main')).toContainText('Documents de test')
    await expect(page.getByLabel('Nom du cabinet')).not.toHaveValue('')
  })

  test('l’accueil n’ouvre pas les paramètres', async ({ page }) => {
    const reception = demoAccount('RECEPTION')
    await signIn(page, reception.login, reception.password)
    await page.goto('/parametres')
    await expect(page.getByText('Accès refusé').first()).toBeVisible()
  })
})
