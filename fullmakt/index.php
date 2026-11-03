<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/view.php';

http_response_code(404);
fullmakt_layout_start('Signera');
?>
<header class="brand">
    <p class="brand-kicker"><?= e(FULLMAKT_BRAND_KICKER) ?></p>
    <h1>Signera dokument</h1>
</header>
<section class="card">
    <p class="lead">Öppna länken i e-postmeddelandet för att signera.</p>
</section>
<?php
fullmakt_layout_end();
