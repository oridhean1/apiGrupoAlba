<?php
// El modal de Confirmar Pago dejaba editar CUALQUIER abono, incluidos los del circuito de eCheq:
// forma de pago, monto, cuenta y numero de cheque. Ese camino no aplica ninguna de las guardas del
// instrumento, asi que se podia cambiarle el monto o la cuenta a un eCheq que YA tenia numero del
// banco. Los del circuito se gestionan en Carga de eCheq, que si valida.
//
// El criterio es `id_estado_instrumento`, NO la forma de pago: hay 83 abonos en Alba y 75 en OSV
// con forma eCheq pero sin estado de instrumento (legacy, anteriores al circuito) que NO aparecen
// en Carga de eCheq — bloquearlos los dejaria sin ninguna pantalla donde corregirlos.
// Pedido el 2026-09-09: "aqui no le permitamos editar echeq".

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
    $repo = new TesPagosRepository();
    $inst = new Inst();
    $opaRepo = new TestOrdenPagoRepository();

    $opa = null; $cuentas = collect();
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razones = $opaRepo->razonesSocialesDeOpa($cand->id_orden_pago);
        if (empty($razones)) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 2000) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')
            ->whereIn('id_razon', $razones)->get()->unique('id_entidad_bancaria')->take(2)->values();
        if ($c->count() >= 2) { $opa = $cand; $cuentas = $c; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    // Se dejan MAS fechas de las que se van a usar a proposito: este test es sobre no poder
    // editar un eCheq, no sobre cobertura. Con tantas fechas como abonos, la validacion de
    // "los montos no cubren lo declarado" se dispararia por los importes de juguete y taparia
    // lo que se quiere probar.
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-10-01',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    foreach ([['2026-10-20', 2], ['2026-10-30', 3], ['2026-11-10', 4]] as [$f, $o]) {
        DB::table('tb_tes_fecha_probable_pago')->insert([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => $f,
            'orden_cuotas' => $o, 'fecha_registra' => now()->toDateString(),
        ]);
    }

    // A) abono del CIRCUITO: nace en Carga de eCheq, con estado de instrumento.
    $echeq = $inst->emitirPagoDeFecha($idFecha, [
        'monto' => 500, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cuentas[0]->id_cuenta_bancaria,
    ]);
    $echeq->id_estado_instrumento = Inst::EMITIDO;
    $echeq->numero_echeq = 'TEST-' . $echeq->id_pago_parcial;
    $echeq->save();

    // B) abono LEGACY: forma eCheq pero SIN estado de instrumento.
    $legacy = TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => '2026-10-02',
        'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ, 'monto_pago' => 300,
        'monto_opa' => $opa->monto_orden_pago, 'id_usuario' => 1,
        'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $cuentas[0]->id_cuenta_bancaria,
    ]);

    echo "OPA {$opa->num_orden_pago} boleta {$boleta->id_pago}\n";
    echo "  abono del circuito : {$echeq->id_pago_parcial} (estado_instr={$echeq->id_estado_instrumento}, echeq {$echeq->numero_echeq})\n";
    echo "  abono legacy       : {$legacy->id_pago_parcial} (forma eCheq, estado_instr=NULL)\n\n";

    $base = fn(array $extra) => (object) array_merge([
        'id_pago' => $boleta->id_pago, 'id_cuenta_bancaria' => '', 'anticipo' => '0',
        'monto_anticipado' => '0', 'num_cheque' => null, 'fecha_probable_pago' => null,
        'observaciones' => null, 'id_forma_cobro' => null, 'monto_cobro' => null,
        'fecha_confirma_cobro' => null, 'cuenta_bancaria' => null, 'imputacion_contable' => null,
        'banco' => null, 'archivos_eliminados' => null, 'monto_pago' => 0,
    ], $extra);

    // Se intenta modificar TODO lo del instrumento en los dos abonos.
    $intento = fn($a, $monto) => (object) [
        'id_pago_parcial' => $a->id_pago_parcial, 'fecha_confirma_pago' => '2026-10-15',
        'id_forma_pago' => 1, 'monto_pago' => $monto, 'monto_opa' => $opa->monto_orden_pago,
        'num_cheque' => 'HACKEADO', 'id_pago' => $boleta->id_pago, 'monto_restante' => 0,
        'id_cuenta_bancaria' => $cuentas[1]->id_cuenta_bancaria,
    ];

    $repo->findByConfirmarPago($base(['lista_pagos' => [
        $intento($echeq, 999999),
        $intento($legacy, 250),
    ]]));

    $echeq->refresh();
    $legacy->refresh();

    echo "--- 1: el abono del CIRCUITO no cambio su monto ---\n";
    echo "  monto={$echeq->monto_pago} (esperado 500, se intento 999999)\n";
    $r[] = (abs((float) $echeq->monto_pago - 500) < 0.01);
    echo $ok(end($r));

    echo "--- 2: tampoco su forma de pago ---\n";
    echo "  forma={$echeq->id_forma_pago} (esperado " . Inst::FORMA_PAGO_ECHEQ . ", se intento 1)\n";
    $r[] = ((int) $echeq->id_forma_pago === Inst::FORMA_PAGO_ECHEQ);
    echo $ok(end($r));

    echo "--- 3: tampoco su cuenta de origen ---\n";
    echo "  cuenta={$echeq->id_cuenta_bancaria} (esperado {$cuentas[0]->id_cuenta_bancaria}, se intento {$cuentas[1]->id_cuenta_bancaria})\n";
    $r[] = ((int) $echeq->id_cuenta_bancaria === (int) $cuentas[0]->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 4: tampoco su numero de cheque ---\n";
    echo "  num_cheque=" . var_export($echeq->num_cheque, true) . " (no puede ser HACKEADO)\n";
    $r[] = ($echeq->num_cheque !== 'HACKEADO');
    echo $ok(end($r));

    echo "--- 5: el eCheq LEGACY si se puede editar (es el unico lugar donde tocarlo) ---\n";
    echo "  monto={$legacy->monto_pago} (esperado 250) forma={$legacy->id_forma_pago} (esperado 1)"
        . " cuenta={$legacy->id_cuenta_bancaria} (esperado {$cuentas[1]->id_cuenta_bancaria})\n";
    $r[] = (abs((float) $legacy->monto_pago - 250) < 0.01
        && (int) $legacy->id_forma_pago === 1
        && (int) $legacy->id_cuenta_bancaria === (int) $cuentas[1]->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 6: confirmar NO falla aunque la lista traiga un eCheq emitido ---\n";
    // Si el backend cortara con excepcion en vez de saltear, la confirmacion entera reventaria.
    $r[] = true; // si llegamos aca, no exploto
    echo "  la confirmacion corrio hasta el final\n";
    echo $ok(end($r));

    echo "--- 7: el estado de la boleta usa el monto GUARDADO del instrumento ---\n";
    // 500 (el real del eCheq, no los 999999 del request) + 250 del legacy = 750.
    $boleta->refresh();
    $vivos = TesPagosParciales::where('id_pago', $boleta->id_pago)->vivos()->sum('monto_pago');
    echo "  abonos vivos suman: {$vivos} (esperado 750)\n";
    $r[] = (abs((float) $vivos - 750) < 0.01);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
