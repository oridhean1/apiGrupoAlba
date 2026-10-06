<?php
// Anticipos (punto 8): pagar sin factura, que quede saldo a favor, y aplicarlo despues.

use App\Http\Controllers\Tesoreria\Repository\TesAnticipoRepository as Ant;
use App\Http\Controllers\Tesoreria\Repository\TesImputacionFifoRepository as Fifo;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
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
$ant  = new Ant($opaRepo);
$fifo = new Fifo($opaRepo);
$inst = new Inst();

// Dos facturas con saldo para aplicar, del mismo prestador. Mismo criterio que la pantalla.
require __DIR__ . '/fixture_aplicables.php';
$fx = prestadorConAplicables(new Ant($opaRepo), 2, 2);
// Montos derivados del saldo REAL de las facturas, no fijos: el fixture puede caer en facturas de
// $1.000 o de $1.000.000 segun lo que haya en la base.
//   P1 = la mitad de lo que le queda a la primera factura (aplicacion parcial)
//   R  = lo que le queda a la segunda (con eso se consume el anticipo)
//   M  = monto del anticipo = P1 + R
if ($fx) {
    $saldoF0 = (float) $fx['facturas'][0]['saldo'];
    $P1 = round(floor($saldoF0 * 50) / 100, 2);
    $R  = round((float) $fx['facturas'][1]['saldo'], 2);
    $M  = round($P1 + $R, 2);
}
$libres = $fx ? collect($fx['facturas'])->take(2)->map(fn($f) => (object) array_merge($f, ['id_prestador' => $fx['id_prestador']]))->values() : collect();
if ($libres->count() < 2) { echo "SIN FACTURAS LIBRES\n"; return; }
$idPrestador = $libres[0]->id_prestador;

