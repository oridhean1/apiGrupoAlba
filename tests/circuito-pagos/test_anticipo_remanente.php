<?php
// Una factura aplicada A MEDIAS tiene que poder recibir el remanente en otra aplicacion.
//
// Antes, una factura que estuviera en cualquier OPA viva quedaba afuera del listado y la guarda la
// rechazaba. La propia aplicacion del anticipo es una OPA viva, asi que una factura aplicada a
// medias quedaba bloqueada para siempre. Reportado sobre la factura 123 de ZENTRUM: $1.000, $500
// aplicados, y no volvia a aparecer. (2026-10-01)
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

// Una factura con al menos $1.000 aplicables. Mismo criterio que la pantalla.
require __DIR__ . '/fixture_aplicables.php';
$fx = prestadorConAplicables(new Ant(app(TestOrdenPagoRepository::class)), 1, 1000);

if (!$fx) {
    echo "SE SALTEA: no hay una factura con saldo aplicable suficiente
";
    echo "
=== 0/0 OK  (rollback hecho)
";
    return;
}

$fac = (object) array_merge($fx['facturas'][0], ['id_prestador' => $fx['id_prestador']]);
$saldoInicial = (float) $fac->saldo;

DB::beginTransaction();
try {
    $ant = new Ant(app(TestOrdenPagoRepository::class));
    $montoAnt = $saldoInicial + 1000;  // alcanza para la factura entera y sobra
    $a = $ant->crearAnticipo($fac->id_prestador, 'PRESTADOR', $montoAnt, 'test remanente',
        [['fecha_probable_pago' => '2026-11-10']], $fx['id_razon']);

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)->first();
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => now()->toDateString(),
        'id_forma_pago' => 1, 'monto_pago' => $montoAnt, 'monto_opa' => $montoAnt, 'id_usuario' => 1,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
    ]);

    $pagable = $saldoInicial;  // lo que le queda a la factura antes de esta prueba
    $mitad = 400.0;
    $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $fac->id_factura, 'monto' => $mitad]]);

    $fila = fn() => collect($ant->facturasAplicables($fac->id_prestador, 'PRESTADOR'))
        ->firstWhere('id_factura', $fac->id_factura);

    echo "--- 1: aplicada a medias, SIGUE en el listado con su remanente ---\n";
    $f = $fila();
    echo "  " . ($f ? "aparece: ya_imputado={$f['ya_imputado']} saldo={$f['saldo']}" : 'NO aparece') . "\n";
    $r[] = ($f && abs($f['saldo'] - ($pagable - $mitad)) < 0.01);
    echo $ok(end($r));

    echo "--- 2: aplicar MAS que el remanente corta ---\n";
    $corto = false;
    try { $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $fac->id_factura, 'monto' => $pagable]]); }
    catch (\Throwable $e) { $corto = str_contains($e->getMessage(), 'le quedan'); echo "  {$e->getMessage()}\n"; }
    $r[] = $corto;
    echo $ok(end($r));

    echo "--- 3: dos lineas a la misma factura se suman contra el remanente ---\n";
    $corto = false;
    $rem = round($pagable - $mitad, 2);
    try {
        $ant->aplicarAFacturas($a->id_orden_pago, [
            ['id_factura' => $fac->id_factura, 'monto' => $rem],
            ['id_factura' => $fac->id_factura, 'monto' => 1],
        ]);
    } catch (\Throwable $e) { $corto = str_contains($e->getMessage(), 'le quedan'); }
    echo "  corta: " . var_export($corto, true) . "\n";
    $r[] = $corto;
    echo $ok(end($r));

    echo "--- 4: aplicar justo el remanente anda, y la factura sale del listado ---\n";
    $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $fac->id_factura, 'monto' => $rem]]);
    $quedaSaldo = false;
    $f = $fila();
    echo "  despues: " . ($f ? "sigue con saldo {$f['saldo']}" : 'ya no aparece') . "\n";
    $r[] = $quedaSaldo ? (bool) $f : !$f;
    echo $ok(end($r));

    echo "--- 5: una factura ya cubierta por una OPA normal sigue bloqueada ---\n";
    // La proteccion que importaba: no pagar dos veces la misma factura.
    $cubierta = DB::table('tb_tes_orden_pago_detalle as d')
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'd.id_orden_pago')
        ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'd.id_factura')
        ->where('o.tipo_opa', 'NORMAL')->whereNotIn('o.id_estado_orden_pago', [3])
        ->where('f.estado', 3)->where('f.id_prestador', $fac->id_prestador)
        ->value('d.id_factura');
    if ($cubierta) {
        $corto = false;
        try { $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $cubierta, 'monto' => 1]]); }
        catch (\Throwable $e) { $corto = true; }
        echo "  corta: " . var_export($corto, true) . "\n";
        $r[] = $corto;
    } else {
        echo "  (el prestador no tiene facturas en OPAs normales: no aplica)\n";
        $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 6: una factura de OTRA razon social corta (regla de CLAUDE.md) ---\n";
    // La plata de una entidad no paga deuda de otra. Caso real: un anticipo de GRUPO ALBA se aplico a
    // la factura 126 de MEDICINA del mismo prestador.
    $ajena = DB::table('tb_facturacion_datos')->where('estado', 3)->where('id_tipo_factura', '!=', 16)
        ->whereNotNull('id_locatorio')->where('id_locatorio', '!=', $fx['id_razon'])->value('id_factura');
    if ($ajena) {
        $corto = false;
        try { $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $ajena, 'monto' => 1]]); }
        catch (\Throwable $e) { $corto = str_contains($e->getMessage(), 'otra razón social'); echo "  {$e->getMessage()}\n"; }
        $r[] = $corto;
        $listada = collect($ant->facturasAplicablesDeAnticipo($a->id_orden_pago))
            ->pluck('id_factura')->contains($ajena);
        echo "  la ofrece el listado: " . var_export($listada, true) . " (esperado false)\n";
        $r[] = !$listada;
    } else { echo "  (no hay facturas de otra razon en esta base)\n"; $r[] = true; }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
