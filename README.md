# DB Recesso 54-bis

Funzione digitale di recesso per WooCommerce, conforme all'**art. 54-bis del Codice del Consumo** (D.Lgs. 209/2025, recepimento Direttiva UE 2023/2673), obbligatoria dal **19 giugno 2026** per l'e-commerce B2C.

Self-contained, gratuito, senza nag né telemetria, installabile in 30 secondi. Privacy-first, WCAG 2.1 AA, HPOS-compatible.

| | |
|---|---|
| Versione | 1.3.0 |
| Autore | Davide Bertolino |
| Licenza | GPL v2 or later |
| WP minimo | 5.8+ |
| PHP minimo | 7.4+ |
| WooCommerce | 7.0+ (HPOS) |

## Due moduli indipendenti

Il plugin contiene due istituti **giuridicamente distinti**, attivabili separatamente dalle impostazioni e tenuti separati nel flusso, nell'interfaccia e nel modello dati:

- **Modulo A — Recesso (art. 54-bis)**: ripensamento del consumatore, finestra di 14 giorni. Attivo di default.
- **Modulo B — Garanzia legale di conformità (art. 128-135)**: prodotto difettoso o non conforme. Off di default.

## Cosa fa

- **Pulsante di recesso** ("Recedere dal contratto qui") accanto a ogni ordine idoneo in *Il mio account → Ordini*, ed endpoint dedicato `/my-account/recesso/`.
- **Dichiarazione guidata** a due step con conferma, senza dark pattern.
- **Accesso ospite** via numero ordine + email (shortcode `[dbr54_recesso_guest]`).
- **Certificazione data/ora** di ricezione (timestamp server con fuso esplicito).
- **Ricevuta su supporto durevole** (PDF con hash SHA-256 dello stato ordine), inviata via email al cliente e all'amministratore.
- **Eccezioni art. 59** gestite per prodotto o categoria, con informativa (mai blocco silenzioso).
- **Gestione stati** recesso (ricevuto / evaso / rimborsato). Nessun rimborso automatico.
- **Integrazione privacy**: DB Privacy Hub + fallback DSAR di WordPress core.

## Timestamp probatorio — due livelli

- **Base (default)**: 100% self-contained. Ora server + fuso + SHA-256 dello stato ordine. Zero dipendenze esterne.
- **Rafforzato (opt-in)**: marca temporale qualificata RFC 3161 applicata all'hash. Introduce una dipendenza esterna (server TSA); il recesso funziona comunque senza. Attivabile dalle impostazioni, con avviso sul trade-off.

## Privacy by design

- **Basi giuridiche**: art. 6.1.b (esecuzione contratto) e 6.1.c (obbligo legale). **Nessun consenso**, nessun cookie/tracker.
- **Minimizzazione**: la tabella `wp_dbr54_recessi` referenzia l'ordine, non ne duplica i dati personali. Il motivo del recesso è sempre **facoltativo**.
- **DSAR (doppio canale)**: con DB Privacy Hub l'exporter/eraser è registrato via `dbph_user_data_exporters` / `dbph_user_data_erasers` (contratto `label` + `callback($email,$page)`); senza Hub via `wp_privacy_personal_data_exporters` / `_erasers` (fallback disattivato se `DBPH_DSAR` esiste, niente doppia registrazione). Entrambi i canali usano le stesse callback in formato WP core (`data` + `done`). L'erasure dichiara l'**eccezione probatoria** (art. 17.3.e GDPR) restituendo `items_removed=false, items_retained=true` invece di cancellare.
- **Retention applicata**: un cron giornaliero (`dbr54_retention_purge`) cancella recessi e pratiche di garanzia con `received_at` più vecchio di *Conservazione ricevute (anni)*, insieme alle ricevute PDF. Valore 0/vuoto = nessuna cancellazione.
- **Registro trattamenti**: attività dichiarata via `dbph_processing_register` (+ legacy `dbseo_processing_register`; schema `id`, `label`, `status`, `purpose`, `legal_basis`, `data_collected`, `retention`, `transfers`).
- **Marker `DBR54_DSAR_AVAILABLE`**: letto dal generatore di Privacy Policy del Hub per citare la procedura DSAR.

## Installazione

1. Carica lo ZIP da *Plugin → Aggiungi nuovo → Carica plugin*, oppure copia la cartella in `wp-content/plugins/`.
2. Attiva. La tabella e l'endpoint vengono creati automaticamente.
3. Configura da *Recessi → Impostazioni* (etichetta, stati idonei, categorie escluse, retention, timestamp).
4. Per l'accesso ospiti, inserisci lo shortcode `[dbr54_recesso_guest]` in una pagina pubblica.

## Aggiornamenti

Automatici da GitHub Releases (`dadebertolino/db-recesso-54bis`) tramite `DB_GitHub_Updater`, direttamente nel pannello WordPress.

## Cosa NON fa (lavoro legale separato)

