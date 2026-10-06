<?php
// Una OP puede incluir uno o mas eCheq **y transferencias, mezclados**. La forma de pago se
// elige POR INSTRUMENTO al emitirlo, no al confirmar la orden.

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

const FORMA_TRANSFERENCIA = 1;

$r = [];
$opaRepo = new TestOrdenPagoRepository();
$inst = new Inst();
$ctrl = app(TesPagosController::class);
$cuenta = DB::table('tb_tes_cuentas_bancarias')->where('activo', 1)->whereNotNull('id_entidad_bancaria')->first();

$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereHas('opadetalle')->whereDoesntHave('pagos')->orderByDesc('id_orden_pago')->first();

if (!$opa) { echo "SIN OPA LIBRE\n"; return; }

DB::beginTransaction();
try {
    echo "OPA {$opa->num_orden_pago} monto " . number_format($opa->monto_orden_pago, 2, ',', '.') . "\n\n";
    $m = round(((float) $opa->monto_orden_pago) / 2, 2);
    $resto = round(((float) $opa->monto_orden_pago) - $m, 2);

    echo "--- 1: confirmar la OPA -> solo define el cronograma, sin comprometer forma ---\n";
    $ctrl->getCrearPago(
        Request::create('/t', 'POST', [[
            'id_pago' => '', 'id_orden_pago' => $opa->id_orden_pago,
            'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
            'fecha_registra' => date('Y-m-d'), 'anticipo' => '0', 'comprobante' => '',
            'monto_pago' => $opa->monto_orden_pago, 'id_forma_pago' => '1',
            'observaciones' => '', 'id_estado_orden_pago' => '1',
            'monto_opa' => $opa->monto_orden_pago, 'recursor' => '0', 'num_cheque' => '',
            'fecha_confirma_pago' => null, 'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
            'pago_emergencia' => false,
            'cuotas' => [
                ['orden_cuotas' => 1, 'fecha_probable_pago' => '2026-10-01', 'monto' => $m],
                ['orden_cuotas' => 2, 'fecha_probable_pago' => '2026-11-01', 'monto' => $resto],
            ],
        ]]),
        app(TesPagosRepository::class), $opaRepo,
        app(App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository::class),
        app(App\Http\Controllers\Utils\GeneradorCodigosUtils::class)
    );

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_pago');
    $abonos = TesPagosParciales::where('id_pago', $boleta)->orderBy('id_pago_parcial')->get();
    echo "  instrumentos: " . $abonos->count() . " | estados: " . $abonos->pluck('id_estado_instrumento')->implode(', ')
        . " (todos " . Inst::BORRADOR . " = BORRADOR)\n";
    $r['dos instrumentos'] = ($abonos->count() === 2);
    $r['todos en borrador'] = $abonos->every(fn($a) => (int) $a->id_estado_instrumento === Inst::BORRADOR);

    echo "\n--- 2: los dos aparecen para definir su forma ---\n";
    $inst->marcarPendienteEmision($opa->id_orden_pago);
    $pend = $inst->listarPendientesDeNumero()->where('id_orden_pago', $opa->id_orden_pago);
    echo "  pendientes: " . $pend->count() . " (esperado 2)\n";
    $r['ambos listados'] = ($pend->count() === 2);

    $ids = $abonos->pluck('id_pago_parcial')->values();

    echo "\n--- 3: uno eCheq y el otro TRANSFERENCIA, en la misma orden ---\n";
    $inst->cambiarFormaPago($ids[0], Inst::FORMA_PAGO_ECHEQ);
    $inst->cambiarFormaPago($ids[1], FORMA_TRANSFERENCIA);
    $abonos = TesPagosParciales::where('id_pago', $boleta)->orderBy('id_pago_parcial')->get();
    foreach ($abonos as $a) {
        echo "    abono {$a->id_pago_parcial}: forma={$a->id_forma_pago} monto={$a->monto_pago}\n";
    }
    $r['formas mezcladas'] = ((int) $abonos[0]->id_forma_pago === Inst::FORMA_PAGO_ECHEQ
        && (int) $abonos[1]->id_forma_pago === FORMA_TRANSFERENCIA);

    echo "\n--- 4: solo el eCheq exige numero para confirmar ---\n";
    try {
        $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
        echo "  NO exigio numero\n"; $r['exige numero solo al echeq'] = false;
    } catch (\Throwable $e) {
        echo "  " . $e->getMessage() . "\n";
        $r['exige numero solo al echeq'] = str_contains($e->getMessage(), '1 número');
    }

    $inst->guardarBorradorNumero($ids[0], 'MIX-001');
    $n = $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
    echo "  confirmados con un solo numero cargado: {$n} (los 2)\n";
    $r['confirma los dos'] = ($n === 2);

    echo "\n--- 5: cambiar de eCheq a transferencia borra el numero ---\n";
    $otra = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->whereHas('opadetalle')
        ->whereDoesntHave('pagos')->where('id_orden_pago', '<>', $opa->id_orden_pago)->first();

    if ($otra) {
        $b2 = \App\Models\Tesoreria\TesPagoEntity::create([
            'id_orden_pago' => $otra->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $otra->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
            'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 7,
            'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        ]);
        $c = $inst->crearInstrumentos($otra->id_orden_pago, [['monto' => 100, 'fecha' => '2026-10-01', 'id_banco_emisor' => 2]]);
        $inst->guardarBorradorNumero($c[0]->id_pago_parcial, 'BORRAR-1');
        echo "  numero antes: " . TesPagosParciales::find($c[0]->id_pago_parcial)->numero_echeq . "\n";
        $inst->cambiarFormaPago($c[0]->id_pago_parcial, FORMA_TRANSFERENCIA);
        $despues = TesPagosParciales::find($c[0]->id_pago_parcial)->numero_echeq;
        echo "  numero despues: " . var_export($despues, true) . " (esperado null)\n";
        $r['limpia el numero'] = is_null($despues);
        $r['numero queda libre'] = $inst->numeroEcheqDisponible('BORRAR-1');
    }

    echo "\n--- 6: no se puede cambiar la forma de uno ya emitido ---\n";
    try {
        $inst->cambiarFormaPago($ids[0], FORMA_TRANSFERENCIA);
        echo "  NO bloqueo\n"; $r['bloquea si ya se emitio'] = false;
    } catch (\Throwable $e) { echo "  " . $e->getMessage() . "\n"; $r['bloquea si ya se emitio'] = true; }

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-30s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
