<?php
// Cuenta corriente: desglose de facturas por pago + export a Excel con UN saldo (2026-10-05).
use App\Exports\CuentaCorrienteExport;
use App\Http\Controllers\Tesoreria\Repository\TesCuentaCorrienteRepository;
use Illuminate\Support\Facades\DB;

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];
$cc = app(TesCuentaCorrienteRepository::class);
$benefs = DB::table('tb_tes_orden_pago')->whereNotNull('id_prestador')->distinct()->limit(60)->pluck('id_prestador');

echo "--- 1: cada pago nombra facturas que suman su haber; todas las filas traen 'facturas' ---\n";
$malos = 0; $conDesglose = 0; $sinCampo = 0;
foreach ($benefs as $b) {
    foreach ($cc->movimientosDosSaldos($b, 'PRESTADOR') as $m) {
        if (!array_key_exists('facturas', $m)) { $sinCampo++; continue; }
        if ($m['tipo'] === 'FACTURA' || empty($m['facturas'])) { continue; }
        $conDesglose++;
        if (abs(array_sum(array_column($m['facturas'], 'monto')) - $m['haber']) > 0.01) { $malos++; }
    }
}
echo "  pagos con desglose {$conDesglose}, desglose != haber {$malos}, filas sin campo {$sinCampo}\n";
$r[] = ($conDesglose > 0 && $malos === 0 && $sinCampo === 0);
echo $ok(end($r));

echo "--- 2: el export cierra con el mismo saldo que la pantalla, en los dos saldos ---\n";
$bien = 0; $total = 0;
foreach ($benefs->take(25) as $b) {
    $mov = $cc->movimientosDosSaldos($b, 'PRESTADOR');
    if (!$mov) { continue; }
    foreach (['economico' => 'saldo_economico', 'financiero' => 'saldo_financiero'] as $s => $campo) {
        $filas = (new CuentaCorrienteExport($cc, $b, 'PRESTADOR', null, null, null, $s))->array();
        $total++;
        if (abs(end($filas)[10] - end($mov)[$campo]) < 0.01) { $bien++; }
    }
}
echo "  bien {$bien}/{$total}\n";
$r[] = ($total > 0 && $bien === $total);
echo $ok(end($r));

echo "--- 3: con periodo arranca en SALDO ANTERIOR y genera el xlsx ---\n";
$b = $benefs->first(fn($b) => count($cc->movimientosDosSaldos($b, 'PRESTADOR')) >= 6);
$todo = $cc->movimientosDosSaldos($b, 'PRESTADOR');
$desde = $todo[intdiv(count($todo), 2)]['fecha'];
$exp = new CuentaCorrienteExport($cc, $b, 'PRESTADOR', $desde, null, 'recepcion', 'financiero');
$filas = $exp->array();
$primera = $filas[5] ?? [];
$bin = \Maatwebsite\Excel\Facades\Excel::raw($exp, \Maatwebsite\Excel\Excel::XLSX);
echo "  primera fila: " . ($primera[1] ?? '?') . " | xlsx " . strlen($bin) . " bytes\n";
$r[] = (($primera[1] ?? '') === 'SALDO ANTERIOR' || $todo[0]['fecha'] >= $desde) && strlen($bin) > 1000;
echo $ok(end($r));

$c = count(array_filter($r));
echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . "\n";
