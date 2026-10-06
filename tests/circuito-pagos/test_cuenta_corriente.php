<?php
// Cuenta corriente (punto 10): deuda, cobros, anticipos y saldo neto.

use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TesCuentaCorrienteRepository as CC;
use App\Http\Controllers\Tesoreria\Repository\TesImputacionFifoRepository as Fifo;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Emite N pagos sobre una OPA, creando lo que haga falta del camino real:
 * boleta -> fechas planificadas -> emitir cada pago con monto y forma.
 *
 * Devuelve los abonos creados. Reemplaza al viejo crearInstrumentos(), que ya no existe:
 * el monto y la forma se definen al emitir, no al planificar. (2026_09_04_101000)
 */
function emitirPagos($inst, $idOpa, array $pagos) {
    $boleta = \App\Models\Tesoreria\TesPagoEntity::where('id_orden_pago', $idOpa)
        ->where('id_estado_orden_pago', '!=', 3)->first();

    if (!$boleta) {
        $opa = \App\Models\Tesoreria\TesOrdenPagoEntity::find($idOpa);
        $boleta = \App\Models\Tesoreria\TesPagoEntity::create([
            'id_orden_pago' => $idOpa, 'fecha_registra' => now(),
            'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
            'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
            'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
            'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
        ]);
    }

    // Desde el 2026-09-05 no se puede emitir por encima del monto PAGABLE de la orden (lo
    // imputado menos el debito de liquidacion). Estos tests corren sobre OPAs reales que muchas
    // veces ya tienen abonos historicos ocupando todo el tope, y ademas pedian montos fijos que
    // no tienen relacion con el monto real de la orden. Para que cada test controle su propio
    // presupuesto: se limpian los abonos previos (estamos dentro de la transaccion que se
    // revierte) y se recorta cada monto a lo que la orden permite.
    $opaRepo = new \App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository();

    // La limpieza va UNA SOLA VEZ por orden: varios tests llaman a este helper dos o tres veces
    // seguidas para armar el escenario (pagar una factura, despues otra), y borrar en cada
    // llamada les borraba el pago anterior.
    static $limpiadas = [];
    if (!isset($limpiadas[$idOpa])) {
        \App\Models\Tesoreria\TesPagosParciales::whereIn(
            'id_pago',
            \App\Models\Tesoreria\TesPagoEntity::where('id_orden_pago', $idOpa)->pluck('id_pago')
        )->delete();
        $limpiadas[$idOpa] = true;
    }

    $tope = $opaRepo->montoPagableOpa($idOpa);
    if ($tope <= 0) {
        $tope = (float) \App\Models\Tesoreria\TesOrdenPagoEntity::find($idOpa)->monto_orden_pago;
    }
    // Se ESCALAN los montos pedidos para que entren en el tope, en vez de recortar de a uno:
    // recortando, el primer pago se comia todo y los siguientes quedaban en 0 (y explotaban con
    // "hay que indicar el monto"). Escalando se conserva la proporcion que cada test quiso
    // probar (mitad y mitad, 2 a 1, etc.).
    $pedido = 0.0;
    foreach ($pagos as $p) { $pedido += (float) $p['monto']; }

    // El presupuesto es lo que queda del tope descontando lo ya emitido en llamadas anteriores.
    $yaEmitido = (float) \App\Models\Tesoreria\TesPagosParciales::whereIn(
        'id_pago',
        \App\Models\Tesoreria\TesPagoEntity::where('id_orden_pago', $idOpa)->pluck('id_pago')
    )->whereNotIn('id_estado_instrumento', [5, 6])->sum('monto_pago');

    $restante = max(0.0, $tope - $yaEmitido);
    $factor = ($pedido > $restante && $pedido > 0) ? ($restante / $pedido) : 1.0;

    $creados = [];
    $orden = \Illuminate\Support\Facades\DB::table('tb_tes_fecha_probable_pago')
        ->where('id_pago', $boleta->id_pago)->max('orden_cuotas') ?? 0;

    foreach ($pagos as $p) {
        $orden++;
        $idFecha = \Illuminate\Support\Facades\DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'fecha_registra' => now(),
            'fecha_probable_pago' => $p['fecha'],
            'orden_cuotas' => $orden,
            'id_pago' => $boleta->id_pago,
        ]);

        $monto = min(round((float) $p['monto'] * $factor, 2), round($restante, 2));
        $restante -= $monto;

        $creados[] = $inst->emitirPagoDeFecha($idFecha, [
            'monto' => $monto,
            'id_forma_pago' => $p['id_forma_pago'] ?? 7,
            'id_banco_emisor' => $p['id_banco_emisor'] ?? null,
            'id_cuenta_bancaria' => $p['id_cuenta_bancaria'] ?? null,
        ]);
    }

    return $creados;
}


Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$r = [];
$opaRepo = new TestOrdenPagoRepository();
$fifo = new Fifo($opaRepo);
$ant  = new Ant($opaRepo);
$cc   = new CC($fifo, $ant);
$inst = new Inst();

