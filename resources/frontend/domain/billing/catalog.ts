import type {
  CatalogItem,
  CatalogItemRequest,
  CatalogItemsStockMode,
  TaxRule,
  TaxRuleRequest,
} from '../contracts/generated'
import { trimBlanks } from '../values/text'

/**
 * Catalogue et règles fiscales saisis par la gérante (B01), EN LIGNE. Aucun prix, aucune taxe et aucune
 * référence ne sont fournis par l'application : en développement, seules des données FICTIVES existent
 * (`eeo:demo:sales`). Libellés des catégories et unités = référentiel `database/02_reference_data.sql`
 * (test d'égalité) ; libellés des modes de stock : propositions d'affichage.
 *
 * Les charges ci-dessous sont celles de `CatalogItemRequest` et `TaxRuleRequest` sans
 * `expected_version` : le répartiteur l'ajoute (null à la création, version de l'entité à la
 * modification, 409 `VERSION_CONFLICT` si elle a changé).
 */
export const CATEGORY_LABELS: Readonly<Record<string, string>> = {
  SERVICE: 'Prestation / examen',
  FRAME: 'Monture',
  LENS: 'Verre',
  CONTACT_LENS: 'Lentille',
  COLLYRE: 'Collyre',
  DERMOCOSMETIC: 'Dermocosmétique',
  OTHER: 'Autre',
}

/** Unités du référentiel et leur contrainte « quantité entière » (`units.integer_only`). */
export const UNIT_INTEGER_ONLY: Readonly<Record<string, boolean>> = {
  UNIT: true,
  PAIR: true,
  SERVICE: false,
  OTHER: false,
}

export const STOCK_MODE_LABELS: Readonly<Record<CatalogItemsStockMode, string>> = {
  NONE: 'Sans stock (prestation)',
  STOCKED: 'Article géré en stock',
  MADE_TO_ORDER: 'Sur mesure (commandé)',
}

export function categoryLabel(code: string): string {
  return CATEGORY_LABELS[code] ?? code
}

export function stockModeLabel(mode: CatalogItemsStockMode): string {
  return STOCK_MODE_LABELS[mode]
}

export const CATALOG_TEXT_LIMITS = { reference: 64, name: 180, brand: 120, model: 120 } as const

export interface CatalogItemForm {
  reference: string
  categoryCode: string | null
  name: string
  brand: string
  model: string
  unitCode: string | null
  quantityIntegerOnly: boolean
  salePrice: number | null
  purchaseCost: number | null
  taxRuleId: string | null
  stockMode: CatalogItemsStockMode | null
  requiresExpiry: boolean
  /** Seuil de stock bas, quantité canonique ou null. */
  lowStockThreshold: string | null
  expiryAlertDays: string
  active: boolean
  publicVisibility: boolean
}

export type CatalogField = keyof CatalogItemForm

export type CatalogErrors = Partial<Record<CatalogField, string>>

export function blankCatalogForm(): CatalogItemForm {
  return {
    reference: '',
    categoryCode: null,
    name: '',
    brand: '',
    model: '',
    unitCode: null,
    quantityIntegerOnly: true,
    salePrice: null,
    purchaseCost: null,
    taxRuleId: null,
    stockMode: null,
    requiresExpiry: false,
    lowStockThreshold: null,
    expiryAlertDays: '',
    active: true,
    publicVisibility: false,
  }
}

export function catalogFormFromItem(item: CatalogItem): CatalogItemForm {
  return {
    reference: item.reference,
    categoryCode: item.category_code,
    name: item.name,
    brand: item.brand ?? '',
    model: item.model ?? '',
    unitCode: item.unit_code,
    quantityIntegerOnly: item.quantity_integer_only,
    salePrice: item.sale_price,
    purchaseCost: item.purchase_cost ?? null,
    taxRuleId: item.tax_rule_id,
    stockMode: item.stock_mode,
    requiresExpiry: item.requires_expiry,
    lowStockThreshold: item.low_stock_threshold,
    expiryAlertDays: item.expiry_alert_days === null ? '' : String(item.expiry_alert_days),
    active: item.active,
    publicVisibility: item.public_visibility,
  }
}

function optional(value: string): string | null {
  const trimmed = trimBlanks(value)
  return trimmed === '' ? null : trimmed
}

function hasControlCharacter(text: string): boolean {
  for (const character of text) {
    const code = character.codePointAt(0) ?? 0
    if (code <= 0x1f || code === 0x7f) return true
  }
  return false
}

