<?php
// emitirPagoDeFecha() no validaba que la cuenta de origen fuera de la misma razon social que la
// OPA. La validacion existia SOLO al confirmar el pago (TesPagosController), asi que se podia
// emitir un eCheq desde una cuenta de otra entidad del grupo y enterarse recien al final, con el
// instrumento cargado. Caso real: OPA id 4521 (razon 1) con un abono de $100.000 sobre una cuenta
// del Macro de la razon 2. Reportado el 2026-09-07.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $repo = new TesInstrumentoPagoRepository();
    $opaRepo = new TestOrdenPagoRepository();

    // Una OPA viva con facturas (o sea, con razon social) y monto pagable disponible.
    $candidata = null;
    $opas = DB::table('tb_tes_orden_pago as o')
        ->join('tb_tes_orden_pago_detalle as od', 'od.id_orden_pago', '=', 'o.id_orden_pago')
        ->whereIn('o.id_estado_orden_pago', [1, 2, 4, 6])
        ->orderByDesc('o.id_orden_pago')
        ->distinct()
        ->limit(400)
        ->pluck('o.id_orden_pago');

    foreach ($opas as $idOpa) {
        $razon = $opaRepo->razonSocialDeOpa($idOpa);
        if (!$razon) { continue; }
        if ($opaRepo->montoPagableOpa($idOpa) < 100) { continue; }
        // Tiene que existir una cuenta de OTRA razon para poder probar el rechazo.
        $propia = DB::table('tb_tes_cuentas_bancarias')->where('id_razon', $razon)->first();
        $ajena = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_razon')
            ->where('id_razon', '!=', $razon)->first();

        // En OSV hoy TODAS las cuentas estan en la razon 1, asi que no hay una ajena real. Se
        // fabrica una dentro de la transaccion (se revierte con el rollback) para que el test
        // valide el codigo igual en las dos bases.
        if (!$ajena && $propia) {
            $otraRazon = DB::table('tb_razones_sociales')->where('id_razon', '!=', $razon)->value('id_razon');
            $ultima = DB::table('tb_tes_cuentas_bancarias')->orderByDesc('id_cuenta_bancaria')->first();
            if ($otraRazon && $ultima) {
                $idAjena = DB::table('tb_tes_cuentas_bancarias')->insertGetId(
                    array_merge((array) $ultima, [
                        'id_cuenta_bancaria' => null,
                        'nombre_cuenta' => 'TEST OTRA RAZON',
                        'id_razon' => $otraRazon,
                    ])
                );
                $ajena = DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $idAjena)->first();
                echo "  (cuenta ajena fabricada para el test: id {$idAjena}, razon {$otraRazon})\n";
            }
        }

        if ($ajena && $propia) {
            $candidata = (object) compact('idOpa', 'razon', 'ajena', 'propia');
            break;
        }
    }

    if (!$candidata) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }

    echo "OPA id {$candidata->idOpa} | razon social {$candidata->razon}\n";
    echo "  cuenta propia: {$candidata->propia->nombre_cuenta} (razon {$candidata->propia->id_razon})\n";
    echo "  cuenta ajena : {$candidata->ajena->nombre_cuenta} (razon {$candidata->ajena->id_razon})\n\n";

    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $candidata->idOpa, 'fecha_registra' => now(),
        'monto_opa' => 100, 'monto_anticipado' => 0, 'anticipo' => 0,
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

    echo "--- 1: emitir con una cuenta de OTRA razon social -> tiene que fallar ---\n";
    try {
        $repo->emitirPagoDeFecha($nuevaFecha(), [
            'monto' => 100, 'id_forma_pago' => 1,
            'id_cuenta_bancaria' => $candidata->ajena->id_cuenta_bancaria,
        ]);
        echo "  NO fallo: dejo emitir desde otra razon social\n";
        $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'razón social de la orden');
    }
    echo $ok(end($r));

    echo "--- 2: emitir con una cuenta de LA MISMA razon social -> tiene que pasar ---\n";
    try {
        $abono = $repo->emitirPagoDeFecha($nuevaFecha(), [
            'monto' => 100, 'id_forma_pago' => 1,
            'id_cuenta_bancaria' => $candidata->propia->id_cuenta_bancaria,
        ]);
        echo "  abono {$abono->id_pago_parcial} emitido con cuenta {$abono->id_cuenta_bancaria}\n";
        $r[] = ((int) $abono->id_cuenta_bancaria === (int) $candidata->propia->id_cuenta_bancaria);
    } catch (\Throwable $e) {
        echo "  fallo inesperado: {$e->getMessage()}\n";
        $r[] = false;
    }
    echo $ok(end($r));

    echo "--- 3: una OPA sin facturas (anticipo) no tiene razon social -> no valida ---\n";
    $sinFacturas = DB::table('tb_tes_orden_pago as o')
        ->leftJoin('tb_tes_orden_pago_detalle as od', 'od.id_orden_pago', '=', 'o.id_orden_pago')
        ->whereNull('od.id_orden_pago_detalle')->value('o.id_orden_pago');
    if ($sinFacturas) {
        $r[] = is_null($opaRepo->razonSocialDeOpa($sinFacturas));
        echo "  OPA {$sinFacturas} razon social: " . var_export($opaRepo->razonSocialDeOpa($sinFacturas), true) . " (esperado NULL)\n";
    } else {
        echo "  no hay OPA sin facturas en esta base, se saltea\n";
        $r[] = true;
    }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
