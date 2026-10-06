<?php
// La guarda de "no acreditar sin pago confirmado" (2026-09-10) miraba la BOLETA, y eso dejaba un
// agujero: una vez confirmada la boleta -por ejemplo con una transferencia-, cualquier eCheq
// emitido DESPUES heredaba el permiso y se podia acreditar sin pasar por Confirmar Pago,
// salteandose la validacion de que los montos cubran la orden.
//
// Reportado sobre la OPA-1120: se anulo un eCheq de $1.000.000 y se emitio uno de $500 que no
// cubria nada, y el sistema lo iba a dejar acreditar.
//
// Es el mismo error que ya nos paso con la razon social: validar la boleta en vez de cada abono.
// Ahora se mira `tb_tes_pago_parcial.fecha_confirmado_en_pago` (ver 2026_09_10_100000).

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
        if (empty($rz) || $opaRepo->montoPagableOpa($c->id_orden_pago) < 5000) { continue; }
        $cc = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $rz)->first();
        if ($cc) { $opa = $c; $cta = $cc; break; }
    }
    if (!$opa) { echo "SIN OPA\n"; DB::rollBack(); return; }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    echo "OPA {$opa->num_orden_pago} pagable " . number_format($pagable, 2) . "\n";
    echo "Se replica el caso de la OPA-1120: transferencia confirmada + eCheq chico emitido despues.\n\n";

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    $f1 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $f2 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-15',
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);

    $base = [
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
    ];

    // 1) Transferencia por una parte, confirmada. La BOLETA queda confirmada.
    $pagoRepo->findByConfirmarPago((object) array_merge($base, ['lista_pagos' => [(object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-01', 'id_forma_pago' => 1,
        'monto_pago' => $pagable - 500, 'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
        'monto_restante' => 0, 'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
    ]]]));
    $boleta->refresh();
    echo "1) transferencia de \$" . number_format($pagable - 500, 2) . " confirmada -> boleta confirmada="
        . var_export($boleta->fecha_confirma_pago, true) . "\n";

    // 2) eCheq chico emitido DESPUES, sin pasar por Confirmar Pago.
    $echeq = $inst->emitirPagoDeFecha($f2, [
        'monto' => 500, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
    ]);
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->numero_echeq = 'POST-' . $echeq->id_pago_parcial;
    $echeq->save();
    echo "2) eCheq de \$500 emitido despues, sin pasar por Confirmar Pago\n\n";

    echo "--- 1: el eCheq nuevo NO quedo marcado como cargado en un pago ---\n";
    $echeq->refresh();
    echo "  fecha_confirmado_en_pago=" . var_export($echeq->fecha_confirmado_en_pago, true) . " (esperado NULL)\n";
    $r[] = is_null($echeq->fecha_confirmado_en_pago);
    echo $ok(end($r));

    echo "--- 2: NO se puede acreditar, aunque la BOLETA este confirmada ---\n";
    try {
        $inst->marcarAcreditado($echeq->id_pago_parcial, '2026-11-20', $opaRepo);
        echo "  NO fallo: acredito heredando el permiso de la boleta\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'no se cargó en un pago');
    }
    echo $ok(end($r));

    echo "--- 3: la transferencia (que SI paso por el pago) quedo marcada ---\n";
    $transf = DB::table('tb_tes_pago_parcial')->where('id_pago', $boleta->id_pago)
        ->where('id_forma_pago', 1)->first();
    echo "  fecha_confirmado_en_pago=" . var_export($transf->fecha_confirmado_en_pago, true) . "\n";
    $r[] = !is_null($transf->fecha_confirmado_en_pago);
    echo $ok(end($r));

    echo "--- 4: el listado de Emitidos lo reporta como NO confirmado ---\n";
    $fila = collect($inst->listarEmitidos())->firstWhere('id_pago_parcial', $echeq->id_pago_parcial);
    echo "  pago_confirmado=" . var_export($fila?->pago_confirmado, true) . " (esperado 0)\n";
    $r[] = ($fila && (int) $fila->pago_confirmado === 0);
    echo $ok(end($r));

    echo "--- 5: pasandolo por Confirmar Pago, ahi si se puede acreditar ---\n";
    $vivos = $pagoRepo->findByPagosParcialesVivos($boleta->id_pago);
    $lista = $vivos->map(fn($a) => (object) [
        'id_pago_parcial' => $a->id_pago_parcial,
        'fecha_confirma_pago' => $a->fecha_confirma_pago,
        'id_forma_pago' => $a->id_forma_pago, 'monto_pago' => $a->monto_pago,
        'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $a->id_cuenta_bancaria,
    ])->values()->all();
    $pagoRepo->findByConfirmarPago((object) array_merge($base, ['lista_pagos' => $lista]));
    $echeq->refresh();
    echo "  fecha_confirmado_en_pago=" . var_export($echeq->fecha_confirmado_en_pago, true) . "\n";
    try {
        $inst->marcarAcreditado($echeq->id_pago_parcial, '2026-11-20', $opaRepo);
        $echeq->refresh();
        echo "  acreditado: estado_instrumento={$echeq->id_estado_instrumento} (esperado 4)\n";
        $r[] = ((int) $echeq->id_estado_instrumento === Inst::ACREDITADO);
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
