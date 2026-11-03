<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var string|null $flash */
/** @var string|null $error */

ob_start();
?>
<div class="links-page logg-page bugg-page">
    <h1>Rapportera bugg</h1>
    <?= jeab_flash_html($flash ?? null) ?>
    <?= jeab_error_html($error ?? null) ?>
    <section class="card">
        <form method="post" action="<?= e(jeab_url('index.php?p=bugg')) ?>" enctype="multipart/form-data">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="bugg.submit">
            <label class="field">
                <span>Beskrivning</span>
                <textarea name="description" required maxlength="20000" placeholder="Vad hände?"><?= e((string) ($_POST['description'] ?? '')) ?></textarea>
            </label>
            <label class="field">
                <span>Skärmdump (valfritt, png/jpg/webp, max 5 MB)</span>
                <input type="file" name="screenshot" accept="image/png,image/jpeg,image/webp">
            </label>
            <label class="field">
                <span>Sidans adress (valfritt)</span>
                <input type="url" name="page_url" value="<?= e((string) ($_POST['page_url'] ?? '')) ?>" placeholder="https://flowstack.example.test/index.php?p=…">
            </label>
            <button class="btn" type="submit">Skicka</button>
        </form>
    </section>
</div>
<?php
return ob_get_clean();
