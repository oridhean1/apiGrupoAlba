<?php
// Cuenta corriente con saldo ECONOMICO y FINANCIERO (doc de Micaela, 2026-10-01).
//   economico : baja cuando el pago se imputa a la factura.
//   financiero: baja cuando la plata sale del banco (eCheq: al acreditarse).
use App\Http\Controllers\Tesoreria\Repository\TesCuentaCorrienteRepository;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

$cc  = app(TesCuentaCorrienteRepository::class);
$opa = app(TestOrdenPagoRepository::class);
$fin = fn($b) => (($m = $cc->movimientosDosSaldos($b, 'PRESTADOR')) ? end($m)['saldo_financiero'] : 0.0);
$eco = fn($b) => (($m = $cc->movimientosDosSaldos($b, 'PRESTADOR')) ? end($m)['saldo_economico'] : 0.0);

echo "--- 1: el saldo financiero coincide con la deuda que calcula el FIFO ---\n";
// La vista vieja de la cuenta corriente ERA la financiera (el FIFO solo cuenta abonos con fecha de
// cobro). Si esto no coincide, la vista nueva cambio el numero que ya se usaba.
$benefs = DB::table('tb_tes_orden_pago')->whereNotNull('id_prestador')->distinct()->limit(60)->pluck('id_prestador');
$coinciden = 0;
foreach ($benefs as $b) {
    $res = $cc->resumen($b, 'PRESTADOR');
    if (abs($fin($b) - round($res['total_facturado'] - $res['total_pagado'], 2)) < 0.01) { $coinciden++; }
}
echo "  coinciden {$coinciden}/" . count($benefs) . "\n";
$r[] = ($coinciden === count($benefs));
echo $ok(end($r));

// Una orden viva de prestador con lugar para un pago mas: lo imputado supera lo cubierto.
$cand = DB::table('tb_tes_orden_pago as o')
    ->join('tb_tes_pago as b', 'b.id_orden_pago', '=', 'o.id_orden_pago')
    ->where('o.tipo_opa', 'NORMAL')->whereIn('o.id_estado_orden_pago', [1, 4, 6])
    ->whereNotNull('o.id_prestador')->where('b.id_estado_orden_pago', '!=', 3)
    ->select('o.id_orden_pago', 'o.id_prestador', 'b.id_pago')
    ->orderByDesc('o.id_orden_pago')->limit(300)->get()
    ->first(function ($c) use ($opa) {
        $imp = (float) DB::table('tb_tes_opa_factura')->where('id_orden_pago', $c->id_orden_pago)->sum('monto_aplicado');
        return $imp - $opa->montoCubiertoOpa($c->id_orden_pago) >= 100;
    });

if (!$cand) {
    echo "SE SALTEA el escenario: no hay una orden viva con saldo para cargarle un pago\n";
} else {
    DB::beginTransaction();
    try {
        $b = $cand->id_prestador;
        $eco0 = $eco($b); $fin0 = $fin($b);

        // eCheq que entro en un pago confirmado pero todavia no se acredito.
        $id = DB::table('tb_tes_pago_parcial')->insertGetId([
            'fecha_registra' => now(), 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => 100,
            'monto_opa' => 100, 'id_usuario' => 1, 'id_pago' => $cand->id_pago, 'monto_restante' => 0,
            'id_estado_instrumento' => Inst::EMITIDO, 'numero_echeq' => 'CC2S-' . getmypid(),
            'fecha_confirmado_en_pago' => now(), 'fecha_confirma_pago' => null,
        ]);

        echo "--- 2: eCheq EMITIDO -> baja el economico, el financiero queda congelado ---\n";
        $eco1 = $eco($b); $fin1 = $fin($b);
        echo "  economico {$eco0} -> {$eco1} | financiero {$fin0} -> {$fin1}\n";
        $r[] = (abs(($eco0 - $eco1) - 100) < 0.01 && abs($fin1 - $fin0) < 0.01);
        echo $ok(end($r));

        echo "--- 3: el renglon del eCheq trae fecha pactada vacia/estado EMITIDO y no tiene acreditacion ---\n";
        $fila = collect($cc->movimientosDosSaldos($b, 'PRESTADOR'))
            ->first(fn($m) => str_contains($m['comprobante'], 'CC2S-'));
        echo "  " . ($fila ? "estado={$fila['estado']} acreditada=" . var_export($fila['fecha_acreditada'], true)
            . " baja_fin=" . var_export($fila['baja_financiero'], true) : 'NO aparece el renglon') . "\n";
        $r[] = ($fila && $fila['estado'] === 'EMITIDO' && is_null($fila['fecha_acreditada']) && !$fila['baja_financiero']);
        echo $ok(end($r));

        echo "--- 4: ACREDITADO -> ahi baja el financiero y los dos vuelven a coincidir ---\n";
        DB::table('tb_tes_pago_parcial')->where('id_pago_parcial', $id)
            ->update(['id_estado_instrumento' => Inst::ACREDITADO, 'fecha_confirma_pago' => now()->toDateString()]);
        $eco2 = $eco($b); $fin2 = $fin($b);
        echo "  economico {$eco2} | financiero {$fin0} -> {$fin2}\n";
        $r[] = (abs(($fin0 - $fin2) - 100) < 0.01 && abs($eco2 - $eco1) < 0.01);
        echo $ok(end($r));

        echo "--- 5: RECHAZADO -> se revierten los dos (criterio del doc ante un rechazo) ---\n";
        DB::table('tb_tes_pago_parcial')->where('id_pago_parcial', $id)
            ->update(['id_estado_instrumento' => Inst::RECHAZADO, 'fecha_confirma_pago' => null]);
        $eco3 = $eco($b); $fin3 = $fin($b);
        echo "  economico {$eco3} (inicial {$eco0}) | financiero {$fin3} (inicial {$fin0})\n";
        $r[] = (abs($eco3 - $eco0) < 0.01 && abs($fin3 - $fin0) < 0.01);
        echo $ok(end($r));
    } catch (\Throwable $e) {
        echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
        $r[] = false;
    } finally {
        DB::rollBack();
    }
}

