# Fullmakt Ellevio

Fristående kundflöde för digital fullmakt till **Demo Energi Järfälla** att anmäla produktionsanläggning till Ellevio. Ingen personal-GUI, ingen inloggning.

Droppa mappen på `https://flowstack.example.test/fullmakt/`.

## Vad som finns

| URL | Vem |
|---|---|
| `POST /fullmakt/api/create.php` | Bot. Skapar dokument, returnerar engångslänk. |
| `GET /fullmakt/s/{token}` | Kund. Fyller 18-siffrigt anläggnings-id och signerar. |
| `GET /fullmakt/tack.php` | Tacksida efter signering. |

Installatörsfälten är hårdkodade (Demo Energi Järfälla). Kunduppgifter kommer i create-anropet. Kunden skriver bara anläggnings-id och signatur.

Efter signering sparas PDF + JSON under `fullmakt/data/signed/` (gitignorerad). Länken blir då förbrukad.

## Installation

1. Kopiera `fullmakt/` till webbservern.
2. Apache behöver `mod_rewrite` (`.htaccess` i den här mappen).
3. Skapa hemlighet och skrivbara kataloger:

```bash
mkdir -p data/pending data/signed data/tmp
cp secret.php.example data/secret.php
# byt change-me till en lång slumpnyckel
chmod 700 data
```

`data/secret.php` ska innehålla:

```php
<?php
$FULLMAKT_KEY = 'change-me';
```

4. PHP 7.4+ med `json` och gärna `gd` (för att kontrollera att signaturen inte är tom). FPDF ligger i `vendor/fpdf/`.

Miljövariabler (valfria):

- `FULLMAKT_KEY` — överskrider `data/secret.php`
- `FULLMAKT_PUBLIC_URL` — t.ex. `https://flowstack.example.test/fullmakt`
- `FULLMAKT_MAIL=1` — tvinga e-post via `mail()`
- `FULLMAKT_DISABLE_MAIL=1`
- `FULLMAKT_MAIL_FROM`
- `FULLMAKT_STAFF_EMAIL` — dit den signerade PDF:en skickas (standard `kontakt@jarfalla.example.test`)

Om `mail()` inte är konfigurerad returnerar API:t ändå `url` så att boten kan skicka mailet.

## Skapa testdokument

```bash
curl -sS -X POST 'https://flowstack.example.test/fullmakt/api/create.php' \
  -H 'Content-Type: application/json' \
  -H 'X-Fullmakt-Key: change-me' \
  -d '{
    "namn": "Anna Andersson",
    "personnummer": "19800101-1234",
    "telefon": "070-123 45 67",
    "epost": "kontakt@jarfalla.example.test",
    "adress": "Testvägen 1",
    "postnummer": "176 76",
    "ort": "Järfälla",
    "typ": "privatperson"
  }'
```

Svar:

```json
{ "ok": true, "token": "…", "url": "https://flowstack.example.test/fullmakt/s/…", "mailed": false }
```

Samma curl finns kommenterad i `api/send-example.sh`.

E-post till kunden (om mail är på):

- Ämne: `Fullmakt till Ellevio — signera`
- Brödtext: `Hej {namn}, för att vi ska kunna anmäla solcellerna till Ellevio behöver du fylla i anläggnings-id … Länk: {url}`

## Create-body

```json
{
  "namn": "…",
  "personnummer": "…",
  "telefon": "…",
  "epost": "…",
  "adress": "…",
  "postnummer": "…",
  "ort": "…",
  "foretagsnamn": "",
  "typ": "privatperson",
  "anlaggningsadress": ""
}
```

`typ`: `privatperson` | `enskild` | `foretag`. Default `privatperson`.  
`anlaggningsadress` defaultar till `adress`.  
`foretagsnamn` lämnas tomt för privatperson.

Header: `X-Fullmakt-Key`. Fel nyckel ger `401`.

## Lokal test

```bash
cd fullmakt
mkdir -p data && cp secret.php.example data/secret.php
php -S localhost:8080 router.php
```

## PDF

En sida A4, svart på vitt, Ellevio-layout (titel, tre innehavartyper, 18 rutor för anläggnings-id, installatör, kund, legaltext, underskrift, sidfot org-nr `556037-7326`). Inga GetAccept-mergetaggar.
