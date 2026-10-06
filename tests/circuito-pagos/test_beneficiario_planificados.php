<?php
// Bug real hallado el 2026-09-05 probando "A emitir" a mano: el beneficiario salia vacio.
// Causa: listarPendientesDeNumero()['planificados'] es una query cruda (DB::table) sin el
// join a proveedor/prestador que el front necesita para nombreBeneficiario(). Se agrego el
// leftJoin y se anida en objetos `proveedor`/`prestador` con la misma forma que usa el resto
// de los listados de esta pantalla.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $repo = new TesInstrumentoPagoRepository();

    $opa = TesOrdenPagoEntity::whereNotNull('id_prestador')->whereNull('id_proveedor')
        ->whereHas('prestador')->where('id_estado_orden_pago', 1)
        ->orderByDesc('id_orden_pago')->first();

    if (!$opa) { echo "SIN DATOS\n"; return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);
    DB::table('tb_tes_fecha_probable_pago')->insert([
        'fecha_registra' => now(), 'fecha_probable_pago' => '2026-10-25',
        'orden_cuotas' => 1, 'id_pago' => $boleta->id_pago,
    ]);

    $res = $repo->listarPendientesDeNumero();
    $fila = $res['planificados']->where('id_orden_pago', $opa->id_orden_pago)->first();

    if (!$fila) { echo "no aparecio la fila planificada\n"; $r[] = false; }
    else {
        $razonEsperada = trim($opa->prestador->razon_social ?? '');
        echo "esperado: {$razonEsperada}\n";
        echo "prestador->razon_social en la fila: " . ($fila->prestador->razon_social ?? 'NULL') . "\n";
        echo "prestador->cuit en la fila: " . ($fila->prestador->cuit ?? 'NULL') . "\n";
        echo "proveedor en la fila (debe ser null): " . var_export($fila->proveedor, true) . "\n";

        $r[] = ($fila->prestador->razon_social ?? '') === $razonEsperada;
        echo $ok(end($r));

        $r[] = is_null($fila->proveedor);
        echo $ok(end($r));
    }
} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