Il plugin fornisce **solo la funzione digitale**. **Non** sostituisce:

- l'informativa precontrattuale (art. 49);
- il modulo tipo Allegato I-B;
- le condizioni generali di vendita.

Questi vanno aggiornati separatamente per citare esistenza e collocazione della funzione. Per il rischio del proprio caso, consultare un legale esperto di diritto del consumo.

## Modulo B — Garanzia legale (art. 128-135)

Attivabile in modo indipendente. Gestisce una pratica RMA leggera per prodotti difettosi o non conformi:

- Endpoint dedicato `/my-account/garanzia/` + pulsante distinto sugli ordini idonei; accesso ospite via shortcode `[dbr54_garanzia_guest]`.
- Form: selezione prodotto, descrizione testuale del difetto (obbligatoria), preferenza di rimedio **puramente informativa** (riparazione/sostituzione/nessuna) — la scelta finale spetta al venditore secondo legge.
- Nessun upload file (solo descrizione testuale, coerente con minimizzazione).
- **Nessun limite temporale automatico**: il venditore valuta se la pratica rientra nella garanzia legale (art. 133).
- Ricevuta PDF su supporto durevole con hash SHA-256, email a cliente e admin.
- Gestione pratica in admin: stati (aperta / in lavorazione / accolta / respinta / chiusa) + nota interna sul rimedio deciso.
- Etichettatura e flusso separati dal recesso, per non confondere ripensamento e garanzia.

## Roadmap

- **v1.0.0** — Modulo A (recesso), timestamp base, Privacy Hub, ricevuta PDF, email, admin.
- **v1.1.0** — Timestamp rafforzato RFC 3161 opt-in.
- **v1.2.0** — Modulo B: reso in garanzia legale di conformità (artt. 128-135), modulo separato.
- **v1.2.1** — Compatibilità PHP 7.4+ (abbassato il requisito minimo).
- **v1.3.0** — Fix DSAR con Privacy Hub, retention applicata, form ospite compatibili con la cache di pagina. *(corrente)*

## Changelog

### 1.3.0 — DSAR via Privacy Hub, retention applicata, form ospite cache-safe

**DSAR (artt. 15/17 GDPR):**
- *Critico*: con DB Privacy Hub attivo l'export falliva per **tutti** i plugin ("Expected data in response array"): la callback registrata sul Hub restituiva gli item senza `data`/`done` e, avendo lo stesso slug, sovrascriveva quella core. Ora il canale Hub punta alle callback core (`data` + `done`; eraser con `items_removed` / `items_retained` / `messages` / `done`).
- Il fallback `wp_privacy_personal_data_*` si disattiva se `DBPH_DSAR` esiste.
- L'erasure continua a **conservare** i record (eccezione art. 17.3.e) e lo dichiara nei messaggi.

**Retention (art. 5.1.e GDPR):**
- Nuovo cron giornaliero `dbr54_retention_purge` (schedulato in modo idempotente su `init`, rimosso alla disattivazione e alla disinstallazione): cancella da `wp_dbr54_recessi` e `wp_dbr54_garanzia` i record con `received_at` (UTC) più vecchio di `retention_years`, eliminando anche il PDF della ricevuta. A lotti (max 10.000 righe per tabella per esecuzione). Retention 0/vuota = conservazione illimitata.

**Form ospite `[dbr54_recesso_guest]` / `[dbr54_garanzia_guest]`:**
- Nessun nonce nell'HTML pubblico per i visitatori anonimi: con la cache di pagina il nonce scadeva dopo 12–24h e `check_admin_referer()` bloccava il recesso con "Il link che hai seguito è scaduto". Per gli anonimi ora: controllo same-site su `Origin`/`Referer` + rate limit per IP (hash salato via transient, mai in chiaro) che impedisce anche l'enumerazione numero ordine + email. Filtri `dbr54_guest_rate_limit` (default 10) e `dbr54_guest_rate_window` (default 900 s). Utenti loggati: nonce invariato.
- Pagine con gli shortcode escluse dalla cache (`DONOTCACHEPAGE` + `nocache_headers()`).
- Gli errori (sessione scaduta, origine non valida, troppi tentativi, autorizzazione) sono mostrati all'utente come avviso, non più con `wp_die()`.
- Fix: il passaggio step 1 → step 2 del recesso ospite tornava al modulo di ricerca, impedendo la conferma.

**Registro trattamenti:** aggiunto hook legacy `dbseo_processing_register`; il testo di retention cita la cancellazione automatica. Aggiunti blocco "Privacy capabilities" nell'header e costante `DBR54_DSAR_AVAILABLE`.

Nessuna modifica di schema.

## Disinstallazione

I dati sono **conservati** di default (valore probatorio). Per una cancellazione completa, definire in `wp-config.php`:

```php
define( 'DBR54_DELETE_DATA_ON_UNINSTALL', true );
```
