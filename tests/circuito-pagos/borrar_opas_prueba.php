<?php
// Borra OPAs de PRUEBA con todo lo que cuelga, devolviendo el saldo bancario que movieron.
// Uso: $opas = [ids...]; $revertirEstadoPago = [id_factura...]; require este archivo.
//
// Orden: asientos (lineas, historial, cabecera) -> movimientos bancarios (devolviendo el saldo)
// -> abonos -> comprobantes -> fechas -> boletas -> puente -> detalle -> OPAs (hijas primero).
use Illuminate\Support\Facades\DB;

$opas = array_values(array_unique($opas));
$revertirEstadoPago = $revertirEstadoPago ?? [];

$bol    = DB::table('tb_tes_pago')->whereIn('id_orden_pago', $opas)->pluck('id_pago')->all();
$abonos = DB::table('tb_tes_pago_parcial')->whereIn('id_pago', $bol)->pluck('id_pago_parcial')->all();
$movs   = DB::table('tb_tes_movimiento_cuenta_bancaria')->whereIn('id_pago', $bol)->get();

// Asientos del pago: por historial y por referencia, SOLO de modelos de pago (los de FACTURA
// usan numero_referencia = id_factura y los numeros colisionan con los de boleta).
$idsAsi = DB::table('tb_cont_asientos_pago_historial')->whereIn('id_pago', $bol)->pluck('id_asiento_contable')
    ->merge(DB::table('tb_cont_asientos_contables')->whereIn('numero_referencia', $bol)
        ->whereIn('asiento_modelo', ['PAGO', 'DEBITO_INSTRUMENTO'])->pluck('id_asiento_contable'))
    ->unique()->values()->all();

// Guarda: ninguna OPA ajena puede colgar de las que se borran.
$hijasAjenas = DB::table('tb_tes_orden_pago')->whereIn('id_opa_anticipo', $opas)
    ->whereNotIn('id_orden_pago', $opas)->count();
if ($hijasAjenas) { echo "ABORTA: hay {$hijasAjenas} aplicacion(es) fuera de la lista\n"; return; }

DB::beginTransaction();
try {
    $n = [];
    $n['asiento lineas']  = DB::table('tb_cont_asientos_contables_detalle')->whereIn('id_asiento_contable', $idsAsi)->delete();
    $n['historial asi']   = DB::table('tb_cont_asientos_pago_historial')->whereIn('id_pago', $bol)->delete();
    $n['asientos']        = DB::table('tb_cont_asientos_contables')->whereIn('id_asiento_contable', $idsAsi)->delete();

    foreach ($movs as $m) {
        // Se deshace el efecto: un EGRESO habia restado, se suma; un INGRESO, al reves.
        $signo = strtoupper((string) $m->tipo_movimiento) === 'EGRESO' ? '+' : '-';
        DB::table('tb_tes_cuentas_bancarias')->where('id_cuenta_bancaria', $m->id_cuenta_bancaria)
            ->update(['saldo_disponible' => DB::raw("saldo_disponible {$signo} " . (float) $m->monto)]);
        echo "  cuenta {$m->id_cuenta_bancaria}: {$signo}\$" . number_format((float) $m->monto, 2, ',', '.') . "\n";
    }
    $n['movimientos']     = DB::table('tb_tes_movimiento_cuenta_bancaria')->whereIn('id_pago', $bol)->delete();
    $n['abonos']          = DB::table('tb_tes_pago_parcial')->whereIn('id_pago', $bol)->delete();
    $n['comprobantes']    = DB::table('tb_test_pago_detalle_comprobantes')->whereIn('id_pago', $bol)->delete();
    $n['fechas']          = DB::table('tb_tes_fecha_probable_pago')->whereIn('id_pago', $bol)->delete();
    $n['boletas']         = DB::table('tb_tes_pago')->whereIn('id_pago', $bol)->delete();
    $n['puente']          = DB::table('tb_tes_opa_factura')->whereIn('id_orden_pago', $opas)->delete();
    $n['detalle']         = DB::table('tb_tes_orden_pago_detalle')->whereIn('id_orden_pago', $opas)->delete();
    $n['opas hijas']      = DB::table('tb_tes_orden_pago')->whereIn('id_orden_pago', $opas)->whereNotNull('id_opa_anticipo')->delete();
    $n['opas']            = DB::table('tb_tes_orden_pago')->whereIn('id_orden_pago', $opas)->delete();
    $n['estado_pago a 0'] = $revertirEstadoPago
        ? DB::table('tb_facturacion_datos')->whereIn('id_factura', $revertirEstadoPago)->update(['estado_pago' => 0]) : 0;
    DB::commit();
    foreach ($n as $k => $v) echo '  ' . str_pad($k, 16) . $v . "\n";
} catch (\Throwable $e) {
    DB::rollBack();
    echo "ROLLBACK: {$e->getMessage()}\n";
}

// Verificacion: nada huerfano en ninguna tabla.
$rest = 0;
foreach (DB::select("SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES tb ON tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_SCHEMA = c.TABLE_SCHEMA
        WHERE c.TABLE_SCHEMA = DATABASE() AND tb.TABLE_TYPE = 'BASE TABLE'
          AND c.COLUMN_NAME IN ('id_orden_pago','id_opa_anticipo','id_pago','id_pago_parcial')") as $x) {
    $ids = $x->c === 'id_pago' ? $bol : ($x->c === 'id_pago_parcial' ? $abonos : $opas);
    if (empty($ids)) { continue; }
    $c = DB::table($x->t)->whereIn($x->c, $ids)->count();
    $rest += $c;
    if ($c) { echo "  QUEDA: {$x->t}.{$x->c} {$c}\n"; }
}
echo "  referencias huerfanas: {$rest} | asientos que quedan: "
    . DB::table('tb_cont_asientos_contables')->whereIn('id_asiento_contable', $idsAsi)->count() . "\n";
