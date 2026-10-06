<?php
// Dos cuotas del cronograma pueden caer el MISMO dia (pasa en la practica: OPA-4417, cuotas 2 y 3
// ambas el 2026-09-18). El desplegable de "Nuevo abono" identificaba la fecha por su VALOR
// (string), asi que las dos cuotas aparecian como opciones indistinguibles: elegir cualquiera
// resolvia al mismo string, y en el backend `pluck('id_fecha_probable', 'fecha_probable_pago')`
// colapsaba las dos filas a una sola (la ultima que recorriera), asi que no habia forma
// determinista de decir "quiero la cuota 3, no la 2".
//
// Ahora el front manda `id_fecha_probable` explicito (id unico, elegido de un desplegable ya
// filtrado a fechas SIN abono vivo) y el backend lo usa si pertenece a la boleta, en vez de
// resolver por fecha. El camino por fecha queda de respaldo para el circuito viejo.
// Reportado el 2026-09-11 sobre la OPA-4417: "no me tira las fechas correctas de pago el
// desplegable".

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

    // Cronograma: cuota 1 en una fecha, cuotas 2 y 3 EL MISMO DIA. Replica la OPA-4417.
    $mismoDia = '2026-12-18';
    $fA = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-12-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $fB = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $mismoDia,
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);
    $fC = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $mismoDia,
        'orden_cuotas' => 3, 'fecha_registra' => now()->toDateString(),
    ]);

    echo "OPA {$opa->num_orden_pago} boleta {$boleta->id_pago} pagable " . number_format($pagable, 2) . "\n";
    echo "  cronograma: fecha {$fA}=2026-12-01, fecha {$fB}={$mismoDia}, fecha {$fC}={$mismoDia}\n\n";

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
    ], $extra);

    $filaNueva = fn($idFecha, $fecha, $monto) => (object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => $fecha,
        'id_forma_pago' => 1, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => null, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
        'id_fecha_probable' => $idFecha,
    ];

    echo "--- 1: mandando id_fecha_probable explicito para la fecha B (cuota 2) ---\n";
    $repo->findByConfirmarPago($base(['lista_pagos' => [$filaNueva($fB, $mismoDia, $tercio)]]));
    $abonoB = TesPagosParciales::where('id_pago', $boleta->id_pago)->first();
    echo "  abono {$abonoB->id_pago_parcial} quedo con id_fecha_probable={$abonoB->id_fecha_probable} (esperado {$fB})\n";
    $r[] = ((int) $abonoB->id_fecha_probable === (int) $fB);
    echo $ok(end($r));

    echo "--- 2: ahora la fecha C (cuota 3, MISMO dia) sigue libre y se puede elegir especificamente ---\n";
    $repo->findByConfirmarPago($base(['lista_pagos' => [$filaNueva($fC, $mismoDia, $tercio)]]));
    $abonoC = TesPagosParciales::where('id_pago', $boleta->id_pago)
        ->where('id_pago_parcial', '!=', $abonoB->id_pago_parcial)->first();
    echo "  abono {$abonoC->id_pago_parcial} quedo con id_fecha_probable={$abonoC->id_fecha_probable} (esperado {$fC})\n";
    $r[] = ((int) $abonoC->id_fecha_probable === (int) $fC);
    echo $ok(end($r));

    echo "--- 3: intentar la fecha B DE NUEVO (ya ocupada) -> rechaza, no la confunde con la C libre ---\n";
    try {
        $repo->findByConfirmarPago($base(['lista_pagos' => [$filaNueva($fB, $mismoDia, 1)]]));
        echo "  NO fallo: dejo duplicar la fecha B\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        // El mensaje tiene que nombrar la fecha real (antes quedaba vacio por una
        // variable indefinida cuando la fecha se resolvia por id explicito).
        $r[] = str_contains($e->getMessage(), 'ya tiene un pago cargado')
            && str_contains($e->getMessage(), $mismoDia);
    }
    echo $ok(end($r));

    echo "--- 4: un id_fecha_probable que NO es de esta boleta se ignora (cae al camino por fecha) ---\n";
    // Fecha A (cuota 1, 2026-12-01) esta libre. Se manda un id ajeno (999999) junto con la fecha
    // real de A: el backend no puede confiar en el id, pero SI puede resolver por fecha.
    $repo->findByConfirmarPago($base(['lista_pagos' => [$filaNueva(999999, '2026-12-01', $tercio)]]));
    $abonoA = TesPagosParciales::where('id_pago', $boleta->id_pago)
        ->whereNotIn('id_pago_parcial', [$abonoB->id_pago_parcial, $abonoC->id_pago_parcial])->first();
    echo "  abono {$abonoA->id_pago_parcial} quedo con id_fecha_probable=" . var_export($abonoA->id_fecha_probable, true)
        . " (esperado {$fA}, resuelto por fecha al no ser valido el id explicito)\n";
    $r[] = ((int) $abonoA->id_fecha_probable === (int) $fA);
    echo $ok(end($r));

    echo "--- 5: la orden queda cubierta y en PAGADO ---\n";
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $estado = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    echo "  estado OPA={$estado} (esperado 5 = PAGADO)\n";
    $r[] = ($estado === 5);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
