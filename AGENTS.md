# Agentregler — Flowstack

## Aldrig deploy, aldrig merge

Agenter deployar **aldrig** till en produktionsserver och mergar **aldrig** till `main` själva. Ägaren av installationen mergar och deployar.

När du gör kodändringar: **commit + push + öppna eller uppdatera en PR**. Stanna där. Lämna PR:en som utkast om inget annat sägs.

## Förbjudet för agenter

- Uppladdning till servern (filhanterare, FTP/SFTP eller deploy-skript)
- `gh pr merge`, GitHub-merge och att själv lägga in tippen på `main`

Rör aldrig serverns `data/`. Hemligheter (API-nycklar, lösenord) ligger på servern i `data/secrets/` eller `privat/`, aldrig i git.

## Affärsdata i SQL

All affärsdata ska ligga i MySQL/SQL. Ingen ny data får sparas i JSON eller JSONL.

Undantag:

- Inställningar och mallar, till exempel `inkorg/settings.json` och config som ligger i git.
- Hemligheter i `private/` (även `privat/` utanför webbroten) och i `data/secrets`.
- Backuper och exporter.

Ny lagring följer `jeab_ensure_*` i `src/db.php`. Funktionerna skapar tabellen idempotent för både MySQL och SQLite. Förebilder är `jeab_ensure_contact_email_audit()` (tabellen `contact_email_audit`) och `jeab_ensure_order_owner_audit()` (tabellen `order_owner_audit`). Anteckningar och skickade SMS är undantaget: `src/notes_store.php` och `src/sms_store.php` skapar sina tabeller själva och `src/db.php` rörs inte.

Kontakter ligger i `contacts` och inkorgens `contacts.json` i `inkorg_contacts`. Flaggan `kontakter_storage` är av tills hooken `kontakter.sql_cutover`. Efter cutover är SQL enda källan. JSON läses bara om tabellen är tom.

`.github/workflows/no-new-json.yml` körs på pull request. Den fallerar om diffen lägger till en ny PHP-skrivning till en `.json`- eller `.jsonl`-fil under `data/`, till exempel `file_put_contents` eller `fopen` mot en sådan fil, eller ett nytt `json_store`-anrop (`jeab_open_json_store`, `new JeabJsonStore`). Befintliga skrivvägar på `main` ligger på en allowlist tills de flyttas. `data/notes.json` och `data/sms-sent.json` är borttagna från allowlisten. Kontrollen ser bara tillagda rader, så en orörd gammal skrivning stoppar inte PR:en.

## Microsoft Graph (mejl och kalender)

Mejl och kalender går via Microsoft Graph. Systemmejl försöker Help Scout först och faller sedan tillbaka till Graph från `kontakt@`. Skickat-kollen och personernas kalendrar läser Graph. Det finns ingen separat kalenderkoppling i Bokning.

Graph-nyckeln ligger i `data/secrets/m365.json` (inte git).
