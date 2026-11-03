<?php

declare(strict_types=1);

/** Klassning i kontaktpanelen skickar inget. Skicka offert tar mallen utan modell. */

$tmp = sys_get_temp_dir() . '/jeab-panel-choice-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
session_save_path($tmp);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function pc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_LLM'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (): string {
    $GLOBALS['_LLM']++;
    return json_encode(['answer' => 'Ja, det ingår.'], JSON_UNESCAPED_UNICODE);
};
$GLOBALS['_SENT_BOX'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => $GLOBALS['_SENT_BOX']]];
    }
    return ['code' => 404, 'body' => []];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 batteri\n")]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode("%PDF-1.4 sol\n")]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 ladd\n")]);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
pc(is_string($jpeg) && str_starts_with($jpeg, "\xFF\xD8"), 'testbilden är JPEG');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$labels = array_column(jeab_inkorg_panel_choices(), 'label');
pc($labels === ['Solceller', 'Batteri', 'Elbilsladdare', 'Service', 'Information', 'Spam'], 'sex val i den ordningen');
pc(jeab_inkorg_panel_choices()['elbilsladdare']['offer'] === 'laddbox', 'elbilsladdare använder laddboxmallen');

$saveSrc = (string) (new ReflectionFunction('jeab_inkorg_panel_choice_save'))->getFileName();
$fn = new ReflectionFunction('jeab_inkorg_panel_choice_save');
$saveBody = implode('', array_slice(file($fn->getFileName()), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
pc(!str_contains($saveBody, 'jeab_auto_send_quote') && !str_contains($saveBody, 'jeab_auto_llm'), 'sparad klassning anropar varken offert eller modell');
unset($saveSrc);

$ingest = static function (string $id, string $email, string $extra = ''): array {
    $body = "Namn: Fejk Kund\nTelefon: 0701234567\nAdress: Fejkgatan 2, 176 70 Järfälla\n";
    if ($email !== '') {
        $body .= "E-post: {$email}\n";
    }
    $body .= $extra;
    return jeab_inkorg_ingest([
        'message_id' => $id . '@panel.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'panel-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
};

$threadOf = static function (string $id): array {
    foreach (jeab_inkorg_threads() as $thread) {
        if ((string) ($thread['id'] ?? '') === $id) {
            return $thread;
        }
    }
    return [];
};

$plain = $ingest('plain', 'fejk.panel@exempel.test');
pc(!empty($plain['ok']), 'ärendet tas in');
$plainTid = (string) $plain['thread_id'];
$open = $threadOf($plainTid);
pc(jeab_inkorg_panel_choice_of($open) === 'batteri', 'batteriklassning läses från offerten');

$link = jeab_inkorg_case_reminder_link($plainTid, 'jarfalla');
pc($link['id'] === 0 && str_contains($link['href'], 'ny=1') && str_contains($link['href'], 'thread='), 'utan påminnelse öppnas ett nytt formulär');
$box = jeab_inkorg_panel_box_html($open, 'batteri', 'fejk.panel@exempel.test', false, $link['href'], 'desk');
pc(substr_count($box, 'type="radio"') === 6, 'ett val per klass');
pc(str_contains($box, 'name="panel_class"') && str_contains($box, 'value="batteri" checked'), 'ett val är förkryssat');
pc(str_contains($box, 'Skicka offert') && str_contains($box, 'name="template_only" value="1"') && str_contains($box, 'name="force_product" value="1"'), 'offertknappen tar mallen');
pc(str_contains($box, '>Skapa påminnelse</a>') && str_contains($box, 'ny=1'), 'påminnelseknappen heter Skapa påminnelse');
pc(!str_contains($box, 'data-send-quote'), 'panelknappen lämnar menyns utskicksknapp ifred');

$before = count($GLOBALS['_SENT']);
foreach (['information' => 'Information', 'spam' => 'Skräp'] as $choice => $type) {
    $_POST = ['thread' => $plainTid, 'panel_class' => $choice];
    pc(jeab_inkorg_action('inkorg.panel_choice') === 'Klassning sparad.', $choice . ' sparas');
    $row = $threadOf($plainTid);
    $last = (array) ($row['last'] ?? []);
    pc(($row['panel_choice'] ?? '') === $choice && (string) (($last['labels']['type'] ?? '')) === $type, $choice . ' sätter typ ' . $type);
    pc(count($GLOBALS['_SENT']) === $before && $GLOBALS['_LLM'] === 0, $choice . ' skickar inget och anropar ingen modell');
}

$svc = $ingest('svc', 'fejk.svc@exempel.test', "Vad kostar batteriet?\n");
$svcTid = (string) $svc['thread_id'];
$_POST = ['thread' => $svcTid, 'panel_class' => 'service'];
pc(jeab_inkorg_action('inkorg.panel_choice') === 'Klassning sparad.', 'service sparas');
pc(count($GLOBALS['_SENT']) === $before && $GLOBALS['_LLM'] === 0, 'service skickar inget');
$_POST = ['thread' => $svcTid, 'dry' => '0', 'template_only' => '1', 'force_product' => '1', 'product' => 'batteri'];
$blocked = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $blocked = $e;
}
pc($blocked instanceof InvalidArgumentException && str_contains($blocked->getMessage(), 'klassen är Service'), 'service stoppar offerten');
pc(count($GLOBALS['_SENT']) === $before && $GLOBALS['_LLM'] === 0, 'stoppad serviceoffert skickar inget');

$ask = "Meddelande:\nFår 10 kWh plats i garaget?\n";
$dry = $ingest('dry', 'fejk.dry@exempel.test', $ask);
$dryTid = (string) $dry['thread_id'];
$previewSent = count($GLOBALS['_SENT']);
$previewLlm = $GLOBALS['_LLM'];
$_POST = ['thread' => $dryTid, 'dry' => '1', 'template_only' => '1'];
$noProduct = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $noProduct = $e;
}
pc($noProduct instanceof InvalidArgumentException && str_contains($noProduct->getMessage(), 'Solceller'), 'förhandsgranskning utan produkt skickar inte');
pc(count($GLOBALS['_SENT']) === $previewSent && $GLOBALS['_LLM'] === $previewLlm, 'saknad produkt rör varken utkorg eller modell');
$_POST = ['thread' => $dryTid, 'dry' => '1', 'template_only' => '1', 'force_product' => '1', 'product' => 'batteri'];
$previewFlash = jeab_inkorg_action('inkorg.send_quote');
pc(str_contains($previewFlash, 'Inget skickat') && count($GLOBALS['_SENT']) === $previewSent && $GLOBALS['_LLM'] === $previewLlm, 'förhandsgranskning av vald produkt skickar inget');
$res = jeab_auto_send_quote(['thread_id' => $dryTid, 'dry' => '1', 'product' => 'batteri', 'force_product' => '1']);
pc(($res['ok'] ?? false) === true && ($res['dry'] ?? 0) === 1 && $GLOBALS['_LLM'] === 1 && str_contains((string) ($res['body'] ?? ''), 'Ja, det ingår') && count($GLOBALS['_SENT']) === $before, 'en fråga anropar modellen när mallen inte är ensam');

$quote = $ingest('quote', 'fejk.quote@exempel.test', $ask);
$quoteTid = (string) $quote['thread_id'];
$_POST = ['thread' => $quoteTid, 'panel_class' => 'batteri'];
pc(jeab_inkorg_action('inkorg.panel_choice') === 'Klassning sparad.', 'produktval sparas utan utskick');
pc(count($GLOBALS['_SENT']) === $before && $GLOBALS['_LLM'] === 1, 'produktval ökar inte utskick eller modellanrop');
$_POST = ['thread' => $quoteTid, 'dry' => '0', 'template_only' => '1', 'force_product' => '1', 'product' => 'batteri'];
pc(jeab_inkorg_action('inkorg.send_quote') === 'Offerten skickad.', 'mallofferten skickas');
pc(count($GLOBALS['_SENT']) === $before + 1 && $GLOBALS['_LLM'] === 1, 'mallofferten hoppar över modellen och skickas en gång');
$sentMail = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
pc(str_contains((string) ($sentMail['subject'] ?? ''), 'Offert batteri') && !str_contains((string) ($sentMail['body'] ?? ''), 'Ja, det ingår'), 'batterimallen skickas utan modellsvaret');

$again = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $again = $e;
}
pc($again instanceof JeabAlreadySentException, 'samma offert skickas inte igen');
pc(count($GLOBALS['_SENT']) === $before + 1, 'sent_log stoppar andra försöket');

$after = $threadOf($quoteTid);
$existing = jeab_inkorg_case_reminder_link($quoteTid, 'jarfalla');
pc($existing['id'] > 0 && !str_contains($existing['href'], 'ny=1'), 'befintlig påminnelse öppnas');
$sentBox = jeab_inkorg_panel_box_html($after, 'batteri', 'fejk.quote@exempel.test', false, $existing['href'], 'mobile');
pc(str_contains($sentBox, '>Skapa påminnelse</a>') && str_contains($sentBox, 'disabled>Offert skickad</button>'), 'knapparna efter utskick');
$prepBefore = count($GLOBALS['_SENT']);
$prepared = jeab_reminder_create_prepare(jeab_store(), ['from_case' => '1', 'thread_id' => $quoteTid]);
pc(is_array($prepared['_existing'] ?? null) && count($GLOBALS['_SENT']) === $prepBefore, 'påminnelse från ärendet skickar inget och återanvänder den som finns');

$testLead = $ingest('test', 'kund@example.com', "Vad kostar batteriet?\n");
$_POST = ['thread' => (string) $testLead['thread_id'], 'dry' => '0', 'template_only' => '1', 'force_product' => '1', 'product' => 'batteri'];
$testErr = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $testErr = $e;
}
pc($testErr instanceof InvalidArgumentException && str_contains(mb_strtolower($testErr->getMessage()), 'test'), 'testkund stoppas');
pc(count($GLOBALS['_SENT']) === $prepBefore && $GLOBALS['_LLM'] === 1, 'testkund får inget mejl');

