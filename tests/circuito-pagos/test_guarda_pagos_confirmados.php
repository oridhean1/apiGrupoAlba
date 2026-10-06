<?php
// `tienePagosConfirmados()` decidia mirando la BOLETA: `fecha_confirma_pago` o estado PAGADO. Pero
// ese campo de la boleta no prueba que haya salido plata — se completa al confirmar la orden,
// cuando recien se arma el cronograma. Resultado: ordenes EN PROCESO sin un peso cobrado rebotaban
// con "la orden tiene pagos confirmados", que ademas no dice que hacer.
// Reportado el 2026-09-11 al intentar "Anular y reemitir" una orden en proceso.
//
// Ahora se mide por `montoPagadoOpa()`, que ya distingue los dos circuitos: si hay abonos mandan
// ellos (solo los confirmados); si no hay, vale la cabecera (circuito viejo).

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
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
    $repo = new TestOrdenPagoRepository();
    $inst = new Inst();

    $buscarOpa = function ($excluir = []) {
        return TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)->whereHas('opadetalle')
            ->whereNotIn('id_orden_pago', $excluir)
            ->orderByDesc('id_orden_pago')->first();
    };

    $boletaDe = function ($opa, array $extra = []) {
        return TesPagoEntity::create(array_merge([
            'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
            'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
            'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        ], $extra));
    };

    echo "--- 1: boleta con fecha_confirma_pago pero SIN abonos cobrados -> se puede anular ---\n";
    // Es el caso reportado: la orden quedo EN PROCESO, la boleta tiene fecha de confirmacion
    // (se completa al armar el cronograma) y hay un abono NO confirmado. No salio plata.
    $opa1 = $buscarOpa();
    $b1 = $boletaDe($opa1, ['fecha_confirma_pago' => now()->toDateString()]);
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => null,
        'id_forma_pago' => 1, 'monto_pago' => 500, 'monto_opa' => $opa1->monto_orden_pago,
        'id_usuario' => 1, 'id_pago' => $b1->id_pago, 'monto_restante' => 0,
    ]);
    TesOrdenPagoEntity::where('id_orden_pago', $opa1->id_orden_pago)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO]);

    $cobrado = $repo->montoPagadoOpa($opa1->id_orden_pago);
    $bloqueo = $repo->motivoQueImpideAnular($opa1->id_orden_pago);
    echo "  cobrado=" . number_format($cobrado, 2) . " | bloqueo=" . var_export($bloqueo, true) . "\n";
    $r[] = (abs($cobrado) < 0.01 && is_null($bloqueo));
    echo $ok(end($r));

    echo "--- 2: si el abono SI esta confirmado, bloquea (salio plata) ---\n";
    $opa2 = $buscarOpa([$opa1->id_orden_pago]);
    $b2 = $boletaDe($opa2, ['fecha_confirma_pago' => now()->toDateString()]);
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => now()->toDateString(),
        'id_forma_pago' => 1, 'monto_pago' => 500, 'monto_opa' => $opa2->monto_orden_pago,
        'id_usuario' => 1, 'id_pago' => $b2->id_pago, 'monto_restante' => 0,
    ]);
    $bloqueo2 = $repo->motivoQueImpideAnular($opa2->id_orden_pago);
    echo "  cobrado=" . number_format($repo->montoPagadoOpa($opa2->id_orden_pago), 2)
        . " | bloqueo=" . var_export($bloqueo2, true) . "\n";
    $r[] = (!is_null($bloqueo2) && str_contains($bloqueo2, 'pagos confirmados'));
    echo $ok(end($r));

    echo "--- 3: con un eCheq EMITIDO sin acreditar, bloquea pero con el mensaje UTIL ---\n";
    // No salio plata, asi que la guarda de pagos confirmados no aplica; la de instrumentos si,
    // y su mensaje dice que hacer (rechazarlo o anularlo primero).
    $opa3 = $buscarOpa([$opa1->id_orden_pago, $opa2->id_orden_pago]);
    $b3 = $boletaDe($opa3, ['fecha_confirma_pago' => now()->toDateString(), 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ]);
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $b3->id_pago, 'fecha_probable_pago' => '2026-11-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $razones = $repo->razonesSocialesDeOpa($opa3->id_orden_pago);
    $cta = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $razones ?: [1])->first();
    $echeq = $inst->emitirPagoDeFecha($idFecha, [
        'monto' => 100, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cta?->id_cuenta_bancaria,
    ]);
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->save();

    $bloqueo3 = $repo->motivoQueImpideAnular($opa3->id_orden_pago);
    echo "  cobrado=" . number_format($repo->montoPagadoOpa($opa3->id_orden_pago), 2)
        . " | bloqueo=" . var_export($bloqueo3, true) . "\n";
    $r[] = (!is_null($bloqueo3) && str_contains($bloqueo3, 'eCheq ya emitido'));
    echo $ok(end($r));

    echo "--- 4: boleta del circuito VIEJO (sin abonos) confirmada -> sigue bloqueando ---\n";
    // Ahi el pago se registraba en la cabecera, sin desglose: la fecha de confirmacion es la
    // unica evidencia que hay, y tiene que seguir valiendo.
    $opa4 = $buscarOpa([$opa1->id_orden_pago, $opa2->id_orden_pago, $opa3->id_orden_pago]);
    $b4 = $boletaDe($opa4, [
        'fecha_confirma_pago' => now()->toDateString(),
        'monto_pago' => 1000,
    ]);
    $bloqueo4 = $repo->motivoQueImpideAnular($opa4->id_orden_pago);
    echo "  abonos=" . TesPagosParciales::where('id_pago', $b4->id_pago)->count()
        . " cobrado=" . number_format($repo->montoPagadoOpa($opa4->id_orden_pago), 2)
        . " | bloqueo=" . var_export($bloqueo4, true) . "\n";
    $r[] = (!is_null($bloqueo4) && str_contains($bloqueo4, 'pagos confirmados'));
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
