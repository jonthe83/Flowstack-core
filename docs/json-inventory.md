# JSON → SQL (läge i koden)

Inventering av koden på `main` (`f643962d`). Ingen live-databas är läst. Tabellen säger vad koden gör, inte vad som redan är påslaget på flowstack.example.test.

Affärsdata ska ligga i SQL. Inställningar, mallar i git, hemligheter (`data/secrets/`, `privat/`, lösenord, Fortnox-token) och backuper/exporter är undantag och står sist.

## Hur omslaget slås

Två lager. Det första vinner när det är explicit.

| Reglage | Vad det gör |
|---|---|
| `data.migrate_all` | Hook. `dry` utelämnat betyder torrkörning. Kopierar `jeab_migrate_registry()` (insert av saknade nycklar, skriver inte över befintlig SQL). Verify är en torr omkörning med checksumma. Grönt svep utan `dry` sätter inställningen `data_backend=mysql`. |
| `data.backend_set` | `backend=mysql` eller `backend=json`. `json` är den gemensamma rollbacken. Den skriver inte tillbaka SQL till fil. |
| `data.migrate_types` | Bara `inbox_sent_new`, `solkoll`, `inkop_mallar`, `inkorg_views`. Rör inte kontakter och sätter ingen flagga. |
| `STEG4_CUTOVER` | Standard på (`privat/flags.json`, env eller `$GLOBALS`). Av = `0`. |
| `jeab_cutover_sql(typ)` | Sant bara när `STEG4_CUTOVER` är på, `data_backend` är explicit (env, `$GLOBALS` eller inställning) och `jeab_data_storage` säger `sql`. |
| `LEGACY_JSON_WRITES` | Standard av. `jeab_legacy_json_skip` hoppar över filen bara när typen är markerad SQL **och** senaste klara `data.migrate_all` har grön verify för den typen. Annars skrivs filen. Inställningen `legacy_json_writes` läses inte. |
| `authoritative_sql` | `purchases`, `gi_loans` och `biz_docs` är SQL på live MySQL även om `data_backend=json`. Rollback med flaggan når dem inte. |

Egen flagga, läst som legacy tills `data_backend` är explicit: `kontakter_storage`, `inkorg_storage`, `purchases_storage`, `ordrar_deleted_storage`, `gi_loans_storage`, `biz_storage_<namn>`, `store_storage` (bara när drivern är `json`).

`inbox`, `inbox_threads`, `purchases` och aliaset `orders` är **retired** i `jeab_migrate_copy`: svepet hoppar över dem och svarar att SQL är enda källan. Hookarna `inbox.sql_*` och `purchases.sql_*` returnerar samma fel direkt. Koden under `return` körs inte.

CLI: `scripts/migrate-kontakter-sql.php` (`--verify`, `--cutover`, `--rollback`, `--write`). `scripts/migrate-inkorg-sql.php` anropar de avstängda inkorgshookarna.

## Risk

- **låg** — körvägen skriver SQL och lämnar filen när SQL är på. Läsning går till SQL först.
- **medel** — JSON är reserv (tom SQL, saknad PDO, `imported` inte satt) eller rollback är bara en flagga utan export till fil.
- **hög** — verify eller rollback saknas på riktigt, filen skrivs vid sidan av SQL, eller koden läser JSON utan att fråga `jeab_cutover_sql`.

## Registertyper

V = verify, C = cutover, R = rollback. «Svep» = `data.migrate_all` plus `data.backend_set`. «Skip» = filen hoppas över bara via `jeab_legacy_json_skip` (flaggan av och grön verify).

