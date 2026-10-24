import { expect, test, type BrowserContext, type Locator, type Page } from '@playwright/test'

import { demoAccount } from './support/accounts'

/**
 * Outils de la gérante et aide intégrée sur la vraie application (lot 2) : aide filtrée par profil (l'accueil ne
 * lit jamais l'aide des examens), statistiques séparées par droits, import contrôlé du catalogue depuis un fichier
 * CSV (aperçu sans écriture, puis tout ou rien). Données FICTIVES.
 */

async function signIn(page: Page, role: string): Promise<void> {
  const account = demoAccount(role)
  await page.goto('/connexion')
  // Diagnostic lisible dans les annotations de la CI si le formulaire n'apparaît pas (adresse et texte de la page).
  await page
    .getByLabel('Identifiant')
    .waitFor({ timeout: 20_000 })
    .catch(async (error: unknown) => {
      const text = await page
        .locator('body')
        .innerText({ timeout: 2_000 })
        .catch(() => 'illisible')
      throw new Error(
        `Formulaire de connexion absent : ${page.url()} — ${text.replace(/\s+/g, ' ').slice(0, 500)} — ${String(error).slice(0, 200)}`,
      )
    })
  await page.getByLabel('Identifiant').fill(account.login)
  await page.getByLabel('Mot de passe').fill(account.password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /^Bonjour / })).toBeVisible()
}

async function switchTo(page: Page, context: BrowserContext, role: string): Promise<void> {
  // Quitter la page d'abord : une requête encore en vol ne doit pas recréer le cookie de session après son effacement.
  await page.goto('about:blank')
  await context.clearCookies()
  await page.goto('/connexion')
  await signIn(page, role)
}

test('l’aide ne présente que les écrans du profil', async ({ page, context }) => {
  await signIn(page, 'RECEPTION')
  await page.goto('/aide')
  await expect(page.getByRole('heading', { level: 1, name: 'Aide' })).toBeVisible()
  const nav = page.getByRole('navigation', { name: 'Sommaire' })
  await expect(nav.getByRole('button', { name: 'Enregistrer une arrivée' })).toBeVisible()
  await expect(nav.getByRole('button', { name: /Examen/ })).toHaveCount(0)
  await expect(nav.getByRole('button', { name: /Facturer/ })).toHaveCount(0)

  await switchTo(page, context, 'CLINICIAN')
  await page.goto('/aide')
  await expect(
    page.getByRole('navigation', { name: 'Sommaire' }).getByRole('button', { name: /Examen/ }),
  ).toBeVisible()
  const text = (await page.getByRole('main').innerText()).toLowerCase()
  for (const forbidden of ['payé', 'soldé', 'gratuit', 'enregistré sur ce poste']) {
    expect(text, forbidden).not.toContain(forbidden)
  }
})

test('les statistiques sont séparées par droits', async ({ page, context }) => {
  await signIn(page, 'RECEPTION')
  await page.goto('/rapports/statistiques')
  await expect(page.getByRole('heading', { level: 2, name: 'Activité de l’accueil' })).toBeVisible()
  await expect(page.getByRole('heading', { level: 2, name: 'Examens' })).toHaveCount(0)
  await page.getByRole('button', { name: 'Cette année' }).click()
  await expect(page.getByText('Visites par motif')).toBeVisible()

  await switchTo(page, context, 'CLINICIAN')
  await page.goto('/rapports/statistiques')
  await expect(page.getByRole('heading', { level: 2, name: 'Examens' })).toBeVisible()
  await expect(page.getByRole('heading', { level: 2, name: 'Activité de l’accueil' })).toHaveCount(
    0,
  )
})

/**
 * Opération sensible : le serveur peut demander la confirmation du mot de passe (423). Attend la réponse
 * (résultat attendu ou boîte de confirmation), confirme au besoin, puis attend le résultat.
 */
async function confirmIfAsked(page: Page, outcome: Locator): Promise<void> {
  const dialog = page.getByRole('dialog')
  await expect(dialog.or(outcome).first()).toBeVisible()
  if (await dialog.isVisible()) {
    await dialog.getByLabel('Mot de passe').fill(demoAccount('MANAGER').password)
    await dialog.getByRole('button', { name: /Confirmer/ }).click()
  }
  await expect(outcome).toBeVisible()
}

test('importer le catalogue : aperçu sans écriture, puis tout ou rien', async ({ page }) => {
  test.setTimeout(180_000)
  await signIn(page, 'MANAGER')
  await page.goto('/ventes/catalogue')
  await page.getByRole('link', { name: 'Importer un fichier CSV' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Importer le catalogue' })).toBeVisible()

  const header =
    'reference;designation;categorie;marque;modele;unite;quantite_entiere;prix_vente;cout_achat;taxe;mode_stock;perissable;seuil_stock;alerte_peremption_jours;actif;visible_public'
  const invalid = [
    header,
    'E2E-IMP-001;Monture importée fictive;Monture;;;Paire;oui;45 000;;;stocké;non;;;oui;non',
    'E2E-IMP-002;Article inconnu;Catégorie inventée;;;Unité;oui;1 000;;;stocké;non;;;oui;non',
  ].join('\r\n')
  await page.getByLabel('Fichier CSV du catalogue').setInputFiles({
    name: 'catalogue.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(invalid, 'utf8'),
  })
  await expect(page.getByRole('main')).toContainText('2 article(s) lu(s)')
  await page
    .getByRole('region', { name: '3. Vérifier, puis importer' })
    .getByRole('button', { name: 'Vérifier sur le serveur' })
    .click()
  await confirmIfAsked(
    page,
    page.getByText('Lignes refusées par le serveur : rien n’a été enregistré'),
  )
  await expect(page.getByRole('main')).toContainText('Ligne 3')
  await expect(page.getByRole('button', { name: /^Importer/ })).toBeDisabled()

  const valid = [
    header,
    'E2E-IMP-001;Monture importée fictive;Monture;;;Paire;oui;45 000;;;stocké;non;;;oui;non',
  ].join('\r\n')
  await page.getByLabel('Fichier CSV du catalogue').setInputFiles({
    name: 'catalogue.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(valid, 'utf8'),
  })
  await page
    .getByRole('region', { name: '3. Vérifier, puis importer' })
    .getByRole('button', { name: 'Vérifier sur le serveur' })
    .click()
  await confirmIfAsked(page, page.getByText('Fichier vérifié par le serveur'))
  await page.getByRole('button', { name: 'Importer 1 article(s)' }).click()
  await confirmIfAsked(page, page.getByText('Catalogue importé'))

  await page.getByRole('link', { name: 'Voir le catalogue' }).click()
  await expect(page.getByRole('main')).toContainText('E2E-IMP-001')
})
