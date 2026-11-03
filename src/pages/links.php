<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
$links = jeab_public_links();

ob_start();
?>
<div class="links-page">
    <ul class="list links-list">
        <?php foreach ($links as $item): ?>
            <?php
            $label = (string) ($item[0] ?? '');
            $href = (string) ($item[1] ?? '');
            $external = !empty($item[2]);
            ?>
            <li>
                <a class="link-row" href="<?= e($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span><?= e($label) ?></span>
                    <i aria-hidden="true">›</i>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= e(jeab_url('index.php')) ?>" class="links-logout">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-danger" type="submit">Logga ut från <?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'JEAB' ?></button>
    </form>
</div>
<?php
return ob_get_clean();
