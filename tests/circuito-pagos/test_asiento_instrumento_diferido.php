<?php
// Etapa 2: el asiento del cheque/eCheq se parte en DOS momentos.
//
//   Al emitir (Confirmar Pago):   DEBE acreedor        / HABER eCheq diferidos   <- pasivo
//   Al acreditar:                 DEBE eCheq diferidos / HABER banco             <- sale la plata
//
// Antes habia un solo asiento y golpeaba el banco el dia de la emision, afirmando que salio plata
// que seguia en la cuenta. Es el mismo problema que ospf documento en su plan de cheques diferidos.
//
// Este test entra por el CONTROLLER (`getConfirmarPago`), no por el repositorio: el asiento y el
// movimiento de saldo viven ahi, asi que llamar al repo directo no los ejercita.
//
// Corre sobre una cuenta que tenga los DOS mapeos contables (BANCO y ECHEQ_DIFERIDO). Al
// 2026-09-12 solo las cuentas de MEDICINA PRIVADA (razon 2) en alba3 los tienen; si no hay ninguna
// en la base, el test se saltea en vez de dar un falso verde.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Tesoreria\Services\TesPagosController;
use App\Models\Contabilidad\AsientosPagoHistorialEntity;
use App\Models\Contabilidad\DetalleAsientosContablesEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$plata = fn($n) => number_format((float) $n, 2, ',', '.');

