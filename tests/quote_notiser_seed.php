<?php

declare(strict_types=1);

/**
 * Sparar de texter send_quote läser för sol och batteri.
 * Utan sparad Notis hålls utskicket. Den här filen skickar inget.
 */
function jeab_test_seed_quote_notiser(): void
{
    $plain = static function (string $variant): string {
        $html = jeab_offert_mall_body($variant, 'Hej');
        $text = trim(html_entity_decode(strip_tags(str_replace(['</div>', '<br>', '<br/>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        if (!str_contains($text, 'medal-solpanel.png')) {
            $text .= "\nhttps://flowstack.example.test/assets/img/medal-solpanel.png";
        }

        return $text;
    };
    jeab_notiser_save_body('offert-sol-jarfalla-kopt', $plain('kopt'), 'test');
    jeab_notiser_save_body('offert-sol-jarfalla-eget', $plain('eget'), 'test');
    $seed = jeab_auto_quote_seed();
    jeab_notiser_save_body('offert-batteri-jarfalla', (string) $seed['batteri_jarfalla'], 'test');
    $malmo = trim((string) ($seed['batteri_malmo'] ?? ''));
    if ($malmo === '') {
        $malmo = jeab_offer_batteri_neo_price_paragraph() . "\n\nKim\nElektriker\nDemo Energi Malmö";
    }
    jeab_notiser_save_body('offert-batteri-malmo', $malmo, 'test');
    $solMalmo = trim((string) ($seed['sol_malmo'] ?? ''));
    if ($solMalmo !== '' && function_exists('jeab_auto_sol_jarfalla_placeholder') && !jeab_auto_sol_jarfalla_placeholder($solMalmo)) {
        jeab_notiser_save_body('offert-sol-malmo', $solMalmo, 'test');
    }
}
