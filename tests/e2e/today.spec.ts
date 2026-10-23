import { expect, test, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * File du jour réelle (P03, AT05, premier jalon sans contrôle de paiement) : une arrivée enregistrée depuis
 * la fiche d'un patient apparaît dans la file avec le marqueur « Paiement de l’examen non contrôlé », sans
 * jamais dire « payé », « soldé » ni « gratuit » ; la caisse lit la file sans pouvoir enregistrer ; la file ne
 * déborde pas à 360 px. Le serveur et la base sont ceux de l'application construite. Tout est fictif.
 */

async function signIn(page: Page, role: string): Promise<void> {
  const account = demoAccount(role)
  await page.goto('/connexion')
  await page.getByLabel('Identifiant').fill(account.login)
  await page.getByLabel('Mot de passe').fill(account.password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

async function createPatient(page: Page, lastName: string, phone: string): Promise<string> {
  await page.goto('/patients/nouveau')
  await page.getByLabel('Nom (obligatoire)').fill(lastName)
  await page.getByLabel('Prénom(s)').fill('Test')
  await page.getByLabel('Sexe').selectOption({ label: 'Masculin' })
  await page.getByLabel('Date de naissance', { exact: true }).fill('03/04/1982')
  await page.getByLabel('Téléphone principal').fill(phone)
  await page.getByRole('button', { name: 'Enregistrer le patient' }).click()
  await expect(page).toHaveURL(/\/patients\/[0-9A-HJKMNP-TV-Z]{26}$/)
  return page.url()
}

async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
}

test.describe('file du jour (P03) sur la vraie application', () => {
  test('enregistrer une arrivée depuis la fiche, la retrouver dans la file, sans jamais dire « payé »', async ({
    page,
  }) => {
    await signIn(page, 'RECEPTION')
    const patientUrl = await createPatient(page, 'Arrivee Parcours', '+237 600 00 01 11')

    // Depuis la fiche : le patient est présélectionné.
    await page.goto(patientUrl)
    await page.getByRole('link', { name: 'Enregistrer une arrivée' }).click()
    await expect(page).toHaveURL(/\/aujourdhui\/arrivee\?patient=/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Enregistrer une arrivée' }),
    ).toBeVisible()
    await expect(page.getByRole('main')).toContainText('Arrivee Parcours')
    await page.getByLabel('Motif de la visite').selectOption({ label: 'Examen de vue' })
    await page.getByLabel('Note administrative (facultative)').fill('Vient pour un examen')
    await page.getByRole('button', { name: 'Enregistrer l’arrivée' }).click()

    // La file du jour montre l'arrivée, le statut du serveur et le marqueur de contrôle.
    await expect(page).toHaveURL(/\/aujourdhui$/)
    const main = page.getByRole('main')
    await expect(main.getByText('Arrivee Parcours').first()).toBeVisible()
    await expect(main).toContainText('Arrivé')
    await expect(main).toContainText('Paiement de l’examen non contrôlé')
    const text = (await main.innerText()).toLowerCase()
    for (const forbidden of [
      'payé',
      'soldé',
      'gratuit',
      'prêt pour l’examen',
      'démarrer l’examen',
    ]) {
      expect(text, forbidden).not.toContain(forbidden)
    }
  })

  test('annuler une visite avant l’examen : motif obligatoire, la visite reste dans la file, marquée annulée', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    await signIn(page, 'RECEPTION')
    const patientUrl = await createPatient(page, 'Annulation Parcours', '+237 600 00 08 88')
    await page.goto(patientUrl)
    await page.getByRole('link', { name: 'Enregistrer une arrivée' }).click()
    await expect(page).toHaveURL(/\/aujourdhui\/arrivee\?patient=/)
    await expect(page.getByRole('main')).toContainText('Annulation Parcours')
    await page.getByLabel('Motif de la visite').selectOption({ label: 'Examen de vue' })
    await page.getByLabel('Note administrative (facultative)').fill('Arrivée à annuler (fictif)')
    await page.getByRole('button', { name: 'Enregistrer l’arrivée' }).click()
    await expect(page).toHaveURL(/\/aujourdhui$/)

    const main = page.getByRole('main')
    await main
      .getByRole('button', { name: /Annuler la visite de .*Annulation Parcours/ })
      .first()
      .click()
    const dialog = page.getByRole('dialog', { name: /Annuler la visite de .*Annulation Parcours/ })
    await expect(dialog).toBeVisible()
    // Sans motif, rien n'est envoyé.
    await dialog.getByRole('button', { name: 'Annuler la visite' }).click()
    await expect(dialog).toContainText('Indiquez le motif')
    await dialog
      .getByLabel('Motif de l’annulation (obligatoire)')
      .fill('Patient reparti avant l’examen')
    await dialog.getByRole('button', { name: 'Annuler la visite' }).click()

    await expect(main).toContainText('Visite annulée')
    await expect(main.getByRole('link', { name: /Annulation Parcours/ }).first()).toBeVisible()
    await expect(main).toContainText('Annulé')
    await expect(
      main.getByRole('button', { name: /Annuler la visite de .*Annulation Parcours/ }),
    ).toHaveCount(0)
  })

  test('la caisse lit la file sans pouvoir enregistrer d’arrivée', async ({ page }) => {
    await signIn(page, 'CASHIER')

    await page.goto('/aujourdhui')

    await expect(page.getByRole('heading', { level: 1, name: 'Aujourd’hui' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Enregistrer une arrivée' })).toHaveCount(0)
    await page.goto('/aujourdhui/arrivee')
    await expect(page.getByText('Accès refusé').first()).toBeVisible()
  })

  test('téléphone 360 px : la file du jour ne déborde pas', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 })
    await signIn(page, 'RECEPTION')

    await page.goto('/aujourdhui')
    await expect(page.getByRole('heading', { level: 1, name: 'Aujourd’hui' })).toBeVisible()
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0)
  })
})
