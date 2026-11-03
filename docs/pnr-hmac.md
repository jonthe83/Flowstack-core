# HMAC-nyckel för personnummer i ångerloggen

När `contacts_sole` är på skriver kontaktlagret personnummer som 12 siffror utan bindestreck, via `jeab_norm_pnr_digits`. Ett värde som inte kan normaliseras sparas trimmad, oförändrat. Visning och utgående flöden (Fortnox, fullmakt, UI) formaterar fortfarande som `ÅÅÅÅMMDD-NNNN`.

`contacts_sole_undo` får inte innehålla personnumret i klartext. Fältet byts mot `pnr_hmac`: HMAC-SHA256 i hexadecimal form av det normaliserade numret. Nyckeln är strängen precis som den står. Den avkodas inte till råa byte.

## Skapa nyckeln

```sh
openssl rand -hex 32
```

Lägg den enda raden i `dirname(public_html)/privat/pnr-hmac.key`. På flowstack.example.test är det `/flowstack.example.test/privat/pnr-hmac.key`, syskon till `public_html` och `db`. Appen löser samma katalog via `jeab_outside_privat_dir()` (webbroten är `JEAB_ROOT`). Sätt rättigheter `0600`. Inga citationstecken och inget extra på raden. Filen ligger utanför git. En kvarlämnad `public_html/private/pnr-hmac.key` läses inte.

Samma sträng kan i stället ligga i miljövariabeln `JEAB_PNR_HMAC_KEY`. En ifylld variabel används före filen.

## Saknas nyckeln

Då utelämnas `pnr_hmac`. Klartext skrivs inte heller. Ånger återställer numret bara när exakt en nuvarande ägare har samma namn, och bara om det numret fortfarande finns på kontakten. Ett tomt värde skriver inte över ett nummer som finns.

## Rotation

Ersätt filen eller miljövariabeln med en ny sträng från samma `openssl`-kommando. Gamla rader i `contacts_sole_undo` har HMAC mot den förra nyckeln och matchar inte längre. Ånger använder då namnträffen. Numret på kontakten ligger kvar. Skriv inte den gamla nyckeln i logg, hook-svar eller i repot.

## Flaggan av

`contacts_sole=0` lämnar lagring och ånger som tidigare. Ingen tvingad tolvsiffrig form och ingen HMAC-väg.
