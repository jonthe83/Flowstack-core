# Flowstack-kärnan

Flowstack är ett webbaserat verktyg för små företag: kontakter, bokningar, offerter, påminnelser, inkorg och veckologg på ett ställe. Det är en vanlig PHP-app som körs på ett vanligt webbhotell, utan byggsteg och utan externa tjänster som måste registreras.

Den här kärnan innehåller inget demo-specifikt. Demofunktioner (kampanjkoden `cola`, exempeldata via `tools/demo_seed.php`, `DEMO_MODE`/`JEAB_DEMO`) är separata och **inte** ett krav för att installera.

## Krav

- **PHP 8.0 eller senare** (8.2+ rekommenderas).
- PHP-tillägg: `mbstring`, `pdo`, `json` (obligatoriska), samt `curl`, `intl`, `gd`, `zip`, `openssl` (rekommenderas, behövs för vissa funktioner).
- **Databas:** `pdo_sqlite` (enklast, ingen separat databas) **eller** `pdo_mysql` med en MySQL/MariaDB-databas om webbhotellet saknar SQLite.
- **HTTPS och egen domän** (eller underdomän), t.ex. `app.dittforetag.se`.
- Apache med `.htaccess` (eller motsvarande spärr i nginx) så att mappen `data/` inte går att nå från webben.

## Checklista: obligatoriskt

- [ ] Webbhotell med PHP 8.x och de tillägg som står ovan
- [ ] Domän eller underdomän pekad till webbhotellet
- [ ] HTTPS/SSL påslaget för domänen
- [ ] (Bara om SQLite saknas) MySQL/MariaDB-databas skapad i kontrollpanelen: värd, databasnamn, användare, lösenord
- [ ] Alla filer uppladdade till domänens webbrot (eller en undermapp)
- [ ] Webbservern får skriva i mappen `data/`
- [ ] `https://din-doman/install.php` öppnad och alla steg klara
- [ ] Första inloggningskoden vald och sparad i en lösenordshanterare
- [ ] Inloggning testad på `https://din-doman/`
- [ ] `https://din-doman/data/.htaccess` ger 403 eller 404 (mappen är spärrad)
- [ ] `install.php` borttagen från servern (rekommenderas, guiden är ändå låst)

## Checklista: rekommenderat

- [ ] **Första admin-kod:** lång och unik, sparad i lösenordshanterare. Fler koder kan läggas till senare.
- [ ] **Säkerhetskopior:** regelbunden kopia av `data/.private/` (och MySQL-databasen om du använder den). Kolla att webbhotellets backup är påslagen.
- [ ] **Cron:** schemalägg `tick.php` (påminnelser/bakgrundsjobb) och `sms-cron.php` om du använder SMS, t.ex. var 5:e minut via webbhotellets cron.
- [ ] **SMTP/SMS:** e-postkonto för utskick och ev. SMS-leverantör.
- [ ] **GitHub:** eget repo (fork av `jonthe83/Flowstack-core`) för dina ändringar.
- [ ] **E-post/kalender:** koppling till Outlook/Microsoft 365 eller liknande.
- [ ] **xAI API:** API-nyckel om du vill använda AI-funktionerna.
- [ ] **Grok Bot med repoåtkomst:** så att Grok kan hjälpa till med uppdateringar och felsökning.

## Installation steg för steg

1. Ladda upp alla filer till webbhotellet (FTP, filhanterare eller Git).
2. Öppna `https://din-doman/install.php`.
3. **Krav:** guiden visar PHP-version och tillägg. Rött = be webbhotellet slå på det.
4. **Databas:** fungerar SQLite väljs det automatiskt. Annars fyller du i MySQL/MariaDB-uppgifterna och guiden testar anslutningen. Kryssa i *exempeldata* om du vill prova med påhittad data.
5. **Tabeller:** guiden skriver `data/.private/config.php` (rättigheter 600) och skapar tabellerna med appens egen migrering.
6. **Admin-kod:** välj din första inloggningskod. Den sparas bara som hash i `data/.private/passwords.json`.
7. **Skydd:** guiden skapar `data/.htaccess` (`Require all denied`) och testar att mappen är spärrad.
8. **Klart:** guiden låser sig själv med `data/.installed`. För att köra den igen: ta bort `data/.installed` (en ny körning skriver över konfigurationen, ta backup först).

