<?php
// Confirmar la OP define SOLO el cronograma (fecha + cuota).
// El monto y la forma de pago se definen al EMITIR cada pago.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Tesoreria\Services\TesPagosController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$FORMA_TRANSFERENCIA = 1;

$r = [];
$opaRepo = new TestOrdenPagoRepository();
$inst = new Inst();
$ctrl = app(TesPagosController::class);
// La OPA y la cuenta tienen que ser de la MISMA razon social: emitir desde la cuenta de otra
// entidad del grupo esta prohibido (`validarCuentaDeRazonSocial`, 2026-09-07). Tomar la primera
// cuenta activa sin mirar la orden hacia que el fixture fallara apenas la OPA elegida cambiaba
// de razon al moverse los datos.
$opaRepoSel = new TestOrdenPagoRepository();
$opa = null; $cuenta = null;

foreach (
    TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->whereHas('opadetalle')->whereDoesntHave('pagos')
        ->orderByDesc('id_orden_pago')->limit(200)->get() as $cand
) {
    $rz = $opaRepoSel->razonesSocialesDeOpa($cand->id_orden_pago);
    if (empty($rz)) { continue; }

    $c = DB::table('tb_tes_cuentas_bancarias')->where('activo', 1)
        ->whereNotNull('id_entidad_bancaria')->whereIn('id_razon', $rz)->first();

    if ($c) { $opa = $cand; $cuenta = $c; break; }
}


if (!$opa) { echo "SIN OPA LIBRE\n"; return; }

