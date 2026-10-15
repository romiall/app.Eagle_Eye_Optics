/* eslint-disable */
// Généré par contracts/tools/generate-types.mjs depuis les JSON Schema de contracts/ : ne pas modifier
// à la main. Les règles conditionnelles (if/then, not) sont vérifiées à l'exécution par Ajv.

/**
 * Identifiant ULID ASCII (26 caractères Crockford, majuscules), connu avant tout commit local ; jamais l'identifiant nul.
 */
export type Ulid = string
/**
 * Code ASCII de référentiel (VARCHAR(40) ascii).
 */
export type Code = string
/**
 *  (catalogue : consultation_histories.history_group)
 */
export type ConsultationHistoriesHistoryGroup = ("OCULAR_HISTORY" | "MEDICAL_HISTORY" | "FAMILY_HISTORY")
/**
 *  (catalogue : visual_acuities.context)
 */
export type VisualAcuitiesContext = ("PRELIMINARY" | "POST_REFRACTION")
/**
 *  (catalogue : visual_acuities.distance)
 */
export type VisualAcuitiesDistance = ("FAR" | "NEAR" | "UNSPECIFIED")
/**
 *  (catalogue : visual_acuities.correction)
 */
export type VisualAcuitiesCorrection = ("WITHOUT" | "WITH")
/**
 *  (catalogue : visual_acuities.eye)
 */
export type VisualAcuitiesEye = ("OD" | "OG" | "OU")
/**
 *  (catalogue : refraction_measurements.stage)
 */
export type RefractionMeasurementsStage = ("AUTOREFRACTOR" | "OBJECTIVE" | "SUBJECTIVE")
/**
 *  (catalogue : refraction_measurements.eye)
 */
export type RefractionMeasurementsEye = ("OD" | "OG")
export type NullableDiopter = (Diopter | null)
/**
 * Valeur optique DECIMAL(6,2) en chaîne exacte, signe explicite admis ; null = non mesuré (jamais remplacé par zéro).
 */
export type Diopter = string
/**
 *  (catalogue : consultations.referral_urgency)
 */
export type ConsultationsReferralUrgency = ("NOT_URGENT" | "RECOMMENDED" | "RAPID" | "EMERGENCY")
export type StepName = ("ANAMNESIS" | "HISTORIES" | "VISUAL_ACUITY" | "REFRACTION" | "CENTERING" | "CONCLUSION")
export type NullableUlid = (Ulid | null)
/**
 * Code de permission du référentiel seedé (ex. invoice.issue).
 */
export type PermissionCode = string
/**
 * Empreinte SHA-256 en hexadécimal minuscule (64 caractères).
 */
export type Sha256Hex = string
export type NullableSha256Hex = (Sha256Hex | null)
/**
 * JWS compact base64url (en-tête.charge.signature), ES256 uniquement.
 */
export type CompactJws = string
/**
 * Montant en francs CFA (XAF), entier exact, bornes SQL 0..1 000 000 000 000 ; jamais de flottant.
 */
export type MoneyXaf = number
/**
 * Montant strictement positif (paiement, remboursement, contre-écriture).
 */
export type PositiveMoneyXaf = number
/**
 * Montant signé (solde, écart de caisse) : entier exact.
 */
export type SignedMoneyXaf = number
/**
 * Monnaie unique du cabinet (CHECK currency = 'XAF').
 */
export type Currency = "XAF"
/**
 * Quantité DECIMAL(12,3) en chaîne décimale exacte (point décimal), strictement positive.
 */
export type Quantity = string
/**
 * Quantité DECIMAL(12,3) signée en chaîne exacte (mouvements de stock).
 */
export type SignedQuantity = string
/**
 * Taux en pourcentage DECIMAL en chaîne exacte (0 à 100).
 */
export type Rate = string
/**
 * Compteur BIGINT (séquence d'appareil, curseur) : chaîne de chiffres sans zéro non significatif ; jamais un nombre JS hors plage sûre.
 */
export type Counter = string
/**
 * Version optimiste (brouillon ou ledger dérivé), entier ≥ 1.
 */
export type Version = number
/**
 * Instant UTC ISO 8601 à la milliseconde, suffixe Z (ex. 2026-10-14T09:30:00.000Z).
 */
export type Instant = string
export type NullableInstant = (Instant | null)
/**
 * Date civile YYYY-MM-DD dans le fuseau du cabinet (Africa/Douala) ; jamais déduite d'un minuit UTC.
 */
export type CivilDate = string
export type NullableCivilDate = (CivilDate | null)
export type ShortText = string
/**
 * Motif obligatoire d'une opération sensible.
 */
export type Reason = string
export type Phone = string
/**
 *  (catalogue : generated_documents.document_type)
 */
export type GeneratedDocumentsDocumentType = ("INVOICE" | "RECEIPT" | "CREDIT_NOTE" | "REFUND" | "OPTICAL_PRESCRIPTION" | "CLINICAL_RECORD" | "WORKSHOP" | "ACCOUNT_STATEMENT" | "LEGACY_BALANCE" | "QUOTE")
/**
 *  (catalogue : appointments.source)
 */
export type AppointmentsSource = ("INTERNAL" | "GOOGLE" | "CRM" | "WEBSITE")
/**
 *  (catalogue : appointments.status)
 */
export type AppointmentsStatus = ("PENDING_CONFIRMATION" | "CONFIRMED" | "ARRIVED" | "COMPLETED" | "CANCELLED" | "NO_SHOW")
/**
 *  (catalogue : approvals.status)
 */
export type ApprovalsStatus = ("REQUESTED" | "APPROVED" | "REFUSED" | "EXPIRED" | "CONSUMED")
/**
 *  (catalogue : assistant_runs.status)
 */
export type AssistantRunsStatus = ("RUNNING" | "COMPLETED" | "HANDOFF" | "FAILED")
/**
 *  (catalogue : backup_runs.scope)
 */
export type BackupRunsScope = ("SERVER_DATABASE" | "SERVER_FILES" | "OFFLINE_UNSYNCED")
/**
 *  (catalogue : backup_runs.status)
 */
export type BackupRunsStatus = ("RUNNING" | "SUCCEEDED" | "FAILED")
/**
 *  (catalogue : calendar_blocks.origin)
 */
export type CalendarBlocksOrigin = ("INTERNAL" | "GOOGLE")
/**
 *  (catalogue : calendar_sync_states.status)
 */
export type CalendarSyncStatesStatus = ("NOT_CONFIGURED" | "READY" | "SYNCING" | "STALE" | "ERROR")
/**
 *  (catalogue : campaign_definitions.status)
 */
export type CampaignDefinitionsStatus = ("DRAFT" | "APPROVED" | "RUNNING" | "COMPLETED" | "CANCELLED" | "FAILED")
/**
 *  (catalogue : campaign_recipients.status)
 */
export type CampaignRecipientsStatus = ("PENDING" | "ELIGIBLE" | "EXCLUDED" | "SUBMITTED" | "DELIVERED" | "FAILED" | "CANCELLED")
/**
 *  (catalogue : cash_movements.kind)
 */
export type CashMovementsKind = ("DEPOSIT" | "WITHDRAWAL" | "EXPENSE" | "OPENING_CORRECTION")
/**
 *  (catalogue : catalog_items.stock_mode)
 */
export type CatalogItemsStockMode = ("NONE" | "STOCKED" | "MADE_TO_ORDER")
/**
 *  (catalogue : catalog_publications.status)
 */
export type CatalogPublicationsStatus = ("DRAFT" | "PUBLISHED" | "ARCHIVED")
/**
 *  (catalogue : communication_consents.channel)
 */
export type CommunicationConsentsChannel = ("SMS" | "WHATSAPP" | "CALL" | "EMAIL")
/**
 *  (catalogue : communication_consents.purpose)
 */
export type CommunicationConsentsPurpose = ("APPOINTMENT" | "FOLLOW_UP" | "ORDER_UPDATE" | "PAYMENT_FOLLOW_UP" | "MARKETING")
/**
 *  (catalogue : communication_consents.status)
 */
export type CommunicationConsentsStatus = ("GRANTED" | "REVOKED")
/**
 *  (catalogue : consultation_history_terms.term_group)
 */
export type ConsultationHistoryTermsTermGroup = ("OCULAR_HISTORY" | "MEDICAL_HISTORY" | "FAMILY_HISTORY")
/**
 *  (catalogue : consultations.status)
 */
export type ConsultationsStatus = ("DRAFT" | "IN_PROGRESS" | "COMPLETED")
/**
 *  (catalogue : contact_attempts.result)
 */
export type ContactAttemptsResult = ("CONTACTED" | "BOOKED" | "NO_RESPONSE" | "REFUSED" | "POSTPONED" | "FAILED")
/**
 *  (catalogue : contact_tasks.channel)
 */
export type ContactTasksChannel = ("INTERNAL" | "CALL" | "SMS" | "WHATSAPP")
/**
 *  (catalogue : contact_tasks.status)
 */
export type ContactTasksStatus = ("PENDING" | "CONTACTED" | "BOOKED" | "NO_RESPONSE" | "REFUSED" | "POSTPONED" | "CANCELLED")
/**
 *  (catalogue : conversation_handoffs.event_type)
 */
export type ConversationHandoffsEventType = ("REQUEST" | "TAKEOVER" | "RESUME_BOT" | "CLOSE")
/**
 *  (catalogue : credit_notes.status)
 */
export type CreditNotesStatus = ("DRAFT" | "ISSUED" | "ABANDONED")
/**
 *  (catalogue : crm_conversations.channel)
 */
export type CrmConversationsChannel = ("WHATSAPP" | "MESSENGER" | "INSTAGRAM")
/**
 *  (catalogue : crm_conversations.status)
 */
export type CrmConversationsStatus = ("BOT_ACTIVE" | "HUMAN_TAKEOVER" | "CLOSED")
/**
 *  (catalogue : crm_leads.status)
 */
export type CrmLeadsStatus = ("OPEN" | "APPOINTMENT_BOOKED" | "HUMAN_TAKEOVER" | "WON" | "LOST" | "CLOSED")
/**
 *  (catalogue : deliveries.status)
 */
export type DeliveriesStatus = ("DRAFT" | "CONFIRMED")
/**
 *  (catalogue : device_stock_allocations.status)
 */
export type DeviceStockAllocationsStatus = ("ACTIVE" | "CLOSING" | "CLOSED")
/**
 *  (catalogue : document_number_allocations.document_type)
 */
export type DocumentNumberAllocationsDocumentType = ("PATIENT" | "INVOICE" | "RECEIPT" | "CREDIT_NOTE" | "REFUND" | "LEGACY_BALANCE" | "QUOTE" | "WORKSHOP")
/**
 *  (catalogue : document_number_allocations.status)
 */
export type DocumentNumberAllocationsStatus = ("ACTIVE" | "CLOSING" | "CLOSED")
/**
 *  (catalogue : document_number_usages.document_type)
 */
export type DocumentNumberUsagesDocumentType = ("PATIENT" | "INVOICE" | "RECEIPT" | "CREDIT_NOTE" | "REFUND" | "LEGACY_BALANCE" | "QUOTE" | "WORKSHOP")
/**
 *  (catalogue : document_number_usages.status)
 */
export type DocumentNumberUsagesStatus = ("USED" | "VOID" | "UNUSED")
/**
 *  (catalogue : document_sequences.document_type)
 */
export type DocumentSequencesDocumentType = ("PATIENT" | "INVOICE" | "RECEIPT" | "CREDIT_NOTE" | "REFUND" | "LEGACY_BALANCE" | "QUOTE" | "WORKSHOP")
/**
 *  (catalogue : enrolled_devices.status)
 */
export type EnrolledDevicesStatus = ("ACTIVE" | "REVOKED" | "LOST" | "REPLACED")
/**
 *  (catalogue : export_requests.export_type)
 */
export type ExportRequestsExportType = ("PATIENT_RECORD" | "PATIENTS" | "FINANCE" | "STOCK" | "FULL_HANDOVER")
/**
 *  (catalogue : export_requests.status)
 */
export type ExportRequestsStatus = ("REQUESTED" | "PROCESSING" | "READY" | "FAILED" | "EXPIRED")
/**
 *  (catalogue : external_links.status)
 */
export type ExternalLinksStatus = ("PENDING" | "SYNCED" | "CONFLICT" | "FAILED")
/**
 *  (catalogue : follow_up_plans.advance_unit)
 */
export type FollowUpPlansAdvanceUnit = ("DAY" | "MONTH")
/**
 *  (catalogue : follow_up_plans.anchor_kind)
 */
export type FollowUpPlansAnchorKind = ("CONSULTATION" | "TREATMENT_START" | "TREATMENT_END" | "MANUAL")
/**
 *  (catalogue : follow_up_plans.date_origin)
 */
export type FollowUpPlansDateOrigin = ("CALCULATED" | "MANUAL")
/**
 *  (catalogue : follow_up_plans.interval_unit)
 */
export type FollowUpPlansIntervalUnit = ("DAY" | "MONTH" | "YEAR")
/**
 *  (catalogue : follow_up_plans.preferred_channel)
 */
export type FollowUpPlansPreferredChannel = ("INTERNAL" | "SMS" | "WHATSAPP" | "CALL")
/**
 *  (catalogue : follow_up_plans.reason)
 */
export type FollowUpPlansReason = ("ROUTINE" | "DROPS" | "OTHER")
/**
 *  (catalogue : follow_up_plans.status)
 */
export type FollowUpPlansStatus = ("ACTIVE" | "SCHEDULED" | "CLOSED" | "CANCELLED")
/**
 *  (catalogue : generated_documents.status)
 */
export type GeneratedDocumentsStatus = ("PENDING" | "READY" | "FAILED")
/**
 *  (catalogue : goods_receipts.status)
 */
export type GoodsReceiptsStatus = ("DRAFT" | "VALIDATED" | "ABANDONED")
/**
 *  (catalogue : idempotency_requests.status)
 */
export type IdempotencyRequestsStatus = ("RECEIVED" | "COMMITTED" | "FAILED")
/**
 *  (catalogue : integration_accounts.provider)
 */
export type IntegrationAccountsProvider = ("GOOGLE" | "KOMMO" | "META_WHATSAPP" | "META_MESSENGER" | "META_INSTAGRAM" | "SMS" | "SMTP")
/**
 *  (catalogue : integration_accounts.status)
 */
export type IntegrationAccountsStatus = ("DISABLED" | "TEST" | "ACTIVE" | "ERROR")
/**
 *  (catalogue : inventory_counts.status)
 */
export type InventoryCountsStatus = ("OPEN" | "COUNTED" | "VALIDATED" | "ABANDONED")
/**
 *  (catalogue : invoice_write_owners.status)
 */
export type InvoiceWriteOwnersStatus = ("ACTIVE" | "CLOSING" | "CLOSED")
/**
 *  (catalogue : invoices.origin)
 */
export type InvoicesOrigin = ("NATIVE" | "LEGACY_BALANCE")
/**
 *  (catalogue : invoices.status)
 */
export type InvoicesStatus = ("DRAFT" | "ISSUED" | "ABANDONED")
/**
 *  (catalogue : knowledge_entries.category)
 */
export type KnowledgeEntriesCategory = ("HOURS" | "LOCATION" | "PRICING" | "INSURANCE" | "SERVICES" | "LENSES" | "PRODUCTS" | "OTHER")
/**
 *  (catalogue : medication_orders.duration_unit)
 */
export type MedicationOrdersDurationUnit = ("DAY" | "WEEK" | "MONTH")
/**
 *  (catalogue : medication_orders.form)
 */
export type MedicationOrdersForm = ("DROPS" | "OINTMENT" | "TABLET" | "OTHER")
/**
 *  (catalogue : message_attempts.status)
 */
