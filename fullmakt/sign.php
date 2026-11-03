<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
fullmakt_boot();
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/pdf.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/parties.php';
fullmakt_load_jeab();

$token = fullmakt_request_token();
$doc = $token !== '' ? fullmakt_load_doc($token) : null;

if ($doc === null) {
    http_response_code(404);
    fullmakt_layout_start('Länken gäller inte');
    echo '<header class="brand"><p class="brand-kicker">' . e(FULLMAKT_BRAND_KICKER) . '</p><h1>Länken gäller inte</h1></header>';
    echo '<section class="card"><p class="lead">Dokumentet hittades inte. Be oss skicka en ny länk om du behöver signera igen.</p></section>';
    fullmakt_layout_end();
    exit;
}

if (($doc['status'] ?? '') === 'signed') {
    // Already-signed invite link: show the signed PDF itself (no token/path/sign-URL in body).
    if (fullmakt_serve_signed_pdf($token)) {
        exit;
    }
    http_response_code(404);
    fullmakt_layout_start('Signerat dokument');
    echo '<header class="brand"><p class="brand-kicker">' . e(FULLMAKT_BRAND_KICKER) . '</p><h1>Signerat dokument</h1></header>';
    echo '<section class="card"><p class="lead">Dokumentet är signerat men kunde inte visas just nu. Be oss skicka filen om du behöver den.</p></section>';
    fullmakt_layout_end();
    exit;
}

$prepared = fullmakt_backfill_anlaggnings_id(fullmakt_ensure_parties($doc));
if ($prepared !== $doc) {
    try {
        fullmakt_save_pending($prepared);
        $doc = $prepared;
    } catch (Throwable $e) {
        $doc = $prepared;
        fullmakt_log('error.log', 'partier ' . $e->getMessage());
    }
}

