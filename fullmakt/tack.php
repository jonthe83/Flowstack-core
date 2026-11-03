<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/view.php';

fullmakt_layout_start('Tack — dokumentet är signerat');
?>
<header class="brand">
    <p class="brand-kicker"><?= e(FULLMAKT_BRAND_KICKER) ?></p>
    <h1>Tack</h1>
</header>
<section class="card">
    <p class="lead">Dokumentet är signerat.</p>
    <p class="help">Du kan stänga den här sidan. Länken fungerar inte att använda igen.</p>
</section>
<?php
fullmakt_layout_end();
