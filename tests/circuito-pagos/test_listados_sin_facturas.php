<?php
// Las ordenes SIN facturas (los ANTICIPOS) tienen que aparecer en los listados igual que el resto.
//
// Los filtros del circuito derivan casi todo de las facturas de la OPA, y un anticipo no tiene
// ninguna: cada vez que un filtro usa `whereHas('opadetalle...')` sin contemplar ese caso, la
// orden desaparece de la pantalla. Reportado sobre la OPA-16555, que existia y estaba pagada pero
// no salia en el Gestor al filtrar por tipo. (2026-09-25)
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

$paramsOpa = fn(array $x = []) => (object) array_merge(array_fill_keys([
    'tipo', 'estado', 'monto_desde', 'monto_hasta', 'fecha_desde', 'fecha_hasta', 'beneficiario',
    'num_opa', 'num_factura', 'id_razon', 'urgentes', 'imputacion', 'razon_social',
    'imputacion_contable', 'id_tipo_imputacion', 'pago_urgente', 'n_factura', 'desde', 'hasta',
    'id_locatorio',
], null), $x);

DB::beginTransaction();
try {
    $opaRepo = app(TestOrdenPagoRepository::class);
    $ant     = new Ant($opaRepo);

    $benef   = DB::table('tb_prestador')->whereNotNull('cuit')
        ->where('razon_social', '!=', 'Sin identificar')->first();
    $idRazon = DB::table('tb_razones_sociales')->value('id_razon');

    $a = $ant->crearAnticipo($benef->cod_prestador, 'PRESTADOR', 250000, 'test listados',
        [['fecha_probable_pago' => '2026-12-15']], $idRazon);
    $a->refresh();
    echo "anticipo de prueba: {$a->num_orden_pago} (PRESTADOR, sin facturas)\n";

    $enGestor = fn($tipo) => collect($opaRepo->getFiltroDinamico($paramsOpa(['tipo' => $tipo])))
        ->contains('num_orden_pago', $a->num_orden_pago);

    echo "--- 1: sin filtro de tipo aparece en el Gestor ---\n";
    $r[] = $enGestor(null);
    echo "  aparece: " . var_export(end($r), true) . "\n";
    echo $ok(end($r));

    echo "--- 2: filtrando por PRESTADOR tambien aparece ---\n";
    // Este era el bug: el filtro hacia whereHas('opadetalle.detallefc') y un anticipo no tiene
    // detalle, asi que se caia del listado justo al filtrar.
    $r[] = $enGestor('PRESTADOR');
    echo "  aparece: " . var_export(end($r), true) . "\n";
    echo $ok(end($r));

    echo "--- 3: filtrando por PROVEEDOR NO aparece (es de prestador) ---\n";
    $r[] = !$enGestor('PROVEEDOR');
    echo "  no aparece: " . var_export(end($r), true) . "\n";
    echo $ok(end($r));

    echo "--- 4: el filtro por tipo no duplica filas ---\n";
    $pre = collect($opaRepo->getFiltroDinamico($paramsOpa(['tipo' => 'PRESTADOR'])));
    $r[] = ($pre->count() === $pre->pluck('id_orden_pago')->unique()->count());
    echo "  filas={$pre->count()} ids unicos=" . $pre->pluck('id_orden_pago')->unique()->count() . "\n";
    echo $ok(end($r));

    echo "--- 5: su boleta aparece en el listado de Pagos ---\n";
    $paramsPago = (object) array_merge(array_fill_keys([
        'tipo', 'estado', 'desde', 'hasta', 'beneficiario', 'numero', 'numero_opa', 'id_locatario',
        'id_tipo_imputacion', 'pago_urgente', 'n_factura', 'id_razon', 'id_tipo',
    ], null), ['tipo' => 'PRESTADOR']);

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)->value('id_pago');
    $enPagos = collect(app(TesPagosRepository::class)->findByListPagosFiltroPrincipal($paramsPago));
    $r[] = $enPagos->contains('id_pago', $boleta);
    echo "  boleta {$boleta} en el listado: " . var_export(end($r), true) . "\n";
    echo $ok(end($r));

    echo "--- 6: el listado de Pagos sale ordenado por fecha de pago ---\n";
    // Ordenaba por tb_tes_pago.fecha_probable_pago, que en las boletas del circuito nuevo viene
    // siempre NULL: el orden no ordenaba y lo recien cargado caia al fondo.
    $fechas = $enPagos->map(function ($b) {
        return DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $b->id_pago)
            ->min('fecha_probable_pago')
            ?? $b->fecha_probable_pago
            ?? substr((string) $b->fecha_registra, 0, 10);
    })->values()->all();

    $ordenadas = $fechas;
    sort($ordenadas);
    echo "  primera={$fechas[0]} ultima=" . end($fechas) . "\n";
    $r[] = ($fechas === $ordenadas);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
