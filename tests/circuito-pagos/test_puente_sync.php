<?php
// La tabla puente tiene que quedar alineada con el detalle despues de CADA operacion,
// y los borrados de OPA no deben chocar contra la FK RESTRICT. Con rollback.

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::first();
Auth::login($user);

// Facturas del MISMO prestador, sin OPA (la validacion de beneficiario lo exige)
$par = DB::table('tb_facturacion_datos')
    ->whereNotIn('id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
    ->where('id_prestador', function ($q) {
        $q->select('id_prestador')->from('tb_facturacion_datos')
          ->whereNotIn('id_factura', function ($q2) { $q2->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
          ->whereNotNull('total_neto')->where('total_neto', '>', 0)->whereNotNull('id_prestador')
          ->groupBy('id_prestador')->havingRaw('COUNT(*) >= 2')->orderBy('id_prestador')->limit(1);
    })
    ->orderBy('id_factura')->limit(2)->get();

if ($par->count() < 2) { echo "SIN DATOS\n"; return; }
[$facA, $facB] = $par->all();

function alineada($idOpa) {
    $det = TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOpa)
        ->orderBy('id_factura')->pluck('monto_factura', 'id_factura')->toArray();
    $pue = TesFacturasOpaEntity::where('id_orden_pago', $idOpa)
        ->orderBy('id_factura')->pluck('monto_aplicado', 'id_factura')->toArray();
    return [$det, $pue, array_keys($det) == array_keys($pue)
        && array_map('floatval', array_values($det)) == array_map('floatval', array_values($pue))];
}

DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();

    echo "--- A: generar OPA con UNA factura -> puente alineada ---\n";
    $opa1 = $repo->findByIdFacturaMultiple([$facA->id_factura]);
    [$d, $p, $ok] = alineada($opa1->id_orden_pago);
    echo "  detalle: " . json_encode($d) . "\n  puente : " . json_encode($p) . "\n";
    echo "  estado derivado: {$opa1->refresh()->id_estado_orden_pago}\n";
    echo ($ok ? ">>> A OK\n\n" : ">>> A FALLA\n\n");

    echo "--- B: agrupar la segunda factura (borra la OPA origen -> prueba la FK) ---\n";
    $opa2 = $repo->findByIdFacturaMultiple([$facB->id_factura]);
    $res = $repo->findAddFacturaMultiple((object)[
        'idFactura' => $facB->id_factura,
        'idOrdenPago' => $opa1->id_orden_pago,
        'idOrdenPagoOrigen' => $opa2->id_orden_pago,
    ]);
    echo "  resultado: " . json_encode($res['success']) . " - {$res['message']}\n";
    $viveOrigen = TesOrdenPagoEntity::find($opa2->id_orden_pago) !== null;
    $puenteOrigen = TesFacturasOpaEntity::where('id_orden_pago', $opa2->id_orden_pago)->count();
    echo "  OPA origen borrada: " . var_export(!$viveOrigen, true) . " | filas puente que quedaron: {$puenteOrigen}\n";
    [$d, $p, $ok] = alineada($opa1->id_orden_pago);
    echo "  detalle destino: " . json_encode($d) . "\n  puente  destino: " . json_encode($p) . "\n";
    $okB = $res['success'] && !$viveOrigen && $puenteOrigen === 0 && $ok;
    echo ($okB ? ">>> B OK\n\n" : ">>> B FALLA\n\n");

    echo "--- C: desagrupar -> la puente del destino se achica, y la nueva OPA nace alineada ---\n";
    $res2 = $repo->findRemoveFacturaMultiple((object)[
        'idFactura' => $facB->id_factura,
        'idOrdenPago' => $opa1->id_orden_pago,
    ]);
    [$d, $p, $ok] = alineada($opa1->id_orden_pago);
    echo "  detalle origen: " . json_encode($d) . "\n  puente  origen: " . json_encode($p) . "\n";
    $nueva = TesOrdenPagoDetalleEntity::where('id_factura', $facB->id_factura)
        ->where('id_orden_pago', '<>', $opa1->id_orden_pago)->first();
    $okC = $ok;
    if ($nueva) {
        [$d2, $p2, $ok2] = alineada($nueva->id_orden_pago);
        echo "  OPA nueva {$nueva->id_orden_pago} -> detalle: " . json_encode($d2) . " puente: " . json_encode($p2) . "\n";
        $okC = $ok && $ok2;
    }
    echo ($okC ? ">>> C OK\n\n" : ">>> C FALLA\n\n");

    echo "--- D: no quedan filas de puente sin su OPA (huerfanas) ---\n";
    $huerfanas = DB::selectOne('SELECT COUNT(*) n FROM tb_tes_opa_factura pf
        LEFT JOIN tb_tes_orden_pago o ON o.id_orden_pago = pf.id_orden_pago
        WHERE o.id_orden_pago IS NULL');
    echo "  huerfanas: {$huerfanas->n} (esperado 0)\n";
    echo ($huerfanas->n == 0 ? ">>> D OK\n" : ">>> D FALLA\n");

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n(rollback hecho)\n";
}
