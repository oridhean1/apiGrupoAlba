<?php
// Factura con un anticipo aplicado: Generar OPA tiene que ofrecer SOLO el resto, y rechazar una
// OPA por el total. Reportado en OSV (2026-10-13): factura 441 de $99.999, anticipo de $61.024,60
// aplicado (OPA-20261013-14926), y la OPA "por el resto" (OPA-20261013-14927) salió por $99.999.
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\FacturasOpaRepository;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

require_once __DIR__ . '/fixture_aplicables.php';
$fx = prestadorConAplicables(new Ant(app(TestOrdenPagoRepository::class)), 1, 1000);

if (!$fx) {
    echo "SE SALTEA: no hay una factura con saldo aplicable suficiente\n";
    return;
}

$fac = (object) array_merge($fx['facturas'][0], ['id_prestador' => $fx['id_prestador']]);
$saldoInicial = (float) $fac->saldo;
$aplicado = round($saldoInicial * 0.6, 2);

DB::beginTransaction();
try {
    $ant = new Ant(app(TestOrdenPagoRepository::class));
    $a = $ant->crearAnticipo($fac->id_prestador, 'PRESTADOR', $aplicado, 'test resto',
        [['fecha_probable_pago' => '2026-11-10']], $fx['id_razon']);
    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)->first();
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => now()->toDateString(),
        'id_forma_pago' => 1, 'monto_pago' => $aplicado, 'monto_opa' => $aplicado, 'id_usuario' => 1,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
    ]);
    app(TestOrdenPagoRepository::class)->recalcularEstadoOpa($a->id_orden_pago);
    $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $fac->id_factura, 'monto' => $aplicado]]);
    echo "  factura {$fac->id_factura}: saldo inicial {$saldoInicial}, anticipo aplicado {$aplicado}\n";

    echo "--- 1: Generar OPA muestra el resto ---\n";
    $gen = (new FacturasOpaRepository())->listarFacturasParaOpa((object) [
        'tipo' => 'PRESTADOR', 'id_prestador' => $fac->id_prestador, 'per_page' => 500]);
    $row = collect($gen['data'])->firstWhere('id_factura', $fac->id_factura);
    $resto = round($saldoInicial - $aplicado, 2);
    echo "  saldo en Generar OPA: " . ($row->saldo_pendiente ?? 'no aparece') . " (esperado {$resto})\n";
    $r[] = $row && abs($row->saldo_pendiente - $resto) < 0.01;
    echo $ok(end($r));

    echo "--- 2: una OPA por el TOTAL de la factura corta ---\n";
    $corto = false;
    try {
        app(TestOrdenPagoRepository::class)->procesarOpaAgrupada((object) [
            'facturas' => [['id_factura' => $fac->id_factura, 'monto_aplicado' => $saldoInicial]],
            'cuotas' => [['fecha_probable_pago' => '2026-11-20']],
        ]);
    } catch (\Throwable $e) { $corto = str_contains($e->getMessage(), 'supera su saldo'); echo "  {$e->getMessage()}\n"; }
    $r[] = $corto;
    echo $ok(end($r));

    echo "--- 3: una OPA por el resto anda ---\n";
    $o = app(TestOrdenPagoRepository::class)->procesarOpaAgrupada((object) [
        'facturas' => [['id_factura' => $fac->id_factura, 'monto_aplicado' => $resto]],
        'cuotas' => [['fecha_probable_pago' => '2026-11-20']],
    ]);
    $pagable = app(TestOrdenPagoRepository::class)->montoPagableOpa($o->id_orden_pago);
    echo "  {$o->num_orden_pago}: monto {$o->monto_orden_pago}, pagable {$pagable}\n";
    $r[] = abs($pagable - $resto) < 0.01;
    echo $ok(end($r));
} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
