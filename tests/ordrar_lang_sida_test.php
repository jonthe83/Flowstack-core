<?php

declare(strict_types=1);

/**
 * Öppnat ärende är en lång sida: Status syns överst och sidan landar på
 * Bekräfta. Nästa i stegen laddar nästa steg som inte är grönt. Slutför
 * heter Nästa. Spara står över Nästa.
 * Bokmalen anropas fortfarande bara via jeab_ordrar_bokmalen_once.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function lang_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
$ordrar = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
lang_assert(str_contains($page, 'data-ordrar-long="1"'), 'ärendet är markerat som lång sida');
lang_assert(str_contains($page, "(\$view === 'status' || \$view === \$landPane) ? '' : ' hidden'"), 'Status och landningspanelen är synliga när ärendet öppnas');
lang_assert(str_contains($page, 'data-js="ordrar-jump"'), 'snabbknapp finns');
lang_assert(str_contains($page, 'function jumpNext'), 'Nästa hoppar till nästa steg');
lang_assert(str_contains($page, 'function nextUngreen'), 'hoppet hoppar över gröna steg');
lang_assert(str_contains($page, "return 'status'"), 'sista steget går tillbaka till Status');
lang_assert(str_contains($page, 'btn.disabled = true') && (str_contains($page, 'goBtn.disabled = true') || str_contains($page, 'window.jeabOrdrarBusy(goBtn, true)')) && str_contains($page, 'quenchNasta'), 'Nästa är släckt medan anropet pågår och efter lyckad sparning');
lang_assert(str_contains($page, 'window.jeabOrdrarBusy(btn, false);') && substr_count($page, 'window.jeabOrdrarBusy(goBtn, false);') === 2 && str_contains($page, 'btn.disabled = false;'), 'Nästa tänds igen efter misslyckad sparning');
lang_assert(!str_contains($page, 'jump.disabled = false'), 'hoppknappen tänds inte igen');
lang_assert(str_contains($page, 'value="ordrar.spara">Spara</button>'), 'Spara finns på bekräfta');
$sparaAt = strpos($page, 'value="ordrar.spara">Spara</button>');
$nastaAt = strpos($page, 'id="ordrar-confirm-go"');
lang_assert($sparaAt !== false && $nastaAt !== false && $sparaAt < $nastaAt, 'Spara står över Nästa');
lang_assert(str_contains($page, 'data-js="ordrar-nasta">Nästa</button>'), 'Slutför heter Nästa');
lang_assert(!str_contains($page, '>Slutför</button>'), 'knappen Slutför är borta');
lang_assert(substr_count($ordrar, 'function jeab_ordrar_bokmalen_once') === 1, 'jeab_ordrar_bokmalen_once är inte kopierad');
lang_assert(str_contains($ordrar, "jeab_ordrar_bokmalen_once('ordrar.foranmalan'"), 'föranmälan går genom once');
lang_assert(str_contains($ordrar, "jeab_ordrar_bokmalen_once('ordrar.bestall'"), 'beställning går genom once');
$specAt = strpos($ordrar, 'ordrar-spec-actions');
$specChunk = $specAt !== false ? substr($ordrar, $specAt, 400) : '';
lang_assert(str_contains($specChunk, 'Spara</button>') && strpos($specChunk, 'Spara</button>') < strpos($specChunk, 'Nästa</button>'), 'Spara står över Nästa på föranmälan');
lang_assert(!str_contains($ordrar, 'data-js="ordrar-jump"'), 'Status har ingen Nästa-knapp');

echo "OK  lång ordersida\n";