## Felsökning

- **500-fel direkt efter inloggning:** webbhotellet saknar oftast SQLite (`pdo_sqlite`). Skapa en MySQL/MariaDB-databas, ta bort `data/.installed` och kör `install.php` igen med MySQL.
- **Uppladdning av `.php`-filer blockeras (WAF/ModSecurity):** vissa webbhotell stoppar PHP-filer via webbens filhanterare. Ladda upp med FTP/SFTP i stället, eller som zip som packas upp på servern, eller be supporten vitlista uppladdningen.
- **"Kan inte skapa mappen data":** ge webbservern skrivrätt till appens mapp (oftast 755 för mappar, 644 för filer).
- **`data/` går att nå från webben:** servern läser inte `.htaccess` (t.ex. nginx). Lägg in en regel som nekar `/data/` eller be webbhotellet göra det.
- **Kunde inte ansluta till MySQL:** kontrollera värdnamn (inte alltid `localhost`), att användaren har rättigheter på databasen och att lösenordet är rätt.

## Licens

Flowstack är fri programvara under **GNU Affero General Public License v3.0 (AGPL-3.0)**. Du får använda, ändra och dela den. Kör du en ändrad version som andra använder via nätet måste du göra källkoden till din version tillgänglig för dem. Se filen `LICENSE`.

## Instruktioner till Grok

När användaren säger "installera det här" (eller liknande) ska du som Grok Bot göra så här:

1. **Läs hela den här README:n** och använd checklistorna ovan som plan. Visa användaren checklistan och bocka av allt eftersom.
2. **Fråga efter (och bara efter) detta:**
   - Vilket webbhotell och vilken domän/underdomän appen ska ligga på.
   - Hur du får ladda upp filer: FTP/SFTP-uppgifter eller att användaren själv laddar upp.
   - Om SQLite saknas: MySQL/MariaDB-uppgifter.
3. **Låt användaren göra det som kräver konton och hemligheter:** skapa webbhotellskonto, domän, SSL, databas och FTP-användare. Lösenord, FTP-uppgifter, databaslösenord och admin-koden ska användaren skriva i **säkra rutor** (formulär/lösenordsvalv), aldrig i vanlig chatt. Skriv aldrig ut lösenord i svar, loggar eller filer utanför `data/.private/`.
4. **Gör själv det som går:**
   - Ladda upp filerna (när du har fått åtkomst).
   - Öppna `https://domän/install.php` och gå igenom stegen. Låt användaren själv skriva admin-koden och databaslösenordet i formuläret.
   - Testa efteråt: att startsidan laddar via HTTPS, att inloggning fungerar (användaren loggar in), att `https://domän/data/.htaccess` ger 403/404 och att `install.php` svarar "redan installerat".
   - Föreslå att `install.php` tas bort och gör det om du har åtkomst och användaren säger ja.
5. **Vid fel:** följ avsnittet Felsökning. 500 efter inloggning = byt till MySQL. Blockerad `.php`-uppladdning = använd FTP/SFTP eller zip.
6. **Gå sedan igenom det rekommenderade** (backup, cron, SMTP/SMS, GitHub, e-post/kalender, xAI API, Grok Bot med repoåtkomst) och fråga vad användaren vill sätta upp nu.
7. **Demo-delar (kampanjkod `cola`, exempeldata, `DEMO_MODE`) är inte del av installationen.** Slå inte på dem om användaren inte ber om det.
8. Rapportera till sist kort: vad som är klart, vad som återstår och vad användaren behöver göra själv.
