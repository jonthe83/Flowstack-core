<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

function fullmakt_layout_start(string $title, string $pageClass = ''): void
{
    fullmakt_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $cssFile = FULLMAKT_ROOT . '/assets/app.css';
    $css = fullmakt_web_base() . '/assets/app.css';
    if (is_file($cssFile)) {
        $css .= '?v=' . (string) filemtime($cssFile);
    }
    $cls = $pageClass !== '' ? ' page ' . $pageClass : ' page';
    ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body>
    <div class="<?= e(trim($cls)) ?>">
    <?php
}

function fullmakt_layout_end(?string $js = null): void
{
    ?>
    </div>
    <?php if ($js): ?>
    <?php
    $jsFile = FULLMAKT_ROOT . '/assets/' . $js;
    $jsUrl = fullmakt_web_base() . '/assets/' . $js;
    if (is_file($jsFile)) {
        $jsUrl .= '?v=' . (string) filemtime($jsFile);
    }
    ?>
    <script src="<?= e($jsUrl) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
    <?php
}

function fullmakt_dl_row(string $label, string $value): void
{
    ?>
    <div class="kv">
        <dt><?= e($label) ?></dt>
        <dd><?= $value === '' ? '—' : e($value) ?></dd>
    </div>
    <?php
}
