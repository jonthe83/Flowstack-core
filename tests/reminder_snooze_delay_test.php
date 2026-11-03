<?php

declare(strict_types=1);

/**
 * Knuffen sparas direkt (ingen väntan före POST) och kortet lämnar listan, i alla vyer.
 * Ångra är en återställning efteråt: den syns 6 s och skriver tillbaka tiden.
 * 2d visar 25/10 direkt, inte «idag»/«imorgon».
 */

function sd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$fnAt = strpos($js, 'function bindSnoozeForm(');
$fnEnd = strpos($js, "\n  function ", $fnAt === false ? 0 : $fnAt + 10);
$fn = ($fnAt === false || $fnEnd === false) ? '' : substr($js, $fnAt, $fnEnd - $fnAt);
sd($fn !== '', 'hittade knuff-hanteraren');
sd(str_contains($fn, 'scheduleSnoozeLeave(row)') && str_contains($fn, 'showSnoozeStatus('), 'alla knuffar visar status och väntar');
sd(!str_contains($fn, 'collapseTodayRow(row)'), 'ingen knuff gömmer kortet i samma veva');
sd(!str_contains($fn, 'closeOpenReminder'), 'knuffen minimerar inte kortet');
sd(!str_contains($fn, 'is-snooze-leave') && !str_contains($fn, 'classList.add'), 'knuffen lägger ingen göm- eller fäll-klass');
sd(str_contains($fn, '_jeabSnoozeSteps.push') && str_contains($fn, 'jeabSnoozeAdvance'), 'varje tryck lägger på tiden och ritar om');
sd(str_contains($fn, 'jeabSnoozeTapCounts') && str_contains($fn, "acceptSnoozeGesture('pointerup'") && str_contains($fn, "acceptSnoozeGesture('submit'"), 'pekare och klick räknas en gång');
sd(!preg_match('/_jeabSnoozeHold\s*\|\|/', $fn), 'nästa tryck blockeras inte av det första');
sd(str_contains($js, 'function scheduleSnoozeLeave(') && str_contains($js, 'jeabSnoozeHides'), 'väntan går via scheduleSnoozeLeave');
sd(str_contains($js, "undo.textContent = 'Ångra'") && str_contains($js, 'function undoSnooze('), 'Ångra finns');
sd(str_contains($js, 'document.body || document.documentElement).appendChild(box)') && str_contains($js, 'row._jeabSnoozeStatus = box'), 'Ångra sitter utanför kortet');
sd(str_contains($js, 'SNOOZE_LEAVE_MS'), 'gömningen använder väntetiden');
sd(!str_contains($fn, 'queueSnoozeSave('), 'knuffen skickas inte i samma veva som klicket');
$leaveAt = strpos($js, 'function scheduleSnoozeLeave(');
$leaveEnd = strpos($js, "\n  function ", $leaveAt === false ? 0 : $leaveAt + 10);
$leave = ($leaveAt === false || $leaveEnd === false) ? '' : substr($js, $leaveAt, $leaveEnd - $leaveAt);
sd(str_contains($leave, 'if (row._jeabSnoozeUndo)') && str_contains($leave, 'queueSnoozeSave('), 'POST väntar ut lämna-tiden och hoppas över vid Ångra');
sd(str_contains($leave, '_jeabSnoozeSteps') && str_contains($leave, '_jeabSnoozeBatch') && substr_count($leave, 'queueSnoozeSave(') === 1, 'hela kön sparas en gång när tiden gått');
sd(str_contains($leave, 'setTimeout(tick, wait)') && str_contains($leave, 'dropSnoozeRow(') && !str_contains($leave, 'isTodayView()'), 'kortet lämnar listan i alla vyer när väntan gått');
sd(str_contains($leave, 'SNOOZE_UNDO_MS') && str_contains($leave, 'started + toastMs') && !str_contains($leave, 'hideSnoozeStatus(row);'), 'Ångra lever kvar när kortet lämnar listan');
sd(str_contains($js, 'function dropSnoozeRow(') && str_contains($js, 'snoozeHidden') && str_contains($js, 'jeabSnoozeSyncKeeps'), 'omritning tar inte tillbaka ett kort som lämnat listan');
sd(str_contains($js, "undo.addEventListener('pointerup'") && str_contains($js, '.item-snooze-status') && str_contains($js, 'const SNOOZE_UNDO_MS'), 'Ångra tar emot pekare och stannar 6 s');
$undoAt = strpos($js, 'function undoSnooze(');
$undoEnd = strpos($js, "\n  function ", $undoAt === false ? 0 : $undoAt + 10);
$undo = ($undoAt === false || $undoEnd === false) ? '' : substr($js, $undoAt, $undoEnd - $undoAt);
$undoIdAt = strpos($js, 'function undoSnoozeId(');
$undoIdEnd = strpos($js, "\n  function ", $undoIdAt === false ? 0 : $undoIdAt + 10);
$undoId = ($undoIdAt === false || $undoIdEnd === false) ? '' : substr($js, $undoIdAt, $undoIdEnd - $undoIdAt);
sd(str_contains($js, 'const snoozeUndoStore = new Map()') && str_contains($undoId, 'reinsertSnoozeCard(entry)') && str_contains($undoId, 'postUndoEntry(entry)'), 'Ångra läser id och ursprunglig tid utanför kortet');
sd(str_contains($fn, 'rememberSnoozeUndo(row, true)') && strpos($fn, 'rememberSnoozeUndo(row, true)') < strpos($fn, 'paintSnoozeDue('), 'ursprunglig tid sparas före knuffen');
$undoPostAt = strpos($js, 'function postUndoEntry(');
$undoPostEnd = strpos($js, "\n  function ", $undoPostAt === false ? 0 : $undoPostAt + 10);
$undoPost = ($undoPostAt === false || $undoPostEnd === false) ? '' : substr($js, $undoPostAt, $undoPostEnd - $undoPostAt);
sd(str_contains($undoPost, "pair[0] === 'due_at'") && str_contains($undoPost, 'jeabSnoozeDueStamp') && str_contains($undoPost, '.json()'), 'Ångra postar serverns due_at och läser svaret');
sd(str_contains($undoPost, 'paintUndoRow(entry, data)') && str_contains($undoPost, 'savedStamp !== dueAt'), 'Ångra ritar om kortet bara när tiden stämmer');
sd(str_contains($undoId, 'snoozeLive.get(key)'), 'Ångra stoppar timern även när kortet lämnat listan');
$syncAt = strpos($js, 'function syncShutPreview(');
$syncEnd = strpos($js, "\n  function ", $syncAt === false ? 0 : $syncAt + 10);
$syncFn = ($syncAt === false || $syncEnd === false) ? '' : substr($js, $syncAt, $syncEnd - $syncAt);
sd(str_contains($syncFn, 'jeabShutNotePlan'), 'fällning lägger inte till anteckningen igen');
sd(str_contains($js, 'jeabCalTodayPlan') && str_contains($js, 'applyCalToday(plan, card)'), 'Idag i kalendern ger svar när dagen redan är vald');
sd(str_contains($js, 'jeabSnoozeDueLabel') && str_contains($js, 'paintSnoozeToastFromRow(row, data)') && !str_contains($fn, "showSnoozeStatus(row, label ?"), 'toasten tar klockan från serverns due_at');
$saveAt = strpos($js, 'function queueSnoozeSave(');
$saveEnd = strpos($js, "\n  function ", $saveAt === false ? 0 : $saveAt + 10);
$save = ($saveAt === false || $saveEnd === false) ? '' : substr($js, $saveAt, $saveEnd - $saveAt);
sd(str_contains($save, 'jeabSnoozeStack') && str_contains($save, 'postSnoozeTarget') && str_contains($js, 'body.delete(\'due_at\')'), 'tre tryck sparas som en sammanlagd tid');
sd(str_contains($undoId, 'jeabSnoozeUndoPlan') && str_contains($undoId, 'reinsertSnoozeCard(entry)') && str_contains($undoId, 'entry.dueAt = plan.dueAt'), 'Ångra lägger tillbaka ursprungstiden även när kortet lämnat listan');
$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
sd(str_contains($css, 'width: min(360px, calc(100vw - 32px))') && str_contains($css, '.item-snooze-status.is-floating .item-snooze-undo'), 'Ångra står still när klocktexten kommer');
sd(str_contains($leave, 'leaveAt') && str_contains($leave, 'jeabSnoozeLeaveDeadline') && str_contains($leave, 'session.leaveTimer'), 'varje tryck flyttar lämna-tiden');
sd(str_contains($js, 'function releaseReadToday(') && str_contains($js, 'jeabCalPopupToday'), 'popuppens Idag stänger och visar kortet');
$bookings = (string) file_get_contents($root . '/src/pages/bookings.php');
sd(str_contains($bookings, 'data-js="read-today"'), 'popuppen har en Idag-knapp');
$index = (string) file_get_contents($root . '/index.php');
sd(str_contains($index, "\$payload['today']"), 'svaret har dagens datum');
sd(str_contains($js, 'is-snooze-shift') && str_contains($js, 'function armSnoozeShift('), 'listan tar inte emot tryck medan kortet lämnar');
$showAt = strpos($js, 'function showSnoozeStatus(');
$showEnd = strpos($js, "\n  function ", $showAt === false ? 0 : $showAt + 10);
$showFn = ($showAt === false || $showEnd === false) ? '' : substr($js, $showAt, $showEnd - $showAt);
sd($showFn !== '' && !str_contains($showFn, 'armSnoozeShift('), 'första trycket stänger inte av 2h');
sd(str_contains($fn, 'jeabSnoozeStack') && str_contains($fn, 'ensureSnoozeHoldRow(row)'), 'klockan staplas från tiden före första trycket och kortet hålls kvar');
sd(str_contains($js, 'function snoozeHoldActive(') && str_contains($js, 'jeabSnoozeHoldSurvives'), 'omritning och förfaller-nu får inte ta kortet under väntan');
$closeAt = strpos($js, 'function closeOpenReminder(');
$closeEnd = strpos($js, "\n  function ", $closeAt === false ? 0 : $closeAt + 10);
$closeFn = ($closeAt === false || $closeEnd === false) ? '' : substr($js, $closeAt, $closeEnd - $closeAt);
sd(str_contains($closeFn, 'snoozeHoldActive(row)'), 'kortet fälls inte ihop under väntan');
sd(str_contains($css, 'item.is-snooze-hold') && str_contains($css, 'pointer-events: auto !important'), '2h går att trycka medan kortet ligger kvar');
$optJs = (string) file_get_contents($root . '/assets/js/optimistic.js');
sd(str_contains($optJs, 'function jeabSnoozeGuard(') && str_contains($optJs, 'function jeabSnoozeEpochAllows('), 'epok avgör om ett svar får skriva över Ångra');
sd(str_contains($undoId, 'jeabSnoozeGuard') && str_contains($undoId, 'leaveTimer') && str_contains($undoId, '.abort()') && str_contains($undoId, 'decision.post !== \'undo\''), 'Ångra inom 2 s avbryter timern och skickar ingen ny tid');
sd(str_contains($save, 'snooze-send') && str_contains($save, 'AbortController') && str_contains($js, 'snooze-response') && str_contains($js, 'jeabSnoozeEpochAllows'), 'en sen knuff ignoreras när Ångra hunnit före');
sd(str_contains($undoId, '_jeabSnoozeRestore') && str_contains($js, 'function snoozeRowPinned('), 'kortet ligger kvar tills ursprungstiden är sparad');
sd(str_contains($js, 'function pushReminderOpen(') && str_contains($js, 'history.pushState({ jeabReminders: true, jeabOpen:') && str_contains($js, 'location.href = next'), 'kortet öppnas på plats, annars laddas sidan om');
sd(str_contains($js, 'window.jeabReminderOpenPop') && str_contains($js, "openReminderRow(row, { href:"), 'öppning och bakåt går utan omladdning');
$shell = (string) file_get_contents($root . '/assets/js/shell.js');
sd(str_contains($shell, 'jeabReminderOpenPop'), 'skalet laddar inte om när bara open ändras');

