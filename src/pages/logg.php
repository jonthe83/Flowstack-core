<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var array $entries */
$entries = $entries ?? [];
$stats = function_exists('jeab_app_errors_stats') ? jeab_app_errors_stats($entries) : ['count' => count($entries), 'first' => '', 'last' => ''];
$visaMig = function_exists('jeab_fellogg_latest_visa_mig') ? jeab_fellogg_latest_visa_mig() : null;
$visaFp = is_array($visaMig) ? trim((string) ($visaMig['fingerprint'] ?? $visaMig['bug_id'] ?? '')) : '';
$count = (int) ($stats['count'] ?? 0);
$first = trim((string) ($stats['first'] ?? ''));
$last = trim((string) ($stats['last'] ?? ''));
$opcacheLine = function_exists('jeab_opcache_ini_line') ? jeab_opcache_ini_line() : '';

ob_start();
?>
<div class="links-page logg-page">
    <h1>Fellogg</h1>
    <p class="logg-meta">
        <?= (int) $count ?> fel<?php if ($first !== ''): ?> · första <?= e($first) ?><?php endif; ?><?php if ($last !== ''): ?> · senaste <?= e($last) ?><?php endif; ?><?php if ($opcacheLine !== ''): ?> · <?= e($opcacheLine) ?><?php endif; ?>
    </p>
    <?php if ($entries === []): ?>
        <p class="logg-empty">Inget i loggen.</p>
    <?php else: ?>
        <ul class="list links-list logg-list">
            <?php foreach ($entries as $row): ?>
                <?php
                $time = trim((string) ($row['time'] ?? $row['last'] ?? ''));
                $surface = trim((string) ($row['surface'] ?? ''));
                $message = trim((string) ($row['message'] ?? ''));
                $rowCount = max(1, (int) ($row['count'] ?? 1));
                $rowFp = trim((string) ($row['fingerprint'] ?? ''));
                $isVisa = $visaFp !== '' && $rowFp !== '' && hash_equals($visaFp, $rowFp);
                $diagBits = [];
                $methodBit = trim((string) ($row['method'] ?? ''));
                $urlBit = trim((string) ($row['url'] ?? ''));
                if ($methodBit !== '' || $urlBit !== '') {
                    $diagBits[] = trim($methodBit . ' ' . $urlBit);
                }
                $reasonBit = trim((string) ($row['reason'] ?? ''));
                if ($reasonBit !== '') {
                    $diagBits[] = $reasonBit;
                }
                $orderBit = trim((string) ($row['order'] ?? ''));
                if ($orderBit !== '') {
                    $diagBits[] = $orderBit;
                }
                $nameBit = trim((string) ($row['user_name'] ?? ''));
                if ($nameBit !== '') {
                    $diagBits[] = $nameBit;
                }
                $actorBit = trim((string) ($row['actor'] ?? ''));
                if ($actorBit !== '') {
                    $diagBits[] = $actorBit;
                }
                if (isset($row['curl_errno']) && (string) $row['curl_errno'] !== '') {
                    $curlBit = 'curl ' . (int) $row['curl_errno'];
                    $curlErrorBit = trim((string) ($row['curl_error'] ?? ''));
                    if ($curlErrorBit !== '') {
                        $curlBit .= ' ' . $curlErrorBit;
                    }
                    $diagBits[] = $curlBit;
                }
                $diag = implode(' · ', $diagBits);
                ?>
                <li>
                    <div class="link-row logg-row">
                        <span>
                            <?php if ($rowCount > 1): ?><b>×<?= (int) $rowCount ?></b><?php endif; ?>
                            <?php if ($isVisa): ?><b>visa mig</b><?php endif; ?>
                            <?php if ($time !== ''): ?><b><?= e($time) ?></b><?php endif; ?>
                            <?php if ($surface !== ''): ?><i><?= e($surface) ?></i><?php endif; ?>
                            <?php if ($message !== ''): ?><?= e($message) ?><?php endif; ?>
                            <?php if ($diag !== ''): ?> <small><?= e($diag) ?></small><?php endif; ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
return ob_get_clean();
