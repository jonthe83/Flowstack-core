<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

$back = jeab_url('index.php?p=links');

ob_start();
?>
<div class="links-page">
    <p class="apple-eyebrow">Övrigt</p>
    <h1>Användare</h1>
    <p>Ansvarsområde per person. Påminnelseägare, offertägare, offert-avsändare och installation läses härifrån. Ingen ruta ikryssad för en produkt och region betyder ingen regel.</p>
    <p><a href="<?= e($back) ?>">Tillbaka till Övrigt</a></p>
    <?= function_exists('jeab_users_responsibility_html') ? jeab_users_responsibility_html() : '' ?>
    <h2>Inloggningar</h2>
    <p>De nio första tecknen i koden identifierar inloggningen. Resten av koden visas eller sparas aldrig. En inloggning utan person, delad eller tvetydig kan kopplas manuellt.</p>
    <?= jeab_login_diag_html() ?>
</div>
<?php
return ob_get_clean();
