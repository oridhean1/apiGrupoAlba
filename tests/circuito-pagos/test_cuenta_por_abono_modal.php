<?php
// El modal de Confirmar Pago pedia UNA cuenta de origen para toda la boleta, y su create() ni
// siquiera la guardaba en el abono: los pagos que nacian por ahi quedaban con id_cuenta_bancaria
// en NULL y el retiro de fondos caia de rebote a la cuenta de la boleta. Resultado: dos
// transferencias de bancos distintos se debitaban las dos de la misma cuenta.
// El modelo lo soporta desde 2026_09_06_100000; este camino no lo usaba.
// Reportado el 2026-09-09: "el selector de banco deberia ir en cada pago, esta bien lo que digo?"

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

    // Una OPA con dos cuentas de BANCOS distintos en su razon social.
    $opa = null; $cuentas = collect();
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razones = $opaRepo->razonesSocialesDeOpa($cand->id_orden_pago);
        if (empty($razones)) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 1000) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')
            ->whereIn('id_razon', $razones)->get()->unique('id_entidad_bancaria')->take(2)->values();
        if ($c->count() >= 2) { $opa = $cand; $cuentas = $c; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    echo "OPA {$opa->num_orden_pago} boleta {$boleta->id_pago}\n";
    echo "  cuenta A: {$cuentas[0]->nombre_cuenta} (banco {$cuentas[0]->id_entidad_bancaria})\n";
    echo "  cuenta B: {$cuentas[1]->nombre_cuenta} (banco {$cuentas[1]->id_entidad_bancaria})\n\n";

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_cuenta_bancaria' => '', 'anticipo' => '0',
        'monto_anticipado' => '0', 'num_cheque' => null, 'fecha_probable_pago' => null,
        'observaciones' => null, 'id_forma_cobro' => null, 'monto_cobro' => null,
        'fecha_confirma_cobro' => null, 'cuenta_bancaria' => null, 'imputacion_contable' => null,
        'banco' => null, 'archivos_eliminados' => null, 'monto_pago' => 300,
    ], $extra);

    $fila = fn($cuenta, $fecha, $monto) => (object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => $fecha,
        'id_forma_pago' => 1, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => null, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $cuenta,
    ];

    echo "--- 1: dos abonos con cuentas DISTINTAS guardan cada uno la suya ---\n";
    $repo->findByConfirmarPago($base(['lista_pagos' => [
        $fila($cuentas[0]->id_cuenta_bancaria, '2026-10-01', 100),
        $fila($cuentas[1]->id_cuenta_bancaria, '2026-10-02', 200),
    ]]));
    $abonos = TesPagosParciales::where('id_pago', $boleta->id_pago)->orderBy('id_pago_parcial')->get();
    foreach ($abonos as $a) {
        echo "  abono {$a->id_pago_parcial} monto={$a->monto_pago} cuenta=" . var_export($a->id_cuenta_bancaria, true)
            . " banco=" . var_export($a->id_banco_emisor, true) . "\n";
    }
    $r[] = ($abonos->count() === 2
        && (int) $abonos[0]->id_cuenta_bancaria === (int) $cuentas[0]->id_cuenta_bancaria
        && (int) $abonos[1]->id_cuenta_bancaria === (int) $cuentas[1]->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 2: el BANCO se deriva de la cuenta, no se pregunta ---\n";
    $r[] = ((int) $abonos[0]->id_banco_emisor === (int) $cuentas[0]->id_entidad_bancaria
        && (int) $abonos[1]->id_banco_emisor === (int) $cuentas[1]->id_entidad_bancaria);
    echo "  bancos: {$abonos[0]->id_banco_emisor} y {$abonos[1]->id_banco_emisor} (esperado "
        . "{$cuentas[0]->id_entidad_bancaria} y {$cuentas[1]->id_entidad_bancaria})\n";
    echo $ok(end($r));

    echo "--- 3: son de bancos DISTINTOS (el caso que antes no se podia representar) ---\n";
    $bancos = $abonos->pluck('id_banco_emisor')->unique();
    echo "  bancos distintos: {$bancos->count()} (esperado 2)\n";
    $r[] = ($bancos->count() === 2);
    echo $ok(end($r));

    echo "--- 3b: la transferencia queda pagada HOY, no en la fecha de la cuota ---\n";
    // El modal manda la fecha de la cuota (2026-10-01 / 02); esa sirve para saber a qué cuota
    // corresponde, pero la fecha de pago es la de la confirmación. (2026-10-06)
    $hoy = \Carbon\Carbon::now('America/Argentina/Buenos_Aires')->toDateString();
    $fechas = $abonos->map(fn($a) => substr((string) $a->fecha_confirma_pago, 0, 10))->all();
    echo "  fechas de pago: " . implode(', ', $fechas) . " (esperado {$hoy})\n";
    $r[] = (count($fechas) === 2 && count(array_filter($fechas, fn($f) => $f === $hoy)) === 2);
    echo $ok(end($r));

    echo "--- 4: la cuenta de la boleta NO se pisa con null cuando el modal no la manda ---\n";
    $boleta->refresh();
    echo "  cuenta de la boleta: " . var_export($boleta->id_cuenta_bancaria, true)
        . " (toma la del primer abono, para el rebote de los abonos viejos)\n";
    $r[] = !is_null($boleta->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 5: una boleta que YA tenia cuenta no la pierde ---\n";
    $boleta2 = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
        'id_cuenta_bancaria' => $cuentas[1]->id_cuenta_bancaria,
    ]);
    $repo->findByConfirmarPago((object) array_merge((array) $base([]), [
        'id_pago' => $boleta2->id_pago,
        'lista_pagos' => [$fila($cuentas[0]->id_cuenta_bancaria, '2026-10-05', 100)],
    ]));
    $boleta2->refresh();
    echo "  cuenta de la boleta: " . var_export($boleta2->id_cuenta_bancaria, true)
        . " (esperado {$cuentas[1]->id_cuenta_bancaria}, la que ya tenia)\n";
    $r[] = ((int) $boleta2->id_cuenta_bancaria === (int) $cuentas[1]->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 6: editar un abono existente cambia su cuenta y su banco ---\n";
    $unAbono = TesPagosParciales::where('id_pago', $boleta2->id_pago)->first();
    $repo->findByConfirmarPago((object) array_merge((array) $base([]), [
        'id_pago' => $boleta2->id_pago,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => $unAbono->id_pago_parcial,
            'fecha_confirma_pago' => '2026-10-05', 'id_forma_pago' => 1,
            'monto_pago' => 100, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'id_pago' => $boleta2->id_pago, 'monto_restante' => 0,
            'id_cuenta_bancaria' => $cuentas[1]->id_cuenta_bancaria,
        ]],
    ]));
    $unAbono->refresh();
    echo "  cuenta={$unAbono->id_cuenta_bancaria} banco={$unAbono->id_banco_emisor}"
        . " (esperado {$cuentas[1]->id_cuenta_bancaria} / {$cuentas[1]->id_entidad_bancaria})\n";
    $r[] = ((int) $unAbono->id_cuenta_bancaria === (int) $cuentas[1]->id_cuenta_bancaria
        && (int) $unAbono->id_banco_emisor === (int) $cuentas[1]->id_entidad_bancaria);
    echo $ok(end($r));

    echo "--- 7: sin cuenta en la fila, cae a la del request (compatibilidad) ---\n";
    $boleta3 = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);
    $repo->findByConfirmarPago((object) array_merge((array) $base([]), [
        'id_pago' => $boleta3->id_pago,
        'id_cuenta_bancaria' => $cuentas[0]->id_cuenta_bancaria,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-10-09',
            'id_forma_pago' => 1, 'monto_pago' => 100, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'monto_restante' => 0,
        ]],
    ]));
    $viejo = TesPagosParciales::where('id_pago', $boleta3->id_pago)->first();
    echo "  cuenta del abono: " . var_export($viejo->id_cuenta_bancaria, true)
        . " (esperado {$cuentas[0]->id_cuenta_bancaria}, la del request)\n";
    $r[] = ((int) $viejo->id_cuenta_bancaria === (int) $cuentas[0]->id_cuenta_bancaria);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
