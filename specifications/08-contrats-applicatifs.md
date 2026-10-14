# Contrats applicatifs et API

## Principes

Ces contrats définissent les actions que Claude doit implémenter. La PWA Vue utilise l’API Laravel authentifiée et le protocole de synchronisation du fichier 19. Chaque endpoint réutilise une action et sa Policy. Une commande hors-ligne emploie les mêmes champs métier, encapsulés dans un journal signé et rejoués via `/api/v1/sync/push` ; ne pas rejouer aveuglément les requêtes HTTP d’écran.

Session authentifiée, CSRF, autorisation de ressource et validation serveur pour le back-office. Intégrations : identité de service et permissions limitées, pas le compte gérante. Réservation publique : aucun token de back-office, protection abuse et réponse limitée. Réponses paginées ; détails cliniques jamais intégrés aux réponses de caisse.

En lecture, timestamps ISO 8601 UTC avec fuseau affichage transmis séparément ; dates civiles `YYYY-MM-DD`. Montants entiers avec monnaie ; valeurs optiques/quantités décimales comme chaînes exactes. Mutation critique avec `Idempotency-Key` et version attendue. Émission centrale : numéro serveur ; émission locale : numéro dans la plage signée, validé par le serveur. Auteur lié au compte/grant, jamais accepté aveuglément du payload.

## Actions L0

Les [contrats d’ingénierie 23](23-contrats-ingenierie.md) fixent la matérialisation OpenAPI/JSON Schema et les champs des commandes locales. `expected_version` est la version du brouillon pour son édition/émission et le `ledger_version` dérivé pour une mutation financière après émission ; `invoices.version` n’est jamais modifié après émission. Les réponses nomment distinctement `draft_version` et `ledger_version`.

| Action de domaine | Endpoint cible | Règles essentielles |
| --- | --- | --- |
| SearchPatients | `GET /api/v1/patients?query=...` | Identité administrative autorisée, pagination |
| CreatePatient / UpdatePatient | `POST /patients`, `PATCH /patients/{id}` | Numéro central ou plage locale valide ; version attendue |
| RegisterVisit | `POST /visits` | Patient, motif et heure ; achat direct autorisé |
| StartConsultation | `POST /visits/{id}/consultations` | Permission clinique ; frais payés/dérogation ; idempotence |
| SaveClinicalStep | `PATCH /consultations/{id}/steps/{step}` | Version attendue ; champs autorisés ; audit |
| CompleteConsultation | `POST /consultations/{id}/complete` | Validation clinique et snapshot complet |
| AmendConsultation | `POST /consultations/{id}/amendments` | Motif, professionnel autorisé, nouvelle version |
| ValidatePrescription | `POST /prescriptions/{id}/validate` | Valeurs explicites, auteur, version immuable |
| SearchCatalog | `GET /catalog?query=...` | Prix de vente ; prix d’achat seulement gérante |
| CreateInvoiceDraft / UpdateDraft | `POST /invoices`, `PATCH /invoices/{id}` | Patient, lignes et version ; droits prix/remise |
| IssueInvoice | `POST /invoices/{id}/issue` | Numéro, snapshot, réservations en transaction |
| RegisterPayment | `POST /invoices/{id}/payments` | Solde verrouillé, reçu au commit |
| RequestApproval / DecideApproval | `POST /approvals`, `POST /approvals/{id}/decision` | Contenu exact ; décision gérante réauthentifiée |
| IssueCreditNote | `POST /invoices/{id}/credit-notes` | Lignes d’origine, limites, approbation |
| ReversePayment | `POST /payments/{id}/reversals` | Erreur comptable expliquée, preuve et approbation |
| RefundCredit | `POST /invoices/{id}/refunds` | Crédit disponible et approbation ; pièce numérotée |
| ConfirmDelivery | `POST /invoices/{id}/deliveries` | Quantités non remises, lots, approbation si dette |
| RegisterStockMovement | `POST /stock/movements` | Origine/motif, quantité, approbation hors vente |
| CreateFollowUp / CreateAppointment | `POST /follow-ups`, `POST /appointments` | Date/canal ; disponibilité et version |
| RescheduleAppointment | `POST /appointments/{id}/reschedule` | Réservation concurrente, invalidation rappels anciens |
| RecordContactAttempt | `POST /contact-tasks/{id}/attempts` | Historique et prochain état explicite |
| GetDocument | `GET /documents/{id}/download` | Policy du document ; stockage privé |
| FinancialDashboard | `GET /reports/financial?from=...&to=...` | Gérante uniquement ; CA et encaissé distincts |

Le préfixe `/api/v1` s’applique aux routes abrégées du tableau. Une route de lecture patient renvoie le minimum par rôle ; la présence d’un identifiant patient ne donne pas le droit d’ouvrir sa consultation. Les exports et PDF appliquent les mêmes permissions que les écrans.

## Exemple de commande financière

```json
{
  "invoice_id": "ULID_DE_TEST",
  "expected_version": 3,
  "amount": 30000,
  "currency": "XAF",
  "method": "MTN_MOMO",
  "reference": "REFERENCE_FICTIVE",
  "effective_at": "2026-10-14T09:30:00Z"
}
```