| typ-id | JSON | SQL | Omslag | V | C | R | JSON efter omslag | Direkt JSON utan `jeab_cutover_sql` | Risk |
|---|---|---|---|---|---|---|---|---|---|
| customers | `data/store.json` | `customers` | `store.sql_*` när driver=`json` (`store_storage`). Live mysql/sqlite är redan `JeabPdoStore`. Svepet kopierar också. | `store.sql_verify` och svep | `store.sql_cutover` enable=1 | enable=0 skriver tillbaka `store.json` bara om driver=`json` och flaggan var sql. Live SQL: `rollback=not_applicable` | `store.php` via skip. Live PDO skriver inte filen | `jeab_store_json_snapshot` läser filen i `store.sql_*`, inte i sidläsningen. `jeab_config` sätter bara sökvägen | låg |
| bookings | `data/store.json` | `bookings` | samma som customers | samma | samma | samma | samma | samma fil | låg |
| reminders | `data/store.json` | `reminders` | samma | samma | samma | samma | samma | `jeab_reminders_newest_backup` läser backup, inte källan | låg |
| reminder_lists | `data/store.json` | `reminder_lists` | samma | samma | samma | samma | samma | samma fil | låg |
| purchases | `data/purchases.json` | `purchases` | Redan SQL på live MySQL (`jeab_purchases_mysql_live`). Hook `purchases.sql_*` är retired. SQLite: `purchases_storage` | Nej. Hooken svarar retired. Svepet hoppar över | Nej (retired). Död kod skulle varit no-op `mysql_already_sql` | Nej på live MySQL | Nej. `jeab_purchase_json_write` skriver SQL. Filen bara när PDO saknas och läget inte är sql | `jeab_purchase_json_read` när PDO saknas och storage inte är sql (`purchase.php`) | låg på live MySQL. Hög om filen fortfarande har rader som saknas i SQL: de kopieras inte |
| ordrar_deleted | `data/ordrar-deleted.json` | `json_docs` (`ordrar_deleted`) | Live MySQL utan flagga. SQLite: `ordrar_deleted_storage` via `ordrar_deleted.sql_*` | `ordrar_deleted.sql_verify` (json_only) | enable=1. Live: no-op `mysql_already_sql` | enable=0 skriver filen på SQLite. Live MySQL: nej | Nej när PDO finns (`jeab_ordrar_deleted_save` returnerar efter SQL). Skip på filvägen | `jeab_ordrar_deleted` läser filen om `jeab_sql_doc_get` inte ger en array | låg |
| contacts | `data/kontakter.json` | `contacts` | `kontakter_storage` via `kontakter.sql_cutover` enable=1. CLI `scripts/migrate-kontakter-sql.php`. `data_backend=mysql` tvingar SQL även om flaggan är json | `kontakter.sql_verify` och svep | ja, enable=1 efter grön verify (eller `force`) | enable=0. `LEGACY_JSON_WRITES` av: SQL-snapshot, filen skrivs inte, läget stannar sql. Flaggan på: filen skrivs och läget blir json | Nej när `jeab_kontakter_writes_sql_only`. Påfyllnad av id som bara finns i JSON tills `data_backend=mysql` | `jeab_kontakter_json_file_load` (`kontakt_store.php`) används av påfyllnad och rollback, inte av sidläsning efter mysql-läge | medel |
| inkorg_contacts | `data/inkorg/contacts.json` | `inkorg_contacts` | samma flagga och hook som contacts (`entity`) | samma verify | samma | samma. Snapshot eller `jeab_kontakter_sql_write_inbox` | samma som contacts | `jeab_inkorg_contacts_json_map`; `jeab_inkorg_contacts` om SQL inte används | medel |
| inbox | `data/inkorg/messages.json` | `inkorg_messages` | `inkorg_storage` om `data_backend` inte är satt. Hook `inbox.sql_*` är retired. CLI träffar samma avstängning | Nej (retired). Svepet hoppar över | Nej | Nej. Död kod skulle skriva messages/threads och sätta json | Nej när `jeab_inkorg_use_sql`. Filvägen i `jeab_inkorg_locked` bara om PDO saknas och läget inte är sql | Filläsning i samma lås, bara i JSON-läge | låg om SQL redan är facit. Hög om unika rader bara finns i filen |
| inbox_threads | `data/inkorg/threads.json` | `inkorg_threads` | samma som inbox | samma | samma | samma | samma | samma | samma som inbox |
| inbox_sent | `data/inkorg/sent.json` | `inbox_sent` | Svep. Körvägen `jeab_inbox_sent_record` skriver SQL via `jeab_ensure_inbox_sent` utan `jeab_cutover_sql` | Svep | `data_backend=mysql` | bara flaggan, ingen export | Körvägen skriver inte filen | Modulen läser filen vid svep. Ingen sidläsning av `sent.json` i `lead_source.php` | låg |
| inbox_sent_new | `data/inkorg/sent-new.json` | `inbox_sent_new` | Svep eller `data.migrate_types`. `jeab_inbox_sent_new_usable` | Svep och `data.migrate_types` | `data_backend=mysql` + steg 4 | bara flaggan | Nej när SQL-vägen är brukbar. Annars filen i `jeab_inkorg_send_new` | `jeab_inkorg_send_new` läser `sent-new.json` när SQL inte är brukbart | medel |
| gi_loans | `data/gi/loans.json` | `json_docs` (`gi_loans`) | Live MySQL utan flagga. SQLite: `gi_loans_storage` via `gi_loans.sql_*` | `gi_loans.sql_verify` | enable=1. Live: no-op | enable=0 på SQLite skriver filen. Live MySQL: nej | Nej i SQL-läge | `jeab_gi_loans_json_read` som reserv tills migrering | låg |
| biz_docs | Registret pekar på `data/biz/code_people.json`. Körvägen är `data/code-people.json`, `data/reminder-ort-cache.json`, `data/reminders-prune.json`, `data/webshop-catchup.json` | `json_docs` (`biz_<namn>`) | Live MySQL utan flagga. SQLite: `biz_storage_<namn>` via `biz_doc.sql_*` (`entity`) | `biz_doc.sql_verify` | enable=1. Live: no-op | enable=0 på SQLite. Live: nej | Nej när `jeab_biz_doc_use_sql`. Ortcache: skip om verify inte är grön och SQL-vägen inte togs | `jeab_biz_doc_json_read` i JSON-läge. Migrate-raderna läser körvägens filer när registerfilen saknas | låg. Medel: registerfilen och körfilen är inte samma sökväg |
| notes | `data/notes.json` | `notes` | `notes.import`. Svepet kopierar också. Ingen storage-flagga | Svep. Import har egen dry | SQL så fort PDO finns. `imported` sätts av importen | ingen export till fil | Skrivs inte. Läsning är JSON tills `imported` och om SQL kastar | `jeab_notes_json_read` i `jeab_notes_store_load` | medel |
| notes_state | `data/notes.json` | `notes_state` | samma fil och import | svep | samma | ingen | skrivs inte | samma läsning | medel |
| sms_sent | `data/sms-sent.json` | `sms_sent` | `sms.import` och svep | svep | SQL när PDO finns och `imported` | ingen | skrivs inte | `jeab_sms_json_read` tills imported, och om SQL kastar (`jeab_sms_store_load`) | medel |
| webshop_orders | `data/webshop-orders.json` | `log_webshop_orders` (log_store och migrate) | Live: `jeab_log_webshop_replace` när log-PDO finns. Steg 4-tabellen används bara när log-PDO saknas (`jeab_cutover_log_pdo_missing`) | Svep mot migrate-tabellen, inte mot log_store | log_store på mysql. Steg 4 är reserv | log_store har ingen fil-export. `data_backend=json` tvingar filen | Nej när log_store eller cutover-låset tar skrivningen | `jeab_webshop_index` läser filen om båda SQL-vägarna missar | medel |
| calendar_log | `data/calendar-log.json` | `log_docs` | `logs.import` och `jeab_log_doc_get`. Skip på filen | svep | log_store på mysql | ingen fil-export | skip, annars filen i `bookings.php` | filen om log-dokumentet saknas eller `_JEAB_CALENDAR_LOG_PATH` är satt | medel |
| who_picks | `data/who-picks.json` | `log_who_picks` | `jeab_log_who_pick_put` först. Migrate-tabellen bara när log-PDO saknas | svep | log_store | ingen | filen bara när båda SQL-vägarna returnerar false | `jeab_who_picks_read` läser filen sist | låg |
| who_diag | `data/who-diag.jsonl` | `log_lines` (`who_diag`) | `jeab_log_who_diag_append`, sedan cutover, sedan fil | svep | log_store | ingen | filen sist | filen i `jeab_who_diag_record` när SQL inte tog raden | låg |
| app_errors | `data/app-errors.jsonl` | `log_lines` (`app_error`) | `jeab_log_app_error` / log_store. Svep | svep | log_store på mysql | ingen | filen när SQL-bump inte tar raden (`jeab_log_app_error_bump_file`) | jsonl som reserv | medel |
| fellogg_open_asks | `data/fellogg-open-asks.json` | `log_docs` | `jeab_log_open_asks_read` / `_write` | svep | log_store | ingen | filen om log_store inte skriver | `jeab_fellogg_open_asks_read` läser filen om log-läsningen inte är en array | medel |
| graph_week | `data/graph-week-cache.json` | `log_graph_week` | `jeab_log_graph_week_put` | svep | log_store | ingen | filen bara om SQL-put misslyckas | `jeab_gp_week_events_cache_read`: färsk JSON (90 s) vinner över äldre SQL | medel |
| graph_events | `data/graph-person-events.json` | `log_graph_events` | log_store, svep | svep | log_store | ingen | filen om SQL saknas | filen som reserv | medel |
| graph_state | `data/inkorg/graph-state.json` | `graph_state` | `jeab_graph_state_update` anropar `jeab_cutover_doc_put` när steg 4 är på | svep | `data_backend=mysql` | bara flaggan | Nej i `jeab_graph_state_update`. `jeab_graph_json_write` skulle skriva filen också, men state-uppdateringen går inte den vägen | `jeab_graph_json_read` läser filen om SQL-läsningen inte ger en array | medel |
| graph_daily | `data/inkorg/graph-daily.json` | `graph_daily` | Svep. Schema i `jeab_graph_daily_schedule` skriver bara fil om SQL-skrivningen returnerar false | svep | `data_backend=mysql` | bara flaggan | **Ja.** `jeab_graph_status_remember` anropar `jeab_graph_json_write`, som skriver filen även när SQL-skrivningen lyckas. `LEGACY_JSON_WRITES` styr inte | `jeab_graph_json_read` efter SQL | hög |
| graph_poll | `data/inkorg/graph-poll.json` | `graph_poll` | samma mönster som graph_daily | svep | samma | bara flaggan | **Ja**, samma `jeab_graph_json_write` | samma | hög |
| sent_scan | `data/graph-sent-scan.json` | `sent_scan` | `jeab_cutover_doc_put` i `jeab_auto_graph_scan_cache_put` | svep | `data_backend=mysql` | bara flaggan. Filen blir inaktuell om den inte skrevs | Nej när cutover-put lyckas | Filen om `jeab_cutover_doc_fetch` inte ger en array (`inkorg_auto.php`) | medel |
| login_throttle | `data/login-throttle.json` | `login_throttle` | `jeab_auth_throttle_mutate` tar `jeab_cutover_throttle_mutate` | svep | `data_backend=mysql` | bara flaggan. Misslyckad SQL sätter `_JEAB_THROTTLE_UNAVAILABLE` och kör mutatören mot tomt lager | Nej i SQL-grenen | Filen i `jeab_auth_throttle_mutate` när cutover är av, och i `jeab_cutover_throttle_load` om SQL-läsningen är null | medel |
| external_queue | `data/external-queue.json` | `external_queue_jobs` | `jeab_external_enqueue` / `jeab_external_tick` | svep | `data_backend=mysql` | bara flaggan | Nej i SQL-grenen | `bootstrap.php` läser och skriver filen när cutover är av. Jobb som bara ligger i filen körs inte efter omslaget | medel |
| notiser | `data/notiser/mallar.json` | `notiser_items` | `jeab_notiser_store_write` via `jeab_cutover_locked` | svep | `data_backend=mysql` | bara flaggan | Nej när låset tar skrivningen | `jeab_notiser_store_read` läser filen när cutover är av | medel |
| inkop_mallar | `data/inkop-mallar.json` | `inkop_mallar` | `jeab_inkop_load` / `jeab_inkop_write`. Även `data.migrate_types` | svep och migrate_types | `data_backend=mysql` | bara flaggan | Nej i SQL-grenen. `jeab_inkop_ensure_file` skapar `{}` om läsningen faller till fil | `jeab_inkop_load` läser filen om boot eller SELECT misslyckas | medel |
| inkorg_views | vyer i `data/inkorg/rules.json` | `inkorg_views` | `jeab_inkorg_views_read` / `_replace`. `data.migrate_types` | svep och migrate_types | `data_backend=mysql` | bara flaggan | Nej när `jeab_inkorg_views_sql_replace` tar skrivningen. `views-backup.json` skrivs bara när boot inte är SQL | `jeab_inkorg_data_part('views')` läser `rules.json` om SQL-listan är null. Samma fil är också `inkorg_rules` | medel |
| inkorg_rules | `data/inkorg/rules.json` | `inkorg_side_doc` | `inkorg_storage` via `jeab_inkorg_side_sql`. Första läsningen importerar filen | svep (checksumma), inte sidots egen verify | följer inkorgen | `jeab_inkorg_side_export` finns men anropas inte av `data.backend_set` | Nej efter import (`imported=1`) | `jeab_inkorg_side_json_doc` vid första importen. `jeab_inkorg_rules_grok_only` läser filen | medel |
| inkorg_settings | `data/inkorg/settings.json` | `inkorg_side_doc` | samma sidolager. Inställning, men koden kan skriva filen | svep | följer inkorgen | export-funktionen finns, kopplas inte av backend-flaggan | Nej efter import | första importen och JSON-läge i `jeab_inkorg_locked` | låg |
| auto_send_cap | `data/inkorg/auto-send-cap.json` | `inkorg_side_doc` | sidolager, importeras innan svar | svep | följer inkorgen | export finns, inte kopplad | Nej efter import | importläsning | medel |
| malmo_offer_sends | `data/inkorg/malmo-offer-sends.json` | `inkorg_side_doc` | samma | svep | följer inkorgen | samma | Nej efter import | importläsning | medel |
| quote_preview | `data/inkorg/quote-preview.json` | `inkorg_side_doc` | samma | svep | följer inkorgen | samma | Nej efter import | importläsning | medel |
| hs_uncertain_open | `data/inkorg/hs-uncertain-open.json` | `inkorg_side_doc` | sidolager. `inkorg.php` har också skip | svep | följer inkorgen | samma | skip eller sidolager. Filen om inget av dem tar raden | import och JSON-läge | medel |
| rebook_log | `data/inkorg/rebook-log.json` | `inkorg_side_doc` | sidolager | svep | följer inkorgen | samma | Nej efter import | importläsning | medel |
| corrections | `data/inkorg/corrections.jsonl` | `inkorg_corrections` | `jeab_inkorg_correction_write`: SQL om legacy är av eller skip, annars sidolager, annars jsonl | svep | `data_backend` eller legacy av | ingen export | jsonl bara när `LEGACY_JSON_WRITES` är på och skip är falsk och sidolagret är av | jsonl-append i den grenen | medel |
| reminder_follow | `data/inkorg/reminder-follow.jsonl` | `log_lines` | `jeab_log_line_append_raw` först | svep | log_store | ingen | filen om append inte lyckas | filen sist i `jeab_follow_reminder_audit_write` | låg |
| reminder_hook | `data/inkorg/reminder-hook.jsonl` | `log_lines` | samma mönster | svep | log_store | ingen | filen som reserv | samma | låg |
| solkoll | `data/solkoll.json` och `data/solkoll-cache.json` | `solkoll_docs` (`config`, `cache`) | `jeab_cutover_solkoll_read`. `data.migrate_types`. Nyckeln i `data/secrets/solkoll.json` lämnas | svep och migrate_types | `data_backend=mysql` | bara flaggan | Ingen PHP-skrivning av de två datafilerna hittades. Cachefilen läses vid migrate | `jeab_solkoll_read_json_file` läser filen om SQL inte svarar, och alltid hemlighetsfilen | medel |
| app_errors_webhook | `data/app-errors-webhook-seen.json` och `data/app-errors-webhook-resolved.json` | `app_error_webhook` | `jeab_cutover_webhook_mutate` | svep (egen källa läser båda filerna) | `data_backend=mysql` | bara flaggan | Nej när mutatören tar skrivningen | `security.php` läser filen när SQL-kartan inte är en array | medel |
| fullmakt | Registervägen `fullmakt/data` (en JSON med `docs`). Körvägen är en fil per token under pending/signed | `fullmakt_docs` | `fullmakt_sql_active` = `jeab_cutover_sql('fullmakt')`. Skrivning: `fullmakt_sql_put` | `fullmakt_sql_verify` räknar json_only. Ingen hook. Svepets `rows()` läser inte per-token-filerna, så checksumman ser inte dem | `data_backend=mysql` slår på SQL-skrivning men importerar inte gamla filer | ingen. PDF och bilder ska ligga kvar på disk | Nej för nya dokument när SQL är på (`fullmakt_create_doc`) | `jeab_fullmakt_signed_tokens` globbar `*.json` även när SQL-listan finns. `fullmakt_load` läser fil om SQL-raden saknas | hög |
| omtago_leads | `data/omtago/leads.json` | `omtago_leads` | `omtago.php` skriver SQL. Ingen filskrivning i den filen | svep | tabellen används direkt | ingen fil-export | skrivs inte av omtago-koden | bara migrate-läsning av filen | låg |
| helpscout_import | `data/inkorg/helpscout-import.json` | `jeab_hs_import_state` | `hs_archive.import`. Filen är reserv tills import | svep | importflagga, inte enbart `data_backend` | ingen | filen skrivs inte av den nuvarande importören | `jeab_hs_archive_checkpoint_json` läser filen utan cutover-koll. Arkivets `index.jsonl` och konversationsfiler ligger kvar | medel |
| malmo_hs_cursor | `data/inkorg/malmo-hs-sync.json` | `malmo_hs_cursor` | samma arkiv | svep | samma | ingen | samma | `jeab_hs_archive_malmo_json` läser filen rakt av | medel |

