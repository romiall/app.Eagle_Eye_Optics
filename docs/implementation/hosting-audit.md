# Audit d'hébergement (Q01) — checklist à exécuter

Statut : **non réalisé, accès à l'offre non disponible** (F01, 2026-10-14). Ce fichier est la grille à remplir avant H01/G4. Aucune réponse ci-dessous n'est supposée.

## À obtenir de l'hébergeur ou du panneau

| # | Point | Exigence du projet | Réponse |
| --- | --- | --- | --- |
| 1 | Offre / fournisseur / formule | Web simple, **pas de VPS** (D05) | À renseigner |
| 2 | Version PHP | 8.3 minimum (Laravel 13) ; 8.4 cible | À renseigner |
| 3 | Extensions PHP | pdo_mysql, mbstring, openssl, sodium, intl, zip, fileinfo, tokenizer, xml, curl, ctype (bcmath/gmp non requises) | À renseigner |
| 4 | Accès SSH / CLI | Sinon : installation par procédure locale sécurisée (23) | À renseigner |
| 5 | Composer sur le serveur | Sinon : déployer `vendor/` construit en CI | À renseigner |
| 6 | MySQL | 8.4 visé ; 8.0.16+ à tester ; InnoDB, utf8mb4 | À renseigner |
| 7 | Droits base | CREATE, ALTER, INDEX, REFERENCES, CREATE VIEW, **TRIGGER**, DROP (migrations) ; pas de SUPER requis | À renseigner |
| 8 | `log_bin_trust_function_creators` / binlog | Peut bloquer la création des 88 triggers sans SUPER | À renseigner |
| 9 | Sous-domaine HTTPS | `app.eagleeyeoptic.com` proposé (A03) ; certificat valide ; pas de DNS supposé créé | À renseigner |
| 10 | Cron | Au moins une exécution par minute ou 5 min ; sinon pull périodique limité | À renseigner |
| 11 | Workers / queue | Pas de démon permanent : queue base de données traitée par cron court | À renseigner |
| 12 | Dossier racine web | Possibilité de pointer sur `public/` ; stockage privé hors racine | À renseigner |
| 13 | Limites : mémoire, durée d'exécution, taille upload/POST (≥ 2 Mio push, PDF) | Voir 23 (limites push/PDF) | À renseigner |
| 14 | Sauvegardes de l'hébergeur et RPO | A11 : 1 h cible, quotidien minimum | À renseigner |
| 15 | Stockage distant de sauvegarde, restauration, support (Q14) | Avant production | À renseigner |
| 16 | Fuseau / locale | Africa/Douala ; instants UTC en base | À renseigner |
| 17 | HTTPS et en-têtes (CSP, service worker scope) | PWA exige contexte sécurisé | À renseigner |

## Règle de décision

Si l'offre est incompatible, proposer une **offre web simple compatible** (D05 interdit le VPS par défaut) et consigner l'écart au journal et au fichier 15. Un hébergement non vérifié bloque G4 et la mise en production ; il ne bloque ni le développement ni G0–G3 sur données fictives.