$error = '';
$docType = fullmakt_doc_type($doc);
$title = fullmakt_copy($doc, 'title');
$lead = fullmakt_copy($doc, 'lead');
$kicker = fullmakt_copy($doc, 'kicker');
$button = fullmakt_copy($doc, 'button');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'byt-part') {
    $which = (string) ($_POST['part'] ?? '');
    $index = (int) ($_POST['med_index'] ?? 0);
    if ($index < 0) {
        $index = 0;
    }
    $next = fullmakt_apply_party($doc, $which === 'medsokande' ? 'medsokande' : 'huvudsokande', $index);
    try {
        fullmakt_save_pending($next);
    } catch (Throwable $e) {
        fullmakt_log('error.log', 'byt-part ' . $e->getMessage());
    }
    header('Location: ' . fullmakt_web_base() . '/s/' . rawurlencode($token), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sig = fullmakt_decode_signature_png((string) ($_POST['underskrift'] ?? ''));
    $postedAid = array_key_exists('anlaggnings_id', $_POST)
        ? (string) $_POST['anlaggnings_id']
        : (string) ($doc['anlaggnings_id'] ?? '');
    $aid = fullmakt_anlaggnings_id_digits($postedAid);
    $needsAid = fullmakt_anlaggnings_id_required($doc);

    if ($needsAid) {
        $error = fullmakt_anlaggnings_id_error($aid);
    }
    if ($error === '' && ($sig === null || !fullmakt_signature_has_ink($sig))) {
        $error = 'Signera med finger/mus eller skriv namnet i fältet under.';
    }
    if ($error === '') {
        try {
            if ($needsAid) {
                $doc['anlaggnings_id'] = $aid;
            }
            [$pdf, $method] = fullmakt_build_pdf($doc, $sig);
            $extra = ['stamp_method' => $method];
            if ($needsAid) {
                $extra['anlaggnings_id'] = $aid;
            }
            $signed = fullmakt_mark_signed($token, $doc, $pdf, $extra);
            $mailed = !empty($signed['signed_winner']) ? fullmakt_send_signed_pdf($signed) : false;
            fullmakt_log('mail.log', 'signed mailed=' . (int) $mailed . ' method=' . $method);
            header('Location: ' . fullmakt_web_base() . '/tack.php', true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $error = str_contains($message, 'anläggnings-id')
                ? $message
                : 'Kunde inte spara dokumentet. Försök igen om en stund.';
            fullmakt_log('error.log', $message . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

$pageClass = $docType === 'overlay' ? 'is-overlay' : '';
fullmakt_layout_start($title, $pageClass);
?>
<header class="brand">
    <p class="brand-kicker"><?= e($kicker) ?></p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($lead) ?></p>
</header>

<?php if ($error !== ''): ?>
    <p class="banner" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?= fullmakt_party_switch_html($doc) ?>

<?php if ($docType === 'overlay'): ?>
<section class="card">
    <h2>Dokument</h2>
    <p class="help">Undertecknare: <?= e((string) $doc['namn']) ?></p>
    <figure class="preview">
        <img src="<?= e(fullmakt_web_base() . '/preview.php?token=' . rawurlencode($token)) ?>" alt="Dokument att signera">
    </figure>
</section>
<?php else: ?>
<?php $typ = (string) ($doc['typ'] ?? 'privatperson'); ?>
<section class="card" id="blankett-uppgifter">
    <h2>Anläggningsinnehavare<?php if (($doc['part'] ?? '') === 'medsokande'): ?> — medsökande<?php else: ?> — huvudsökande<?php endif; ?></h2>
    <div class="typer" role="group" aria-label="Typ av innehavare">
        <?php foreach (FULLMAKT_TYPER as $key => $label): ?>
            <span class="typ<?= $typ === $key ? ' is-on' : '' ?>">
                <span class="box" aria-hidden="true"><?= $typ === $key ? '✓' : '' ?></span>
                <?= e($label) ?>
            </span>
        <?php endforeach; ?>
    </div>
    <dl class="grid">
        <?php
        fullmakt_dl_row('För- och efternamn', (string) $doc['namn']);
        fullmakt_dl_row('Person-/organisationsnummer', (string) ($doc['personnummer'] ?? ''));
        fullmakt_dl_row('Företagsnamn', (string) ($doc['foretagsnamn'] ?? ''));
        fullmakt_dl_row('Telefon', (string) ($doc['telefon'] ?? ''));
        fullmakt_dl_row('E-post', (string) ($doc['epost'] ?? ''));
        fullmakt_dl_row('Folkbokförings-/sätesadress', (string) ($doc['adress'] ?? ''));
        fullmakt_dl_row('Postnummer', (string) ($doc['postnummer'] ?? ''));
        fullmakt_dl_row('Ort', (string) ($doc['ort'] ?? ''));
        fullmakt_dl_row('Anläggningsadress', (string) ($doc['anlaggningsadress'] ?? ''));
        ?>
    </dl>
    <p class="help"><a href="<?= e(fullmakt_web_base() . '/blankett.php?token=' . rawurlencode($token)) ?>">Visa blanketten</a> innan du signerar.</p>
</section>

<section class="card">
    <h2>Installatör (fullmaktshavare)</h2>
    <dl class="grid">
        <?php
        fullmakt_dl_row('Installationsföretag', FULLMAKT_INSTALLER['foretag']);
        fullmakt_dl_row('Organisationsnummer', FULLMAKT_INSTALLER['orgnr']);
        fullmakt_dl_row('För- och efternamn/Installatör', FULLMAKT_INSTALLER['namn']);
        fullmakt_dl_row('Telefonnummer', FULLMAKT_INSTALLER['telefon']);
        fullmakt_dl_row('Adress', FULLMAKT_INSTALLER['adress']);
        fullmakt_dl_row('E-postadress', FULLMAKT_INSTALLER['epost']);
        fullmakt_dl_row('Postnummer', FULLMAKT_INSTALLER['postnummer']);
        fullmakt_dl_row('Ort', FULLMAKT_INSTALLER['ort']);
        ?>
    </dl>
</section>
<?php endif; ?>

<form class="card form" id="sign-form" method="post" action="" novalidate>
    <?php if ($docType !== 'overlay'): ?>
    <?php
    $aidShown = fullmakt_prefill_anlaggnings_id($doc, $_POST);
    $aidError = ($error !== '' && str_contains($error, 'anläggnings-id')) ? $error : '';
    ?>
    <h2>Anläggnings-id</h2>
    <p class="help">Obligatoriskt innan signering. 18 siffror, står vid elmätaren och på elfakturan. Förifyllt om ordern redan har id:t.</p>
    <p class="banner" id="aid-error" role="alert"<?= $aidError === '' ? ' hidden' : '' ?>><?= e($aidError) ?></p>
    <label class="sr-only" for="anlaggnings_id">Anläggnings-id, 18 siffror</label>
    <div class="aid-wrap<?= $aidError !== '' ? ' is-invalid' : '' ?>">
        <input
            id="anlaggnings_id"
            name="anlaggnings_id"
            class="aid-input"
            inputmode="numeric"
            autocomplete="off"
            spellcheck="false"
            maxlength="40"
            pattern="[0-9]{18}"
            required
            aria-invalid="<?= $aidError !== '' ? 'true' : 'false' ?>"
            aria-describedby="aid-error"
            value="<?= e($aidShown) ?>"
        >
        <div class="aid" id="aid-boxes" aria-hidden="true"></div>
    </div>
    <style>
    .aid-wrap.is-invalid .aid-box { border-color: #ff453a; }
    #aid-error { margin-bottom: 12px; }
    </style>
    <?php endif; ?>
    <h2>Underskrift</h2>
    <p class="help">Rita med finger eller mus, eller skriv namnet så det återges med skriftstil. Minst ett av sätten krävs.</p>
    <style>
    @font-face {
        font-family: "Great Vibes";
        src: url("<?= e(fullmakt_web_base()) ?>/assets/fonts/GreatVibes-Regular.woff2") format("woff2");
        font-weight: 400;
        font-style: normal;
        font-display: swap;
    }
    #skriv-namn {
        font-family: "Great Vibes", cursive;
        font-size: 34px;
        height: 64px;
        line-height: 1.1;
    }
    </style>
    <div class="pad-wrap">
        <canvas id="pad" width="640" height="220" aria-label="Signatur"></canvas>
    </div>
    <div class="pad-tools">
        <label class="skriv">
            <span>Skriv namn</span>
            <input id="skriv-namn" type="text" maxlength="80" autocomplete="name" placeholder="För- och efternamn">
        </label>
        <button type="button" class="ghost" id="rensa">Rensa</button>
    </div>
    <input type="hidden" name="underskrift" id="underskrift" value="">

    <button type="submit" class="primary" id="skicka"><?= e($button) ?></button>
</form>
<?php
fullmakt_layout_end('app.js');
