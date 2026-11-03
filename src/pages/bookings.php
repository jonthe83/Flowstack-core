<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var DateTimeImmutable $monday */
/** @var int $weekOffset */
/** @var array $bookings */
/** @var array $selectedInstallers */
/** @var string|null $flash */
/** @var string|null $error */
/** @var array|null $fromReminder */

$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$whoFilter = jeab_booking_who_filter();
$jobFilter = jeab_booking_job_filter();
$company = jeab_selected_company();
$monthView = jeab_booking_view() === 'month';
$dayView = jeab_booking_view() === 'day';
$focusDay = $dayView ? jeab_calendar_focus_day() : null;
$fromReminder = is_array($fromReminder ?? null) ? $fromReminder : null;
$monthDate = $monday->modify('first day of this month')->setTime(0, 0);
$monthMondays = jeab_mondays_in_month((int) $monthDate->format('Y'), (int) $monthDate->format('n'));
if ($monthMondays === []) {
    $monthMondays = [$monday];
}
$monthFrom = $monthMondays[0];
$monthTo = end($monthMondays)->modify('+5 days');
$outlookEvents = is_array($outlookEvents ?? null) ? array_values($outlookEvents) : [];
$passedBookings = is_array($bookings ?? null) ? $bookings : [];
$companyRows = jeab_filter_bookings_for_company($passedBookings, $company);
$officeRows = is_array($officeBookings ?? null) ? $officeBookings : $companyRows;
$bookings = jeab_filter_bookings($companyRows, $whoFilter, $jobFilter);
if ($outlookEvents !== [] && jeab_calendar_include_outlook($jobFilter)) {
    $prepared = jeab_calendar_prepare_visible($bookings, $officeRows, $outlookEvents);
    $bookings = $prepared['bookings'];
    $outlookEvents = $prepared['outlook'];
} else {
    $outlookEvents = [];
}
$byDay = [];
$allDayRows = [];
$outlookAllDay = [];
$outlookByDay = [];
foreach ($bookings as $row) {
    if (jeab_booking_is_all_day((string) $row['starts_at'], (string) $row['ends_at'])) {
        $place = jeab_booking_allday_placement((string) $row['starts_at'], (string) $row['ends_at'], $monday);
        if ($place) {
            $allDayRows[] = ['row' => $row, 'place' => $place];
        }
        continue;
    }
    $key = (new DateTimeImmutable($row['starts_at']))->format('Y-m-d');
    $byDay[$key][] = $row;
}

$days = [];
if ($focusDay instanceof DateTimeImmutable) {
    $days[] = $focusDay;
} else {
    for ($i = 0; $i < 5; $i++) {
        $days[] = $monday->modify('+' . $i . ' days');
    }
}
foreach ($outlookEvents as $event) {
    if (!empty($event['all_day'])) {
        $place = jeab_booking_allday_placement((string) $event['starts_at'], (string) $event['ends_at'], $monday);
        if ($place) {
            $outlookAllDay[] = ['event' => $event, 'place' => $place];
        }
        continue;
    }
    foreach ($days as $day) {
        $place = jeab_booking_grid_placement((string) $event['starts_at'], (string) $event['ends_at'], $day);
        if ($place) {
            $outlookByDay[$day->format('Y-m-d')][] = ['event' => $event, 'place' => $place];
        }
    }
}
$allDayPack = [];
foreach ($allDayRows as $idx => $item) {
    $allDayPack[] = [
        'key' => 'b' . $idx,
        'start' => (int) $item['place']['col'],
        'end' => (int) $item['place']['col'] + (int) $item['place']['span'],
    ];
}
foreach ($outlookAllDay as $idx => $item) {
    $allDayPack[] = [
        'key' => 'o' . $idx,
        'start' => (int) $item['place']['col'],
        'end' => (int) $item['place']['col'] + (int) $item['place']['span'],
    ];
}
$allDayLanes = jeab_calendar_pack_lanes($allDayPack);
$allDayPacked = 0;
foreach ($allDayLanes as $lane) {
    $allDayPacked = max($allDayPacked, (int) $lane['lane'] + 1);
}
// Alltid en tom rad under bokade heldagar (0 bokningar → 1 rad).
$allDayRowsCount = $allDayPacked + 1;

