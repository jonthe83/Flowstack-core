<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

jeab_redirect(jeab_legacy_bokning_target(), 302);