DB::beginTransaction();
try {
    echo "--- 1: crear anticipo (sin facturas) ---\n";
    // La razon social es obligatoria desde 2026_09_25_100000: sin ella no se puede validar de
    // que cuenta sale la plata. Se toma una real de la base.
    // La razon de las facturas del fixture: el anticipo solo se aplica a su propia razon social.
    $idRazonTest = $fx['id_razon'];
    $a = $ant->crearAnticipo($idPrestador, 'PRESTADOR', $M, 'Anticipo de prueba', [], $idRazonTest);
    echo "  {$a->num_orden_pago} tipo={$a->tipo_opa} monto={$a->monto_orden_pago} estado={$a->id_estado_orden_pago}\n";
    echo "  facturas asociadas: " . DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $a->id_orden_pago)->count() . " (esperado 0)\n";
    $r['anticipo creado'] = ($a->tipo_opa === Ant::TIPO_ANTICIPO && !empty($a->num_orden_pago));
    $r['sin facturas'] = (DB::table('tb_tes_orden_pago_detalle')->where('id_orden_pago', $a->id_orden_pago)->count() === 0);

    echo "\n--- 2: sin pagar todavia -> NO hay saldo disponible ---\n";
    echo "  saldo: " . $ant->saldoDisponible($a->id_orden_pago) . " (esperado 0)\n";
    $r['sin pagar sin saldo'] = ($ant->saldoDisponible($a->id_orden_pago) == 0.0);

    echo "\n--- 3: no se puede aplicar lo que no se pago ---\n";
    try {
        $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libres[0]->id_factura, 'monto' => 100]]);
        echo "  NO corto\n"; $r['bloquea sin saldo'] = false;
    } catch (\Throwable $e) { echo "  corto: " . substr($e->getMessage(), 0, 80) . "\n"; $r['bloquea sin saldo'] = true; }

    echo "\n--- 4: se paga el anticipo -> aparece el saldo a favor ---\n";
    $c = emitirPagos($inst, $a->id_orden_pago,  [['monto' => $M, 'fecha' => '2026-09-30', 'id_banco_emisor' => 2]]);
    $inst->guardarBorradorNumero($c[0]->id_pago_parcial, 'ANT-TEST-1');
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

    $saldo = $ant->saldoDisponible($a->id_orden_pago);
    echo "  saldo disponible: " . number_format($saldo, 2, ',', '.') . "\n";
    echo "  estado anticipo: " . $a->refresh()->id_estado_orden_pago . " (5=PAGADO)\n";
    $r['saldo tras pagar'] = (abs($saldo - $M) < 0.01);
    $r['anticipo pagado'] = ((int) $a->id_estado_orden_pago === 5);

    echo "\n--- 5: saldo a favor del prestador ---\n";
    echo "  a favor: " . number_format($ant->saldoAFavor($idPrestador, 'PRESTADOR'), 2, ',', '.') . "\n";
    echo "  anticipos con saldo: " . count($ant->anticiposConSaldo($idPrestador, 'PRESTADOR')) . "\n";
    $r['saldo a favor'] = ($ant->saldoAFavor($idPrestador, 'PRESTADOR') >= $M - 0.01);

    echo "\n--- 6: no se puede aplicar mas de lo disponible ---\n";
    try {
        $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libres[0]->id_factura, 'monto' => $M * 5]]);
        echo "  NO corto\n"; $r['bloquea exceso'] = false;
    } catch (\Throwable $e) { echo "  corto: " . substr($e->getMessage(), 0, 95) . "\n"; $r['bloquea exceso'] = true; }

    echo "\n--- 7: aplicar una parte a una factura ---\n";
    $ap = $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libres[0]->id_factura, 'monto' => $P1]], 'Aplicacion parcial');
    echo "  {$ap->num_orden_pago} tipo={$ap->tipo_opa} monto={$ap->monto_orden_pago} id_opa_anticipo={$ap->id_opa_anticipo}\n";
    $pagosDeAplicacion = DB::table('tb_tes_pago')->where('id_orden_pago', $ap->id_orden_pago)->count();
    echo "  pagos generados por la aplicacion: {$pagosDeAplicacion} (esperado 0, la plata ya salio)\n";
    $r['aplicacion creada'] = ($ap->tipo_opa === Ant::TIPO_APLICACION);
    $r['vinculo al anticipo'] = ((int) $ap->id_opa_anticipo === (int) $a->id_orden_pago);
    $r['no genera pago'] = ($pagosDeAplicacion === 0);

    echo "\n--- 8: el saldo bajo ---\n";
    $saldo2 = $ant->saldoDisponible($a->id_orden_pago);
    echo "  saldo: " . number_format($saldo2, 2, ',', '.') . " (esperado 600.000)\n";
    $r['saldo baja'] = (abs($saldo2 - $R) < 0.01);

    echo "\n--- 9: la APLICACION cuenta como cubierta (no tiene pagos propios) ---\n";
    $cub = $opaRepo->montoCubiertoOpa($ap->id_orden_pago);
    $pag = $opaRepo->montoPagadoOpa($ap->id_orden_pago);
    echo "  montoPagado={$pag} (0: no tiene instrumentos) | montoCubierto={$cub}\n";
    echo "  estado de la aplicacion: " . $ap->refresh()->id_estado_orden_pago . " (5=PAGADO)\n";
    $r['cubierta sin pagos'] = ($pag == 0.0 && abs($cub - $P1) < 0.01);
    $r['aplicacion pagada'] = ((int) $ap->id_estado_orden_pago === 5);

    echo "\n--- 10: el FIFO ve la factura como cubierta ---\n";
    $d = $fifo->distribuir($ap->id_orden_pago);
    echo "  " . json_encode($d['facturas'][0]) . "\n";
    $r['fifo ve cubierta'] = ($d['facturas'][0]['estado'] === Fifo::CUBIERTA);

    echo "\n--- 11: a la factura ya aplicada en parte no se le puede aplicar MAS que su remanente ---\n";
    // Hasta el 2026-10-01 este caso afirmaba que NO se podia aplicar a una factura que ya estuviera
    // en otra OP. Era demasiado: la propia aplicacion es una OP viva, y una factura aplicada a
    // medias quedaba bloqueada para siempre (factura 123 de ZENTRUM). La regla ahora es el saldo
    // que le queda; pasarse sigue cortando, que es lo que protege de pagarla dos veces.
    try {
        $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libres[0]->id_factura, 'monto' => $saldoF0]]);
        echo "  NO corto\n"; $r['no pasa del remanente'] = false;
    } catch (\Throwable $e) {
        echo "  corto: " . substr($e->getMessage(), 0, 90) . "\n";
        $r['no pasa del remanente'] = str_contains($e->getMessage(), 'le quedan')
            || str_contains($e->getMessage(), 'saldo suficiente');
    }

    echo "\n--- 12: aplicar el resto -> el anticipo queda CONSUMIDA ---\n";
    $ap2 = $ant->aplicarAFacturas($a->id_orden_pago, [['id_factura' => $libres[1]->id_factura, 'monto' => $R]]);
    $ant->actualizarEstadoAnticipo($a->id_orden_pago);
    $saldo3 = $ant->saldoDisponible($a->id_orden_pago);
    echo "  saldo: {$saldo3} | estado anticipo: " . $a->refresh()->id_estado_orden_pago . " (7=CONSUMIDA)\n";
    $r['saldo cero'] = ($saldo3 == 0.0);
    $r['queda consumida'] = ((int) $a->id_estado_orden_pago === 7);
    // ESTE anticipo ya no figura; el prestador puede tener otros reales con saldo (ZENTRUM tiene la
    // OPA-16759 del usuario), asi que contar todos daba falso negativo.
    $r['ya no figura con saldo'] = !collect($ant->anticiposConSaldo($idPrestador, 'PRESTADOR'))
        ->contains('id_orden_pago', $a->id_orden_pago);

    echo "\n--- 13: anular una aplicacion devuelve el saldo ---\n";
    $ap2->id_estado_orden_pago = 3; $ap2->save();
    $ant->actualizarEstadoAnticipo($a->id_orden_pago);
    $saldo4 = $ant->saldoDisponible($a->id_orden_pago);
    echo "  saldo recuperado: " . number_format($saldo4, 2, ',', '.') . " | estado anticipo: " . $a->refresh()->id_estado_orden_pago . " (5=PAGADO)\n";
    $r['saldo vuelve'] = (abs($saldo4 - $R) < 0.01);
    $r['deja de estar consumida'] = ((int) $a->id_estado_orden_pago === 5);

    echo "\n--- 14: no se rompio la OP normal (montoCubierto == montoPagado) ---\n";
    $normal = TesOrdenPagoEntity::where('tipo_opa', 'NORMAL')->whereHas('pagos')->first();
    if ($normal) {
        $eq = ($opaRepo->montoCubiertoOpa($normal->id_orden_pago) === $opaRepo->montoPagadoOpa($normal->id_orden_pago));
        echo "  OPA {$normal->num_orden_pago}: cubierto == pagado -> " . var_export($eq, true) . "\n";
        $r['normal sin cambios'] = $eq;
    }

    // El anticipo nace con su cronograma, como las ordenes de Generar OPA.
    //
    // Sin esto se creaba una OPA suelta, sin boleta, que NO aparecia en Pagos: el operador la
    // creaba y no la encontraba en ningun lado, y tenia que pasar por "Confirmar OPA" en el
    // Gestor. Reportado sobre la OPA-16460. (2026-09-25)
    echo "\n--- el anticipo nace con boleta y cronograma ---\n";

    $benef = DB::table('tb_prestador')->whereNotNull('cuit')
        ->where('razon_social', '!=', 'Sin identificar')->first();

    // Se mandan desordenadas a proposito: el orden de cuota lo pone el calendario, no el tipeo.
    $conFechas = $ant->crearAnticipo($benef->cod_prestador, 'PRESTADOR', 500000, 'test cronograma', [
        ['fecha_probable_pago' => '2026-11-20'],
        ['fecha_probable_pago' => '2026-10-20'],
    ], $idRazonTest);
    $conFechas->refresh();

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $conFechas->id_orden_pago)->first();
    $fechas = $boleta
        ? DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $boleta->id_pago)
            ->orderBy('orden_cuotas')->pluck('fecha_probable_pago')->all()
        : [];

    echo "  {$conFechas->num_orden_pago} estado={$conFechas->id_estado_orden_pago} boleta="
        . ($boleta->id_pago ?? 'NINGUNA') . " fechas=" . implode(', ', $fechas) . "\n";

    $r['anticipo nace con boleta']   = (bool) $boleta;
    $r['anticipo queda EN PROCESO']  = ((int) $conFechas->id_estado_orden_pago === 4);
    $r['cuotas ordenadas por fecha'] = ($fechas === ['2026-10-20', '2026-11-20']);

    // La razon social del anticipo: es lo que impide pagarlo desde la cuenta de otra entidad.
    //
    // Un anticipo no tiene facturas de donde derivarla, y validarCuentaDeRazonSocial() se apagaba
    // sola cuando faltaba: se podia pagar desde cualquiera de las 3 razones del grupo. Verificado
    // el 2026-09-25. Ver la regla en CLAUDE.md. (2026_09_25_100000)
    echo "--- la razon social es obligatoria y acota la cuenta de origen ---
