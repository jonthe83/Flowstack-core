# Migreringsmoduler

`data.migrate_all` kör modulerna i `src/migrate/registry.php`. En flagga styr läsningen efteråt: `DATA_BACKEND=mysql|json` (miljö eller inställningen `data_backend`). Återställning är att sätta flaggan till `json`. Typer som redan har MySQL som källa på live stannar i SQL när `authoritative_sql` är sann, eftersom deras JSON inte är källan.

Kontakter är navet. Konfliktlösning och postnummer körs efter att alla moduler har kopierat, och före flaggan slås på.

## Fil

`src/migrate/<id>.php`. Filnamnet är id:t, till exempel `purchases`, `bookings`, `reminders`.

Gruppen i registret är `A` (kontakter), `B` (ordrar och bokningar) eller `C` (övrigt). Byt ut filen. Funktionsnamnen ska vara kvar.

## Yta

Tre funktioner. Inget eget hook och ingen egen storage-flagga.

```php
function jeab_migrate_<id>_module(): array
{
    return jeab_migrate_module(
        '<id>',
        '<tabell>',
        'data/<fil>.json',
        '<primärnyckel>',
        ['<primärnyckel>', '<kolumn>'],
        '<CREATE TABLE mysql>',
        '<CREATE TABLE sqlite>',
        ['<beroende-id>']
    );
}

function jeab_migrate_<id>_rows(array $source): array
{
    return jeab_migrate_project($rows, '<primärnyckel>', jeab_migrate_<id>_module()['columns']);
}

function jeab_migrate_<id>_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_<id>_rows($source));
}
```

`jeab_migrate_module()` ligger i `src/migrate/canon.php` och returnerar `id`, `table`, `json`, `primary_key`, `columns`, `depends` och `schema` med `mysql` och `sqlite`.

`jeab_migrate_declare()` är samma funktion, för moduler som redan anropar det namnet.

`_rows()` tar JSON-källan och returnerar kanoniska rader. `_checksum()` tar samma källa och returnerar sha256. Inga personnummer och inga hela rader i svaret från `data.migrate_all`.

Svepet skapar tabellen från `module()` och upsertar `rows()` idempotent. Verifieringen jämför antal och `_checksum()` mellan JSON och MySQL. Modulerna behöver inga egna migrate- eller verify-funktioner.

Kontakter och kunder körs först, sedan typer som pekar på dem. `orders` är samma modul som `purchases`. `src/migrate/orders.php` anropas inte av registret.

Hela svepet ska rymmas i 900 sekunder. Kopiera satsvis. Gör inga externa anrop per rad.

## Lager

`DATA_BACKEND` läses av `jeab_data_backend()` i `src/datastore.php`.

Befintliga `*_storage_mode()` frågar `jeab_data_storage($type, $legacy)`.

- Flaggan är inte satt: den gamla per-typ-flaggan gäller.
- `DATA_BACKEND=mysql`: typen läser och skriver SQL.
- `DATA_BACKEND=json`: typen läser och skriver JSON, utom när `authoritative_sql` är sann.

`jeab_data_read($id)` och `jeab_data_write($id, $rows)` täcker varje registrerad typ. En modul med egen `read` eller `write` används där. Annars läser och skriver lagret kanoniska rader, SQL när flaggan är `mysql` och JSON när den är `json`. JSON-skrivning sker bara om `rows()` läser tillbaka samma checksumma, så en delad fil som `store.json` inte ersätts med en annan typ.

Underhållsläge (`data_maintenance`) spärrar skrivningar och får `jeab_tick_run` och `jeab_sms_tick` att återvända utan jobb. Migreringen sätter `$_JEAB_DATA_MIGRATE` så att den egna processen får skriva.
