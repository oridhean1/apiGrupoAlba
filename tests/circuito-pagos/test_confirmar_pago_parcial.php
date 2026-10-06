<?php
// findByConfirmarPago() hardcodeaba `id_estado_orden_pago = 5` (PAGADO) sin importar cuanto se
// hubiera pagado realmente. $estado se calculaba (6 = PARCIAL, 5 = PAGADO) pero nunca se usaba.
// Por eso el modal de Confirmar Pago exigia cargar EXACTAMENTE un abono por cada fecha
// planificada antes de dejar confirmar: era la unica forma de que ese 5 hardcodeado no mintiera.
// Reportado el 2026-09-07: "no me deja hacer pagos parciales, deberia??" -> si, deberia.

use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
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

    $opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->where('monto_orden_pago', '>=', 5000)
        ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
    if (!$opa) { echo "SIN OPA\n"; return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 1, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    echo "OPA {$opa->num_orden_pago} monto {$opa->monto_orden_pago}\n\n";

    $mitad = round((float) $opa->monto_orden_pago / 2, 2);
    $resto = (float) $opa->monto_orden_pago - $mitad;

    $paramsBase = fn(array $extra) => (object) array_merge([
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
    ], $extra);

    echo "--- 1: confirmar con UN solo abono (menos que las fechas planificadas) ---\n";
    $resultado1 = $repo->findByConfirmarPago($paramsBase([
        'id_pago' => $boleta->id_pago, 'monto_pago' => $mitad,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-09-07',
            'id_forma_pago' => 1, 'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'monto_restante' => $mitad,
        ]],
    ]));
    echo "  estado de la boleta: {$resultado1->id_estado_orden_pago} (esperado 6 = PAGO PARCIAL)\n";
    $r[] = ((int) $resultado1->id_estado_orden_pago === 6);
    echo $ok(end($r));

    echo "--- 2: NO se puede volver a confirmar con menos abonos que fechas (ya no bloquea el front, pero el backend tiene que seguir dando PARCIAL) ---\n";
    $r[] = ((int) DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago') === 6);
    echo $ok(end($r));

    echo "--- 3: en otra sesion se completa con el resto -> PAGADO ---\n";
    $abonoExistente = DB::table('tb_tes_pago_parcial')->where('id_pago', $boleta->id_pago)->first();
    $resultado2 = $repo->findByConfirmarPago($paramsBase([
        'id_pago' => $boleta->id_pago, 'monto_pago' => $resto,
        'lista_pagos' => [
            (object) [
                'id_pago_parcial' => $abonoExistente->id_pago_parcial, 'fecha_confirma_pago' => '2026-09-07',
                'id_forma_pago' => 1, 'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago,
                'num_cheque' => null, 'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
            ],
            (object) [
                'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-09-08',
                'id_forma_pago' => 1, 'monto_pago' => $resto, 'monto_opa' => $opa->monto_orden_pago,
                'num_cheque' => null, 'monto_restante' => 0,
            ],
        ],
    ]));
    echo "  estado de la boleta: {$resultado2->id_estado_orden_pago} (esperado 5 = PAGADO)\n";
    $r[] = ((int) $resultado2->id_estado_orden_pago === 5);
    echo $ok(end($r));

    $totalAbonos = DB::table('tb_tes_pago_parcial')->where('id_pago', $boleta->id_pago)->count();
    echo "  abonos totales acumulados: {$totalAbonos} (esperado 2)\n";
    $r[] = ($totalAbonos === 2);
    echo $ok(end($r));

    echo "--- 4: una boleta SIN ningun abono (0 pagado) no queda marcada PAGADA ---\n";
    $boletaVacia = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 1, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);
    $resultado3 = $repo->findByConfirmarPago($paramsBase([
        'id_pago' => $boletaVacia->id_pago, 'monto_pago' => 0, 'lista_pagos' => [],
    ]));
    echo "  estado de la boleta: {$resultado3->id_estado_orden_pago} (esperado 1, no cambia sin abonos)\n";
    $r[] = ((int) $resultado3->id_estado_orden_pago === 1);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