// Prestador con facturas en valorizacion final Y al menos una LIBRE (sin OP), para que el
// caso 6 (aplicar el anticipo) se pueda ejercitar de verdad.
$idPrestador = DB::table('tb_facturacion_datos as f')->where('f.estado', 3)
    ->whereNotNull('f.id_prestador')->where('f.total_neto', '>', 0)
    ->whereNotIn('f.id_factura', function ($q) { $q->select('id_factura')->from('tb_tes_orden_pago_detalle'); })
    ->select('f.id_prestador', DB::raw('COUNT(*) n'))->groupBy('f.id_prestador')
    ->havingRaw('COUNT(*) >= 1')->orderBy('f.id_prestador')->value('f.id_prestador');

if (!$idPrestador) { echo "SIN PRESTADOR\n"; return; }

DB::beginTransaction();
try {
    echo "--- 1: resumen de un prestador real ---\n";
    $res = $cc->resumen($idPrestador, 'PRESTADOR');
    foreach ($res as $k => $v) { printf("  %-22s %s\n", $k, is_numeric($v) ? number_format($v, 2, ',', '.') : $v); }
    $r['resumen sale'] = ($res['cantidad_facturas'] > 0);
    $r['facturado positivo'] = ($res['total_facturado'] > 0);

    echo "\n--- 2: la aritmetica cierra ---\n";
    $calc = round($res['total_facturado'] - $res['total_pagado'], 2);
    printf("  facturado - pagado = %s | deuda_pendiente = %s\n",
        number_format($calc, 2, ',', '.'), number_format($res['deuda_pendiente'], 2, ',', '.'));
    printf("  deuda - anticipos = %s | saldo_neto = %s\n",
        number_format(round($calc - $res['anticipos_disponibles'], 2), 2, ',', '.'),
        number_format($res['saldo_neto'], 2, ',', '.'));
    $r['deuda coherente'] = (abs(max(0, $calc) - $res['deuda_pendiente']) < 0.01);
    $r['neto coherente'] = (abs(($calc - $res['anticipos_disponibles']) - $res['saldo_neto']) < 0.01);

    echo "\n--- 3: movimientos con saldo acumulado ---\n";
    $movs = $cc->movimientos($idPrestador, 'PRESTADOR');
    foreach (array_slice($movs, 0, 6) as $m) {
        printf("  %s %-8s %-10s debe=%14s haber=%14s saldo=%14s\n", $m['fecha'], $m['tipo'],
            $m['comprobante'], number_format($m['debe'], 2, ',', '.'),
            number_format($m['haber'], 2, ',', '.'), number_format($m['saldo'], 2, ',', '.'));
    }
    $r['hay movimientos'] = (count($movs) > 0);

    // El saldo del ultimo movimiento tiene que ser facturado - pagado
    $ultimo = end($movs);
    printf("  saldo final=%s | facturado-pagado=%s\n",
        number_format($ultimo['saldo'], 2, ',', '.'), number_format($calc, 2, ',', '.'));
    $r['saldo final cuadra'] = (abs($ultimo['saldo'] - $calc) < 0.01);

    echo "\n--- 4: orden cronologico ---\n";
    $fechas = array_column($movs, 'fecha');
    $ord = $fechas; sort($ord);
    echo "  ordenado: " . var_export($fechas === $ord, true) . "\n";
    $r['cronologico'] = ($fechas === $ord);

    echo "\n--- 5: un anticipo aparece como saldo a favor, NO como cobro de factura ---\n";
    $resAntes = $cc->resumen($idPrestador, 'PRESTADOR');
    $deudaAntes = $resAntes['deuda_pendiente'];
    // La factura a la que se va a aplicar, elegida ANTES: el anticipo tiene que nacer con SU razon
    // social. Antes se creaba con "la primera razon de la tabla" y se aplicaba a una factura de otra
    // entidad — el cruce que la regla de CLAUDE.md prohibe, y que la guarda nueva corta. (2026-10-01)
    $aplicable = collect($ant->facturasAplicables($idPrestador, 'PRESTADOR'))->first();
    $libre = $aplicable ? DB::table('tb_facturacion_datos')->where('id_factura', $aplicable['id_factura'])->first() : null;
    $razonAnticipo = $libre->id_locatorio ?? DB::table('tb_razones_sociales')->value('id_razon');
    $a = $ant->crearAnticipo($idPrestador, 'PRESTADOR', 250000, 'Anticipo CC', [], $razonAnticipo);
    $c = emitirPagos($inst, $a->id_orden_pago,  [['monto' => 250000, 'fecha' => '2026-09-30', 'id_banco_emisor' => 2]]);
    $inst->guardarBorradorNumero($c[0]->id_pago_parcial, 'CC-TEST-1');
    $inst->confirmarEmisionDeOpa($a->id_orden_pago, $opaRepo);
    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el
    // descuento del saldo salen de ahi, no del camino de acreditacion. Este test no prueba ese
    // flujo, asi que se deja la boleta confirmada directamente.
    DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);
    // Y los abonos: desde 2026_09_10_100000 la guarda mira `fecha_confirmado_en_pago`
    // de CADA abono, no la confirmacion de la boleta. Un eCheq emitido despues de que la
    // boleta ya estaba confirmada no hereda el permiso.
    DB::table('tb_tes_pago_parcial')
        ->whereIn('id_pago', DB::table('tb_tes_pago')->where('id_orden_pago', $a->id_orden_pago)->pluck('id_pago'))
        ->whereNull('fecha_confirmado_en_pago')
        ->update(['fecha_confirmado_en_pago' => now()]);
    $inst->marcarAcreditado($c[0]->id_pago_parcial, '2026-09-30', $opaRepo);
    $ant->actualizarEstadoAnticipo($a->id_orden_pago);

    $res2 = $cc->resumen($idPrestador, 'PRESTADOR');
    printf("  deuda antes=%s despues=%s (no debe cambiar)\n",
        number_format($deudaAntes, 2, ',', '.'), number_format($res2['deuda_pendiente'], 2, ',', '.'));
    printf("  anticipos disponibles=%s | saldo neto=%s\n",
        number_format($res2['anticipos_disponibles'], 2, ',', '.'),
        number_format($res2['saldo_neto'], 2, ',', '.'));
    $r['anticipo no cancela deuda'] = (abs($res2['deuda_pendiente'] - $deudaAntes) < 0.01);
    $r['anticipo suma a favor'] = ($res2['anticipos_disponibles'] >= 250000.0);
    // Diferencia antes/despues: el prestador puede tener otros anticipos reales con saldo (ZENTRUM
    // tiene la OPA-16759 del usuario), asi que comparar contra 'deuda - 250000' daba falso negativo.
    $r['neto baja por anticipo'] = (abs(($resAntes['saldo_neto'] - $res2['saldo_neto']) - 250000) < 0.01);

    echo "\n--- 6: al APLICAR el anticipo, ahi si baja la deuda ---\n";
    // $libre ya se eligio arriba, de la misma razon social que el anticipo.
    if ($libre) {
        $monto = min(250000, (float) $aplicable['saldo']);
        $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libre->id_factura, 'monto' => $monto]]);
        $ant->actualizarEstadoAnticipo($a->id_orden_pago);

        $res3 = $cc->resumen($idPrestador, 'PRESTADOR');
        printf("  aplicado %s a la factura %s\n", number_format($monto, 2, ',', '.'), $libre->numero);
        printf("  deuda: %s -> %s | anticipos: %s -> %s\n",
            number_format($res2['deuda_pendiente'], 2, ',', '.'), number_format($res3['deuda_pendiente'], 2, ',', '.'),
            number_format($res2['anticipos_disponibles'], 2, ',', '.'), number_format($res3['anticipos_disponibles'], 2, ',', '.'));
        $r['aplicar baja deuda'] = ($res3['deuda_pendiente'] < $res2['deuda_pendiente']);
        $r['aplicar consume saldo'] = ($res3['anticipos_disponibles'] < $res2['anticipos_disponibles']);
        // El neto no deberia moverse: se cambio saldo a favor por menos deuda
        printf("  saldo neto: %s -> %s (no deberia moverse)\n",
            number_format($res2['saldo_neto'], 2, ',', '.'), number_format($res3['saldo_neto'], 2, ',', '.'));
        $r['neto estable al aplicar'] = (abs($res3['saldo_neto'] - $res2['saldo_neto']) < 0.01);
    } else {
        echo "  (sin factura libre para aplicar)\n";
    }

    echo "\n--- 7: las facturas ANULADAS no cuentan ---\n";
    $fs = $cc->facturasConDeuda($idPrestador, 'PRESTADOR');
    $anuladas = $fs->filter(fn($f) => (int) $f->estado === 4)->count();
    echo "  facturas anuladas incluidas: {$anuladas} (esperado 0)\n";
    $r['sin anuladas'] = ($anuladas === 0);

    // Criterio definido por Contaduria el 2026-09-04: las facturas todavia no valorizadas
    // TAMBIEN son deuda. Antes se excluian; este test afirmaba lo contrario.
    echo "
--- 8: las NO valorizadas SI cuentan como deuda ---
";
    $noValorizadas = $fs->filter(fn($f) => !in_array((int) $f->estado, [3, 4], true))->count();
    $existentes = DB::table('tb_facturacion_datos')
        ->where('id_prestador', $idPrestador)->whereNotIn('estado', [3, 4])->count();
    echo "  el prestador tiene {$existentes} no valorizadas; incluidas en la cuenta: {$noValorizadas}
";
    $r['incluye no valorizadas'] = ($noValorizadas === $existentes);

    echo "\n--- 9: cuentaCorriente() devuelve las 3 secciones ---\n";
    $full = $cc->cuentaCorriente($idPrestador, 'PRESTADOR');
    echo "  claves: " . json_encode(array_keys($full)) . "\n";
    $r['estructura completa'] = (isset($full['resumen'], $full['movimientos'], $full['anticipos']));

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-28s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
