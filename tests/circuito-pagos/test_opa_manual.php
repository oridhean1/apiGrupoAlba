<?php
// Test de findByIdFacturaMultiple reescrita (2026-08-13): debe crear la OPA desde cero
// cuando la factura no tiene ninguna, agrupar varias, y seguir fusionando OPAs existentes.
// Todo con rollback: no deja rastro.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

// Facturas que NO estén ya en el detalle de alguna OPA, para que el test no sea ambiguo.
// CASO 2 agrupa facB+facC: ahora que se valida "mismo beneficiario" (2026-08-13), tienen que
// ser del mismo id_prestador -- si no, la nueva validación las rechaza (correctamente).
$facA = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)
    ->orderBy('id_factura')->first(['id_factura', 'total_neto', 'id_prestador', 'id_proveedor', 'id_tipo_factura']);

$parMismoPrestador = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
    ->where('id_prestador', function ($q) {
        $q->select('id_prestador')->from('tb_facturacion_datos')
            ->whereNotIn('id_factura', function ($q2) { $q2->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
            ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
            ->groupBy('id_prestador')->havingRaw('COUNT(*) >= 2')->orderBy('id_prestador')->limit(1);
    })
    ->orderBy('id_factura')->limit(2)
    ->get(['id_factura', 'total_neto', 'id_prestador', 'id_proveedor', 'id_tipo_factura']);

if ($parMismoPrestador->count() < 2) { echo "SIN FACTURAS SUFICIENTES DEL MISMO PRESTADOR\n"; return; }
[$facB, $facC] = $parMismoPrestador->all();

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- CASO 1: UNA factura sin OPA previa (el caso nuevo) ---\n";
    echo "factura {$facA->id_factura}, total_neto {$facA->total_neto}\n";
    $opa1 = $repo->findByIdFacturaMultiple([$facA->id_factura]);
    $det1 = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa1->id_orden_pago)->get();
    echo "OPA {$opa1->id_orden_pago} | num: {$opa1->num_orden_pago} | monto: {$opa1->monto_orden_pago} | estado: {$opa1->id_estado_orden_pago}\n";
    echo "id_factura en cabecera: " . var_export($opa1->id_factura, true) . " | filas detalle: {$det1->count()} | factura_unida: " . $det1->first()->factura_unida . "\n";
    $ok1 = abs((float)$opa1->monto_orden_pago - (float)$facA->total_neto) < 0.01
        && $det1->count() === 1
        && (int)$det1->first()->factura_unida === 0
        && (int)$opa1->id_factura === (int)$facA->id_factura
        && (int)$opa1->id_estado_orden_pago === 1
        && !empty($opa1->num_orden_pago);
    echo ($ok1 ? ">>> CASO 1 OK\n\n" : ">>> CASO 1 FALLA\n\n");

    echo "--- CASO 2: DOS facturas sin OPA previa (agrupar desde cero) ---\n";
    $esperado = (float)$facB->total_neto + (float)$facC->total_neto;
    echo "facturas {$facB->id_factura} ({$facB->total_neto}) + {$facC->id_factura} ({$facC->total_neto}) = {$esperado}\n";
    $opa2 = $repo->findByIdFacturaMultiple([$facB->id_factura, $facC->id_factura]);
    $det2 = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa2->id_orden_pago)->get();
    echo "OPA {$opa2->id_orden_pago} | num: {$opa2->num_orden_pago} | monto: {$opa2->monto_orden_pago}\n";
    echo "id_factura en cabecera: " . var_export($opa2->id_factura, true) . " (debe ser NULL) | filas detalle: {$det2->count()} | factura_unida: " . $det2->pluck('factura_unida')->implode(',') . "\n";
    $ok2 = abs((float)$opa2->monto_orden_pago - $esperado) < 0.01
        && $det2->count() === 2
        && is_null($opa2->id_factura)
        && $det2->every(fn($d) => (int)$d->factura_unida === 1);
    echo ($ok2 ? ">>> CASO 2 OK\n\n" : ">>> CASO 2 FALLA\n\n");

    echo "--- CASO 3: factura que YA tiene OPA (circuito proveedor, comportamiento viejo) ---\n";
    $facD = DB::table('tb_facturacion_datos')
        ->whereNotIn('id_factura', function ($q) {
            $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
        })
        ->whereNotNull('total_neto')->orderBy('id_factura')->skip(10)->first();
    $opaVieja = TesOrdenPagoEntity::create([
        'monto_orden_pago' => 999.00, 'id_moneda' => 1, 'id_estado_orden_pago' => 1,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facD->id_factura, 'tipo_factura' => 'PROVEEDOR',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaVieja->id_orden_pago, 'id_factura' => $facD->id_factura,
        'monto_factura' => 999.00, 'tipo_factura' => 'PROVEEDOR', 'factura_unida' => 0,
    ]);
    echo "factura {$facD->id_factura} con OPA previa {$opaVieja->id_orden_pago} (999.00)\n";
    $opa3 = $repo->findByIdFacturaMultiple([$facD->id_factura]);
    $viveVieja = TesOrdenPagoEntity::find($opaVieja->id_orden_pago) !== null;
    echo "OPA nueva {$opa3->id_orden_pago} | monto: {$opa3->monto_orden_pago} (esperado 999.00)\n";
    echo "OPA vieja sigue viva: " . ($viveVieja ? 'SI (MAL)' : 'no (ok, se fusiono)') . "\n";
    $ok3 = abs((float)$opa3->monto_orden_pago - 999.00) < 0.01 && !$viveVieja;
    echo ($ok3 ? ">>> CASO 3 OK\n\n" : ">>> CASO 3 FALLA\n\n");

    echo "--- CASO 4: guarda de pagos vivos (no debe permitir agrupar) ---\n";
    $facE = DB::table('tb_facturacion_datos')
        ->whereNotIn('id_factura', function ($q) {
            $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
        })->whereNotNull('total_neto')->orderBy('id_factura')->skip(20)->first();
    $opaConPago = TesOrdenPagoEntity::create([
        'monto_orden_pago' => 500.00, 'id_moneda' => 1, 'id_estado_orden_pago' => 1,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facE->id_factura, 'tipo_factura' => 'PRESTADOR',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaConPago->id_orden_pago, 'id_factura' => $facE->id_factura,
        'monto_factura' => 500.00, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);
    \App\Models\Tesoreria\TesPagoEntity::create([
        'id_orden_pago' => $opaConPago->id_orden_pago, 'fecha_registra' => now(),
        'monto_pago' => 500.00, 'anticipo' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => $user->cod_usuario,
        'num_pago' => '999999999999', 'monto_opa' => 500.00, 'monto_anticipado' => 0,
        'recursor' => 0, 'tipo_factura' => 'PRESTADOR', 'pago_emergencia' => 0,
    ]);
    try {
        $repo->findByIdFacturaMultiple([$facE->id_factura]);
        echo ">>> CASO 4 FALLA (dejo agrupar una OPA con pago vivo)\n";
    } catch (\Throwable $e) {
        echo "bloqueado: " . $e->getMessage() . "\n";
        echo ">>> CASO 4 OK\n";
    }

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho - no quedo nada en la base)\n";
}
