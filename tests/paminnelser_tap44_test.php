<?php

declare(strict_types=1);

/**
 * N-32 / N-49: Klar-bocken har 44×44 träffyta med 28 px cirkel,
 * tel/mail på öppet kort har 44 px träffyta. Bara CSS.
 */

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');

function tap44(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function css_rule(string $css, string $selector): string
{
    $at = strpos($css, $selector);
    if ($at === false) {
        return '';
    }
    $open = strpos($css, '{', $at);
    $close = strpos($css, '}', $open === false ? $at : $open);
    if ($open === false || $close === false) {
        return '';
    }
    return substr($css, $at, $close - $at + 1);
}

$form = css_rule($css, "html body.page-reminders .check-form");
tap44($form !== '', 'N-32 .check-form finns');
tap44(str_contains($form, 'min-width: 44px') && str_contains($form, 'min-height: 44px'), 'N-32 .check-form är minst 44×44');
tap44(str_contains($form, 'display: flex') && str_contains($form, 'align-items: center'), 'N-32 .check-form centrerar bocken');

$check = css_rule($css, "html body.page-reminders .apple-list .check,");
tap44($check !== '', 'N-32 .check finns');
tap44(str_contains($check, 'width: 28px') && str_contains($check, 'height: 28px'), 'N-32 visuell cirkel är 28 px');
tap44(!str_contains($check, 'width: 44px') && !str_contains($check, 'height: 44px'), 'N-32 cirkeln växer inte till 44 px');

$hit = css_rule($css, "html body.page-reminders .apple-list .check::before,");
tap44($hit !== '' && str_contains($hit, 'inset: -8px'), 'N-32 ::before fyller 44×44 runt 28 px-cirkeln');

$tel = css_rule($css, "html body.page-reminders .apple-list .item.is-open a.contact-digits,");
tap44($tel !== '', 'N-49 tel/mail på öppet kort finns');
tap44(str_contains($tel, 'display: inline-flex') && str_contains($tel, 'min-height: 44px'), 'N-49 inline-flex och min-height 44 px');
tap44(str_contains($tel, 'padding:') && str_contains($tel, 'align-items: center'), 'N-49 träffyta via padding och centrering');

echo "ALLA OK\n";
