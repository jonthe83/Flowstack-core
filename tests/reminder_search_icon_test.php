<?php

declare(strict_types=1);

/**
 * Sökikonen ska ha bredd och höjd i HTML och i app.css, så första paint
 * inte ritar en jätte-SVG innan paminnelser.css hunnit in.
 */

function si_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$reminders = (string) file_get_contents($root . '/src/pages/reminders.php');
$contacts = (string) file_get_contents($root . '/src/pages/kontakter.php');
$css = (string) file_get_contents($root . '/assets/css/app.css');
$layout = (string) file_get_contents($root . '/src/layout.php');

si_assert(str_contains($reminders, 'class="crm-search-ico" width="16" height="16"'), 'sökikonen på Påminnelser har bredd och höjd');
si_assert(str_contains($contacts, 'class="crm-search-ico" width="16" height="16"'), 'sökikonen på Kontakter har bredd och höjd');
si_assert(
    str_contains($css, 'html body.page-reminders .crm-search-ico') && str_contains($css, 'width: 16px;'),
    'app.css ger ikonen storlek innan paminnelser.css'
);
si_assert(str_contains($layout, "assets/css/paminnelser.css"), 'paminnelser.css ligger kvar i sidans stil-lista');

echo "ALLA OK\n";