";

    $corto = false;
    try { $ant->crearAnticipo($benef->cod_prestador, 'PRESTADOR', 1000, 'sin razon', [], null); }
    catch (\Throwable $e) { $corto = true; echo "  sin razon -> corta: {$e->getMessage()}
"; }
    $r['razon social obligatoria'] = $corto;

    $conRazon = $ant->crearAnticipo($benef->cod_prestador, 'PRESTADOR', 100000, 'con razon', [], $idRazonTest);
    $leida = $opaRepo->razonSocialDeOpa($conRazon->id_orden_pago);
    echo "  razonSocialDeOpa = " . var_export($leida, true) . " (esperado {$idRazonTest})
";
    $r['la OPA guarda su razon'] = ($leida === (int) $idRazonTest);

    $inst = new \App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository();
    $permite = [];
    foreach (DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_razon')->get()->groupBy('id_razon') as $idr => $g) {
        try {
            $inst->validarCuentaDeRazonSocial($g->first()->id_cuenta_bancaria, $conRazon->id_orden_pago, $opaRepo);
            $permite[] = (int) $idr;
        } catch (\Throwable $e) { /* corta, que es lo que se espera de las ajenas */ }
    }
    echo "  razones cuya cuenta puede pagarlo: " . (implode(', ', $permite) ?: 'ninguna')
        . " (esperado solo la {$idRazonTest})
";
    $r['solo paga su propia razon'] = ($permite === [(int) $idRazonTest]);


    echo "--- sin fechas sigue andando: queda sin boleta, como antes ---\n";
    // El parametro es opcional: un llamador que no manda cuotas no se rompe.
    $sinFechas = $ant->crearAnticipo($benef->cod_prestador, 'PRESTADOR', 100, 'sin fechas', [], $idRazonTest);
    $cuantas = DB::table('tb_tes_pago')->where('id_orden_pago', $sinFechas->id_orden_pago)->count();
    echo "  boletas: {$cuantas} (esperado 0)\n";
    $r['sin fechas no crea boleta'] = ($cuantas === 0);


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
