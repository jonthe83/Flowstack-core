<?php

declare(strict_types=1);

/** H-GRAPH-2: delta- och listmarkören flyttas inte förbi ett misslyckat intag. */

$tmp = sys_get_temp_dir() . '/jeab-graph-hold-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gh(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function gh_msg(string $id): array
{
    return [
        'id' => $id,
        'internetMessageId' => '<' . $id . '@kund.test>',
        'conversationId' => 'C-' . $id,
        'from' => ['emailAddress' => ['address' => strtolower($id) . '@exempel.test', 'name' => 'Kund']],
        'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
        'subject' => 'Hej',
        'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z'),
        'body' => ['content' => 'Hej'],
        'isDraft' => false,
    ];
}

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$badOpen = true;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$badOpen): array {
    unset($method, $body, $headers);
    if (str_contains($url, 'deltatoken=hold1')) {
        return ['code' => 200, 'body' => [
            'value' => [
                ['id' => 'BAD1', 'receivedDateTime' => '2026-10-22T10:00:00Z'],
                ['id' => 'GOOD1', 'receivedDateTime' => '2026-10-22T10:05:00Z'],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=hold2',
        ]];
    }
    if (str_contains($url, '/messages/BAD1')) {
        if ($badOpen) {
            return ['code' => 500, 'body' => ['error' => ['message' => 'tillfälligt']]];
        }

        return ['code' => 200, 'body' => gh_msg('BAD1')];
    }
    if (str_contains($url, '/messages/GOOD1')) {
        return ['code' => 200, 'body' => gh_msg('GOOD1')];
    }
    if ($method === 'POST') {
        return ['code' => 202, 'body' => []];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};

jeab_graph_state_update(static function (array &$d): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=hold1';
    $d['delta_listed_through']['jarfalla'] = time();
    unset($d['delta_list']['jarfalla']);

    return true;
});
$first = jeab_graph_delta(0, 3);
$box = (array) ($first['boxes']['jarfalla'] ?? []);
gh((int) ($box['errors'] ?? 0) >= 1, 'deltat räknar felet');
gh(str_contains((string) (jeab_graph_state()['delta']['jarfalla'] ?? ''), 'deltatoken=hold1'), 'misslyckad sida behåller förra deltamarkören');
gh(!str_contains((string) (jeab_graph_state()['delta']['jarfalla'] ?? ''), 'hold2'), 'ny deltaLink skrivs inte');

$badOpen = false;
$second = jeab_graph_delta(0, 3);
gh((int) (($second['boxes']['jarfalla']['errors'] ?? 0)) === 0, 'omförsöket tar in sidan');
gh(str_contains((string) (jeab_graph_state()['delta']['jarfalla'] ?? ''), 'deltatoken=hold2'), 'markören flyttas när varje id är ok');
$haveBad = false;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'BAD1') {
        $haveBad = true;
    }
}
gh($haveBad, 'BAD1 ligger i inkorgen efter omförsöket');

jeab_graph_state_update(static function (array &$d): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=idle';
    $d['delta_list']['jarfalla'] = 'https://graph.microsoft.com/v1.0/list?$skiptoken=bad';
    unset($d['delta_listed_through']['jarfalla']);

    return true;
});
$listFail = true;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$listFail): array {
    unset($method, $body, $headers);
    if (str_contains($url, 'skiptoken=bad')) {
        return ['code' => 200, 'body' => [
            'value' => [['id' => 'LIST1', 'receivedDateTime' => '2026-10-22T11:00:00Z']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/list?$skiptoken=next',
        ]];
    }
    if (str_contains($url, '/messages/LIST1')) {
        if ($listFail) {
            return ['code' => 500, 'body' => ['error' => ['message' => 'tillfälligt']]];
        }

        return ['code' => 200, 'body' => gh_msg('LIST1')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
$st = jeab_graph_list_backfill('jarfalla', 'kontakt@jarfalla.example.test', ['seen' => 0, 'new' => 0, 'duplicates' => 0, 'errors' => 0]);
gh((int) ($st['errors'] ?? 0) >= 1, 'listan räknar felet');
gh((string) (jeab_graph_state()['delta_list']['jarfalla'] ?? '') === 'https://graph.microsoft.com/v1.0/list?$skiptoken=bad', 'listan behåller den misslyckade sidan');
gh(!isset(jeab_graph_state()['delta_listed_through']['jarfalla']), 'listan räknas inte som klar');

$listFail = false;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $body, $headers);
    if (str_contains($url, 'skiptoken=bad')) {
        return ['code' => 200, 'body' => [
            'value' => [['id' => 'LIST1', 'receivedDateTime' => '2026-10-22T11:00:00Z']],
        ]];
    }
    if (str_contains($url, '/messages/LIST1')) {
        return ['code' => 200, 'body' => gh_msg('LIST1')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
jeab_graph_list_backfill('jarfalla', 'kontakt@jarfalla.example.test', ['seen' => 0, 'new' => 0, 'duplicates' => 0, 'errors' => 0]);
gh(!isset(jeab_graph_state()['delta_list']['jarfalla']) && (int) (jeab_graph_state()['delta_listed_through']['jarfalla'] ?? 0) > 0, 'listan stämplas klar när sidan går igenom');

echo "graph cursor hold: ok\n";
