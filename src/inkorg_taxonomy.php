<?php

declare(strict_types=1);

/**
 * Ny klassning (Sam 2026-10-15): typerna Ny lead, Support, Bokning/ombokning, Kräver åtgärd från oss,
 * Information, Skräp, Oklassad. Åtgärd och prioritet visas inte längre (härleds ur typen).
 * Etiketterna Skräp och Support finns. Migreringen nedan flyttar befintliga ärenden, exempel, regler, vyer
 * och instruktionen till de nya värdena (säkerhetskopia först).
 */

function jeab_inkorg_taxonomy_text(string $t): string
{
    $pairs = [
        'Typ Spara, åtgärd arkivera, prioritet 4, etikett Test.' => 'Typ Information, etikett Test.',
        'Ny lead med prioritet 1' => 'Ny lead',
        'skräp: Säljförsök till oss. Nyhetsbrev och reklam → Nyhetsbrev/reklam. Nätfiske eller bedrägeri → Skräp/nätfiske.' => 'Skräp. Nyhetsbrev, reklam, nätfiske och bedrägeri → Skräp.',
        'annars Fråga/Ärende från befintlig kund, Klagomål, Bokning/ombokning eller Signering/dokument.' => 'annars Support (frågor, ärenden och klagomål från befintliga kunder) eller Bokning/ombokning. Signering och dokument → Information.',
        'är typen Fråga/Ärende från befintlig kund' => 'är typen Support',
        '(type Nyhetsbrev/reklam eller Skräp/nätfiske)' => '(type Skräp)',
        'Fråga/Ärende från befintlig kund' => 'Support',
    ];
    $t = strtr($t, $pairs);
    $t = (string) preg_replace('/^- Prioritet:[^\n]*\n?/mu', '', $t);
    return strtr($t, jeab_inkorg_type_map());
}

function jeab_inkorg_taxonomy_migrate(bool $dry = false): array
{
    $map = jeab_inkorg_type_map();
    $n = ['messages' => 0, 'examples' => 0, 'rules' => 0, 'views' => 0, 'tags_added' => 0, 'intent' => 0, 'by_type' => []];
    $dir = jeab_inkorg_dir();
    if (!$dry) {
        $bk = $dir . '/backup-taxonomy-' . date('Ymd-His');
        @mkdir($bk, 0770, true);
        foreach (['settings.json', 'rules.json'] as $f) {
            $doc = function_exists('jeab_inkorg_json_file') ? jeab_inkorg_json_file($f) : null;
            if (is_array($doc) && $doc !== []) {
                $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($encoded)) {
                    file_put_contents($bk . '/' . $f, $encoded);
                }
            } elseif (is_file($dir . '/' . $f)) {
                copy($dir . '/' . $f, $bk . '/' . $f);
            }
        }
        $n['backup'] = basename($bk);
    }
    try {
    jeab_inkorg_locked('messages', function (?array $d) use ($map, &$n, $dry) {
        $d = $d ?? ['messages' => []];
        foreach ((array) ($d['messages'] ?? []) as $k => $m) {
            if (!is_array($m) || !is_array($m['labels'] ?? null)) {
                continue;
            }
            $old = (string) ($m['labels']['type'] ?? '');
            $new = $map[$old] ?? $old;
            $changed = $new !== $old;
            if ($new !== '') {
                $act = jeab_inkorg_derived_action($new);
                $pr = jeab_inkorg_default_priority($new);
                $changed = $changed || ($m['labels']['action'] ?? '') !== $act || (int) ($m['labels']['priority'] ?? 0) !== $pr;
                $m['labels']['type'] = $new;
                $m['labels']['action'] = $act;
                $m['labels']['priority'] = $pr;
            }
            if ($changed) {
                $n['messages']++;
                $n['by_type'][$old . ' → ' . $new] = ($n['by_type'][$old . ' → ' . $new] ?? 0) + 1;
                $d['messages'][$k] = $m;
            }
        }
        return [null, $dry || $n['messages'] === 0 ? null : $d];
    });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'SQL saknas') {
            throw $e;
        }

        return ['ok' => false, 'error' => 'SQL saknas', 'dry' => $dry];
    }
    jeab_inkorg_locked('settings.json', function (?array $d) use ($map, &$n, $dry) {
        $d = $d ?? [];
        foreach ((array) ($d['examples'] ?? []) as $i => $e) {
            $l = (string) ($e['label'] ?? '');
            $nl = str_starts_with($l, 'typ:') ? 'typ:' . ($map[substr($l, 4)] ?? substr($l, 4)) : strtr($l, $map);
            if ($nl !== $l) {
                $d['examples'][$i]['label'] = $nl;
                $n['examples']++;
            }
        }
        $cur = trim((string) ($d['intent'] ?? ''));
        if ($cur !== '') {
            $new = jeab_inkorg_taxonomy_text($cur);
            if ($new !== $cur) {
                $d['intent'] = $new;
                $n['intent'] = 1;
            }
        }
        foreach ((array) ($d['quote_templates'] ?? []) as $k => $t) {
            $f = jeab_auto_text_fix((string) $t);
            if ($f !== $t) {
                $d['quote_templates'][$k] = $f;
                $n['templates'] = ($n['templates'] ?? 0) + 1;
            }
        }
        $d['taxonomy_v2'] = date('c');
        return [null, $dry ? null : $d];
    });
    jeab_inkorg_locked('rules.json', function (?array $d) use ($map, &$n, $dry) {
        $d = $d ?? [];
        foreach ((array) ($d['rules'] ?? []) as $i => $r) {
            $t = (string) ($r['type'] ?? '');
            if ($t !== '' && isset($map[$t])) {
                $d['rules'][$i]['type'] = $map[$t];
                $d['rules'][$i]['action'] = jeab_inkorg_derived_action($map[$t]);
                $d['rules'][$i]['priority'] = jeab_inkorg_default_priority($map[$t]);
                $n['rules']++;
            }
        }
        $fixList = function (array $l) use ($map): array {
            return array_values(array_unique(array_map(fn($x) => $map[(string) $x] ?? (string) $x, $l)));
        };
        foreach ((array) ($d['views'] ?? []) as $i => $v) {
            $f = (array) ($v['filter'] ?? []);
            $before = json_encode($f, JSON_UNESCAPED_UNICODE);
            foreach (['types', 'types_not'] as $k) {
                if (isset($f[$k]) && is_array($f[$k])) {
                    $f[$k] = $fixList($f[$k]);
                }
            }
            if (isset($f['type']) && is_string($f['type'])) {
                $f['type'] = $map[$f['type']] ?? $f['type'];
            }
            unset($f['priority']);
            if (json_encode($f, JSON_UNESCAPED_UNICODE) !== $before) {
                $d['views'][$i]['filter'] = $f;
                $n['views']++;
            }
        }
        foreach (['skrap' => 'Skräp', 'support' => 'Support'] as $id => $name) {
            if (!isset($d['tags'][$id])) {
                $d['tags'][$id] = ['name' => $name];
                $n['tags_added']++;
            }
        }
        return [null, $dry ? null : $d];
    });
    return ['ok' => true, 'dry' => $dry] + $n;
}