DB::beginTransaction();
try {
    $total = (float) $opa->monto_orden_pago;
    echo "OPA {$opa->num_orden_pago} monto " . number_format($total, 2, ',', '.') . "\n\n";

    // ── 1) Confirmar: SOLO fechas ────────────────────────────────────────
    echo "--- 1: confirmar la OP -> solo cronograma, sin montos ni formas ---\n";
    $ctrl->getCrearPago(
        Request::create('/t', 'POST', [[
            'id_pago' => '', 'id_orden_pago' => $opa->id_orden_pago,
            'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
            'fecha_registra' => date('Y-m-d'), 'anticipo' => '0', 'comprobante' => '',
            'monto_pago' => $total, 'id_forma_pago' => '1', 'observaciones' => '',
            'id_estado_orden_pago' => '1', 'monto_opa' => $total, 'recursor' => '0',
            'num_cheque' => '', 'fecha_confirma_pago' => null,
            'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR', 'pago_emergencia' => false,
            'cuotas' => [
                ['orden_cuotas' => 1, 'fecha_probable_pago' => '2026-10-01'],
                ['orden_cuotas' => 2, 'fecha_probable_pago' => '2026-11-01'],
            ],
        ]]),
        app(TesPagosRepository::class), $opaRepo,
        app(App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository::class),
        app(App\Http\Controllers\Utils\GeneradorCodigosUtils::class)
    );

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_pago');
    $fechas = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $boleta)->count();
    $abonos = TesPagosParciales::where('id_pago', $boleta)->count();
    echo "  fechas planificadas: {$fechas} | pagos emitidos: {$abonos} (esperado 2 y 0)\n";
    $r['crea el cronograma'] = ($fechas === 2);
    $r['no emite pagos todavia'] = ($abonos === 0);

    // Corregido el 2026-09-05: confirmar dejaba la orden en PENDIENTE para siempre, aunque ya
    // tuviera boleta. El boton "Confirmar OPA" invitaba a un segundo click que chocaba con
    // "ya tiene un pago generado". Ahora pasa a EN_PROCESO(4), el estado del catalogo que
    // existia justo para esto.
    $estadoOpa = (int) TesOrdenPagoEntity::find($opa->id_orden_pago)->id_estado_orden_pago;
    echo "  estado de la OPA tras confirmar: {$estadoOpa} (esperado 4 = EN PROCESO)\n";
    $r['pasa a EN PROCESO'] = ($estadoOpa === TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO);

    // ── 2) Lo pendiente de emitir ────────────────────────────────────────
    echo "\n--- 2: quedan las dos fechas esperando que se emita el pago ---\n";
    $pend = $inst->fechasPendientesDeEmitir($opa->id_orden_pago);
    foreach ($pend as $f) { echo "    cuota {$f->orden_cuotas}: {$f->fecha_probable_pago}\n"; }
    $r['dos fechas pendientes'] = ($pend->count() === 2);

    $lista = $inst->listarPendientesDeNumero();
    $planif = collect($lista['planificados'])->where('id_orden_pago', $opa->id_orden_pago);
    echo "  en el listado de la pantalla: " . $planif->count() . " planificados, "
        . collect($lista['sin_numero'])->where('id_orden_pago', $opa->id_orden_pago)->count() . " sin numero\n";
    $r['listado muestra el plan'] = ($planif->count() === 2);

    // ── 3) Emitir el primero como eCheq ──────────────────────────────────
    echo "\n--- 3: emitir la cuota 1 como eCHEQ, definiendo el monto ---\n";
    $ids = $pend->pluck('id_fecha_probable')->values();
    $mitad = round($total / 2, 2);
    $a1 = $inst->emitirPagoDeFecha($ids[0], [
        'monto' => $mitad, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
    ]);
    echo "  emitido: monto={$a1->monto_pago} forma={$a1->id_forma_pago} estado={$a1->id_estado_instrumento}"
        . " (esperado " . Inst::PENDIENTE_EMISION . " = espera numero)\n";
    $r['echeq espera numero'] = ((int) $a1->id_estado_instrumento === Inst::PENDIENTE_EMISION);
    $r['monto del pago'] = (abs((float) $a1->monto_pago - $mitad) < 0.01);

    // ── 4) Emitir el segundo como TRANSFERENCIA ──────────────────────────
    echo "\n--- 4: emitir la cuota 2 como TRANSFERENCIA ---\n";
    $a2 = $inst->emitirPagoDeFecha($ids[1], [
        'monto' => round($total - $mitad, 2), 'id_forma_pago' => $FORMA_TRANSFERENCIA,
        'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
    ]);
    echo "  emitido: monto={$a2->monto_pago} forma={$a2->id_forma_pago} estado={$a2->id_estado_instrumento}"
        . " (esperado " . Inst::EMITIDO . " = no espera numero)\n";
    $r['transferencia emitida'] = ((int) $a2->id_estado_instrumento === Inst::EMITIDO);
    $r['formas mezcladas'] = ((int) $a1->id_forma_pago === Inst::FORMA_PAGO_ECHEQ
        && (int) $a2->id_forma_pago === $FORMA_TRANSFERENCIA);

    echo "\n--- 5: ya no queda nada del plan sin emitir ---\n";
    echo "  fechas pendientes: " . $inst->fechasPendientesDeEmitir($opa->id_orden_pago)->count() . " (esperado 0)\n";
    $r['plan completo'] = ($inst->fechasPendientesDeEmitir($opa->id_orden_pago)->count() === 0);

    echo "\n--- 6: no se puede emitir dos veces la misma fecha ---\n";
    try {
        $inst->emitirPagoDeFecha($ids[0], ['monto' => 10, 'id_forma_pago' => $FORMA_TRANSFERENCIA]);
        echo "  NO bloqueo\n"; $r['no duplica'] = false;
    } catch (\Throwable $e) { echo "  " . $e->getMessage() . "\n"; $r['no duplica'] = true; }

    echo "\n--- 7: emitir sin monto o sin forma no se puede ---\n";
    $f3 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'fecha_registra' => now(), 'fecha_probable_pago' => '2026-12-01',
        'orden_cuotas' => 3, 'id_pago' => $boleta,
    ]);
    foreach ([['monto' => 0, 'id_forma_pago' => 1], ['monto' => 100, 'id_forma_pago' => null]] as $i => $datos) {
        try {
            $inst->emitirPagoDeFecha($f3, $datos);
            echo "  NO bloqueo caso {$i}\n"; $r["valida caso {$i}"] = false;
        } catch (\Throwable $e) { echo "  " . $e->getMessage() . "\n"; $r["valida caso {$i}"] = true; }
    }

    echo "\n--- 8: el eCheq exige numero para confirmar, la transferencia no ---\n";
    DB::table('tb_tes_fecha_probable_pago')->where('id_fecha_probable', $f3)->delete();
    try {
        $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
        echo "  NO exigio\n"; $r['exige numero al echeq'] = false;
    } catch (\Throwable $e) { echo "  " . $e->getMessage() . "\n"; $r['exige numero al echeq'] = true; }

    $inst->guardarBorradorNumero($a1->id_pago_parcial, 'CRONO-1');
    $n = $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
    echo "  confirmados: {$n}\n";
    $r['confirma'] = ($n >= 1);

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-26s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
