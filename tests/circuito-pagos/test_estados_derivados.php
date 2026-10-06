<?php
// Estados derivados: la OPA calcula su estado comparando imputado vs pagado.
// Todo con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);
$R = TestOrdenPagoRepository::class;

// La factura de prueba tiene que estar SIN DEBITO y con neto suficiente: desde el 2026-09-05 el
// estado se deriva contra el monto PAGABLE (imputado menos debito), asi que una factura con
// debito haria que "pagar 30.000 de 100.000" alcance para cerrar la orden y el caso D dejaria de
// tener sentido. Los montos del test (100.000) son sinteticos, la factura es real.
$fac = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_opa_factura'); })
    ->whereNotNull('total_neto')->where('total_neto', '>=', 100000)->whereNotNull('id_prestador')
    ->where(function ($q) {
        $q->whereNull('total_debitado_liquidacion')->orWhere('total_debitado_liquidacion', '=', 0);
    })
    ->orderBy('id_factura')->first();

function nuevaOpa($user, $fac, $monto) {
    $opa = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $monto, 'id_moneda' => 1, 'id_estado_orden_pago' => 1,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $fac->id_factura, 'tipo_factura' => 'PRESTADOR', 'id_prestador' => $fac->id_prestador,
    ]);
    TesFacturasOpaEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'id_factura' => $fac->id_factura,
        'monto_aplicado' => $monto, 'fecha_imputacion' => now(), 'cod_usuario' => $user->cod_usuario,
    ]);
    return $opa;
}

function pago($user, $opa, $monto, $estado, $confirma) {
    return TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'fecha_confirma_pago' => $confirma, 'monto_pago' => $monto, 'anticipo' => 0,
        'id_forma_pago' => 1, 'id_estado_orden_pago' => $estado, 'id_usuario' => $user->cod_usuario,
        'num_pago' => '55' . rand(100000, 999999), 'monto_opa' => $monto, 'monto_anticipado' => 0,
        'recursor' => 0, 'tipo_factura' => 'PRESTADOR', 'pago_emergencia' => 0,
    ]);
}

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();
    $total = 100000.00;

    echo "--- A: OPA imputada en 100.000, sin pagos -> PENDIENTE (1) ---\n";
    $a = nuevaOpa($user, $fac, $total);
    $e = $repo->recalcularEstadoOpa($a->id_orden_pago);
    echo "  estado: {$e} | imputado: {$repo->montoImputadoOpa($a->id_orden_pago)} | pagado: {$repo->montoPagadoOpa($a->id_orden_pago)}\n";
    echo ($e === 1 ? ">>> OK\n\n" : ">>> FALLA\n\n");

    // Cambiado el 2026-09-05: antes esto esperaba PENDIENTE(1). Ahora una orden que YA tiene su
    // boleta -aunque no se haya cobrado un peso- es EN PROCESO(4). PENDIENTE quedo reservado
    // para "todavia no se definio como se paga". Sin esta distincion, el recalculo devolvia la
    // orden a PENDIENTE apenas se tocaba un abono y reaparecia el boton Confirmar OPA sobre una
    // orden ya confirmada -> 409 "esta orden ya tiene un pago generado".
    echo "--- B: + pago SIN confirmar de 100.000 -> EN PROCESO (hay boleta, no se movio plata) ---\n";
    $pb = pago($user, $a, $total, 1, null);
    $e = $repo->recalcularEstadoOpa($a->id_orden_pago);
    echo "  estado: {$e} | pagado: {$repo->montoPagadoOpa($a->id_orden_pago)}\n";
    echo ($e === 4 ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- C: se confirma ese pago -> PAGADO (5) ---\n";
    $pb->id_estado_orden_pago = 5; $pb->fecha_confirma_pago = now(); $pb->save();
    $e = $repo->recalcularEstadoOpa($a->id_orden_pago);
    echo "  estado: {$e} | pagado: {$repo->montoPagadoOpa($a->id_orden_pago)}\n";
    echo ($e === 5 ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- D: OPA nueva, pago confirmado por SOLO 30.000 de 100.000 -> PAGO PARCIAL (6) ---\n";
    echo "     (es el caso que antes se cerraba como PAGADO y dejo \$15,4M mal marcados)\n";
    $d = nuevaOpa($user, $fac, $total);
    pago($user, $d, 30000.00, 5, now());
    $e = $repo->recalcularEstadoOpa($d->id_orden_pago);
    echo "  estado: {$e} | imputado: {$repo->montoImputadoOpa($d->id_orden_pago)} | pagado: {$repo->montoPagadoOpa($d->id_orden_pago)}\n";
    echo ($e === 6 ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- E: se anula ese pago -> la orden VUELVE a PENDIENTE, no queda rechazada ---\n";
    DB::table('tb_tes_pago')->where('id_orden_pago', $d->id_orden_pago)->update(['id_estado_orden_pago' => 3]);
    $e = $repo->recalcularEstadoOpa($d->id_orden_pago);
    $d->refresh();
    echo "  estado: {$e} | pagado: {$repo->montoPagadoOpa($d->id_orden_pago)}\n";
    echo "  y como el pago quedo rechazado, se puede generar otro: " . var_export(!$repo->tieneAlgunPago($d->id_orden_pago), true) . "\n";
    echo ($e === 1 && !$repo->tieneAlgunPago($d->id_orden_pago) ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- F: monto_pago NULL pero monto_opa cargado -> tiene que contarlo igual ---\n";
    echo "     (pasa en 100 de 251 pagos confirmados de Alba y 74 de 103 de OSV)\n";
    $f = nuevaOpa($user, $fac, $total);
    $pf = pago($user, $f, $total, 5, now());
    $pf->monto_pago = null; $pf->save();
    $e = $repo->recalcularEstadoOpa($f->id_orden_pago);
    echo "  estado: {$e} | pagado: {$repo->montoPagadoOpa($f->id_orden_pago)} (esperado 100000)\n";
    echo ($e === 5 ? ">>> OK\n\n" : ">>> FALLA\n\n");

    echo "--- G: una OPA RECHAZADA no se recalcula (es decision administrativa) ---\n";
    $g = nuevaOpa($user, $fac, $total);
    pago($user, $g, $total, 5, now());
    $g->id_estado_orden_pago = 3; $g->save();
    $e = $repo->recalcularEstadoOpa($g->id_orden_pago);
    echo "  estado: {$e} (esperado 3, aunque tenga el pago completo)\n";
    echo ($e === 3 ? ">>> OK\n" : ">>> FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