`orders` finns som fil `src/migrate/orders.php` men inte i `jeab_migrate_order()`. Den pekar på `purchases` och är retired.

## Typer utan modul

| typ-id | JSON | SQL | Omslag | V | C | R | JSON efter omslag | Direktläsning | Risk |
|---|---|---|---|---|---|---|---|---|---|
| sent_check | `data/graph-sent-check.json` | ingen. `jeab_migrate_find('sent_check')` är null | `jeab_cutover_sql('sent_check')` blir sant när `data_backend=mysql`, men `jeab_cutover_doc_put` kastar. `catch` i `jeab_auto_sent_check_cache_save` faller sedan till filen | nej | nej (flaggan ljuger) | nej | **Ja, filen är fortfarande skrivvägen** | `jeab_auto_sent_check_cache_load` läser filen eftersom doc_fetch inte kan returnera dokument | hög |
| quote-queue | `data/quote-queue.json` | ingen | ingen | nej | nej | nej | filen är enda lagret (`jeab_inbox_quote_queue_save`) | `jeab_inbox_quote_queue_load` läser alltid filen | hög |
| classify-pending | `data/inkorg/classify-pending.json` | ingen | `jeab_inkorg_locked`. Sidolistan är bara `settings.json` och `rules.json`, så den här filen låses på disk även när inkorgen är SQL | nej | nej | nej | **Ja** | låset läser filen | hög |

