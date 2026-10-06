<?php
// Acreditar un eCheq movia el estado de la ORDEN pero NO el de su BOLETA. Como la grilla de
// Pagos muestra `tb_tes_pago.id_estado_orden_pago` directo como badge, la orden quedaba PAGADA y
// la pantalla de Pagos seguia diciendo PAGO PARCIAL hasta que alguien volvia a confirmar el pago
// a mano. Es el espejo del bug del 2026-09-10 (confirmar movia la boleta y no la orden).
//
// Secuencia reportada: transferencia -> PARCIAL -> eCheq por el resto -> acreditar -> seguia
// diciendo PARCIAL.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
DB::beginTransaction();
try {
    $inst = new Inst();
    $opaRepo = new TestOrdenPagoRepository();
    $pagoRepo = new TesPagosRepository();

    $opa = null; $cta = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $c
    ) {
        $rz = $opaRepo->razonesSocialesDeOpa($c->id_orden_pago);
        if (empty($rz) || $opaRepo->montoPagableOpa($c->id_orden_pago) < 2000) { continue; }
        $cc = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $rz)->first();
        if ($cc) { $opa = $c; $cta = $cc; break; }
    }
    if (!$opa) { echo "SIN OPA\n"; DB::rollBack(); return; }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    $mitad = round($pagable / 2, 2);
    $resto = $pagable - $mitad;

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    $f1 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-09-04',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $f2 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-09-11',
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);
    TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO]);

    $r = [];
    $ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

    $estados = function () use ($opa, $boleta) {
        return [
            (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago'),
            (int) DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago'),
        ];
    };

    $ver = function ($paso) use ($opa, $boleta, $opaRepo) {
        $eo = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
        $eb = DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago');
        printf("  %-46s OPA=%s boleta=%s  cubierto=%s / pagable=%s\n", $paso, $eo, $eb,
            number_format($opaRepo->montoCubiertoOpa($opa->id_orden_pago), 2),
            number_format($opaRepo->montoPagableOpa($opa->id_orden_pago), 2));
    };

    echo "OPA {$opa->num_orden_pago} pagable " . number_format($pagable, 2)
        . " (transferencia " . number_format($mitad, 2) . " + eCheq " . number_format($resto, 2) . ")\n\n";
    $ver('inicio');

    // 1) Transferencia por la mitad, confirmada desde Pagos.
    $base = [
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => $mitad,
    ];
    $pagoRepo->findByConfirmarPago((object) array_merge($base, ['lista_pagos' => [(object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-09-04', 'id_forma_pago' => 1,
        'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
        'monto_restante' => $resto, 'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
    ]]]));
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $ver('1) transferencia confirmada');

    // 2) eCheq por el resto, desde Carga de eCheq.
    $echeq = $inst->emitirPagoDeFecha($f2, [
        'monto' => $resto, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
    ]);
    $ver('2) eCheq emitido (sin numero)');

    $inst->guardarBorradorNumero($echeq->id_pago_parcial, 'REPLAY-' . $echeq->id_pago_parcial);
    $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
    $ver('3) emision confirmada (EMITIDO)');
    $trasEmitir = $estados();

    // Desde 2026_09_10_100000 el eCheq tiene que pasar por Confirmar Pago antes de acreditarse:
    // ahi se valida la cobertura, se genera el asiento y se descuenta el saldo. Se manda la lista
    // completa, que es lo que hace el modal.
    $vivosAntes = $pagoRepo->findByPagosParcialesVivos($boleta->id_pago);
    $pagoRepo->findByConfirmarPago((object) array_merge($base, [
        'lista_pagos' => $vivosAntes->map(fn($a) => (object) [
            'id_pago_parcial' => $a->id_pago_parcial,
            'fecha_confirma_pago' => $a->fecha_confirma_pago,
            'id_forma_pago' => $a->id_forma_pago, 'monto_pago' => $a->monto_pago,
            'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
            'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
            'id_cuenta_bancaria' => $a->id_cuenta_bancaria,
        ])->values()->all(),
    ]));
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $ver('3.bis) eCheq cargado en el pago');

    $inst->marcarAcreditado($echeq->id_pago_parcial, '2026-09-11', $opaRepo);
    $ver('4) eCheq ACREDITADO');
    $trasAcreditar = $estados();

    // 5) Confirmar pago desde Pagos, mandando los dos abonos.
    $vivos = $pagoRepo->findByPagosParcialesVivos($boleta->id_pago);
    $lista = $vivos->map(fn($a) => (object) [
        'id_pago_parcial' => $a->id_pago_parcial,
        'fecha_confirma_pago' => $a->fecha_confirma_pago ?: $a->fecha_emision_echeq,
        'id_forma_pago' => $a->id_forma_pago, 'monto_pago' => $a->monto_pago,
        'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $a->id_cuenta_bancaria,
    ])->values()->all();
    $pagoRepo->findByConfirmarPago((object) array_merge($base, ['lista_pagos' => $lista]));
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $ver('5) confirmar pago desde Pagos');
    $trasConfirmar = $estados();

    echo "\n  (3 = RECHAZADO, 4 = EN PROCESO, 5 = PAGADO, 6 = PAGO PARCIAL)\n\n";

    echo "--- 1: al ACREDITAR, la boleta pasa a PAGADO sin volver a confirmar ---\n";
    echo "  boleta despues de acreditar: {$trasAcreditar[1]} (esperado 5)\n";
    $r[] = ($trasAcreditar[1] === 5);
    echo $ok(end($r));

    echo "--- 2: la ORDEN tambien, y las dos dicen LO MISMO ---\n";
    echo "  OPA={$trasAcreditar[0]} boleta={$trasAcreditar[1]}\n";
    $r[] = ($trasAcreditar[0] === 5 && $trasAcreditar[0] === $trasAcreditar[1]);
    echo $ok(end($r));

    echo "--- 3: un eCheq EMITIDO y sin acreditar NO adelanta el estado ---\n";
    echo "  tras confirmar la emision: OPA={$trasEmitir[0]} boleta={$trasEmitir[1]} (esperado 6 y 6)\n";
    $r[] = ($trasEmitir[0] === 6 && $trasEmitir[1] === 6);
    echo $ok(end($r));

    echo "--- 4: volver a confirmar el pago no cambia nada (ya estaba al dia) ---\n";
    echo "  OPA={$trasConfirmar[0]} boleta={$trasConfirmar[1]}\n";
    $r[] = ($trasConfirmar[0] === 5 && $trasConfirmar[1] === 5);
    echo $ok(end($r));

    echo "\n--- dar de baja TODOS los abonos devuelve la boleta a EN PROCESO ---\n";
    // El estado de la boleta solo podia SUBIR: `recalcularEstadoBoletas()` tenia dos `continue`
    // —uno cuando no quedaban abonos vivos, otro cuando lo cobrado era 0— que la dejaban como
    // estaba. Una boleta que habia llegado a PAGO PARCIAL se quedaba ahi para siempre aunque
    // despues se dieran de baja todos sus pagos.
    // Reportado sobre la OPA-1408 (id 4479): se rechazaron los dos eCheq, la orden volvio a
    // EN PROCESO pero la boleta seguia mostrando PAGO PARCIAL en la grilla de Pagos. (2026-09-15)
    foreach (TesPagosParciales::where('id_pago', $boleta->id_pago)->get() as $a) {
        $a->id_estado_instrumento = Inst::RECHAZADO;
        $a->fecha_confirma_pago = null;
        $a->save();
    }
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);

    $estOpa = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    $estBol = (int) DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago');
    echo "  OPA={$estOpa} boleta={$estBol} (esperado 4 y 4 = EN PROCESO; 6 en la boleta era el bug)\n";
    $r[] = ($estOpa === 4 && $estBol === 4);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r ?? []));
    echo "\n=== {$okc}/" . count($r ?? []) . " OK " . ($okc === count($r ?? []) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}