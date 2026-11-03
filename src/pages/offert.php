<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
$store = isset($store) && $store instanceof JeabStore ? $store : null;
$standard = jeab_offert_standard_map($store);
$calcIn = $standard;
$g27Saved = jeab_offert_num($calcIn['g27'] ?? 70000, 70000.0);
$g27Auto = abs($g27Saved - 70000.0) < 0.5;
if ($g27Auto) {
    unset($calcIn['g27']);
}
$savedBattery = jeab_offert_saved_battery($store);
if ($savedBattery !== null) {
    $calcIn['sigen_typ'] = $savedBattery['sigen_typ'];
    $calcIn['sigen_kw'] = $savedBattery['sigen_kw'];
} elseif (trim((string) ($calcIn['sigen_typ'] ?? '')) === '') {
    $calcIn['sigen_typ'] = 'neo';
    $calcIn['sigen_kw'] = '12.0';
    if (!function_exists('jeab_sigen_sizes') || !in_array('12.0', jeab_sigen_sizes('neo'), true)) {
        $calcIn['sigen_typ'] = 'tp2';
        $calcIn['sigen_kw'] = '12.0';
    }
}
$calc = jeab_offert_calc($calcIn);
$barStandard = $standard;
$barStandard['sigen_typ'] = (string) ($calc['sigen_typ'] ?? '');
$barStandard['sigen_kw'] = (string) ($calc['sigen_kw'] ?? '');
$evIn = [];
foreach (jeab_offert_ev_defaults() as $key => $_) {
    if (array_key_exists('ev_' . $key, $standard)) {
        $evIn[$key] = $standard['ev_' . $key];
    }
}
$ev = jeab_offert_ev_calc($evIn);
$margins = jeab_offert_margins_label($calc);
$offertSaveAction = (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj())
    ? 'kalkyl.php'
    : 'index.php?p=offert';

ob_start();
?>
<div class="offert-board">
<div class="offert" id="offert-app" data-js="offert" data-neo-kundpris="<?= e((string) json_encode(jeab_offert_neo_kundpris_ladder(), JSON_UNESCAPED_UNICODE)) ?>">
    <div class="offert-panel offert-scroll">
        <form class="offert-daily" autocomplete="off">
            <?= jeab_offert_daily_controls_html($calc) ?>
        </form>
        <details class="offert-month">
            <summary>Ändra och visa detaljer <span class="offert-chevron" aria-hidden="true"></span></summary>
            <div class="offert-month-box">
                <?= jeab_offert_sigen_price_fields_html($standard) ?>
                <label class="offert-field"><span>Batteri timmar</span><input type="text" inputmode="numeric" data-in="b5" value="<?= e(jeab_offert_int(24)) ?>"></label>
                <label class="offert-field"><span>Elbilsladdare inköp</span><input type="text" inputmode="numeric" data-in="laddare_unit" value="<?= e(jeab_offert_int(6793)) ?>"></label>
                <label class="offert-field"><span>Laddare timmar</span><input type="text" inputmode="numeric" data-in="laddare_hours" value="<?= e(jeab_offert_int(8)) ?>"></label>
                <label class="offert-field"><span>Övrigt sol</span><input type="text" inputmode="numeric" data-in="c13" value="<?= e(jeab_offert_int(2000)) ?>"></label>
                <label class="offert-field"><span>Sol timmar</span><input type="text" inputmode="numeric" data-in="b17" value="<?= e(jeab_offert_int(16)) ?>"></label>
                <label class="offert-field"><span>Per panel + material</span><input type="text" inputmode="numeric" data-in="c28" value="<?= e(jeab_offert_int(1558)) ?>"></label>
                <label class="offert-field"><span>Timpris</span><input type="text" inputmode="numeric" data-in="hourly" value="<?= e(jeab_offert_int(750)) ?>"></label>
            </div>
        </details>
        <p class="offert-margins" data-out="margins"><?= e($margins) ?></p>
    </div>

    <aside class="offert-cards" aria-live="polite">
        <section class="offert-card">
            <h2>SOLPANELER OCH BATTERI</h2>
            <p><span>Batteri</span><b data-out="i10"><?= e(jeab_offert_money($calc['i10'])) ?></b></p>
            <p><span>Solpaneler</span><b data-out="i11"><?= e(jeab_offert_money($calc['i11'])) ?></b></p>
            <p><span>Schablon</span><b data-out="i12"><?= e(jeab_offert_money($calc['i12'])) ?></b></p>
            <p class="offert-strong"><span>Att betala</span><b data-out="i14"><?= e(jeab_offert_money($calc['i14'])) ?></b></p>
            <p><span>Total</span><b data-out="i15"><?= e(jeab_offert_money($calc['i15'])) ?></b></p>
            <p class="offert-gt"><span>Grön teknik</span><b data-out="i16"><?= e(jeab_offert_money($calc['i16'])) ?></b></p>
            <p><span>Vinst</span><b data-out="al20"><?= e(jeab_offert_money($calc['al20'])) ?></b></p>
            <label class="offert-card-input">
                <span>Kundpris</span>
                <input type="text" inputmode="numeric" name="c23" data-in="c23" data-auto="1" value="<?= e(jeab_offert_int($calc['c24'])) ?>">
            </label>
        </section>
        <section class="offert-card" data-card="battery-only">
            <h2>ENDAST BATTERI</h2>
            <p><span>Summa</span><b data-out="h31"><?= e(jeab_offert_money($calc['h31'])) ?></b></p>
            <p><span>Schablon</span><b data-out="h29"><?= e(jeab_offert_money($calc['h29'])) ?></b></p>
            <p><span>Utan</span><b data-out="h28"><?= e(jeab_offert_money($calc['h28'])) ?></b></p>
            <p class="offert-gt"><span>Grön teknik</span><b data-out="h30"><?= e(jeab_offert_money($calc['h30'])) ?></b></p>
            <p><span>Vinst</span><b data-out="g27_vinst"><?= e(jeab_offert_money($calc['g27_vinst'])) ?></b></p>
            <label class="offert-card-input">
                <span>Kundpris</span>
                <input type="text" inputmode="numeric" name="g27" data-in="g27" data-auto="<?= $g27Auto ? '1' : '0' ?>" value="<?= e(jeab_offert_int($calc['g27'])) ?>">
            </label>
        </section>
    </aside>
