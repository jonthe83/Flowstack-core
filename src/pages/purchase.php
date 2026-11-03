<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var array $purchase */
/** @var array $reminder */
/** @var int $step */
/** @var string|null $flash */
/** @var string|null $error */

$purchase = $purchase ?? [];
if (is_array($purchase) && function_exists('jeab_purchase_shown')) {
    $purchase = jeab_purchase_shown($purchase);
}
$reminder = $reminder ?? [];
$step = ((int) ($step ?? 1)) >= 2 ? 2 : 1;
$kind = (string) ($purchase['kind'] ?? '');
$label = jeab_purchase_kind_label($kind);
$company = (string) ($purchase['company'] ?? jeab_reminder_company($reminder));
$lines = jeab_purchase_pad_lines($purchase['lines'] ?? []);
$applicant = is_array($purchase['applicant'] ?? null) ? $purchase['applicant'] : [];
$email = trim((string) ($purchase['email'] ?? ''));
$phone = trim((string) ($purchase['phone'] ?? ''));
$back = jeab_reminders_url(['open' => (int) ($reminder['id'] ?? 0), 'co' => $company]);

ob_start();
echo jeab_error_html($error ?? null);
?>
<section class="buy">
    <header class="buy-head">
        <a class="buy-back" href="<?= e($back) ?>">Till påminnelsen</a>
        <h1><?= e($label !== '' ? $label : 'Köp') ?></h1>
        <?= jeab_flash_html($flash ?? null) ?>
    </header>

    <?php if ($step === 1): ?>
        <form class="buy-card" method="post" action="<?= e(jeab_purchase_url(['id' => (int) $reminder['id'], 'step' => 1, 'co' => $company])) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="purchase.save">
            <input type="hidden" name="id" value="<?= (int) $reminder['id'] ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <input type="hidden" name="company" value="<?= e($company) ?>">
            <input type="hidden" name="step" value="1">

            <label class="buy-field">
                <span>Mailadress</span>
                <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email">
            </label>
            <label class="buy-field">
                <span>Telefonnummer</span>
                <input type="tel" name="phone" value="<?= e($phone) ?>" autocomplete="tel">
            </label>

            <div class="buy-lines" role="group" aria-label="Order">
                <?php foreach ($lines as $i => $line): ?>
                    <div class="buy-line">
                        <label class="buy-field">
                            <span><?= $i === 0 ? 'Artikel' : '' ?></span>
                            <input type="text" name="line_title[]" value="<?= e((string) ($line['title'] ?? '')) ?>" autocomplete="off">
                        </label>
                        <label class="buy-field buy-amount">
                            <span><?= $i === 0 ? 'Belopp' : '' ?></span>
                            <input type="text" inputmode="decimal" name="line_amount[]" value="<?= e((string) ((int) round((float) ($line['amount'] ?? 0)))) ?>" autocomplete="off">
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <button class="buy-primary" type="submit">Nästa</button>
        </form>
    <?php else: ?>
        <form class="buy-card" method="post" action="<?= e(jeab_purchase_url(['id' => (int) $reminder['id'], 'step' => 2, 'co' => $company])) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="purchase.save">
            <input type="hidden" name="id" value="<?= (int) $reminder['id'] ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <input type="hidden" name="company" value="<?= e($company) ?>">
            <input type="hidden" name="step" value="2">

            <div class="buy-person is-on">
                <p class="buy-role is-on">Huvudsökande</p>
                <label class="buy-field">
                    <span>Namn</span>
                    <input type="text" name="applicant_name" value="<?= e((string) ($applicant['name'] ?? '')) ?>" autocomplete="name">
                </label>
                <p class="buy-read"><span>Telefon</span> <?= $phone !== '' ? e($phone) : '—' ?></p>
                <p class="buy-read"><span>Mail</span> <?= $email !== '' ? e($email) : '—' ?></p>
            </div>

            <div class="buy-person">
                <p class="buy-role">Medsökande</p>
                <label class="buy-field">
                    <span>Namn</span>
                    <input type="text" name="coapplicant_name" value="<?= e((string) (($purchase['coapplicant']['name'] ?? ''))) ?>" autocomplete="name">
                </label>
                <p class="buy-read"><span>Telefon</span> <?= $phone !== '' ? e($phone) : '—' ?></p>
                <p class="buy-read"><span>Mail</span> <?= $email !== '' ? e($email) : '—' ?></p>
            </div>

            <button class="buy-primary" type="submit">Spara</button>
        </form>
    <?php endif; ?>
</section>
<?php
return ob_get_clean();