## Annan JSON som koden skriver

Inte cutover-kandidater. De ska inte följa med i `data_backend`.

| Fil | Varför den finns kvar |
|---|---|
| `privat/flags.json` | `STEG4_CUTOVER`, `CONTACTS_SOLE`, `CONTACTS_STRICT`, `LEGACY_JSON_WRITES`. Skrivs av `flags.set` |
| `data/passwords.json`, `data/restricted-users.json` | Inloggning. Hemlighet |
| `data/secrets/**`, `m365.json`, `m365-token.json`, Fortnox-token, webhook-hemligheter | Hemlighet. `jeab_graph_json_write` skriver alltid de här filerna. Graph-id för token matchar inte, så de går inte till SQL |
| `data/inkorg/settings.json` | Inställning. Har ändå en SQL-kopia i `inkorg_side_doc` (raden ovan) |
| `data/offert-defaults.json` | Offertstandard, mall |
| `data/secrets/solkoll.json` | Nyckel. Lämnas utanför `solkoll_docs` |
| `data/.cache/boilerplate.json`, `lm-fastighet-cache.json`, `orts.json` | Cache |
| `data/inkorg/views-backup.json` | Engångsbackup före vyflytt |
| `reminders.bak-*`, `store.bak-*`, `kontakter.bak-*`, exportfiler | Backup |
| `conn-sample.json`, `bg-denied.json` | Driftspår, inte kunddata |
| `jeab-purchase-booking-ids.json` | Tempfil utanför `data/` |
| `lead-reparse-undo.json`, `mark_test_undo.json` | Ångra-filer för verktyg |