$node = trim((string) shell_exec('command -v node'));
sd($node !== '', 'node finns');
$script = <<<'JS'
const m = require(process.argv[2]);
const wait = m.jeabSnoozeLeaveMs();
if (wait !== 0) {
  console.error('wait ' + wait);
  process.exit(1);
}
const cases = [
  ['2h', 0, false, true],
  ['2h', 8000, false, true],
  ['2h', 0, true, false],
  ['2h', 2000, true, false],
  ['2d', 0, false, true],
  ['2d', 2000, false, true],
  ['2d', 2000, true, false],
  ['1w', 2000, false, true],
  ['1w', 2000, true, false]
];
for (const row of cases) {
  const got = m.jeabSnoozeHides(row[0], row[1], row[2]);
  if (got !== row[3]) {
    console.error(JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
const now = new Date(2026, 9, 23, 15, 0, 0);
function stamp(by, raw) {
  const got = m.jeabSnoozeAdvance(by, now, raw);
  if (!got || !got.stamp) {
    console.error('advance tom ' + by + ' ' + raw);
    process.exit(1);
  }
  return got;
}
const h1 = stamp('2h', '2026-10-23 18:30:00');
if (h1.stamp !== '2026-10-23 20:30:00' || !h1.label.endsWith('20:30')) {
  console.error('2h ' + JSON.stringify(h1));
  process.exit(1);
}
const h2 = stamp('2h', h1.stamp);
if (h2.stamp !== '2026-10-23 22:30:00') {
  console.error('2h+2h ' + JSON.stringify(h2));
  process.exit(1);
}
const d1 = stamp('2d', h2.stamp);
if (d1.stamp !== '2026-10-25 00:00:00' || d1.label !== '25/10') {
  console.error('2d ' + JSON.stringify(d1));
  process.exit(1);
}
const d2 = stamp('2d', d1.stamp);
if (d2.stamp !== '2026-10-27 00:00:00' || d2.label !== '27/10') {
  console.error('2d+2d ' + JSON.stringify(d2));
  process.exit(1);
}
const fromToday = stamp('2d', '2026-10-23 15:00:00');
if (fromToday.stamp !== '2026-10-25 00:00:00' || fromToday.label !== '25/10' || fromToday.label === 'idag' || fromToday.label === 'imorgon') {
  console.error('2d idag ' + JSON.stringify(fromToday));
  process.exit(1);
}
if (m.jeabSnoozeUndoMs() < 6000) {
  console.error('undo ms ' + m.jeabSnoozeUndoMs());
  process.exit(1);
}
const once = m.jeabSnoozeAfterTaps(now, '2026-10-23 15:00:00', [{ t: 0, by: '2d' }]);
if (once.removeAt !== 0 || once.undoUntil !== 6000 || once.label !== '25/10') {
  console.error('once ' + JSON.stringify(once));
  process.exit(1);
}
const again = m.jeabSnoozeAfterTaps(now, '2026-10-23 15:00:00', [{ t: 0, by: '2h' }, { t: 1500, by: '2d' }]);
if (again.removeAt !== 1500 || again.undoUntil !== 7500 || again.stamp !== '2026-10-25 00:00:00' || again.label !== '25/10') {
  console.error('again ' + JSON.stringify(again));
  process.exit(1);
}
if (m.jeabSnoozeSyncKeeps('9', { '9': true }, { '9': true }) !== 'drop') {
  console.error('sync drop');
  process.exit(1);
}
if (m.jeabSnoozeSyncKeeps('9', {}, { '9': true }) !== 'keep') {
  console.error('sync keep');
  process.exit(1);
}
if (m.jeabSnoozeSyncKeeps('9', {}, {}) !== 'replace') {
  console.error('sync replace');
  process.exit(1);
}
if (m.jeabSnoozeShiftMs() !== 400) {
  console.error('shift ' + m.jeabSnoozeShiftMs());
  process.exit(1);
}
const rec = m.jeabSnoozeUndoRecord('12', '2026-10-23 15:00:00', '', 1000);
if (rec.id !== '12' || rec.dueAt !== '2026-10-23 15:00:00' || rec.until !== 7000) {
  console.error('record ' + JSON.stringify(rec));
  process.exit(1);
}
if (!m.jeabSnoozeUndoLive(rec, 2000) || !m.jeabSnoozeUndoLive(rec, 7000) || m.jeabSnoozeUndoLive(rec, 7001)) {
  console.error('live window');
  process.exit(1);
}
if (m.jeabSnoozeUndoLive(Object.assign({}, rec, { undone: true }), 2000)) {
  console.error('undone still live');
  process.exit(1);
}
if (m.jeabReminderOpenPlan(true, true) !== 'push' || m.jeabReminderOpenPlan(false, true) !== 'reload' || m.jeabReminderOpenPlan(true, false) !== 'reload') {
  console.error('open plan');
  process.exit(1);
}
const late = stamp('2h', '2026-10-20 09:00:00');
if (late.stamp !== '2026-10-23 17:00:00') {
  console.error('past ' + JSON.stringify(late));
  process.exit(1);
}
const cross = stamp('2h', '2026-10-23 23:00:00');
if (cross.stamp !== '2026-10-24 01:00:00' || cross.label.indexOf('01:00') === -1) {
  console.error('cross ' + JSON.stringify(cross));
  process.exit(1);
}
let tapState = null;
function tap(name, at, expect) {
  const verdict = m.jeabSnoozeTapCounts(tapState, name, at);
  tapState = verdict.state;
  if (verdict.count !== expect) {
    console.error(name + '@' + at + ' -> ' + verdict.count);
    process.exit(1);
  }
}
tap('pointerup', 0, true);
tap('pointerup', 20, false);
tap('submit', 40, false);
tap('click', 60, false);
tap('pointerup', 180, true);
tap('submit', 200, false);
tap('submit', 800, false);
tap('submit', 2000, true);
tap('submit', 2010, false);
tap('submit', 2200, true);
if (m.jeabSnoozeDueStamp('2026-10-23 12:54:00') !== '2026-10-23 12:54:00') {
  console.error('stamp plain');
  process.exit(1);
}
if (m.jeabSnoozeDueStamp('2026-10-23T12:54:00.000Z') !== '2026-10-23 12:54:00') {
  console.error('stamp Z');
  process.exit(1);
}
if (m.jeabSnoozeDueStamp('2026-10-23T12:54:00+00:00') !== '2026-10-23 12:54:00') {
  console.error('stamp offset');
  process.exit(1);
}
const recorded = m.jeabSnoozeUndoRecord('9', '2026-10-23T12:54:00.000Z', '', 0);
if (recorded.dueAt !== '2026-10-23 12:54:00') {
  console.error('record ' + recorded.dueAt);
  process.exit(1);
}
if (m.jeabShutNotePlan(1, 'Hej') !== 'keep' || m.jeabShutNotePlan(2, 'Hej') !== 'keep' || m.jeabShutNotePlan(0, 'Hej') !== 'append' || m.jeabShutNotePlan(1, '') !== 'clear') {
  console.error('note plan');
  process.exit(1);
}
const sameDay = m.jeabCalTodayPlan('day', '2026-10-23', '2026-10-23', '2026-10-23', '12:54', 'read');
if (sameDay.action !== 'feedback' || sameDay.time !== '12:54') {
  console.error('today feedback ' + JSON.stringify(sameDay));
  process.exit(1);
}
const moveDay = m.jeabCalTodayPlan('day', '2026-10-23', '2026-10-23', '2026-10-22', '12:54', 'sheet');
if (moveDay.action !== 'set-today' || moveDay.time !== '12:54' || moveDay.day !== '2026-10-23') {
  console.error('today set ' + JSON.stringify(moveDay));
  process.exit(1);
}
const otherView = m.jeabCalTodayPlan('week', '2026-10-19', '2026-10-23', '2026-10-23', '12:54', 'read');
if (otherView.action !== 'feedback' || otherView.time !== '12:54') {
  console.error('today card ' + JSON.stringify(otherView));
  process.exit(1);
}
const weekNav = m.jeabCalTodayPlan('week', '2026-10-19', '2026-10-23', '', '', '');
if (weekNav.action !== 'navigate') {
  console.error('today nav ' + JSON.stringify(weekNav));
  process.exit(1);
}
const toast = m.jeabSnoozeDueLabel('2026-10-23 19:30:00', '2026-10-23');
if (toast !== 'idag 19:30') {
  console.error('toast label ' + toast);
  process.exit(1);
}
const toastZ = m.jeabSnoozeDueLabel('2026-10-23T19:30:00.000Z', '2026-10-23');
if (toastZ !== 'idag 19:30') {
  console.error('toast Z ' + toastZ);
  process.exit(1);
}
const tomorrow = m.jeabSnoozeDueLabel('2026-10-24 19:30:00', '2026-10-23');
if (tomorrow !== 'imorgon 19:30') {
  console.error('toast tomorrow ' + tomorrow);
  process.exit(1);
}
if (m.jeabSnoozeDueLabel('2026-10-23 19:30:00', '2026-10-23').indexOf('13:30') !== -1) {
  console.error('toast shifted');
  process.exit(1);
}
if (m.jeabSnoozeLeaveDeadline(0) !== 0 || m.jeabSnoozeLeaveDeadline(1500) !== 1500) {
  console.error('leave deadline ' + m.jeabSnoozeLeaveDeadline(0) + ' ' + m.jeabSnoozeLeaveDeadline(1500));
  process.exit(1);
}
const popup = m.jeabCalPopupToday('2026-10-23', '13:30', '2026-10-23');
if (popup.action !== 'feedback' || popup.time !== '13:30') {
  console.error('popup today ' + JSON.stringify(popup));
  process.exit(1);
}
const popupMove = m.jeabCalPopupToday('2026-10-22', '13:30', '2026-10-23');
if (popupMove.action !== 'set-today' || popupMove.time !== '13:30' || popupMove.day !== '2026-10-23') {
  console.error('popup move ' + JSON.stringify(popupMove));
  process.exit(1);
}
const baseDue = '2026-10-23 19:30:00';
const stacked = m.jeabSnoozeStack(baseDue, ['2h', '2h', '2h']);
if (stacked !== '2026-10-24 01:30:00') {
  console.error('stack 3x2h ' + stacked);
  process.exit(1);
}
if (m.jeabSnoozeStack(baseDue, ['2h']) === stacked) {
  console.error('one tap matched three');
  process.exit(1);
}
const mixed = m.jeabSnoozeStack(baseDue, ['2h', '2d']);
if (mixed !== '2026-10-25 00:00:00') {
  console.error('stack 2h+2d ' + mixed);
  process.exit(1);
}
const week = m.jeabSnoozeStack('2026-10-31 23:00:00', ['1w', '2h']);
if (week !== '2026-11-07 02:00:00') {
  console.error('stack 1w+2h ' + week);
  process.exit(1);
}
const streak = m.jeabSnoozeUndoRecord('7', baseDue, '', 1000);
const left = m.jeabSnoozeUndoPlan(streak, 4000, false);
if (left.act !== 'restore' || left.dueAt !== baseDue || left.dueAt === stacked || left.reinsert !== true) {
  console.error('undo after leave ' + JSON.stringify(left));
  process.exit(1);
}
const staying = m.jeabSnoozeUndoPlan(streak, 1500, true);
if (staying.act !== 'restore' || staying.dueAt !== baseDue || staying.reinsert !== false) {
  console.error('undo while listed ' + JSON.stringify(staying));
  process.exit(1);
}
const expired = m.jeabSnoozeUndoPlan(streak, 7001, false);
if (expired.act !== 'ignore' || expired.dueAt !== '' || expired.reinsert !== false) {
  console.error('undo expired ' + JSON.stringify(expired));
  process.exit(1);
}
const undone = m.jeabSnoozeUndoPlan(Object.assign({}, streak, { undone: true }), 4000, false);
if (undone.act !== 'ignore') {
  console.error('undo twice');
  process.exit(1);
}
if (m.jeabSnoozeHoldSurvives(false, true, false) !== true) {
  console.error('hold survives due-now');
  process.exit(1);
}
if (m.jeabSnoozeHoldSurvives(false, false, false) !== false) {
  console.error('idle row follows filter');
  process.exit(1);
}
if (m.jeabSnoozeHoldSurvives(true, false, false) !== true) {
  console.error('listed row stays');
  process.exit(1);
}
if (m.jeabSnoozeHoldSurvives(false, true, true) !== false) {
  console.error('left row comes back');
  process.exit(1);
}
if (m.jeabSnoozePointerBlock('hold') !== false || m.jeabSnoozePointerBlock('leave') !== true || m.jeabSnoozePointerBlock('') !== false) {
  console.error('pointer block');
  process.exit(1);
}
const base2130 = '2026-10-23 21:30:00';
if (m.jeabSnoozeStack(base2130, ['2h']) !== '2026-10-23 23:30:00') {
  console.error('one 2h from 21:30');
  process.exit(1);
}
const six = m.jeabSnoozeStack(base2130, ['2h', '2h', '2h']);
if (six !== '2026-10-24 03:30:00') {
  console.error('three 2h from 21:30 ' + six);
  process.exit(1);
}
const overdueNow = new Date('2026-10-26T12:00:00Z');
const overdueDue = '2026-10-24 02:00:00';
if (m.jeabStockholmStamp(overdueNow) !== '2026-10-26 13:00:00') {
  console.error('stockholm now ' + m.jeabStockholmStamp(overdueNow));
  process.exit(1);
}
const overdueHour = m.jeabSnoozeStack(overdueDue, ['2h'], overdueNow);
if (overdueHour !== '2026-10-26 15:00:00') {
  console.error('overdue 2h ' + overdueHour);
  process.exit(1);
}
const overdueHours = m.jeabSnoozeStack(overdueDue, ['2h', '2h'], overdueNow);
if (overdueHours !== '2026-10-26 17:00:00') {
  console.error('overdue 2h+2h ' + overdueHours);
  process.exit(1);
}
const overdueDay = m.jeabSnoozeStack(overdueDue, ['2d'], overdueNow);
if (overdueDay !== '2026-10-28 00:00:00') {
  console.error('overdue 2d ' + overdueDay);
  process.exit(1);
}
const overdueWeek = m.jeabSnoozeStack(overdueDue, ['1w'], overdueNow);
if (overdueWeek !== '2026-11-02 00:00:00') {
  console.error('overdue 1w ' + overdueWeek);
  process.exit(1);
}
if (m.jeabSnoozeStack('2026-10-27 18:00:00', ['2h'], overdueNow) !== '2026-10-27 20:00:00') {
  console.error('future 2h still stacks from due');
  process.exit(1);
}
if (overdueHour.indexOf('02:00') !== -1 || overdueDay.slice(0, 10) === '2026-10-24') {
  console.error('overdue kept the old clock');
  process.exit(1);
}
const quick = [{ t: 0 }, { t: 120 }, { t: 240 }];
function stillListed(at) {
  var last = quick[quick.length - 1].t;
  for (var i = quick.length - 1; i >= 0; i--) {
    if (quick[i].t <= at) {
      last = quick[i].t;
      break;
    }
  }
  var holding = at - last < m.jeabSnoozeLeaveMs();
  return m.jeabSnoozeHoldSurvives(false, holding, false);
}
if (stillListed(50) || stillListed(300) || stillListed(2240)) {
  console.error('card leaves directly, no 2 s hold');
  process.exit(1);
}
const originalDue = '2026-10-24 01:32:00';
const stackedDue = '2026-10-24 03:32:00';
if (!m.jeabSnoozeEpochAllows(0, 0) || !m.jeabSnoozeEpochAllows(1, 1) || m.jeabSnoozeEpochAllows(0, 1)) {
  console.error('epoch allows');
  process.exit(1);
}
let during = m.jeabSnoozeGuard({ epoch: 0, phase: 'idle', original: '', due: '' }, { type: 'hold', due: originalDue });
during = m.jeabSnoozeGuard(during, { type: 'undo' });
if (during.post !== '' || during.phase !== 'cancelled' || during.due !== originalDue || during.epoch !== 1) {
  console.error('undo during hold ' + JSON.stringify(during));
  process.exit(1);
}
const lateSend = m.jeabSnoozeGuard(during, { type: 'snooze-send', due: stackedDue });
if (!lateSend.ignore || lateSend.post !== '' || lateSend.due !== originalDue) {
  console.error('save after undo during hold ' + JSON.stringify(lateSend));
  process.exit(1);
}
const lateReply = m.jeabSnoozeGuard(during, { type: 'snooze-response', epoch: 0, due: stackedDue });
if (!lateReply.ignore || lateReply.due !== originalDue) {
  console.error('stale reply during hold ' + JSON.stringify(lateReply));
  process.exit(1);
}
let after = m.jeabSnoozeGuard({ epoch: 0, phase: 'holding', original: originalDue, due: originalDue }, { type: 'snooze-send', due: stackedDue });
if (after.post !== 'snooze' || after.phase !== 'inflight' || after.epoch !== 0) {
  console.error('send after leave ' + JSON.stringify(after));
  process.exit(1);
}
after = m.jeabSnoozeGuard(after, { type: 'snooze-response', epoch: 0, due: stackedDue });
if (after.ignore || after.phase !== 'saved' || after.due !== stackedDue) {
  console.error('saved ' + JSON.stringify(after));
  process.exit(1);
}
after = m.jeabSnoozeGuard(after, { type: 'undo' });
if (after.post !== 'undo' || after.due !== originalDue || after.epoch !== 1 || after.phase !== 'restoring') {
  console.error('undo after save ' + JSON.stringify(after));
  process.exit(1);
}
const overwrite = m.jeabSnoozeGuard(after, { type: 'snooze-response', epoch: 0, due: stackedDue });
if (!overwrite.ignore || overwrite.due !== originalDue || overwrite.phase !== 'restoring') {
  console.error('stale save after undo ' + JSON.stringify(overwrite));
  process.exit(1);
}
after = m.jeabSnoozeGuard(after, { type: 'undo-response', epoch: 1, due: originalDue });
if (after.ignore || after.phase !== 'restored' || after.due !== originalDue) {
  console.error('undo stored ' + JSON.stringify(after));
  process.exit(1);
}
const afterStale = m.jeabSnoozeGuard(after, { type: 'snooze-response', epoch: 0, due: stackedDue });
if (!afterStale.ignore || afterStale.due !== originalDue) {
  console.error('stale after restore ' + JSON.stringify(afterStale));
  process.exit(1);
}
let inflight = m.jeabSnoozeGuard({ epoch: 0, phase: 'holding', original: originalDue, due: originalDue }, { type: 'snooze-send', due: stackedDue });
inflight = m.jeabSnoozeGuard(inflight, { type: 'undo' });
if (inflight.post !== 'undo' || inflight.due !== originalDue || inflight.epoch !== 1) {
  console.error('undo while saving ' + JSON.stringify(inflight));
  process.exit(1);
}
const inflightReply = m.jeabSnoozeGuard(inflight, { type: 'snooze-response', epoch: 0, due: stackedDue });
if (!inflightReply.ignore || inflightReply.due !== originalDue) {
  console.error('inflight reply ' + JSON.stringify(inflightReply));
  process.exit(1);
}
console.log('delay ok');
JS;
$tmp = tempnam(sys_get_temp_dir(), 'jeab-snooze-');
if ($tmp === false) {
    fwrite(STDERR, "FAIL tempfil\n");
    exit(1);
}
file_put_contents($tmp, $script);
$cmd = escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js');
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
@unlink($tmp);
sd($code === 0, '2h, 2d och 1v sparas direkt utan väntan, Ångra återställer efteråt (' . trim(implode("\n", $out)) . ')');

$acc = sys_get_temp_dir() . '/jeab-snooze-acc-' . bin2hex(random_bytes(3));
if (!is_dir($acc) && !mkdir($acc, 0775, true) && !is_dir($acc)) {
    fwrite(STDERR, "FAIL tempkatalog för knuff\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $acc;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($acc . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $acc . '/app.sqlite', 'json_path' => $acc . '/store.json'],
], true) . ";\n");
require_once $root . '/src/bootstrap.php';
require_once $root . '/src/reminders.php';
$cardLabel = jeab_reminder_due_with_clock(jeab_format_due('2026-10-23 19:30:00'), '2026-10-23 19:30:00');
sd(str_contains($cardLabel, '19:30') && !str_contains($cardLabel, '13:30'), 'kortets formatterare visar 19:30, fick ' . $cardLabel);
$keptStack = jeab_reminder_due_keep_clock(jeab_reminder_due_posted('2026-10-24 01:30:00'), '2026-10-23 19:30:00');
sd($keptStack === '2026-10-24 01:30:00', 'sammanlagda 6 h sparas som 01:30, fick ' . (string) $keptStack);
$store = jeab_store();
$seed = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Stockholm')))->setTime(10, 15, 0);
$created = $store->insertReminder([
    'title' => 'Knufftest',
    'customer_name' => 'Test Knuff',
    'company' => 'jarfalla',
    'due_at' => $seed->format('Y-m-d H:i:s'),
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$accId = (int) ($created['id'] ?? 0);
sd($accId > 0, 'påminnelse att knuffa');
$once = jeab_snooze_reminder($store, $accId, '2h');
$expectOnce = $seed->modify('+2 hours')->format('Y-m-d H:i:s');
sd((string) ($once['due_at'] ?? '') === $expectOnce, 'en 2h lägger två timmar, fick ' . (string) ($once['due_at'] ?? ''));
$twice = jeab_snooze_reminder($store, $accId, '2h');
$expectTwice = $seed->modify('+4 hours')->format('Y-m-d H:i:s');
sd((string) ($twice['due_at'] ?? '') === $expectTwice, 'två 2h lägger fyra timmar, fick ' . (string) ($twice['due_at'] ?? ''));
$day = jeab_snooze_reminder($store, $accId, '2d');
$expectDay = (new DateTimeImmutable($expectTwice))->modify('+2 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
sd((string) ($day['due_at'] ?? '') === $expectDay, '2d lägger två dygn på den tidigare tiden, fick ' . (string) ($day['due_at'] ?? ''));
$day2 = jeab_snooze_reminder($store, $accId, '2d');
$expectDay2 = (new DateTimeImmutable($expectDay))->modify('+2 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
sd((string) ($day2['due_at'] ?? '') === $expectDay2, 'två 2d lägger fyra dygn, fick ' . (string) ($day2['due_at'] ?? ''));

echo "ALLA OK\n";
