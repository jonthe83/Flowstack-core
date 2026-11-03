<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/**
 * Nyckelpar för contacts.drop_customer_fields-backupen. Körs på boxen, inte på servern.
 *
 *   php scripts/contacts_drop_keygen.php <katalog utanför repot>
 *
 * Skriver <katalog>/contacts-drop-backup.key (privat, 0600) och contacts-drop-backup.pub
 * (publik) i en 0700-katalog. Skriver aldrig över en befintlig nyckel och skriver aldrig ut
 * den privata nyckeln: bara sökvägar och publika nyckelns sha256. Den publika filen läggs i
 * config/contacts-drop-backup.pub på servern. Vid undo kopieras den privata till
 * data/.private/contacts-drop-undo.key (0600) en kort stund; servern raderar den efteråt.
 */

$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '') {
    fwrite(STDERR, "Ange en katalog: php scripts/contacts_drop_keygen.php <katalog>\n");
    exit(2);
}
$repo = realpath(dirname(__DIR__));
$parent = realpath(dirname($dir));
if ($parent === false) {
    fwrite(STDERR, "Föräldrakatalogen finns inte.\n");
    exit(2);
}
$abs = $parent . '/' . basename($dir);
if ($repo !== false && ($abs === $repo || str_starts_with($abs . '/', $repo . '/'))) {
    fwrite(STDERR, "Katalogen ligger i repot. Välj en katalog utanför, så den privata nyckeln aldrig checkas in.\n");
    exit(2);
}
if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "openssl saknas i PHP.\n");
    exit(1);
}
$old = umask(0077);
if (!is_dir($abs) && !mkdir($abs, 0700, true) && !is_dir($abs)) {
    fwrite(STDERR, "Katalogen gick inte att skapa.\n");
    exit(1);
}
chmod($abs, 0700);
$privPath = $abs . '/contacts-drop-backup.key';
$pubPath = $abs . '/contacts-drop-backup.pub';
if (file_exists($privPath) || file_exists($pubPath)) {
    fwrite(STDERR, "Det finns redan en nyckel i katalogen. Inget skrevs.\n");
    exit(1);
}
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
if ($key === false) {
    fwrite(STDERR, "Nyckeln gick inte att skapa.\n");
    exit(1);
}
$pem = '';
$info = openssl_pkey_get_details($key);
if (!openssl_pkey_export($key, $pem) || !is_array($info)) {
    fwrite(STDERR, "Nyckeln gick inte att exportera.\n");
    exit(1);
}
$pub = (string) $info['key'];
$fd = fopen($privPath, 'x');
if ($fd === false || fwrite($fd, $pem) === false || !fclose($fd) || !chmod($privPath, 0600)) {
    @unlink($privPath);
    fwrite(STDERR, "Den privata nyckeln gick inte att skriva.\n");
    exit(1);
}
$pem = '';
if (file_put_contents($pubPath, $pub, LOCK_EX) === false || !chmod($pubPath, 0644)) {
    fwrite(STDERR, "Den publika nyckeln gick inte att skriva.\n");
    exit(1);
}
umask($old);
$der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pub), true);
echo "privat: {$privPath} (0600, skrivs inte ut)\n";
echo "publik: {$pubPath}\n";
echo 'pub_sha256: ' . hash('sha256', (string) $der) . "\n";