$timedByDay = [];
foreach ($days as $day) {
    $key = $day->format('Y-m-d');
    $list = [];
    $pack = [];
    foreach ($byDay[$key] ?? [] as $idx => $row) {
        $place = jeab_booking_grid_placement((string) $row['starts_at'], (string) $row['ends_at'], $day);
        if (!$place) {
            continue;
        }
        $id = 'b' . $idx;
        $list[] = ['kind' => 'booking', 'key' => $id, 'row' => $row, 'place' => $place];
        $pack[] = ['key' => $id, 'start' => (int) $place['row'], 'end' => (int) $place['row'] + (int) $place['span']];
    }
    foreach ($outlookByDay[$key] ?? [] as $idx => $item) {
        $id = 'o' . $idx;
        $list[] = ['kind' => 'outlook', 'key' => $id, 'event' => $item['event'], 'place' => $item['place']];
        $pack[] = ['key' => $id, 'start' => (int) $item['place']['row'], 'end' => (int) $item['place']['row'] + (int) $item['place']['span']];
    }
    $lanes = jeab_calendar_pack_lanes($pack);
    foreach ($list as $i => $entry) {
        $list[$i]['lane'] = $lanes[$entry['key']] ?? ['lane' => 0, 'lanes' => 1, 'overlap' => false];
    }
    $timedByDay[$key] = $list;
}