$nomail = $ingest('nomail', '');
$_POST = ['thread' => (string) $nomail['thread_id'], 'dry' => '0', 'template_only' => '1', 'force_product' => '1', 'product' => 'batteri'];
$mailErr = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $mailErr = $e;
}
pc($mailErr instanceof InvalidArgumentException && (str_contains($mailErr->getMessage(), 'kundadress') || str_contains($mailErr->getMessage(), 'saknas')), 'utan e-post skickas ingen offert');
pc(count($GLOBALS['_SENT']) === $prepBefore, 'saknad e-post lämnar utkorgen orörd');

$_POST = ['thread' => $quoteTid, 'dry' => '0', 'template_only' => '1', 'product' => 'hej'];
$bad = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $bad = $e;
}
pc($bad instanceof InvalidArgumentException && str_contains($bad->getMessage(), 'Solceller'), 'malloffert kräver en av de tre produkterna');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $plainTid];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
foreach (['>Sol<', '>Batteri<', '>Elbilsladdare<', '>Service<', '>Information<', '>Spam<', 'Skicka offert', 'Skapa påminnelse', 'template_only', 'ix-kund-body'] as $needle) {
    pc(str_contains($html, $needle), 'sidan visar ' . $needle);
}
$panelPreview = strpos($html, 'data-panel-preview="1"');
$panelEnd = $panelPreview === false ? false : strpos($html, '</form>', $panelPreview);
$panelSlice = ($panelPreview === false || $panelEnd === false) ? '' : substr($html, $panelPreview, $panelEnd - $panelPreview);
pc(
    str_contains($panelSlice, 'name="product" value="')
    && str_contains($panelSlice, 'name="force_product" value="1"')
    && str_contains($panelSlice, 'name="template_only" value="1"')
    && str_contains($panelSlice, 'name="dry" value="1"')
    && str_contains($panelSlice, '>Förhandsgranska</button>'),
    'Förhandsgranska i panelen postar den valda produkten'
);
$kundAt = strpos($html, 'id="ix-kund-body"');
$kundEnd = $kundAt === false ? false : strpos($html, '</aside>', $kundAt);
$kund = ($kundAt === false || $kundEnd === false) ? '' : substr($html, $kundAt, $kundEnd - $kundAt);
$typAt = strpos($kund, 'aria-label="Typ"');
$prodAt = strpos($kund, 'aria-label="Produkt"');
pc($typAt !== false && $prodAt !== false && $typAt < $prodAt, 'typ och produkt sitter i kundpanelen');
pc(substr_count($html, '>Skapa påminnelse<') + substr_count($html, '>Påminnelse<') === 1, 'det finns en påminnelseknapp');
pc(!str_contains($html, '<h2>Etiketter</h2>'), 'etikettblocket är borta');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/inkorg.css');
$mobilePanel = false;
if (preg_match_all('/@media \(max-width: 760px\) \{/', $css, $marks, PREG_OFFSET_CAPTURE) === 1 || $marks[0] !== []) {
    foreach ($marks[0] as $mark) {
        $start = (int) $mark[1];
        $depth = 0;
        $end = $start;
        $len = strlen($css);
        for ($i = $start; $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $block = substr($css, $start, $end - $start);
        if (str_contains($block, 'body.page-inkorg .ix-kund-toggle') && str_contains($block, 'body.page-inkorg .ix-kund-body[hidden]') && str_contains($block, 'display: none !important') && str_contains($block, 'body.page-inkorg .ix-v2 .ix-side') && str_contains($block, 'display: block')) {
            $mobilePanel = true;
        }
    }
}
pc($mobilePanel, 'mobilen fäller ut kundpanelen med typ och produkt');

echo "inkorg panel choice ok\n";
