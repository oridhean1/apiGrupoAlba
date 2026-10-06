<?php
// Test: factura valorizada con OPA PENDIENTE sin pagos -> re-valorizar debe permitirse y
// actualizar el monto de la OPA (cabecera + detalle) al nuevo total_neto. Todo con rollback.

use App\Http\Controllers\facturacion\FacturasPrestadoresController;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

$facturaBase = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) {
        $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
    })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)
    ->orderBy('id_factura')->first();

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    // Factura en Valorización Final (3) con una OPA PENDIENTE
    FacturacionDatosEntity::where('id_factura', $facturaBase->id_factura)->update(['estado' => 3]);
    $opaPendiente = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $facturaBase->total_neto, 'id_moneda' => 1, 'id_estado_orden_pago' => 1,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facturaBase->id_factura, 'tipo_factura' => 'PRESTADOR',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaPendiente->id_orden_pago, 'id_factura' => $facturaBase->id_factura,
        'monto_factura' => $facturaBase->total_neto, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);

    echo "Factura {$facturaBase->id_factura}, total_neto original {$facturaBase->total_neto}\n";
    echo "OPA {$opaPendiente->id_orden_pago} PENDIENTE, monto {$opaPendiente->monto_orden_pago}, sin pagos\n\n";

    // Simular que se re-liquidó con un total distinto
    $nuevoTotal = round((float) $facturaBase->total_neto * 1.25, 2);
    FacturacionDatosEntity::where('id_factura', $facturaBase->id_factura)->update(['total_neto' => $nuevoTotal]);
    echo "Nuevo total_neto tras re-liquidar: {$nuevoTotal}\n\n";

    $request = new Request(['factura' => $facturaBase->id_factura, 'estado' => '3']);
    $controller = new FacturasPrestadoresController();
    $response = app()->call([$controller, 'getActualizarEstadoLiquidacion'], ['request' => $request]);
    $data = json_decode($response->getContent(), true);

    echo "--- RESPUESTA (status " . $response->getStatusCode() . ") ---\n";
    echo json_encode($data) . "\n\n";

    $opaPendiente->refresh();
    $detalle = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opaPendiente->id_orden_pago)->first();
    $facturaFinal = FacturacionDatosEntity::find($facturaBase->id_factura);

    echo "--- ESTADO FINAL ---\n";
    echo "factura estado: {$facturaFinal->estado} (esperado 3)\n";
    echo "OPA monto_orden_pago: {$opaPendiente->monto_orden_pago} (esperado {$nuevoTotal})\n";
    echo "detalle monto_factura: {$detalle->monto_factura} (esperado {$nuevoTotal})\n";

    $ok = $response->getStatusCode() === 200
        && (int) $facturaFinal->estado === 3
        && abs((float) $opaPendiente->monto_orden_pago - $nuevoTotal) < 0.01
        && abs((float) $detalle->monto_factura - $nuevoTotal) < 0.01;
    echo "\n" . ($ok ? ">>> CASO A (revalorizar con OPA pendiente) OK\n" : ">>> CASO A FALLA\n");

    // --- CASO B: la misma factura, pero ahora la OPA tiene un pago -> debe bloquear ---
    echo "\n--- CASO B: OPA pendiente CON pago vivo -> debe bloquear ---\n";
    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opaPendiente->id_orden_pago, 'fecha_registra' => now(),
        'monto_pago' => $nuevoTotal, 'anticipo' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => $user->cod_usuario,
        'num_pago' => '888888888888', 'monto_opa' => $nuevoTotal, 'monto_anticipado' => 0,
        'recursor' => 0, 'tipo_factura' => 'PRESTADOR', 'pago_emergencia' => 0,
    ]);
    // "Pago vivo" = plata COBRADA (abono con fecha de confirmación), no una boleta suelta: es el
    // criterio de tienePagosConfirmados() desde la Fase 1. Una boleta sin plata no bloquea.
    DB::table('tb_tes_pago_parcial')->insert([
        'id_pago' => $boleta->id_pago, 'fecha_registra' => now(), 'id_forma_pago' => 1,
        'monto_pago' => 100, 'monto_opa' => $nuevoTotal, 'monto_restante' => $nuevoTotal - 100,
        'id_usuario' => $user->cod_usuario, 'fecha_confirma_pago' => now()->toDateString(),
        'fecha_confirmado_en_pago' => now(),
    ]);
    $request2 = new Request(['factura' => $facturaBase->id_factura, 'estado' => '3']);
    $controller2 = new FacturasPrestadoresController();
    $response2 = app()->call([$controller2, 'getActualizarEstadoLiquidacion'], ['request' => $request2]);
    $data2 = json_decode($response2->getContent(), true);
    echo "status: {$response2->getStatusCode()} | " . ($data2['message'] ?? '') . "\n";
    $okB = $response2->getStatusCode() === 409;
    echo ($okB ? ">>> CASO B OK (bloqueado)\n" : ">>> CASO B FALLA (dejo pasar con pago vivo)\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
