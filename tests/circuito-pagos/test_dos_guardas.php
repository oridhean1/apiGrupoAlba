<?php
// Verifica que las DOS guardas se comporten distinto donde corresponde:
//  - tienePagosConfirmados: permisiva. Solo bloquea si salió plata. Para editar/anular.
//  - tieneAlgunPago: estricta. Bloquea con cualquier pago. Para borrado físico.
// Todo con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

$facs = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
    ->orderBy('id_factura')->limit(2)->get();
[$facA, $facB] = $facs->all();

function opaConPago($user, $fac, $estadoPago, $fechaConfirma) {
    $opa = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $fac->total_neto, 'id_moneda' => 1, 'id_estado_orden_pago' => 4,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $fac->id_factura, 'tipo_factura' => 'PRESTADOR', 'id_prestador' => $fac->id_prestador,
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'id_factura' => $fac->id_factura,
        'monto_factura' => $fac->total_neto, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);
    if ($estadoPago !== null) {
        TesPagoEntity::create([
            'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
            'fecha_confirma_pago' => $fechaConfirma,
            'monto_pago' => $fac->total_neto, 'anticipo' => 0, 'id_forma_pago' => 1,
            'id_estado_orden_pago' => $estadoPago, 'id_usuario' => $user->cod_usuario,
            'num_pago' => '66666' . rand(1000, 9999), 'monto_opa' => $fac->total_neto,
            'monto_anticipado' => 0, 'recursor' => 0, 'tipo_factura' => 'PRESTADOR', 'pago_emergencia' => 0,
        ]);
    }
    return $opa;
}

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- Las dos guardas sobre una OPA con pago SIN confirmar ---\n";
    $opa = opaConPago($user, $facA, 1, null);
    $conf = $repo->tienePagosConfirmados($opa->id_orden_pago);
    $alguno = $repo->tieneAlgunPago($opa->id_orden_pago);
    echo "  tienePagosConfirmados: " . var_export($conf, true) . " (esperado false -> deja editar/anular)\n";
    echo "  tieneAlgunPago:        " . var_export($alguno, true) . " (esperado true  -> NO deja borrar)\n";
    $ok1 = ($conf === false && $alguno === true);
    echo ($ok1 ? ">>> OK: se comportan distinto como corresponde\n\n" : ">>> FALLA\n\n");

    echo "--- Agrupar una OPA con pago sin confirmar -> debe BLOQUEAR (borra físicamente) ---\n";
    try {
        $repo->findByIdFacturaMultiple([$facA->id_factura]);
        echo ">>> FALLA (permitió borrar una OPA con un pago que quedaría huérfano)\n\n";
    } catch (\Throwable $e) {
        echo "  bloqueado: " . $e->getMessage() . "\n";
        echo ">>> OK\n\n";
    }

    echo "--- Anular esa misma OPA -> debe PERMITIR (no borra, solo cambia estado) ---\n";
    $res = $repo->findByAnularOpaDeFactura($facA->id_factura, 'test');
    echo "  anulada: " . var_export($res['anulada'], true) . " | " . $res['message'] . "\n";
    $opa->refresh();
    echo "  estado de la OPA: {$opa->id_estado_orden_pago} (3 = RECHAZADO)\n";
    $pagoSigue = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->exists();
    echo "  el pago sigue existiendo (no quedó huérfano): " . var_export($pagoSigue, true) . "\n";
    echo (($res['anulada'] === true && (int)$opa->id_estado_orden_pago === 3 && $pagoSigue) ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- OPA sin ningún pago -> ambas guardas en false, se puede todo ---\n";
    $opaB = opaConPago($user, $facB, null, null);
    echo "  tienePagosConfirmados: " . var_export($repo->tienePagosConfirmados($opaB->id_orden_pago), true) . "\n";
    echo "  tieneAlgunPago:        " . var_export($repo->tieneAlgunPago($opaB->id_orden_pago), true) . "\n";
    $ok2 = (!$repo->tienePagosConfirmados($opaB->id_orden_pago) && !$repo->tieneAlgunPago($opaB->id_orden_pago));
    echo ($ok2 ? ">>> OK\n" : ">>> FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
