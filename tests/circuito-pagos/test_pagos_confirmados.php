<?php
// Test del criterio nuevo: solo un pago CONFIRMADO bloquea. Un pago creado sin confirmar no.
// Todo con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

$fac = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
    ->orderBy('id_factura')->first();

function armarOpa($user, $fac, $estadoPago, $fechaConfirma) {
    $opa = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $fac->total_neto, 'id_moneda' => 1, 'id_estado_orden_pago' => 4,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $fac->id_factura, 'tipo_factura' => 'PRESTADOR',
        'id_prestador' => $fac->id_prestador,
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'id_factura' => $fac->id_factura,
        'monto_factura' => $fac->total_neto, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);
    TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'fecha_confirma_pago' => $fechaConfirma,
        'monto_pago' => $fac->total_neto, 'anticipo' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => $estadoPago, 'id_usuario' => $user->cod_usuario,
        'num_pago' => '77777' . rand(1000, 9999), 'monto_opa' => $fac->total_neto,
        'monto_anticipado' => 0, 'recursor' => 0, 'tipo_factura' => 'PRESTADOR', 'pago_emergencia' => 0,
    ]);
    return $opa;
}

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- CASO A: pago PENDIENTE sin confirmar -> NO debe bloquear ---\n";
    $opaA = armarOpa($user, $fac, 1, null);
    $bloqueaA = $repo->tienePagosConfirmados($opaA->id_orden_pago);
    echo "  tienePagosConfirmados: " . var_export($bloqueaA, true) . " (esperado false)\n";
    echo ($bloqueaA === false ? ">>> CASO A OK\n\n" : ">>> CASO A FALLA\n\n");

    echo "--- CASO B: pago PAGADO confirmado -> SÍ debe bloquear ---\n";
    $opaB = armarOpa($user, $fac, 5, now());
    $bloqueaB = $repo->tienePagosConfirmados($opaB->id_orden_pago);
    echo "  tienePagosConfirmados: " . var_export($bloqueaB, true) . " (esperado true)\n";
    echo ($bloqueaB === true ? ">>> CASO B OK\n\n" : ">>> CASO B FALLA\n\n");

    echo "--- CASO C: pago RECHAZADO aunque tenga fecha de confirmación -> NO debe bloquear ---\n";
    $opaC = armarOpa($user, $fac, 3, now());
    $bloqueaC = $repo->tienePagosConfirmados($opaC->id_orden_pago);
    echo "  tienePagosConfirmados: " . var_export($bloqueaC, true) . " (esperado false)\n";
    echo ($bloqueaC === false ? ">>> CASO C OK\n\n" : ">>> CASO C FALLA\n\n");

    echo "--- CASO D: pago PENDIENTE pero CON fecha de confirmación (la anomalía de Alba) ---\n";
    $opaD = armarOpa($user, $fac, 1, now());
    $bloqueaD = $repo->tienePagosConfirmados($opaD->id_orden_pago);
    echo "  tienePagosConfirmados: " . var_export($bloqueaD, true) . " (esperado true - ante la duda, bloquea)\n";
    echo ($bloqueaD === true ? ">>> CASO D OK\n\n" : ">>> CASO D FALLA\n\n");

    echo "--- CASO E: anular una OPA con pago SIN confirmar -> ahora debe poder ---\n";
    $opaE = armarOpa($user, $fac, 1, null);
    $res = $repo->findByAnularOpaDeFactura($fac->id_factura, 'test de anulacion');
    echo "  anulada: " . var_export($res['anulada'], true) . " | " . $res['message'] . "\n";
    echo ($res['anulada'] === true ? ">>> CASO E OK\n\n" : ">>> CASO E FALLA\n\n");

    echo "--- CASO F: el alias viejo delega al criterio nuevo ---\n";
    $viejo = $repo->findByOpaTienePagosVivos($opaA->id_orden_pago);
    $nuevo = $repo->tienePagosConfirmados($opaA->id_orden_pago);
    echo "  findByOpaTienePagosVivos: " . var_export($viejo, true) . " | tienePagosConfirmados: " . var_export($nuevo, true) . "\n";
    echo ($viejo === $nuevo ? ">>> CASO F OK\n" : ">>> CASO F FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