export type MessageAttemptsStatus = ("PLANNED" | "SUBMITTED" | "DELIVERED" | "READ" | "FAILED" | "UNKNOWN" | "CANCELLED")
/**
 *  (catalogue : message_template_versions.provider_approval_status)
 */
export type MessageTemplateVersionsProviderApprovalStatus = ("DRAFT" | "PENDING" | "APPROVED" | "REJECTED" | "PAUSED")
/**
 *  (catalogue : message_templates.channel)
 */
export type MessageTemplatesChannel = ("SMS" | "WHATSAPP" | "EMAIL")
/**
 *  (catalogue : message_templates.purpose)
 */
export type MessageTemplatesPurpose = ("APPOINTMENT" | "FOLLOW_UP" | "ORDER_UPDATE" | "PAYMENT_FOLLOW_UP" | "MARKETING")
/**
 *  (catalogue : optical_prescription_eyes.eye)
 */
export type OpticalPrescriptionEyesEye = ("OD" | "OG")
/**
 *  (catalogue : optical_prescriptions.origin)
 */
export type OpticalPrescriptionsOrigin = ("INTERNAL" | "EXTERNAL")
/**
 *  (catalogue : optical_prescriptions.status)
 */
export type OpticalPrescriptionsStatus = ("DRAFT" | "VALIDATED")
/**
 *  (catalogue : outbox_events.status)
 */
export type OutboxEventsStatus = ("PENDING" | "PROCESSING" | "PROCESSED" | "FAILED")
/**
 *  (catalogue : patient_records.sex)
 */
export type PatientRecordsSex = ("F" | "M" | "UNKNOWN")
/**
 *  (catalogue : payment_reconciliation_lines.status)
 */
export type PaymentReconciliationLinesStatus = ("MATCHED" | "UNMATCHED" | "DISPUTED")
/**
 *  (catalogue : payment_reconciliations.method)
 */
export type PaymentReconciliationsMethod = ("CASH" | "MTN_MOMO" | "ORANGE_MONEY" | "BANK_TRANSFER" | "CHEQUE" | "OTHER")
/**
 *  (catalogue : payment_reconciliations.status)
 */
export type PaymentReconciliationsStatus = ("DRAFT" | "VALIDATED")
/**
 *  (catalogue : payments.method)
 */
export type PaymentsMethod = ("CASH" | "MTN_MOMO" | "ORANGE_MONEY" | "BANK_TRANSFER" | "CHEQUE" | "OTHER")
/**
 *  (catalogue : payments.status)
 */
export type PaymentsStatus = ("PENDING" | "CONFIRMED" | "REJECTED")
/**
 *  (catalogue : physical_returns.condition_code)
 */
export type PhysicalReturnsConditionCode = ("SELLABLE" | "DAMAGED" | "QUARANTINE" | "OTHER")
/**
 *  (catalogue : prospect_contacts.origin)
 */
export type ProspectContactsOrigin = ("CRM" | "WEBSITE" | "WHATSAPP" | "MESSENGER" | "INSTAGRAM" | "MANUAL")
/**
 *  (catalogue : public_booking_requests.status)
 */
export type PublicBookingRequestsStatus = ("RECEIVED" | "PENDING" | "CONFIRMED" | "CONFLICT" | "CANCELLED" | "REJECTED")
/**
 *  (catalogue : public_contents.status)
 */
export type PublicContentsStatus = ("DRAFT" | "PUBLISHED" | "ARCHIVED")
/**
 *  (catalogue : quotes.status)
 */
export type QuotesStatus = ("DRAFT" | "ISSUED" | "ACCEPTED" | "DECLINED" | "EXPIRED" | "CONVERTED")
/**
 *  (catalogue : refunds.method)
 */
export type RefundsMethod = ("CASH" | "MTN_MOMO" | "ORANGE_MONEY" | "BANK_TRANSFER" | "CHEQUE" | "OTHER")
/**
 *  (catalogue : reminder_policies.channel)
 */
export type ReminderPoliciesChannel = ("INTERNAL" | "CALL" | "SMS" | "WHATSAPP")
/**
 *  (catalogue : reminder_policies.offset_unit)
 */
export type ReminderPoliciesOffsetUnit = ("HOUR" | "DAY" | "MONTH")
/**
 *  (catalogue : reminder_policies.purpose)
 */
export type ReminderPoliciesPurpose = ("APPOINTMENT" | "FOLLOW_UP" | "ORDER_UPDATE" | "PAYMENT_FOLLOW_UP")
/**
 *  (catalogue : restore_tests.status)
 */
export type RestoreTestsStatus = ("RUNNING" | "PASSED" | "FAILED")
/**
 *  (catalogue : stock_alerts.kind)
 */
export type StockAlertsKind = ("LOW_STOCK" | "EXPIRY")
/**
 *  (catalogue : stock_alerts.status)
 */
export type StockAlertsStatus = ("OPEN" | "ACKNOWLEDGED" | "RESOLVED")
/**
 *  (catalogue : stock_lots.status)
 */
export type StockLotsStatus = ("SELLABLE" | "QUARANTINE")
/**
 *  (catalogue : stock_movements.kind)
 */
export type StockMovementsKind = ("OPENING" | "RECEIPT" | "SALE_DELIVERY" | "PATIENT_RETURN" | "BREAKAGE" | "SUPPLIER_RETURN" | "ADJUSTMENT")
/**
 *  (catalogue : stock_reservations.status)
 */
export type StockReservationsStatus = ("ACTIVE" | "CONSUMED" | "RELEASED")
/**
 *  (catalogue : stored_files.classification)
 */
export type StoredFilesClassification = ("MEDICAL" | "FINANCIAL" | "INTERNAL" | "PUBLIC_APPROVED")
/**
 *  (catalogue : sync_change_log.change_kind)
 */
export type SyncChangeLogChangeKind = ("CREATED" | "UPDATED" | "ARCHIVED")
/**
 *  (catalogue : sync_change_log.visibility)
 */
export type SyncChangeLogVisibility = ("ADMINISTRATIVE" | "CLINICAL" | "FINANCIAL" | "MANAGER" | "PUBLIC")
/**
 *  (catalogue : sync_command_results.status)
 */
export type SyncCommandResultsStatus = ("RECEIVED" | "ACCEPTED" | "WAITING_DEPENDENCY" | "CONFLICT_REQUIRES_REVIEW" | "REJECTED")
/**
 *  (catalogue : sync_conflicts.status)
 */
export type SyncConflictsStatus = ("OPEN" | "RESOLVED")
/**
 *  (catalogue : visits.reason)
 */
export type VisitsReason = ("EXAM" | "DIRECT_PURCHASE" | "PICKUP" | "FOLLOW_UP")
/**
 *  (catalogue : visits.status)
 */
export type VisitsStatus = ("ARRIVED" | "AWAITING_EXAM_FEE" | "READY_FOR_EXAM" | "IN_CONSULTATION" | "COMPLETED" | "CANCELLED")
/**
 *  (catalogue : webhook_inbox.status)
 */
export type WebhookInboxStatus = ("RECEIVED" | "VERIFIED" | "PROCESSED" | "REJECTED" | "FAILED")
/**
 *  (catalogue : workshop_events.event_type)
 */
export type WorkshopEventsEventType = ("STATUS_CHANGE" | "REWORK" | "QUALITY_CHECK" | "ADJUSTMENT" | "CANCEL")
/**
 *  (catalogue : workshop_events.quality_result)
 */
export type WorkshopEventsQualityResult = ("PASSED" | "FAILED" | "NOT_APPLICABLE")
/**
 *  (catalogue : workshop_order_eyes.eye)
 */
export type WorkshopOrderEyesEye = ("OD" | "OG")
/**
 *  (catalogue : workshop_orders.status)
 */
export type WorkshopOrdersStatus = ("ORDERED" | "RECEIVED" | "MOUNTING" | "QUALITY_CHECK" | "READY" | "DELIVERED" | "CANCELLED")
/**
 * Codes du fichier 08 et compléments documentés (F05). Un nouveau code s'ajoute ici avec sa route.
 */
export type ErrorCode = ("AUTH_REQUIRED" | "SESSION_CONTEXT_EXPIRED" | "SESSION_REQUIRED" | "ACTION_FORBIDDEN" | "RESOURCE_NOT_FOUND" | "METHOD_NOT_ALLOWED" | "VERSION_CONFLICT" | "PAYMENT_EXCEEDS_BALANCE" | "STOCK_UNAVAILABLE" | "IDEMPOTENCY_PAYLOAD_MISMATCH" | "SLOT_UNAVAILABLE" | "VALIDATION_FAILED" | "EXAM_PAYMENT_REQUIRED" | "APPROVAL_REQUIRED_OR_EXPIRED" | "REAUTHENTICATION_REQUIRED" | "LOT_EXPIRED" | "RATE_LIMITED" | "DEPENDENCY_UNAVAILABLE" | "CLINIC_NOT_CONFIGURED" | "CLINIC_ALREADY_SET_UP" | "LAST_MANAGER_REQUIRED" | "MANAGER_RESET_REQUIRES_CONSOLE" | "SELF_DEACTIVATION_FORBIDDEN" | "SELF_OVERRIDE_FORBIDDEN" | "SELF_RESET_FORBIDDEN" | "SELF_ROLE_CHANGE_FORBIDDEN" | "WRITE_OWNER_REQUIRED" | "NUMBER_RANGE_EXHAUSTED" | "GRANT_EXPIRED_OR_REVOKED" | "AUTHORIZATION_REVIEW_REQUIRED" | "SERVER_BUSY" | "HTTP_ERROR" | "INTERNAL_ERROR")
export type VectorCase = (({
expected: unknown
} | {
error: unknown
}) & {
id: string
operation: string
description?: string
/**
 * Scénarios de recette (fichier 14), exigences (fichier 17) ou tests d'intégration (fichier 25) illustrés par ce cas.
 * 
 * @minItems 1
 */
covers?: [string, ...(string)[]]
input: {

}
expected?: unknown
error?: VectorRefusal
})
/**
 * Commandes disponibles dans le journal local (actions du fichier 08 jouables hors-ligne).
 */
export type CommandName = ("CreatePatient" | "UpdatePatient" | "RegisterVisit" | "StartConsultation" | "SaveClinicalStep" | "CompleteConsultation" | "AmendConsultation" | "CreatePrescription" | "ValidatePrescription" | "CreateInvoiceDraft" | "UpdateDraft" | "IssueInvoice" | "RegisterPayment" | "ReversePayment" | "IssueCreditNote" | "RefundCredit" | "OpenCashSession" | "CloseCashSession" | "ConfirmDelivery" | "RegisterPhysicalReturn" | "RegisterStockMovement" | "CreateFollowUp" | "CreateAppointment" | "RecordContactAttempt" | "RequestApproval" | "DecideApproval")
/**
 * Événements de la version 1 (fichier 08).
 */
export type EventType = ("PatientCreated" | "ConsultationCompleted" | "PrescriptionValidated" | "FollowUpScheduled" | "AppointmentConfirmed" | "AppointmentRescheduled" | "AppointmentCancelled" | "InvoiceIssued" | "PaymentConfirmed" | "CreditNoteIssued" | "DeliveryConfirmed" | "StockThresholdCrossed" | "WorkshopReady")

