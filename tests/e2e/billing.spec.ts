import { expect, test, type Browser, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * Facturation et encaissement EN LIGNE sur la vraie application (B02 à B05, AT10, AT11) : la caisse
 * ouvre sa session, crée une facture pour un patient depuis sa fiche (identité reprise, jamais
 * ressaisie), l'émet (numéro définitif du serveur après confirmation), encaisse un acompte en espèces
 * puis le solde en mobile money, obtient deux reçus distincts, et retrouve tout après rechargement.
 * Jamais « payé », « soldé » ni « acquitté ». Les articles DEMO-* et leurs prix sont FICTIFS
 * (`eeo:demo:sales`, lancé par prepare-env.mjs avec les modules « sales » et « stock » ouverts).
 */

const FORBIDDEN = ['payé', 'payée', 'soldé', 'soldée', 'acquitté', 'acquittée', 'gratuit']

async function signIn(page: Page, role: string): Promise<void> {
  const account = demoAccount(role)
  await page.goto('/connexion')
  await page.getByLabel('Identifiant').fill(account.login)
  await page.getByLabel('Mot de passe').fill(account.password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

async function createPatient(browser: Browser, lastName: string, phone: string): Promise<string> {
  const context = await browser.newContext()
  const page = await context.newPage()
  await signIn(page, 'RECEPTION')
  await page.goto('/patients/nouveau')
  await page.getByLabel('Nom (obligatoire)').fill(lastName)
  await page.getByLabel('Prénom(s)').fill('Test')
  await page.getByLabel('Sexe').selectOption({ label: 'Féminin' })
  await page.getByLabel('Date de naissance', { exact: true }).fill('12/03/1985')
  await page.getByLabel('Téléphone principal').fill(phone)
  await page.getByRole('button', { name: 'Enregistrer le patient' }).click()
  await expect(page).toHaveURL(/\/patients\/[0-9A-HJKMNP-TV-Z]{26}$/)
  const url = new URL(page.url()).pathname
  await context.close()
  return url
}

/** Ouvre la session de caisse du compte si elle ne l'est pas déjà (base e2e réutilisée). */
async function ensureCashOpen(page: Page): Promise<void> {
  await page.goto('/ventes/caisse')
  await expect(page.getByRole('heading', { level: 1, name: 'Caisse' })).toBeVisible()
  const main = page.getByRole('main')
  await expect(main.getByText(/Session ouverte|Aucune session de caisse ouverte/).first()).toBeVisible()
  if (await main.getByText('Aucune session de caisse ouverte').isVisible()) {
    await page.getByLabel('Fond de caisse').fill('10 000')
    await page.getByRole('button', { name: 'Ouvrir la caisse' }).click()
  }
  await expect(main.getByText(/^Session ouverte/)).toBeVisible()
  await expect(main).toContainText('Espèces attendues dans le tiroir')
}

async function addCatalogLine(page: Page, search: string, name: RegExp): Promise<void> {
  await page.getByLabel('Ajouter un article du catalogue').fill(search)
  await page.getByRole('option', { name }).click()
}

function receiptNumbers(text: string): string[] {
  return [...new Set(text.match(/TEST-REC-\d{4}-\d{6}/g) ?? [])]
}

test.describe('facturation et encaissement (B02 à B05) sur la vraie application', () => {
  test('ouvrir la caisse, facturer, émettre, encaisser deux fois, deux reçus, recharger', async ({
    browser,
  }) => {
    const patientPath = await createPatient(browser, 'Facture Parcours', '+237 600 00 02 22')

    const context = await browser.newContext()
    const page = await context.newPage()
    await signIn(page, 'CASHIER')
    await ensureCashOpen(page)

    // Depuis la fiche : le patient est présélectionné, son identité n'est pas ressaisie.
    await page.goto(patientPath)
    await page.getByRole('link', { name: 'Créer une facture' }).click()
    await expect(page).toHaveURL(/\/ventes\/factures\/nouvelle\?patient=[0-9A-HJKMNP-TV-Z]{26}$/)
    const main = page.getByRole('main')
    await expect(main).toContainText('Facture Parcours')
    await expect(page.getByLabel('Nom (obligatoire)')).toHaveCount(0)

    await addCatalogLine(page, 'DEMO-MON', /Monture fictive/)
    await addCatalogLine(page, 'DEMO-EXA', /Examen de vue \(fictif\)/)
    await expect(main).toContainText('Total TTC : 55 000 FCFA')
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
    await expect(page).toHaveURL(/\/ventes\/factures\/[0-9A-HJKMNP-TV-Z]{26}$/)
    await expect(main).toContainText('Brouillon de facture enregistré sur le serveur.')
    await expect(main).not.toContainText('TEST-FAC-')

    // Émission : confirmation explicite, numéro définitif après la réponse du serveur.
    await page.getByRole('button', { name: 'Émettre la facture' }).click()
    const dialog = page.getByRole('dialog', { name: 'Émettre la facture ?' })
    await expect(dialog).toContainText('La facture émise ne pourra plus être modifiée.')
    await dialog.getByRole('button', { name: 'Émettre la facture' }).click()
    await expect(page.getByRole('heading', { level: 1, name: /^Facture TEST-FAC-\d{4}-\d{6}$/ })).toBeVisible()
    await expect(main).toContainText('Reste à encaisser : 55 000 FCFA')

    // Acompte en espèces (session ouverte).
    await page.getByLabel('Montant encaissé').fill('20 000')
    await page.getByLabel('Mode de règlement').selectOption({ label: 'Espèces' })
    await page.getByRole('button', { name: /^Encaisser \d/ }).click()
    await expect(main).toContainText('Encaissement enregistré sur le serveur')
    await expect(main).toContainText('Reste à encaisser maintenant : 35 000 FCFA')
    await expect(main).toContainText('Acompte')

    // Solde en mobile money, avec sa référence (fictive).
    await page.getByRole('button', { name: 'Nouvel encaissement' }).click()
    await page.getByRole('button', { name: /^Encaisser le reste/ }).click()
    await page.getByLabel('Mode de règlement').selectOption({ label: 'Mobile money MTN' })
    await page.getByLabel('Référence de l’opération').fill('REFERENCE-FICTIVE-E2E')
    await page.getByRole('button', { name: /^Encaisser \d/ }).click()
    await expect(main).toContainText('Reste à encaisser maintenant : 0 FCFA')
    await expect(main).toContainText('Règlement du solde')

    const numbers = receiptNumbers(await main.innerText())
    expect(numbers).toHaveLength(2)

    // Rechargement : la facture, ses deux reçus et le reste nul viennent du serveur.
    await page.reload()
    await expect(page.getByRole('heading', { level: 1, name: /^Facture TEST-FAC-/ })).toBeVisible()
    for (const number of numbers) await expect(main).toContainText(`Reçu ${number}`)
    await expect(page.getByRole('form', { name: 'Encaisser' })).toHaveCount(0)
    const text = (await main.innerText()).toLowerCase()
    for (const word of FORBIDDEN) expect(text, word).not.toContain(word)

    // Téléphone 360 px : la fiche de la facture ne déborde pas.
    await page.setViewportSize({ width: 360, height: 740 })
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
    ).toBeLessThanOrEqual(0)

    await context.close()
  })

  test('liste filtrée par jour d’émission, caisse du jour, abandon d’un brouillon', async ({ browser }) => {
    test.setTimeout(180_000)
    const patientPath = await createPatient(browser, 'Jour Parcours', '+237 600 00 04 44')

    const context = await browser.newContext()
    const page = await context.newPage()
    await signIn(page, 'CASHIER')
    await ensureCashOpen(page)
    const main = page.getByRole('main')

    // Une facture émise aujourd'hui et encaissée en espèces.
    await page.goto(patientPath)
    await page.getByRole('link', { name: 'Créer une facture' }).click()
    await addCatalogLine(page, 'DEMO-EXA', /Examen de vue \(fictif\)/)
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
    await expect(page).toHaveURL(/\/ventes\/factures\/[0-9A-HJKMNP-TV-Z]{26}$/)
    await page.getByRole('button', { name: 'Émettre la facture' }).click()
    await page.getByRole('dialog', { name: 'Émettre la facture ?' }).getByRole('button', { name: 'Émettre la facture' }).click()
    const heading = page.getByRole('heading', { level: 1, name: /^Facture TEST-FAC-\d{4}-\d{6}$/ })
    await expect(heading).toBeVisible()
    const number = ((await heading.innerText()).match(/TEST-FAC-\d{4}-\d{6}/) ?? [''])[0]
    await page.getByRole('button', { name: /^Encaisser le reste/ }).click()
    await page.getByLabel('Mode de règlement').selectOption({ label: 'Espèces' })
    await page.getByRole('button', { name: /^Encaisser \d/ }).click()
    await expect(main).toContainText('Reste à encaisser maintenant : 0 FCFA')

    // Liste : patient affiché, filtre du jour appliqué par le serveur (aujourd'hui au fuseau du cabinet).
    await page.goto('/ventes/factures')
    await expect(main).toContainText('Jour Parcours')
    const today = await page.evaluate(() =>
      new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Douala', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date()),
    )
    await page.getByLabel('Jour d’émission').fill(today)
    await page.getByLabel('Jour d’émission').blur()
    await expect(main).toContainText(number)
    await expect(main).not.toContainText('page affichée')

    // Caisse du jour : ses encaissements et ses sessions, jamais « payé ».
    await page.goto('/ventes/caisse-du-jour')
    await expect(page.getByRole('heading', { level: 1, name: /Caisse du jour/ })).toBeVisible()
    await expect(main).toContainText('Encaissements nets par mode')
    await expect(main).toContainText('Espèces')
    await expect(main).toContainText('Vos encaissements et vos sessions seulement')
    const dayText = (await main.innerText()).toLowerCase()
    for (const word of FORBIDDEN) expect(dayText, word).not.toContain(word)

    // Abandon d'un brouillon : ni numéro ni effet financier.
    await page.goto(patientPath)
    await page.getByRole('link', { name: 'Créer une facture' }).click()
    await addCatalogLine(page, 'DEMO-MON', /Monture fictive/)
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
    await expect(page).toHaveURL(/\/ventes\/factures\/[0-9A-HJKMNP-TV-Z]{26}$/)
    await page.getByRole('button', { name: 'Abandonner le brouillon' }).click()
    const dialog = page.getByRole('dialog', { name: 'Abandonner ce brouillon ?' })
    await dialog.getByLabel('Motif (facultatif)').fill('Le patient renonce (fictif)')
    await dialog.getByRole('button', { name: 'Abandonner le brouillon' }).click()
    await expect(main).toContainText('Facture abandonnée')
    await page.reload()
    await expect(main).toContainText('Facture abandonnée')
    await expect(main).not.toContainText('TEST-FAC-')

    await context.close()
  })

  test('la réception ne voit ni les ventes ni « Créer une facture »', async ({ page }) => {
    await signIn(page, 'RECEPTION')
    await expect(page.getByRole('link', { name: 'Ventes et caisse' })).toHaveCount(0)
    await page.goto('/ventes/caisse')
    await expect(page.getByText('Accès refusé').first()).toBeVisible()
  })
})
