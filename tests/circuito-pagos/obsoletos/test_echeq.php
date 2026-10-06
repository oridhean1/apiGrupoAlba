<?php
// Ciclo de vida del eCheq, contra datos reales, con rollback.
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());

$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereDoesntHave('pagos')->orderByDesc('id_orden_pago')->first();
if (!$opa) { $opa = TesOrdenPagoEntity::where('id_estado_orden_pago',1)->orderByDesc('id_orden_pago')->first(); }
if (!$opa) { echo "SIN OPA\n"; return; }
echo "OPA {$opa->id_orden_pago} ({$opa->num_orden_pago}) monto {$opa->monto_orden_pago}\n\n";

$ok = fn($c) => $c ? ">>> OK\n\n" : ">>> FALLA\n\n";

DB::beginTransaction();
try {
    $r = new Inst();
    $opaRepo = new TestOrdenPagoRepository();

    echo "--- 1: crear 2 instrumentos, nacen SIN numero en BORRADOR ---\n";
    $creados = $r->crearInstrumentos($opa->id_orden_pago, [
        ['monto' => 1000.00, 'fecha' => '2026-09-10', 'id_banco_emisor' => 1],
        ['monto' =>  500.50, 'fecha' => '2026-09-20', 'id_banco_emisor' => 1],
    ]);
    $p1 = $creados[0]->refresh(); $p2 = $creados[1]->refresh();
    echo "  p1 estado={$p1->id_estado_instrumento} num=" . var_export($p1->numero_echeq, true)
       . " banco=" . var_export($p1->id_banco_emisor, true) . " monto={$p1->monto_pago}\n";
    echo $ok((int)$p1->id_estado_instrumento === Inst::BORRADOR && is_null($p1->numero_echeq)
            && (int)$p1->id_banco_emisor === 1);

    echo "--- 2: imprimir la OP -> PENDIENTE DE EMISION ---\n";
    $n = $r->marcarPendienteEmision($opa->id_orden_pago);
    echo "  movidos: {$n}, estado p1=" . $p1->refresh()->id_estado_instrumento . "\n";
    echo $ok($n === 2 && (int)$p1->id_estado_instrumento === Inst::PENDIENTE_EMISION);

    echo "--- 3: confirmar SIN cargar numeros -> debe bloquear ---\n";
    try { $r->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo); echo "  NO bloqueo\n"; echo $ok(false); }
    catch (\Throwable $e) { echo "  bloqueo: {$e->getMessage()}\n"; echo $ok(true); }

    echo "--- 4: cargar numeros como borrador ---\n";
    $r->guardarBorradorNumero($p1->id_pago, ' ECHEQ-TEST-001 ');
    $r->guardarBorradorNumero($p2->id_pago, 'ECHEQ-TEST-002');
    echo "  p1 num='" . $p1->refresh()->numero_echeq . "' estado={$p1->id_estado_instrumento}\n";
    echo $ok($p1->numero_echeq === 'ECHEQ-TEST-001'
            && (int)$p1->id_estado_instrumento === Inst::PENDIENTE_EMISION);

    echo "--- 5: numero duplicado -> debe rechazar ---\n";
    echo "  disponible('ECHEQ-TEST-001') = " . var_export($r->numeroEcheqDisponible('ECHEQ-TEST-001'), true) . "\n";
    try { $r->guardarBorradorNumero($p2->id_pago, 'ECHEQ-TEST-001'); echo "  NO rechazo\n"; echo $ok(false); }
    catch (\Throwable $e) { echo "  rechazo: {$e->getMessage()}\n"; echo $ok(true); }

    echo "--- 6: revalidar el propio numero no choca consigo mismo ---\n";
    $propio = $r->numeroEcheqDisponible('ECHEQ-TEST-001', $p1->id_pago);
    echo "  disponible excluyendose = " . var_export($propio, true) . "\n";
    echo $ok($propio === true);

    echo "--- 7: confirmar emision -> EMITIDO + fecha ---\n";
    $c = $r->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
    echo "  confirmados={$c} p1 estado=" . $p1->refresh()->id_estado_instrumento
       . " fecha_emision={$p1->fecha_emision_echeq}\n";
    echo "  estado OPA=" . $opa->refresh()->id_estado_orden_pago . " (emitir NO acredita)\n";
    echo $ok($c === 2 && (int)$p1->id_estado_instrumento === Inst::EMITIDO
            && !empty($p1->fecha_emision_echeq));

    echo "--- 8: acreditar el primero -> OPA a PAGO PARCIAL ---\n";
    $r->marcarAcreditado($p1->id_pago, '2026-09-10', $opaRepo);
    $e8 = (int) $opa->refresh()->id_estado_orden_pago;
    echo "  p1 estado=" . $p1->refresh()->id_estado_instrumento . " | estado OPA={$e8}\n";
    echo "  imputado={$opaRepo->montoImputadoOpa($opa->id_orden_pago)} pagado={$opaRepo->montoPagadoOpa($opa->id_orden_pago)}\n";
    echo $ok((int)$p1->id_estado_instrumento === Inst::ACREDITADO);

    echo "--- 9: rechazar el acreditado (carga MANUAL) -> sale del computo ---\n";
    $pagadoAntes = $opaRepo->montoPagadoOpa($opa->id_orden_pago);
    $r->marcarRechazado($p1->id_pago, 'Fondos insuficientes', $opaRepo);
    $pagadoDespues = $opaRepo->montoPagadoOpa($opa->id_orden_pago);
    echo "  p1 estado=" . $p1->refresh()->id_estado_instrumento . " motivo='{$p1->motivo_rechazo}'\n";
    echo "  pagado antes={$pagadoAntes} -> despues={$pagadoDespues}\n";
    echo $ok((int)$p1->id_estado_instrumento === Inst::RECHAZADO
            && $pagadoDespues < $pagadoAntes);

    echo "--- 10: no se puede cargar numero en un EMITIDO ---\n";
    try { $r->guardarBorradorNumero($p2->id_pago, 'OTRO-999'); echo "  NO bloqueo\n"; echo $ok(false); }
    catch (\Throwable $e) { echo "  bloqueo: {$e->getMessage()}\n"; echo $ok(true); }

    echo "--- 11: listado de pendientes de numero ---\n";
    $lista = $r->listarPendientesDeNumero();
    echo "  filas: " . $lista->count() . " (los de esta OPA ya se emitieron, no deben estar)\n";
    $deEsta = $lista->where('id_orden_pago', $opa->id_orden_pago)->count();
    echo "  de esta OPA: {$deEsta}\n";
    echo $ok($deEsta === 0);

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally { DB::rollBack(); echo "(rollback hecho)\n"; }
