<?php
// Un eCheq emitido pero SIN NUMERO no se podia corregir ni dar de baja: si se elegia la cuenta
// equivocada, el unico camino era anular y reemitir la ORDEN ENTERA (con numero de OPA nuevo).
// El requerimiento lo habilita: "mientras el pago este creado pero sin confirmar, la orden es
// editable por completo". Reportado el 2026-09-07 al necesitar cambiarle el banco a un eCheq.

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
    $inst = new Inst();
    $opaRepo = new TestOrdenPagoRepository();

    // Una OPA con razon social y con DOS cuentas de bancos distintos en esa razon, para poder
    // probar el cambio de cuenta contra una valida.
    $opa = null; $cuentas = collect();
    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razon = $opaRepo->razonSocialDeOpa($cand->id_orden_pago);
        if (!$razon) { continue; }
        if ($opaRepo->montoPagableOpa($cand->id_orden_pago) < 500) { continue; }
        $c = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')
            ->where('id_razon', $razon)->get()->unique('id_entidad_bancaria')->take(2)->values();
        if ($c->count() >= 2) { $opa = $cand; $cuentas = $c; $razonOpa = $razon; break; }
    }
    if (!$opa) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);

    $orden = 0;
    $nuevaFecha = function () use ($boleta, &$orden) {
        return DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-09-10',
            'orden_cuotas' => ++$orden, 'fecha_registra' => now()->toDateString(),
        ]);
    };

    echo "OPA {$opa->num_orden_pago} (id {$opa->id_orden_pago}) razon {$razonOpa}\n";
    echo "  cuenta A: {$cuentas[0]->nombre_cuenta} (banco {$cuentas[0]->id_entidad_bancaria})\n";
    echo "  cuenta B: {$cuentas[1]->nombre_cuenta} (banco {$cuentas[1]->id_entidad_bancaria})\n\n";

    $idFecha = $nuevaFecha();
    $abono = $inst->emitirPagoDeFecha($idFecha, [
        'monto' => 100, 'id_forma_pago' => 1,
        'id_cuenta_bancaria' => $cuentas[0]->id_cuenta_bancaria,
    ]);
    echo "abono {$abono->id_pago_parcial} emitido, estado {$abono->id_estado_instrumento} (2 = PENDIENTE_EMISION)\n\n";

    echo "--- 1: cambiar la CUENTA arrastra el BANCO ---\n";
    $editado = $inst->editarAbonoNoEmitido($abono->id_pago_parcial, [
        'id_cuenta_bancaria' => $cuentas[1]->id_cuenta_bancaria,
    ], $opaRepo)->refresh();
    echo "  cuenta={$editado->id_cuenta_bancaria} (esperado {$cuentas[1]->id_cuenta_bancaria})"
       . " banco={$editado->id_banco_emisor} (esperado {$cuentas[1]->id_entidad_bancaria})\n";
    $r[] = ((int) $editado->id_cuenta_bancaria === (int) $cuentas[1]->id_cuenta_bancaria
        && (int) $editado->id_banco_emisor === (int) $cuentas[1]->id_entidad_bancaria);
    echo $ok(end($r));

    echo "--- 2: NO deja poner una cuenta de otra razon social ---\n";
    $ajena = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_razon')
        ->where('id_razon', '!=', $razonOpa)->first();
    if (!$ajena) {
        $otra = DB::table('tb_razones_sociales')->where('id_razon', '!=', $razonOpa)->value('id_razon');
        $ult = DB::table('tb_tes_cuentas_bancarias')->orderByDesc('id_cuenta_bancaria')->first();
        $idA = DB::table('tb_tes_cuentas_bancarias')->insertGetId(array_merge((array) $ult, [
            'id_cuenta_bancaria' => null, 'nombre_cuenta' => 'TEST OTRA RAZON', 'id_razon' => $otra,
        ]));
        $ajena = DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $idA)->first();
    }
    try {
        $inst->editarAbonoNoEmitido($abono->id_pago_parcial, [
            'id_cuenta_bancaria' => $ajena->id_cuenta_bancaria,
        ], $opaRepo);
        echo "  NO fallo\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'razón social de la orden');
    }
    echo $ok(end($r));

    echo "--- 3: editar el MONTO no se cuenta a si mismo contra el tope ---\n";
    // Subirlo un poco tiene que pasar: sin excluir el propio abono, 100 -> 101 se rechazaria
    // por "ya emitido 100".
    try {
        $editado = $inst->editarAbonoNoEmitido($abono->id_pago_parcial, ['monto' => 150], $opaRepo)->refresh();
        echo "  monto={$editado->monto_pago} (esperado 150)\n";
        $r[] = (abs((float) $editado->monto_pago - 150) < 0.01);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 4: NO deja pasarse del tope pagable ---\n";
    $tope = $opaRepo->montoPagableOpa($opa->id_orden_pago);
    try {
        $inst->editarAbonoNoEmitido($abono->id_pago_parcial, ['monto' => $tope + 1000], $opaRepo);
        echo "  NO fallo: dejo pasarse del tope\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'se pasa de lo que hay que pagar');
    }
    echo $ok(end($r));

    echo "--- 5: la fecha NO aparece en 'A emitir' mientras el abono este vivo ---\n";
    $enPlan = fn() => collect($inst->fechasPendientesDeEmitir($opa->id_orden_pago))
        ->contains(fn($f) => (int) $f->id_fecha_probable === (int) $idFecha);
    echo "  aparece: " . var_export($enPlan(), true) . " (esperado false)\n";
    $r[] = ($enPlan() === false);
    echo $ok(end($r));

    echo "--- 6: ANULAR lo pasa a ANULADO y devuelve la fecha al plan ---\n";
    $anulado = $inst->anularAbonoNoEmitido($abono->id_pago_parcial, 'cuenta equivocada', $opaRepo)->refresh();
    echo "  estado={$anulado->id_estado_instrumento} (esperado 6 = ANULADO) motivo='{$anulado->motivo_rechazo}'\n";
    $r[] = ((int) $anulado->id_estado_instrumento === 6);
    echo $ok(end($r));
    echo "  la fecha vuelve a 'A emitir': " . var_export($enPlan(), true) . " (esperado true)\n";
    $r[] = ($enPlan() === true);
    echo $ok(end($r));

    echo "--- 7: se puede REEMITIR sobre esa misma fecha ---\n";
    try {
        $reemitido = $inst->emitirPagoDeFecha($idFecha, [
            'monto' => 100, 'id_forma_pago' => 1,
            'id_cuenta_bancaria' => $cuentas[0]->id_cuenta_bancaria,
        ]);
        echo "  abono nuevo {$reemitido->id_pago_parcial} sobre la fecha {$idFecha}\n";
        $r[] = ((int) $reemitido->id_pago_parcial !== (int) $abono->id_pago_parcial);
    } catch (\Throwable $e) {
        echo "  fallo: {$e->getMessage()}\n"; $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 8: el abono anulado NO cuenta para el tope ---\n";
    // Quedan vivos solo los 100 del reemitido, no los 150 del anulado.
    $vivos = TesPagosParciales::where('id_pago', $boleta->id_pago)
        ->where(function ($q) { $q->whereNull('id_estado_instrumento')->orWhereNotIn('id_estado_instrumento', [5, 6]); })
        ->sum('monto_pago');
    echo "  emitido vivo: {$vivos} (esperado 100, el anulado de 150 no cuenta)\n";
    $r[] = (abs((float) $vivos - 100) < 0.01);
    echo $ok(end($r));

    echo "--- 9: un eCheq EMITIDO todavia se puede corregir; uno ACREDITADO no ---\n";
    // El corte cambio el 2026-09-15. Antes era "antes de emitir" (BORRADOR/PENDIENTE_EMISION),
    // pero al mudar la emision a Confirmar Pago ese corte dejaba CERO margen: el abono nacia y se
    // emitia en la misma accion, asi que un banco mal tipeado obligaba a rehacer la orden entera.
    // Ahora el limite es lo que el banco ya resolvio: ACREDITADO, RECHAZADO o ANULADO.
    $reemitido->id_estado_instrumento = Inst::EMITIDO;
    $reemitido->save();

    $dejaEditar = false;
    try {
        $inst->editarAbonoNoEmitido($reemitido->id_pago_parcial, ['monto' => 50], $opaRepo);
        $dejaEditar = true;
    } catch (\Throwable $e) { echo "  editar fallo: {$e->getMessage()}\n"; }
    echo "  EMITIDO se puede editar: " . var_export($dejaEditar, true) . " (esperado true)\n";
    $r[] = $dejaEditar;
    echo $ok(end($r));

    echo "--- 10: un ACREDITADO ya no se toca ---\n";
    $reemitido->refresh();
    $reemitido->id_estado_instrumento = Inst::ACREDITADO;
    $reemitido->save();
    $bloqueoEditar = false; $bloqueoAnular = false;
    try { $inst->editarAbonoNoEmitido($reemitido->id_pago_parcial, ['monto' => 60], $opaRepo); }
    catch (\Throwable $e) { $bloqueoEditar = str_contains($e->getMessage(), 'acreditado por el banco'); }
    try { $inst->anularAbonoNoEmitido($reemitido->id_pago_parcial, 'x', $opaRepo); }
    catch (\Throwable $e) { $bloqueoAnular = str_contains($e->getMessage(), 'acreditado por el banco'); }
    echo "  editar bloqueado: " . var_export($bloqueoEditar, true)
       . " | anular bloqueado: " . var_export($bloqueoAnular, true) . "\n";
    $r[] = ($bloqueoEditar && $bloqueoAnular);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
