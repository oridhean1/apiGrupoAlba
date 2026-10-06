<?php
// Test: agrupar una factura que YA tiene una OPA PENDIENTE con otra sin OPA -> debe fusionar.
// Y una factura cuya OPA ya está APROBADA (aunque sin pagos) -> debe BLOQUEAR, no fusionar
// en silencio. Con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

// CASO A agrupa facA+facB: tienen que ser del mismo id_prestador para pasar la validación de
// "mismo beneficiario" agregada el 2026-08-13.
$parMismoPrestador = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
    ->where('id_prestador', function ($q) {
        $q->select('id_prestador')->from('tb_facturacion_datos')
            ->whereNotIn('id_factura', function ($q2) { $q2->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
            ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
            ->groupBy('id_prestador')->havingRaw('COUNT(*) >= 2')->orderBy('id_prestador')->limit(1);
    })
    ->orderBy('id_factura')->limit(2)->get();

if ($parMismoPrestador->count() < 2) { echo "SIN FACTURAS SUFICIENTES DEL MISMO PRESTADOR\n"; return; }
[$facA, $facB] = $parMismoPrestador->all();

$facC = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)
    ->whereNotIn('id_factura', [$facA->id_factura, $facB->id_factura])
    ->orderBy('id_factura')->skip(5)->first();

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- CASO A: factura con OPA PENDIENTE + factura sin OPA -> deben fusionarse ---\n";
    $opaPendiente = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $facA->total_neto, 'id_moneda' => 1, 'id_estado_orden_pago' => 1,
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facA->id_factura, 'tipo_factura' => 'PRESTADOR',
        'id_prestador' => $facA->id_prestador,
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaPendiente->id_orden_pago, 'id_factura' => $facA->id_factura,
        'monto_factura' => $facA->total_neto, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);
    echo "factura {$facA->id_factura} con OPA {$opaPendiente->id_orden_pago} PENDIENTE ({$facA->total_neto})\n";
    echo "+ factura {$facB->id_factura} sin OPA ({$facB->total_neto})\n";

    $esperado = (float) $facA->total_neto + (float) $facB->total_neto;
    $nueva = $repo->findByIdFacturaMultiple([$facA->id_factura, $facB->id_factura]);
    $viejaSigue = TesOrdenPagoEntity::find($opaPendiente->id_orden_pago);
    $det = TesOrdenPagoDetalleEntity::where('id_orden_pago', $nueva->id_orden_pago)->get();

    echo "OPA nueva {$nueva->id_orden_pago}, monto {$nueva->monto_orden_pago} (esperado {$esperado})\n";
    echo "OPA pendiente vieja sigue viva: " . ($viejaSigue ? 'SI (mal)' : 'no (ok, se fusiono)') . "\n";
    echo "filas detalle: {$det->count()} (esperado 2)\n";
    $okA = abs((float)$nueva->monto_orden_pago - $esperado) < 0.01 && !$viejaSigue && $det->count() === 2;
    echo ($okA ? ">>> CASO A OK\n\n" : ">>> CASO A FALLA\n\n");

    echo "--- CASO B: factura con OPA APROBADA (sin pagos) -> NO debe fusionarse en silencio ---\n";
    $opaAprobada = TesOrdenPagoEntity::create([
        'monto_orden_pago' => $facC->total_neto, 'id_moneda' => 1, 'id_estado_orden_pago' => 2, // APROBADO
        'monto_anticipado' => 0, 'cod_usuario' => $user->cod_usuario, 'fecha_genera' => now(),
        'fecha_emision' => now()->toDateString(), 'fecha_vencimiento' => now()->toDateString(),
        'id_factura' => $facC->id_factura, 'tipo_factura' => 'PRESTADOR',
    ]);
    TesOrdenPagoDetalleEntity::create([
        'id_orden_pago' => $opaAprobada->id_orden_pago, 'id_factura' => $facC->id_factura,
        'monto_factura' => $facC->total_neto, 'tipo_factura' => 'PRESTADOR', 'factura_unida' => 0,
    ]);
    echo "factura {$facC->id_factura} con OPA {$opaAprobada->id_orden_pago} APROBADA, sin pagos\n";
    try {
        $repo->findByIdFacturaMultiple([$facC->id_factura]);
        echo ">>> CASO B FALLA (fusiono/regenero una OPA ya aprobada)\n";
    } catch (\Throwable $e) {
        echo "bloqueado: " . $e->getMessage() . "\n";
        echo ">>> CASO B OK\n";
    }

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
