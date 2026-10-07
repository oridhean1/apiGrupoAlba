<?php
// 2026-10-06: acredita con la fecha de HOY. Acreditar a futuro ahora se rechaza (ver
// test_acreditar_fecha_futura.php), y estos tests usaban fechas inventadas a futuro.
// Corregir un instrumento YA ASENTADO tiene que rehacer su asiento.
//
// Es la contrapartida obligatoria de haber abierto la edicion de un EMITIDO (2026-09-15). El
// asiento de emision dejo una linea con el monto y la cuenta de ese instrumento; si se los cambia
// sin tocar la contabilidad, el asiento queda diciendo un importe que ya no es.
//
// Estaba anotado como pendiente #12 en estado-sincronizacion-bases.md: "editar un abono ya asentado
// no rehace el asiento". Este test es el que lo cierra.
//
// Se resuelve como pide Contaduria: contraasiento de la linea vieja + asiento nuevo por el valor
// corregido. El asiento original NO se modifica — un asiento emitido se reversa, no se edita.

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

/** Neto de una cuenta del plan sobre TODOS los asientos de un abono (haber - debe). */
$netoDe = function ($idAbono, $idDetallePlan): float {
    $ids = AsientosPagoHistorialEntity::where('id_pago_parcial', $idAbono)->pluck('id_asiento_contable');
    $lineas = DetalleAsientosContablesEntity::whereIn('id_asiento_contable', $ids)
        ->where('id_detalle_plan', $idDetallePlan)->get();
    return round((float) $lineas->sum('monto_haber') - (float) $lineas->sum('monto_debe'), 2);
};