## Ordning, minst risk först

1. **Redan SQL på live MySQL:** `purchases`, `ordrar_deleted`, `gi_loans`, `biz_docs` (fyra dokument). Bekräfta med respektive `*.sql_verify` där hooken fortfarande svarar. `purchases.sql_verify` svarar inte.
2. **Butiken:** `customers`, `bookings`, `reminders`, `reminder_lists`. På mysql/sqlite är drivern redan SQL. `store.sql_*` behövs bara om driver är `json`.
3. **Log_store som redan tar skrivningen:** `who_picks`, `who_diag`, `reminder_follow`, `reminder_hook`, `calendar_log`, `webshop_orders`, `graph_events`, `fellogg_open_asks`, `app_errors`.
4. **Importflagga, filen skrivs inte mer:** `notes`, `notes_state`, `sms_sent`, `omtago_leads`, `inbox_sent`. Sätt `imported` och kör svepets verify innan flaggan.
5. **Kontakter:** `contacts` och `inkorg_contacts` via `kontakter.sql_verify` och sedan `kontakter.sql_cutover` (CLI finns). `data_backend=mysql` ska komma efter det, annars slutar JSON-påfyllnaden.
6. **Inkorg som redan är SQL:** `inbox`, `inbox_threads` och sidodokumenten (`inkorg_rules`, `inkorg_settings`, `auto_send_cap`, `malmo_offer_sends`, `quote_preview`, `hs_uncertain_open`, `rebook_log`, `corrections`). Hooken kan inte verifiera. Jämför utanför den retired hooken innan `data_backend` slås på.
7. **Steg 4 med ren SQL-gren:** `login_throttle`, `external_queue` (kopiera kön först, annars blir filjobb kvar), `notiser`, `inkop_mallar`, `inkorg_views`, `inbox_sent_new`, `sent_scan`, `graph_state`, `solkoll`, `app_errors_webhook`. Ett grönt `data.migrate_all` (eller `data.migrate_types` för de fyra tillåtna) och sedan `data_backend=mysql`.
8. **Sist, efter kodluckorna nedan:** `graph_daily`, `graph_poll`, `fullmakt`, `sent_check`, `quote-queue`, `classify-pending`, Help Scout-arkivet.

