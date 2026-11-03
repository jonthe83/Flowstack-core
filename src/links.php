<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_public_links(): array
{
    $links = [
        ['Utskick', jeab_url('index.php?p=utskick'), false],
        ['Kontakter', jeab_url('index.php?p=kontakter'), false],
        ['Manuell order', jeab_url('index.php?p=manuell-order'), false],
        ['Jordtag - Dokumentation', 'https://kalkyl.jarfalla.example.test/jord.html', true],
        ['Sökt på solceller', 'https://api.kalkyl.jarfalla.example.test/dashboard/', true],
        ['Hämtning av säck', jeab_url('index.php?p=sack'), false],
        ['Interna anteckningar', jeab_url('index.php?p=notes'), false],
        ['Rapportera bugg', jeab_url('index.php?p=bugg'), false],
        ['Fellogg', jeab_url('index.php?p=logg'), false],
        ['E-signering', jeab_url('index.php?p=fullmakter'), false],
    ];
    if (function_exists('jeab_demo_feature_off') && jeab_demo_feature_off('kalkyl')) {
        $links = array_values(array_filter($links, static function (array $item): bool {
            return !str_contains((string) ($item[1] ?? ''), 'kalkyl.jarfalla.example.test');
        }));
    }
    if (function_exists('jeab_is_admin') && jeab_is_admin()) {
        $links[] = ['Solkampanj', jeab_url('solkampanj.php'), false];
    }
    if (function_exists('jeab_demo_filter_links')) {
        $links = jeab_demo_filter_links($links);
    }
    if (function_exists('jeab_app_module_on')) {
        $links = array_values(array_filter($links, static function (array $item): bool {
            $href = (string) ($item[1] ?? '');
            return !(str_contains($href, 'p=notes') && !jeab_app_module_on('notes'))
                && !(str_contains($href, 'p=fullmakter') && !jeab_app_module_on('fullmakter'));
        }));
        $links[] = ['Inställningar', jeab_url('index.php?p=installningar'), false];
    }
    if (function_exists('jeab_login_diag_allowed') && jeab_login_diag_allowed()) {
        $links[] = ['Användare', jeab_url('index.php?p=inloggdiag'), false];
    }

    return $links;
}
