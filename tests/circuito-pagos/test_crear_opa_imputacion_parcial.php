<?php
// Pantalla nueva Tesoreria > Crear OPA: listado de facturas en Valorizacion Final e imputacion
// de un monto por factura. Reemplaza al checkbox + "Generar OPA" del visor de liquidaciones.
//
// Lo que se prueba, en orden de riesgo:
//
//   - El SALDO se mide neto de debito y descontando lo imputado a OPAs VIVAS. Una OPA RECHAZADA
//     no puede seguir reservando saldo: si lo hiciera, la factura quedaria imposible de volver a
//     pagar. Ojo con el numero: acá RECHAZADO es el 3. La pantalla de referencia (ospf) escribe
//     `whereNotIn(..., [5,6])` y acá 5=PAGADO / 6=PAGO PARCIAL; copiarlo invierte el calculo.
//   - El invariante `detalle.monto_factura == puente.monto_aplicado == lo imputado`. Es fragil a
//     proposito de verificar: `recalcularMontoDesdeDetalle()` pisa `monto_aplicado` con
//     `monto_factura`, asi que si se escribieran distinto, la primera operacion que toque el
//     detalle borraria la imputacion parcial y la dejaria en el bruto de la factura.
//   - `findByOpaVigenteFactura()` tiene que encontrar la OPA nueva: es la guarda que impide
//     reabrir una liquidacion cuya factura ya tiene orden. Un falso negativo genera una segunda
//     OPA para la misma factura (ya paso, 6 casos en produccion).
//   - `num_orden_pago` lo pone un trigger: sin `refresh()` vuelve null al front.

use App\Http\Controllers\Tesoreria\Repository\FacturasOpaRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$plata = fn($n) => number_format((float) $n, 2, ',', '.');

