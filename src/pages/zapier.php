<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var string $outgoing */
/** @var string $token Maskerad. Aldrig hela hemligheten. */
/** @var string $hookUrl */
/** @var string|null $flash */

ob_start();
?>
<p class="back"><a href="<?= e(jeab_url('index.php')) ?>">Tillbaka</a></p>
<h1 class="page-title">Zapier</h1>
<?= jeab_flash_html($flash ?? null) ?>
<p class="lead">Inga färdiga Zaps — bara rören. Ett utgående anrop när något händer, och en adress Zapier kan POSTa till.</p>

<section class="card">
    <h2>Utgående</h2>
    <p>Klistra in en Catch Hook från Zapier. Appen POSTar JSON vid skapad / uppdaterad / klar / avbokad.</p>
    <form method="post" action="<?= e(jeab_url('index.php?p=zapier')) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="settings.webhook">
        <label class="field">
            <span>Webhook-URL</span>
            <input type="url" name="outgoing_webhook_url" value="<?= e($outgoing) ?>" placeholder="https://hooks.zapier.com/hooks/catch/…">
        </label>
        <button class="btn" type="submit">Spara</button>
    </form>
</section>

<section class="card">
    <h2>Ingående</h2>
    <p>Zapier → Webhooks by Zapier → POST. Skicka token i <code>Authorization: Bearer</code>, header <code>X-Webhook-Token</code>, eller i POST-body. <code>?token=</code> i URL avvisas.</p>
    <label class="field">
        <span>Adress</span>
        <input type="text" readonly value="<?= e($hookUrl) ?>" onclick="this.select()">
    </label>
    <label class="field">
        <span>Token</span>
        <input type="text" readonly value="<?= e(jeab_mask_incoming_token($token)) ?>" autocomplete="off" spellcheck="false">
    </label>
    <form method="post" action="<?= e(jeab_url('index.php?p=zapier')) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="settings.incoming_token">
        <p>Hela token visas inte. Ny token ersätter den som hook.php tar emot, och den gamla slutar gälla.</p>
        <button class="btn" type="submit">Ny token</button>
    </form>
</section>
<?php
return ob_get_clean();
