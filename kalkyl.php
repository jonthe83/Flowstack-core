<?php

declare(strict_types=1);

// Kalkylatorn. Rollen solkampanj får den här sidan och solkampanj.php, inget annat.
if ((string) ($_GET['graph_hook'] ?? '') !== '' || array_key_exists('validationToken', $_GET)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Okänd sida.';
    exit;
}

require __DIR__ . '/index.php';
