<?php
// Test: agrupar facturas de DOS prestadores distintos debe bloquear, aunque el frontend
// no lo valide (esto simula un request directo al endpoint). Con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

// Dos facturas sin OPA, de prestadores distintos (si existen)
$facturas = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) {
        $q->select('id_factura')->from('tb_tes_orden_pago_detalle');
    })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)
    ->whereNotNull('id_prestador')
    ->orderBy('id_prestador')
    ->limit(200)
    ->get(['id_factura', 'id_prestador', 'total_neto']);

$porPrestador = $facturas->groupBy('id_prestador');
if ($porPrestador->count() < 2) { echo "SIN DATOS SUFICIENTES\n"; return; }

$facA = $porPrestador->values()[0]->first();
$facB = $porPrestador->values()[1]->first();

echo "factura {$facA->id_factura} (prestador {$facA->id_prestador}) + factura {$facB->id_factura} (prestador {$facB->id_prestador})\n\n";

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- CASO: dos prestadores distintos -> debe bloquear ---\n";
    try {
        $repo->findByIdFacturaMultiple([$facA->id_factura, $facB->id_factura]);
        echo ">>> FALLA (dejo agrupar dos prestadores distintos)\n";
    } catch (\Throwable $e) {
        echo "bloqueado: " . $e->getMessage() . "\n";
        echo ">>> OK\n";
    }

    echo "\n--- CASO: mismo prestador (dos facturas del mismo) -> debe permitir ---\n";
    $dosDelMismo = $porPrestador->values()[0];
    if ($dosDelMismo->count() < 2) {
        echo "SIN 2 FACTURAS DEL MISMO PRESTADOR PARA PROBAR\n";
    } else {
        $f1 = $dosDelMismo[0];
        $f2 = $dosDelMismo[1];
        $esperado = (float) $f1->total_neto + (float) $f2->total_neto;
        $opa = $repo->findByIdFacturaMultiple([$f1->id_factura, $f2->id_factura]);
        echo "OPA {$opa->id_orden_pago}, monto {$opa->monto_orden_pago} (esperado {$esperado})\n";
        echo (abs((float)$opa->monto_orden_pago - $esperado) < 0.01 ? ">>> OK\n" : ">>> FALLA\n");
    }

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