DB::beginTransaction();
$r = [];
try {
    $opaRepo = new TestOrdenPagoRepository();
    $inst = new Inst();

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
        DB::rollBack(); return;
    }

    $opa = null;
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(400)->get() as $c
    ) {
        $rz = $opaRepo->razonesSocialesDeOpa($c->id_orden_pago);
        if ($rz == [$cta->id_razon] && $opaRepo->montoPagableOpa($c->id_orden_pago) >= 5000) { $opa = $c; break; }
    }
    if (!$opa) { echo "SE SALTEA: sin OPA de la razon {$cta->id_razon}\n"; DB::rollBack(); return; }

    $periodos = new \App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository();
    if (is_null($periodos->findByPeriodoContableActivoNow($cta->id_razon))) {
        echo "SE SALTEA: sin periodo contable activo\n"; DB::rollBack(); return;
    }

    $pagable = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    $inicial = round($pagable / 2, 2);

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        'num_pago' => 'TEST-REAS',
    ]);
    $f1 = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    // Dos cuotas: sin la segunda, pagar la mitad no es un parcial legitimo y la validacion de
    // cobertura lo rechaza (correctamente).
    DB::table('tb_tes_fecha_probable_pago')->insert([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-11-15',
        'orden_cuotas' => 2, 'fecha_registra' => now()->toDateString(),
    ]);

    echo "cuenta {$cta->nombre_cuenta} | plan diferidos {$cta->plan_diferido}\n";
    echo "OPA {$opa->num_orden_pago}: un eCheq de {$plata($inicial)}\n\n";

    $payload = [
        'id_pago' => $boleta->id_pago, 'id_orden_pago' => $opa->id_orden_pago,
        'id_razon' => $cta->id_razon,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 0,
        'lista_pagos' => [[
            'id_pago_parcial' => null, 'fecha_confirma_pago' => '2026-11-01',
            'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => $inicial,
            'monto_opa' => $opa->monto_orden_pago, 'num_cheque' => null, 'monto_restante' => 0,
            'id_cuenta_bancaria' => $cta->id_cuenta_bancaria, 'id_fecha_probable' => $f1,
        ]],
    ];
    $req = Request::create('/api/v1/tesoreria/confirmar-pago', 'POST', ['data' => json_encode($payload)]);
    $resp = app()->call([app(TesPagosController::class), 'getConfirmarPago'], ['request' => $req]);

    if ($resp->getStatusCode() !== 200) {
        echo "No se pudo confirmar el pago: " . $resp->getContent() . "\n"; DB::rollBack(); return;
    }

    $echeq = TesPagosParciales::where('id_pago', $boleta->id_pago)->first();

    echo "--- 1: el asiento inicial dejo el pasivo por el monto original ---\n";
    $pasivo1 = $netoDe($echeq->id_pago_parcial, $cta->plan_diferido);
    echo "  pasivo en diferidos={$plata($pasivo1)} (esperado {$plata($inicial)})\n";
    $r[] = (abs($pasivo1 - $inicial) < 0.02);
    echo $ok(end($r));

    echo "--- 2: corregir el NUMERO no genera contraasiento (no lo ve la contabilidad) ---\n";
    $contrasAntes = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)
        ->where('es_contraasiento', true)->count();
    $inst->guardarBorradorNumero($echeq->id_pago_parcial, 'REAL-12345');
    $contrasDespues = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)
        ->where('es_contraasiento', true)->count();
    echo "  contraasientos: {$contrasAntes} -> {$contrasDespues} (esperado sin cambio)\n";
    $r[] = ($contrasAntes === $contrasDespues);
    echo $ok(end($r));

    echo "--- 3: y el numero dejo de ser provisorio ---\n";
    $echeq->refresh();
    echo "  numero_echeq=" . var_export($echeq->numero_echeq, true)
        . " provisorio=" . var_export($echeq->numero_provisorio, true) . "\n";
    $r[] = ($echeq->numero_echeq === 'REAL-12345' && $echeq->numero_provisorio === false);
    echo $ok(end($r));

    echo "--- 4: cambiar el MONTO si rehace el asiento ---\n";
    $corregido = round($inicial / 2, 2);
    $inst->editarAbonoNoEmitido($echeq->id_pago_parcial, ['monto' => $corregido], $opaRepo);
    $pasivo2 = $netoDe($echeq->id_pago_parcial, $cta->plan_diferido);
    echo "  pasivo en diferidos={$plata($pasivo2)} (esperado {$plata($corregido)}, el monto nuevo)\n";
    $r[] = (abs($pasivo2 - $corregido) < 0.02);
    echo $ok(end($r));

    echo "--- 5: el asiento ORIGINAL no se modifico, se reverso ---\n";
    // Un asiento emitido no se edita: queda, y al lado queda su reverso.
    $hist = AsientosPagoHistorialEntity::where('id_pago_parcial', $echeq->id_pago_parcial)->get();
    $emisiones = $hist->where('tipo_evento', 'EMISION')->count();
    $contras = $hist->where('es_contraasiento', true)->count();
    echo "  eventos EMISION: {$emisiones} (el original + el reasiento) | contraasientos: {$contras}\n";
    $r[] = ($emisiones === 2 && $contras === 1);
    echo $ok(end($r));

    echo "--- 6: cada asiento por separado balancea ---\n";
    $desbalanceados = [];
    foreach ($hist->pluck('id_asiento_contable')->unique() as $idAs) {
        $l = DetalleAsientosContablesEntity::where('id_asiento_contable', $idAs)->get();
        if (abs((float) $l->sum('monto_debe') - (float) $l->sum('monto_haber')) > 0.02) {
            $desbalanceados[] = $idAs;
        }
    }
    echo "  asientos desbalanceados: " . (count($desbalanceados) ?: 'ninguno') . "\n";
    $r[] = empty($desbalanceados);
    echo $ok(end($r));

    echo "--- 7: acreditar despues del reasiento deja la cuenta de diferidos en CERO ---\n";
    // La prueba de fuego: si el reasiento hubiera quedado mal, el pasivo no cerraria.
    $echeq->refresh();
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->save();
    $periodoHoy = $periodos->findByPeriodoContableActivoNow($cta->id_razon);
    $inst->marcarAcreditado($echeq->id_pago_parcial, \Carbon\Carbon::now('America/Argentina/Buenos_Aires')->toDateString(), $opaRepo);
    $pasivoFinal = $netoDe($echeq->id_pago_parcial, $cta->plan_diferido);
    echo "  pasivo en diferidos={$plata($pasivoFinal)} (esperado 0,00)\n";
    $r[] = (abs($pasivoFinal) < 0.02);
    echo $ok(end($r));

    echo "--- 8: y el banco quedo debitado por el monto CORREGIDO, no el original ---\n";
    // La salida por banco ES el haber de esa cuenta, asi que el neto (haber - debe) ya viene
    // positivo. Negarlo era el error.
    $netoBanco = $netoDe($echeq->id_pago_parcial, $cta->plan_banco);
    echo "  salida por banco={$plata($netoBanco)} (esperado {$plata($corregido)}, no {$plata($inicial)})\n";
    $r[] = (abs($netoBanco - $corregido) < 0.02);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
