# Omtago-webhook

Publik endpoint för leads från Omtago. Den ligger utanför inloggningen och tar bara POST.

## Endpoint

`POST https://flowstack.example.test/api/omtago-lead.php`

`GET` och andra metoder svarar `405` med `{"ok":false,"error":"Använd POST."}`.

## Nyckel

Nyckeln ligger i en fil **utanför webbroten**:

`privat/omtago_api_key`

Det är syskonet till webbroten, samma mönster som `privat/incoming-token.txt`. Filen är en rad med nyckeln, utan citationstecken. Den ska inte ligga i git, inte i `data/` och inte i ett JSON-dokument.

Skicka nyckeln i en av dessa headers:

- `Authorization: Bearer <nyckel>`
- `X-Api-Key: <nyckel>`

Saknas filen, är den tom eller stämmer nyckeln inte svarar endpointen `401` med `{"ok":false,"error":"Fel nyckel."}`. Jämförelsen är `hash_equals`. Kontrollen sker innan boot och innan någon SQL-fråga. En saknad fil eller en fel nyckel blir därför 401 även när databasen inte svarar.

Lägg nyckeln själv i `privat/omtago_api_key` (utanför webbroten) med chmod 600. Checka aldrig in den i git.

Fler än 60 godkända anrop per minut och IP svarar `429`. Räkningen sker efter att nyckeln stämt, så den kräver SQL. Tabellerna `omtago_leads` och `omtago_rate` skapas med `CREATE TABLE IF NOT EXISTS` i koden. Ett SQL-fel efter godkänd nyckel svarar `503` och det verkliga felet skrivs i app-errors, utan nyckel och utan kunddata.

## Inkorgen

Riktiga leads skrivs i den befintliga SQL-inkorgen. Flaggan `inkorg_storage` måste vara `sql`. Annars svarar endpointen `503` och skapar ingen tråd.

## Payload

JSON-objekt:

| Fält | Krav |
| --- | --- |
| `lead_id` | Sträng, 1–128 tecken, `A–Z a–z 0–9 . _ : -`. Unik. |
| `created_at` | Datum som går att tolka, till exempel `2026-10-20T10:00:00+02:00`. |
| `lead_type` | `Batteri` eller `Solceller`. |
| `property_type` | Sträng. Landet Sverige är inte en fastighetstyp. |
| `contact.first_name` | Sträng. |
| `contact.last_name` | Sträng. |
| `contact.email` | Giltig e-post. Inte en leadkälla, alltså inte `@omtago.se`. |
| `contact.phone` | Minst sju siffror. |
| `address.street` | Gata. Inte enbart «Sverige». |
| `address.city` | Ort. Inte enbart «Sverige» eller «Sweden». |
| `address.postal_code` | Fem siffror, med eller utan mellanslag. |
| `summary` | Sträng, får vara tom. |
| `profile` | Lista av `{label, value}`. Får vara tom. |
| `test` | Bool, `true` eller `false`. |

Fel i payload svarar `400` med `{"ok":false,"error":"<orsak>"}`.

Ordet Sverige skrivs inte in i tråden, kontakten eller påminnelsen. En ort som bara är landet avvisas.

## Svar

`200`:

```json
{"ok":true,"id":"<tråd-id>","lead_id":"<lead_id>"}
```

`id` är vårt tråd-id. Samma `lead_id` igen ger samma `id` och skapar inte en ny tråd.

## test

`test: true` sparar raden i SQL-tabellen `omtago_leads` (`lead_id` UNIQUE, `thread_id`, `received_at`, `test`) och svarar med `id`. Ingen inkorgstråd, ingen kontakt och ingen offert skapas.

`test: false` skapar en tråd i SQL-inkorgen:

- avsändare är kundens mejl och namn
- typ är Ny lead
- produkt är batteri eller sol utifrån `lead_type`
- källa är Omtago
- leaduppgifterna ligger i brödtexten
- mottagaren för automatisk offert är kundens mejl

Klassning, områdesregler och automatisk offert går genom samma kod som andra leads (`jeab_inkorg_classify`, ombokning och `jeab_auto_on_message`). Kontakten fylls via den befintliga kontaktvägen, med gata och ort.

## Blockerad utskickskälla

`omtago.se` ligger i leadkällistan i `src/lead_source.php`. Utskick till adresser på den domänen stoppas, på samma sätt som till andra leadkällor.
