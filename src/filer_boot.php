<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
/**
 * Körs före TinyFM. Nginx ignorerar auto_prepend i .htaccess, så live
 * filer.php måste själv börja med:
 * require_once __DIR__ . '/src/filer_boot.php';
 * TinyFM-filen ska inte bytas ut i övrigt vid deploy.
 *
 * IP-låset ligger bara här. index.php och kampanjgrinden i övrigt rörs inte.
 * Listan är data/.private/tinyfm_allowlist.txt (en CIDR per rad). Saknas den
 * blir det 403 innan kampanjrutan. Lägg till en IP genom att redigera filen
 * på servern — ingen ny koddeploy.
 */
require_once __DIR__ . '/tinyfm_allow.php';

if (PHP_SAPI !== 'cli') {
    jeab_tinyfm_guard();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/layout.php';

jeab_gate();
