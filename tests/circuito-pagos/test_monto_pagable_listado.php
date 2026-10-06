<?php
// El modal de Confirmar Pago proponia como "Monto a pagar" el bruto guardado en la boleta
// (`pago.monto_pago`): sin descontar el debito de liquidacion ni lo ya abonado. El operador tenia
// que corregirlo a mano y, si no lo hacia, el backend rebotaba el pago por pasarse del tope.
//
// Ademas el front calculaba lo pagable por su cuenta como `monto_orden_pago - debito`, que falla
// por dos lados: la cabecera puede estar desincronizada con lo realmente imputado (OPA-1206:
// $2.266.110,16 de cabecera contra $2.214.425,55 de facturas) y no aplica el tope por factura.
// Ahora el listado manda `monto_pagable`, el MISMO numero que usa el freno de sobrepago.
// Reportado el 2026-09-09.

use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $repo = new TesPagosRepository();
    $opaRepo = new TestOrdenPagoRepository();

    $params = (object) [
        'tipo' => 'PRESTADOR', 'beneficiario' => null, 'desde' => null, 'hasta' => null,
        'num_opa' => null, 'numero' => null, 'estado' => null,
        'pago_urgente' => null, 'id_tipo' => null, 'id_tipo_imputacion' => null,
        'monto_desde' => null, 'monto_hasta' => null,
    ];

    $lista = collect($repo->findByListPagosFiltroPrincipal($params));
    echo "boletas en el listado: {$lista->count()}\n\n";

    if ($lista->isEmpty()) { echo "SIN DATOS\n"; DB::rollBack(); return; }

    echo "--- 1: todas las boletas traen monto_pagable ---\n";
    $sin = $lista->filter(fn($b) => is_null($b->monto_pagable ?? null))->count();
    echo "  sin monto_pagable: {$sin} de {$lista->count()}\n";
    $r[] = ($sin === 0);
    echo $ok(end($r));

    echo "--- 2: coincide EXACTAMENTE con el tope del freno de sobrepago ---\n";
    // Es la condicion que importa: si difieren, la pantalla propone algo que el backend rechaza.
    $distintos = 0; $muestra = [];
    foreach ($lista as $b) {
        $tope = $opaRepo->montoPagableOpa($b->id_orden_pago);
        if ($tope <= 0) { $tope = (float) $b->monto_opa; }
        if (abs((float) $b->monto_pagable - $tope) > 0.01) {
            $distintos++;
            if (count($muestra) < 5) {
                $muestra[] = "  boleta {$b->id_pago} (OPA {$b->id_orden_pago}): listado="
                    . number_format((float) $b->monto_pagable, 2) . " tope=" . number_format($tope, 2);
            }
        }
    }
    foreach ($muestra as $m) { echo $m . "\n"; }
    echo "  boletas donde difiere: {$distintos} de {$lista->count()}\n";
    $r[] = ($distintos === 0);
    echo $ok(end($r));

    echo "--- 3: el pagable NO es el bruto de la boleta cuando hay debito ---\n";
    // Si fueran siempre iguales, el arreglo no estaria haciendo nada.
    $conDebito = $lista->filter(fn($b) => abs((float) $b->monto_pagable - (float) $b->monto_opa) > 0.01);
    echo "  boletas donde pagable != monto_opa: {$conDebito->count()}\n";
    foreach ($conDebito->take(3) as $b) {
        echo "    boleta {$b->id_pago}: monto_opa=" . number_format((float) $b->monto_opa, 2)
            . " pagable=" . number_format((float) $b->monto_pagable, 2) . "\n";
    }
    $r[] = true; // informativo
    echo $ok(end($r));

    echo "--- 4: un ANTICIPO (sin facturas) cae a su propio monto ---\n";
    $anticipos = $lista->filter(function ($b) {
        return DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $b->id_orden_pago)->count() === 0;
    });
    if ($anticipos->isEmpty()) {
        echo "  (no hay anticipos en este listado, se saltea)\n";
        $r[] = true;
    } else {
        $malos = $anticipos->filter(fn($b) => abs((float) $b->monto_pagable - (float) $b->monto_opa) > 0.01)->count();
        echo "  anticipos: {$anticipos->count()} | con pagable != monto_opa: {$malos}\n";
        $r[] = ($malos === 0);
    }
    echo $ok(end($r));

    echo "--- 5: el caso concreto que motivo el fix (cabecera desincronizada) ---\n";
    $b = DB::table('tb_tes_pago')->where('id_orden_pago', 3633)->first();
    if ($b) {
        $o = DB::table('tb_tes_orden_pago')->where('id_orden_pago', 3633)->first();
        $deb = DB::table('tb_tes_orden_pago_detalle as od')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'od.id_factura')
            ->where('od.id_orden_pago', 3633)->sum('f.total_debitado_liquidacion');
        $formulaVieja = round((float) $o->monto_orden_pago - (float) $deb, 2);
        $tope = $opaRepo->montoPagableOpa(3633);
        echo "  formula vieja del front: " . number_format($formulaVieja, 2) . "\n";
        echo "  tope real del backend:   " . number_format($tope, 2) . "\n";
        echo "  la vieja se pasaba por:  " . number_format($formulaVieja - $tope, 2) . "\n";
        $r[] = ($formulaVieja > $tope + 0.01);
    } else {
        echo "  (la OPA 3633 no tiene boleta en esta base, se saltea)\n";
        $r[] = true;
    }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
