import { readFileSync } from 'node:fs'

import { expect, test, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * Documents PDF locaux (D01, D02) sur la vraie application et un vrai Chromium : le PDF est généré
 * SUR LE POSTE par jsPDF sous la vraie politique de sécurité (CSP), sans requête hors de l'origine, et
 * continue de se générer réseau coupé une fois le moteur chargé. L'aperçu montre les pages et le texte,
 * le téléchargement donne un vrai PDF au nom sans donnée sensible. Tout est fictif.
 *
 * Non couvert ici (il faut le coffre, la caisse et l'impression réelle) : la conservation chiffrée des
 * PDF et la reprise après fermeture (O01 à O05, G1), l'impression sur imprimante A4 et thermique.
 */
async function signIn(page: Page, role: string): Promise<void> {
  const account = demoAccount(role)
  await page.goto('/connexion')
  await page.getByLabel('Identifiant').fill(account.login)
  await page.getByLabel('Mot de passe').fill(account.password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
}

test.describe('aperçu et PDF des documents (D01, D02)', () => {
  test('génère le PDF sur le poste, sans sortir de l’origine, puis le télécharge', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const foreign: string[] = []
    const consoleErrors: string[] = []

    await signIn(page, 'MANAGER')
    // Écoute après la connexion : la lecture de session avant connexion répond 401 et le journalise.
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text())
    })
    const appOrigin = new URL(page.url()).origin
    page.on('request', (request) => {
      const url = request.url()
      if (!url.startsWith(appOrigin) && !url.startsWith('blob:') && !url.startsWith('data:')) {
        foreign.push(url)
      }
    })

    await page.goto('/documents/apercu')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Aperçu des documents' }),
    ).toBeVisible()
    await expect(page.getByRole('main')).toContainText('Données fictives de démonstration')
    await expect(page.getByRole('main')).toContainText('ne sont conservées nulle part')

    // Premier document : la facture courte, générée par jsPDF dans la page.
    const preview = page.getByRole('region', { name: /^Aperçu : Facture courte/ })
    await expect(preview).toBeVisible({ timeout: 60_000 })
    await expect(
      preview.getByRole('img', { name: 'Page 1 sur 1 : Facture courte (80 000 FCFA)' }),
    ).toBeVisible()
    await preview.getByText('Lire le contenu de la page 1 en texte').click()
    await expect(preview).toContainText('Numéro | TEST-FAC-000001')
    await expect(preview).toContainText('DOCUMENT DE TEST')

    // Téléchargement : un vrai PDF, nom sans donnée sensible.
    const downloading = page.waitForEvent('download')
    await preview.getByRole('button', { name: 'Télécharger le PDF' }).click()
    const download = await downloading
    expect(download.suggestedFilename()).toBe('facture-TEST-FAC-000001.pdf')
    const path = await download.path()
    const bytes = readFileSync(path)
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
    expect(bytes.byteLength).toBeGreaterThan(1500)
    await expect(preview.getByRole('status')).toContainText(
      'PDF téléchargé : facture-TEST-FAC-000001.pdf.',
    )

    expect(foreign).toEqual([])
    expect(consoleErrors).toEqual([])
  })

  test('réseau coupé : un autre document se génère et se télécharge encore', async ({
    page,
    context,
  }) => {
    test.setTimeout(120_000)
    await signIn(page, 'MANAGER')
    await page.goto('/documents/apercu')
    const preview = page.getByRole('region', { name: /^Aperçu : Facture courte/ })
    await expect(preview).toBeVisible({ timeout: 60_000 })

    await context.setOffline(true)
    await page
      .getByLabel('Document à afficher')
      .selectOption({ label: 'Reçu d’acompte (30 000 FCFA)' })
    const receipt = page.getByRole('region', { name: /^Aperçu : Reçu d’acompte/ })
    await expect(receipt).toBeVisible({ timeout: 30_000 })
    await receipt.getByText('Lire le contenu de la page 1 en texte').click()
    await expect(receipt).toContainText('Acompte')
    await expect(receipt).toContainText('Numéro | TEST-REC-000001')

    const downloading = page.waitForEvent('download')
    await receipt.getByRole('button', { name: 'Télécharger le PDF' }).click()
    const download = await downloading
    expect(download.suggestedFilename()).toBe('recu-TEST-REC-000001.pdf')
    expect(
      readFileSync(await download.path())
        .subarray(0, 5)
        .toString('latin1'),
    ).toBe('%PDF-')
    await context.setOffline(false)
  })

  test('un document clinique ne s’ouvre qu’avec le droit clinique', async ({ page, context }) => {
    test.setTimeout(120_000)
    await signIn(page, 'CASHIER')
    await page.goto('/documents/apercu')
    const select = page.getByLabel('Document à afficher')
    await expect(select).toBeVisible({ timeout: 30_000 })
    const options = await select.locator('option').allTextContents()

    expect(options.some((label) => label.startsWith('Reçu d’acompte'))).toBe(true)
    for (const clinical of [
      'Prescription',
      'Ordonnance',
      'Lettre d’orientation',
      'Fiche de consultation',
    ]) {
      expect(
        options.some((label) => label.includes(clinical)),
        clinical,
      ).toBe(false)
    }

    await context.clearCookies()
    await signIn(page, 'CLINICIAN')
    await page.goto('/documents/apercu')
    // Attendre l'écran (session relue, liste rendue) avant de lire les choix : `allTextContents` n'attend rien.
    const clinicalSelect = page.getByLabel('Document à afficher')
    await expect(clinicalSelect).toBeVisible({ timeout: 30_000 })
    const clinicalOptions = await clinicalSelect.locator('option').allTextContents()

    expect(clinicalOptions.some((label) => label.startsWith('Prescription optique'))).toBe(true)
    expect(clinicalOptions.some((label) => label.startsWith('Reçu'))).toBe(false)
  })

  test('téléphone 360 px : l’aperçu ne fait pas défiler la page en largeur', async ({ page }) => {
    test.setTimeout(120_000)
    await page.setViewportSize({ width: 360, height: 740 })
    await signIn(page, 'MANAGER')
    await page.goto('/documents/apercu')
    const preview = page.getByRole('region', { name: /^Aperçu : Facture courte/ })
    await expect(preview).toBeVisible({ timeout: 60_000 })

    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0)
    for (const name of ['Ouvrir le PDF', 'Imprimer', 'Télécharger le PDF']) {
      const box = await preview.getByRole('button', { name }).boundingBox()
      expect(box?.height ?? 0, name).toBeGreaterThanOrEqual(44)
    }
  })
})
