# SQL-migrering — inkorg

Databas: `jeab_eu_db_1`. Tabellerna skapas av `jeab_ensure_inkorg_tables()` i `src/db.php` vid anslutning. `schema/` deployas inte och behövs inte för det här steget.

Flaggan `settings.inkorg_storage` är **av** (`json`, eller saknas) tills cutover. Config-nyckeln `inkorg.storage` slår inte på SQL.

Anteckningar är rader i `messages.json` med `internal_note` eller `hs_thread_type=note`. De ligger i `inkorg_messages` med `is_note=1`. Övriga rader är meddelanden. Trådar ligger i `inkorg_threads`.

## Filer att deploya, i den ordningen

1. `src/db.php`
2. `src/inkorg_store.php`
3. `src/inkorg_sql_migrate.php`
4. `src/inkorg.php`
5. `scripts/migrate-inkorg-sql.php`

Deploya alla fem innan någon hook körs. Då finns tabellerna och anropen när `inkorg.php` laddas. Rör inte `tests/`, `schema/` eller `data/`.

## Steg efter deploy

1. Kontrollera att live fortfarande är JSON: `inbox.sql_cutover` utan `enable` ska svara `storage=json`.
2. `inbox.sql_migrate` med `dry=1` (standard). En batch, eller `all=1` i CLI. Inget skrivs.
3. `inbox.sql_migrate` med `dry=0` och `all=1` (eller batchar tills `done`). Idempotent: kör igen om en batch avbryts. SQL-rader som inte finns i JSON tas bort när hela körningen är klar.
4. `inbox.sql_verify`. Jämför antal trådar, meddelanden och anteckningar, plus stickprov (`sample`, standard 5). Svaret innehåller id och `match`, inte radinnehåll. `match` ska vara sant och `storage` ska fortfarande vara `json`.
5. `inbox.sql_cutover` med `enable=1` och `dry=1`. Förhandsvisar. Flaggan ändras inte.
6. `inbox.sql_cutover` med `enable=1` och `dry=0`. Kräver att verify matchar. Därefter läser och skriver inkorgen SQL. JSON-filerna rörs inte.

## Rollback

`inbox.sql_cutover` med `enable=0`. `dry=1` är standard och skriver inget.

Med `dry=0`: SQL skrivs tillbaka till `messages.json` och `threads.json` (förra filen sparas som `.prev`), sedan sätts flaggan till `json`. Live läser JSON igen. Vägrar om SQL är tom och JSON har rader.

## CLI

```bash
php scripts/migrate-inkorg-sql.php
php scripts/migrate-inkorg-sql.php --all --write
php scripts/migrate-inkorg-sql.php --verify
php scripts/migrate-inkorg-sql.php --cutover
php scripts/migrate-inkorg-sql.php --cutover --write
php scripts/migrate-inkorg-sql.php --rollback --write
```

Utan `--write` är det `dry=1`.

## Kontakter

Tabellerna `contacts` (registret `kontakter.json`) och `inkorg_contacts` (inkorgens `contacts.json`) skapas av `jeab_ensure_contacts_tables()` i `src/db.php`. `schema/` deployas inte.

Flaggan `settings.kontakter_storage` är **av** (`json`, eller saknas) tills cutover. Config-nyckeln `kontakter.storage` slår inte på SQL.

När flaggan är `sql` skriver `src/kontakt_store.php` bara SQL. Har tabellen rader läses bara SQL. En tom tabell läser JSON. En rad som bara ligger kvar i JSON blandas inte in. `src/inkorg_contacts.php`, `src/kontakter_dedupe.php` och `src/kontakter_migrate.php` går via samma lager.

### Filer

1. `src/db.php`
2. `src/kontakt_store.php`
3. `src/kontakter_sql_migrate.php`
4. `src/kontakter.php`
5. `src/inkorg_contacts.php`
6. `src/kontakter_dedupe.php`
7. `src/kontakter_migrate.php`
8. `hook.php`
9. `scripts/migrate-kontakter-sql.php`

### Steg

