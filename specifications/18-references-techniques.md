# Références techniques officielles

Consultation le 14 octobre 2026. Ces sources vérifient des capacités et contraintes techniques ; elles n’élargissent pas le périmètre commercial. Revérifier les versions exactes au démarrage, verrouiller les dépendances et exécuter les tests sur l’offre d’hébergement réelle.

| Sujet | Source officielle | Usage dans le socle |
| --- | --- | --- |
| Laravel 13 | [Déploiement](https://laravel.com/docs/13.x/deployment) | PHP ≥8.3, extensions, racine `public/`, debug et permissions |
| Migrations Laravel | [Migrations 13](https://laravel.com/framework/docs/13.x/migrations) | Installation/adoption du schéma et limites du schema dump sur hébergement sans client SQL |
| Sessions SPA | [Sanctum 13](https://laravel.com/framework/docs/13.x/sanctum) | Auth par cookie/session, CSRF et Policies, distincte d’un token durable |
| Scheduler | [Planification Laravel](https://laravel.com/docs/13.x/scheduling) | Cron/planification et non-chevauchement selon offre web |
| SQL | [Verrous MySQL 8.4 InnoDB](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) | Verrous transactionnels sur soldes et allocations centrales |
| Contraintes SQL | [CHECK MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html) | Contraintes de ligne, limites sur agrégats et expressions |
| Relations SQL | [Foreign keys MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html) | Clés composites uniques, colonnes générées et restriction des suppressions |
| Vue | [Guide Vue 3](https://vuejs.org/guide/introduction.html) | Interface SPA composantisée et TypeScript |
| PWA | [Guide Vite PWA](https://vite-pwa-org.netlify.app/guide/) | Build et service worker, stratégies de mise à jour |
| IndexedDB | [Transactions Dexie](https://dexie.org/docs/Dexie/Dexie.transaction()) | Transactions locales et pièges des opérations asynchrones |
| Canonicalisation | [RFC 8785 JCS](https://www.rfc-editor.org/info/rfc8785/) | Octets JSON canoniques identiques PHP/TS avant hash |
| Signature | [RFC 7518 JWA](https://www.rfc-editor.org/info/rfc7518/) | ES256 et format JWS de longueur fixe |
| Crypto navigateur | [Web Crypto](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API) | Primitives de signature/chiffrement/enveloppement, contexte sécurisé |
| Stockage navigateur | [Persistent storage](https://web.dev/articles/persistent-storage) | Persistance demandée sans garantie de sauvegarde |
| PDF client | [jsPDF projet officiel](https://github.com/parallax/jsPDF) | Génération locale, fontes et PDF sans appel serveur |
| PDF serveur optionnel | [Dompdf projet officiel](https://github.com/dompdf/dompdf) | Rapports ultérieurs ; restrictions modèles/ressources |
| WhatsApp | [Politique officielle](https://business.whatsapp.com/policy) | Templates/fenêtre service, consentements, transfert humain et qualité |
| Google Calendar | [Synchronisation](https://developers.google.com/workspace/calendar/api/guides/sync) | Tokens, pagination et reprise de token invalide |
| Google Calendar push | [Notifications](https://developers.google.com/workspace/calendar/api/guides/push) | Renouvellement des canaux et resynchronisation |
| Kommo | [OAuth 2.0](https://developers.kommo.com/docs/oauth-20) | Autorisation et accès à l’API |
| Kommo événements | [Webhooks](https://developers.kommo.com/docs/webhooks-general) | Événements et offre à vérifier ; ne pas présumer une signature universelle |

Le protocole offline du fichier 19 est une conception proposée pour ce projet : Dexie/PWA ne fournissent pas automatiquement synchronisation, chiffrement, numéros disjoints ou exclusivité de facture. Ces règles doivent être implémentées et testées. La signature des commandes n’est pas une signature médicale ou fiscale certifiée.
