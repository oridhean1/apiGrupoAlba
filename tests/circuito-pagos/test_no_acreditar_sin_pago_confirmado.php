<?php
// Un eCheq podia recorrer TODO su ciclo dentro de Carga de eCheq —emitir, numero, confirmar
// emision, acreditar— sin pasar nunca por Confirmar Pago. El problema: el asiento contable y el
// descuento del saldo de la cuenta se hacen SOLO en `TesPagosController::getConfirmarPago`. No hay
// una sola linea que los genere en el camino de acreditacion (verificado: 0 referencias).
//
// Resultado: la orden quedaba PAGADA, el eCheq ACREDITADO -o sea, plata que salio del banco- y la
// contabilidad sin enterarse, con el saldo de la cuenta intacto.
//
// Decision del usuario el 2026-09-10: no dejar acreditar si el pago no esta confirmado.
// El RECHAZO no lleva la guarda: un eCheq que el banco devolvio tiene que poder registrarse
// siempre.

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

    // Recibe la orden: el tope de emision es POR ORDEN, asi que el caso 4 necesita una distinta
    // de la que ya consumio todo lo pagable en los casos 1-3.
    $armar = function ($fecha, $ordenPago, $montoEmitir) use ($inst, $opaRepo) {
        $opa = $ordenPago;
        $pagable = $montoEmitir;
        // La cuenta se resuelve por la razon social DE ESA orden: desde el 2026-09-07 emitir
        // desde una cuenta de otra entidad del grupo se rechaza.
        $cta = DB::table('tb_tes_cuentas_bancarias')
            ->whereIn('id_razon', $opaRepo->razonesSocialesDeOpa($opa->id_orden_pago) ?: [1])
            ->first();
        $b = TesPagoEntity::create([
            'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
            'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
            'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        ]);
        $f = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'id_pago' => $b->id_pago, 'fecha_probable_pago' => $fecha,
            'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
        ]);
        $a = $inst->emitirPagoDeFecha($f, [
            'monto' => $pagable, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
            'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
        ]);
        $a->id_estado_instrumento = Inst::EMITIDO;
        $a->numero_echeq = 'ACR-' . $a->id_pago_parcial;
        $a->save();
        return [$b, $a];
    };

    echo "OPA {$opa->num_orden_pago} pagable " . number_format($pagable, 2) . "\n\n";

    echo "--- 1: acreditar con el pago SIN confirmar -> tiene que rechazar ---\n";
    [$b1, $a1] = $armar('2026-11-01', $opa, $pagable);
    echo "  boleta {$b1->id_pago} fecha_confirma_pago=" . var_export($b1->fecha_confirma_pago, true) . "\n";
    try {
        $inst->marcarAcreditado($a1->id_pago_parcial, '2026-11-05', $opaRepo);
        echo "  NO fallo: acredito sin pago confirmado\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'no se cargó en un pago');
    }
    echo $ok(end($r));

    echo "--- 2: el eCheq quedo intacto, sigue EMITIDO ---\n";
    $a1->refresh();
    echo "  estado_instrumento={$a1->id_estado_instrumento} (esperado 3) confirma="
        . var_export($a1->fecha_confirma_pago, true) . "\n";
    $r[] = ((int) $a1->id_estado_instrumento === Inst::EMITIDO && is_null($a1->fecha_confirma_pago));
    echo $ok(end($r));

    echo "--- 3: confirmando el pago primero, SI deja acreditar ---\n";
    $pagoRepo->findByConfirmarPago((object) [
        'id_pago' => $b1->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => $pagable,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => $a1->id_pago_parcial, 'fecha_confirma_pago' => null,
            'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => $pagable,
            'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
            'id_pago' => $b1->id_pago, 'monto_restante' => 0,
        ]],
    ]);
    $b1->refresh();
    echo "  boleta confirmada=" . var_export($b1->fecha_confirma_pago, true) . "\n";
    try {
        $inst->marcarAcreditado($a1->id_pago_parcial, '2026-11-05', $opaRepo);
        $a1->refresh();
        echo "  acreditado: estado_instrumento={$a1->id_estado_instrumento} (esperado 4)\n";
        $r[] = ((int) $a1->id_estado_instrumento === Inst::ACREDITADO);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 4: RECHAZAR sigue permitido sin pago confirmado ---\n";
    // Un eCheq que el banco devolvio tiene que poder registrarse siempre.
    $opa2 = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->where('id_orden_pago', '!=', $opa->id_orden_pago)
            ->orderByDesc('id_orden_pago')->limit(300)->get() as $c
    ) {
        $rz = $opaRepo->razonesSocialesDeOpa($c->id_orden_pago);
        if (empty($rz) || $opaRepo->montoPagableOpa($c->id_orden_pago) < 200) { continue; }
        if (DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $rz)->exists()) { $opa2 = $c; break; }
    }
    if (!$opa2) { echo "  (sin segunda OPA candidata, se saltea)\n"; $r[] = true; echo $ok(true); return; }
    $pagable2 = $opaRepo->montoPagableOpa($opa2->id_orden_pago);
    [$b2, $a2] = $armar('2026-12-01', $opa2, min(100, max(1, $pagable2)));
    try {
        $inst->marcarRechazado($a2->id_pago_parcial, 'lo devolvio el banco', $opaRepo);
        $a2->refresh();
        echo "  rechazado OK: estado_instrumento={$a2->id_estado_instrumento} (esperado 5)\n";
        $r[] = ((int) $a2->id_estado_instrumento === Inst::RECHAZADO);
    } catch (\Throwable $e) {
        echo "  NO deberia haber fallado: {$e->getMessage()}\n"; $r[] = false;
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