export interface ContractTypes {
SchemasClinicalStepsJsonAnamnesis?: Anamnesis
SchemasClinicalStepsJsonHistories?: Histories
SchemasClinicalStepsJsonVisualAcuity?: VisualAcuity
SchemasClinicalStepsJsonRefraction?: Refraction
SchemasClinicalStepsJsonCentering?: Centering
SchemasClinicalStepsJsonConclusion?: Conclusion
SchemasClinicalStepsJsonStepName?: StepName
SchemasClinicalStepsJsonAllSteps?: AllSteps
SchemasCommonJsonUlid?: Ulid
SchemasCommonJsonNullableUlid?: NullableUlid
SchemasCommonJsonCode?: Code
SchemasCommonJsonPermissionCode?: PermissionCode
SchemasCommonJsonSha256Hex?: Sha256Hex
SchemasCommonJsonNullableSha256Hex?: NullableSha256Hex
SchemasCommonJsonCompactJws?: CompactJws
SchemasCommonJsonMoneyXaf?: MoneyXaf
SchemasCommonJsonPositiveMoneyXaf?: PositiveMoneyXaf
SchemasCommonJsonSignedMoneyXaf?: SignedMoneyXaf
SchemasCommonJsonCurrency?: Currency
SchemasCommonJsonQuantity?: Quantity
SchemasCommonJsonSignedQuantity?: SignedQuantity
SchemasCommonJsonDiopter?: Diopter
SchemasCommonJsonNullableDiopter?: NullableDiopter
SchemasCommonJsonRate?: Rate
SchemasCommonJsonCounter?: Counter
SchemasCommonJsonVersion?: Version
SchemasCommonJsonInstant?: Instant
SchemasCommonJsonNullableInstant?: NullableInstant
SchemasCommonJsonCivilDate?: CivilDate
SchemasCommonJsonNullableCivilDate?: NullableCivilDate
SchemasCommonJsonShortText?: ShortText
SchemasCommonJsonReason?: Reason
SchemasCommonJsonPhone?: Phone
SchemasCommonJsonPagination?: Pagination
CompatibilityDeclaration?: CompatibilityDeclaration
SchemasEnumsJsonAppointmentsSource?: AppointmentsSource
SchemasEnumsJsonAppointmentsStatus?: AppointmentsStatus
SchemasEnumsJsonApprovalsStatus?: ApprovalsStatus
SchemasEnumsJsonAssistantRunsStatus?: AssistantRunsStatus
SchemasEnumsJsonBackupRunsScope?: BackupRunsScope
SchemasEnumsJsonBackupRunsStatus?: BackupRunsStatus
SchemasEnumsJsonCalendarBlocksOrigin?: CalendarBlocksOrigin
SchemasEnumsJsonCalendarSyncStatesStatus?: CalendarSyncStatesStatus
SchemasEnumsJsonCampaignDefinitionsStatus?: CampaignDefinitionsStatus
SchemasEnumsJsonCampaignRecipientsStatus?: CampaignRecipientsStatus
SchemasEnumsJsonCashMovementsKind?: CashMovementsKind
SchemasEnumsJsonCatalogItemsStockMode?: CatalogItemsStockMode
SchemasEnumsJsonCatalogPublicationsStatus?: CatalogPublicationsStatus
SchemasEnumsJsonCommunicationConsentsChannel?: CommunicationConsentsChannel
SchemasEnumsJsonCommunicationConsentsPurpose?: CommunicationConsentsPurpose
SchemasEnumsJsonCommunicationConsentsStatus?: CommunicationConsentsStatus
SchemasEnumsJsonConsultationHistoriesHistoryGroup?: ConsultationHistoriesHistoryGroup
SchemasEnumsJsonConsultationHistoryTermsTermGroup?: ConsultationHistoryTermsTermGroup
SchemasEnumsJsonConsultationsReferralUrgency?: ConsultationsReferralUrgency
SchemasEnumsJsonConsultationsStatus?: ConsultationsStatus
SchemasEnumsJsonContactAttemptsResult?: ContactAttemptsResult
SchemasEnumsJsonContactTasksChannel?: ContactTasksChannel
SchemasEnumsJsonContactTasksStatus?: ContactTasksStatus
SchemasEnumsJsonConversationHandoffsEventType?: ConversationHandoffsEventType
SchemasEnumsJsonCreditNotesStatus?: CreditNotesStatus
SchemasEnumsJsonCrmConversationsChannel?: CrmConversationsChannel
SchemasEnumsJsonCrmConversationsStatus?: CrmConversationsStatus
SchemasEnumsJsonCrmLeadsStatus?: CrmLeadsStatus
SchemasEnumsJsonDeliveriesStatus?: DeliveriesStatus
SchemasEnumsJsonDeviceStockAllocationsStatus?: DeviceStockAllocationsStatus
SchemasEnumsJsonDocumentNumberAllocationsDocumentType?: DocumentNumberAllocationsDocumentType
SchemasEnumsJsonDocumentNumberAllocationsStatus?: DocumentNumberAllocationsStatus
SchemasEnumsJsonDocumentNumberUsagesDocumentType?: DocumentNumberUsagesDocumentType
SchemasEnumsJsonDocumentNumberUsagesStatus?: DocumentNumberUsagesStatus
SchemasEnumsJsonDocumentSequencesDocumentType?: DocumentSequencesDocumentType
SchemasEnumsJsonEnrolledDevicesStatus?: EnrolledDevicesStatus
SchemasEnumsJsonExportRequestsExportType?: ExportRequestsExportType
SchemasEnumsJsonExportRequestsStatus?: ExportRequestsStatus
SchemasEnumsJsonExternalLinksStatus?: ExternalLinksStatus
SchemasEnumsJsonFollowUpPlansAdvanceUnit?: FollowUpPlansAdvanceUnit
SchemasEnumsJsonFollowUpPlansAnchorKind?: FollowUpPlansAnchorKind
SchemasEnumsJsonFollowUpPlansDateOrigin?: FollowUpPlansDateOrigin
SchemasEnumsJsonFollowUpPlansIntervalUnit?: FollowUpPlansIntervalUnit
SchemasEnumsJsonFollowUpPlansPreferredChannel?: FollowUpPlansPreferredChannel
SchemasEnumsJsonFollowUpPlansReason?: FollowUpPlansReason
SchemasEnumsJsonFollowUpPlansStatus?: FollowUpPlansStatus
SchemasEnumsJsonGeneratedDocumentsDocumentType?: GeneratedDocumentsDocumentType
SchemasEnumsJsonGeneratedDocumentsStatus?: GeneratedDocumentsStatus
SchemasEnumsJsonGoodsReceiptsStatus?: GoodsReceiptsStatus
SchemasEnumsJsonIdempotencyRequestsStatus?: IdempotencyRequestsStatus
SchemasEnumsJsonIntegrationAccountsProvider?: IntegrationAccountsProvider
SchemasEnumsJsonIntegrationAccountsStatus?: IntegrationAccountsStatus
SchemasEnumsJsonInventoryCountsStatus?: InventoryCountsStatus
SchemasEnumsJsonInvoiceWriteOwnersStatus?: InvoiceWriteOwnersStatus
SchemasEnumsJsonInvoicesOrigin?: InvoicesOrigin
SchemasEnumsJsonInvoicesStatus?: InvoicesStatus
SchemasEnumsJsonKnowledgeEntriesCategory?: KnowledgeEntriesCategory
SchemasEnumsJsonMedicationOrdersDurationUnit?: MedicationOrdersDurationUnit
SchemasEnumsJsonMedicationOrdersForm?: MedicationOrdersForm
SchemasEnumsJsonMessageAttemptsStatus?: MessageAttemptsStatus
SchemasEnumsJsonMessageTemplateVersionsProviderApprovalStatus?: MessageTemplateVersionsProviderApprovalStatus
SchemasEnumsJsonMessageTemplatesChannel?: MessageTemplatesChannel
SchemasEnumsJsonMessageTemplatesPurpose?: MessageTemplatesPurpose
SchemasEnumsJsonOpticalPrescriptionEyesEye?: OpticalPrescriptionEyesEye
SchemasEnumsJsonOpticalPrescriptionsOrigin?: OpticalPrescriptionsOrigin
SchemasEnumsJsonOpticalPrescriptionsStatus?: OpticalPrescriptionsStatus
SchemasEnumsJsonOutboxEventsStatus?: OutboxEventsStatus
SchemasEnumsJsonPatientRecordsSex?: PatientRecordsSex
SchemasEnumsJsonPaymentReconciliationLinesStatus?: PaymentReconciliationLinesStatus
SchemasEnumsJsonPaymentReconciliationsMethod?: PaymentReconciliationsMethod
SchemasEnumsJsonPaymentReconciliationsStatus?: PaymentReconciliationsStatus
SchemasEnumsJsonPaymentsMethod?: PaymentsMethod
SchemasEnumsJsonPaymentsStatus?: PaymentsStatus
SchemasEnumsJsonPhysicalReturnsConditionCode?: PhysicalReturnsConditionCode
SchemasEnumsJsonProspectContactsOrigin?: ProspectContactsOrigin
SchemasEnumsJsonPublicBookingRequestsStatus?: PublicBookingRequestsStatus
SchemasEnumsJsonPublicContentsStatus?: PublicContentsStatus
SchemasEnumsJsonQuotesStatus?: QuotesStatus
SchemasEnumsJsonRefractionMeasurementsEye?: RefractionMeasurementsEye
SchemasEnumsJsonRefractionMeasurementsStage?: RefractionMeasurementsStage
SchemasEnumsJsonRefundsMethod?: RefundsMethod
SchemasEnumsJsonReminderPoliciesChannel?: ReminderPoliciesChannel
SchemasEnumsJsonReminderPoliciesOffsetUnit?: ReminderPoliciesOffsetUnit
SchemasEnumsJsonReminderPoliciesPurpose?: ReminderPoliciesPurpose
SchemasEnumsJsonRestoreTestsStatus?: RestoreTestsStatus
SchemasEnumsJsonStockAlertsKind?: StockAlertsKind
SchemasEnumsJsonStockAlertsStatus?: StockAlertsStatus
SchemasEnumsJsonStockLotsStatus?: StockLotsStatus
SchemasEnumsJsonStockMovementsKind?: StockMovementsKind
SchemasEnumsJsonStockReservationsStatus?: StockReservationsStatus
SchemasEnumsJsonStoredFilesClassification?: StoredFilesClassification
SchemasEnumsJsonSyncChangeLogChangeKind?: SyncChangeLogChangeKind
SchemasEnumsJsonSyncChangeLogVisibility?: SyncChangeLogVisibility
SchemasEnumsJsonSyncCommandResultsStatus?: SyncCommandResultsStatus
SchemasEnumsJsonSyncConflictsStatus?: SyncConflictsStatus
SchemasEnumsJsonVisitsReason?: VisitsReason
SchemasEnumsJsonVisitsStatus?: VisitsStatus
SchemasEnumsJsonVisualAcuitiesContext?: VisualAcuitiesContext
SchemasEnumsJsonVisualAcuitiesCorrection?: VisualAcuitiesCorrection
SchemasEnumsJsonVisualAcuitiesDistance?: VisualAcuitiesDistance
SchemasEnumsJsonVisualAcuitiesEye?: VisualAcuitiesEye
SchemasEnumsJsonWebhookInboxStatus?: WebhookInboxStatus
SchemasEnumsJsonWorkshopEventsEventType?: WorkshopEventsEventType
SchemasEnumsJsonWorkshopEventsQualityResult?: WorkshopEventsQualityResult
SchemasEnumsJsonWorkshopOrderEyesEye?: WorkshopOrderEyesEye
SchemasEnumsJsonWorkshopOrdersStatus?: WorkshopOrdersStatus
ErrorEnvelope?: ErrorEnvelope
SchemasErrorJsonErrorCode?: ErrorCode
SchemasRequestsHttpJsonLoginRequest?: LoginRequest
SchemasRequestsHttpJsonReauthenticateRequest?: ReauthenticateRequest
SchemasRequestsHttpJsonChangePasswordRequest?: ChangePasswordRequest
SchemasRequestsHttpJsonCreateStaffRequest?: CreateStaffRequest
SchemasRequestsHttpJsonUpdateStaffRequest?: UpdateStaffRequest
SchemasRequestsHttpJsonPermissionOverrideRequest?: PermissionOverrideRequest
SchemasRequestsHttpJsonResetPasswordRequest?: ResetPasswordRequest
SchemasRequestsHttpJsonUpdateClinicSettingsRequest?: UpdateClinicSettingsRequest
SchemasRequestsHttpJsonUpdatePrescriptionDraftRequest?: UpdatePrescriptionDraftRequest
SchemasRequestsHttpJsonRescheduleAppointmentRequest?: RescheduleAppointmentRequest
SchemasRequestsHttpJsonCancelAppointmentRequest?: CancelAppointmentRequest
SchemasRequestsHttpJsonCatalogItemRequest?: CatalogItemRequest
SchemasRequestsHttpJsonTaxRuleRequest?: TaxRuleRequest
SchemasRequestsHttpJsonOpeningStockRequest?: OpeningStockRequest
SchemasRequestsHttpJsonEnrollDeviceRequest?: EnrollDeviceRequest
SchemasRequestsHttpJsonPrepareDeviceRequest?: PrepareDeviceRequest
SchemasRequestsHttpJsonReleaseDeviceRequest?: ReleaseDeviceRequest
SchemasRequestsHttpJsonConflictResolutionRequest?: ConflictResolutionRequest
SchemasRequestsHttpJsonCreateExportRequest?: CreateExportRequest
SchemasRequestsHttpJsonBookingRequest?: BookingRequest
SchemasResourcesAccessJsonStaffMemberSummary?: StaffMemberSummary
SchemasResourcesAccessJsonStaffMember?: StaffMember
SchemasResourcesAccessJsonClinicSummary?: ClinicSummary
SchemasResourcesAccessJsonMe?: Me
SchemasResourcesAccessJsonClinicSettings?: ClinicSettings
SchemasResourcesAccessJsonReauthentication?: Reauthentication
SchemasResourcesAccessJsonStaffMemberResponse?: StaffMemberResponse
SchemasResourcesAccessJsonStaffMemberPage?: StaffMemberPage
SchemasResourcesAccessJsonMeResponse?: MeResponse
SchemasResourcesAccessJsonClinicSettingsResponse?: ClinicSettingsResponse
SchemasResourcesAccessJsonReauthenticationResponse?: ReauthenticationResponse
SchemasResourcesBillingJsonCatalogItem?: CatalogItem
SchemasResourcesBillingJsonCatalogPage?: CatalogPage
SchemasResourcesBillingJsonCatalogItemResponse?: CatalogItemResponse
SchemasResourcesBillingJsonTaxRule?: TaxRule
SchemasResourcesBillingJsonTaxRuleResponse?: TaxRuleResponse
SchemasResourcesBillingJsonTaxRuleList?: TaxRuleList
SchemasResourcesBillingJsonImportReport?: ImportReport
SchemasResourcesBillingJsonImportReportResponse?: ImportReportResponse
SchemasResourcesBillingJsonInvoiceLine?: InvoiceLine
SchemasResourcesBillingJsonInvoiceBalance?: InvoiceBalance
SchemasResourcesBillingJsonInvoicePayment?: InvoicePayment
SchemasResourcesBillingJsonDocumentState?: DocumentState
SchemasResourcesBillingJsonInvoice?: Invoice
SchemasResourcesBillingJsonInvoiceSummary?: InvoiceSummary
SchemasResourcesBillingJsonInvoiceResponse?: InvoiceResponse
SchemasResourcesBillingJsonInvoicePage?: InvoicePage
SchemasResourcesBillingJsonPaymentResult?: PaymentResult
SchemasResourcesBillingJsonReversalResult?: ReversalResult
SchemasResourcesBillingJsonCreditNoteResult?: CreditNoteResult
SchemasResourcesBillingJsonRefundResult?: RefundResult
SchemasResourcesBillingJsonPaymentResultResponse?: PaymentResultResponse
SchemasResourcesBillingJsonReversalResultResponse?: ReversalResultResponse
SchemasResourcesBillingJsonCreditNoteResultResponse?: CreditNoteResultResponse
SchemasResourcesBillingJsonRefundResultResponse?: RefundResultResponse
SchemasResourcesBillingJsonApproval?: Approval
SchemasResourcesBillingJsonApprovalResponse?: ApprovalResponse
SchemasResourcesBillingJsonCashSession?: CashSession
SchemasResourcesBillingJsonCashSessionResponse?: CashSessionResponse
SchemasResourcesBillingJsonCurrentCashSessionResponse?: CurrentCashSessionResponse
SchemasResourcesBillingJsonDelivery?: Delivery
SchemasResourcesBillingJsonDeliveryResponse?: DeliveryResponse
SchemasResourcesClinicalJsonClinicalRevision?: ClinicalRevision
SchemasResourcesClinicalJsonConsultation?: Consultation
SchemasResourcesClinicalJsonPrescription?: Prescription
SchemasResourcesClinicalJsonConsultationResponse?: ConsultationResponse
SchemasResourcesClinicalJsonPrescriptionResponse?: PrescriptionResponse
SchemasResourcesOperationsJsonPublicJwk?: PublicJwk
SchemasResourcesOperationsJsonEnrolledDevice?: EnrolledDevice
SchemasResourcesOperationsJsonEnrolledDeviceResponse?: EnrolledDeviceResponse
SchemasResourcesOperationsJsonOfflineGrant?: OfflineGrant
SchemasResourcesOperationsJsonNumberAllocation?: NumberAllocation
SchemasResourcesOperationsJsonStockQuota?: StockQuota
SchemasResourcesOperationsJsonPreparation?: Preparation
SchemasResourcesOperationsJsonPreparationResponse?: PreparationResponse
SchemasResourcesOperationsJsonRelease?: Release
SchemasResourcesOperationsJsonReleaseResponse?: ReleaseResponse
SchemasResourcesOperationsJsonDeviceHealth?: DeviceHealth
SchemasResourcesOperationsJsonDeviceHealthResponse?: DeviceHealthResponse
SchemasResourcesOperationsJsonConflict?: Conflict
SchemasResourcesOperationsJsonConflictPage?: ConflictPage
SchemasResourcesOperationsJsonConflictResponse?: ConflictResponse
SchemasResourcesOperationsJsonGeneratedDocument?: GeneratedDocument
SchemasResourcesOperationsJsonGeneratedDocumentResponse?: GeneratedDocumentResponse
SchemasResourcesOperationsJsonExportRequest?: ExportRequest
SchemasResourcesOperationsJsonExportRequestResponse?: ExportRequestResponse
SchemasResourcesOperationsJsonFinancialReport?: FinancialReport
SchemasResourcesOperationsJsonFinancialReportResponse?: FinancialReportResponse
SchemasResourcesOperationsJsonAuditEvent?: AuditEvent
SchemasResourcesOperationsJsonAuditEventPage?: AuditEventPage
SchemasResourcesPatientsJsonPatientSummary?: PatientSummary
SchemasResourcesPatientsJsonPatient?: Patient
SchemasResourcesPatientsJsonPatientHistory?: PatientHistory
SchemasResourcesPatientsJsonPatientResponse?: PatientResponse
SchemasResourcesPatientsJsonPatientPage?: PatientPage
SchemasResourcesPatientsJsonPatientHistoryResponse?: PatientHistoryResponse
SchemasResourcesPatientsJsonVisit?: Visit
SchemasResourcesPatientsJsonVisitResponse?: VisitResponse
SchemasResourcesPatientsJsonVisitPage?: VisitPage
SchemasResourcesPublicJsonPublicService?: PublicService
SchemasResourcesPublicJsonPublicServiceList?: PublicServiceList
SchemasResourcesPublicJsonPublicSlot?: PublicSlot
SchemasResourcesPublicJsonPublicSlotList?: PublicSlotList
SchemasResourcesPublicJsonBookingReceipt?: BookingReceipt
SchemasResourcesPublicJsonBookingReceiptResponse?: BookingReceiptResponse
SchemasResourcesSchedulingJsonFollowUpPlan?: FollowUpPlan
SchemasResourcesSchedulingJsonFollowUpPlanResponse?: FollowUpPlanResponse
SchemasResourcesSchedulingJsonAppointment?: Appointment
SchemasResourcesSchedulingJsonAppointmentResponse?: AppointmentResponse
SchemasResourcesSchedulingJsonAppointmentPage?: AppointmentPage
SchemasResourcesSchedulingJsonContactTask?: ContactTask
SchemasResourcesSchedulingJsonContactTaskPage?: ContactTaskPage
SchemasResourcesSchedulingJsonContactAttemptResult?: ContactAttemptResult
SchemasResourcesSchedulingJsonContactAttemptResultResponse?: ContactAttemptResultResponse
SchemasResourcesSchedulingJsonActivityReport?: ActivityReport
SchemasResourcesSchedulingJsonActivityReportResponse?: ActivityReportResponse
SchemasResourcesSchedulingJsonClinicalReport?: ClinicalReport
SchemasResourcesSchedulingJsonClinicalReportResponse?: ClinicalReportResponse
SchemasResourcesStockJsonStockAvailability?: StockAvailability
SchemasResourcesStockJsonStockAvailabilityPage?: StockAvailabilityPage
SchemasResourcesStockJsonStockMovement?: StockMovement
SchemasResourcesStockJsonStockMovementResponse?: StockMovementResponse
SchemasResourcesStockJsonOpeningStockResult?: OpeningStockResult
SchemasResourcesStockJsonOpeningStockResponse?: OpeningStockResponse
SchemasResourcesStockJsonPhysicalReturn?: PhysicalReturn
SchemasResourcesStockJsonPhysicalReturnResponse?: PhysicalReturnResponse
SchemasRuleVectorsJson?: RuleVectors
SchemasRuleVectorsJsonVectorCase?: VectorCase
SchemasRuleVectorsJsonVectorRefusal?: VectorRefusal
Ack?: Ack
CommandCore?: CommandCore
PullPage?: PullPage
SyncPullPageJsonChange?: Change
PushRequest?: PushRequest
PushResponse?: PushResponse
SignedCommand?: SignedCommand
SyncSignedCommandJsonJwsProtectedHeader?: JwsProtectedHeader
SyncSignedCommandJsonJwsPayload?: JwsPayload
AmendConsultationPayload?: AmendConsultationPayload
CommandsAmendConsultationV1JsonDirectRequest?: AmendConsultationDirectRequest
CloseCashSessionPayload?: CloseCashSessionPayload
CommandsCloseCashSessionV1JsonDirectRequest?: CloseCashSessionDirectRequest
CompleteConsultationPayload?: CompleteConsultationPayload
CommandsCompleteConsultationV1JsonDirectRequest?: CompleteConsultationDirectRequest
ConfirmDeliveryPayload?: ConfirmDeliveryPayload
CommandsConfirmDeliveryV1JsonDirectRequest?: ConfirmDeliveryDirectRequest
CreateAppointmentPayload?: CreateAppointmentPayload
CommandsCreateAppointmentV1JsonDirectRequest?: CreateAppointmentDirectRequest
CreateFollowUpPayload?: CreateFollowUpPayload
CommandsCreateFollowUpV1JsonDirectRequest?: CreateFollowUpDirectRequest
CreateInvoiceDraftPayload?: CreateInvoiceDraftPayload
CommandsCreateInvoiceDraftV1JsonDirectRequest?: CreateInvoiceDraftDirectRequest
CreatePatientPayload?: CreatePatientPayload
CommandsCreatePatientV1JsonDirectRequest?: CreatePatientDirectRequest
CreatePrescriptionPayload?: CreatePrescriptionPayload
CommandsCreatePrescriptionV1JsonDirectRequest?: CreatePrescriptionDirectRequest
DecideApprovalPayload?: DecideApprovalPayload
CommandsDecideApprovalV1JsonDirectRequest?: DecideApprovalDirectRequest
IssueCreditNotePayload?: IssueCreditNotePayload
CommandsIssueCreditNoteV1JsonDirectRequest?: IssueCreditNoteDirectRequest
IssueInvoicePayload?: IssueInvoicePayload
CommandsIssueInvoiceV1JsonDirectRequest?: IssueInvoiceDirectRequest
OpenCashSessionPayload?: OpenCashSessionPayload
CommandsOpenCashSessionV1JsonDirectRequest?: OpenCashSessionDirectRequest
RecordContactAttemptPayload?: RecordContactAttemptPayload
CommandsRecordContactAttemptV1JsonDirectRequest?: RecordContactAttemptDirectRequest
RefundCreditPayload?: RefundCreditPayload
CommandsRefundCreditV1JsonDirectRequest?: RefundCreditDirectRequest
RegisterPaymentPayload?: RegisterPaymentPayload
CommandsRegisterPaymentV1JsonDirectRequest?: RegisterPaymentDirectRequest
RegisterPhysicalReturnPayload?: RegisterPhysicalReturnPayload
CommandsRegisterPhysicalReturnV1JsonDirectRequest?: RegisterPhysicalReturnDirectRequest
RegisterStockMovementPayload?: RegisterStockMovementPayload
CommandsRegisterStockMovementV1JsonDirectRequest?: RegisterStockMovementDirectRequest
RegisterVisitPayload?: RegisterVisitPayload
CommandsRegisterVisitV1JsonDirectRequest?: RegisterVisitDirectRequest
RequestApprovalPayload?: RequestApprovalPayload
CommandsRequestApprovalV1JsonDirectRequest?: RequestApprovalDirectRequest
ReversePaymentPayload?: ReversePaymentPayload
CommandsReversePaymentV1JsonDirectRequest?: ReversePaymentDirectRequest
SaveClinicalStepPayload?: SaveClinicalStepPayload
CommandsSaveClinicalStepV1JsonDirectRequest?: SaveClinicalStepDirectRequest
StartConsultationPayload?: StartConsultationPayload
CommandsStartConsultationV1JsonDirectRequest?: StartConsultationDirectRequest
UpdateDraftPayload?: UpdateDraftPayload
CommandsUpdateDraftV1JsonDirectRequest?: UpdateDraftDirectRequest
UpdatePatientPayload?: UpdatePatientPayload
CommandsUpdatePatientV1JsonDirectRequest?: UpdatePatientDirectRequest
ValidatePrescriptionPayload?: ValidatePrescriptionPayload
CommandsValidatePrescriptionV1JsonDirectRequest?: ValidatePrescriptionDirectRequest
CommandsDispatchJsonCommandName?: CommandName
AppointmentCancelledEventPayload?: AppointmentCancelledEventPayload
AppointmentConfirmedEventPayload?: AppointmentConfirmedEventPayload
AppointmentRescheduledEventPayload?: AppointmentRescheduledEventPayload
ConsultationCompletedEventPayload?: ConsultationCompletedEventPayload
CreditNoteIssuedEventPayload?: CreditNoteIssuedEventPayload
DeliveryConfirmedEventPayload?: DeliveryConfirmedEventPayload
FollowUpScheduledEventPayload?: FollowUpScheduledEventPayload
InvoiceIssuedEventPayload?: InvoiceIssuedEventPayload
PatientCreatedEventPayload?: PatientCreatedEventPayload
PaymentConfirmedEventPayload?: PaymentConfirmedEventPayload
PrescriptionValidatedEventPayload?: PrescriptionValidatedEventPayload
StockThresholdCrossedEventPayload?: StockThresholdCrossedEventPayload
WorkshopReadyEventPayload?: WorkshopReadyEventPayload
EventEnvelope?: EventEnvelope
EventsEnvelopeJsonEventType?: EventType
ClinicalRevisionSnapshot?: ClinicalRevisionSnapshot
CreditNoteSnapshot?: CreditNoteSnapshot
DeliveryPatientSnapshot?: DeliveryPatientSnapshot
InvoiceClinicSnapshot?: InvoiceClinicSnapshot
InvoicePatientSnapshot?: InvoicePatientSnapshot
InvoiceTaxSnapshot?: InvoiceTaxSnapshot
ReceiptSnapshot?: ReceiptSnapshot
RefundSnapshot?: RefundSnapshot
}
export interface Anamnesis {
symptoms: (string | null)
wears_glasses: (boolean | null)
glasses_duration: (string | null)
previous_correction_available: (boolean | null)
/**
 * @minItems 0
 * @maxItems 50
 */
complaints: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}
export interface Histories {
/**
 * @minItems 0
 * @maxItems 3
 */
histories: []|[{
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}]|[{
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}, {
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}]|[{
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}, {
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}, {
id: Ulid
history_group: ConsultationHistoriesHistoryGroup
known_none: boolean
comments: (string | null)
/**
 * @minItems 0
 * @maxItems 50
 */
terms: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
}]
}
export interface VisualAcuity {
/**
 * @minItems 0
 * @maxItems 48
 */
acuities: {
id: Ulid
context: VisualAcuitiesContext
distance: VisualAcuitiesDistance
correction: VisualAcuitiesCorrection
eye: VisualAcuitiesEye
scale_id: Ulid
raw_value: string
normalized_value: (string | null)
}[]
}
export interface Refraction {
/**
 * @minItems 0
 * @maxItems 6
 */
measurements: []|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]|[{
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}, {
id: Ulid
stage: RefractionMeasurementsStage
eye: RefractionMeasurementsEye
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
}]
refraction_observations: (string | null)
/**
 * @minItems 0
 * @maxItems 20
 */