La date effective d’un paiement saisi rétroactivement exige droit et motif ; sa date d’enregistrement reste celle du serveur. Réponse confirmée : références paiement/reçu, montant, solde recalculé et état de génération du PDF. Une réponse `document_status=PENDING` n’autorise pas à répéter l’encaissement.

## Erreurs

| Code HTTP | Code métier | Affichage attendu |
| --- | --- | --- |
| 401 | AUTH_REQUIRED | Reconnexion, données non sauvegardées conservées en mémoire |
| 419 | SESSION_CONTEXT_EXPIRED | Conserver saisie/coffre, renouveler session/CSRF ; retry de la même commande après réauthentification |
| 403 | ACTION_FORBIDDEN | Droit insuffisant ; ne pas divulguer les données cibles |
| 404 | RESOURCE_NOT_FOUND | Ressource inexistante ou inaccessible selon politique |
| 409 | VERSION_CONFLICT | Version plus récente à consulter, formulaire conservé |
| 409 | PAYMENT_EXCEEDS_BALANCE | Solde actualisé, aucun paiement créé |
| 409 | STOCK_UNAVAILABLE | Quantité disponible actualisée, pas d’émission partielle silencieuse |
| 409 | IDEMPOTENCY_PAYLOAD_MISMATCH | Clé déjà utilisée avec un contenu différent |
| 409 | SLOT_UNAVAILABLE | Créneau réservé entre-temps ; proposer de nouveaux créneaux |
| 422 | VALIDATION_FAILED | Erreurs par champ, texte saisi conservé |
| 422 | EXAM_PAYMENT_REQUIRED | Proposer caisse ou demande de dérogation |
| 422 | APPROVAL_REQUIRED_OR_EXPIRED | Demander/renouveler l’approbation précise |
| 422 | LOT_EXPIRED | Lot interdit, sélectionner un lot valide |
| 429 | RATE_LIMITED | Attendre et réessayer, sans rejouer un paiement sans clé |
| 503 | DEPENDENCY_UNAVAILABLE | Enregistrer l’échec d’intégration ; ne pas annuler la vente validée |

Format : `error.code`, `error.message` français, `error.fields` éventuel, `correlation_id`. Aucun stack trace ni secret en réponse de production.

## Événements internes

Version 1 des événements `PatientCreated`, `ConsultationCompleted`, `PrescriptionValidated`, `FollowUpScheduled`, `AppointmentConfirmed`, `AppointmentRescheduled`, `AppointmentCancelled`, `InvoiceIssued`, `PaymentConfirmed`, `CreditNoteIssued`, `DeliveryConfirmed`, `StockThresholdCrossed`, `WorkshopReady`.

Enveloppe : `event_id`, `event_type`, `event_version`, `clinic_id`, `occurred_at`, `aggregate_type`, `aggregate_id`, `aggregate_version`, `correlation_id`, `payload` minimal. Un message externe reçoit seulement les champs nécessaires ; pas de snapshot clinique entier dans l’outbox de communication.

## Contrats L2 et L3

`GET /public/v1/services` et `/slots` exposent prestations réservables et disponibilité administrative, sans dossiers ni noms de patients. `POST /public/v1/booking-requests` valide nom/contact/consentement, anti-abus et idempotence, réserve sous verrou et renvoie une référence publique distincte. Pas de déduction publique indiquant qu’un téléphone possède déjà un dossier.

Réception webhooks fournisseurs sur routes séparées : challenge éventuel, validation d’origine selon protocole, taille bornée, déduplication, persistance en inbox avant traitement rapide. Le callback OAuth ne vaut pas authentification de tous les webhooks CRM. Les événements non signés par un fournisseur doivent être réconciliés via son API autorisée avant mutations sensibles ; voir fichier 10.

Outils d’IA limités : `get_public_faq`, `get_public_catalog`, `find_available_slots`, `request_booking`, `request_human_handoff`. Aucune action de facturation, prescription, remboursement ou sortie de stock dans les outils de l’IA.

## Compléments d’implémentation L0

F06 matérialise aussi les créations/lectures de prescriptions, sessions de caisse ouverture/clôture, retour physique, stock initial, comptes/cabinet, préparation/release, conflits et exports/recovery nécessaires aux écrans. Les actions sont nommées dans les fiches 24 et gardent leurs Policies et invariants ; les routes ne contournent pas une action métier.

ACK de sync : ACCEPTED, ALREADY_ACCEPTED (transport d’un ACCEPTED existant), WAITING_DEPENDENCY, CONFLICT_REQUIRES_REVIEW et REJECTED après décision finale documentée. REJECTED ne signifie jamais paiement accepté. Le préfixe du journal finalisé peut progresser après cette décision, sans effacer les originaux ; le fichier 23 fixe les règles et les tests IT11/IT12. Les limites de lots/taille, curseurs, core JCS/JWS et erreurs sont spécifiés en 23, pas improvisés dans chaque endpoint.
