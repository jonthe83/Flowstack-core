# Bidra till JEAB Bokning

## Affärsdata

Affärsdata ska ligga i SQL. Ingen ny data i JSON eller JSONL. Undantag och regeln: `AGENTS.md`.

Ny tabell skapas i `src/db.php` (`jeab_ensure_*`), inte bara i `schema/`.

## Inkorg, steg 1

Meddelanden, anteckningar (`internal_note` eller `hs_thread_type=note`) och trådar kan ligga i SQL. Flaggan är av tills cutover, så live fortsätter mot JSON.

- `inbox.sql_migrate` — idempotent, `dry=1` som standard
- `inbox.sql_verify` — räknar och jämför, plus stickprov, skriver inte
- `inbox.sql_cutover` — `enable=1` slår på SQL när verify matchar, `enable=0` skriver tillbaka till JSON

## Kontakter

`kontakter.json` och inkorgens `contacts.json` kan ligga i SQL (`contacts`, `inkorg_contacts`). Flaggan `kontakter_storage` är av tills cutover. `kontakter.storage` i config slår inte på SQL.

- `kontakter.sql_migrate` — idempotent, `dry=1` som standard
- `kontakter.sql_verify` — räknar, checksum och stickprov, skriver inte
- `kontakter.sql_cutover` — `enable=1` slår på SQL när verify matchar, `enable=0` skriver tillbaka till JSON

Efter cutover läser och skriver registret, dedupe, migrate och `inkorg_contacts.php` via `kontakt_store`. JSON är bara läsreserv.

Se `docs/sql-migration-plan.md`.

## Agenter

Commit, push, PR. Ingen merge och ingen deploy. Se `AGENTS.md`.
