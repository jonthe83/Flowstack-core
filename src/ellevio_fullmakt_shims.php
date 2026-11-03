<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

if (!function_exists('fullmakt_now')) {
    function fullmakt_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
    }
}

if (!function_exists('fullmakt_client_ip')) {
    function fullmakt_client_ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

if (!function_exists('fullmakt_copy')) {
    function fullmakt_copy(array $doc, string $key): string
    {
        $override = $doc[$key] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $copy = [
            'title' => 'Fullmakt till Ellevio',
            'invite_subject' => 'Fullmakt till Ellevio — signera',
            'invite_body' => "Hej {namn}, för att vi ska kunna anmäla solcellerna till Ellevio behöver du signera fullmakten.\n\nDu måste fylla i anläggnings-id. Det står på elmätaren och på elfakturan.\nDen som signerar måste vara samma person som står på elfakturan.\nI dokumentet kan du välja vem som signerar.\nLänk: {url}\n",
        ];
        return $copy[$key] ?? '';
    }
}
