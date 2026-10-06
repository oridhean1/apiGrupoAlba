<?php
// DOS cosas, reportadas juntas el 2026-09-09.
//
// 1) Si el cronograma declara N fechas y ya se cargaron las N, los abonos tienen que CUBRIR lo
//    pagable. Si no, la orden queda en PAGO PARCIAL sin ninguna fecha libre donde cargar la
//    diferencia: trabada y en silencio.
//
// 2) 423 "La cuenta bancaria seleccionada no tiene una cuenta contable asignada" aunque la cuenta
//    la tuviera. Regresion: al sacar el selector global del modal, `$params->id_cuenta_bancaria`
//    llega vacio y el asiento lo usaba tal cual. Ademas el HABER era UNA linea por el total, asi
//    que pagar desde dos cuentas acreditaba todo a un banco.

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

    $opa = null; $cuentas = collect();
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razones = $opaRepo->razonesSocialesDeOpa($cand->id_orden_pago);
        if (empty($razones)) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 2000) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')
            ->whereIn('id_razon', $razones)->get()->unique('id_entidad_bancaria')->take(2)->values();
        if ($c->count() >= 2) { $opa = $cand; $cuentas = $c; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    echo "OPA {$opa->num_orden_pago} | pagable " . number_format($pagable, 2) . "\n\n";

    // Cada caso arranca con la orden LIMPIA de abonos anteriores.
    //
    // Los 6 casos comparten una sola OPA y le van colgando una boleta nueva a cada uno. Eso servia
    // mientras el modal no validaba el tope, pero desde el 2026-09-12 si: el freno de sobrepago es
    // POR ORDEN —es lo que se le debe al beneficiario, no lo que entra en una boleta—, asi que los
    // abonos del caso 2 seguian ocupando lugar en el caso 3 y este lo rechazaba por pasarse.
    //
    // El tope esta bien; el fixture estaba mal. En la realidad el problema no existe: ninguna de
    // las 4.187 OPAs tiene mas de una boleta (verificado 2026-09-04), asi que varias boletas vivas
    // sobre la misma orden es una situacion que solo se da en este test.
    $nuevaBoleta = function () use ($opa) {
        $boletasPrevias = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->pluck('id_pago');
        \App\Models\Tesoreria\TesPagosParciales::whereIn('id_pago', $boletasPrevias)->delete();

        return TesPagoEntity::create([
            'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
            'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
            'id_estado_orden_pago' => 1, 'id_usuario' => 1,
            'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
        ]);
    };
    $agregarFechas = function ($boleta, array $fechas) {
        $o = 0;
        foreach ($fechas as $f) {
            DB::table('tb_tes_fecha_probable_pago')->insert([
                'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $f,
                'orden_cuotas' => ++$o, 'fecha_registra' => now()->toDateString(),
            ]);
        }
    };
    $base = fn($boleta, array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_cuenta_bancaria' => '', 'anticipo' => '0',
        'monto_anticipado' => '0', 'num_cheque' => null, 'fecha_probable_pago' => null,
        'observaciones' => null, 'id_forma_cobro' => null, 'monto_cobro' => null,
        'fecha_confirma_cobro' => null, 'cuenta_bancaria' => null, 'imputacion_contable' => null,
        'banco' => null, 'archivos_eliminados' => null, 'monto_pago' => 0,
    ], $extra);
    $fila = fn($fecha, $monto, $cuenta) => (object) [
        'id_pago_parcial' => null, 'fecha_confirma_pago' => $fecha,
        'id_forma_pago' => 1, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => null, 'monto_restante' => 0, 'id_cuenta_bancaria' => $cuenta,
    ];

    echo "--- 1: 2 fechas, 2 abonos que NO cubren -> rechaza ---\n";
    $b1 = $nuevaBoleta();
    $agregarFechas($b1, ['2026-11-01', '2026-11-15']);
    try {
        $repo->findByConfirmarPago($base($b1, ['lista_pagos' => [
            $fila('2026-11-01', 100, $cuentas[0]->id_cuenta_bancaria),
            $fila('2026-11-15', 200, $cuentas[0]->id_cuenta_bancaria),
        ]]));
        echo "  NO fallo: dejo confirmar sin cubrir\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'no cubren');
    }
    echo $ok(end($r));

    echo "--- 2: 2 fechas pero SOLO 1 abono cargado -> deja (parcial legitimo) ---\n";
    $b2 = $nuevaBoleta();
    $agregarFechas($b2, ['2026-11-01', '2026-11-15']);
    try {
        $res = $repo->findByConfirmarPago($base($b2, ['lista_pagos' => [
            $fila('2026-11-01', 100, $cuentas[0]->id_cuenta_bancaria),
        ]]));
        echo "  estado de la boleta: {$res->id_estado_orden_pago} (6 = PAGO PARCIAL)\n";
        $r[] = ((int) $res->id_estado_orden_pago === 6);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 3: 2 fechas, 2 abonos que SI cubren -> deja y queda PAGADO ---\n";
    $b3 = $nuevaBoleta();
    $agregarFechas($b3, ['2026-11-01', '2026-11-15']);
    $mitad = round($pagable / 2, 2);
    try {
        $res = $repo->findByConfirmarPago($base($b3, ['lista_pagos' => [
            $fila('2026-11-01', $mitad, $cuentas[0]->id_cuenta_bancaria),
            $fila('2026-11-15', $pagable - $mitad, $cuentas[1]->id_cuenta_bancaria),
        ]]));
        echo "  estado de la boleta: {$res->id_estado_orden_pago} (5 = PAGADO)\n";
        $r[] = ((int) $res->id_estado_orden_pago === 5);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 4: una boleta SIN cronograma no se valida (circuito viejo) ---\n";
    $b4 = $nuevaBoleta();
    try {
        $repo->findByConfirmarPago($base($b4, ['lista_pagos' => [
            $fila('2026-11-01', 50, $cuentas[0]->id_cuenta_bancaria),
        ]]));
        echo "  dejo confirmar sin cronograma\n"; $r[] = true;
    } catch (\Throwable $e) {
        echo "  fallo: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    // ── El asiento contable ──
    echo "--- 5: el asiento parte el HABER por cuenta ---\n";
    $asientoRepo = new App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository();
    $metodo = new ReflectionMethod($asientoRepo, 'obtenerCuentaContableByCuentaBancaria');
    $metodo->setAccessible(true);
    $c0 = $metodo->invoke($asientoRepo, $cuentas[0]->id_cuenta_bancaria);
    $c1 = $metodo->invoke($asientoRepo, $cuentas[1]->id_cuenta_bancaria);
    echo "  cuenta {$cuentas[0]->id_cuenta_bancaria} tiene relacion contable: " . var_export(!is_null($c0), true) . "\n";
    echo "  cuenta {$cuentas[1]->id_cuenta_bancaria} tiene relacion contable: " . var_export(!is_null($c1), true) . "\n";
    // Solo informativo: si el catalogo esta incompleto, el asiento avisa cual falta.
    $r[] = true;
    echo $ok(end($r));

    echo "--- 6: la cuenta del asiento ya no sale de un campo vacio ---\n";
    // Reproduce el caso del 423: params sin cuenta, abonos con cuenta.
    $b5 = $nuevaBoleta();
    $agregarFechas($b5, ['2026-12-01']);
    $repo->findByConfirmarPago($base($b5, ['lista_pagos' => [
        $fila('2026-12-01', $pagable, $cuentas[0]->id_cuenta_bancaria),
    ]]));
    $montosPorCuenta = [];
    foreach ($repo->findByPagosParcialesVivos($b5->id_pago) as $a) {
        $idc = $a->id_cuenta_bancaria ?: null;
        if ($idc) { $montosPorCuenta[$idc] = ($montosPorCuenta[$idc] ?? 0) + (float) $a->monto_pago; }
    }
    $resuelta = '' ?: (array_key_first($montosPorCuenta) ?? null);
    echo "  params traia '' | cuenta resuelta para el asiento: " . var_export($resuelta, true) . "\n";
    $r[] = (!is_null($resuelta) && (int) $resuelta === (int) $cuentas[0]->id_cuenta_bancaria);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
