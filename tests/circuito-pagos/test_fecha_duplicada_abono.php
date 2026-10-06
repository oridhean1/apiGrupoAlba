<?php
// El modal de Confirmar Pago creaba los abonos SIN `id_fecha_probable`: quedaban sueltos del
// cronograma, no ocupaban ninguna fecha, y nada impedia cargar una transferencia en la misma
// fecha en la que ya habia un eCheq emitido. El circuito de eCheq si lo bloquea
// (emitirPagoDeFecha), pero este camino se lo saltaba entero.
// Reportado el 2026-09-08 sobre la OPA-4284: "cargue echeq y al agregar en pagos una
// transferencia me permitio usar la misma fecha, no deberia no?".

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
    $inst = new Inst();
    $opaRepo = new TestOrdenPagoRepository();
    $pagoRepo = new TesPagosRepository();

    $opa = null; $cuenta = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razon = $opaRepo->razonSocialDeOpa($cand->id_orden_pago);
        if (!$razon) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 5000) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->where('id_razon', $razon)->first();
        if ($c) { $opa = $cand; $cuenta = $c; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    // Cronograma de dos cuotas, como el de la OPA-4284.
    $fechaA = '2026-09-05';
    $fechaB = '2026-09-12';
    $idFechaA = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $fechaA,
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $idFechaB = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $fechaB,
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);
    // Fechas de sobra a proposito: este test es sobre la fecha duplicada, no sobre cobertura.
    // Con tantas fechas como abonos, la validacion de "los montos no cubren lo declarado" se
    // dispararia por los importes de juguete y taparia lo que se quiere probar.
    foreach ([['2026-10-01', 3], ['2026-10-15', 4], ['2026-10-30', 5], ['2026-11-15', 6]] as [$f, $o]) {
        DB::table('tb_tes_fecha_probable_pago')->insert([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $f,
            'orden_cuotas' => $o, 'fecha_registra' => now()->toDateString(),
        ]);
    }

    // Un eCheq emitido sobre la cuota 1. NO esta confirmado: fecha_confirma_pago queda NULL y su
    // fecha vive en fecha_emision_echeq. Ese es el detalle que hacia fallar la validacion vieja.
    $echeq = $inst->emitirPagoDeFecha($idFechaA, [
        'monto' => 1000, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
    ]);

    echo "OPA {$opa->num_orden_pago} boleta {$boleta->id_pago}\n";
    echo "  cronograma: {$fechaA} (fecha {$idFechaA}) y {$fechaB} (fecha {$idFechaB})\n";
    echo "  eCheq {$echeq->id_pago_parcial} sobre {$fechaA} | fecha_confirma_pago="
        . var_export($echeq->fecha_confirma_pago, true) . " (NULL: por eso no matcheaba)\n\n";

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_cuenta_bancaria' => '', 'anticipo' => '0',
        'monto_anticipado' => '0', 'num_cheque' => null, 'fecha_probable_pago' => null,
        'observaciones' => null, 'id_forma_cobro' => null, 'monto_cobro' => null,
        'fecha_confirma_cobro' => null, 'cuenta_bancaria' => null, 'imputacion_contable' => null,
        'banco' => null, 'archivos_eliminados' => null, 'monto_pago' => 500,
    ], $extra);

    $abonoNuevo = fn($fecha, $monto = 500) => (object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => $fecha,
        'id_forma_pago' => 1, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => null, 'monto_restante' => 0,
    ];

    echo "--- 1: transferencia en LA MISMA fecha que el eCheq -> tiene que rechazar ---\n";
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => [$abonoNuevo($fechaA)]]));
        echo "  NO fallo: dejo duplicar la fecha\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'ya tiene un pago cargado');
    }
    echo $ok(end($r));

    echo "--- 2: transferencia en la OTRA fecha del cronograma -> tiene que pasar ---\n";
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => [$abonoNuevo($fechaB)]]));
        $creado = TesPagosParciales::where('id_pago', $boleta->id_pago)
            ->where('id_forma_pago', 1)->first();
        echo "  abono {$creado->id_pago_parcial} creado, id_fecha_probable="
            . var_export($creado->id_fecha_probable, true) . " (esperado {$idFechaB})\n";
        $r[] = ((int) $creado->id_fecha_probable === (int) $idFechaB);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 3: ahora la segunda fecha tambien queda ocupada ---\n";
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => [$abonoNuevo($fechaB, 300)]]));
        echo "  NO fallo\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'ya tiene un pago cargado');
    }
    echo $ok(end($r));

    echo "--- 4: si el eCheq se ANULA, su fecha se libera y se puede usar ---\n";
    $inst->anularAbonoNoEmitido($echeq->id_pago_parcial, 'mal cargado', $opaRepo);
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => [$abonoNuevo($fechaA, 400)]]));
        $liberada = TesPagosParciales::where('id_pago', $boleta->id_pago)
            ->where('id_fecha_probable', $idFechaA)->vivos()->count();
        echo "  abonos vivos sobre {$fechaA}: {$liberada} (esperado 1, el nuevo)\n";
        $r[] = ($liberada === 1);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 5: una fecha FUERA del cronograma sigue permitida (circuito viejo) ---\n";
    // 292 de 302 abonos de Alba estan asi: sin fecha del plan. No se puede prohibir.
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => [$abonoNuevo('2026-12-25', 100)]]));
        $suelto = TesPagosParciales::where('id_pago', $boleta->id_pago)
            ->whereNull('id_fecha_probable')->count();
        echo "  abonos sin fecha del plan: {$suelto} (esperado 1)\n";
        $r[] = ($suelto === 1);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 6: reconfirmar los abonos YA existentes no rebota por su propia fecha ---\n";
    $existentes = TesPagosParciales::where('id_pago', $boleta->id_pago)->vivos()->get()
        ->map(fn($a) => (object) [
            'id_pago_parcial' => $a->id_pago_parcial,
            'fecha_confirma_pago' => $a->fecha_confirma_pago ?: $a->fecha_emision_echeq,
            'id_forma_pago' => $a->id_forma_pago, 'monto_pago' => $a->monto_pago,
            'monto_opa' => $a->monto_opa, 'num_cheque' => null,
            'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        ])->values()->all();
    try {
        $pagoRepo->findByConfirmarPago($base(['lista_pagos' => $existentes]));
        echo "  reconfirmo " . count($existentes) . " abonos existentes sin rebotar\n";
        $r[] = true;
    } catch (\Throwable $e) {
        echo "  fallo: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
