<?php
// Test del fix de agrupado/desagrupado de OPAs (2026-08-11).
// Todo dentro de una transacción con rollback: no deja rastro.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
if (!$user) { echo "SIN USUARIOS\n"; return; }
Auth::login($user);

// Facturas que NO estén ya en el detalle de alguna OPA, para que el test no sea ambiguo.
$facturas = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) {
        $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
    })
    ->orderBy('id_factura')->limit(3)->pluck('id_factura')->toArray();
if (count($facturas) < 3) { echo "SIN FACTURAS SUFICIENTES\n"; return; }
[$facA, $facB, $facC] = $facturas;

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    $mk = function ($idFactura, $monto) use ($user) {
        $opa = TesOrdenPagoEntity::create([
            'monto_orden_pago' => $monto,
            'id_moneda' => 1,
            'id_razon' => 1,
            'fecha_emision' => now()->toDateString(),
            'fecha_vencimiento' => now()->toDateString(),
            'fecha_probable_pago' => now()->toDateString(),
            'id_estado_orden_pago' => 1,
            'monto_anticipado' => 0,
            'cod_usuario' => $user->cod_usuario,
            'fecha_genera' => now(),
            'id_factura' => $idFactura,
            'tipo_factura' => 'PRESTADOR',
        ]);
        TesOrdenPagoDetalleEntity::create([
            'id_orden_pago' => $opa->id_orden_pago,
            'id_factura' => $idFactura,
            'monto_factura' => $monto,
            'tipo_factura' => 'PRESTADOR',
            'factura_unida' => 0,
        ]);
        return $opa;
    };

    $opaDestino = $mk($facA, 100.00);
    $opaOrigen  = $mk($facB, 200.00);

    echo "--- ANTES ---\n";
    echo "destino {$opaDestino->id_orden_pago}: cabecera 100.00\n";
    echo "origen  {$opaOrigen->id_orden_pago}: cabecera 200.00\n\n";

    $res = $repo->findAddFacturaMultiple((object) [
        'idFactura' => $facB,
        'idOrdenPago' => $opaDestino->id_orden_pago,
    ]);
    echo "resultado: " . json_encode($res['success']) . " - {$res['message']}\n\n";

    $destino = TesOrdenPagoEntity::find($opaDestino->id_orden_pago);
    $sumDet  = (float) TesOrdenPagoDetalleEntity::where('id_orden_pago', $destino->id_orden_pago)->sum('monto_factura');
    $nDet    = TesOrdenPagoDetalleEntity::where('id_orden_pago', $destino->id_orden_pago)->count();
    $origenVive = TesOrdenPagoEntity::find($opaOrigen->id_orden_pago) !== null;

    echo "--- DESPUES ---\n";
    echo "cabecera destino : " . number_format((float) $destino->monto_orden_pago, 2) . "\n";
    echo "suma detalle     : " . number_format($sumDet, 2) . "\n";
    echo "filas detalle    : {$nDet}\n";
    echo "origen sigue vivo: " . ($origenVive ? 'SI (MAL)' : 'no (ok)') . "\n\n";

    $ok = abs((float) $destino->monto_orden_pago - 300.00) < 0.01
        && abs($sumDet - 300.00) < 0.01
        && $nDet === 2
        && !$origenVive;
    echo $ok ? ">>> AGRUPAR OK\n\n" : ">>> AGRUPAR FALLA\n\n";

    // Desagrupar la factura B y verificar que la cabecera vuelva a 100
    $repo->findRemoveFacturaMultiple((object) [
        'idFactura' => $facB,
        'idOrdenPago' => $destino->id_orden_pago,
    ]);
    $destino->refresh();
    $sumDet2 = (float) TesOrdenPagoDetalleEntity::where('id_orden_pago', $destino->id_orden_pago)->sum('monto_factura');

    echo "--- DESPUES DE DESAGRUPAR ---\n";
    echo "cabecera destino : " . number_format((float) $destino->monto_orden_pago, 2) . "\n";
    echo "suma detalle     : " . number_format($sumDet2, 2) . "\n";
    $ok2 = abs((float) $destino->monto_orden_pago - 100.00) < 0.01 && abs($sumDet2 - 100.00) < 0.01;
    echo $ok2 ? ">>> DESAGRUPAR OK\n" : ">>> DESAGRUPAR FALLA\n";

    // --- Guarda de pagos: agrupar una OPA con pago vivo debe rechazarse ---
    $opaConPago = $mk($facC, 500.00);
    \App\Models\Tesoreria\TesPagoEntity::create([
        'id_orden_pago' => $opaConPago->id_orden_pago,
        'fecha_registra' => now(),
        'monto_pago' => 500.00,
        'anticipo' => 0,
        'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1,      // pago vivo (no rechazado)
        'id_usuario' => $user->cod_usuario,
        'num_pago' => '999999999999',
        'monto_opa' => 500.00,
        'monto_anticipado' => 0,
        'recursor' => 0,
        'tipo_factura' => 'PRESTADOR',
        'pago_emergencia' => 0,
    ]);

    echo "\n[debug] opaConPago id       : {$opaConPago->id_orden_pago}\n";
    echo "[debug] pagos en esa OPA    : " . \App\Models\Tesoreria\TesPagoEntity::where('id_orden_pago', $opaConPago->id_orden_pago)->count() . "\n";
    $detDebug = TesOrdenPagoDetalleEntity::where('id_factura', $facC)->first();
    echo "[debug] detalle facC -> OPA : " . ($detDebug ? $detDebug->id_orden_pago : 'NULL') . "\n";
    echo "[debug] facturas A/B/C      : {$facA} / {$facB} / {$facC}\n";

    $resGuarda = $repo->findAddFacturaMultiple((object) [
        'idFactura' => $facC,
        'idOrdenPago' => $destino->id_orden_pago,
    ]);

    echo "\n--- GUARDA DE PAGOS ---\n";
    echo "success: " . json_encode($resGuarda['success']) . "\n";
    echo "mensaje: {$resGuarda['message']}\n";
    echo ($resGuarda['success'] === false ? ">>> GUARDA OK (bloqueo)\n" : ">>> GUARDA FALLA (dejo agrupar con pago)\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho - no quedo nada en la base)\n";
}