## Luckor före cutover

Åtgärdade i koden. `data.cutover_all` tar backup, backfill, snabbkoll, SQL-flagga och raderar JSON. Kontakter, inkorg, `external_queue` och `login_throttle` körs ett anrop i taget (`type=kontakter`, `type=inkorg`, `type=external_queue`, `type=login_throttle`), i den ordningen. `dry=1` är standard. `write=1` skriver. `data.rollback_type` med samma `type` återställer från backupen.

De här måste vara åtgärdade eller uttryckligen undantagna innan `data_backend=mysql` slås på för allt.

1. **`sent_check` saknar modul, tabell, verify och rollback.** När steg 4 är på skrivs `data/graph-sent-check.json` ändå (`src/inkorg_auto.php`, `jeab_auto_sent_check_cache_save`).
2. **`graph_daily` och `graph_poll` skriver JSON vid sidan av SQL.** `jeab_graph_json_write` i `jeab_graph_status_remember` (`src/inkorg_graph.php`) skriver filen oavsett `LEGACY_JSON_WRITES`.
3. **`quote-queue.json` och `classify-pending.json` har ingen SQL.** Offertkön läser och skriver bara filen. Klassningskön går genom `jeab_inkorg_locked` och träffar inte sidolagret, så den skrivs även när `inkorg_storage=sql`.
4. **`fullmakt` importeras inte av svepet.** `jeab_migrate_fullmakt_rows` väntar sig en lista `docs` i `fullmakt/data`, inte en fil per token. `jeab_fullmakt_signed_tokens` slår ihop SQL med `*.json` på disk. `fullmakt_sql_verify` är en räknare utan hook och utan rollback.
5. **`inbox` och `purchases` har ingen körbar verify.** `inbox.sql_*` och `purchases.sql_*` returnerar `retired: SQL is sole source`. `data.migrate_all` hoppar över dem. `data_backend=mysql` gör inkorgen till SQL via `jeab_data_storage` utan att läsa `messages.json` / `threads.json`. Rader som bara finns i filen följer inte med.
6. **Steg 4-rollback skriver inte tillbaka filer.** `data.backend_set` med `backend=json` byter bara flagga. När `LEGACY_JSON_WRITES` är av och verify är grön är filen inaktuell för `login_throttle`, `external_queue`, `notiser`, `inkorg_views`, `inbox_sent_new`, `sent_scan`, `graph_state`, `solkoll`, `inkop_mallar`, `app_errors_webhook` med flera. `jeab_inkorg_side_export` finns men kopplas inte till den flaggan.
7. **`graph_week` kan läsa filen trots SQL.** En färsk `graph-week-cache.json` (90 sekunder) vinner över en äldre SQL-rad.
8. **`notes` och `sms_sent` läser JSON** tills `imported` är satt, och igen om SQL-läsningen kastar.
9. **`biz_docs` i registret** pekar på `data/biz/code_people.json`. Körvägen är de fyra filerna under `data/`. Svepet råkar läsa rätt filer bara när registerfilen saknas.