echo "--- 6: con periodo, el SALDO ANTERIOR es la deuda real a esa fecha, con los tres criterios ---\n";
// Minuta con el cliente (2026-10-05): un solo rango con selector de criterio, y lo anterior al
// periodo resumido en un renglon. Los saldos se calculan sobre la historia completa ANTES de
// recortar: si arrancaran en cero, el saldo de un periodo no seria la deuda.
$bien = 0; $total = 0;
foreach (DB::table('tb_tes_orden_pago')->whereNotNull('id_prestador')->distinct()->limit(25)->pluck('id_prestador') as $b) {
    foreach (['recepcion', 'comprobante', 'emision_opa'] as $crit) {
        $todo = $cc->movimientosDosSaldos($b, 'PRESTADOR', null, null, $crit);
        if (count($todo) < 4) { continue; }
        $desde = $todo[intdiv(count($todo), 2)]['fecha'];
        $hasta = end($todo)['fecha'];
        $previos = array_values(array_filter($todo, fn($m) => $m['fecha'] < $desde));
        $hastaFila = array_values(array_filter($todo, fn($m) => $m['fecha'] <= $hasta));
        $per = $cc->movimientosDosSaldos($b, 'PRESTADOR', $desde, $hasta, $crit);
        $total++;
        $okAnterior = empty($previos)
            ? ($per[0]['tipo'] ?? '') !== 'SALDO_ANTERIOR'
            : (($per[0]['tipo'] ?? '') === 'SALDO_ANTERIOR'
                && abs($per[0]['saldo_economico'] - end($previos)['saldo_economico']) < 0.01
                && abs($per[0]['saldo_financiero'] - end($previos)['saldo_financiero']) < 0.01
                && $per[0]['movimientos_resumidos'] === count($previos));
        $okCierre = abs(end($per)['saldo_financiero'] - end($hastaFila)['saldo_financiero']) < 0.01
            && abs(end($per)['saldo_economico'] - end($hastaFila)['saldo_economico']) < 0.01;
        if ($okAnterior && $okCierre) { $bien++; }
    }
}
echo "  casos bien: {$bien}/{$total}\n";
$r[] = ($total > 0 && $bien === $total);
echo $ok(end($r));

echo "--- 7: sin periodo, el cierre es el mismo con cualquier criterio ---\n";
// El criterio solo cambia DONDE cae cada movimiento, nunca cuanto se debe al final.
$iguales = true;
foreach (DB::table('tb_tes_orden_pago')->whereNotNull('id_prestador')->distinct()->limit(25)->pluck('id_prestador') as $b) {
    $cierres = [];
    foreach (['recepcion', 'comprobante', 'emision_opa'] as $crit) {
        $m = $cc->movimientosDosSaldos($b, 'PRESTADOR', null, null, $crit);
        $cierres[] = $m ? round(end($m)['saldo_financiero'], 2) . '|' . round(end($m)['saldo_economico'], 2) : '0|0';
    }
    if (count(array_unique($cierres)) !== 1) { $iguales = false; echo "  difiere prestador {$b}: " . implode(' / ', $cierres) . "\n"; }
}
$r[] = $iguales;
echo "  cierres iguales: " . var_export($iguales, true) . "\n";
echo $ok(end($r));

$c = count(array_filter($r));
echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
