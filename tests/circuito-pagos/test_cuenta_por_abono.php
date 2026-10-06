<?php
// La CUENTA DE ORIGEN vive en el abono, no en la boleta (2026_09_06_100000).
//
// Reportado el 2026-09-06: el modal de Confirmar Pago ofrecia un unico selector de cuenta para
// toda la orden, asi que no se podia pagar una orden con dos transferencias desde bancos
// distintos. La forma de pago y el banco emisor ya eran por abono; la cuenta era el unico de los
// tres que habia quedado a nivel boleta.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
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

    // Dos cuentas de BANCOS distintos: es el escenario que antes no se podia representar.
    //
    // Tienen que ser de la MISMA razon social que la OPA: desde el 2026-09-07 emitir desde una
    // cuenta de otra entidad del grupo se rechaza (ver test_razon_social_emision.php). Antes este
    // test tomaba las dos primeras cuentas que encontrara y daba la casualidad de que eran de
    // razones distintas, asi que empezo a fallar por el guard nuevo, no por un bug.
    $opaRepo = new \App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository();

    $opa = null;
    $cuentas = collect();

    foreach (
        TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
            ->where('monto_orden_pago', '>=', 5000)
            ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->limit(300)->get() as $cand
    ) {
        $razon = $opaRepo->razonSocialDeOpa($cand->id_orden_pago);
        if (!$razon) { continue; }

        $c = DB::table('tb_tes_cuentas_bancarias')
            ->whereNotNull('id_entidad_bancaria')->where('id_razon', $razon)->get()
            ->unique('id_entidad_bancaria')->take(2)->values();

        if ($c->count() >= 2) { $opa = $cand; $cuentas = $c; break; }
    }

    if (!$opa) { echo "SIN OPA CON DOS CUENTAS DE BANCOS DISTINTOS EN SU RAZON SOCIAL\n"; return; }

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    echo "OPA {$opa->num_orden_pago} | cuentas " . $cuentas->pluck('id_cuenta_bancaria')->implode(' y ')
        . " (bancos " . $cuentas->pluck('id_entidad_bancaria')->implode(' y ') . ")\n\n";

    $creados = [];
    foreach ($cuentas as $n => $c) {
        $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'fecha_registra' => now(), 'fecha_probable_pago' => '2026-11-0' . ($n + 1),
            'orden_cuotas' => $n + 1, 'id_pago' => $boleta->id_pago,
        ]);
        $creados[] = $inst->emitirPagoDeFecha($idFecha, [
            'monto' => 100, 'id_forma_pago' => 1, 'id_cuenta_bancaria' => $c->id_cuenta_bancaria,
        ]);
    }

    echo "--- 1: la cuenta queda guardada EN CADA ABONO ---\n";
    foreach ($creados as $i => $a) {
        $a->refresh();
        echo "  abono {$a->id_pago_parcial} cuenta={$a->id_cuenta_bancaria} banco={$a->id_banco_emisor}\n";
        $r[] = ((int) $a->id_cuenta_bancaria === (int) $cuentas[$i]->id_cuenta_bancaria);
    }
    echo $ok(!in_array(false, $r, true));

    echo "--- 2: la MISMA orden con pagos de DOS bancos distintos ---\n";
    $bancos = collect($creados)->pluck('id_banco_emisor')->unique();
    echo "  bancos distintos: {$bancos->count()} (esperado 2)\n";
    $r[] = ($bancos->count() === 2);
    echo $ok(end($r));

    echo "--- 3: el banco se deriva de la cuenta, no hace falta mandarlo ---\n";
    $coinciden = true;
    foreach ($creados as $i => $a) {
        $coinciden = $coinciden
            && ((int) $a->id_banco_emisor === (int) $cuentas[$i]->id_entidad_bancaria);
    }
    echo "  el banco de cada abono coincide con el de su cuenta: " . var_export($coinciden, true) . "\n";
    $r[] = $coinciden;
    echo $ok(end($r));

    echo "--- 4: si no se manda cuenta, se hereda la de la boleta ---\n";
    $boleta->id_cuenta_bancaria = $cuentas[0]->id_cuenta_bancaria;
    $boleta->save();
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'fecha_registra' => now(), 'fecha_probable_pago' => '2026-11-09',
        'orden_cuotas' => 9, 'id_pago' => $boleta->id_pago,
    ]);
    $heredado = $inst->emitirPagoDeFecha($idFecha, ['monto' => 50, 'id_forma_pago' => 1])->refresh();
    echo "  cuenta heredada: {$heredado->id_cuenta_bancaria} (esperado {$cuentas[0]->id_cuenta_bancaria})\n";
    $r[] = ((int) $heredado->id_cuenta_bancaria === (int) $cuentas[0]->id_cuenta_bancaria);
    echo $ok(end($r));

    echo "--- 5: el listado expone la cuenta de cada abono ---\n";
    $emitidos = collect($inst->listarEmitidos())
        ->whereIn('id_pago_parcial', collect($creados)->pluck('id_pago_parcial'));
    $conCuenta = $emitidos->filter(fn($e) => !is_null($e->cuentaBancaria))->count();
    echo "  abonos del listado con su cuenta resuelta: {$conCuenta} de {$emitidos->count()}\n";
    $r[] = ($emitidos->count() > 0 && $conCuenta === $emitidos->count());
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