$clockSlots = jeab_calendar_slots($monday);
$friday = $monday->modify('+4 days');
$weekLabel = jeab_week_range_label($monday);
$navLabel = $monthView ? jeab_month_label($monthDate) : $weekLabel;
if ($focusDay instanceof DateTimeImmutable) {
    $navLabel = jeab_calendar_sv_date($focusDay->format('Y-m-d'), true);
}
$prevMonth = $monthDate->modify('-1 month');
$nextMonth = $monthDate->modify('+1 month');
$prevMonthMonday = jeab_month_anchor_monday((int) $prevMonth->format('Y'), (int) $prevMonth->format('n'));
$nextMonthMonday = jeab_month_anchor_monday((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'));
$prevHref = $monthView
    ? jeab_bookings_url(['w' => jeab_week_offset_for_monday($prevMonthMonday)])
    : jeab_bookings_url(['w' => $weekOffset - 1]);
$nextHref = $monthView
    ? jeab_bookings_url(['w' => jeab_week_offset_for_monday($nextMonthMonday)])
    : jeab_bookings_url(['w' => $weekOffset + 1]);
$prevAria = $monthView ? 'Föregående månad' : 'Föregående vecka';
$nextAria = $monthView ? 'Nästa månad' : 'Nästa vecka';
if ($focusDay instanceof DateTimeImmutable) {
    $prevHref = jeab_bookings_url(['d' => jeab_calendar_weekday_step($focusDay, -1)->format('Y-m-d')]);
    $nextHref = jeab_bookings_url(['d' => jeab_calendar_weekday_step($focusDay, 1)->format('Y-m-d')]);
    $prevAria = 'Föregående dag';
    $nextAria = 'Nästa dag';
}
// Vyväljaren: Månad · Vecka · Idag. Vald vy igen = tillbaka till nu.
$monthHref = jeab_bookings_url(['view' => 'month', 'w' => $monthView ? 0 : $weekOffset]);
$weekHref = jeab_bookings_url(['view' => '', 'w' => ($monthView || $dayView) ? $weekOffset : 0]);
$todayHref = jeab_bookings_url(['view' => 'day', 'd' => jeab_calendar_weekday_step($today, 0)->format('Y-m-d')]);
$chipParams = static fn(int $off): array => $dayView ? ['w' => $off, 'view' => ''] : ['w' => $off];
$redDays = jeab_calendar_red_days($monthView ? $monthFrom : $monday, $monthView ? $monthTo : $monday->modify('+4 days'));
$viewName = $monthView ? 'month' : ($dayView ? 'day' : 'week');
$todayKey = $today->format('Y-m-d');

// Veckologgen hämtas när den öppnas. Den håller inte sidans load.
$weekLog = [];

if (isset($store) && $store instanceof JeabStore) {
    $titleIds = [];
    foreach ($weekLog as $entry) {
        if (!is_array($entry) || trim((string) ($entry['title'] ?? '')) !== '') {
            continue;
        }
        $titleId = (int) ($entry['booking_id'] ?? 0);
        if ($titleId > 0) {
            $titleIds[$titleId] = $titleId;
        }
    }
    if ($titleIds !== []) {
        $titled = jeab_bookings_by_ids($store, array_values($titleIds));
        foreach ($weekLog as $i => $entry) {
            if (!is_array($entry) || trim((string) ($entry['title'] ?? '')) !== '') {
                continue;
            }
            $logged = $titled[(int) ($entry['booking_id'] ?? 0)] ?? null;
            if (is_array($logged)) {
                $weekLog[$i]['title'] = jeab_booking_title($logged);
            }
        }
    }
}

ob_start();
echo jeab_error_html($error ?? null);
?>
<div class="cal-page" data-js="cal-page">
<header class="crm-top">
    <div class="crm-top-text">
        <p class="apple-eyebrow"><?= e($company === 'malmo' ? 'Malmö' : 'Järfälla') ?></p>
        <h1 class="crm-title">Kalender</h1>
        <p class="cal-stale" data-js="cal-stale" hidden>uppdateras…</p>
    </div>
    <button type="button" class="chip chip-primary crm-add" data-js="new-booking">+ Boka ny tid</button>
</header>
<div class="week-nav">
    <div class="week-nav-row">
    <div class="week-nav-shift">
    <a class="icon-btn week-nav-prev" href="<?= e($prevHref) ?>" aria-label="<?= e($prevAria) ?>" data-js="cal-nav">‹</a>
    <details class="week-pick">
        <summary class="week-label"><?= e($navLabel) ?></summary>
        <div class="week-pick-box" data-base="<?= e(jeab_week_monday(0)->format('Y-m-d')) ?>" data-who="<?= e((string) $whoFilter) ?>" data-job="<?= e((string) $jobFilter) ?>" data-view="<?= e($monthView ? 'month' : ($dayView ? 'day' : '')) ?>">
            <input class="week-date" type="date" aria-label="Gå till datum" autocomplete="off">
            <div class="week-weeks">
                <?php for ($off = -1; $off <= 8; $off++): ?>
                    <?php
                    $chipMon = jeab_week_monday($off);
                    $chipFri = $chipMon->modify('+4 days');
                    $chipRange = ((int) $chipMon->format('n') === (int) $chipFri->format('n'))
                        ? $chipMon->format('j') . '–' . jeab_calendar_sv_date($chipFri->format('Y-m-d'))
                        : jeab_calendar_sv_date($chipMon->format('Y-m-d')) . '–' . jeab_calendar_sv_date($chipFri->format('Y-m-d'));
                    ?>
                    <a class="week-chip<?= $chipMon->format('Y-m-d') === $monday->format('Y-m-d') ? ' is-on' : '' ?>" href="<?= e(jeab_bookings_url($chipParams($off))) ?>">
                        <span class="week-chip-nr">v<?= e($chipMon->format('W')) ?></span>
                        <span class="week-chip-range"><?= e($chipRange) ?></span>
                    </a>
                <?php endfor; ?>
            </div>
            <a class="week-now" href="<?= e(jeab_bookings_url($chipParams(0))) ?>">Denna vecka</a>
        </div>
    </details>
    <a class="icon-btn week-nav-next" href="<?= e($nextHref) ?>" aria-label="<?= e($nextAria) ?>" data-js="cal-nav">›</a>
    </div>
    <nav class="cal-filter week-view-toggle cal-views" aria-label="Vy">
        <a class="chip<?= $monthView ? ' is-on' : '' ?>" href="<?= e($monthHref) ?>" data-js="cal-nav" data-view="month"<?= $monthView ? ' aria-current="page"' : '' ?>>Månad</a>
        <a class="chip<?= $viewName === 'week' ? ' is-on' : '' ?>" href="<?= e($weekHref) ?>" data-js="cal-nav" data-view="week"<?= $viewName === 'week' ? ' aria-current="page"' : '' ?>>Vecka</a>
        <a class="chip week-today<?= $dayView ? ' is-on' : '' ?>" href="<?= e($todayHref) ?>" data-js="cal-nav" data-view="day"<?= $dayView ? ' aria-current="page"' : '' ?>>Idag</a>
    </nav>
    </div>
    <?= jeab_flash_html($flash ?? null) ?>
</div>

<div class="cal-filter">
    <div class="cal-filter-main cal-filter-people">
        <?= jeab_filter_chips_html(
            static fn(array $params): string => jeab_bookings_url($params),
            $whoFilter,
            $jobFilter,
            $company,
            'staff'
        ) ?>
    </div>
    <div class="cal-filter-products">
        <?= jeab_filter_chips_html(
            static fn(array $params): string => jeab_bookings_url($params),
            $whoFilter,
            $jobFilter,
            $company,
            'job'
        ) ?>
    </div>
</div>

<?php if ($monthView): ?>
<section class="month-cal">
    <div class="mv-head" aria-hidden="true">
        <?php
        $headMon = $monthMondays[0];
        for ($h = 0; $h < 5; $h++):
            $headDay = $headMon->modify('+' . $h . ' days');
            ?>
            <span><?= e(jeab_sv_weekday($headDay, true)) ?></span>
        <?php endfor; ?>
    </div>
    <?php foreach ($monthMondays as $weekMonday): ?>
        <?php
        $weekDays = [];
        for ($d = 0; $d < 5; $d++) {
            $weekDays[] = $weekMonday->modify('+' . $d . ' days');
        }
        $solBars = jeab_month_sol_bars($bookings, $weekMonday);
        ?>
        <article class="mv-week" data-week="<?= e($weekMonday->format('Y-m-d')) ?>">
            <h3 class="mv-week-label"><?= e(jeab_week_row_label($weekMonday)) ?></h3>
            <?php if ($solBars !== []): ?>
                <div class="mv-sols">
                    <?php foreach ($solBars as $bar): ?>
                        <div class="mv-sol-lane">
                            <button class="mv-sol is-sol" type="button" data-js="edit-booking" style="grid-column: <?= (int) $bar['col'] ?> / span <?= (int) $bar['span'] ?>"<?= jeab_booking_data_attrs($bar['row']) ?>>
                                <?= e(jeab_month_sol_label($bar['row'])) ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="mv-days">
                <?php foreach ($weekDays as $day): ?>
                    <?php
                    $outside = (int) $day->format('n') !== (int) $monthDate->format('n')
                        || (int) $day->format('Y') !== (int) $monthDate->format('Y');
                    $next = $day->modify('+1 day');
                    $dayItems = jeab_month_day_items($bookings, $outlookEvents, $day);
                    $dayClass = 'mv-day';
                    if ($outside) {
                        $dayClass .= ' is-out';
                    }
                    if ($day->format('Y-m-d') === $today->format('Y-m-d')) {
                        $dayClass .= ' is-today';
                    }
                    $red = $redDays[$day->format('Y-m-d')] ?? '';
                    if ($red !== '') {
                        $dayClass .= ' is-red';
                    }
                    ?>
                    <div class="<?= e($dayClass) ?>" data-js="month-day"
                            data-day="<?= e($day->format('Y-m-d')) ?>"
                            data-starts="<?= e($day->format('Y-m-d')) ?>T00:00"
                            data-ends="<?= e($next->format('Y-m-d')) ?>T00:00">
                        <span class="mv-date"><?= e($day->format('j')) ?><?php if ($red !== ''): ?> <span class="mv-red"><?= e($red) ?></span><?php endif; ?></span>
                        <?php foreach (jeab_calendar_paint_order($dayItems) as $item): ?>
                            <?= jeab_calendar_wave_month_html($item) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?= jeab_month_legend_html() ?>
</section>
<?php else: ?>
<section class="week-grid<?= $dayView ? ' is-day' : '' ?>">
    <div class="wg-head">
        <span></span>
        <?php foreach ($days as $day): ?>
            <?php $dk = $day->format('Y-m-d'); $red = $redDays[$dk] ?? ''; ?>
            <div class="wg-day<?= $dk === $todayKey ? ' is-today' : '' ?><?= $red !== '' ? ' is-red' : '' ?>"<?= $red !== '' ? ' title="' . e($red) . '"' : '' ?>>
                <?= e(jeab_sv_weekday($day, true)) ?> <?= e($day->format('j')) ?><?php if ($red !== ''): ?> <span class="wg-red"><?= e($red) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="wg-out is-early" data-js="cal-out" data-when="early" hidden>
        <span class="wg-out-label">Före 07</span>
        <?php foreach ($days as $day): ?>
            <div class="wg-out-cell" data-day="<?= e($day->format('Y-m-d')) ?>"></div>
        <?php endforeach; ?>
    </div>
    <div <?= $allDayRowsCount > 1 ? 'class="wg-allday is-stack"' : 'class="wg-allday"' ?> style="--allday-rows: <?= (int) $allDayRowsCount ?>">
        <span class="wg-allday-label">Heldag</span>
        <?php foreach ($days as $i => $day): ?>
            <?php $next = $day->modify('+1 day'); ?>
            <div class="wg-allday-cell<?= $day->format('Y-m-d') === $today->format('Y-m-d') ? ' is-today' : '' ?>" data-js="allday"
                    data-day="<?= e($day->format('Y-m-d')) ?>"
                    data-starts="<?= e($day->format('Y-m-d')) ?>T00:00"
                    data-ends="<?= e($next->format('Y-m-d')) ?>T00:00"
                    style="grid-column: <?= $i + 2 ?>"></div>
        <?php endforeach; ?>
        <?php
        $alldayEntries = [];
        foreach ($allDayRows as $idx => $item) {
            $alldayEntries[] = ['kind' => 'booking', 'key' => 'b' . $idx, 'row' => $item['row'], 'place' => $item['place']];
        }
        foreach ($outlookAllDay as $idx => $item) {
            $alldayEntries[] = ['kind' => 'outlook', 'key' => 'o' . $idx, 'event' => $item['event'], 'place' => $item['place']];
        }
        foreach (jeab_calendar_paint_order($alldayEntries) as $entry) {
            $lane = $allDayLanes[$entry['key']] ?? ['lane' => 0, 'overlap' => false];
            if (($entry['kind'] ?? '') === 'outlook') {
                echo jeab_calendar_wave_outlook_html($entry['event'], $entry['place'], $lane, true);
            } else {
                echo jeab_calendar_wave_booking_html($entry['row'], $entry['place'], $lane, true);
            }
        }
        ?>
    </div>
    <div class="wg-body">
        <div class="wg-hours">
            <?php foreach ($clockSlots as $slot): ?>
                <span class="wg-hour<?= (int) $slot['start']->format('i') !== 0 ? ' is-half' : '' ?>"><?= (int) $slot['start']->format('i') === 0 ? e($slot['start']->format('H')) : '' ?></span>
            <?php endforeach; ?>
        </div>
        <?php foreach ($days as $day): ?>
            <?php $dk = $day->format('Y-m-d'); $red = $redDays[$dk] ?? ''; ?>
            <div class="wg-day-col<?= $dk === $todayKey ? ' is-today' : '' ?><?= $red !== '' ? ' is-red' : '' ?>" data-day="<?= e($dk) ?>" data-label="<?= e(jeab_calendar_sv_date($dk, true) . ($red !== '' ? ' · ' . $red : '') . ($dk === $todayKey ? ' · idag' : '')) ?>">
                <?php foreach (jeab_calendar_slots($day) as $i => $slot): ?>
                    <div class="wg-cell<?= (int) $slot['start']->format('i') !== 0 ? ' is-half' : '' ?>" data-js="slot"
                            data-starts="<?= e($slot['start']->format('Y-m-d\TH:i')) ?>"
                            data-ends="<?= e($slot['end']->format('Y-m-d\TH:i')) ?>"
                            style="grid-row: <?= $i + 1 ?>"></div>
                <?php endforeach; ?>
                <?php foreach (jeab_calendar_paint_order($timedByDay[$day->format('Y-m-d')] ?? []) as $item): ?>
                    <?php $place = $item['place']; $lane = $item['lane']; ?>
                    <?php if (($item['kind'] ?? '') === 'outlook'): ?>
                        <?= jeab_calendar_wave_outlook_html($item['event'], $place, $lane, false) ?>
                    <?php else: ?>
                        <?= jeab_calendar_wave_booking_html($item['row'], $place, $lane, false) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="wg-out is-late" data-js="cal-out" data-when="late" hidden>
        <span class="wg-out-label">Efter 17</span>
        <?php foreach ($days as $day): ?>
            <div class="wg-out-cell" data-day="<?= e($day->format('Y-m-d')) ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="wg-peek" hidden aria-hidden="true"></div>
</section>
<details class="cal-week-log">
<summary>Veckologg</summary>
<div data-cal-log-body></div>
</details>
<?php endif; ?>

<script type="application/json" id="jeab-cal-boot"><?= json_encode([
    'monday' => $monday->format('Y-m-d'),
    'view' => $viewName,
    'day' => $focusDay instanceof DateTimeImmutable ? $focusDay->format('Y-m-d') : '',
    'who' => (string) $whoFilter,
    'job' => (string) $jobFilter,
    'company' => $company,
    'today' => $todayKey,
    'bookings' => jeab_bookings_url(['intent' => 'calendar-bookings']),
    'outlook' => jeab_bookings_url(['intent' => 'calendar-outlook']),
    'log' => jeab_bookings_url(['intent' => 'calendar-log']),
    'cacheKey' => implode('.', [$company, $monday->format('Y-m-d'), $monthView ? 'm' : 'w', (string) $whoFilter, (string) $jobFilter, function_exists('jeab_asset_version_token') ? jeab_asset_version_token(JEAB_ROOT . '/assets/js/app.js') : '0']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</div>
<?php if (is_array($fromReminder)): ?>
<script type="application/json" id="from-reminder-seed"><?= json_encode($fromReminder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endif; ?>

<dialog class="outlook" id="booking-sheet" tabindex="-1">
    <i class="outlook-pointer" id="outlook-pointer" aria-hidden="true"></i>
    <header class="outlook-head" tabindex="0"></header>
    <form method="post" action="<?= e(jeab_bookings_url()) ?>" id="booking-form">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="booking.create" id="booking-action">
        <input type="hidden" name="id" value="" id="booking-id">
        <input type="hidden" name="starts_at" id="booking-starts">
        <input type="hidden" name="ends_at" id="booking-ends">
        <input type="hidden" name="company" id="booking-company" value="<?= e($company) ?>">
        <label class="field field-when">
            <?= jeab_icon('clock') ?>
            <span class="lbl">När</span>
            <span class="when-edit">
                <span class="when-day">
                    <svg class="when-day-ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3.5v3M16 3.5v3M4 9.5h16"/></svg>
                    <span class="when-day-sv" id="booking-start-day-sv" aria-hidden="true"></span>
                    <input type="date" id="booking-start-day" required aria-label="Dag">
                    <svg class="when-day-chevron" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10l5 5 5-5"/></svg>
                </span>
                <span class="when-times" id="booking-when-times">
                    <select id="booking-start-time" class="when-clock" required aria-label="Starttid">
                        <option value=""></option>
                        <?php foreach (jeab_booking_half_hour_labels() as $clock): ?>
                            <option value="<?= e($clock) ?>"><?= e($clock) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="when-dash">–</span>
                    <select id="booking-end-time" class="when-clock" required aria-label="Sluttid">
                        <option value=""></option>
                        <?php foreach (jeab_booking_half_hour_labels() as $clock): ?>
                            <option value="<?= e($clock) ?>"><?= e($clock) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="when-days" id="booking-when-days" hidden>
                    <select id="booking-day-count" aria-label="Antal dagar">
                        <?php for ($n = 1; $n <= 10; $n++): ?>
                            <option value="<?= $n ?>"<?= $n === 5 ? ' selected' : '' ?>><?= $n ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="when-days-lbl">dagar</span>
                </span>
            </span>
            <p class="when-error" id="booking-when-error" hidden></p>
        </label>
        <label class="field field-name">
            <?= jeab_icon('person') ?>
            <span class="lbl">Namn</span>
            <span class="booking-name-wrap">
                <input type="text" name="customer" id="booking-customer" autocomplete="name" autocorrect="off" spellcheck="false" required placeholder="Namn" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="booking-name-suggest">
                <ul class="booking-name-suggest" id="booking-name-suggest" hidden role="listbox" aria-label="Påminnelser"></ul>
            </span>
        </label>
        <label class="field">
            <?= jeab_icon('mail') ?>
            <span class="lbl">E-post</span>
            <input type="email" name="email" id="booking-email" autocomplete="email" inputmode="email" placeholder="E-post">
            <a class="contact-mail sheet-contact-link" id="booking-email-link" data-js="mail" hidden></a>
        </label>
        <label class="field">
            <?= jeab_icon('phone') ?>
            <span class="lbl">Telefon</span>
            <input type="tel" name="phone" id="booking-phone" autocomplete="tel" inputmode="tel" placeholder="Telefon">
            <a class="contact-digits sheet-contact-link" id="booking-phone-link" data-js="tel" hidden></a>
        </label>
        <label class="field">
            <?= jeab_icon('pin') ?>
            <span class="lbl">Adress</span>
            <input type="text" name="address" id="booking-address" autocomplete="street-address" placeholder="Adress">
        </label>
        <label class="field field-notes">
            <?= jeab_icon('note') ?>
            <span class="lbl">Att göra på plats</span>
            <textarea name="notes" id="booking-notes" class="notes" rows="1" data-js="grow-note" placeholder="Att göra på plats"></textarea>
        </label>
        <details class="who-job" data-js="who-job">
            <summary class="who-job-summary">
                <?= jeab_icon('people') ?>
                <span data-js="who-job-label">Vem · Typ</span>
            </summary>
            <div class="who-job-pick">
                <div class="who-job-col">
                    <?= jeab_installer_chips($selectedInstallers, jeab_assignees_join($selectedInstallers)) ?>
                </div>
                <div class="who-job-col">
                    <div class="chips chips-job">
                        <?php foreach (JEAB_JOB_TYPES as $key => $label): ?>
                            <?php $pairWho = jeab_job_person($key, $company); ?>
                            <label class="chip-opt">
                                <input type="checkbox" name="job_types[]" value="<?= e($key) ?>"<?= $pairWho ? ' data-who="' . e($pairWho) . '"' : '' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </details>
        <div class="outlook-foot">
            <details class="sheet-more" id="booking-more" hidden>
                <summary class="sheet-more-btn" aria-label="Fler val">…</summary>
                <div class="sheet-more-menu">
                    <button type="button" data-js="sheet-read">Visa</button>
                    <button class="btn-danger" type="button" id="booking-delete">Avboka</button>
                </div>
            </details>
            <button class="btn-ignore" type="button" data-js="close-sheet">Avbryt</button>
            <button class="btn-save" type="submit" id="booking-save">Spara</button>
        </div>
    </form>
    <div class="sheet-discard" data-js="sheet-discard" hidden>
        <div class="sheet-discard-card" role="alertdialog" aria-labelledby="sheet-discard-title" aria-describedby="sheet-discard-text">
            <p class="sheet-discard-title" id="sheet-discard-title">Kasta ändringarna?</p>
            <p class="sheet-discard-text" id="sheet-discard-text">Bokningen är inte sparad.</p>
            <div class="sheet-discard-actions">
                <button type="submit" class="btn-save" form="booking-form" data-js="sheet-discard-save">Spara</button>
                <button type="button" class="btn-save" data-js="sheet-discard-keep">Fortsätt redigera</button>
                <button type="button" class="btn-ignore" data-js="sheet-discard-drop">Kasta</button>
            </div>
        </div>
    </div>
</dialog>

<?php if (is_array($openBooking ?? null) && (int) ($openBooking['id'] ?? 0) > 0): ?>
<button type="button" hidden data-js="edit-booking" data-open-seed="1"<?= jeab_booking_data_attrs($openBooking) ?>></button>
<?php endif; ?>
<dialog class="cal-read" id="booking-read" aria-labelledby="booking-read-title">
    <div class="cal-read-card">
        <div class="cal-read-top">
            <p class="cal-read-when" data-js="read-when"></p>
            <details class="sheet-more cal-read-more">
                <summary class="sheet-more-btn" aria-label="Fler val">…</summary>
                <div class="sheet-more-menu">
                    <button class="btn-danger" type="button" data-js="read-cancel">Avboka</button>
                </div>
            </details>
            <button type="button" class="cal-read-close" data-js="read-close" aria-label="Stäng">×</button>
        </div>
        <h2 class="cal-read-title" id="booking-read-title" data-js="read-address"></h2>
        <p class="cal-read-customer" data-js="read-customer"></p>
        <p class="cal-read-line" data-js="read-phone-row"><?= jeab_icon('phone') ?><a data-js="read-phone"></a></p>
        <p class="cal-read-line" data-js="read-email-row"><?= jeab_icon('mail') ?><a data-js="read-email"></a></p>
        <p class="cal-read-line" data-js="read-who-row"><?= jeab_icon('people') ?><span data-js="read-who"></span></p>
        <p class="cal-read-notes" data-js="read-notes"></p>
        <div class="cal-read-big">
            <a class="cal-read-btn" data-js="read-call">Ring</a>
            <a class="cal-read-btn" data-js="read-nav" target="_blank" rel="noopener">Navigera</a>
        </div>
        <div class="cal-read-move" data-js="read-move">
            <button type="button" data-js="read-prev-day">Dagen före</button>
            <button type="button" data-js="read-today">Idag</button>
            <button type="button" data-js="read-next-day">Dagen efter</button>
        </div>
        <div class="cal-read-foot">
            <a class="cal-read-link" data-js="read-open"></a>
            <button type="button" class="btn-save" data-js="read-edit">Ändra</button>
        </div>
    </div>
</dialog>
<div class="cal-toast" data-js="cal-toast" hidden>
    <span data-js="cal-toast-text"></span>
    <button type="button" data-js="cal-toast-undo" hidden>Ångra</button>
</div>

<noscript>
    <form class="detail" method="post" action="<?= e(jeab_url('index.php?p=bookings&w=' . $weekOffset)) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="booking.create">
        <h2>Ny bokning</h2>
        <label class="field"><span>När</span><input type="datetime-local" name="starts_at" required></label>
        <label class="field"><span>Slut</span><input type="datetime-local" name="ends_at"></label>
        <label class="field"><span>Namn</span><input type="text" name="customer" required></label>
        <label class="field"><span>E-post</span><input type="email" name="email"></label>
        <label class="field"><span>Telefon</span><input type="tel" name="phone"></label>
        <label class="field"><span>Adress</span><input type="text" name="address"></label>
        <label class="field"><span>Att göra på plats</span><textarea name="notes" class="notes" rows="2"></textarea></label>
        <div class="field"><span>Vem åker</span><?= jeab_installer_chips($selectedInstallers) ?></div>
        <button class="btn" type="submit">Boka</button>
    </form>
</noscript>
<?php
return ob_get_clean();

function jeab_icon(string $name): string
{
    $paths = [
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
        'person' => '<circle cx="12" cy="8" r="3.2"/><path d="M5.5 19c.8-3.4 3.3-5 6.5-5s5.7 1.6 6.5 5"/>',
        'mail' => '<rect x="4" y="6.5" width="16" height="11" rx="1.5"/><path d="M5 8l7 5 7-5"/>',
        'phone' => '<path d="M8 4.5h2.2l1 3.2-1.6 1a11 11 0 005.7 5.7l1-1.6 3.2 1V18a1.4 1.4 0 01-1.5 1.5C9.6 19.2 4.8 14.4 4.5 6A1.4 1.4 0 016 4.5z"/>',
        'pin' => '<path d="M12 21s6-5.2 6-10a6 6 0 10-12 0c0 4.8 6 10 6 10z"/><circle cx="12" cy="11" r="2"/>',
        'note' => '<path d="M7 6h10M7 11h10M7 16h6"/>',
        'people' => '<circle cx="9" cy="8" r="2.4"/><circle cx="16" cy="8.5" r="2"/><path d="M4.5 18c.6-2.6 2.4-4 4.5-4s3.9 1.4 4.5 4M14 14.2c1.8 0 3.3 1 3.9 3.3"/>',
        'tag' => '<path d="M12 4.5h6.5V11L12.2 17.3a2 2 0 01-2.8 0l-2.7-2.7a2 2 0 010-2.8z"/><circle cx="16" cy="8.2" r="1"/>',
    ];
    $d = $paths[$name] ?? $paths['note'];
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}
