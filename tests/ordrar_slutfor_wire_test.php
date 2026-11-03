<?php

declare(strict_types=1);

/**
 * Slutför anropar befintlig bokning och sedan befintlig Fortnox-skrivning.
 * Ingen ny bokningsmotor.
 */

function sw_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sw_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    sw_fail($message);
}

function sw_extract(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        sw_fail("saknar {$name}");
    }
    $brace = strpos($src, '{', $start);
    $depth = 0;
    $len = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    sw_fail("okapad {$name}");
}

class JeabStore
{
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
eval(sw_extract($src, 'jeab_ordrar_slutfor'));

$GLOBALS['sw_calls'] = [];

function jeab_ordrar_save_questions(array $input): array
{
    $GLOBALS['sw_calls'][] = 'save';
    $kind = (string) ($input['kind'] ?? 'laddbox');
    return [
        'kind' => $kind,
        'reminder_id' => (int) ($input['id'] ?? 0),
        'fortnox_order' => '',
        'pris' => (int) ($input['pris'] ?? 0),
        'install_what' => (string) ($input['install_what'] ?? ''),
        'install_note' => (string) ($input['install_note'] ?? ''),
    ];
}

function jeab_ordrar_create_booking(object $store, array $input): array
{
    $GLOBALS['sw_calls'][] = 'book';
    unset($store);
    return [
        'kind' => (string) ($GLOBALS['sw_kind'] ?? 'laddbox'),
        'reminder_id' => (int) ($input['id'] ?? 0),
        'booking_id' => 44,
        'fortnox_order' => '',
    ];
}

function jeab_ordrar_fortnox_on_next(array $row): array
{
    $GLOBALS['sw_calls'][] = 'fortnox';
    $row['fortnox_order'] = '9001';
    return $row;
}

function jeab_ordrar_is_booked(array $row): bool
{
    return (int) ($row['booking_id'] ?? 0) > 0;
}

function jeab_ordrar_books_at_bestall(array $row): bool
{
    $kind = (string) ($row['kind'] ?? '');
    return $kind === 'batteri' || $kind === 'sol';
}

function jeab_ordrar_fortnox_attach_locked(array $row): bool
{
    unset($row);
    return false;
}

$store = new JeabStore();
$row = jeab_ordrar_slutfor($store, [
    'id' => 7,
    'kind' => 'laddbox',
    'pris' => '12500',
    'install_what' => 'Easee Home',
    'install_note' => 'Nyckel under mattan',
]);
sw_assert($GLOBALS['sw_calls'] === ['save', 'book', 'fortnox'], 'slutför: spara, boka, fortnox. fick ' . implode(',', $GLOBALS['sw_calls']));
sw_assert((int) ($row['booking_id'] ?? 0) === 44 && ($row['fortnox_order'] ?? '') === '9001', 'slutför lämnar bokning och fortnox-order');

$GLOBALS['sw_calls'] = [];
$battery = jeab_ordrar_slutfor($store, [
    'id' => 8,
    'kind' => 'batteri',
    'pris' => '70000',
    'install_what' => 'Sigenergy',
    'install_note' => 'Källare',
]);
sw_assert($GLOBALS['sw_calls'] === ['save', 'fortnox'], 'batteri slutför: spara och fortnox, ingen bokning. fick ' . implode(',', $GLOBALS['sw_calls']));
sw_assert((int) ($battery['booking_id'] ?? 0) === 0 && ($battery['fortnox_order'] ?? '') === '9001', 'batteri lämnar Fortnox-order utan bokning');

$GLOBALS['sw_calls'] = [];
$GLOBALS['sw_kind'] = 'sol';
$solar = jeab_ordrar_slutfor($store, ['id' => 9, 'kind' => 'sol', 'pris' => '180000']);
sw_assert($GLOBALS['sw_calls'] === ['save', 'fortnox'], 'sol slutför: spara och fortnox, ingen bokning. fick ' . implode(',', $GLOBALS['sw_calls']));
sw_assert((int) ($solar['booking_id'] ?? 0) === 0 && ($solar['fortnox_order'] ?? '') === '9001', 'sol lämnar Fortnox-order utan bokning');

echo "OK  slutfor wire\n";
