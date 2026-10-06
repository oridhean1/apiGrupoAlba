<?php
// Imputacion FIFO: la mas vieja primero, cancelada entera antes de pasar a la siguiente.

use App\Http\Controllers\Tesoreria\Repository\TesImputacionFifoRepository as Fifo;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$r = [];
$opaRepo = new TestOrdenPagoRepository();
$fifo = new Fifo($opaRepo);
$inst = new Inst();

// OPA agrupada con varias facturas
$idOpa = DB::table('tb_tes_opa_factura')->select('id_orden_pago', DB::raw('COUNT(*) c'))
    ->groupBy('id_orden_pago')->havingRaw('COUNT(*) >= 4')->orderByDesc('c')->value('id_orden_pago');
if (!$idOpa) { echo "SIN OPA AGRUPADA\n"; return; }

DB::beginTransaction();
try {
    // Se arranca de cero: se anulan los pagos previos para controlar el escenario
    DB::table('tb_tes_pago')->where('id_orden_pago', $idOpa)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO]);

    $orden = $fifo->facturasOrdenadas($idOpa);
    echo "OPA {$idOpa} con " . $orden->count() . " facturas\n";
    echo "--- 1: orden determinístico (fecha, y ante empate id_factura) ---\n";
    foreach ($orden->take(6) as $f) {
        printf("  %s  id=%-6s %14s\n", $f->fecha_comprobante, $f->id_factura,
            number_format($f->monto_aplicado, 2, ',', '.'));
    }
    $fechas = $orden->pluck('fecha_comprobante')->all();
    $ordenadas = $fechas; sort($ordenadas);
    $r['orden por fecha'] = ($fechas === $ordenadas);

    // Ante empate de fecha, id_factura ascendente
    $okEmpate = true;
    for ($i = 1; $i < $orden->count(); $i++) {
        if ($orden[$i]->fecha_comprobante === $orden[$i - 1]->fecha_comprobante
            && $orden[$i]->id_factura < $orden[$i - 1]->id_factura) { $okEmpate = false; }
    }
    $r['desempate por id_factura'] = $okEmpate;

    echo "\n--- 2: sin pagos -> todo PENDIENTE ---\n";
    $d = $fifo->distribuir($idOpa);
    printf("  imputado=%s pagado=%s sin_aplicar=%s\n",
        number_format($d['total_imputado'], 2, ',', '.'),
        number_format($d['total_pagado'], 2, ',', '.'),
        number_format($d['sin_aplicar'], 2, ',', '.'));
    $estados = array_column($d['facturas'], 'estado');
    echo "  estados: " . json_encode(array_count_values($estados)) . "\n";
    $r['sin pagos todo pendiente'] = (count(array_unique($estados)) === 1 && $estados[0] === Fifo::PENDIENTE);
    $r['pagado cero'] = ($d['total_pagado'] == 0.0);

    // Pago que cubre exactamente las 2 primeras facturas
    $dos = $d['facturas'][0]['imputado'] + $d['facturas'][1]['imputado'];
    echo "\n--- 3: pago exacto de las 2 primeras (" . number_format($dos, 2, ',', '.') . ") ---\n";
    $c = $inst->crearInstrumentos($idOpa, [['monto' => $dos, 'fecha' => '2026-09-30', 'id_banco_emisor' => 2]]);
    $inst->marcarPendienteEmision($idOpa);
    $inst->guardarBorradorNumero($c[0]->id_pago, 'FIFO-TEST-1');
    $inst->confirmarEmisionDeOpa($idOpa, $opaRepo);
    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el
    // descuento del saldo salen de ahi, no del camino de acreditacion. Este test no prueba ese
    // flujo, asi que se deja la boleta confirmada directamente.
    DB::table('tb_tes_pago')->where('id_orden_pago', $idOpa)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);
    $inst->marcarAcreditado($c[0]->id_pago, '2026-09-30', $opaRepo);

    $d = $fifo->distribuir($idOpa);
    foreach (array_slice($d['facturas'], 0, 4) as $f) {
        printf("  id=%-6s imputado=%12s pagado=%12s saldo=%12s %s\n", $f['id_factura'],
            number_format($f['imputado'], 2, ',', '.'), number_format($f['pagado'], 2, ',', '.'),
            number_format($f['saldo'], 2, ',', '.'), $f['estado']);
    }
    $r['dos cubiertas exactas'] = ($d['facturas'][0]['estado'] === Fifo::CUBIERTA
        && $d['facturas'][1]['estado'] === Fifo::CUBIERTA
        && $d['facturas'][2]['estado'] === Fifo::PENDIENTE);
    $r['sin resto flotando'] = ($d['sin_aplicar'] == 0.0);

    // Pago adicional que cubre la mitad de la tercera
    $mitad = round($d['facturas'][2]['imputado'] / 2, 2);
    echo "\n--- 4: pago parcial de la tercera (" . number_format($mitad, 2, ',', '.') . ") ---\n";
    $c2 = $inst->crearInstrumentos($idOpa, [['monto' => $mitad, 'fecha' => '2026-10-05', 'id_banco_emisor' => 2]]);
    $inst->marcarPendienteEmision($idOpa);
    $inst->guardarBorradorNumero($c2[0]->id_pago, 'FIFO-TEST-2');
    $inst->confirmarEmisionDeOpa($idOpa, $opaRepo);
    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el
    // descuento del saldo salen de ahi, no del camino de acreditacion. Este test no prueba ese
    // flujo, asi que se deja la boleta confirmada directamente.
    DB::table('tb_tes_pago')->where('id_orden_pago', $idOpa)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);
    $inst->marcarAcreditado($c2[0]->id_pago, '2026-10-05', $opaRepo);

    $d = $fifo->distribuir($idOpa);
    $t = $d['facturas'][2];
    printf("  tercera: imputado=%s pagado=%s saldo=%s -> %s\n",
        number_format($t['imputado'], 2, ',', '.'), number_format($t['pagado'], 2, ',', '.'),
        number_format($t['saldo'], 2, ',', '.'), $t['estado']);
    $r['tercera parcial'] = ($t['estado'] === Fifo::PARCIAL && $t['pagado'] == $mitad);
    $r['cuarta intacta'] = ($d['facturas'][3]['estado'] === Fifo::PENDIENTE);
    $r['no prorratea'] = ($d['facturas'][0]['estado'] === Fifo::CUBIERTA
        && $d['facturas'][1]['estado'] === Fifo::CUBIERTA);

    echo "\n--- 5: la suma de lo aplicado = lo pagado (no se pierde ni se inventa plata) ---\n";
    $suma = array_sum(array_column($d['facturas'], 'pagado'));
    printf("  suma aplicada=%s | total pagado=%s | sin aplicar=%s\n",
        number_format($suma, 2, ',', '.'), number_format($d['total_pagado'], 2, ',', '.'),
        number_format($d['sin_aplicar'], 2, ',', '.'));
    $r['conservacion'] = (abs(($suma + $d['sin_aplicar']) - $d['total_pagado']) < 0.005);

    echo "\n--- 6: idempotente, dos corridas dan lo mismo ---\n";
    $a = json_encode($fifo->distribuir($idOpa));
    $b = json_encode($fifo->distribuir($idOpa));
    echo '  identicas: ' . var_export($a === $b, true) . "\n";
    $r['idempotente'] = ($a === $b);

    echo "\n--- 7: sobrepago -> queda en sin_aplicar, no se lo come ---\n";
    $c3 = $inst->crearInstrumentos($idOpa, [['monto' => $d['total_imputado'], 'fecha' => '2026-10-10', 'id_banco_emisor' => 2]]);
    $inst->marcarPendienteEmision($idOpa);
    $inst->guardarBorradorNumero($c3[0]->id_pago, 'FIFO-TEST-3');
    $inst->confirmarEmisionDeOpa($idOpa, $opaRepo);
    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el
    // descuento del saldo salen de ahi, no del camino de acreditacion. Este test no prueba ese
    // flujo, asi que se deja la boleta confirmada directamente.
    DB::table('tb_tes_pago')->where('id_orden_pago', $idOpa)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);
    $inst->marcarAcreditado($c3[0]->id_pago, '2026-10-10', $opaRepo);

    $d = $fifo->distribuir($idOpa);
    $cubiertas = count(array_filter($d['facturas'], fn($f) => $f['estado'] === Fifo::CUBIERTA));
    printf("  cubiertas=%d/%d | sin_aplicar=%s\n", $cubiertas, count($d['facturas']),
        number_format($d['sin_aplicar'], 2, ',', '.'));
    $r['todas cubiertas'] = ($cubiertas === count($d['facturas']));
    $r['sobrante informado'] = ($d['sin_aplicar'] > 0);

    echo "\n--- 8: estado de una factura suelta ---\n";
    $ef = $fifo->estadoDeFactura($d['facturas'][0]['id_factura']);
    echo '  ' . json_encode($ef, JSON_UNESCAPED_UNICODE) . "\n";
    $r['estado de factura'] = in_array($ef['estado'], [Fifo::CUBIERTA, Fifo::PARCIAL, Fifo::PENDIENTE], true);

    echo "\n--- 9: los pagos RECHAZADOS no cuentan ---\n";
    $antes = $fifo->distribuir($idOpa)['total_pagado'];
    $inst->marcarRechazado($c3[0]->id_pago, 'sin fondos', $opaRepo);
    $despues = $fifo->distribuir($idOpa)['total_pagado'];
    printf("  pagado antes=%s despues=%s\n", number_format($antes, 2, ',', '.'), number_format($despues, 2, ',', '.'));
    $r['rechazado no cuenta'] = ($despues < $antes);

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-28s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