export type CatalogRequestPayload = Omit<CatalogItemRequest, 'expected_version'>

/**
 * Contrôles d'écran (mêmes règles croisées que `CatalogItemInput` du serveur, qui reste juge) puis
 * charge. `withCost` : le compte lit et saisit le coût d'achat (`catalog.purchase_cost.read`) ; sans
 * ce droit, le coût n'est jamais envoyé autrement que tel qu'il a été lu (absent : null).
 */
export function validateCatalogForm(
  form: CatalogItemForm,
): { ok: true; payload: CatalogRequestPayload } | { ok: false; errors: CatalogErrors } {
  const errors: CatalogErrors = {}
  const text = (
    field: 'reference' | 'name' | 'brand' | 'model',
    required: boolean,
  ): string | null => {
    const value = optional(form[field])
    if (value === null) {
      if (required) errors[field] = 'Ce champ est obligatoire.'
      return null
    }
    if (value.length > CATALOG_TEXT_LIMITS[field]) {
      errors[field] = `Au plus ${CATALOG_TEXT_LIMITS[field]} caractères.`
    } else if (hasControlCharacter(value)) {
      errors[field] = 'Ce champ ne peut contenir ni retour à la ligne ni caractère de contrôle.'
    }
    return value
  }
  const reference = text('reference', true)
  const name = text('name', true)
  const brand = text('brand', false)
  const model = text('model', false)

  if (form.categoryCode === null || !(form.categoryCode in CATEGORY_LABELS)) {
    errors.categoryCode = 'Choisissez une catégorie.'
  }
  if (form.unitCode === null || !(form.unitCode in UNIT_INTEGER_ONLY)) {
    errors.unitCode = 'Choisissez une unité.'
  }
  if (form.stockMode === null) errors.stockMode = 'Choisissez le mode de stock.'
  if (form.salePrice === null) errors.salePrice = 'Le prix de vente est obligatoire (FCFA).'

  let alertDays: number | null = null
  const daysText = trimBlanks(form.expiryAlertDays)
  if (daysText !== '') {
    if (!/^[0-9]{1,4}$/.test(daysText)) {
      errors.expiryAlertDays = 'Un nombre entier de jours est attendu.'
    } else {
      alertDays = Number(daysText)
    }
  }

  if (form.categoryCode === 'COLLYRE' && !form.requiresExpiry) {
    errors.requiresExpiry = 'Un collyre exige un numéro de lot et une date d’expiration.'
  }
  if (form.requiresExpiry && form.stockMode !== null && form.stockMode !== 'STOCKED') {
    errors.requiresExpiry = 'Seul un article géré en stock peut avoir une date d’expiration.'
  }
  if (
    form.categoryCode === 'SERVICE' &&
    (form.stockMode === 'STOCKED' || form.stockMode === 'MADE_TO_ORDER')
  ) {
    errors.stockMode = 'Une prestation n’a aucun mouvement de stock.'
  }
  if (
    form.unitCode !== null &&
    UNIT_INTEGER_ONLY[form.unitCode] === true &&
    !form.quantityIntegerOnly
  ) {
    errors.quantityIntegerOnly = 'Cette unité ne se divise pas : la quantité doit être entière.'
  }
  if (form.lowStockThreshold !== null && form.stockMode !== 'STOCKED') {
    errors.lowStockThreshold =
      'Un seuil de stock bas n’a de sens que pour un article géré en stock.'
  }
  if (alertDays !== null && !form.requiresExpiry) {
    errors.expiryAlertDays =
      'Un délai d’alerte de péremption n’a de sens que pour un article périssable.'
  }

  if (
    Object.keys(errors).length > 0 ||
    reference === null ||
    name === null ||
    form.categoryCode === null ||
    form.unitCode === null ||
    form.stockMode === null ||
    form.salePrice === null
  ) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    payload: {
      reference,
      category_code: form.categoryCode,
      name,
      brand,
      model,
      unit_code: form.unitCode,
      quantity_integer_only: form.quantityIntegerOnly,
      sale_price: form.salePrice,
      purchase_cost: form.purchaseCost,
      tax_rule_id: form.taxRuleId,
      stock_mode: form.stockMode,
      requires_expiry: form.requiresExpiry,
      low_stock_threshold: form.lowStockThreshold,
      expiry_alert_days: alertDays,
      active: form.active,
      public_visibility: form.publicVisibility,
    },
  }
}

