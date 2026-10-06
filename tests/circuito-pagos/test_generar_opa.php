<?php
// Test de findByIdFacturaMultiple reescrita (2026-08-12): debe poder crear una OPA
// desde cero (sin OPA previa), agrupar varias sin OPA previa, y seguir agrupando OPAs
// existentes como antes (circuito proveedor). Todo con rollback, no deja rastro.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

$facturas = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) {
        $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
    })
    ->whereNotNull('total_neto')
    ->orderBy('id_factura')->limit(3)->get(['id_factura', 'total_neto', 'id_prestador', 'id_proveedor', 'id_tipo_factura']);

if ($facturas->count() < 3) { echo "SIN FACTURAS SUFICIENTES SIN OPA\n"; return; }
[$facA, $facB, $facC] = $facturas->all();

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- CASO 1: una sola factura SIN opa previa ---\n";
    echo "factura {$facA->id_factura}, total_neto {$facA->total_neto}\n";
    $opa1 = $repo->findByIdFacturaMultiple([$facA->id_factura]);
    $det1 = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa1->id_orden_pago)->get();
    echo "OPA creada: {$opa1->id_orden_pago}, monto: {$opa1->monto_orden_pago}, estado: {$opa1->id_estado_orden_pago}\n";
    echo "filas detalle: {$det1->count()}, factura_unida: " . $det1->first()->factura_unida . "\n";
    $ok1 = abs((float)$opa1->monto_orden_pago - (float)$facA->total_neto) < 0.01
        && $det1->count() === 1 && (int)$det1->first()->factura_unida === 0;
    echo $ok1 ? ">>> CASO 1 OK\n\n" : ">>> CASO 1 FALLA\n\n";

    echo "--- CASO 2: dos facturas SIN opa previa (agrupar desde cero) ---\n";
    echo "facturas {$facB->id_factura} ({$facB->total_neto}) y {$facC->id_factura} ({$facC->total_neto})\n";
    $opa2 = $repo->findByIdFacturaMultiple([$facB->id_factura, $facC->id_factura]);
    $det2 = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa2->id_orden_pago)->get();
    $sumaEsperada = (float)$facB->total_neto + (float)$facC->total_neto;
    echo "OPA creada: {$opa2->id_orden_pago}, monto: {$opa2->monto_orden_pago} (esperado {$sumaEsperada})\n";
    echo "filas detalle: {$det2->count()}, factura_unida: " . $det2->pluck('factura_unida')->implode(',') . "\n";
    $ok2 = abs((float)$opa2->monto_orden_pago - $sumaEsperada) < 0.01
        && $det2->count() === 2 && $det2->every(fn($d) => (int)$d->factura_unida === 1);
    echo $ok2 ? ">>> CASO 2 OK\n\n" : ">>> CASO 2 FALLA\n\n";

    echo "--- CASO 3: factura que YA tiene OPA (circuito proveedor, comportamiento viejo) ---\n";
    $facD = DB::table('tb_facturacion_datos')->whereNotNull('total_neto')->orderBy('id_factura')->skip(50)->first();
    $opaVieja = TesOrdenPagoEntity::create([
        'monto_orden_pago' => 999.00,
        'id_moneda' => 1, 'id_estado_orden_pago' => 1, 'monto_anticipado' => 0,
        'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => null, 'tipo_factura' => 'PROVEEDOR',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaVieja->id_orden_pago, 'id_factura' => $facD->id_factura,
        'monto_factura' => 999.00, 'tipo_factura' => 'PROVEEDOR', 'factura_unida' => 0,
    ]);
    echo "factura {$facD->id_factura} con OPA previa {$opaVieja->id_orden_pago} (monto 999.00)\n";
    $opa3 = $repo->findByIdFacturaMultiple([$facD->id_factura]);
    $opaViejaSigueViva = TesOrdenPagoEntity::find($opaVieja->id_orden_pago) !== null;
    echo "OPA nueva: {$opa3->id_orden_pago}, monto: {$opa3->monto_orden_pago} (esperado 999.00)\n";
    echo "OPA vieja sigue viva: " . ($opaViejaSigueViva ? 'SI (MAL)' : 'no (ok, se fusiono y borro)') . "\n";
    $ok3 = abs((float)$opa3->monto_orden_pago - 999.00) < 0.01 && !$opaViejaSigueViva;
    echo $ok3 ? ">>> CASO 3 OK\n\n" : ">>> CASO 3 FALLA\n\n";

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
    echo "(rollback hecho - no quedo nada en la base)\n";
}
