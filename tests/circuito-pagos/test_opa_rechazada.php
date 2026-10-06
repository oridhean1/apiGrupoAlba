<?php
// Test: factura con una OPA RECHAZADA debe poder generar una OPA nueva, y la rechazada
// debe quedar intacta (no se fusiona ni se borra, se conserva el motivo_rechazo).

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

$facA = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) {
        $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
    })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)
    ->orderBy('id_factura')->first();

if (!$facA) { echo "SIN FACTURA\n"; return; }

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    // Simular una OPA rechazada previa sobre esta factura
    $opaRechazada = TesOrdenPagoEntity::create([
        'monto_orden_pago' => 777.00, 'id_moneda' => 1, 'id_estado_orden_pago' => 3, // RECHAZADO
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facA->id_factura, 'tipo_factura' => 'PRESTADOR',
        'motivo_rechazo' => 'Motivo de prueba - no debe perderse',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaRechazada->id_orden_pago, 'id_factura' => $facA->id_factura,
        'monto_factura' => 777.00, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);

    echo "Factura {$facA->id_factura} (total_neto {$facA->total_neto}) con OPA rechazada {$opaRechazada->id_orden_pago} (777.00)\n\n";

    $nueva = $repo->findByIdFacturaMultiple([$facA->id_factura]);

    $rechazadaSigueViva = TesOrdenPagoEntity::find($opaRechazada->id_orden_pago);
    $detalleRechazadaIntacto = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opaRechazada->id_orden_pago)->count();

    echo "--- RESULTADO ---\n";
    echo "OPA nueva: {$nueva->id_orden_pago} | monto: {$nueva->monto_orden_pago} (esperado {$facA->total_neto}, NO 777)\n";
    echo "OPA rechazada sigue existiendo: " . ($rechazadaSigueViva ? 'SI (correcto)' : 'NO (mal, se borro)') . "\n";
    echo "motivo_rechazo conservado: " . ($rechazadaSigueViva->motivo_rechazo ?? 'PERDIDO') . "\n";
    echo "detalle de la rechazada sigue: {$detalleRechazadaIntacto} fila(s)\n";

    $ok = abs((float)$nueva->monto_orden_pago - (float)$facA->total_neto) < 0.01
        && $nueva->id_orden_pago !== $opaRechazada->id_orden_pago
        && $rechazadaSigueViva !== null
        && $rechazadaSigueViva->motivo_rechazo === 'Motivo de prueba - no debe perderse'
        && $detalleRechazadaIntacto === 1;

    echo "\n" . ($ok ? ">>> OK\n" : ">>> FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