/** Champ du formulaire d'article désigné par une erreur du serveur (`sale_price` → `salePrice`). */
export function catalogFieldFromServer(key: string): CatalogField | null {
  const map: Readonly<Record<string, CatalogField>> = {
    reference: 'reference',
    category_code: 'categoryCode',
    name: 'name',
    brand: 'brand',
    model: 'model',
    unit_code: 'unitCode',
    quantity_integer_only: 'quantityIntegerOnly',
    sale_price: 'salePrice',
    purchase_cost: 'purchaseCost',
    tax_rule_id: 'taxRuleId',
    stock_mode: 'stockMode',
    requires_expiry: 'requiresExpiry',
    low_stock_threshold: 'lowStockThreshold',
    expiry_alert_days: 'expiryAlertDays',
    active: 'active',
    public_visibility: 'publicVisibility',
  }
  return map[key] ?? null
}

// --- Règles fiscales ------------------------------------------------------------------------------

export interface TaxRuleForm {
  code: string
  label: string
  /** Taux canonique (« 10.0000 ») ou null. */
  ratePercent: string | null
  active: boolean
  validate: boolean
  legalNote: string
}

export type TaxRuleField = keyof TaxRuleForm

export type TaxRuleErrors = Partial<Record<TaxRuleField, string>>

export function blankTaxRuleForm(): TaxRuleForm {
  return { code: '', label: '', ratePercent: null, active: false, validate: false, legalNote: '' }
}

export function taxRuleFormFromRule(rule: TaxRule): TaxRuleForm {
  return {
    code: rule.code,
    label: rule.label,
    ratePercent: rule.rate_percent,
    active: rule.active,
    validate: false,
    legalNote: rule.legal_note ?? '',
  }
}

export type TaxRuleRequestPayload = Omit<TaxRuleRequest, 'expected_version'>

/**
 * Règle fiscale : une règle active exige un taux et sa validation ; changer le taux d'une règle validée
 * retire la validation (à redonner dans la même demande). Le serveur tranche (`TaxRuleInput`).
 */
export function validateTaxRuleForm(
  form: TaxRuleForm,
  current: TaxRule | null,
): { ok: true; payload: TaxRuleRequestPayload } | { ok: false; errors: TaxRuleErrors } {
  const errors: TaxRuleErrors = {}
  const code = trimBlanks(form.code)
  const label = trimBlanks(form.label)
  if (code === '') errors.code = 'Ce champ est obligatoire.'
  else if (code.length > 40) errors.code = 'Au plus 40 caractères.'
  if (label === '') errors.label = 'Ce champ est obligatoire.'
  else if (label.length > 180) errors.label = 'Au plus 180 caractères.'
  const note = trimBlanks(form.legalNote)
  if (note.length > 4000) errors.legalNote = 'Au plus 4 000 caractères.'

  if (form.validate && form.ratePercent === null) {
    errors.validate = 'Une règle sans taux ne peut pas être validée.'
  }
  const rateChanged =
    current !== null && current.rate_percent !== null && form.ratePercent !== current.rate_percent
  const keepsValidation = current !== null && current.validated_at !== null && !rateChanged
  if (form.active && form.ratePercent === null) {
    errors.active = 'Une règle active exige un taux.'
  } else if (form.active && !form.validate && !keepsValidation) {
    errors.active = rateChanged
      ? 'Le taux a changé : revalidez la règle pour la laisser active.'
      : 'Une règle active doit être validée par la gérante.'
  }
  if (Object.keys(errors).length > 0) return { ok: false, errors }
  return {
    ok: true,
    payload: {
      code,
      label,
      rate_percent: form.ratePercent,
      active: form.active,
      validate: form.validate,
      legal_note: note === '' ? null : note,
    },
  }
}

export function taxRuleFieldFromServer(key: string): TaxRuleField | null {
  const map: Readonly<Record<string, TaxRuleField>> = {
    code: 'code',
    label: 'label',
    rate_percent: 'ratePercent',
    active: 'active',
    validate: 'validate',
    legal_note: 'legalNote',
  }
  return map[key] ?? null
}

/** État d'une règle dit en toutes lettres : jamais appliquée aux documents sans validation. */
export function taxRuleState(rule: TaxRule): string {
  if (!rule.active) return 'Inactive'
  return rule.validated_at === null ? 'Active, non validée (non appliquée)' : 'Active et validée'
}
