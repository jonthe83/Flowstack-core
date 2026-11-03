# SQL eller JSON per datatyp – bedömning (PR cursor/sql-sole-truth)

Inga JSON-filer raderas av detta arbete. Alla flips görs live i efterhand: först torrkörning, sedan write=1. Rollback sker med `data.rollback_type type=X`.

## Hybridtyper (SQL ska vara ensam sanning)
| Typ | Status i PR | Nästa steg live |
|---|---|---|
| customers / bookings / reminders / reminder_lists | `data.cutover_all` dry visar nu `flag`, `driver`, `ready` och med `check=1` en skrivfri spotkontroll. `jeab_store_sql_required()` läser sql-listan. | Kör dry `check=1` och kontrollera att `ready=true` och differ=0. Kör sedan `data.set_storage mode=sql`. |
| inkorg_contacts | Kopplingskörningen visar `json_count`, `sql_count`, `json_only`, `left` och `sole_source`. Backfillen är fortsatt idempotent. Reserven stängs när kontakter och inkorg_contacts båda är tvingade till sql. | Kör dry, granska `left`/`json_only` och kör sedan write=1. |
| sms_sent | Migreringens payload får inte längre ett booking_id inlagt, vilket var orsaken till de 51 differensraderna. | Kör `check=1` och, om differ=0, write=1 med keep_json=1. |
| graph_week | När typen är tvingad till sql läses ingen JSON-cache. | Kör `data.set_storage` för graph_week med sql. |
| notes | När typen är tvingad till sql görs ingen JSON-läsning, inte heller när SQL är tom. | Importera med keep_json och flagga sedan sql. |
| app_errors / app_errors_webhook / who_diag | Orsaken loggas nu med `error_log` (ingen_pdo/import/insert/mutate), en gång per typ och request. Importen av gamla rader stoppar inte längre den nya raden. | Läs error_log efter deploy. |
| fullmakt | SQL-läsning finns redan (`jeab_fullmakt_signed_sql_docs`). | Kör dry `check=1` och sedan keep_json-cutover. |
| inbox / inbox_threads | `data.inkorg_counts` visar nu `compare` för ärenden och historik, SQL mot JSON. | Stäng reserven när `compare.ok` är true. |

## Rena JSON-typer
| Typ | Beslut | Motivering |
|---|---|---|
| ordrar_deleted | SQL efter dry check=1 | Tabelldata som växer. Rullades tillbaka tidigare, så orsaken ska läsas i `check`. |
| calendar_log | SQL efter dry check=1 | Logg som passar SQL. |
| inkorg_views | SQL efter dry check=1 | Räknare. Kontrollera summorna först. |
| fellogg_open_asks | Behåll JSON tills vidare | Liten kö med kort livslängd. |
| sent_scan | Behåll JSON tills vidare | Skanningsstatus/markör. |
| graph_state | Behåll JSON | Liten tillståndsfil (tokens/deltalänkar). |
| malmo_hs_cursor | Behåll JSON | En enda markör. |
| helpscout_import | SQL först när läsaren är bytt | Läses direkt via `jeab_hs_archive_*_json`. |

## Överhoppat
- Läsarbyte för helpscout_import och malmo_hs_cursor (måttlig risk).
- Kontroll av räknarna för inkorg_views.
- Borttagning av inbox-reservens kod (styrs av compare och flaggor).
- Fixar av differensorsakerna för de rena JSON-typerna (görs när check-utdata live har lästs).