DB::beginTransaction();
$r = [];
try {
    $opaRepo = new TestOrdenPagoRepository();
    $saldos = new FacturasOpaRepository();
    $VF = FacturasOpaRepository::ESTADO_FACTURA_VALORIZACION_FINAL;

    // Dos facturas del MISMO prestador, con debito y sin OPA viva. Si en la base no hay un par
    // asi, se fabrica: se toman dos facturas de un prestador cualquiera y se las pone en
    // Valorizacion Final dentro de la transaccion (todo vuelve atras al final).
    $idPrestador = DB::table('tb_facturacion_datos')
        ->whereNotNull('id_prestador')->whereNull('id_proveedor')
        ->where('total_neto', '>', 10000)
        ->groupBy('id_prestador')
        ->havingRaw('COUNT(*) >= 2')
        ->value('id_prestador');

    if (!$idPrestador) { echo "SIN PRESTADOR CON 2 FACTURAS\n"; DB::rollBack(); return; }

    $facturas = FacturacionDatosEntity::where('id_prestador', $idPrestador)
        ->whereNull('id_proveedor')->where('total_neto', '>', 10000)
        ->orderByDesc('id_factura')->limit(2)->get();

    foreach ($facturas as $f) {
        // Se las deja en Valorizacion Final y con un debito conocido, para que el saldo sea
        // predecible. Y se libera cualquier imputacion previa: interesa el calculo, no el
        // historial de estas dos facturas en particular.
        //
        // Las tres cosas hacen falta. La primera version de este fixture borraba solo puente y
        // detalle, y el caso 10 fallaba: `findByOpaVigenteFactura()` busca por los DOS lados
        // —detalle y `tb_tes_orden_pago.id_factura`— porque en las OPAs agrupadas la cabecera
        // queda en NULL. La factura de prueba tenia una OPA vieja apuntandola desde la cabecera,
        // asi que la guarda devolvia esa (la de id mas bajo) y no la nueva. El codigo estaba
        // bien; faltaba dejar la factura realmente sin OPA viva, que es la precondicion de la
        // pantalla: el listado solo ofrece facturas con saldo.
        TesFacturasOpaEntity::where('id_factura', $f->id_factura)->delete();
        TesOrdenPagoDetalleEntity::where('id_factura', $f->id_factura)->delete();
        TesOrdenPagoEntity::where('id_factura', $f->id_factura)
            ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO]);
        $f->estado = $VF;
        $f->total_debitado_liquidacion = round((float) $f->total_neto * 0.10, 2);
        $f->save();
    }

    // Precondicion del escenario: ninguna de las dos puede tener OPA viva antes de empezar.
    foreach ($facturas as $f) {
        if ($opaRepo->findByOpaVigenteFactura($f->id_factura)) {
            echo "FIXTURE MAL: la factura {$f->id_factura} sigue con OPA viva\n";
            DB::rollBack();
            return;
        }
    }

    $fA = $facturas[0]->fresh();
    $fB = $facturas[1]->fresh();
    $pagableA = round((float) $fA->total_neto - (float) $fA->total_debitado_liquidacion, 2);
    $pagableB = round((float) $fB->total_neto - (float) $fB->total_debitado_liquidacion, 2);

    echo "prestador {$idPrestador}\n";
    echo "  factura A {$fA->id_factura}: neto {$plata($fA->total_neto)} - debito {$plata($fA->total_debitado_liquidacion)} = pagable {$plata($pagableA)}\n";
    echo "  factura B {$fB->id_factura}: neto {$plata($fB->total_neto)} - debito {$plata($fB->total_debitado_liquidacion)} = pagable {$plata($pagableB)}\n\n";

    echo "--- 1: el saldo arranca en el PAGABLE (neto de debito), no en el bruto ---\n";
    $s = $saldos->saldoImputableFactura($fA);
    echo "  saldo={$plata($s)} (esperado {$plata($pagableA)}, bruto seria {$plata($fA->total_neto)})\n";
    $r[] = (abs($s - $pagableA) < 0.01);
    echo $ok(end($r));

    echo "--- 2: aparece en el listado de Crear OPA ---\n";
    $listado = $saldos->listarFacturasParaOpa((object) ['id_prestador' => $idPrestador, 'per_page' => 50]);
    $fila = collect($listado['data'])->firstWhere('id_factura', $fA->id_factura);
    echo "  total={$listado['total']} | fila " . ($fila ? "presente, saldo={$plata($fila->saldo_pendiente)}" : 'AUSENTE') . "\n";
    // El saldo de SQL y el de PHP tienen que coincidir: si divergen, la grilla muestra un tope y
    // el backend valida contra otro.
    $r[] = ($fila && abs((float) $fila->saldo_pendiente - $pagableA) < 0.01);
    echo $ok(end($r));

    echo "--- 3: imputacion PARCIAL de A (la mitad) + entera de B ---\n";
    $mitadA = round($pagableA / 2, 2);
    $opa = $opaRepo->procesarOpaAgrupada((object) [
        'facturas' => [
            ['id_factura' => $fA->id_factura, 'monto_aplicado' => $mitadA],
            ['id_factura' => $fB->id_factura, 'monto_aplicado' => $pagableB],
        ],
        'observaciones' => 'test imputacion parcial',
    ]);
    $esperado = round($mitadA + $pagableB, 2);
    echo "  OPA {$opa->num_orden_pago} (id {$opa->id_orden_pago}) monto={$plata($opa->monto_orden_pago)} (esperado {$plata($esperado)})\n";
    $r[] = (abs((float) $opa->monto_orden_pago - $esperado) < 0.01);
    echo $ok(end($r));

    echo "--- 4: num_orden_pago no vuelve null (lo pone un trigger, hace falta refresh) ---\n";
    echo "  num_orden_pago=" . var_export($opa->num_orden_pago, true) . "\n";
    $r[] = !empty($opa->num_orden_pago);
    echo $ok(end($r));

    echo "--- 5: invariante detalle.monto_factura == puente.monto_aplicado == lo imputado ---\n";
    $det = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->pluck('monto_factura', 'id_factura');
    $pte = TesFacturasOpaEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->pluck('monto_aplicado', 'id_factura');
    echo "  A: detalle={$plata($det[$fA->id_factura])} puente={$plata($pte[$fA->id_factura])} (esperado {$plata($mitadA)})\n";
    echo "  B: detalle={$plata($det[$fB->id_factura])} puente={$plata($pte[$fB->id_factura])} (esperado {$plata($pagableB)})\n";
    $r[] = abs((float) $det[$fA->id_factura] - $mitadA) < 0.01
        && abs((float) $pte[$fA->id_factura] - $mitadA) < 0.01
        && abs((float) $det[$fB->id_factura] - $pagableB) < 0.01
        && abs((float) $pte[$fB->id_factura] - $pagableB) < 0.01;
    echo $ok(end($r));

    echo "--- 6: el invariante SOBREVIVE a recalcularMontoDesdeDetalle ---\n";
    // Es el caso que romperia todo: ese metodo llama a sincronizarPuenteDesdeDetalle(), que pisa
    // monto_aplicado con monto_factura. Cualquier flujo viejo que toque el detalle pasa por aca.
    $opaRepo->recalcularEstadoOpa($opa->id_orden_pago);
    $opaRepo->sincronizarPuenteDesdeDetalle($opa->id_orden_pago);
    $pteDespues = (float) TesFacturasOpaEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->where('id_factura', $fA->id_factura)->value('monto_aplicado');
    echo "  puente A despues de sincronizar={$plata($pteDespues)} (esperado {$plata($mitadA)}, el bug daria {$plata($fA->total_neto)})\n";
    $r[] = (abs($pteDespues - $mitadA) < 0.01);
    echo $ok(end($r));

    echo "--- 7: a A le queda la otra mitad disponible ---\n";
    $sA = $saldos->saldoImputableFactura($fA->fresh());
    echo "  saldo A={$plata($sA)} (esperado {$plata(round($pagableA - $mitadA, 2))})\n";
    $r[] = (abs($sA - round($pagableA - $mitadA, 2)) < 0.01);
    echo $ok(end($r));

    echo "--- 8: B quedo en cero y el listado ya no la ofrece ---\n";
    $sB = $saldos->saldoImputableFactura($fB->fresh());
    $listado2 = $saldos->listarFacturasParaOpa((object) ['id_prestador' => $idPrestador, 'per_page' => 50]);
    $sigueB = collect($listado2['data'])->firstWhere('id_factura', $fB->id_factura);
    echo "  saldo B={$plata($sB)} | en el listado: " . ($sigueB ? 'SI (mal)' : 'no') . "\n";
    $r[] = ($sB < 0.01 && is_null($sigueB));
    echo $ok(end($r));

    echo "--- 9: sobre-imputar lo que queda de A -> rechaza ---\n";
    try {
        $opaRepo->procesarOpaAgrupada((object) [
            'facturas' => [['id_factura' => $fA->id_factura, 'monto_aplicado' => $pagableA]],
        ]);
        echo "  NO fallo: dejo imputar mas que el saldo\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'supera su saldo disponible');
    }
    echo $ok(end($r));

    echo "--- 10: la guarda de reabrir liquidacion encuentra la OPA nueva ---\n";
    $vigente = $opaRepo->findByOpaVigenteFactura($fA->id_factura);
    echo "  findByOpaVigenteFactura(A) -> " . ($vigente ? "OPA {$vigente->id_orden_pago}" : 'NULL (falso negativo!)') . "\n";
    $r[] = ($vigente && (int) $vigente->id_orden_pago === (int) $opa->id_orden_pago);
    echo $ok(end($r));

    echo "--- 11: si la OPA se RECHAZA, el saldo vuelve entero ---\n";
    // Acá se rompe si quedó algún literal [5,6] copiado de ospf: rechazado es el 3.
    TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO]);
    $sAr = $saldos->saldoImputableFactura($fA->fresh());
    $sBr = $saldos->saldoImputableFactura($fB->fresh());
    echo "  saldo A={$plata($sAr)} (esperado {$plata($pagableA)}) | saldo B={$plata($sBr)} (esperado {$plata($pagableB)})\n";
    $r[] = (abs($sAr - $pagableA) < 0.01 && abs($sBr - $pagableB) < 0.01);
    echo $ok(end($r));

    echo "--- 12: una OPA PAGADA si sigue reservando saldo ---\n";
    TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_PAGADO]);
    $sAp = $saldos->saldoImputableFactura($fA->fresh());
    echo "  saldo A={$plata($sAp)} (esperado {$plata(round($pagableA - $mitadA, 2))})\n";
    $r[] = (abs($sAp - round($pagableA - $mitadA, 2)) < 0.01);
    echo $ok(end($r));

    echo "--- 13: dos prestadores distintos en la misma orden -> rechaza ---\n";
    $otra = FacturacionDatosEntity::whereNotNull('id_prestador')->whereNull('id_proveedor')
        ->where('id_prestador', '!=', $idPrestador)->where('total_neto', '>', 10000)
        ->orderByDesc('id_factura')->first();
    if ($otra) {
        $otra->estado = $VF; $otra->total_debitado_liquidacion = 0; $otra->save();
        TesFacturasOpaEntity::where('id_factura', $otra->id_factura)->delete();
        TesOrdenPagoDetalleEntity::where('id_factura', $otra->id_factura)->delete();
        try {
            $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
                ['id_factura' => $fA->id_factura, 'monto_aplicado' => 100],
                ['id_factura' => $otra->id_factura, 'monto_aplicado' => 100],
            ]]);
            echo "  NO fallo: mezclo dos prestadores\n"; $r[] = false;
        } catch (\Throwable $e) {
            echo "  rechazado: {$e->getMessage()}\n";
            $r[] = str_contains($e->getMessage(), 'distintos prestadores');
        }
    } else {
        echo "  sin otro prestador para probar, se saltea\n"; $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 14: una factura fuera de Valorizacion Final -> rechaza ---\n";
    $fA->estado = 1; $fA->save();   // 1 = CARGADA
    try {
        $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
            ['id_factura' => $fA->id_factura, 'monto_aplicado' => 100],
        ]]);
        echo "  NO fallo: genero OPA de una factura sin valorizar\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'Valorización Final');
    }
    echo $ok(end($r));

    echo "--- 15: monto cero o negativo -> rechaza ---\n";
    $fA->estado = $VF; $fA->save();
    try {
        $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
            ['id_factura' => $fA->id_factura, 'monto_aplicado' => 0],
        ]]);
        echo "  NO fallo: genero una OPA por 0\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  rechazado: {$e->getMessage()}\n";
        $r[] = str_contains($e->getMessage(), 'mayor a 0');
    }
    echo $ok(end($r));

    echo "--- 16: factura cuyo prestador NO esta en tb_prestador -> igual se lista ---\n";
    // El join a tb_prestador arranco siendo INNER y esas facturas desaparecian del listado sin
    // ningun aviso: el usuario veia una factura valorizada que simplemente no estaba, sin manera
    // de saber por que. Ademas era mas estricto que el camino viejo — "Generar OPA" desde
    // liquidaciones ni mira esa tabla, asi que hoy esas facturas SI se pueden pagar.
    // Hay 1 asi en Alba y 3 en OSV (2026-09-12).
    $codLibre = ((int) DB::table('tb_prestador')->max('cod_prestador')) + 9999;
    $fHuerfana = FacturacionDatosEntity::whereNotNull('id_prestador')->whereNull('id_proveedor')
        ->where('total_neto', '>', 10000)
        ->whereNotIn('id_factura', [$fA->id_factura, $fB->id_factura])
        ->orderByDesc('id_factura')->first();

    if ($fHuerfana) {
        TesFacturasOpaEntity::where('id_factura', $fHuerfana->id_factura)->delete();
        TesOrdenPagoDetalleEntity::where('id_factura', $fHuerfana->id_factura)->delete();
        $fHuerfana->id_prestador = $codLibre;   // prestador que no existe en el catalogo
        $fHuerfana->estado = $VF;
        $fHuerfana->total_debitado_liquidacion = 0;
        $fHuerfana->save();

        $listado3 = $saldos->listarFacturasParaOpa((object) ['id_prestador' => $codLibre, 'per_page' => 50]);
        $filaH = collect($listado3['data'])->firstWhere('id_factura', $fHuerfana->id_factura);
        echo "  prestador inexistente {$codLibre} -> " . ($filaH
            ? "listada, razon_social=" . var_export($filaH->razon_social, true) . " saldo={$plata($filaH->saldo_pendiente)}"
            : 'AUSENTE (el INNER JOIN la escondia)') . "\n";
        $r[] = !is_null($filaH);
    } else {
        echo "  sin factura para probar, se saltea\n"; $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 17: dos facturas de DISTINTA razon social -> rechaza ---\n";
    // `id_locatorio` es cual de nuestras empresas debe la factura. Una orden se paga desde UNA
    // cuenta bancaria, y esa cuenta pertenece a una sola razon social: mezclarlas hace que el
    // desplegable de cuentas ofrezca las de las dos empresas y se termine pagando la deuda de una
    // con la plata de la otra.
    //
    // Mismo prestador NO implica misma razon: un prestador le puede facturar a dos empresas.
    // Reportado el 2026-09-16 sobre la OPA-16002, creada con esta misma pantalla. (2026-09-16)
    $razonesExistentes = DB::table('tb_razones_sociales')->pluck('id_razon');
    $otraRazon = $razonesExistentes->first(fn($r) => $r != $fA->id_locatorio);

    if ($otraRazon) {
        $fB->refresh();
        $fB->estado = $VF;
        $fB->total_debitado_liquidacion = 0;
        $fB->id_locatorio = $otraRazon;              // misma OPA, otra empresa
        $fB->save();
        TesFacturasOpaEntity::where('id_factura', $fB->id_factura)->delete();
        TesOrdenPagoDetalleEntity::where('id_factura', $fB->id_factura)->delete();

        $fA->refresh(); $fA->estado = $VF; $fA->save();

        try {
            $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
                ['id_factura' => $fA->id_factura, 'monto_aplicado' => 100],
                ['id_factura' => $fB->id_factura, 'monto_aplicado' => 100],
            ]]);
            echo "  NO fallo: mezclo dos razones sociales\n"; $r[] = false;
        } catch (\Throwable $e) {
            echo "  rechazado: {$e->getMessage()}\n";
            $r[] = str_contains($e->getMessage(), 'distintas razones sociales');
        }
    } else {
        echo "  sin otra razon social para probar, se saltea\n"; $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 18: PROVEEDOR usa el mismo circuito, con otro estado habilitante ---\n";
    // La misma columna `estado` lleva dos catalogos: para prestador el 3 es Valorizacion Final,
    // para proveedor su equivalente es el 1 (CONFIRMADA) — no pasa por liquidacion, nace final.
    // Verificado el 2026-09-16: las 1.256 facturas de proveedor de Alba estan todas en 1, ninguna
    // en 3, asi que filtrar por 3 no habria traido ninguna.
    $fcProv = FacturacionDatosEntity::where('id_tipo_factura', FacturasOpaRepository::TIPO_FACTURA_PROVEEDOR)
        ->whereNotNull('id_proveedor')->where('total_neto', '>', 1000)
        ->orderByDesc('id_factura')->first();

    if ($fcProv) {
        TesFacturasOpaEntity::where('id_factura', $fcProv->id_factura)->delete();
        TesOrdenPagoDetalleEntity::where('id_factura', $fcProv->id_factura)->delete();
        TesOrdenPagoEntity::where('id_factura', $fcProv->id_factura)
            ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO]);
        $fcProv->estado = FacturasOpaRepository::ESTADO_FACTURA_PROVEEDOR_CONFIRMADA;
        $fcProv->total_debitado_liquidacion = 0;
        $fcProv->save();

        $listadoProv = $saldos->listarFacturasParaOpa((object) ['tipo' => 'PROVEEDOR', 'per_page' => 200]);
        $estaListada = collect($listadoProv['data'])->firstWhere('id_factura', $fcProv->id_factura);
        echo "  aparece en el listado de PROVEEDOR: " . ($estaListada ? 'si' : 'NO') . "\n";

        $opaProv = $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
            ['id_factura' => $fcProv->id_factura, 'monto_aplicado' => 500],
        ]]);
        echo "  OPA {$opaProv->num_orden_pago} tipo={$opaProv->tipo_factura}"
            . " id_proveedor=" . var_export($opaProv->id_proveedor, true)
            . " id_prestador=" . var_export($opaProv->id_prestador, true) . "\n";
        $r[] = ($estaListada
            && $opaProv->tipo_factura === 'PROVEEDOR'
            && (int) $opaProv->id_proveedor === (int) $fcProv->id_proveedor
            && is_null($opaProv->id_prestador));
    } else {
        echo "  sin factura de proveedor para probar, se saltea\n"; $r[] = true;
    }
    echo $ok(end($r));

    echo "--- 19: no se pueden mezclar una de proveedor y una de prestador ---\n";
    if ($fcProv) {
        $fA->refresh(); $fA->estado = $VF; $fA->id_locatorio = $fcProv->id_locatorio; $fA->save();
        try {
            $opaRepo->procesarOpaAgrupada((object) ['facturas' => [
                ['id_factura' => $fA->id_factura, 'monto_aplicado' => 100],
                ['id_factura' => $fcProv->id_factura, 'monto_aplicado' => 100],
            ]]);
            echo "  NO fallo: mezclo proveedor con prestador\n"; $r[] = false;
        } catch (\Throwable $e) {
            echo "  rechazado: {$e->getMessage()}\n";
            $r[] = str_contains($e->getMessage(), 'proveedor y de prestador');
        }
    } else { $r[] = true; }
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho, nada quedo guardado)\n";
}
