# Demoläge (demo.flowstack.se)

Demoläget slås på med miljövariabeln `JEAB_DEMO=1` eller `'demo' => true` i `data/.private/config.php` (se `jeab_demo_env_on()` / `jeab_demo_mode()` i `src/demo.php`).
När det är av beter sig appen som vanligt; all demokod är vilande.

## Var demokoden ligger

| Del | Fil / funktion |
|---|---|
| Av/på | `src/demo.php`: `jeab_demo_mode()` |
| Kampanjkod-login (`cola`) | `src/demo.php`: `jeab_demo_campaign_code_ok()`, `jeab_demo_person()`, `jeab_demo_auth_overlay()`; anropas från `jeab_auth_attempt()` i `src/auth.php` |
| Spärr av mejl/SMS/HTTP ut | `src/demo.php`: `jeab_demo_sent()` (mejl/SMS låtsas lyckas) och `jeab_demo_outbound()` (övriga anrop). Loggas i `data/.private/demo-send.log` |
| Spärr av cron/hooks | `jeab_demo_forbid_cron()`, `jeab_demo_forbid_hook()` |
| Avstängda delar (manuell order, säck, solkampanj) | `jeab_demo_route_guard()` (index.php), `jeab_demo_filter_links()` (src/links.php), guard i `solkampanj.php` |
| Kalkyl av | `jeab_demo_feature_off('kalkyl')` |
| Varumärke (Flowstack-logga, titel) | `jeab_demo_brand_logo_svg()`, `jeab_demo_public_name()`, `jeab_demo_title()` |
| Utskicksmallar (5 exempel) | `jeab_notiser_demo_rows()` i `src/notiser.php` |
| Fellogg dold, ingen who-diag | `index.php` (p=logg) och `src/who_identity.php` |
| Exempeldata | `tools/demo_seed.php` (körs inte om; skriver över data) |

Banner/statusrad renderas inte längre (`jeab_demo_banner_html()` ger tom sträng).

## Inte demo-specifikt

`src/app_settings.php` + Övrigt → Inställningar: bolags- och modulspakar. Generellt, sparas i tabellen `settings` (`app.settings`).

## Stänga av demon

Ta bort `JEAB_DEMO` / demo-flaggan i config. Alla `jeab_demo_*`-anrop är skyddade med `function_exists`/`jeab_demo_mode()`, så kärnan går utan demokod.
