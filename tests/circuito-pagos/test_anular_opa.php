<?php
// Anular una OPA sin reemplazarla. Antes solo existia `anularYReemitir()`, que ademas crea una
// orden nueva: no habia forma de decir "esta orden no tendria que existir" y punto.
// Pedido el 2026-09-09 para anular la OPA-1102 en dev y en produccion.

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

    $opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->where('monto_orden_pago', '>=', 5000)
        ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
    if (!$opa) { echo "SIN OPA\n"; DB::rollBack(); return; }

    $idOpa = $opa->id_orden_pago;
    $facturas = DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $idOpa)->pluck('id_factura');
    echo "OPA {$opa->num_orden_pago} (id {$idOpa}) con " . $facturas->count() . " factura(s)\n\n";

    echo "--- 1: sin motivo NO deja anular ---\n";
    $res = $repo->anularOpa($idOpa, '   ');
    echo "  {$res['message']}\n";
    $r[] = (!$res['ok'] && str_contains($res['message'], 'motivo'));
    echo $ok(end($r));

    echo "--- 2: una factura de la orden esta TOMADA antes de anular ---\n";
    $primera = $facturas->first();
    $vigenteAntes = $repo->findByOpaVigenteFactura($primera);
    echo "  OPA vigente de la factura {$primera}: " . var_export($vigenteAntes?->id_orden_pago, true) . "\n";
    $r[] = (!is_null($vigenteAntes) && (int) $vigenteAntes->id_orden_pago === (int) $idOpa);
    echo $ok(end($r));

    echo "--- 3: anular con motivo -> RECHAZADA con motivo, fecha y usuario ---\n";
    $res = $repo->anularOpa($idOpa, 'mezclaba dos razones sociales');
    echo "  {$res['message']}\n";
    $a = $res['anulada'];
    $r[] = ($res['ok'] && (int) $a->id_estado_orden_pago === 3
        && $a->motivo_rechazo === 'mezclaba dos razones sociales'
        && !empty($a->fecha_rechazo));
    echo "  estado={$a->id_estado_orden_pago} motivo={$a->motivo_rechazo} fecha={$a->fecha_rechazo}\n";
    echo $ok(end($r));

    echo "--- 4: las facturas quedan LIBRES ---\n";
    $libres = 0;
    foreach ($facturas as $idF) {
        if (is_null($repo->findByOpaVigenteFactura($idF))) { $libres++; }
    }
    echo "  facturas sin OPA vigente: {$libres} de " . $facturas->count() . "\n";
    $r[] = ($libres === $facturas->count());
    echo $ok(end($r));

    echo "--- 5: la orden NO se borra ---\n";
    $sigue = !is_null(TesOrdenPagoEntity::find($idOpa));
    echo "  sigue en la base: " . var_export($sigue, true) . "\n";
    $r[] = $sigue;
    echo $ok(end($r));

    echo "--- 6: anular dos veces NO deja ---\n";
    $res = $repo->anularOpa($idOpa, 'de nuevo');
    echo "  {$res['message']}\n";
    $r[] = (!$res['ok'] && str_contains($res['message'], 'ya'));
    echo $ok(end($r));

    echo "--- 7: con un eCheq EMITIDO no deja anular ---\n";
    $opa2 = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->where('monto_orden_pago', '>=', 5000)->whereHas('opadetalle')
        ->where('id_orden_pago', '!=', $idOpa)->orderByDesc('id_orden_pago')->first();
    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa2->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa2->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
    ]);
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'id_pago' => $boleta->id_pago, 'fecha_probable_pago' => '2026-09-20',
        'orden_cuotas' => 1, 'fecha_registra' => now()->toDateString(),
    ]);
    $razones = $repo->razonesSocialesDeOpa($opa2->id_orden_pago);
    $cta = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $razones ?: [1])->first();
    $abono = $inst->emitirPagoDeFecha($idFecha, [
        'monto' => 100, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
        'id_cuenta_bancaria' => $cta?->id_cuenta_bancaria,
    ]);
    $abono->id_estado_instrumento = Inst::EMITIDO;
    $abono->save();
    $res = $repo->anularOpa($opa2->id_orden_pago, 'probando');
    echo "  {$res['message']}\n";
    $r[] = (!$res['ok'] && str_contains($res['message'], 'emitido'));
    echo $ok(end($r));

    echo "--- 8: si ese eCheq se anula, la orden ya se puede anular ---\n";
    $abono->id_estado_instrumento = Inst::PENDIENTE_EMISION;
    $abono->save();
    $inst->anularAbonoNoEmitido($abono->id_pago_parcial, 'mal cargado', $repo);
    $res = $repo->anularOpa($opa2->id_orden_pago, 'ya sin echeq vivo');
    echo "  {$res['message']}\n";
    $r[] = $res['ok'];
    echo $ok(end($r));

    echo "--- 9: la BOLETA tambien queda dada de baja ---\n";
    $estadoBoleta = (int) DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago');
    echo "  estado de la boleta: {$estadoBoleta} (esperado 3 = RECHAZADO)\n";
    $r[] = ($estadoBoleta === 3);
    echo $ok(end($r));

    echo "--- 10: con un pago CONFIRMADO no deja anular ---\n";
    $opa3 = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->where('monto_orden_pago', '>=', 5000)->whereHas('opadetalle')
        ->whereNotIn('id_orden_pago', [$idOpa, $opa2->id_orden_pago])
        ->orderByDesc('id_orden_pago')->first();
    $boleta3 = TesPagoEntity::create([
        'id_orden_pago' => $opa3->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa3->monto_orden_pago, 'monto_anticipado' => 0, 'anticipo' => 0,
        'recursor' => 0, 'pago_emergencia' => 0, 'id_forma_pago' => 1,
        'id_estado_orden_pago' => 1, 'id_usuario' => 1, 'tipo_factura' => 'PRESTADOR',
        'fecha_confirma_pago' => now(),
    ]);
    TesPagosParciales::create([
        'fecha_registra' => now(), 'fecha_confirma_pago' => now()->toDateString(),
        'id_forma_pago' => 1, 'monto_pago' => 500, 'monto_opa' => $opa3->monto_orden_pago,
        'id_usuario' => 1, 'id_pago' => $boleta3->id_pago, 'monto_restante' => 0,
    ]);
    $res = $repo->anularOpa($opa3->id_orden_pago, 'probando');
    echo "  {$res['message']}\n";
    $r[] = (!$res['ok'] && str_contains($res['message'], 'confirmados'));
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho)\n";
}
