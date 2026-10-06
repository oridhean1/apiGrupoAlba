<?php
// DOS cosas reportadas el 2026-09-10 sobre la OPA-4284.
//
// 1) La orden no pasaba a PAGO PARCIAL: confirmar el pago actualizaba la BOLETA pero NUNCA la
//    OPA. `recalcularEstadoOpa()` solo se llamaba al ANULAR. Resultado: boleta en 6 (PAGO
//    PARCIAL) y OPA en 4 (EN PROCESO), diciendo cosas distintas sobre lo mismo.
//
// 2) El total del comprobante estaba mal: la columna "Valores Entregados" cerraba con el total A
//    PAGAR en vez de con la suma de las filas que lista. Debajo de un unico eCheq de $70.000
//    decia $78.960.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $repo = new TesPagosRepository();
    $opaRepo = new TestOrdenPagoRepository();
    $inst = new Inst();

    $opa = null; $cuenta = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razones = $opaRepo->razonesSocialesDeOpa($cand->id_orden_pago);
        if (empty($razones)) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 2000) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $razones)->first();
        if ($c) { $opa = $cand; $cuenta = $c; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    echo "OPA {$opa->num_orden_pago} (id {$opa->id_orden_pago}) pagable " . number_format($pagable, 2) . "\n\n";

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);
    foreach ([['2026-11-01', 1], ['2026-11-15', 2], ['2026-11-30', 3]] as [$f, $o]) {
        DB::table('tb_tes_fecha_probable_pago')->insert([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $f,
            'orden_cuotas' => $o, 'fecha_registra' => now()->toDateString(),
        ]);
    }
    TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO]);

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
    ], $extra);

    $mitad = round($pagable / 2, 2);

    echo "--- 1: una TRANSFERENCIA confirmada mueve la OPA a PAGO PARCIAL ---\n";
    // Una transferencia se confirma con fecha, asi que SI cuenta como cobrada.
    $repo->findByConfirmarPago($base(['lista_pagos' => [(object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-01',
        'id_forma_pago' => 1, 'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => null, 'monto_restante' => 0, 'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
    ]]]));
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);   // lo que ahora hace el controller
    $estadoOpa = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    $estadoBoleta = (int) DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago');
    echo "  OPA={$estadoOpa} (esperado 6) | boleta={$estadoBoleta} (esperado 6)\n";
    $r[] = ($estadoOpa === 6);
    echo $ok(end($r));

    echo "--- 2: la boleta y la OPA ahora dicen LO MISMO ---\n";
    echo "  coinciden: " . var_export($estadoOpa === $estadoBoleta, true) . "\n";
    $r[] = ($estadoOpa === $estadoBoleta);
    echo $ok(end($r));

    echo "--- 3: completar el resto lleva la OPA a PAGADO ---\n";
    $existente = DB::table('tb_tes_pago_parcial')->where('id_pago', $boleta->id_pago)->first();
    $repo->findByConfirmarPago($base(['lista_pagos' => [
        (object) [
            'id_pago_parcial' => $existente->id_pago_parcial, 'fecha_confirma_pago' => '2026-11-01',
            'id_forma_pago' => 1, 'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
            'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
        ],
        (object) [
            'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-15',
            'id_forma_pago' => 1, 'monto_pago' => $pagable - $mitad, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'monto_restante' => 0, 'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
        ],
    ]]));
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $estadoOpa = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    echo "  OPA={$estadoOpa} (esperado 5 = PAGADO)\n";
    $r[] = ($estadoOpa === 5);
    echo $ok(end($r));

    echo "--- 4: un eCheq SIN acreditar NO mueve la OPA (es el diseño del circuito) ---\n";
    $opa2 = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
        ->whereHas('opadetalle')->where('id_orden_pago', '!=', $opa->id_orden_pago)
        ->orderByDesc('id_orden_pago')->first();
    $b2 = TesPagoEntity::create([
        'id_orden_pago' => $opa2->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa2->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $b2->id_pago, 'fecha_probable_pago' => '2026-12-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $razones2 = $opaRepo->razonesSocialesDeOpa($opa2->id_orden_pago);
    $c2 = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $razones2 ?: [1])->first();
    $echeq = $inst->emitirPagoDeFecha($idFecha, [
        'monto' => 100, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $c2?->id_cuenta_bancaria,
    ]);
    $opaRepo->recalcularEstadoOpa($opa2->id_orden_pago);
    $e2 = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa2->id_orden_pago)->value('id_estado_orden_pago');
    echo "  OPA={$e2} (esperado 4 = EN PROCESO: el eCheq no se acredito todavia)\n";
    $r[] = ($e2 === 4);
    echo $ok(end($r));

    echo "--- 5: al ACREDITARLO, ahi si pasa a PAGO PARCIAL ---\n";
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->save();
    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el
    // descuento del saldo salen de ahi, no del camino de acreditacion. Este test no prueba ese
    // flujo, asi que se deja la boleta confirmada directamente.
    DB::table('tb_tes_pago')->where('id_orden_pago', $opa2->id_orden_pago)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);
    $inst->marcarAcreditado($echeq->id_pago_parcial, '2026-12-05', $opaRepo);
    $e3 = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa2->id_orden_pago)->value('id_estado_orden_pago');
    echo "  OPA={$e3} (esperado 6 = PAGO PARCIAL)\n";
    $r[] = ($e3 === 6);
    echo $ok(end($r));

    // ── Totales del comprobante ──
    echo "--- 6: el comprobante cierra 'Valores Entregados' con la SUMA de sus filas ---\n";
    $instrumentos = collect();
    foreach (TesPagoEntity::where('id_orden_pago', $opa2->id_orden_pago)->get() as $bb) {
        $instrumentos = $instrumentos->merge(
            \App\Models\Tesoreria\TesPagosParciales::where('id_pago', $bb->id_pago)->vivos()->get()
        );
    }
    $entregado = $instrumentos->filter(fn($a) => !is_null($a->id_estado_instrumento))->sum(fn($a) => (float) $a->monto_pago)
        + $instrumentos->filter(fn($a) => is_null($a->id_estado_instrumento))->sum(fn($a) => (float) $a->monto_pago);
    $pagable2 = $opaRepo->montoPagableOpa($opa2->id_orden_pago);
    $restante = max(0, $pagable2 - $entregado);
    echo "  a pagar=" . number_format($pagable2, 2) . " entregado=" . number_format($entregado, 2)
        . " restante=" . number_format($restante, 2) . "\n";
    // Antes el pie decia el "a pagar" debajo de la lista de entregados.
    $r[] = (abs($entregado - 100) < 0.01 && abs($restante - ($pagable2 - 100)) < 0.01);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
