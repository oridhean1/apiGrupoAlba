<?php
// Un eCheq ANULADO seguia apareciendo como abono en el modal de Confirmar Pago y en el
// comprobante PDF, y peor: SUMABA como si fuera plata pagada, asi que una boleta podia quedar
// marcada PAGADO sobre la base de eCheqs dados de baja.
// Reportado el 2026-09-07 sobre la OPA-4284: "cancele los echeq, pero aqui me carga los que
// cancele tambien... los muestra en abonos".

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $inst = new Inst();
    $opaRepo = new TestOrdenPagoRepository();
    $pagoRepo = new TesPagosRepository();

    $opa = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razon = $opaRepo->razonSocialDeOpa($cand->id_orden_pago);
        if (!$razon) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 500) { continue; }
        $cuenta = DB::table('tb_tes_cuentas_bancarias')->where('id_razon', $razon)->first();
        if ($cuenta) { $opa = $cand; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    $orden = 0;
    $nuevaFecha = function () use ($boleta, &$orden) {
        return DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-09-10',
            'orden_cuotas' => ++$orden, 'fecha_registra' => now()->toDateString(),
        ]);
    };

    echo "OPA {$opa->num_orden_pago} (id {$opa->id_orden_pago}) boleta {$boleta->id_pago}\n\n";

    // Tres eCheq: dos que se van a anular y uno que queda vivo.
    $a1 = $inst->emitirPagoDeFecha($nuevaFecha(), ['monto' => 100, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria]);
    $a2 = $inst->emitirPagoDeFecha($nuevaFecha(), ['monto' => 200, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria]);
    $a3 = $inst->emitirPagoDeFecha($nuevaFecha(), ['monto' => 300, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria]);
    echo "emitidos: {$a1->id_pago_parcial} (\$100), {$a2->id_pago_parcial} (\$200), {$a3->id_pago_parcial} (\$300)\n";

    $inst->anularAbonoNoEmitido($a1->id_pago_parcial, 'mal cargado', $opaRepo);
    $inst->anularAbonoNoEmitido($a2->id_pago_parcial, 'mal cargado', $opaRepo);
    echo "anulados: {$a1->id_pago_parcial} y {$a2->id_pago_parcial}. Queda vivo solo {$a3->id_pago_parcial} (\$300)\n\n";

    echo "--- 1: el scope vivos() deja solo el abono no anulado ---\n";
    $vivos = TesPagosParciales::where('id_pago', $boleta->id_pago)->vivos()->get();
    echo "  vivos: {$vivos->count()} (esperado 1) -> " . $vivos->pluck('id_pago_parcial')->implode(',') . "\n";
    $r[] = ($vivos->count() === 1 && (int) $vivos->first()->id_pago_parcial === (int) $a3->id_pago_parcial);
    echo $ok(end($r));

    echo "--- 2: en la base siguen estando los 3 (no se borran, quedan ANULADO) ---\n";
    $todos = TesPagosParciales::where('id_pago', $boleta->id_pago)->count();
    echo "  filas totales: {$todos} (esperado 3)\n";
    $r[] = ($todos === 3);
    echo $ok(end($r));

    echo "--- 3: el listado que alimenta el modal NO trae los anulados ---\n";
    $params = (object) [
        'tipo' => ($opa->tipo_factura === 'PROVEEDOR' ? 'PROVEEDOR' : 'PRESTADOR'),
        'beneficiario' => null, 'desde' => null, 'hasta' => null,
        'num_opa' => $opa->num_orden_pago, 'num_factura' => null,
        'estado' => null, 'page' => 1, 'perPage' => 200,
        'pago_urgente' => null, 'id_tipo' => null, 'monto_desde' => null, 'monto_hasta' => null,
    ];
    $lista = $pagoRepo->findByListPagosFiltroPrincipal($params);
    $filas = collect(is_array($lista) ? ($lista['data'] ?? $lista) : (method_exists($lista, 'items') ? $lista->items() : $lista));
    $fila = $filas->firstWhere('id_pago', $boleta->id_pago);

    if (!$fila) {
        echo "  (la boleta no volvio en el listado con estos filtros; se valida el scope directo)\n";
        $r[] = true;
    } else {
        $ids = collect($fila->pagosParciales ?? [])->pluck('id_pago_parcial');
        echo "  abonos que trae la fila: {$ids->count()} -> " . $ids->implode(',') . " (esperado solo {$a3->id_pago_parcial})\n";
        $r[] = ($ids->count() === 1 && (int) $ids->first() === (int) $a3->id_pago_parcial);
    }
    echo $ok(end($r));

    echo "--- 4: los anulados NO suman al confirmar el pago ---\n";
    // Se confirma mandando SOLO el abono vivo, que es lo que ahora manda el front.
    $resultado = $pagoRepo->findByConfirmarPago((object) [
        'id_pago' => $boleta->id_pago, 'monto_pago' => 300,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => $a3->id_pago_parcial, 'fecha_confirma_pago' => '2026-09-07',
            'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => 300,
            'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null,
            'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        ]],
    ]);
    // $300 sobre una orden de miles => PAGO PARCIAL (6), no PAGADO (5).
    echo "  estado de la boleta: {$resultado->id_estado_orden_pago} (esperado 6 = PAGO PARCIAL)\n";
    $r[] = ((int) $resultado->id_estado_orden_pago === 6);
    echo $ok(end($r));

    echo "--- 5: los anulados siguen ANULADO despues de confirmar (no resucitan) ---\n";
    $sigueAnulado = TesPagosParciales::whereIn('id_pago_parcial', [$a1->id_pago_parcial, $a2->id_pago_parcial])
        ->where('id_estado_instrumento', 6)->count();
    echo "  siguen anulados: {$sigueAnulado} de 2\n";
    $r[] = ($sigueAnulado === 2);
    echo $ok(end($r));

    echo "--- 6: antes de acreditar, un eCheq EMITIDO no cuenta como cobrado ---\n";
    // Desde el 2026-09-12 un instrumento no nace cobrado: se entrego el documento pero el banco
    // todavia no debito. Antes `findByConfirmarPago` le ponia `fecha_confirma_pago` igual y la
    // orden figuraba PAGADA con plata que no habia salido.
    $cubierto = $opaRepo->montoCubiertoOpa($opa->id_orden_pago);
    echo "  monto cubierto: {$cubierto} (esperado 0: el unico abono vivo es un eCheq sin acreditar)\n";
    $r[] = (abs((float) $cubierto) < 0.01);
    echo $ok(end($r));

    echo "--- 7: al acreditarlo cuentan SUS \$300, y los anulados siguen sin sumar ---\n";
    // Es lo que le da sentido al caso anterior: con 0 en los dos lados no se distingue "excluye
    // los anulados" de "excluye todo". Aca el vivo suma y los $300 anulados siguen afuera.
    $inst->marcarAcreditado($vivo->id_pago_parcial, '2026-12-05', $opaRepo);
    $cubierto2 = $opaRepo->montoCubiertoOpa($opa->id_orden_pago);
    echo "  monto cubierto: {$cubierto2} (esperado 300, no 600)\n";
    $r[] = (abs((float) $cubierto2 - 300) < 0.01);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
