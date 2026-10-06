<?php
// Filtros de la pantalla Carga de eCheq: por N° de OPA y por razon social DE LA ENTIDAD PAGADORA
// (Grupo Alba, Tripalium, Medicina Privada, etc. via tb_facturacion_datos.id_locatorio), NO del
// proveedor/prestador beneficiario. Corregido el 2026-09-05 tras aclaracion del usuario: la
// primera version filtraba por beneficiario, que no es lo que se pidio.

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

    // Dos OPAs con facturas de id_locatorio (razon social) distinto, para poder discriminar.
    $filaA = DB::table('tb_tes_orden_pago_detalle as od')
        ->join('tb_facturacion_datos as fd', 'fd.id_factura', '=', 'od.id_factura')
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'od.id_orden_pago')
        ->whereNotNull('fd.id_locatorio')
        ->where('o.id_estado_orden_pago', 1)
        ->select('o.id_orden_pago', 'o.num_orden_pago', 'o.monto_orden_pago', 'o.tipo_factura', 'fd.id_locatorio')
        ->orderByDesc('o.id_orden_pago')
        ->first();

    $filaB = DB::table('tb_tes_orden_pago_detalle as od')
        ->join('tb_facturacion_datos as fd', 'fd.id_factura', '=', 'od.id_factura')
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'od.id_orden_pago')
        ->whereNotNull('fd.id_locatorio')
        ->where('fd.id_locatorio', '!=', $filaA->id_locatorio)
        ->where('o.id_estado_orden_pago', 1)
        ->select('o.id_orden_pago', 'o.num_orden_pago', 'o.monto_orden_pago', 'o.tipo_factura', 'fd.id_locatorio')
        ->orderByDesc('o.id_orden_pago')
        ->first();

    if (!$filaA || !$filaB) {
        echo "SIN DATOS SUFICIENTES (no hay dos OPAs con facturas de razon social distinta)\n";
        return;
    }

    $crearFechaSuelta = function ($fila) {
        $boleta = TesPagoEntity::create([
            'id_orden_pago' => $fila->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $fila->monto_orden_pago, 'monto_anticipado' => 0,
            'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
            'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
            'tipo_factura' => $fila->tipo_factura ?: 'PRESTADOR',
        ]);
        return DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'fecha_registra' => now(), 'fecha_probable_pago' => '2026-10-20',
            'orden_cuotas' => 1, 'id_pago' => $boleta->id_pago,
        ]);
    };

    $crearFechaSuelta($filaA);
    $crearFechaSuelta($filaB);

    $numeroA = preg_replace('/\D/', '', $filaA->num_orden_pago);

    echo "OPA A: {$filaA->num_orden_pago} (id_locatorio={$filaA->id_locatorio})\n";
    echo "OPA B: {$filaB->num_orden_pago} (id_locatorio={$filaB->id_locatorio})\n\n";

    echo "--- filtro por numero de OPA (formato con prefijo) ---\n";
    $res = $repo->listarPendientesDeNumero(null, 'OPA-' . $numeroA, null);
    $planA = $res['planificados']->where('id_orden_pago', $filaA->id_orden_pago);
    $planB = $res['planificados']->where('id_orden_pago', $filaB->id_orden_pago);
    $r[] = $planA->count() >= 1 && $planB->count() === 0;
    echo $ok(end($r));

    echo "--- filtro por id_razon: trae SOLO las ordenes de esa razon social ---\n";
    $resRazon = $repo->listarPendientesDeNumero(null, null, $filaA->id_locatorio);
    $r[] = $resRazon['planificados']->where('id_orden_pago', $filaA->id_orden_pago)->count() >= 1
        && $resRazon['planificados']->where('id_orden_pago', $filaB->id_orden_pago)->count() === 0;
    echo $ok(end($r));

    echo "--- filtro por la OTRA razon social: trae solo la B ---\n";
    $resRazonB = $repo->listarPendientesDeNumero(null, null, $filaB->id_locatorio);
    $r[] = $resRazonB['planificados']->where('id_orden_pago', $filaB->id_orden_pago)->count() >= 1
        && $resRazonB['planificados']->where('id_orden_pago', $filaA->id_orden_pago)->count() === 0;
    echo $ok(end($r));

    echo "--- id_razon inexistente -> vacio ---\n";
    $resNadie = $repo->listarPendientesDeNumero(null, null, 999999);
    $r[] = $resNadie['planificados']->count() === 0 && $resNadie['sin_numero']->count() === 0;
    echo $ok(end($r));

    echo "--- combinando numero de OPA (A) + id_razon de B -> vacio ---\n";
    $resCombo = $repo->listarPendientesDeNumero(null, $numeroA, $filaB->id_locatorio);
    $r[] = $resCombo['planificados']->count() === 0;
    echo $ok(end($r));

    echo "--- sin filtros: ambas aparecen ---\n";
    $resTodo = $repo->listarPendientesDeNumero();
    $r[] = $resTodo['planificados']->where('id_orden_pago', $filaA->id_orden_pago)->count() >= 1
        && $resTodo['planificados']->where('id_orden_pago', $filaB->id_orden_pago)->count() >= 1;
    echo $ok(end($r));

    echo "--- el mismo filtro aplica tambien a listarEmitidos() (no debe romper) ---\n";
    $repo->listarEmitidos(null, $numeroA, $filaA->id_locatorio);
    $r[] = true;
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
