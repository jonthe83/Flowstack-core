<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_inkop_path(): string
{
    return jeab_data_file('inkop-mallar.json');
}

function jeab_inkop_ensure_file(): string
{
    $path = jeab_inkop_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_file($path)) {
        file_put_contents($path, "{}");
    }
    return $path;
}

function jeab_inkop_load(): array
{
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkop_mallar') && function_exists('jeab_cutover_read_rows')) {
        $rows = null;
        $booted = !function_exists('jeab_cutover_type_boot') || jeab_cutover_type_boot('inkop_mallar');
        if ($booted) {
            try {
                $rows = jeab_cutover_read_rows('inkop_mallar');
            } catch (Throwable) {
                $rows = null;
            }
        }
        if (is_array($rows)) {
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }
                $mall = jeab_inkop_normalize_mall($payload, (string) ($row['mall_id'] ?? ($payload['id'] ?? '')));
                if ($mall !== null) {
                    $out[$mall['id']] = $mall;
                }
            }
            return $out;
        }
    }
    $path = jeab_inkop_ensure_file();
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $mall = jeab_inkop_normalize_mall($row, (string) $key);
        if ($mall !== null) {
            $out[$mall['id']] = $mall;
        }
    }
    return $out;
}

function jeab_inkop_write(array $mallar): void
{
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkop_mallar') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('inkop_mallar', static function (array $current) use ($mallar): array {
            unset($current);

            return $mallar;
        });

        return;
    }
    $path = jeab_inkop_ensure_file();
    $payload = $mallar === [] ? new stdClass() : $mallar;
    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function jeab_inkop_line_max(): int
{
    return 100;
}

function jeab_inkop_normalize_lines(mixed $lines): array
{
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        if (count($out) >= jeab_inkop_line_max()) {
            break;
        }
        if (!is_array($line)) {
            continue;
        }
        $sku = trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? ''));
        $title = trim((string) ($line['title'] ?? $line['artikelnamn'] ?? ''));
        $qtyRaw = $line['qty'] ?? $line['antal'] ?? '';
        $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : 0;
        if ($qty < 0) {
            $qty = 0;
        }
        if ($sku === '' && $title === '' && $qty === 0) {
            continue;
        }
        $out[] = [
            'sku' => $sku,
            'title' => $title,
            'qty' => $qty,
        ];
    }
    return $out;
}

function jeab_inkop_normalize_mall(array $row, string $fallbackId = ''): ?array
{
    $id = trim((string) ($row['id'] ?? $fallbackId));
    if ($id === '') {
        return null;
    }
    $kind = (string) ($row['kind'] ?? 'batteri');
    $kind = $kind === 'sol' ? 'sol' : 'batteri';
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        $name = $kind === 'sol' ? 'Solpaneler' : 'Batteri';
    }
    $updated = trim((string) ($row['updated_at'] ?? ''));
    if ($updated === '') {
        $updated = jeab_now();
    }
    return [
        'id' => $id,
        'name' => mb_substr($name, 0, 80),
        'kind' => $kind,
        'lines' => jeab_inkop_normalize_lines($row['lines'] ?? []),
        'updated_at' => $updated,
    ];
}

function jeab_inkop_list(): array
{
    $rows = array_values(jeab_inkop_load());
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
    });
    return $rows;
}

function jeab_inkop_default_leverans(): string
{
    $tz = new DateTimeZone('Europe/Stockholm');
    return (new DateTimeImmutable('now', $tz))->modify('+7 days')->format('Y-m-d');
}

function jeab_inkop_kr(int $amount): string
{
    return number_format($amount, 0, ',', "\u{202F}");
}

/** @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>} */
function jeab_inkop_apply(array $mallar, array $post): array
{
    $id = trim((string) ($post['id'] ?? ''));
    $kind = (string) ($post['kind'] ?? 'batteri') === 'sol' ? 'sol' : 'batteri';
    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '') {
        $name = $kind === 'sol' ? 'Solpaneler' : 'Batteri';
    }
    $lines = $post['lines'] ?? [];
    if (is_string($lines)) {
        $decoded = json_decode($lines, true);
        $lines = is_array($decoded) ? $decoded : [];
    }
    if ($id === '' || !isset($mallar[$id])) {
        if (count($mallar) >= 10) {
            $oldestId = null;
            $oldestAt = null;
            foreach ($mallar as $existingId => $existing) {
                $at = (string) ($existing['updated_at'] ?? '');
                if ($oldestAt === null || $at < $oldestAt) {
                    $oldestAt = $at;
                    $oldestId = (string) $existingId;
                }
            }
            if ($oldestId !== null) {
                unset($mallar[$oldestId]);
            }
        }
        $id = 'ink_' . bin2hex(random_bytes(6));
    }
    $row = [
        'id' => $id,
        'name' => mb_substr($name, 0, 80),
        'kind' => $kind,
        'lines' => jeab_inkop_normalize_lines($lines),
        'updated_at' => jeab_now(),
    ];
    $mallar[$id] = $row;
    if (count($mallar) > 10) {
        $sorted = $mallar;
        uasort($sorted, static function (array $a, array $b): int {
            return strcmp((string) $a['updated_at'], (string) $b['updated_at']);
        });
        while (count($sorted) > 10) {
            $drop = array_key_first($sorted);
            unset($sorted[$drop], $mallar[$drop]);
        }
    }
    return [$mallar, $row];
}

function jeab_inkop_save(array $post): array
{
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkop_mallar') && function_exists('jeab_cutover_locked')) {
        $saved = null;
        jeab_cutover_locked('inkop_mallar', static function (array $mallar) use ($post, &$saved): array {
            [$mallar, $row] = jeab_inkop_apply($mallar, $post);
            $saved = $row;

            return $mallar;
        });

        return is_array($saved) ? $saved : [];
    }
    $mallar = jeab_inkop_load();
    [$mallar, $row] = jeab_inkop_apply($mallar, $post);
    jeab_inkop_write($mallar);

    return $row;
}

function jeab_inkop_fortnox_account(string $company): string
{
    return $company === 'malmo' ? 'Malmo' : 'Jarfalla';
}

function jeab_inkop_set_company(string $company): array
{
    jeab_remember_company($company);
    $id = jeab_selected_company();
    return [
        'company' => $id,
        'fortnoxAccount' => jeab_inkop_fortnox_account($id),
    ];
}

function jeab_inkop_boot(): array
{
    $company = jeab_selected_company();
    return [
        'action' => jeab_url('index.php'),
        'csrf' => jeab_csrf_token(),
        'company' => $company,
        'fortnoxAccount' => jeab_inkop_fortnox_account($company),
        'leverans' => jeab_inkop_default_leverans(),
        'mallar' => jeab_inkop_list(),
    ];
}

function jeab_inkop_boot_json(): string
{
    return json_encode(jeab_inkop_boot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}
