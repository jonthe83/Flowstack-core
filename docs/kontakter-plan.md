# Kontakter — kunddata på ett ställe

Steg 1 är det här dokumentet, datamodellen, kopplingen när en påminnelse skapas från inkorgen, hooken `kontakter.link` och sidan Kontakter. Påminnelser, ordrar, kalender, fakturering, `/rot/` och Fortnox läser fortfarande sina egna fält. De får bara ett nytt, tomt `contact_id` som fylls i av kopplingen.

## Var kunddata ligger idag

Inget av lagren nedan är kanoniskt. Samma gata, namn, e-post, telefon och personnummer kan finnas i flera kopior. En rättning på ett ställe syns inte på de andra.

### Påminnelser

| | |
|---|---|
| Lagring | JSON-butiken `reminders[]` i `store.json`, eller tabellen `reminders` (SQLite/MySQL) |
| Kod | `src/store.php` (`jeab_hydrate_reminder`), `src/reminders.php` |
| Fält | `customer_name`, `email`, `phone`, `address`, `ort`, `note`, `company`, `person`, `assignees`, `tags`, `booking_id` |

`ort` är orten, ofta ifylld i efterhand via `jeab_reminder_lookup_city` som anropar samma adressuppslag som ordrar. Postnummer ligger ofta inbakat i `address`, inte i ett eget fält.

### Ordrar

| | |
|---|---|
| Lagring | `purchases.json` eller tabellen `purchases.payload` (JSON per `reminder_id`) |
| Kod | `src/purchase.php`, `src/ordrar.php` |
| Fält | `name`, `email`, `phone`, `address`, `postnummer`, `ort`, `personnummer`, `fastighetsbeteckning`, `owners[]` (namn, personnummer, adress, andel), `medsokande[]`, `applicant`, `coapplicant`, `huvudsokande`, `company`, `fortnox_order` |

En order skapas från påminnelsens kopia (`jeab_purchase_blank`), sedan fylls ägare och personnummer på vid uppslag och i formuläret. Stängd order: `closed_at` eller `allt_klart` (`jeab_ordrar_manuellt_stangt`).

### Kalenderbokningar

| | |
|---|---|
| Lagring | `bookings[]` / tabellen `bookings`. Tabellen `customers` är bara `id` + `name` som bokningen pekar på |
| Kod | `src/store.php` (`jeab_hydrate_booking`), `src/bookings.php` |
| Fält | `customer_id`, `customer_name`, `email`, `phone`, `address`, `notes`, `starts_at`, `ends_at`, `status`, `job_type`, `company` |

Tiden (`starts_at`, `ends_at`) är bokningens egen data och får inte röras när kontakten kopplas.

### Inkorgens kontakter

| | |
|---|---|
| Lagring | `data/.private/inkorg/contacts.json` |
| Kod | `src/inkorg_contacts.php`, sida `src/pages/inkorg_kontakter.php` (`p=inkorg-kontakter`) |
| Fält | `name`, `emails[]`, `mobile`, `address`, `town`, `notes` |

De skapas från inkommande mejl och matchas på e-post eller mobil (`+46`). Sidan i inkorgen är kvar i steg 1. Den nya sidan Kontakter är adressregistret.

### Fakturering

Fakturering är steg på ordern, inte ett eget kundregister. Underlag är orderns `personnummer`, `owners` / `medsokande`, adress, postnummer, ort och fastighetsbeteckning. Grönt avdrag och fakturarader byggs därifrån i `src/ordrar.php` och `src/fortnox.php`.

### Fortnox

| | |
|---|---|
| Kod | `src/fortnox.php`, klassen `FortnoxAPI` |
| Kund | `findOrCreateCustomer`: namn, personnummer som `OrganisationNumber`, e-post, telefon, adress (`Address1`, `ZipCode`, `City`) |
| Order | `CustomerNumber`, adress, `Comments` = fastighetsbeteckning, skattereduktion med `SocialSecurityNumber` |
| Hemlighet | Token i `data/.private/fortnox/` (inte i git). Inga Fortnox-anrop i tester |

Fortnox är ett eget kundkort hos Fortnox. Appen skickar en kopia av orderns fält. Steg 1 ändrar inte den payloaden.

### /rot/

| | |
|---|---|
| Kod | `src/address_lookup.php` (inkluderad från `rot/address_lookup.php`) |
| Uppslag | `LantmaterietAPI` i `rot/data_scraping.php` på servern (filen finns inte i git). Reservanrop mot kalkylen finns för ordersök |
| Svar | `fastighetsbeteckning`, `address`, `owners[]` (namn, personnummer), `postnummer`, `ort` |

Resultatet skrivs in på ordern. `/rot/` har ingen egen kundtabell i repot.

## Datamodell (steg 1)

En kontakt är i första hand en adress.

| Fält | |
|---|---|
| `id` | Stabilt `kt_` + 16 hex. Andra poster lagrar bara det här |
| `street` | Gata och nummer, visningstext |
| `street_key` | Normaliserad gata + nummer |
| `postal_code` | Fem siffror |
| `city` | Ort |
| `fastighetsbeteckning` | Valfri |
| `owners` | 1–2 ägare: `name`, `personnummer` |
| kontaktperson | Exakt en ägare har `contact: true` och då `email` + `phone` |

Normaliserad adress: gata plus nummer, utan skillnad på versaler, diakriter (`å/ä/ö`) eller blanksteg. `Västra Gatan 12` och `vastra  gatan 12` är samma nyckel. Postnummer ingår i nyckeln när det är känt. Saknas nummer är det ingen adressnyckel; då används e-post, sedan telefon.

`contact_id` (nullbart) finns nu på påminnelser, ordrar och bokningar. Läsarna använder det inte än.

Personnummer valideras med datum och Luhn (samordningsnummer med dag + 60 godkänns). Det loggas inte, skrivs inte i feltexter och följer inte med i hookens svar. I gränssnittet visas de fyra sista siffrorna. Inloggad personal kan visa hela numret med ett klick. Ogiltigt nummer stoppar sparning på sidan. När en påminnelse skapas från inkorgen hoppas ett ogiltigt nummer över så att påminnelsen ändå skapas.

Uppslag av postnummer och ort går via `jeab_kontakt_lookup_postort`. Den använder Lantmäteriet-klassen som redan finns (`jeab_rot_class_address_search` + `jeab_rot_normalize_lookup`). Saknas klassen, eller anropet kastar, blir svaret tomt och påminnelsen skapas ändå. Provider styrs av `kontakter.lookup_provider` i serverkonfig (`rot` är standard, `off` stänger av, `static` läser en karta i samma konfig). Ingen nyckel läggs i git.

## Steg

1. **Det här.** Modell, `contact_id`, koppling vid påminnelse från inkorg och lead-steget, hooken `kontakter.link` (`dry=1` som standard, samma Bearer som `inbox.gp_probe`), sidan Kontakter. Inga andra läsningar byts.
2. **Påminnelser och ordrar** läser namn, adress, e-post, telefon och personnummer från kontakten. Deras egna fält skrivs inte längre som kopia.
3. **Kalendern** läser kunden från kontakten. Bokningens tid ligger kvar på bokningen.
4. **Fakturering och Fortnox** bygger kund, ägare och skattereduktion från kontakten. Fortnox-anropet ändras först då.
5. Därefter kan inkorgens kontaktspegel och `/rot/`-resultatet sluta vara egna kopior och bara peka på `contact_id`.
