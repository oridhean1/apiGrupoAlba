<?php
// Una OPA que mezcla facturas de DOS razones sociales no tiene una razon social unica, pero el
// codigo la resumia con `->value()` / `MIN(id_locatorio)`, o sea eligiendo una al azar.
// En la OPA-1102 (id 3152) eso devolvia la razon 1 -> $273.838 de un total de $12.312.369 (el 2%).
// Consecuencias: el listado mostraba "razon 1" para una orden filtrada por razon 2, el desplegable
// ofrecia solo cuentas de la razon 1, y la validacion RECHAZABA la cuenta del 98% del monto.
// Reportado el 2026-09-09: "me trae de una razon social que no pido".

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $opaRepo = new TestOrdenPagoRepository();
    $inst = new Inst();

    // Se busca una OPA real que mezcle razones; si no hay, se fabrica una dentro de la transaccion.
    $mixta = DB::selectOne("
        SELECT od.id_orden_pago FROM tb_tes_orden_pago_detalle od
        JOIN tb_facturacion_datos fd ON fd.id_factura = od.id_factura
        WHERE fd.id_locatorio IS NOT NULL
        GROUP BY od.id_orden_pago HAVING COUNT(DISTINCT fd.id_locatorio) > 1 LIMIT 1");

    if ($mixta) {
        $idOpa = (int) $mixta->id_orden_pago;
        echo "OPA mixta real: id {$idOpa}\n";
    } else {
        // Fabricar: tomar una OPA de una sola razon y mover una de sus facturas a otra razon.
        $cand = DB::selectOne("
            SELECT od.id_orden_pago FROM tb_tes_orden_pago_detalle od
            JOIN tb_facturacion_datos fd ON fd.id_factura = od.id_factura
            WHERE fd.id_locatorio IS NOT NULL
            GROUP BY od.id_orden_pago HAVING COUNT(*) > 1 AND COUNT(DISTINCT fd.id_locatorio) = 1 LIMIT 1");
        if (!$cand) { echo "SIN OPA CANDIDATA\n"; DB::rollBack(); return; }
        $idOpa = (int) $cand->id_orden_pago;
        $actual = $opaRepo->razonesSocialesDeOpa($idOpa)[0];
        $otra = DB::table('tb_razones_sociales')->where('id_razon', '!=', $actual)->value('id_razon');
        $unaFactura = DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $idOpa)->value('id_factura');
        DB::table('tb_facturacion_datos')->where('id_factura', $unaFactura)->update(['id_locatorio' => $otra]);
        echo "OPA mixta fabricada: id {$idOpa} (razones {$actual} y {$otra})\n";
    }

    $razones = $opaRepo->razonesSocialesDeOpa($idOpa);
    echo "  razones de la orden: " . implode(', ', $razones) . "\n\n";

    echo "--- 1: razonesSocialesDeOpa devuelve TODAS ---\n";
    $r[] = (count($razones) >= 2);
    echo "  cantidad: " . count($razones) . " (esperado >= 2)\n";
    echo $ok(end($r));

    echo "--- 2: razonSocialDeOpa devuelve NULL cuando es ambigua (antes elegia una al azar) ---\n";
    $unica = $opaRepo->razonSocialDeOpa($idOpa);
    echo "  razonSocialDeOpa: " . var_export($unica, true) . " (esperado NULL)\n";
    $r[] = is_null($unica);
    echo $ok(end($r));

    echo "--- 3: una cuenta de CUALQUIERA de sus razones es valida ---\n";
    $metodo = new ReflectionMethod(Inst::class, 'validarCuentaDeRazonSocial');
    $metodo->setAccessible(true);
    $aceptadas = 0; $probadas = 0;
    foreach ($razones as $razon) {
        $c = DB::table('tb_tes_cuentas_bancarias')->where('id_razon', $razon)->first();
        if (!$c) { continue; }
        $probadas++;
        try { $metodo->invoke($inst, $c->id_cuenta_bancaria, $idOpa, $opaRepo); $aceptadas++; }
        catch (\Throwable $e) { echo "  RECHAZO la cuenta de la razon {$razon}: {$e->getMessage()}\n"; }
    }
    echo "  cuentas aceptadas: {$aceptadas} de {$probadas} razones con cuenta\n";
    $r[] = ($probadas > 0 && $aceptadas === $probadas);
    echo $ok(end($r));

    echo "--- 4: una cuenta de una razon AJENA sigue rechazada ---\n";
    $ajena = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_razon')
        ->whereNotIn('id_razon', $razones)->first();
    if (!$ajena) {
        $otraR = DB::table('tb_razones_sociales')->whereNotIn('id_razon', $razones)->value('id_razon');
        if ($otraR) {
            $ult = DB::table('tb_tes_cuentas_bancarias')->orderByDesc('id_cuenta_bancaria')->first();
            $idA = DB::table('tb_tes_cuentas_bancarias')->insertGetId(array_merge((array) $ult, [
                'id_cuenta_bancaria' => null, 'nombre_cuenta' => 'TEST AJENA', 'id_razon' => $otraR,
            ]));
            $ajena = DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $idA)->first();
        }
    }
    if ($ajena) {
        try {
            $metodo->invoke($inst, $ajena->id_cuenta_bancaria, $idOpa, $opaRepo);
            echo "  NO fallo: acepto una cuenta ajena\n"; $r[] = false;
        } catch (\Throwable $e) {
            echo "  rechazado: {$e->getMessage()}\n";
            $r[] = str_contains($e->getMessage(), 'razón social de la orden');
        }
    } else {
        echo "  (no hay razon ajena disponible en esta base, se saltea)\n"; $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 5: una OPA de UNA sola razon sigue comportandose igual ---\n";
    $simple = DB::selectOne("
        SELECT od.id_orden_pago FROM tb_tes_orden_pago_detalle od
        JOIN tb_facturacion_datos fd ON fd.id_factura = od.id_factura
        WHERE fd.id_locatorio IS NOT NULL AND od.id_orden_pago <> {$idOpa}
        GROUP BY od.id_orden_pago HAVING COUNT(DISTINCT fd.id_locatorio) = 1 LIMIT 1");
    if ($simple) {
        $idS = (int) $simple->id_orden_pago;
        $rs = $opaRepo->razonesSocialesDeOpa($idS);
        $us = $opaRepo->razonSocialDeOpa($idS);
        echo "  OPA {$idS}: razones=" . implode(',', $rs) . " | razonSocialDeOpa=" . var_export($us, true) . "\n";
        $r[] = (count($rs) === 1 && (int) $us === (int) $rs[0]);
    } else { $r[] = true; }
    echo $ok(end($r));

    // Hasta el 2026-09-25 este caso esperaba que un anticipo sin facturas "aceptara cualquier
    // cuenta": ese era EL BUG (un anticipo se pagaba desde la cuenta de cualquier razón social).
    // La regla ahora es la inversa: sin razón social de donde validar, la guarda CORTA.
    echo "--- 6: una orden SIN facturas y SIN razón propia -> la guarda corta (no deja pasar) ---\n";
    $anticipo = DB::selectOne("
        SELECT o.id_orden_pago FROM tb_tes_orden_pago o
        LEFT JOIN tb_tes_orden_pago_detalle od ON od.id_orden_pago = o.id_orden_pago
        WHERE od.id_orden_pago_detalle IS NULL AND o.id_razon IS NULL LIMIT 1");
    if ($anticipo) {
        $cualquiera = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_razon')->first();
        $paso = true;
        try { $metodo->invoke($inst, $cualquiera->id_cuenta_bancaria, (int) $anticipo->id_orden_pago, $opaRepo); }
        catch (\Throwable $e) { $paso = false; }
        echo "  acepto una cuenta cualquiera: " . var_export($paso, true) . " (esperado false)\n";
        $r[] = !$paso;
    } else { echo "  no hay ordenes sin facturas y sin razon: nada que probar\n"; $r[] = true; }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