DB::beginTransaction();
$r = [];
try {
    $opaRepo = new TestOrdenPagoRepository();
    $inst = new Inst();

    // Cuenta con los dos mapeos: sin la de diferidos este circuito no puede funcionar (y el codigo
    // corta a proposito en vez de caer al banco).
    $cta = DB::table('tb_tes_cuentas_bancarias as c')
        ->join('tb_cont_banco_cuenta_contable as mb', function ($j) {
            $j->on('mb.id_cuenta_bancaria', '=', 'c.id_cuenta_bancaria')
                ->where('mb.tipo', 'BANCO')->where('mb.vigente', 1);
        })
        ->join('tb_cont_banco_cuenta_contable as md', function ($j) {
            $j->on('md.id_cuenta_bancaria', '=', 'c.id_cuenta_bancaria')
                ->where('md.tipo', 'ECHEQ_DIFERIDO')->where('md.vigente', 1);
        })
        ->first(['c.id_cuenta_bancaria', 'c.nombre_cuenta', 'c.id_razon',
                 'mb.id_detalle_plan as plan_banco', 'md.id_detalle_plan as plan_diferido']);

    if (!$cta) {
        echo "SE SALTEA: ninguna cuenta tiene los mapeos BANCO + ECHEQ_DIFERIDO en esta base.\n";
        echo "  Ver docs/circuito-pagos/cuentas-contables-echeq-diferido.md\n";
        DB::rollBack();
        return;
    }

    echo "cuenta {$cta->nombre_cuenta} (razon {$cta->id_razon}) | plan banco {$cta->plan_banco} | plan diferidos {$cta->plan_diferido}\n";

    // OPA de esa razon social, con periodo contable activo.
    $opa = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(400)->get() as $c
    ) {
        $rz = $opaRepo->razonesSocialesDeOpa($c->id_orden_pago);
        if ($rz == [$cta->id_razon] && $opaRepo->montoPagableOpa($c->id_orden_pago) >= 5000) {
            $opa = $c; break;
        }
    }
    if (!$opa) { echo "SE SALTEA: sin OPA de la razon {$cta->id_razon}\n"; DB::rollBack(); return; }

    $periodos = new \App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository();
    $periodoHoy = $periodos->findByPeriodoContableActivoNow($cta->id_razon);
    if (is_null($periodoHoy)) {
        echo "SE SALTEA: no hay periodo contable activo para la razon {$cta->id_razon}\n";
        DB::rollBack(); return;
    }

    // La fecha de acreditacion sale de un periodo REAL, no se inventa: el asiento 2 se imputa al
    // periodo de ESA fecha y si no existe el sistema corta (a proposito: no se puede asentar en
    // un periodo inexistente). Los periodos de esta base llegan hasta 2026-09-30.
    // El PRIMER dia del periodo (antes el ultimo): acreditar a futuro ahora se rechaza, y sigue
    // siendo una fecha distinta de hoy, que es lo que necesita el caso 6b. (2026-10-06)
    $fechaAcredita = min(\Carbon\Carbon::parse($periodoHoy->periodo_inicio)->toDateString(), \Carbon\Carbon::now('America/Argentina/Buenos_Aires')->toDateString());
    echo "acreditacion a usar: {$fechaAcredita} (periodo {$periodoHoy->id_periodo_contable})\n";

    // La fecha de acreditacion sale de un periodo REAL. No se inventa: el asiento 2 se imputa al
    // periodo de esa fecha, y si no existe el sistema corta (a proposito — no se puede asentar en
    // un periodo inexistente). Los periodos de esta base llegan hasta 2026-09-30.
    // El PRIMER dia del periodo (antes el ultimo): acreditar a futuro ahora se rechaza, y sigue
    // siendo una fecha distinta de hoy, que es lo que necesita el caso 6b. (2026-10-06)
    $fechaAcredita = min(\Carbon\Carbon::parse($periodoHoy->periodo_inicio)->toDateString(), \Carbon\Carbon::now('America/Argentina/Buenos_Aires')->toDateString());
    echo "fecha de acreditacion a usar: {$fechaAcredita} (dentro del periodo {$periodoHoy->id_periodo_contable})\n";

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    // Se parte EXACTO, no con dos `round($pagable/2)`: si el pagable termina en centavo impar, dos
    // mitades redondeadas suman un centavo de mas y el tope de sobrepago las rechaza — con razon.
    $mitad = round($pagable / 2, 2);
    $otraMitad = round($pagable - $mitad, 2);

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        'num_pago' => 'TEST-DIF',
    ]);
    $f1 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $f2 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-15',
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);

    echo "OPA {$opa->num_orden_pago} pagable {$plata($pagable)}: mitad transferencia + mitad eCheq, LA MISMA CUENTA\n\n";

    $saldoAntes = (float) DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $cta->id_cuenta_bancaria)->value('saldo_disponible');

    // ── Confirmar Pago por el controller ────────────────────────────────────────────────
    $payload = [
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_razon' => $cta->id_razon,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
        'lista_pagos' => [
            [   // transferencia: sale YA
                'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-01',
                'id_forma_pago' => 1, 'monto_pago' => $mitad, 'monto_opa' => $opa->monto_orden_pago,
                'num_cheque' => null, 'monto_restante' => 0,
                'id_cuenta_bancaria' => $cta->id_cuenta_bancaria, 'id_fecha_probable' => $f1,
            ],
            [   // eCheq: pasivo hasta que el banco lo debite
                'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-15',
                'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => $otraMitad,
                'monto_opa' => $opa->monto_orden_pago,
                'num_cheque' => null, 'monto_restante' => 0,
                'id_cuenta_bancaria' => $cta->id_cuenta_bancaria, 'id_fecha_probable' => $f2,
            ],
        ],
    ];

    $request = Request::create('/api/v1/tesoreria/confirmar-pago', 'POST', ['data' => json_encode($payload)]);
    $resp = app()->call([app(TesPagosController::class), 'getConfirmarPago'], ['request' => $request]);
    $cuerpo = json_decode($resp->getContent(), true);

    echo "--- 1: el pago se confirma ---\n";
    echo "  status={$resp->getStatusCode()} | " . ($cuerpo['message'] ?? '') . "\n";
    $r[] = ($resp->getStatusCode() === 200);
    echo $ok(end($r));
    if (!end($r)) { throw new \Exception('sin pago confirmado no tiene sentido seguir'); }

    // ── El asiento 1 ────────────────────────────────────────────────────────────────────
    $hist = AsientosPagoHistorialEntity::where('id_pago', $boleta->id_pago)->get();
    $idAsiento1 = $hist->firstWhere('tipo_evento', 'ALTA')->id_asiento_contable ?? null;
    $lineas = DetalleAsientosContablesEntity::where('id_asiento_contable', $idAsiento1)->get();

    echo "--- 2: el HABER se parte: una linea al BANCO y otra a DIFERIDOS ---\n";
    $haberBanco = $lineas->where('id_detalle_plan', $cta->plan_banco)->sum('monto_haber');
    $haberDif   = $lineas->where('id_detalle_plan', $cta->plan_diferido)->sum('monto_haber');
    echo "  HABER banco={$plata($haberBanco)} (esperado {$plata($mitad)}, la transferencia)\n";
    echo "  HABER diferidos={$plata($haberDif)} (esperado {$plata($otraMitad)}, el eCheq)\n";
    $r[] = (abs($haberBanco - $mitad) < 0.02 && abs($haberDif - $otraMitad) < 0.02);
    echo $ok(end($r));

    echo "--- 3: el asiento 1 balancea ---\n";
    $debe = (float) $lineas->sum('monto_debe'); $haber = (float) $lineas->sum('monto_haber');
    echo "  DEBE={$plata($debe)} HABER={$plata($haber)}\n";
    $r[] = (abs($debe - $haber) < 0.02);
    echo $ok(end($r));

    echo "--- 4: el saldo de la cuenta bajo SOLO por la transferencia ---\n";
    $saldoMedio = (float) DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $cta->id_cuenta_bancaria)->value('saldo_disponible');
    echo "  retirado={$plata($saldoAntes - $saldoMedio)} (esperado {$plata($mitad)}; el eCheq todavia no salio)\n";
    $r[] = (abs(($saldoAntes - $saldoMedio) - $mitad) < 0.02);
    echo $ok(end($r));

    echo "--- 5: quedo registrada LA LINEA del instrumento, para poder revertir solo esa ---\n";
    $echeq = TesPagosParciales::where('id_pago', $boleta->id_pago)
        ->where('id_forma_pago', Inst::FORMA_PAGO_ECHEQ)->first();
    $evEmision = $hist->firstWhere('tipo_evento', 'EMISION');
    echo "  evento EMISION -> abono=" . var_export($evEmision->id_pago_parcial ?? null, true)
        . " linea=" . var_export($evEmision->id_asiento_contable_detalle ?? null, true) . "\n";
    $r[] = ($evEmision && (int) $evEmision->id_pago_parcial === (int) $echeq->id_pago_parcial
        && !is_null($evEmision->id_asiento_contable_detalle));
    echo $ok(end($r));

    // ── Acreditar: el asiento 2 ─────────────────────────────────────────────────────────
    echo "--- 6: al acreditar se genera el asiento 2 (DEBE diferidos / HABER banco) ---\n";
    $inst = new Inst();
    // El numero real va por `guardarBorradorNumero`: es el camino que limpia la marca de
    // provisorio, y sin eso la guarda de acreditacion lo frena (con razon: el banco no pudo
    // debitar un documento con un numero que inventamos nosotros).
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->save();
    $inst->guardarBorradorNumero($echeq->id_pago_parcial, 'DIF-' . $echeq->id_pago_parcial);
    $echeq->refresh();

    $inst->marcarAcreditado($echeq->id_pago_parcial, $fechaAcredita, $opaRepo);

    $evDebito = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)
        ->where('tipo_evento', 'DEBITO')->first();
    $lineas2 = DetalleAsientosContablesEntity::where('id_asiento_contable', $evDebito->id_asiento_contable ?? 0)->get();
    $debeDif2  = (float) $lineas2->where('id_detalle_plan', $cta->plan_diferido)->sum('monto_debe');
    $haberBco2 = (float) $lineas2->where('id_detalle_plan', $cta->plan_banco)->sum('monto_haber');
    echo "  DEBE diferidos={$plata($debeDif2)} | HABER banco={$plata($haberBco2)} (esperado {$plata($otraMitad)} cada uno)\n";
    $r[] = (abs($debeDif2 - $otraMitad) < 0.02 && abs($haberBco2 - $otraMitad) < 0.02);
    echo $ok(end($r));

    echo "--- 6b: el asiento 2 lleva la FECHA DE ACREDITACION, no la de hoy ---\n";
    // Antes salía con la fecha del día y el período de la acreditación: fecha y período no
    // coincidían si se acreditaba en otro mes. (2026-10-06)
    $fechaAsi2 = (string) DB::table('tb_cont_asientos_contables')
        ->where('id_asiento_contable', $evDebito->id_asiento_contable ?? 0)->value('fecha_asiento');
    echo "  fecha del asiento={$fechaAsi2} (esperado {$fechaAcredita})\n";
    $r[] = (substr($fechaAsi2, 0, 10) === $fechaAcredita);
    echo $ok(end($r));

    echo "--- 7: los dos asientos se cancelan sobre la cuenta de diferidos ---\n";
    // Si no da cero, quedo un pasivo abierto por un instrumento ya cobrado.
    $netoDif = $haberDif - $debeDif2;
    echo "  neto en diferidos={$plata($netoDif)} (esperado 0,00)\n";
    $r[] = (abs($netoDif) < 0.02);
    echo $ok(end($r));

    echo "--- 8: recien ahora sale el saldo del eCheq ---\n";
    $saldoFinal = (float) DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $cta->id_cuenta_bancaria)->value('saldo_disponible');
    echo "  retirado en total={$plata($saldoAntes - $saldoFinal)} (esperado {$plata($pagable)})\n";
    $r[] = (abs(($saldoAntes - $saldoFinal) - $pagable) < 0.03);
    echo $ok(end($r));

    echo "--- 9: el periodo del asiento 2 es el de la ACREDITACION, no el de hoy ---\n";
    $asiento2 = \App\Models\Contabilidad\AsientosContablesEntity::find($evDebito->id_asiento_contable);
    $per = DB::table('tb_cont_periodos_contables')->where('id_periodo_contable', $asiento2->id_periodo_contable)->first();
    $entra = $per && $per->periodo_inicio <= $fechaAcredita && $per->periodo_fin >= $fechaAcredita;
    echo "  periodo {$per->periodo_inicio} .. {$per->periodo_fin} contiene {$fechaAcredita}: " . ($entra ? 'si' : 'NO') . "\n";
    $r[] = (bool) $entra;
    echo $ok(end($r));

    echo "--- 10: la OPA queda PAGADA recien despues de acreditar ---\n";
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $est = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    echo "  estado OPA={$est} (esperado 5 = PAGADO)\n";
    $r[] = ($est === 5);
    echo $ok(end($r));

    echo "--- 11: rechazar un instrumento asentado revierte SOLO su linea ---\n";
    // El asiento de emision es compartido: tiene la linea del eCheq y la de la transferencia.
    // Revertir el asiento entero se llevaria puesta la transferencia, que no tiene nada que ver.
    $lineaTransferencia = $lineas->where('id_detalle_plan', $cta->plan_banco)->first();

    $inst->marcarRechazado($echeq->id_pago_parcial, 'devuelto por el banco', $opaRepo);

    $contras = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)
        ->where('es_contraasiento', true)->get();
    echo "  contraasientos generados: " . $contras->count() . " (esperado 2: uno del pasivo, uno del debito)\n";
    $r[] = ($contras->count() === 2);
    echo $ok(end($r));

    echo "--- 12: la linea de la TRANSFERENCIA quedo intacta ---\n";
    $sigueViva = DetalleAsientosContablesEntity::find($lineaTransferencia->id_asiento_contable_detalle);
    echo "  linea " . $lineaTransferencia->id_asiento_contable_detalle . " -> "
        . ($sigueViva ? "existe, haber={$plata($sigueViva->monto_haber)}" : "BORRADA") . "\n";
    $r[] = ($sigueViva && abs((float) $sigueViva->monto_haber - $mitad) < 0.02);
    echo $ok(end($r));

    echo "--- 13: la cuenta de diferidos queda en cero y la deuda vuelve a estar viva ---\n";
    $idsAsientos = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)
        ->pluck('id_asiento_contable');
    $todas = DetalleAsientosContablesEntity::whereIn('id_asiento_contable', $idsAsientos)->get();
    $netoDifFinal = (float) $todas->where('id_detalle_plan', $cta->plan_diferido)->sum('monto_haber')
                  - (float) $todas->where('id_detalle_plan', $cta->plan_diferido)->sum('monto_debe');
    echo "  neto en diferidos={$plata($netoDifFinal)} (esperado 0,00)\n";
    $r[] = (abs($netoDifFinal) < 0.02);
    echo $ok(end($r));

    echo "--- 14: la plata volvio a la cuenta (el banco devolvio el eCheq) ---\n";
    $saldoTrasRechazo = (float) DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $cta->id_cuenta_bancaria)->value('saldo_disponible');
    echo "  retirado neto={$plata($saldoAntes - $saldoTrasRechazo)} (esperado {$plata($mitad)}: solo la transferencia)\n";
    $r[] = (abs(($saldoAntes - $saldoTrasRechazo) - $mitad) < 0.03);
    echo $ok(end($r));

    echo "--- 15: la OPA vuelve a PAGO PARCIAL ---\n";
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $estFinal = (int) DB::table('tb_tes_orden_pago')->where('id_orden_pago', $opa->id_orden_pago)->value('id_estado_orden_pago');
    echo "  estado OPA={$estFinal} (esperado 6 = PAGO PARCIAL)\n";
    $r[] = ($estFinal === 6);
    echo $ok(end($r));

    echo "--- 16: las observaciones nombran la cuenta y el banco, no el id ---\n";
    // "Salida de fondos - Cuenta: 7" no le dice nada a quien lee el libro mayor.
    $obs = DetalleAsientosContablesEntity::whereIn('id_asiento_contable', $idsAsientos)
        ->pluck('observaciones')->filter()->unique()->values();
    foreach ($obs as $o) { echo "  {$o}\n"; }
    $conIdCrudo = $obs->filter(fn($o) => str_contains($o, 'Cuenta: '))->count();
    $conNombre  = $obs->filter(fn($o) => str_contains($o, $cta->nombre_cuenta))->count();
    echo "  con id crudo: {$conIdCrudo} (esperado 0) | nombrando la cuenta: {$conNombre}\n";
    $r[] = ($conIdCrudo === 0 && $conNombre > 0);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