</div>

<section class="offert-ev" id="offert-ev" data-js="offert-ev">
    <div class="offert-ev-body">
        <div class="offert-ev-panel offert-ev-form">
            <h2 class="offert-ev-title">Elbilsladdare (BRF)</h2>
            <form class="offert-ev-daily" autocomplete="off">
                <label class="offert-field">
                    <span>Meter 5G6</span>
                    <input type="text" inputmode="numeric" name="ev_c4" data-in="ev_c4" value="<?= e(jeab_offert_int($ev['c4'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Meter 5G16</span>
                    <input type="text" inputmode="numeric" name="ev_c5" data-in="ev_c5" value="<?= e(jeab_offert_int($ev['c5'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Kopplingsdosa, a-box</span>
                    <input type="text" inputmode="numeric" name="ev_c6" data-in="ev_c6" value="<?= e(jeab_offert_int($ev['c6'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Laddare</span>
                    <input type="text" inputmode="numeric" name="ev_c7" data-in="ev_c7" value="<?= e(jeab_offert_int($ev['c7'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Säkring för dawn</span>
                    <input type="text" inputmode="numeric" name="ev_c8" data-in="ev_c8" value="<?= e(jeab_offert_int($ev['c8'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Lastbalans</span>
                    <input type="text" inputmode="numeric" name="ev_c9" data-in="ev_c9" value="<?= e(jeab_offert_int($ev['c9'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Extra material (kr)</span>
                    <input type="text" inputmode="numeric" name="ev_c10" data-in="ev_c10" value="<?= e(jeab_offert_int($ev['c10'])) ?>">
                </label>
                <label class="offert-field">
                    <span>Extra arbetstid (h)</span>
                    <input type="text" inputmode="numeric" name="ev_c11" data-in="ev_c11" value="<?= e(jeab_offert_int($ev['c11'])) ?>">
                </label>
                <label class="offert-field offert-ev-wide">
                    <span>Extra externa (kr)</span>
                    <input type="text" inputmode="numeric" name="ev_c12" data-in="ev_c12" value="<?= e(jeab_offert_int($ev['c12'])) ?>">
                </label>
            </form>
            <p class="offert-margins offert-ev-hours" data-out="hours"><?= e($ev['hours']) ?></p>

            <details class="offert-month offert-ev-month">
                <summary>Ändra och visa detaljer <span class="offert-chevron" aria-hidden="true"></span></summary>
                <div class="offert-month-box">
                    <label class="offert-field"><span>5G6 kabel (kr/m)</span><input type="text" inputmode="numeric" data-in="ev_d4" value="<?= e(jeab_offert_int($ev['d4'])) ?>"></label>
                    <label class="offert-field"><span>5G16 kabel (kr/m)</span><input type="text" inputmode="numeric" data-in="ev_d5" value="<?= e(jeab_offert_int($ev['d5'])) ?>"></label>
                    <label class="offert-field"><span>Dosa (kr)</span><input type="text" inputmode="numeric" data-in="ev_d6" value="<?= e(jeab_offert_int($ev['d6'])) ?>"></label>
                    <label class="offert-field"><span>Laddare (kr)</span><input type="text" inputmode="numeric" data-in="ev_d7" value="<?= e(jeab_offert_int($ev['d7'])) ?>"></label>
                    <label class="offert-field"><span>Säkring (kr)</span><input type="text" inputmode="numeric" data-in="ev_d8" value="<?= e(jeab_offert_int($ev['d8'])) ?>"></label>
                    <label class="offert-field"><span>Lastbalans (kr)</span><input type="text" inputmode="numeric" data-in="ev_d9" value="<?= e(jeab_offert_int($ev['d9'])) ?>"></label>
                    <label class="offert-field"><span>Extra timpris (kr/h)</span><input type="text" inputmode="numeric" data-in="ev_hourly_extra" value="<?= e(jeab_offert_int($ev['hourly_extra'])) ?>"></label>
                    <label class="offert-field"><span>Tim låg (kr/h)</span><input type="text" inputmode="numeric" data-in="ev_hour_low" value="<?= e(jeab_offert_int($ev['hour_low'])) ?>"></label>
                    <label class="offert-field"><span>Tim hög (kr/h)</span><input type="text" inputmode="numeric" data-in="ev_hour_high" value="<?= e(jeab_offert_int($ev['hour_high'])) ?>"></label>
                    <label class="offert-field"><span>Övrigt elmaterial bas</span><input type="text" inputmode="numeric" data-in="ev_mat_base" value="<?= e(jeab_offert_int($ev['mat_base'])) ?>"></label>
                    <label class="offert-field"><span>Övrigt elmaterial / laddare låg</span><input type="text" inputmode="numeric" data-in="ev_mat_per_low" value="<?= e(jeab_offert_int($ev['mat_per_low'])) ?>"></label>
                    <label class="offert-field"><span>Övrigt elmaterial / laddare hög</span><input type="text" inputmode="numeric" data-in="ev_mat_per_high" value="<?= e(jeab_offert_int($ev['mat_per_high'])) ?>"></label>
                    <label class="offert-field"><span>Övrigt elmaterial låg</span><b class="offert-ev-readonly" data-out="c16"><?= e(jeab_offert_money($ev['c16'])) ?></b></label>
                    <label class="offert-field"><span>Övrigt elmaterial hög</span><b class="offert-ev-readonly" data-out="d16"><?= e(jeab_offert_money($ev['d16'])) ?></b></label>
                    <label class="offert-field"><span>Elcentral låg (h)</span><input type="text" inputmode="numeric" data-in="ev_c21" value="<?= e(jeab_offert_int($ev['c21'])) ?>"></label>
                    <label class="offert-field"><span>Elcentral hög (h)</span><input type="text" inputmode="numeric" data-in="ev_d21" value="<?= e(jeab_offert_int($ev['d21'])) ?>"></label>
                </div>
                <div class="offert-ev-labor">
                    <h3>Egen arbetstid</h3>
                    <div class="offert-ev-labor-wrap">
                        <table class="offert-ev-labor-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Lågt</th>
                                    <th>Högt</th>
                                    <th>Lågt</th>
                                    <th>Högt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row">Övrigt elmaterial</th>
                                    <td data-out="c16"><?= e(jeab_offert_money($ev['c16'])) ?></td>
                                    <td data-out="d16"><?= e(jeab_offert_money($ev['d16'])) ?></td>
                                    <td data-out="e16"><?= e(jeab_offert_money($ev['e16'])) ?></td>
                                    <td data-out="f16"><?= e(jeab_offert_money($ev['f16'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Arbetstid kabel</th>
                                    <td data-out="c17" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['c17'])) ?></td>
                                    <td data-out="d17" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['d17'])) ?></td>
                                    <td data-out="e17"><?= e(jeab_offert_money($ev['e17'])) ?></td>
                                    <td data-out="f17"><?= e(jeab_offert_money($ev['f17'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Arbetstid boxar</th>
                                    <td data-out="c18" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['c18'])) ?></td>
                                    <td data-out="d18" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['d18'])) ?></td>
                                    <td data-out="e18"><?= e(jeab_offert_money($ev['e18'])) ?></td>
                                    <td data-out="f18"><?= e(jeab_offert_money($ev['f18'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Arbetstid laddare</th>
                                    <td data-out="c19" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['c19'])) ?></td>
                                    <td data-out="d19" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['d19'])) ?></td>
                                    <td data-out="e19"><?= e(jeab_offert_money($ev['e19'])) ?></td>
                                    <td data-out="f19"><?= e(jeab_offert_money($ev['f19'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Arbetstid projektledning</th>
                                    <td data-out="c20" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['c20'])) ?></td>
                                    <td data-out="d20" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['d20'])) ?></td>
                                    <td data-out="e20"><?= e(jeab_offert_money($ev['e20'])) ?></td>
                                    <td data-out="f20"><?= e(jeab_offert_money($ev['f20'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Arbete elcentral</th>
                                    <td data-out="c21" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['c21'])) ?></td>
                                    <td data-out="d21" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['d21'])) ?></td>
                                    <td data-out="e21"><?= e(jeab_offert_money($ev['e21'])) ?></td>
                                    <td data-out="f21"><?= e(jeab_offert_money($ev['f21'])) ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row">Totalt</th>
                                    <td data-out="hours_low" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['hours_low'])) ?></td>
                                    <td data-out="hours_high" data-fmt="h"><?= e(jeab_offert_hours_cell($ev['hours_high'])) ?></td>
                                    <td data-out="e23"><?= e(jeab_offert_money($ev['e23'])) ?></td>
                                    <td data-out="f23"><?= e(jeab_offert_money($ev['f23'])) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </details>
        </div>

        <aside class="offert-ev-cards" aria-live="polite">
            <section class="offert-card offert-ev-card">
                <p class="offert-ev-total"><span>Rekommenderat pris inklusive moms</span><b data-out="rek_inkl"><?= e(jeab_offert_money($ev['rek_inkl'])) ?></b></p>
                <p><span>Arbetstid</span><b data-out="hours_rek"><?= e(jeab_offert_hours($ev['hours_rek'])) ?> h</b></p>
                <p><span>Inköpspris ink moms</span><b data-out="inkop_rek"><?= e(jeab_offert_money($ev['inkop_rek'])) ?></b></p>
                <p><span>Vinst, ex moms</span><b data-out="vinst_rek"><?= e(jeab_offert_money($ev['vinst_rek'])) ?></b></p>
            </section>
            <section class="offert-card offert-ev-card">
                <p class="offert-ev-total"><span>Självkostnadspris inklusive moms</span><b data-out="sjalv_inkl"><?= e(jeab_offert_money($ev['sjalv_inkl'])) ?></b></p>
                <p><span>Arbetstid</span><b data-out="hours_sjalv"><?= e(jeab_offert_hours($ev['hours_sjalv'])) ?> h</b></p>
                <p><span>Inköpspris ink moms</span><b data-out="inkop_sjalv"><?= e(jeab_offert_money($ev['inkop_sjalv'])) ?></b></p>
                <p><span>Vinst, ex moms</span><b data-out="vinst_sjalv"><?= e(jeab_offert_money($ev['vinst_sjalv'])) ?></b></p>
            </section>
            <section class="offert-card offert-ev-card">
                <p class="offert-ev-total"><span>Listpris inklusive moms</span><b data-out="list_inkl"><?= e(jeab_offert_money($ev['list_inkl'])) ?></b></p>
                <p><span>Arbetstid</span><b data-out="hours_list"><?= e(jeab_offert_hours($ev['hours_list'])) ?> h</b></p>
                <p><span>Inköpspris ink moms</span><b data-out="inkop_list"><?= e(jeab_offert_money($ev['inkop_list'])) ?></b></p>
                <p><span>Vinst, ex moms</span><b data-out="vinst_list"><?= e(jeab_offert_money($ev['vinst_list'])) ?></b></p>
            </section>
        </aside>
    </div>
</section>
<div class="offert-save" data-js="offert-save-bar" data-action="<?= e(jeab_url($offertSaveAction)) ?>" data-standard="<?= e(json_encode($barStandard, JSON_UNESCAPED_UNICODE)) ?>" hidden>
    <p class="offert-save-error" data-js="offert-save-error" role="alert" hidden></p>
    <button type="button" data-js="offert-save">spara nya värden som standard</button>
</div>
</div>
<?php
return ob_get_clean();
