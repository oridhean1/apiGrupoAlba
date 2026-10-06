<?php
// Test: anular una factura cuya OPA está PENDIENTE-sin-tocar debe seguir pasando por la
// cascada (rechazar la OPA), no dejarla huérfana. Es la regresión que casi introduzco al
// agregar la excepción de "pendiente sin tocar". Con rollback.

use App\Http\Controllers\facturacion\FacturasPrestadoresController;
use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
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
    ->orderBy('id_factura')->skip(1)->first();

DB::beginTransaction();
try {
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

    echo "Factura {$facturaBase->id_factura} con OPA {$opaPendiente->id_orden_pago} PENDIENTE, sin pagos\n\n";

    $request = new Request(['factura' => $facturaBase->id_factura, 'estado' => '4', 'motivo_anulacion' => 'test']);
    $controller = new FacturasPrestadoresController();
    $response = app()->call([$controller, 'getActualizarEstadoLiquidacion'], ['request' => $request]);
    $data = json_decode($response->getContent(), true);
    echo "status: {$response->getStatusCode()} | " . ($data['message'] ?? '') . "\n\n";

    $facturaFinal = FacturacionDatosEntity::find($facturaBase->id_factura);
    $opaFinal = TesOrdenPagoEntity::find($opaPendiente->id_orden_pago);

    echo "factura estado: {$facturaFinal->estado} (esperado 4 ANULADA)\n";
    echo "OPA estado: {$opaFinal->id_estado_orden_pago} (esperado 3 RECHAZADO, NO debe quedar huerfana en 1)\n";

    $ok = $response->getStatusCode() === 200
        && (int) $facturaFinal->estado === 4
        && (int) $opaFinal->id_estado_orden_pago === 3;
    echo "\n" . ($ok ? ">>> OK\n" : ">>> FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
