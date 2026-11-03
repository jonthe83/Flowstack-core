<?php

declare(strict_types=1);

/**
 * Testnyckelpar för contacts.drop_customer_fields, skapas i testets tmp-katalog.
 * Inga riktiga nycklar. t_drop_undo_key() lägger den privata nyckeln i den tillfälliga
 * undo-filen (0600) inför ett undo-anrop.
 */
function t_drop_keypair(string $dir, string $name = 'a'): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $pem = '';
    openssl_pkey_export($key, $pem);
    $pub = (string) openssl_pkey_get_details($key)['key'];
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/' . $name . '.pub', $pub);
    file_put_contents($dir . '/' . $name . '.key', $pem);
    chmod($dir . '/' . $name . '.key', 0600);

    return ['pub' => $dir . '/' . $name . '.pub', 'priv' => $dir . '/' . $name . '.key', 'pem' => $pem, 'pub_pem' => $pub];
}

function t_drop_use_pub(array $pair): void
{
    $GLOBALS['_JEAB_DROP_PUBKEY_PATH'] = $pair['pub'];
}

function t_drop_undo_key(array $pair, int $mode = 0600): string
{
    $path = jeab_contacts_drop_undo_key_path();
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $pair['pem']);
    chmod($path, $mode);

    return $path;
}
