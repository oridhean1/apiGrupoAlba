<?php
// Etapa 1: el eCheq/cheque se emite desde el modal de Confirmar Pago, no desde una pestania aparte.
// La pantalla de emision pedia exactamente los mismos tres datos que el modal ya pedia (monto,
// forma de pago y cuenta de origen), asi que eran dos pantallas para un solo acto.
//
// Lo que se verifica:
//   - un abono con forma cheque(2)/eCheq(7) nace como INSTRUMENTO, con su estado
//   - y NO nace cobrado: `fecha_confirma_pago` queda NULL, porque el banco todavia no debito.
//     Es lo que evita que la orden figure PAGADA con plata que no salio.
//   - una transferencia sigue naciendo cobrada, como siempre
//   - el tope de sobrepago, que hasta ahora solo aplicaba `emitirPagoDeFecha`, ahora tambien
//     frena al modal

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$plata = fn($n) => number_format((float) $n, 2, ',', '.');

DB::beginTransaction();
$r = [];
try {
    $repo = new TesPagosRepository();
    $opaRepo = new TestOrdenPagoRepository();

    $opa = null; $cta = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $c
    ) {
        $rz = $opaRepo->razonesSocialesDeOpa($c->id_orden_pago);
        if (empty($rz) || $opaRepo->montoPagableOpa($c->id_orden_pago) < 5000) { continue; }
        $cc = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $rz)->first();
        if ($cc) { $opa = $c; $cta = $cc; break; }
    }
    if (!$opa) { echo "SIN OPA\n"; DB::rollBack(); return; }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    $tercio = round($pagable / 3, 2);

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    $fechas = [];
    foreach (['2026-11-01', '2026-11-15', '2026-11-30'] as $i => $f) {
        $fechas[] = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $f,
            'orden_cuotas' => $i + 1, 'fecha_registra' => now()->toDateString(),
        ]);
    }

    echo "OPA {$opa->num_orden_pago} pagable {$plata($pagable)} | cuenta {$cta->nombre_cuenta}\n\n";

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
    ], $extra);

    $fila = fn($idFecha, $fecha, $monto, $forma, $numCheque = null) => (object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => $fecha,
        'id_forma_pago' => $forma, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => $numCheque, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $cta->id_cuenta_bancaria, 'id_fecha_probable' => $idFecha,
    ];

    echo "--- 1: eCheq cargado desde el modal nace como INSTRUMENTO, ya EMITIDO ---\n";
    // Confirmar el pago EMITE los instrumentos de la orden (2026-09-15). Antes quedaban en
    // PENDIENTE_EMISION esperando la pantalla *Sin numero*, que exigia el numero del banco para
    // dejar emitir; esa pantalla se elimino y el numero ya no frena nada.
    $repo->findByConfirmarPago($base(['lista_pagos' => [$fila($fechas[0], '2026-11-01', $tercio, Inst::FORMA_PAGO_ECHEQ)]]));
    $echeq = TesPagosParciales::where('id_pago', $boleta->id_pago)->orderByDesc('id_pago_parcial')->first();
    echo "  estado_instrumento=" . var_export($echeq->id_estado_instrumento, true)
        . " (esperado " . Inst::EMITIDO . " EMITIDO)\n";
    echo "  fecha_emision_echeq=" . var_export($echeq->fecha_emision_echeq, true) . "\n";
    $r[] = ((int) $echeq->id_estado_instrumento === Inst::EMITIDO);
    echo $ok(end($r));

    echo "--- 1.bis: y le pusieron un numero PROVISORIO, para no frenar la carga ---\n";
    // `numero_echeq` tiene UNIQUE global: el provisorio se arma con el id del abono, asi que es
    // unico por construccion y no puede chocar contra el numero real que asigne el banco.
    echo "  numero_echeq=" . var_export($echeq->numero_echeq, true)
        . " provisorio=" . var_export($echeq->numero_provisorio, true) . "\n";
    $r[] = ($echeq->numero_provisorio === true
        && $echeq->numero_echeq === Inst::PREFIJO_NUMERO_PROVISORIO . $echeq->id_pago_parcial);
    echo $ok(end($r));

    echo "--- 2: y NO nace cobrado: la plata todavia no salio del banco ---\n";
    echo "  fecha_confirma_pago=" . var_export($echeq->fecha_confirma_pago, true) . " (esperado NULL)\n";
    echo "  cobrado de la OPA=" . $plata($opaRepo->montoPagadoOpa($opa->id_orden_pago)) . " (esperado 0,00)\n";
    $r[] = (is_null($echeq->fecha_confirma_pago) && $opaRepo->montoPagadoOpa($opa->id_orden_pago) < 0.01);
    echo $ok(end($r));

    echo "--- 3: pero SI quedo cargado en el pago, asi que se va a poder acreditar ---\n";
    echo "  fecha_confirmado_en_pago=" . var_export($echeq->fecha_confirmado_en_pago, true) . "\n";
    $r[] = !is_null($echeq->fecha_confirmado_en_pago);
    echo $ok(end($r));

    echo "--- 4: la OPA no pasa a PAGADO por un eCheq sin acreditar ---\n";
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $est = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    echo "  estado OPA={$est} (5=PAGADO seria el bug)\n";
    $r[] = ($est !== 5);
    echo $ok(end($r));

    echo "--- 5: un CHEQUE nace EMITIDO (el numero lo escribe quien lo emite) ---\n";
    $repo->findByConfirmarPago($base(['lista_pagos' => [$fila($fechas[1], '2026-11-15', $tercio, Inst::FORMA_PAGO_CHEQUE, 'CHQ-12345')]]));
    $cheque = TesPagosParciales::where('id_pago', $boleta->id_pago)->orderByDesc('id_pago_parcial')->first();
    echo "  estado_instrumento=" . var_export($cheque->id_estado_instrumento, true) . " (esperado " . Inst::EMITIDO . " EMITIDO)"
        . " num_cheque=" . var_export($cheque->num_cheque, true) . "\n";
    $r[] = ((int) $cheque->id_estado_instrumento === Inst::EMITIDO && is_null($cheque->fecha_confirma_pago));
    echo $ok(end($r));

    echo "--- 6: una TRANSFERENCIA sigue naciendo cobrada, sin estado de instrumento ---\n";
    $repo->findByConfirmarPago($base(['lista_pagos' => [$fila($fechas[2], '2026-11-30', $tercio, 1)]]));
    $transf = TesPagosParciales::where('id_pago', $boleta->id_pago)->orderByDesc('id_pago_parcial')->first();
    echo "  estado_instrumento=" . var_export($transf->id_estado_instrumento, true) . " (esperado NULL)"
        . " fecha_confirma_pago=" . var_export($transf->fecha_confirma_pago, true) . "\n";
    $r[] = (is_null($transf->id_estado_instrumento) && !is_null($transf->fecha_confirma_pago));
    echo $ok(end($r));

    echo "--- 7: solo la transferencia cuenta como cobrado ---\n";
    $cobrado = $opaRepo->montoPagadoOpa($opa->id_orden_pago);
    echo "  cobrado={$plata($cobrado)} (esperado {$plata($tercio)}, el tercio de la transferencia)\n";
    $r[] = (abs($cobrado - $tercio) < 0.02);
    echo $ok(end($r));

    echo "--- 8: el TOPE de sobrepago ahora tambien frena al modal ---\n";
    // Hasta el 2026-09-12 esta validacion solo vivia en emitirPagoDeFecha y este camino la
    // esquivaba: se podian cargar abonos por encima de lo pagable.
    $boleta2 = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    try {
        $repo->findByConfirmarPago((object) array_merge((array) $base([]), [
            'id_pago' => $boleta2->id_pago,
            'lista_pagos' => [(object) [
                'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-12-01',
                'id_forma_pago' => 1, 'monto_pago' => $pagable, 'monto_opa' => $opa->monto_orden_pago,
                'num_cheque' => null, 'monto_restante' => 0,
                'id_cuenta_bancaria' => $cta->id_cuenta_bancaria, 'id_fecha_probable' => null,
            ]],
        ]));
        echo "  NO fallo: dejo pasarse del tope\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'se pasa de lo que hay que pagar');
    }
    echo $ok(end($r));

    echo "--- 9: acreditar el eCheq SI lo marca cobrado ---\n";
    $inst = new Inst();
    $echeq->refresh();
    // Cargar el numero real va por `guardarBorradorNumero`, que es el camino que ademas limpia la
    // marca de provisorio. Setear la columna a mano dejaba `numero_provisorio` en true y la guarda
    // de acreditacion lo frenaba, con razon.
    $inst->guardarBorradorNumero($echeq->id_pago_parcial, 'TEST-' . $echeq->id_pago_parcial);
    $echeq->refresh();
    $inst->marcarAcreditado($echeq->id_pago_parcial, '2026-12-05', $opaRepo);
    $echeq->refresh();
    echo "  estado_instrumento={$echeq->id_estado_instrumento} fecha_confirma_pago=" . var_export($echeq->fecha_confirma_pago, true) . "\n";
    echo "  cobrado de la OPA=" . $plata($opaRepo->montoPagadoOpa($opa->id_orden_pago)) . "\n";
    $r[] = ((int) $echeq->id_estado_instrumento === Inst::ACREDITADO && !is_null($echeq->fecha_confirma_pago));
    echo $ok(end($r));

    echo "--- 10: NO se puede acreditar un eCheq con numero PROVISORIO ---\n";
    // Acreditar afirma que el banco debito el documento; no pudo debitar algo cuyo numero real
    // nunca existio. Ademas la conciliacion bancaria no podria matchearlo contra el extracto.
    // Hasta el 2026-09-15 esto lo impedia de rebote la pantalla *Sin numero* (no dejaba emitir sin
    // numero, y sin emitir no se podia acreditar); al mover el numero al pago se perdio.
    // Reportado sobre un eCheq acreditado con PROV-3386.
    $provisorio = TesPagosParciales::where('id_pago', $boleta->id_pago)
        ->where('id_forma_pago', Inst::FORMA_PAGO_CHEQUE)->first();
    $provisorio->id_forma_pago = Inst::FORMA_PAGO_ECHEQ;
    $provisorio->id_estado_instrumento = Inst::EMITIDO;
    $provisorio->numero_echeq = Inst::PREFIJO_NUMERO_PROVISORIO . $provisorio->id_pago_parcial;
    $provisorio->numero_provisorio = true;
    $provisorio->save();

    try {
        $inst->marcarAcreditado($provisorio->id_pago_parcial, '2026-12-05', $opaRepo);
        echo "  NO fallo: acredito con numero provisorio\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'numero provisorio')
            || str_contains($e->getMessage(), 'número provisorio');
    }
    echo $ok(end($r));

    echo "--- 11: cargandole el numero real, ahi si se acredita ---\n";
    $inst->guardarBorradorNumero($provisorio->id_pago_parcial, 'BANCO-77777');
    $provisorio->refresh();
    echo "  numero=" . var_export($provisorio->numero_echeq, true)
        . " provisorio=" . var_export($provisorio->numero_provisorio, true) . "\n";
    try {
        $inst->marcarAcreditado($provisorio->id_pago_parcial, '2026-12-05', $opaRepo);
        $provisorio->refresh();
        echo "  estado_instrumento={$provisorio->id_estado_instrumento} (esperado " . Inst::ACREDITADO . ")\n";
        $r[] = ((int) $provisorio->id_estado_instrumento === Inst::ACREDITADO);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