ametropias: []|[{
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]
}
export interface Centering {
centering_id: Ulid
pd_total: NullableDiopter
pd_od: NullableDiopter
pd_og: NullableDiopter
inconsistency_reason: (string | null)
}
export interface Conclusion {
conclusion: (string | null)
referral: (boolean | null)
referral_urgency: (ConsultationsReferralUrgency | null)
/**
 * @minItems 0
 * @maxItems 20
 */
referral_reasons: []|[{
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]
}
export interface AllSteps {
ANAMNESIS: Anamnesis
HISTORIES: Histories
VISUAL_ACUITY: VisualAcuity
REFRACTION: Refraction
CENTERING: Centering
CONCLUSION: Conclusion
}
export interface Pagination {
current_page: number
last_page: number
per_page: (25 | 50)
total: number
}
export interface CompatibilityDeclaration {
/**
 * Version de l'application (identique à info.version du contrat OpenAPI).
 */
release: string
sync_protocol_version: number
catalog_sha256: Sha256Hex
/**
 * Versions de règles métier dont les commandes locales sont acceptées (format du cœur signé).
 * 
 * @minItems 1
 */
rules_versions: [string, ...(string)[]]
/**
 * Pour chaque commande, versions de schéma de charge reprises (schema_version du cœur).
 */
commands: {
/**
 * @minItems 1
 */
[k: string]: [string, ...(string)[]] | undefined
}
/**
 * Pour chaque événement interne, versions produites et consommées.
 */
events: {
/**
 * @minItems 1
 */
[k: string]: [number, ...(number)[]] | undefined
}
/**
 * Versions du format du coffre local reprises (définies par O01, migrations par O06).
 */
vault_formats: number[]
/**
 * Modèles de documents disponibles pour régénérer une pièce en attente (définis par D01/D02).
 */
document_templates: {
document_type: GeneratedDocumentsDocumentType
template_version: string
}[]
notes: string
}
export interface ErrorEnvelope {
error: {
code: ErrorCode
message: string
fields?: {
/**
 * @minItems 1
 */
[k: string]: [string, ...(string)[]] | undefined
}
}
correlation_id: string
}
export interface LoginRequest {
login: string
password: string
}
export interface ReauthenticateRequest {
password: string
}
export interface ChangePasswordRequest {
current_password: string
password: string
password_confirmation: string
}
export interface CreateStaffRequest {
login: string
display_name: string
email?: (string | null)
password: string
/**
 * @minItems 1
 * @maxItems 4
 */
roles: [("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]
}
export interface UpdateStaffRequest {
expected_version: Version
display_name?: string
email?: (string | null)
active?: boolean
/**
 * @minItems 1
 * @maxItems 4
 */
roles?: [("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]
reason?: (string | null)
}
export interface PermissionOverrideRequest {
expected_version: Version
permission_code: PermissionCode
effect: ("ALLOW" | "DENY" | "CLEAR")
reason: string
expires_at?: NullableInstant
}
export interface ResetPasswordRequest {
expected_version: Version
password: string
password_confirmation: string
reason: string
}
/**
 * Fuseau, monnaie et production_documents_enabled sont refusés (422).
 */
export interface UpdateClinicSettingsRequest {
name?: string
address?: (string | null)
phone?: (string | null)
email?: (string | null)
tax_identifier?: (string | null)
professional_mentions?: (string | null)
document_settings?: ({

} | null)
}
/**
 * Modification d'une prescription encore DRAFT, en ligne seulement ; jeton de concurrence updated_at. Hors-ligne, une correction est une nouvelle version (CreatePrescription avec supersedes_id).
 */
export interface UpdatePrescriptionDraftRequest {
expected_updated_at: Instant
changes: {

}
}
export interface RescheduleAppointmentRequest {
expected_version: Version
starts_at: Instant
ends_at: Instant
resource_id?: Ulid
}
export interface CancelAppointmentRequest {
expected_version: Version
reason: string
}
/**
 * Création (expected_version null) ou modification d'un article par la gérante (catalog.manage). Les prix réels sont fournis par le cabinet : seules des données fictives sont utilisées en développement.
 */
export interface CatalogItemRequest {
expected_version: (Version | null)
reference: string
category_code: Code
name: string
brand: (string | null)
model: (string | null)
unit_code: Code
quantity_integer_only: boolean
sale_price: MoneyXaf
purchase_cost: (MoneyXaf | null)
tax_rule_id: NullableUlid
stock_mode: CatalogItemsStockMode
requires_expiry: boolean
low_stock_threshold: (Quantity | null)
expiry_alert_days: (number | null)
active: boolean
public_visibility: boolean
}
/**
 * Règle fiscale saisie par la gérante ; validate = true enregistre la validation (réauthentification). Aucune taxe n'est préconfigurée.
 */
export interface TaxRuleRequest {
expected_version: (Version | null)
code: string
label: string
rate_percent: (Rate | null)
active: boolean
validate: boolean
legal_note: (string | null)
}
/**
 * Stock initial validé (I00) : un mouvement OPENING par ligne, lots créés au besoin ; gérante.
 */
export interface OpeningStockRequest {
counted_at: Instant
reason: string
approval_id: NullableUlid
/**
 * @minItems 1
 * @maxItems 500
 */
lines: [{
movement_id: Ulid
item_id: Ulid
location_id: Ulid
lot: ({
lot_id: Ulid
batch_number: string
expires_on: NullableCivilDate
} | null)
quantity: Quantity
unit_cost: (MoneyXaf | null)
}, ...({
movement_id: Ulid
item_id: Ulid
location_id: Ulid
lot: ({
lot_id: Ulid
batch_number: string
expires_on: NullableCivilDate
} | null)
quantity: Quantity
unit_cost: (MoneyXaf | null)
})[]]
}
export interface EnrollDeviceRequest {
device_code: string
name: string
public_key_jwk: PublicJwk
responsible_user_id: Ulid
app_version: string
}
/**
 * Clé publique ES256 du poste (JWK, jamais de composante privée « d »).
 */
export interface PublicJwk {
kty: "EC"
crv: "P-256"
x: string
y: string
}
/**
 * Préparation en ligne (gérante) : profils à préparer, plages demandées, quotas et créances à attribuer à ce poste.
 */
export interface PrepareDeviceRequest {
/**
 * @minItems 1
 * @maxItems 10
 */
profiles: [Ulid]|[Ulid, Ulid]|[Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid]|[Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid, Ulid]
/**
 * @minItems 0
 */
number_ranges: {
document_type: DocumentNumberAllocationsDocumentType
quantity: number
}[]
/**
 * @minItems 0
 */
stock_quotas: {
stock_position_id: Ulid
stock_lot_id: NullableUlid
quantity: Quantity
}[]
/**
 * @minItems 0
 */
invoice_ids: Ulid[]
known_catalog_version: (string | null)
}
export interface ReleaseDeviceRequest {
last_device_sequence: Counter
last_event_hash: NullableSha256Hex
reason: string
}
/**
 * Résolution autorisée et motivée ; ne réécrit jamais core/hash/signature ; une correction a sa propre command_id (contrat 23).
 */
export interface ConflictResolutionRequest {
decision: ("ACCEPT_LOCAL" | "KEEP_CENTRAL" | "REJECT" | "CORRECT")
reason: string
correction_command_id: NullableUlid
}
export interface CreateExportRequest {
export_type: ExportRequestsExportType
patient_id?: NullableUlid
filters?: ({

} | null)
}
/**
 * Réservation publique (L2) : consentement explicite exigé, anti-abus et idempotence.
 */
export interface BookingRequest {
service_id: Ulid
starts_at: Instant
name: string
phone: Phone
email?: (string | null)
consent: true
}
/**
 * Compte dans une liste : sans les permissions effectives.
 */
export interface StaffMemberSummary {
id: Ulid
login: string
display_name: string
email: (string | null)
active: boolean
version: Version
/**
 * @minItems 0
 * @maxItems 4
 */
roles: []|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]
}
/**
 * Compte du personnel : profils actifs et permissions effectives (jamais de hash ni de secret MFA).
 */
export interface StaffMember {
id: Ulid
login: string
display_name: string
email: (string | null)
active: boolean
version: Version
/**
 * @minItems 0
 * @maxItems 4
 */
roles: []|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]
/**
 * @minItems 0
 */
permissions: PermissionCode[]
}
export interface ClinicSummary {
id: Ulid
name: string
timezone: string
currency: Currency
production_documents_enabled: boolean
}
/**
 * Compte connecté : profils, permissions effectives, cabinet (nom, fuseau, monnaie).
 */
export interface Me {
id: Ulid
login: string
display_name: string
email: (string | null)
active: boolean
version: Version
/**
 * @minItems 0
 * @maxItems 4
 */
roles: []|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]|[("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION"), ("CASHIER" | "CLINICIAN" | "MANAGER" | "RECEPTION")]
/**
 * @minItems 0
 */
permissions: PermissionCode[]
clinic: ClinicSummary
}
/**
 * Paramètres administratifs du cabinet (gérante).
 */
export interface ClinicSettings {
id: Ulid
name: string
timezone: string
currency: Currency
address: (string | null)
phone: (string | null)
email: (string | null)
tax_identifier: (string | null)
professional_mentions: (string | null)
document_settings: ({

} | null)
production_documents_enabled: boolean
}
export interface Reauthentication {
reauthenticated_until: Instant
}
export interface StaffMemberResponse {
data: StaffMember
}
export interface StaffMemberPage {
/**
 * @minItems 0
 */
data: StaffMemberSummary[]
meta: Pagination
}
export interface MeResponse {
data: Me
}
export interface ClinicSettingsResponse {
data: ClinicSettings
}
export interface ReauthenticationResponse {
data: Reauthentication
}
/**
 * Article : prix de vente pour les profils autorisés ; purchase_cost présent seulement pour la gérante (fichier 08).
 */
export interface CatalogItem {
id: Ulid
reference: string
category_code: Code
name: string
brand: (string | null)
model: (string | null)
unit_code: Code
quantity_integer_only: boolean
sale_price: MoneyXaf
purchase_cost?: (MoneyXaf | null)
tax_rule_id: NullableUlid
stock_mode: CatalogItemsStockMode
requires_expiry: boolean
active: boolean
version: Version
}
export interface CatalogPage {
/**
 * @minItems 0
 */
data: CatalogItem[]
meta: Pagination
}
export interface CatalogItemResponse {
data: CatalogItem
}
/**
 * Règle fiscale saisie par la gérante ; jamais inventée : sans validation (validated_at), elle n'est pas appliquée aux documents de production.
 */
export interface TaxRule {
id: Ulid
code: string
label: string
rate_percent: (Rate | null)
active: boolean
validated_by: NullableUlid
validated_at: NullableInstant
legal_note: (string | null)
version: Version
}
export interface TaxRuleResponse {
data: TaxRule
}
export interface TaxRuleList {
/**
 * @minItems 0
 */
data: TaxRule[]
}
/**
 * Rapport d'import contrôlé (X01) : aperçu sans écriture (dry_run) puis import ; une ligne refusée n'est jamais importée partiellement.
 */
export interface ImportReport {
dry_run: boolean
rows: number
accepted: number
/**
 * @minItems 0
 */
rejected: {
line: number
field: (string | null)
message: string
}[]
/**
 * @minItems 0
 */
created_ids: Ulid[]
}
export interface ImportReportResponse {
data: ImportReport
}
export interface InvoiceLine {
id: Ulid
position: number
catalog_item_id: NullableUlid
reference_snapshot: (string | null)
description_snapshot: string
category_snapshot: Code
unit_snapshot: Code
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
line_total_gross: MoneyXaf
tax_code_snapshot: string
tax_rate_snapshot: (Rate | null)
tax_amount: (MoneyXaf | null)
prescription_id: NullableUlid
}
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
export interface InvoiceBalance {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
export interface InvoicePayment {
id: Ulid
amount: PositiveMoneyXaf
method: PaymentsMethod
reference: (string | null)
effective_at: Instant
confirmed_at: NullableInstant
status: PaymentsStatus
receipt_id: NullableUlid
receipt_number: (string | null)
reversed_amount: MoneyXaf
}
/**
 * État central du PDF : PENDING n'autorise jamais à répéter l'opération (fichier 08).
 */
export interface DocumentState {
document_id: Ulid
document_status: GeneratedDocumentsStatus
}
/**
 * Facture : draft_version (version du brouillon, figée à l'émission) et ledger_version (projection après émission, null pour un brouillon) nommées distinctement (fichier 08).
 */
export interface Invoice {
id: Ulid
patient_id: Ulid
visit_id: NullableUlid
number: (string | null)
status: InvoicesStatus
issued_at: NullableInstant
issued_by: NullableUlid
due_date: NullableCivilDate
currency: Currency
total_gross: MoneyXaf
origin: InvoicesOrigin
legacy_reference: (string | null)
draft_version: Version
ledger_version: (Version | null)
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
balance: {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
/**
 * @minItems 0
 */
lines: {
id: Ulid
position: number
catalog_item_id: NullableUlid
reference_snapshot: (string | null)
description_snapshot: string
category_snapshot: Code
unit_snapshot: Code
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
line_total_gross: MoneyXaf
tax_code_snapshot: string
tax_rate_snapshot: (Rate | null)
tax_amount: (MoneyXaf | null)
prescription_id: NullableUlid
}[]
/**
 * @minItems 0
 */
payments: {
id: Ulid
amount: PositiveMoneyXaf
method: PaymentsMethod
reference: (string | null)
effective_at: Instant
confirmed_at: NullableInstant
status: PaymentsStatus
receipt_id: NullableUlid
receipt_number: (string | null)
reversed_amount: MoneyXaf
}[]
/**
 * @minItems 0
 */
credit_notes: {
id: Ulid
number: (string | null)
status: CreditNotesStatus
amount_gross: MoneyXaf
issued_at: NullableInstant
}[]
/**
 * @minItems 0
 */
refunds: {
id: Ulid
number: string
amount: PositiveMoneyXaf
method: RefundsMethod
effective_at: Instant
}[]
write_owner: ({
device_id: Ulid
epoch: number
status: InvoiceWriteOwnersStatus
} | null)
document: ({
document_id: Ulid
document_status: GeneratedDocumentsStatus
} | null)
}
export interface InvoiceSummary {
id: Ulid
patient_id: Ulid
number: (string | null)
status: InvoicesStatus
issued_at: NullableInstant
total_gross: MoneyXaf
balance_due: SignedMoneyXaf
draft_version: Version
ledger_version: (Version | null)
}
export interface InvoiceResponse {
data: Invoice
}
export interface InvoicePage {
/**
 * @minItems 0
 */
data: InvoiceSummary[]
meta: Pagination
}
/**
 * Réponse confirmée d'encaissement (fichier 08) : références paiement/reçu, montant, solde recalculé, état du PDF.
 */
export interface PaymentResult {
payment_id: Ulid
receipt_id: Ulid
receipt_number: string
invoice_id: Ulid
amount: PositiveMoneyXaf
currency: Currency
method: PaymentsMethod
effective_at: Instant
recorded_at: Instant
ledger_version: Version
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
balance: {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
/**
 * État central du PDF : PENDING n'autorise jamais à répéter l'opération (fichier 08).
 */
receipt_document: {
document_id: Ulid
document_status: GeneratedDocumentsStatus
}
}
export interface ReversalResult {
reversal_id: Ulid
payment_id: Ulid
invoice_id: Ulid
amount: PositiveMoneyXaf
recorded_at: Instant
ledger_version: Version
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
balance: {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
}
export interface CreditNoteResult {
credit_note_id: Ulid
number: string
invoice_id: Ulid
amount_gross: PositiveMoneyXaf
issued_at: Instant
ledger_version: Version
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
balance: {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
/**
 * État central du PDF : PENDING n'autorise jamais à répéter l'opération (fichier 08).
 */
document: {
document_id: Ulid
document_status: GeneratedDocumentsStatus
}
}
export interface RefundResult {
refund_id: Ulid
number: string
invoice_id: Ulid
credit_note_id: Ulid
amount: PositiveMoneyXaf
method: RefundsMethod
effective_at: Instant
ledger_version: Version
/**
 * Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.
 */
balance: {
total_gross: MoneyXaf
paid_net: MoneyXaf
credited: MoneyXaf
refunded: MoneyXaf
balance_due: SignedMoneyXaf
credit_available: MoneyXaf
settlement: ("NOT_ISSUED" | "UNPAID" | "PARTIALLY_PAID" | "SETTLED" | "IN_CREDIT")
}
/**
 * État central du PDF : PENDING n'autorise jamais à répéter l'opération (fichier 08).
 */
document: {
document_id: Ulid
document_status: GeneratedDocumentsStatus
}
}
export interface PaymentResultResponse {
data: PaymentResult
}
export interface ReversalResultResponse {
data: ReversalResult
}
export interface CreditNoteResultResponse {
data: CreditNoteResult
}
export interface RefundResultResponse {
data: RefundResult
}
export interface Approval {
id: Ulid
action_code: PermissionCode
target_type: string
target_id: Ulid
payload_hash: Sha256Hex
requested_by: Ulid
reason: string
status: ApprovalsStatus
decided_by: NullableUlid
decided_at: NullableInstant
expires_at: NullableInstant
consumed_at: NullableInstant
}
export interface ApprovalResponse {
data: Approval
}
/**
 * Session de caisse ; collected_cash/refunded_cash sont des projections de la session.
 */
export interface CashSession {
id: Ulid
cash_register_id: Ulid
cashier_id: Ulid
opened_at: Instant
opening_amount: MoneyXaf
closed_at: NullableInstant
counted_amount: (MoneyXaf | null)
expected_amount: (MoneyXaf | null)
variance: (SignedMoneyXaf | null)
variance_reason: (string | null)
closed_by: NullableUlid
collected_cash: MoneyXaf
refunded_cash: MoneyXaf
}
export interface CashSessionResponse {
data: CashSession
}
export interface CurrentCashSessionResponse {
data: (CashSession | null)
}
export interface Delivery {
id: Ulid
invoice_id: Ulid
status: DeliveriesStatus
delivered_at: NullableInstant
delivered_by: NullableUlid
credit_delivery_approval_id: NullableUlid
/**
 * @minItems 1
 */
lines: [{
id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
}, ...({
id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
})[]]
}
export interface DeliveryResponse {
data: Delivery
}
export interface ClinicalRevision {
id: Ulid
revision_no: number
recorded_at: Instant
author_id: Ulid
reason: string
previous_revision_id: NullableUlid
sha256: Sha256Hex
}
/**
 * Consultation complète : professionnel et gérante seulement (clinical.read).
 */
export interface Consultation {
id: Ulid
visit_id: Ulid
patient_id: Ulid
clinician_id: Ulid
effective_at: Instant
date_change_reason: (string | null)
status: ConsultationsStatus
current_revision: number
completed_at: NullableInstant
finalization_missing_data_reason: (string | null)
version: Version
steps: AllSteps
/**
 * @minItems 0
 */
revisions: {
id: Ulid
revision_no: number
recorded_at: Instant
author_id: Ulid
reason: string
previous_revision_id: NullableUlid
sha256: Sha256Hex
}[]
}
/**
 * Prescription versionnée ; updated_at sert de jeton de concurrence pour PATCH d'un brouillon (optical_prescriptions n'a pas de colonne version).
 */
export interface Prescription {
id: Ulid
series_id: Ulid
version_no: number
supersedes_id: NullableUlid
patient_id: Ulid
origin: OpticalPrescriptionsOrigin
consultation_id: NullableUlid
external_prescriber: (string | null)
external_prescribed_on: NullableCivilDate
external_file_id: NullableUlid
status: OpticalPrescriptionsStatus
usage_code: (Code | null)
usage_other: (string | null)
lens_type_code: (Code | null)
lens_type_other: (string | null)
lens_index: (string | null)
lens_index_other: (string | null)
pd_total: NullableDiopter
pd_od: NullableDiopter
pd_og: NullableDiopter
pd_inconsistency_reason: (string | null)
notes: (string | null)
/**
 * @minItems 2
 * @maxItems 2
 */
eyes: [{
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}, {
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}]
/**
 * @minItems 0
 */
treatments: {
id: Ulid
term_code: Code
other_text: (string | null)
}[]
validated_at: NullableInstant
validated_by: NullableUlid
document_id: NullableUlid
updated_at: Instant
}
export interface ConsultationResponse {
data: Consultation
}
export interface PrescriptionResponse {
data: Prescription
}
export interface EnrolledDevice {
device_id: Ulid
device_code: string
name: string
kid: Ulid
status: EnrolledDevicesStatus
enrolled_at: Instant
responsible_user_id: Ulid
app_version: string
}
export interface EnrolledDeviceResponse {
data: EnrolledDevice
}
export interface OfflineGrant {
grant_id: Ulid
user_id: Ulid
/**
 * @minItems 0
 */
permissions_snapshot: PermissionCode[]
/**
 * @minItems 0
 */
compartments: ("ADMINISTRATIVE" | "CLINICAL" | "FINANCIAL" | "MANAGER")[]
rules_version: string
catalog_version: string
issued_at: Instant
valid_until: Instant
signed_payload: CompactJws
}
export interface NumberAllocation {
allocation_id: Ulid
document_type: DocumentNumberAllocationsDocumentType
document_year: number
range_start: Counter
range_end: Counter
last_consumed_value: (Counter | null)
status: DocumentNumberAllocationsStatus
}
export interface StockQuota {
allocation_id: Ulid
stock_position_id: Ulid
stock_lot_id: NullableUlid
allocated_quantity: Quantity
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
consumed_quantity: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
returned_quantity: string
status: DeviceStockAllocationsStatus
}
/**
 * Préparation du poste (fichier 19) : grants signés par profil, plages de numéros, quotas, factures attribuées, catalogue versionné.
 */
export interface Preparation {
device_id: Ulid
/**
 * @minItems 1
 */
grants: [{
grant_id: Ulid
user_id: Ulid
/**
 * @minItems 0
 */
permissions_snapshot: PermissionCode[]
/**
 * @minItems 0
 */
compartments: ("ADMINISTRATIVE" | "CLINICAL" | "FINANCIAL" | "MANAGER")[]
rules_version: string
catalog_version: string
issued_at: Instant
valid_until: Instant
signed_payload: CompactJws
}, ...({
grant_id: Ulid
user_id: Ulid
/**
 * @minItems 0
 */
permissions_snapshot: PermissionCode[]
/**
 * @minItems 0
 */
compartments: ("ADMINISTRATIVE" | "CLINICAL" | "FINANCIAL" | "MANAGER")[]
rules_version: string
catalog_version: string
issued_at: Instant
valid_until: Instant
signed_payload: CompactJws
})[]]
/**
 * @minItems 0
 */
number_allocations: {
allocation_id: Ulid
document_type: DocumentNumberAllocationsDocumentType
document_year: number
range_start: Counter
range_end: Counter
last_consumed_value: (Counter | null)
status: DocumentNumberAllocationsStatus
}[]
/**
 * @minItems 0
 */
stock_allocations: {
allocation_id: Ulid
stock_position_id: Ulid
stock_lot_id: NullableUlid
allocated_quantity: Quantity
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
consumed_quantity: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
returned_quantity: string
status: DeviceStockAllocationsStatus
}[]
/**
 * @minItems 0
 */
write_owners: {
invoice_id: Ulid
epoch: number
}[]
catalog: {
catalog_version: string
snapshot_sha256: Sha256Hex
file_id: Ulid
}
rules_version: string
server_time: Instant
last_accepted_sequence: Counter
last_accepted_hash: NullableSha256Hex
}
export interface PreparationResponse {
data: Preparation
}
export interface Release {
device_id: Ulid
/**
 * @minItems 0
 */
closed_number_allocations: Ulid[]
/**
 * @minItems 0
 */
returned_stock_allocations: Ulid[]
/**
 * @minItems 0
 */
closed_write_owners: Ulid[]
released_at: Instant
}
export interface ReleaseResponse {
data: Release
}
export interface DeviceHealth {
device_id: Ulid
status: EnrolledDevicesStatus
last_sync_at: NullableInstant
last_accepted_sequence: Counter
last_accepted_hash: NullableSha256Hex
pending_conflicts: number
app_version: string
grants_valid_until: NullableInstant
/**
 * @minItems 0
 */
number_ranges_low: Ulid[]
}
export interface DeviceHealthResponse {
data: DeviceHealth
}
/**
 * Conflit de synchronisation : les instantanés chiffrés restent côté serveur ; seuls les profils autorisés les déchiffrent.
 */
export interface Conflict {
id: Ulid
command_id: Ulid
conflict_type: string
status: SyncConflictsStatus
device_id: Ulid
created_at: Instant
resolution: (string | null)
resolved_by: NullableUlid
resolved_at: NullableInstant
}
export interface ConflictPage {
/**
 * @minItems 0
 */
data: Conflict[]
meta: Pagination
}
export interface ConflictResponse {
data: Conflict
}
export interface GeneratedDocument {
id: Ulid
document_type: GeneratedDocumentsDocumentType
source_key: string
revision_no: number
template_version: string
status: GeneratedDocumentsStatus
sha256: NullableSha256Hex
generated_at: NullableInstant
failure_code: (string | null)
}
export interface GeneratedDocumentResponse {
data: GeneratedDocument
}
export interface ExportRequest {
id: Ulid
export_type: ExportRequestsExportType
patient_id: NullableUlid
filters: ({

} | null)
status: ExportRequestsStatus
requested_by: Ulid
completed_at: NullableInstant
expires_at: NullableInstant
error_code: (string | null)
}
export interface ExportRequestResponse {
data: ExportRequest
}
/**
 * Gérante : chiffre d'affaires émis et encaissé distincts ; créances d'ouverture papier séparées ; postes non synchronisés signalés (totaux incomplets).
 */
export interface FinancialReport {
from: CivilDate
to: CivilDate
issued_total: MoneyXaf
collected_total: MoneyXaf
credited_total: MoneyXaf
refunded_total: MoneyXaf
outstanding_total: SignedMoneyXaf
/**
 * @minItems 0
 */
collected_by_method: {
method: PaymentsMethod
amount: MoneyXaf
}[]
legacy_opening_balances: MoneyXaf
/**
 * @minItems 0
 */
incomplete_devices: {
device_id: Ulid
last_sync_at: NullableInstant
}[]
}
export interface FinancialReportResponse {
data: FinancialReport
}
/**
 * Événement du journal append-only (lecture gérante, audit.read) ; aucun secret dans le diff.
 */
export interface AuditEvent {
id: Ulid
actor_id: NullableUlid
actor_name_snapshot: string
action: string
target_type: string
target_id: NullableUlid
recorded_at: Instant
correlation_id: string
reason: (string | null)
sanitized_diff: ({

} | null)
event_hash: Sha256Hex
}
export interface AuditEventPage {
/**
 * @minItems 0
 */
data: AuditEvent[]
meta: Pagination
}
/**
 * Résultat de recherche : identité administrative minimale (tous profils autorisés).
 */
export interface PatientSummary {
id: Ulid
record_number: string
last_name: string
first_names: (string | null)
sex: PatientRecordsSex
birth_date: NullableCivilDate
phone_primary: (Phone | null)
archived: boolean
merged_into_patient_id: NullableUlid
version: Version
}
/**
 * Dossier administratif (identité durable) ; jamais de donnée clinique.
 */
export interface Patient {
id: Ulid
record_number: string
last_name: string
first_names: (string | null)
sex: PatientRecordsSex
birth_date: NullableCivilDate
phone_primary: (Phone | null)
archived: boolean
merged_into_patient_id: NullableUlid
version: Version
birth_date_missing_reason: (string | null)
phone_secondary: (Phone | null)
phone_missing_reason: (string | null)
address: (string | null)
profession: (string | null)
guardian_contact: ({

} | null)
legacy_record: boolean
first_known_visit_on: NullableCivilDate
first_visit_certified: boolean
legacy_record_number: (string | null)
created_at: Instant
updated_at: Instant
}
/**
 * Historique : visites pour tout profil autorisé ; consultations seulement avec clinical.read ; pièces seulement avec invoice.read.
 */
export interface PatientHistory {
patient_id: Ulid
/**
 * @minItems 0
 */
visits: {
id: Ulid
reason: VisitsReason
occurred_at: Instant
status: VisitsStatus
}[]
/**
 * @minItems 0
 */
consultations?: {
id: Ulid
visit_id: Ulid
effective_at: Instant
status: ConsultationsStatus
current_revision: number
clinician_id: Ulid
}[]
/**
 * @minItems 0
 */
invoices?: {
id: Ulid
number: (string | null)
status: InvoicesStatus
issued_at: NullableInstant
total_gross: MoneyXaf
balance_due: SignedMoneyXaf
}[]
}
export interface PatientResponse {
data: Patient
}
export interface PatientPage {
/**
 * @minItems 0
 */
data: PatientSummary[]
meta: Pagination
}
export interface PatientHistoryResponse {
data: PatientHistory
}
export interface Visit {
id: Ulid
patient_id: Ulid
reason: VisitsReason
occurred_at: Instant
status: VisitsStatus
appointment_id: NullableUlid
exam_invoice_id: NullableUlid
exam_waiver_approval_id: NullableUlid
arrival_at: NullableInstant
closed_at: NullableInstant
administrative_note: (string | null)
version: Version
}
export interface VisitResponse {
data: Visit
}
export interface VisitPage {
/**
 * @minItems 0
 */
data: Visit[]
meta: Pagination
}
export interface PublicService {
id: Ulid
name: string
duration_minutes: number
}
export interface PublicServiceList {
/**
 * @minItems 0
 */
data: PublicService[]
}
export interface PublicSlot {
service_id: Ulid
resource_id: Ulid
starts_at: Instant
ends_at: Instant
}
export interface PublicSlotList {
/**
 * @minItems 0
 */
data: PublicSlot[]
}
/**
 * Référence publique distincte des identifiants internes ; ne révèle jamais l'existence d'un dossier.
 */
export interface BookingReceipt {
public_reference: string
status: ("RECEIVED" | "PENDING_CONFIRMATION")
}
export interface BookingReceiptResponse {
data: BookingReceipt
}
export interface FollowUpPlan {
id: Ulid
consultation_id: Ulid
patient_id: Ulid
reason: FollowUpPlansReason
recommended_date: CivilDate
date_origin: FollowUpPlansDateOrigin
reminder_enabled: boolean
preferred_channel: FollowUpPlansPreferredChannel
status: FollowUpPlansStatus
version: Version
}
export interface FollowUpPlanResponse {
data: FollowUpPlan
}
/**
 * Rendez-vous ; un rendez-vous pris hors-ligne reste PENDING_CONFIRMATION jusqu'à validation serveur.
 */
export interface Appointment {
id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
follow_up_id: NullableUlid
resource_id: Ulid
service_id: Ulid
starts_at: Instant
ends_at: Instant
status: AppointmentsStatus
source: AppointmentsSource
administrative_reason: (string | null)
cancel_reason: (string | null)
version: Version
}
export interface AppointmentResponse {
data: Appointment
}
export interface AppointmentPage {
/**
 * @minItems 0
 */
data: Appointment[]
meta: Pagination
}
export interface ContactTask {
id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
follow_up_id: NullableUlid
appointment_id: NullableUlid
invoice_id: NullableUlid
due_at: Instant
channel: ContactTasksChannel
status: ContactTasksStatus
next_attempt_at: NullableInstant
assigned_to: NullableUlid
administrative_notes: (string | null)
version: Version
}
export interface ContactTaskPage {
/**
 * @minItems 0
 */
data: ContactTask[]
meta: Pagination
}
export interface ContactAttemptResult {
attempt_id: Ulid
task: ContactTask
}
export interface ContactAttemptResultResponse {
data: ContactAttemptResult
}
/**
 * Activité administrative (accueil, gérante) : comptages, aucune donnée clinique ni financière.
 */
export interface ActivityReport {
from: CivilDate
to: CivilDate
visits: number
new_patients: number
appointments: number
appointments_no_show: number
contact_tasks_due: number
contact_tasks_done: number
}
export interface ActivityReportResponse {
data: ActivityReport
}
/**
 * Statistiques cliniques agrégées (R02, statistics.clinical) : comptages seulement, aucun patient identifiable.
 */
export interface ClinicalReport {
from: CivilDate
to: CivilDate
consultations_completed: number
prescriptions_validated: number
referrals: number
/**
 * @minItems 0
 */
ametropias: {
term_code: Code
count: number
}[]
}
export interface ClinicalReportResponse {
data: ClinicalReport
}
/**
 * Disponible central = physique − réservations centrales − quotas locaux non consommés (fichier 19).
 */
export interface StockAvailability {
item_id: Ulid
location_id: Ulid
stock_position_id: Ulid
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
on_hand: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
reserved: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
offline_allocated: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
central_available: string
/**
 * @minItems 0
 */
lots: {
lot_id: Ulid
batch_number: string
expires_on: NullableCivilDate
status: StockLotsStatus
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
on_hand: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
reserved: string
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
offline_allocated: string
}[]
}
export interface StockAvailabilityPage {
/**
 * @minItems 0
 */
data: StockAvailability[]
meta: Pagination
}
export interface StockMovement {
id: Ulid
item_id: Ulid
position_id: Ulid
lot_id: NullableUlid
kind: StockMovementsKind
/**
 * DECIMAL(12,3) en chaîne exacte, zéro admis.
 */
signed_quantity: string
occurred_at: Instant
reason: string
approval_id: NullableUlid
}
export interface StockMovementResponse {
data: StockMovement
}
export interface OpeningStockResult {
/**
 * @minItems 1
 */
movements: [StockMovement, ...(StockMovement)[]]
}
export interface OpeningStockResponse {
data: OpeningStockResult
}
export interface PhysicalReturn {
id: Ulid
delivery_line_id: Ulid
item_id: Ulid
quantity: Quantity
condition_code: PhysicalReturnsConditionCode
accepted_for_restock: boolean
occurred_at: Instant
stock_movement_id: NullableUlid
credit_note_id: NullableUlid
}
export interface PhysicalReturnResponse {
data: PhysicalReturn
}
export interface RuleVectors {
/**
 * Version des règles (contracts/compatibility.json, rules_versions).
 */
rules_version: string
description: string
/**
 * @minItems 1
 */
cases: [VectorCase, ...(VectorCase)[]]
}
export interface VectorRefusal {
/**
 * Code d'erreur du fichier 08 (sous-ensemble de error.json#/$defs/ErrorCode).
 */
code: ("VALIDATION_FAILED" | "PAYMENT_EXCEEDS_BALANCE" | "STOCK_UNAVAILABLE" | "LOT_EXPIRED")
field: string
rule: string
details?: {
[k: string]: string | number | null | undefined
}
}
export interface Ack {
command_id: Ulid
device_sequence: Counter
status: ("ACCEPTED" | "ALREADY_ACCEPTED" | "WAITING_DEPENDENCY" | "CONFLICT_REQUIRES_REVIEW" | "REJECTED")
/**
 * Identifiants d'origine des effets appliqués (ex. invoice_id, payment_id, receipt_id), identiques à ceux du poste.
 */
result_references: {
[k: string]: Ulid | undefined
}
versions: {
draft_version?: Version
ledger_version?: Version
entity_version?: Version
}
/**
 * Dépendances manquantes accessibles (WAITING_DEPENDENCY), sinon liste vide.
 */
dependencies: {
entity_type: string
entity_id: Ulid
}[]
conflict_id: NullableUlid
error_code: (ErrorCode | null)
reason: (string | null)
}
export interface CommandCore {
command_id: Ulid
command_name: CommandName
device_id: Ulid
actor_id: Ulid
grant_id: Ulid
clinic_id: Ulid
/**
 * Rang strictement croissant dans le journal unique du poste (premier = "1").
 */
device_sequence: Counter
previous_event_hash: NullableSha256Hex
/**
 * Version du schéma de la charge (contracts/commands/<Commande>.v<N>.json), en chaîne de chiffres comme la colonne sync_commands.schema_version : le cœur est reconstitué à l'identique pour recalculer event_hash.
 */
schema_version: string
/**
 * Version des règles métier appliquées localement (compatibility.json).
 */
rules_version: string
occurred_at_local: Instant
last_server_time: Instant
/**
 * Agrégat principal visé (nom de table du catalogue).
 */
entity_type: string
entity_id: Ulid
/**
 * Version brouillon (UpdateDraft/IssueInvoice) ou ledger_version (RegisterPayment/ReversePayment/IssueCreditNote/RefundCredit) ; null pour une création.
 */
expected_version: (Version | null)
number_allocation_id: NullableUlid
/**
 * Quotas device_stock_allocations utilisés, triés, sans doublon (liste vide si aucun).
 * 
 * @maxItems 100
 */
stock_allocation_ids: Ulid[]
/**
 * Charge métier validée par contracts/commands/<command_name>.v<schema_version>.json (dispatch.json).
 */
payload: {

}
}
export interface PullPage {
/**
 * @maxItems 100
 */
changes: Change[]
/**
 * Curseur opaque signé (borne supérieure incluse) ; à renvoyer tel quel.
 */
next_cursor: string
has_more: boolean
server_time: Instant
}
export interface Change {
change_sequence: Counter
entity_type: string
entity_id: Ulid
entity_version: Version
change_kind: SyncChangeLogChangeKind
visibility: SyncChangeLogVisibility
recorded_at: Instant
/**
 * Projection autorisée de l'entité pour ce profil (jamais une donnée d'un compartiment non autorisé).
 */
data: {

}
}
export interface PushRequest {
device_id: Ulid
/**
 * @minItems 1
 * @maxItems 50
 */
commands: [SignedCommand, ...(SignedCommand)[]]
}
export interface SignedCommand {
core: CommandCore
event_hash: Sha256Hex
signature: CompactJws
}
export interface PushResponse {
results: Ack[]
finalized_prefix: {
last_sequence: Counter
last_hash: NullableSha256Hex
}
server_time: Instant
}
/**
 * En-tête protégé du JWS : algorithme et type imposés, kid de la clé d'appareil enrôlée.
 */
export interface JwsProtectedHeader {
alg: "ES256"
typ: "eeo-command+jws"
kid: Ulid
}
/**
 * Charge signée : la signature porte sur l'identité de la commande, le poste et event_hash (pas sur le cœur entier, déjà lié par le hash).
 */
export interface JwsPayload {
command_id: Ulid
device_id: Ulid
event_hash: Sha256Hex
}
export interface AmendConsultationPayload {
consultation_id: Ulid
revision_id: Ulid
revision_no: number
previous_revision_id: Ulid
reason: Reason
/**
 * @minItems 1
 * @maxItems 6
 */
steps: [{
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]
snapshot_sha256: Sha256Hex
}
/**
 * Corps de la route directe POST /consultations/{id}/amendments : même charge + expected_version (Version de l'agrégat (colonne version).) ; en-tête Idempotency-Key obligatoire.
 */
export interface AmendConsultationDirectRequest {
expected_version: Version
consultation_id: Ulid
revision_id: Ulid
revision_no: number
previous_revision_id: Ulid
reason: Reason
/**
 * @minItems 1
 * @maxItems 6
 */
steps: [{
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]|[{
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}, {
step: StepName
data: {

}
}]
snapshot_sha256: Sha256Hex
}
export interface CloseCashSessionPayload {
cash_session_id: Ulid
closed_at: Instant
counted_amount: MoneyXaf
expected_amount: MoneyXaf
variance: SignedMoneyXaf
variance_reason: (string | null)
}
/**
 * Corps de la route directe POST /cash-sessions/{id}/close : même charge + expected_version (Transition d'état contrôlée sous verrou (statut attendu vérifié par le serveur) ; la table n'a pas de colonne version, que le contrat 23 interdit d'inventer : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CloseCashSessionDirectRequest {
expected_version: null
cash_session_id: Ulid
closed_at: Instant
counted_amount: MoneyXaf
expected_amount: MoneyXaf
variance: SignedMoneyXaf
variance_reason: (string | null)
}
export interface CompleteConsultationPayload {
consultation_id: Ulid
revision_id: Ulid
revision_no: number
snapshot_sha256: Sha256Hex
finalization_missing_data_reason: (string | null)
}
/**
 * Corps de la route directe POST /consultations/{id}/complete : même charge + expected_version (Version de l'agrégat (colonne version).) ; en-tête Idempotency-Key obligatoire.
 */
export interface CompleteConsultationDirectRequest {
expected_version: Version
consultation_id: Ulid
revision_id: Ulid
revision_no: number
snapshot_sha256: Sha256Hex
finalization_missing_data_reason: (string | null)
}
export interface ConfirmDeliveryPayload {
delivery_id: Ulid
invoice_id: Ulid
delivered_at: Instant
credit_delivery_approval_id: NullableUlid
/**
 * @minItems 1
 * @maxItems 200
 */
lines: [{
delivery_line_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
stock_movement_id: NullableUlid
}, ...({
delivery_line_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
stock_movement_id: NullableUlid
})[]]
}
/**
 * Corps de la route directe POST /invoices/{id}/deliveries : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface ConfirmDeliveryDirectRequest {
expected_version: null
delivery_id: Ulid
invoice_id: Ulid
delivered_at: Instant
credit_delivery_approval_id: NullableUlid
/**
 * @minItems 1
 * @maxItems 200
 */
lines: [{
delivery_line_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
stock_movement_id: NullableUlid
}, ...({
delivery_line_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
quantity: Quantity
stock_position_id: NullableUlid
stock_lot_id: NullableUlid
reservation_id: NullableUlid
stock_movement_id: NullableUlid
})[]]
}
export interface CreateAppointmentPayload {
appointment_id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
follow_up_id: NullableUlid
resource_id: Ulid
service_id: Ulid
starts_at: Instant
ends_at: Instant
administrative_reason: (string | null)
}
/**
 * Corps de la route directe POST /appointments : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CreateAppointmentDirectRequest {
expected_version: null
appointment_id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
follow_up_id: NullableUlid
resource_id: Ulid
service_id: Ulid
starts_at: Instant
ends_at: Instant
administrative_reason: (string | null)
}
export interface CreateFollowUpPayload {
follow_up_plan_id: Ulid
consultation_id: Ulid
patient_id: Ulid
reason: FollowUpPlansReason
anchor_kind: FollowUpPlansAnchorKind
anchor_date: CivilDate
interval_value: (number | null)
interval_unit: (FollowUpPlansIntervalUnit | null)
recommended_date: CivilDate
date_origin: FollowUpPlansDateOrigin
manual_reason: (string | null)
reminder_enabled: boolean
preferred_channel: FollowUpPlansPreferredChannel
advance_value: (number | null)
advance_unit: (FollowUpPlansAdvanceUnit | null)
notes: (string | null)
}
/**
 * Corps de la route directe POST /follow-ups : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CreateFollowUpDirectRequest {
expected_version: null
follow_up_plan_id: Ulid
consultation_id: Ulid
patient_id: Ulid
reason: FollowUpPlansReason
anchor_kind: FollowUpPlansAnchorKind
anchor_date: CivilDate
interval_value: (number | null)
interval_unit: (FollowUpPlansIntervalUnit | null)
recommended_date: CivilDate
date_origin: FollowUpPlansDateOrigin
manual_reason: (string | null)
reminder_enabled: boolean
preferred_channel: FollowUpPlansPreferredChannel
advance_value: (number | null)
advance_unit: (FollowUpPlansAdvanceUnit | null)
notes: (string | null)
}
export interface CreateInvoiceDraftPayload {
invoice_id: Ulid
patient_id: Ulid
visit_id: NullableUlid
due_date: NullableCivilDate
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Ligne de brouillon : prix TTC unitaire, remise TTC ; total de ligne recalculé (ROUND(quantité × prix) − remise).
 */
lines: {
line_id: Ulid
position: number
catalog_item_id: NullableUlid
description: string
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
prescription_id: NullableUlid
}[]
}
/**
 * Corps de la route directe POST /invoices : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CreateInvoiceDraftDirectRequest {
expected_version: null
invoice_id: Ulid
patient_id: Ulid
visit_id: NullableUlid
due_date: NullableCivilDate
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Ligne de brouillon : prix TTC unitaire, remise TTC ; total de ligne recalculé (ROUND(quantité × prix) − remise).
 */
lines: {
line_id: Ulid
position: number
catalog_item_id: NullableUlid
description: string
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
prescription_id: NullableUlid
}[]
}
export interface CreatePatientPayload {
patient_id: Ulid
record_number_usage_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
sex: PatientRecordsSex
birth_date: NullableCivilDate
birth_date_missing_reason: (string | null)
phone_primary: (Phone | null)
phone_secondary: (Phone | null)
phone_missing_reason: (string | null)
address: (string | null)
profession: (string | null)
guardian_contact: ({

} | null)
legacy_record: boolean
first_known_visit_on: NullableCivilDate
first_visit_certified: boolean
legacy_record_number: (string | null)
}
/**
 * Corps de la route directe POST /patients : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CreatePatientDirectRequest {
expected_version: null
patient_id: Ulid
record_number_usage_id: Ulid
/**
 * Numéro central attribué par le serveur sous verrou : null dans la requête directe.
 */
record_number: null
last_name: string
first_names: (string | null)
sex: PatientRecordsSex
birth_date: NullableCivilDate
birth_date_missing_reason: (string | null)
phone_primary: (Phone | null)
phone_secondary: (Phone | null)
phone_missing_reason: (string | null)
address: (string | null)
profession: (string | null)
guardian_contact: ({

} | null)
legacy_record: boolean
first_known_visit_on: NullableCivilDate
first_visit_certified: boolean
legacy_record_number: (string | null)
}
export interface CreatePrescriptionPayload {
prescription_id: Ulid
series_id: Ulid
version_no: number
supersedes_id: NullableUlid
patient_id: Ulid
origin: OpticalPrescriptionsOrigin
consultation_id: NullableUlid
external_prescriber: (string | null)
external_prescribed_on: NullableCivilDate
external_file_id: NullableUlid
usage_code: (Code | null)
usage_other: (string | null)
lens_type_code: (Code | null)
lens_type_other: (string | null)
lens_index: (string | null)
lens_index_other: (string | null)
pd_total: NullableDiopter
pd_od: NullableDiopter
pd_og: NullableDiopter
pd_inconsistency_reason: (string | null)
notes: (string | null)
/**
 * @minItems 2
 * @maxItems 2
 */
eyes: [{
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}, {
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}]
/**
 * @minItems 0
 * @maxItems 20
 */
treatments: []|[{
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]
}
/**
 * Corps de la route directe POST /consultations/{id}/prescriptions : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface CreatePrescriptionDirectRequest {
expected_version: null
prescription_id: Ulid
series_id: Ulid
version_no: number
supersedes_id: NullableUlid
patient_id: Ulid
origin: OpticalPrescriptionsOrigin
consultation_id: NullableUlid
external_prescriber: (string | null)
external_prescribed_on: NullableCivilDate
external_file_id: NullableUlid
usage_code: (Code | null)
usage_other: (string | null)
lens_type_code: (Code | null)
lens_type_other: (string | null)
lens_index: (string | null)
lens_index_other: (string | null)
pd_total: NullableDiopter
pd_od: NullableDiopter
pd_og: NullableDiopter
pd_inconsistency_reason: (string | null)
notes: (string | null)
/**
 * @minItems 2
 * @maxItems 2
 */
eyes: [{
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}, {
id: Ulid
eye: OpticalPrescriptionEyesEye
no_correction: boolean
sphere: NullableDiopter
cylinder: NullableDiopter
axis: (number | null)
addition: NullableDiopter
fitting_height: NullableDiopter
}]
/**
 * @minItems 0
 * @maxItems 20
 */
treatments: []|[{
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]|[{
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}, {
id: Ulid
term_code: Code
other_text: (string | null)
}]
}
export interface DecideApprovalPayload {
approval_id: Ulid
decision: ("APPROVED" | "REFUSED")
reason: (string | null)
}
/**
 * Corps de la route directe POST /approvals/{id}/decision : même charge + expected_version (Transition d'état contrôlée sous verrou (statut attendu vérifié par le serveur) ; la table n'a pas de colonne version, que le contrat 23 interdit d'inventer : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface DecideApprovalDirectRequest {
expected_version: null
approval_id: Ulid
decision: ("APPROVED" | "REFUSED")
reason: (string | null)
}
export interface IssueCreditNotePayload {
invoice_id: Ulid
credit_note_id: Ulid
number_usage_id: Ulid
number: string
amount_gross: PositiveMoneyXaf
reason: Reason
approval_id: Ulid
document_id: Ulid
/**
 * @minItems 1
 * @maxItems 200
 */
lines: [{
credit_note_line_id: Ulid
invoice_line_id: Ulid
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
reason: (string | null)
}, ...({
credit_note_line_id: Ulid
invoice_line_id: Ulid
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
reason: (string | null)
})[]]
}
/**
 * Corps de la route directe POST /invoices/{id}/credit-notes : même charge + expected_version (ledger_version dérivée : 1 + paiements confirmés + contre-écritures + avoirs émis + remboursements (contrat 23).) ; en-tête Idempotency-Key obligatoire.
 */
export interface IssueCreditNoteDirectRequest {
expected_version: Version
invoice_id: Ulid
credit_note_id: Ulid
number_usage_id: Ulid
/**
 * Numéro central attribué par le serveur sous verrou : null dans la requête directe.
 */
number: null
amount_gross: PositiveMoneyXaf
reason: Reason
approval_id: Ulid
document_id: Ulid
/**
 * @minItems 1
 * @maxItems 200
 */
lines: [{
credit_note_line_id: Ulid
invoice_line_id: Ulid
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
reason: (string | null)
}, ...({
credit_note_line_id: Ulid
invoice_line_id: Ulid
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
reason: (string | null)
})[]]
}
export interface IssueInvoicePayload {
invoice_id: Ulid
number_usage_id: Ulid
number: string
issued_at: Instant
total_gross: MoneyXaf
write_owner_id: NullableUlid
catalog_snapshot_sha256: Sha256Hex
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Réserve créée à l'émission (I00) : une réserve sous quota local reste incluse dans le quota, jamais soustraite deux fois.
 */
reservations: {
reservation_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
stock_position_id: Ulid
stock_lot_id: NullableUlid
device_allocation_id: (Ulid | null)
quantity: Quantity
}[]
document_id: Ulid
}
/**
 * Corps de la route directe POST /invoices/{id}/issue : même charge + expected_version (Version du brouillon (invoices.version), figée à l'émission.) ; en-tête Idempotency-Key obligatoire.
 */
export interface IssueInvoiceDirectRequest {
expected_version: Version
invoice_id: Ulid
number_usage_id: Ulid
/**
 * Numéro central attribué par le serveur sous verrou : null dans la requête directe.
 */
number: null
issued_at: Instant
total_gross: MoneyXaf
write_owner_id: NullableUlid
catalog_snapshot_sha256: Sha256Hex
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Réserve créée à l'émission (I00) : une réserve sous quota local reste incluse dans le quota, jamais soustraite deux fois.
 */
reservations: {
reservation_id: Ulid
invoice_line_id: Ulid
item_id: Ulid
stock_position_id: Ulid
stock_lot_id: NullableUlid
device_allocation_id: (Ulid | null)
quantity: Quantity
}[]
document_id: Ulid
}
export interface OpenCashSessionPayload {
cash_session_id: Ulid
cash_register_id: Ulid
opening_amount: MoneyXaf
opened_at: Instant
}
/**
 * Corps de la route directe POST /cash-sessions : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface OpenCashSessionDirectRequest {
expected_version: null
cash_session_id: Ulid
cash_register_id: Ulid
opening_amount: MoneyXaf
opened_at: Instant
}
export interface RecordContactAttemptPayload {
attempt_id: Ulid
task_id: Ulid
occurred_at: Instant
result: ContactAttemptsResult
appointment_id: NullableUlid
administrative_note: (string | null)
next_status: ContactTasksStatus
next_attempt_at: NullableInstant
}
/**
 * Corps de la route directe POST /contact-tasks/{id}/attempts : même charge + expected_version (Version de l'agrégat (colonne version).) ; en-tête Idempotency-Key obligatoire.
 */
export interface RecordContactAttemptDirectRequest {
expected_version: Version
attempt_id: Ulid
task_id: Ulid
occurred_at: Instant
result: ContactAttemptsResult
appointment_id: NullableUlid
administrative_note: (string | null)
next_status: ContactTasksStatus
next_attempt_at: NullableInstant
}
export interface RefundCreditPayload {
invoice_id: Ulid
refund_id: Ulid
credit_note_id: Ulid
number_usage_id: Ulid
number: string
amount: PositiveMoneyXaf
method: RefundsMethod
reference: (string | null)
approval_id: Ulid
effective_at: Instant
cash_session_id: NullableUlid
document_id: Ulid
}
/**
 * Corps de la route directe POST /invoices/{id}/refunds : même charge + expected_version (ledger_version dérivée : 1 + paiements confirmés + contre-écritures + avoirs émis + remboursements (contrat 23).) ; en-tête Idempotency-Key obligatoire.
 */
export interface RefundCreditDirectRequest {
expected_version: Version
invoice_id: Ulid
refund_id: Ulid
credit_note_id: Ulid
number_usage_id: Ulid
/**
 * Numéro central attribué par le serveur sous verrou : null dans la requête directe.
 */
number: null
amount: PositiveMoneyXaf
method: RefundsMethod
reference: (string | null)
approval_id: Ulid
effective_at: Instant
cash_session_id: NullableUlid
document_id: Ulid
}
export interface RegisterPaymentPayload {
invoice_id: Ulid
payment_id: Ulid
receipt_id: Ulid
receipt_number_usage_id: Ulid
receipt_number: string
amount: PositiveMoneyXaf
currency: Currency
method: PaymentsMethod
reference: (string | null)
effective_at: Instant
cash_session_id: NullableUlid
retroactive_reason: (string | null)
receipt_document_id: Ulid
}
/**
 * Corps de la route directe POST /invoices/{id}/payments : même charge + expected_version (ledger_version dérivée : 1 + paiements confirmés + contre-écritures + avoirs émis + remboursements (contrat 23).) ; en-tête Idempotency-Key obligatoire.
 */
export interface RegisterPaymentDirectRequest {
expected_version: Version
invoice_id: Ulid
payment_id: Ulid
receipt_id: Ulid
receipt_number_usage_id: Ulid
/**
 * Numéro central attribué par le serveur sous verrou : null dans la requête directe.
 */
receipt_number: null
amount: PositiveMoneyXaf
currency: Currency
method: PaymentsMethod
reference: (string | null)
effective_at: Instant
cash_session_id: NullableUlid
retroactive_reason: (string | null)
receipt_document_id: Ulid
}
export interface RegisterPhysicalReturnPayload {
physical_return_id: Ulid
delivery_line_id: Ulid
item_id: Ulid
quantity: Quantity
condition_code: PhysicalReturnsConditionCode
accepted_for_restock: boolean
approved_by: Ulid
occurred_at: Instant
reason: Reason
credit_note_id: NullableUlid
stock_movement_id: NullableUlid
}
/**
 * Corps de la route directe POST /deliveries/{id}/physical-returns : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface RegisterPhysicalReturnDirectRequest {
expected_version: null
physical_return_id: Ulid
delivery_line_id: Ulid
item_id: Ulid
quantity: Quantity
condition_code: PhysicalReturnsConditionCode
accepted_for_restock: boolean
approved_by: Ulid
occurred_at: Instant
reason: Reason
credit_note_id: NullableUlid
stock_movement_id: NullableUlid
}
export interface RegisterStockMovementPayload {
movement_id: Ulid
item_id: Ulid
position_id: Ulid
lot_id: NullableUlid
kind: ("BREAKAGE" | "ADJUSTMENT")
signed_quantity: SignedQuantity
unit_cost: (MoneyXaf | null)
approval_id: Ulid
reason: Reason
occurred_at: Instant
}
/**
 * Corps de la route directe POST /stock/movements : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface RegisterStockMovementDirectRequest {
expected_version: null
movement_id: Ulid
item_id: Ulid
position_id: Ulid
lot_id: NullableUlid
kind: ("BREAKAGE" | "ADJUSTMENT")
signed_quantity: SignedQuantity
unit_cost: (MoneyXaf | null)
approval_id: Ulid
reason: Reason
occurred_at: Instant
}
export interface RegisterVisitPayload {
visit_id: Ulid
patient_id: Ulid
reason: VisitsReason
occurred_at: Instant
appointment_id: NullableUlid
administrative_note: (string | null)
}
/**
 * Corps de la route directe POST /visits : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface RegisterVisitDirectRequest {
expected_version: null
visit_id: Ulid
patient_id: Ulid
reason: VisitsReason
occurred_at: Instant
appointment_id: NullableUlid
administrative_note: (string | null)
}
export interface RequestApprovalPayload {
approval_id: Ulid
action_code: PermissionCode
target_type: string
target_id: Ulid
payload_hash: Sha256Hex
reason: Reason
expires_at: NullableInstant
}
/**
 * Corps de la route directe POST /approvals : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface RequestApprovalDirectRequest {
expected_version: null
approval_id: Ulid
action_code: PermissionCode
target_type: string
target_id: Ulid
payload_hash: Sha256Hex
reason: Reason
expires_at: NullableInstant
}
export interface ReversePaymentPayload {
invoice_id: Ulid
payment_id: Ulid
reversal_id: Ulid
amount: PositiveMoneyXaf
reason: Reason
approval_id: Ulid
}
/**
 * Corps de la route directe POST /payments/{id}/reversals : même charge + expected_version (ledger_version dérivée : 1 + paiements confirmés + contre-écritures + avoirs émis + remboursements (contrat 23).) ; en-tête Idempotency-Key obligatoire.
 */
export interface ReversePaymentDirectRequest {
expected_version: Version
invoice_id: Ulid
payment_id: Ulid
reversal_id: Ulid
amount: PositiveMoneyXaf
reason: Reason
approval_id: Ulid
}
export interface SaveClinicalStepPayload {
consultation_id: Ulid
step: {
step: StepName
data: {

}
}
}
/**
 * Corps de la route directe PATCH /consultations/{id}/steps/{step} : même charge + expected_version (Version de l'agrégat (colonne version).) ; en-tête Idempotency-Key obligatoire.
 */
export interface SaveClinicalStepDirectRequest {
expected_version: Version
consultation_id: Ulid
step: {
step: StepName
data: {

}
}
}
export interface StartConsultationPayload {
consultation_id: Ulid
visit_id: Ulid
patient_id: Ulid
effective_at: Instant
date_change_reason: (string | null)
exam_invoice_id: NullableUlid
exam_waiver_approval_id: NullableUlid
}
/**
 * Corps de la route directe POST /visits/{id}/consultations : même charge + expected_version (Création : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface StartConsultationDirectRequest {
expected_version: null
consultation_id: Ulid
visit_id: Ulid
patient_id: Ulid
effective_at: Instant
date_change_reason: (string | null)
exam_invoice_id: NullableUlid
exam_waiver_approval_id: NullableUlid
}
export interface UpdateDraftPayload {
invoice_id: Ulid
visit_id: NullableUlid
due_date: NullableCivilDate
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Ligne de brouillon : prix TTC unitaire, remise TTC ; total de ligne recalculé (ROUND(quantité × prix) − remise).
 */
lines: {
line_id: Ulid
position: number
catalog_item_id: NullableUlid
description: string
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
prescription_id: NullableUlid
}[]
}
/**
 * Corps de la route directe PATCH /invoices/{id} : même charge + expected_version (Version du brouillon (invoices.version), figée à l'émission.) ; en-tête Idempotency-Key obligatoire.
 */
export interface UpdateDraftDirectRequest {
expected_version: Version
invoice_id: Ulid
visit_id: NullableUlid
due_date: NullableCivilDate
/**
 * @minItems 0
 * @maxItems 200
 * 
 * Items: Ligne de brouillon : prix TTC unitaire, remise TTC ; total de ligne recalculé (ROUND(quantité × prix) − remise).
 */
lines: {
line_id: Ulid
position: number
catalog_item_id: NullableUlid
description: string
quantity: Quantity
unit_price_gross: MoneyXaf
discount_gross: MoneyXaf
prescription_id: NullableUlid
}[]
}
export interface UpdatePatientPayload {
patient_id: Ulid
changes: {
last_name?: string
first_names?: (string | null)
sex?: PatientRecordsSex
birth_date?: NullableCivilDate
birth_date_missing_reason?: (string | null)
phone_primary?: (Phone | null)
phone_secondary?: (Phone | null)
phone_missing_reason?: (string | null)
address?: (string | null)
profession?: (string | null)
guardian_contact?: ({

} | null)
}
}
/**
 * Corps de la route directe PATCH /patients/{id} : même charge + expected_version (Version de l'agrégat (colonne version).) ; en-tête Idempotency-Key obligatoire.
 */
export interface UpdatePatientDirectRequest {
expected_version: Version
patient_id: Ulid
changes: {
last_name?: string
first_names?: (string | null)
sex?: PatientRecordsSex
birth_date?: NullableCivilDate
birth_date_missing_reason?: (string | null)
phone_primary?: (Phone | null)
phone_secondary?: (Phone | null)
phone_missing_reason?: (string | null)
address?: (string | null)
profession?: (string | null)
guardian_contact?: ({

} | null)
}
}
export interface ValidatePrescriptionPayload {
prescription_id: Ulid
document_id: Ulid
}
/**
 * Corps de la route directe POST /prescriptions/{id}/validate : même charge + expected_version (Transition d'état contrôlée sous verrou (statut attendu vérifié par le serveur) ; la table n'a pas de colonne version, que le contrat 23 interdit d'inventer : expected_version vaut null.) ; en-tête Idempotency-Key obligatoire.
 */
export interface ValidatePrescriptionDirectRequest {
expected_version: null
prescription_id: Ulid
document_id: Ulid
}
export interface AppointmentCancelledEventPayload {
appointment_id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
starts_at: Instant
cancelled_at: Instant
}
export interface AppointmentConfirmedEventPayload {
appointment_id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
resource_id: Ulid
service_id: Ulid
starts_at: Instant
ends_at: Instant
source: AppointmentsSource
}
export interface AppointmentRescheduledEventPayload {
appointment_id: Ulid
patient_id: NullableUlid
prospect_contact_id: NullableUlid
resource_id: Ulid
previous_starts_at: Instant
starts_at: Instant
ends_at: Instant
}
export interface ConsultationCompletedEventPayload {
consultation_id: Ulid
patient_id: Ulid
visit_id: Ulid
revision_id: Ulid
revision_no: number
completed_at: Instant
}
export interface CreditNoteIssuedEventPayload {
credit_note_id: Ulid
invoice_id: Ulid
number: string
amount_gross: PositiveMoneyXaf
currency: Currency
issued_at: Instant
}
export interface DeliveryConfirmedEventPayload {
delivery_id: Ulid
invoice_id: Ulid
delivered_at: Instant
line_count: number
}
export interface FollowUpScheduledEventPayload {
follow_up_plan_id: Ulid
patient_id: Ulid
consultation_id: Ulid
recommended_date: CivilDate
reminder_enabled: boolean
preferred_channel: FollowUpPlansPreferredChannel
}
export interface InvoiceIssuedEventPayload {
invoice_id: Ulid
patient_id: Ulid
number: string
issued_at: Instant
total_gross: MoneyXaf
currency: Currency
source_device_id: NullableUlid
}
export interface PatientCreatedEventPayload {
patient_id: Ulid
record_number: string
legacy_record: boolean
source_device_id: NullableUlid
}
export interface PaymentConfirmedEventPayload {
payment_id: Ulid
invoice_id: Ulid
receipt_id: Ulid
receipt_number: string
amount: PositiveMoneyXaf
currency: Currency
method: PaymentsMethod
effective_at: Instant
}
export interface PrescriptionValidatedEventPayload {
prescription_id: Ulid
patient_id: Ulid
consultation_id: NullableUlid
origin: OpticalPrescriptionsOrigin
version_no: number
validated_at: Instant
document_id: NullableUlid
}
export interface StockThresholdCrossedEventPayload {
stock_alert_id: Ulid
item_id: Ulid
location_id: Ulid
lot_id: NullableUlid
kind: StockAlertsKind
detected_at: Instant
due_on: NullableCivilDate
}
export interface WorkshopReadyEventPayload {
workshop_order_id: Ulid
patient_id: Ulid
invoice_line_id: Ulid
ready_revision: number
ready_at: Instant
}
export interface EventEnvelope {
event_id: Ulid
event_type: EventType
event_version: number
clinic_id: Ulid
occurred_at: Instant
aggregate_type: string
aggregate_id: Ulid
aggregate_version: Version
correlation_id: string
payload: {

}
}
export interface ClinicalRevisionSnapshot {
consultation_id: Ulid
revision_no: number
recorded_at: Instant
author: {
user_id: Ulid
display_name: string
}
patient: {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
visit_id: Ulid
effective_at: Instant
steps: AllSteps
finalization_missing_data_reason: (string | null)
previous_revision_id: NullableUlid
amendment_reason: (string | null)
}
export interface CreditNoteSnapshot {
credit_note_id: Ulid
number: string
issued_at: Instant
invoice: {
invoice_id: Ulid
number: string
issued_at: Instant
total_gross: MoneyXaf
}
patient: {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
/**
 * @minItems 1
 */
lines: [{
invoice_line_id: Ulid
description: string
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
tax_amount: (MoneyXaf | null)
}, ...({
invoice_line_id: Ulid
description: string
quantity: (Quantity | null)
amount_gross: PositiveMoneyXaf
tax_amount: (MoneyXaf | null)
})[]]
amount_gross: PositiveMoneyXaf
reason: string
issued_by: {
user_id: Ulid
display_name: string
}
approval_id: Ulid
/**
 * Régime fiscal figé : aucune taxe inventée ; sans règle validée, NO_TAX_CONFIGURED et liste vide.
 */
tax: {
regime: ("NO_TAX_CONFIGURED" | "VALIDATED_RULES")
/**
 * @minItems 0
 */
rules: {
tax_rule_id: Ulid
code: string
label: string
rate_percent: (Rate | null)
validated_at: NullableInstant
legal_note: (string | null)
}[]
}
/**
 * En-tête du cabinet au moment de l'émission ; test_document = true tant que production_documents_enabled est faux (document TEST clairement marqué, fichier 23).
 */
clinic: {
clinic_id: Ulid
name: string
address: (string | null)
phone: (string | null)
email: (string | null)
tax_identifier: (string | null)
professional_mentions: (string | null)
document_settings: ({

} | null)
currency: Currency
timezone: string
test_document: boolean
}
template_version: string
}
export interface DeliveryPatientSnapshot {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
export interface InvoiceClinicSnapshot {
clinic_id: Ulid
name: string
address: (string | null)
phone: (string | null)
email: (string | null)
tax_identifier: (string | null)
professional_mentions: (string | null)
document_settings: ({

} | null)
currency: Currency
timezone: string
test_document: boolean
}
export interface InvoicePatientSnapshot {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
export interface InvoiceTaxSnapshot {
regime: ("NO_TAX_CONFIGURED" | "VALIDATED_RULES")
/**
 * @minItems 0
 */
rules: {
tax_rule_id: Ulid
code: string
label: string
rate_percent: (Rate | null)
validated_at: NullableInstant
legal_note: (string | null)
}[]
}
export interface ReceiptSnapshot {
receipt_id: Ulid
receipt_number: string
issued_at: Instant
effective_at: Instant
invoice: {
invoice_id: Ulid
number: string
issued_at: Instant
total_gross: MoneyXaf
}
patient: {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
payment: {
payment_id: Ulid
amount: PositiveMoneyXaf
currency: Currency
method: PaymentsMethod
reference: (string | null)
}
paid_net_before: MoneyXaf
paid_net_after: MoneyXaf
balance_after: SignedMoneyXaf
/**
 * Calculée : BALANCE_SETTLEMENT si le reste après ce paiement est nul, sinon DEPOSIT (un encaissement ne dépasse jamais le solde).
 */
kind: ("DEPOSIT" | "BALANCE_SETTLEMENT")
cashier: {
user_id: Ulid
display_name: string
}
/**
 * En-tête du cabinet au moment de l'émission ; test_document = true tant que production_documents_enabled est faux (document TEST clairement marqué, fichier 23).
 */
clinic: {
clinic_id: Ulid
name: string
address: (string | null)
phone: (string | null)
email: (string | null)
tax_identifier: (string | null)
professional_mentions: (string | null)
document_settings: ({

} | null)
currency: Currency
timezone: string
test_document: boolean
}
template_version: string
}
export interface RefundSnapshot {
refund_id: Ulid
number: string
effective_at: Instant
credit_note: {
credit_note_id: Ulid
number: string
}
invoice: {
invoice_id: Ulid
number: string
issued_at: Instant
total_gross: MoneyXaf
}
beneficiary: {
patient_id: Ulid
record_number: string
last_name: string
first_names: (string | null)
}
amount: PositiveMoneyXaf
currency: Currency
method: RefundsMethod
reference: (string | null)
created_by: {
user_id: Ulid
display_name: string
}
/**
 * En-tête du cabinet au moment de l'émission ; test_document = true tant que production_documents_enabled est faux (document TEST clairement marqué, fichier 23).
 */
clinic: {
clinic_id: Ulid
name: string
address: (string | null)
phone: (string | null)
email: (string | null)
tax_identifier: (string | null)
professional_mentions: (string | null)
document_settings: ({

} | null)
currency: Currency
timezone: string
test_document: boolean
}
template_version: string
}
