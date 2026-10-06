<?php
// Detalle del anticipo (punto 5 del doc UX/UI): historial de aplicaciones y evolucion del saldo,
// con los nombres de situacion que acordo el area. (2026-10-01)
use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

// Dos facturas con saldo >= $1.000 del mismo prestador. Mismo criterio que la pantalla.
require __DIR__ . '/fixture_aplicables.php';
$fx = prestadorConAplicables(new Ant(app(TestOrdenPagoRepository::class)), 2, 1000);

if (!$fx) {
    echo "SE SALTEA: no hay un prestador con dos facturas aplicables en esta base
";
    echo "
=== 0/0 OK  (rollback hecho)
";
    return;
}

$prest = $fx['id_prestador'];
$facturas = collect($fx['facturas'])->take(2)->map(fn($f) => (object) $f)->values();

DB::beginTransaction();
try {
    $ant = new Ant(app(TestOrdenPagoRepository::class));
    $idRazon = $fx['id_razon'];  // la de las facturas: no se cruza entidades

    $a = $ant->crearAnticipo($prest, 'PRESTADOR', 1000, 'test detalle',
        [['fecha_probable_pago' => '2026-11-10']], $idRazon);

    echo "--- 1: recien creado -> SIN PAGAR, sin historial ---\n";
    $d = $ant->detalleAnticipo($a->id_orden_pago);
    echo "  situacion={$d['situacion']} historial=" . count($d['historial']) . "\n";
    $r[] = ($d['situacion'] === 'SIN PAGAR' && count($d['historial']) === 0);
    echo $ok(end($r));

    // Se paga por el circuito normal.
    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)->first();
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => now()->toDateString(),
        'id_forma_pago' => 1, 'monto_pago' => 1000, 'monto_opa' => 1000, 'id_usuario' => 1,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
    ]);

    echo "--- 2: pagado y sin aplicar -> DISPONIBLE ---\n";
    $d = $ant->detalleAnticipo($a->id_orden_pago);
    echo "  situacion={$d['situacion']} saldo={$d['saldo']}\n";
    $r[] = ($d['situacion'] === 'DISPONIBLE' && (float) $d['saldo'] === 1000.0);
    echo $ok(end($r));

    echo "--- 3: aplicado en parte -> PARCIALMENTE APLICADO, con su historial ---\n";
    $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $facturas[0]->id_factura, 'monto' => 300]]);
    $d = $ant->detalleAnticipo($a->id_orden_pago);
    echo "  situacion={$d['situacion']} aplicado={$d['aplicado']} saldo={$d['saldo']} historial=" . count($d['historial']) . "\n";
    $r[] = ($d['situacion'] === 'PARCIALMENTE APLICADO'
        && (float) $d['aplicado'] === 300.0 && (float) $d['saldo'] === 700.0
        && count($d['historial']) === 1
        && (float) $d['historial'][0]['saldo_posterior'] === 700.0);
    echo $ok(end($r));

    echo "--- 4: aplicado el resto -> CONSUMIDA, el saldo posterior baja factura por factura ---\n";
    $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $facturas[1]->id_factura, 'monto' => 700]]);
    $d = $ant->detalleAnticipo($a->id_orden_pago);
    $posteriores = array_map(fn($h) => (float) $h['saldo_posterior'], $d['historial']);
    echo "  situacion={$d['situacion']} saldos posteriores=" . implode(' -> ', $posteriores) . "\n";
    $r[] = ($d['situacion'] === 'CONSUMIDA' && $posteriores === [700.0, 0.0]);
    echo $ok(end($r));

    echo "--- 5: la evolucion arranca en lo pagado y tiene un punto por aplicacion ---\n";
    $evo = array_map(fn($e) => (float) $e['saldo'], $d['evolucion']);
    echo "  evolucion=" . implode(' -> ', $evo) . "\n";
    $r[] = ($evo === [1000.0, 700.0, 0.0]);
    echo $ok(end($r));

    echo "--- 6: consumido, igual aparece en la grilla (antes se excluia) ---\n";
    // Sin esto un anticipo agotado desaparecia y no habia forma de abrir su detalle.
    $enGrilla = collect($ant->listarAnticipos())->firstWhere('id_orden_pago', $a->id_orden_pago);
    $soloSaldo = collect($ant->listarAnticipos(null, null, true))->firstWhere('id_orden_pago', $a->id_orden_pago);
    echo "  en la grilla: " . ($enGrilla ? 'si' : 'NO') . " | con 'solo con saldo': " . ($soloSaldo ? 'si' : 'no') . "\n";
    $r[] = ($enGrilla && $enGrilla['situacion'] === 'CONSUMIDA' && !$soloSaldo);
    echo $ok(end($r));

    echo "--- 7: una orden que no es anticipo no tiene detalle ---\n";
    $normal = DB::table('tb_tes_orden_pago')->where('tipo_opa', 'NORMAL')->value('id_orden_pago');
    $corto = false;
    try { $ant->detalleAnticipo($normal); } catch (\Throwable $e) { $corto = true; }
    $r[] = $corto;
    echo "  corta: " . var_export($corto, true) . "\n";
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
