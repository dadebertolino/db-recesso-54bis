# DB Recesso 54-bis

Funzione digitale di recesso per WooCommerce, conforme all'**art. 54-bis del Codice del Consumo** (D.Lgs. 209/2025, recepimento Direttiva UE 2023/2673), obbligatoria dal **19 giugno 2026** per l'e-commerce B2C.

Self-contained, gratuito, senza nag né telemetria, installabile in 30 secondi. Privacy-first, WCAG 2.1 AA, HPOS-compatible.

| | |
|---|---|
| Versione | 1.2.1 |
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
- **DSAR**: export dei record via `dbph_user_data_exporters` (contratto `label` + `callback($email,$page)` → `array('data'=>[], 'done'=>bool)`) e fallback WP core. L'erasure dichiara l'**eccezione probatoria** (art. 17 GDPR) restituendo `items_removed=false, items_retained=true` invece di cancellare.
- **Registro trattamenti**: attività dichiarata via `dbph_processing_register` (schema `id`, `label`, `status`, `purpose`, `legal_basis`, `data_collected`, `retention`, `transfers`).

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
- **v1.2.1** — Compatibilità PHP 7.4+ (abbassato il requisito minimo). *(corrente)*

## Disinstallazione

I dati sono **conservati** di default (valore probatorio). Per una cancellazione completa, definire in `wp-config.php`:

```php
define( 'DBR54_DELETE_DATA_ON_UNINSTALL', true );
```