1. `kontakter.sql_cutover` utan `enable` ska svara `storage=json`.
2. `kontakter.sql_migrate` med `dry=1` (standard). Inget skrivs.
3. `kontakter.sql_migrate` med `dry=0`. Idempotent upsert av registret och inkorgskontakterna.
4. `kontakter.sql_verify`. Antal, checksum och stickprov (`sample`, standard 5). Svaret har id och `match`, inte radinnehåll. `storage` ska fortfarande vara `json`.
5. `kontakter.sql_cutover` med `enable=1` och `dry=1`. Förhandsvisar.
6. `kontakter.sql_cutover` med `enable=1` och `dry=0`. Kräver att verify matchar.

### Rollback

`kontakter.sql_cutover` med `enable=0`. `dry=1` är standard. Med `dry=0` skrivs SQL tillbaka till JSON (förra filen sparas som `.prev`) och flaggan sätts till `json`.

### CLI

```bash
php scripts/migrate-kontakter-sql.php
php scripts/migrate-kontakter-sql.php --write
php scripts/migrate-kontakter-sql.php --verify
php scripts/migrate-kontakter-sql.php --cutover
php scripts/migrate-kontakter-sql.php --cutover --write
php scripts/migrate-kontakter-sql.php --rollback --write
```

### Ett svep

`data.migrate_all` är torr som standard. Med `dry=0` slår den på underhållsläge, pausar tick och sms-cron, migrerar modulerna i `src/migrate/registry.php`, löser kontaktkonflikter, kontrollerar antal, checksumma och kopplingar, och sätter sedan `data_backend=mysql`. Konfliktlösningen i svepet får skriva SQL innan flaggan slås på. Ett eget anrop till `kontakter.conflicts` med `dry=0` kräver fortfarande att `kontakter_storage` är `sql`. Fallerar en kontroll står flaggan kvar och underhållsläget släpps. Budgeten är 900 sekunder. Gränssnittet är `jeab_migrate_<id>_module()`, `_rows()` och `_checksum()`, samma yta som spår B. Det ligger i `src/migrate/README.md` och `src/migrate/registry.php`. Bokningar, påminnelser och kunder kopieras från `store.json` med samma upsert. Stämmer inte antal eller checksumma, eller om en kontaktkoppling saknas, slås flaggan inte på.

### Konflikter

`data.cutover_all` med `type` tar kontakter (`contacts` och `inkorg_contacts`, flaggan `kontakter_storage`), inkorg (`inbox` och `inbox_threads`, flaggan `inkorg_storage`), `external_queue` och `login_throttle`. Ett anrop per typ. Ordning: kontakter, inkorg, external_queue, login_throttle. `dry=1` ändrar inget. `write=1` tar backup (zip och dump under `privat/backup/`), backfill, snabbkoll (radantal och fem stickprov; kön och spärren kräver samma antal), SQL-flagga och raderar JSON. GET räcker, med samma cron-token som övriga hookar. Vid kön och inloggningsspärren pausas cron och utskick under skrivningen och släpps efteråt. Pausen släpper av sig själv efter 15 minuter. `data.rollback_type` med samma `type` och `write=1` lägger tillbaka filerna från backupen och sätter flaggan till json. Går inställningen inte att skriva ligger samma värde i `privat/kontakter-storage.txt` eller `privat/inkorg-storage.txt`.

`kontakter.conflicts` är torr som standard och visar varje planerat beslut plus antal påminnelser, bokningar, ordrar, inkorgsrader och okända kopplingar före och efter. `dry=0` löser allt när `kontakter_storage` är `sql`: nyaste datan vinner, samma normaliserade adress slås ihop, och saknat postnummer tas från order, bokning, inkorg, kalkyl eller påminnelse, sedan från gatan, samma ort och sist från uppslag. Det finns ingen manuell kö. Varje ändring loggas i `contact_conflict_undo` med före- och eftervärde. `kontakter.conflicts_undo` är torr som standard och spelar tillbaka en körning. Om antalet rader eller okända kopplingar ändras, eller en koppling töms, rullas transaktionen tillbaka. Svaret har id, åtgärd och postnummer, inte personnummer. `kontakter.json` skrivs inte.

Utan `--write` är det `dry=1`.
