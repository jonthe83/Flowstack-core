<?php

declare(strict_types=1);

/**
 * Påminnelser-listkort (stängt): siffran utan ordet «mail», kort datum «14/10»,
 * telefonen utanför adressraden. Påhittad data.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-card-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function cc_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$now = jeab_now();
$due = '2026-10-14 09:00:00';
$row = [
    'id' => 14,
    'title' => 'Norrskensvägen 12',
    'address' => 'Norrskensvägen 12',
    'customer_name' => 'Test Kort',
    'due_at' => $due,
    'phone' => '0701112233',
    'mailat' => 1,
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
];

cc_assert(jeab_format_due_short($due) === '14/10', 'kort datum är 14/10');
cc_assert(jeab_format_due($due) !== '14/10' && str_contains(jeab_format_due($due), '14'), 'långt datum behåller veckodag och dag');

$html = jeab_reminder_row($row, false);
cc_assert(preg_match('#<div class="item-shut-meta">\s*<span class="item-due-chip">14/10<span class="item-due-clock"> 09:00</span></span>#', $html) === 1, 'stängt listkort visar 14/10 och klockslaget i en egen span');
cc_assert(preg_match('#<span class="item-due-chip">[^<]*(ons|okt|idag)[^<]*</span>#', $html) !== 1, 'stängt chip har inte veckodag eller månadsnamn');
cc_assert(!str_contains($html, 'name="address"') && !str_contains($html, 'data-js="snooze"') && !str_contains($html, 'item-head-open'), 'stängt kort ritar inte redigeraren');
$openRow = jeab_reminder_row($row, true);
cc_assert(preg_match('#<button type="button" class="item-due-chip"[^>]*>' . preg_quote(jeab_reminder_due_with_clock(jeab_format_due($due), $due), '#') . '</button>#', $openRow) === 1, 'öppet kort behåller det långa datumet och klockslaget');

cc_assert(preg_match('#<span class="item-tap-total" title="1 mail" aria-label="1 mail">1</span>#', $html) === 1, 'siffran 1 med title och aria-label «1 mail»');
cc_assert(substr_count($html, '>1 mail<') === 0, 'ordet mail syns inte som text');
cc_assert(substr_count($html, 'class="item-tap-total"') === 2, 'siffran finns på stängt kort och i den dolda titelraden');
cc_assert(substr_count($openRow, 'class="item-tap-total"') >= 2, 'öppet kort behåller siffran');

$copy = strpos($html, 'class="item-shut-copy"');
$meta = strpos($html, 'class="item-shut-meta"');
$call = strpos($html, 'class="item-call"');
$addrEnd = strpos($html, '</p>', (int) $copy);
cc_assert($copy !== false && $meta !== false && $addrEnd !== false && $meta > $addrEnd, 'högerkolumnen ligger efter adressraden');
cc_assert(preg_match('#<div class="item-shut-meta">\s*<span class="item-due-chip">14/10<span class="item-due-clock"> 09:00</span></span>.*?<span class="item-tap-total"[^>]*>1</span>.*?<a class="item-call"#s', $html) === 1, 'datum överst, siffran till vänster om telefonen');
cc_assert($call !== false && $call > $meta && !str_contains(substr($html, $copy, $meta - $copy), 'item-call'), 'telefonen sitter inte i adressraden');
cc_assert(str_contains($html, 'class="item-call" href="tel:'), 'ring-länken finns kvar');

$two = jeab_reminder_row(array_merge($row, ['mailat' => 2, 'ringt' => 0]), false);
cc_assert(preg_match('#<span class="item-tap-total" title="2 mail" aria-label="2 mail">2</span>#', $two) === 1, 'två kontakter: synlig siffra 2, etikett «2 mail»');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?page-reminders:not\(\.page-kontakter\).*?\.item:not\(\.is-open\) \.item-shut-meta:has\(\.item-call\) \{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*max-content 44px;[^}]*grid-template-rows:\s*auto 44px;[^}]*padding-top:\s*0;/s', $css) === 1, 'mobil: datumet har en egen rad ovanför telefonen');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?\.item-shut-meta \.item-due-clock \{[^}]*display:\s*none;/s', $css) === 1, 'mobil: klockslaget på det stängda kortet är dolt');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?\.item-shut-meta \.item-due-chip \{[^}]*position:\s*static;[^}]*grid-row:\s*1;/s', $css) === 1, 'mobil: datumet ligger ovanför telefonen');
cc_assert(!str_contains($css, 'padding-top: calc(11px * 1.2 + 2px)'), 'mobil: ingen extra höjd ovanför telefonen');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?page-reminders:not\(\.page-kontakter\).*?\.item-call \{[^}]*grid-row:\s*2;[^}]*justify-self:\s*end;[^}]*width:\s*44px;[^}]*height:\s*44px;/s', $css) === 1, 'mobil: telefonen är 44px under datumet');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?\.item:not\(\.is-open\) \.item-shut-addr \{[^}]*font-size:\s*15px;[^}]*text-overflow:\s*clip;/s', $css) === 1, '639-regeln sätter 15px');
cc_assert(preg_match('/@media \(max-width: 899px\) \{.*?\.item:not\(\.is-open\) \.item-shut-addr,\s*.*?\.item:not\(\.is-open\) \.item-shut-name \{[^}]*overflow:\s*hidden;[^}]*text-overflow:\s*ellipsis;/s', $css) === 1 && strpos($css, 'text-overflow: clip') < strpos($css, '@media (max-width: 899px)'), 'under 900px: adress och namn får ellips efter 639-regeln');
$desktopCss = strstr($css, '@media (max-width: 639px)', true);
cc_assert(is_string($desktopCss) && !str_contains($desktopCss, '.item-due-clock') && str_contains($desktopCss, 'text-overflow: ellipsis'), 'dator: klockslaget syns och adressraden behåller sin ellips');
cc_assert(preg_match('/\.item:not\(\.is-open\) \.item-inkorg-row \{\s*display:\s*none !important;/', $css) === 1, 'Inkorgsärende är dolt på stängt kort');
$withThread = jeab_reminder_row(array_merge($row, ['thread_id' => 'g-kort']), false);
$openThread = jeab_reminder_row(array_merge($row, ['thread_id' => 'g-kort']), true);
cc_assert(str_contains($withThread, 'class="item-inkorg"') && !str_contains($withThread, ' is-open'), 'stängt kort har länken i markupen men inte klassen is-open');
cc_assert(str_contains($openThread, ' is-open') && str_contains($openThread, '>Öppna ärendet<'), 'öppet kort visar Öppna ärendet');
cc_assert(preg_match('/is-late[^{]*\{[^}]*(background|border)/', $css) !== 1, 'ingen ny försenad-bakgrund');

$linked = jeab_reminder_row(array_merge($row, ['contact_id' => 'c-14']), false);
$metaAt = strpos($linked, 'class="item-shut-meta"');
$metaEnd = $metaAt === false ? false : strpos($linked, '</div>', $metaAt);
$metaHtml = ($metaAt === false || $metaEnd === false) ? '' : substr($linked, $metaAt, $metaEnd - $metaAt);
$linkAt = strpos($linked, 'class="item-link-row"');
$kontaktAt = strpos($linked, 'class="item-kontakt"');
cc_assert($metaHtml !== '' && !str_contains($metaHtml, 'item-kontakt'), 'Kontakt sitter inte i datumets rutnät');
cc_assert($linkAt !== false && $kontaktAt !== false && $kontaktAt > $linkAt, 'Kontakt ligger på länkaden');
cc_assert(!str_contains($linked, 'item-kontakt-row'), 'Kontakt är inte en egen rad under anteckningen');
cc_assert(substr_count($linked, 'class="item-kontakt"') === 1, 'Kontakt finns en gång, på länkaden');
cc_assert(str_contains($css, '.item-link-row a.item-kontakt') && str_contains($css, 'min-height: 44px'), 'Kontakt är en 44px-träffyta');
cc_assert(preg_match('/@media \(max-width: 480px\) \{.*?\.item:not\(\.is-open\) \.item-head-open[^}]*display:\s*none !important;/s', $css) === 1, 'mobil: stängt kort visar inte adressfältets höjd');

cc_assert(str_contains($linked, 'class="item-shut-addr"') && str_contains($linked, 'class="item-shut-name"'), 'adress och namn är egna rader i mallen');
$nameAt = strpos($linked, 'class="item-shut-name"');
$addrAt = strpos($linked, 'class="item-shut-addr"');
cc_assert($nameAt !== false && $addrAt !== false && $addrAt < $nameAt, 'adressen ligger före namnet');
cc_assert(preg_match('/@media \(max-width: 899px\) \{.*?\.item:not\(\.is-open\) \.item-shut-copy \{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/s', $css) === 1, 'under 900px: namnet ligger på raden under adressen');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?\.item:not\(\.is-open\) \.item-shut-addr,\s*.*?\.item:not\(\.is-open\) \.item-shut-name \{\s*display:\s*block;[^}]*line-height:\s*1\.15;/s', $css) === 1, 'mobil: namn och adress är fortfarande egna element');
cc_assert(!str_contains($css, 'item-shut-name::before'), 'mobil: namnet skiljs inte med punkt på samma rad');
$noted = jeab_reminder_row(array_merge($row, ['note' => "Första raden\nAndra raden som inte ska ta höjd"]), false);
cc_assert(preg_match('#<div class="item-note">Första raden\nAndra raden som inte ska ta höjd</div>#', $noted) === 1, 'stängt kort har anteckningen i mallen');
$openNoted = jeab_reminder_row(array_merge($row, ['note' => "Första raden\nAndra raden som inte ska ta höjd"]), true);
cc_assert(str_contains($openNoted, ' is-open') && str_contains($openNoted, '<textarea class="item-note box-field"') && str_contains($openNoted, 'Första raden'), 'öppet kort har anteckningen i textrutan');
cc_assert(substr_count($openNoted, '<div class="item-note">') === 0, 'öppet kort från servern har ingen förhandsrad under textrutan');
cc_assert(preg_match('/\.item\.is-open \.item-stack > \.item-note \{\s*display:\s*none;/', $css) === 1, 'öppet kort döljer förhandsraden som klicket lämnar kvar under textrutan');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?\.item:not\(\.is-open\) \.item-stack > \.item-note,.*?\.item-head-shut \.item-note \{\s*display:\s*-webkit-box;[^}]*-webkit-line-clamp:\s*3;[^}]*white-space:\s*normal;/s', $css) === 1, 'mobil: anteckningen visar tre rader');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?page-reminders:not\(\.page-kontakter\) \.apple-list \.item:not\(\.is-open\) \{\s*padding:\s*4px 8px !important;[^}]*line-height:\s*1\.15;/s', $css) === 1, 'mobil: stängt kort har tätare padding och radhöjd');
cc_assert(preg_match('/@media \(max-width: 639px\) \{.*?page-reminders:not\(\.page-kontakter\) \.apple-list \.item:not\(\.is-open\) \.item-link-row \{\s*display:\s*none !important;/s', $css) === 1, 'mobil: Kontakt är dold på stängt kort');
cc_assert(!preg_match('/\.item\.is-open[^{]*item-kontakt[^{]*\{[^}]*display:\s*none/', $css), 'öppet kort döljer inte Kontakt');
$openLinked = jeab_reminder_row(array_merge($row, ['contact_id' => 'c-14']), true);
cc_assert(str_contains($openLinked, ' is-open') && substr_count($openLinked, 'class="item-kontakt"') === 1, 'öppet kort behåller Kontakt i mallen');
$desktopCss = strstr($css, '@media (max-width: 639px)', true);
cc_assert(is_string($desktopCss) && !str_contains($desktopCss, '.item:not(.is-open) .item-link-row {'), 'dator: stängt kort behåller Kontakt-raden');

$kontakter = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
cc_assert(!str_contains($kontakter, 'item-tap-total') && !str_contains($kontakter, 'item-call'), 'Kontakter-listan har egen markup');

foreach ([['Båtbyggarvägen 12', 'Järfälla'], ['trestegsv 2', 'Sollentuna']] as [$street, $town]) {
    $long = jeab_reminder_row(array_merge($row, [
        'id' => 15,
        'title' => $street,
        'address' => $street . ', ' . $town,
        'mailat' => 12,
    ]), false);
    $addrAt = strpos($long, 'class="item-shut-addr">');
    $addrEnd = $addrAt === false ? false : strpos($long, '</p>', $addrAt);
    $addrHtml = ($addrAt === false || $addrEnd === false) ? '' : substr($long, $addrAt, $addrEnd - $addrAt);
    cc_assert(str_contains($addrHtml, $street) && str_contains($addrHtml, $town), 'hela adressen och orten finns i markupen: ' . $street . ', ' . $town);
    cc_assert(!str_contains($addrHtml, '…') && !str_contains($addrHtml, '&hellip;'), 'adressraden har ingen ellips i markupen');
    cc_assert(preg_match('#<span class="item-due-chip">[^<]*<span class="item-due-clock"> \d{2}:\d{2}</span>#', $long) === 1, 'klockslaget ligger kvar i markupen för datorn');
}

echo "ALLA OK\n";
