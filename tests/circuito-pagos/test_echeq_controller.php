<?php
// Controller de instrumentos de pago: mapeo de codigos HTTP.
//   422 = falta un dato del request
//   409 = choca contra una regla de negocio, con mensaje para el usuario
//   201/200 = exito

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Services\TesInstrumentoPagoController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$FORMA_TRANSFERENCIA = 1;

// Se pide un monto minimo: desde el 2026-09-05 no se puede emitir por encima del monto pagable
// de la orden, y este test emite importes fijos que sobre una OPA chica no entrarian.
$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->where('monto_orden_pago', '>=', 2000)
    ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();

if (!$opa) { echo "SIN OPA\n"; return; }

$ctrl = app(TesInstrumentoPagoController::class);

// OJO: `new Request($query, $body)` arma un GET, y en un GET Laravel lee input() del query bag
// -> el body se ignora entero. Hay que crear el request con su verbo.
function req(array $body = [], array $query = []) {
    if (!empty($query)) { return Request::create('/test', 'GET', $query); }
    return Request::create('/test', 'POST', $body);
}

function chk($label, $resp, $esperado) {
    $code = $resp->getStatusCode();
    $body = json_decode($resp->getContent(), true);
    $msg  = $body['message'] ?? '';
    $ok   = $code === $esperado;
    printf("  %-46s %s (esperado %d)  %s\n", $label, $code, $esperado, $ok ? 'OK' : '<<< FALLA');
    if ($msg) { echo '      msg: ' . substr((string) $msg, 0, 74) . "\n"; }
    return $ok;
}

DB::beginTransaction();
$r = [];
try {
    echo "OPA {$opa->num_orden_pago}\n\n";

    // La boleta y el cronograma los deja "Confirmar OPA"; aca se arman minimos.
    $boleta = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->where('id_estado_orden_pago', '!=', 3)->first();

    if (!$boleta) {
        $boleta = TesPagoEntity::create([
            'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
            'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
            'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
            'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
            'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
        ]);
    }

    $fechas = [];
    foreach ([['2026-10-01', 91], ['2026-11-01', 92]] as [$fecha, $orden]) {
        $fechas[] = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
            'fecha_registra' => now(), 'fecha_probable_pago' => $fecha,
            'orden_cuotas' => $orden, 'id_pago' => $boleta->id_pago,
        ]);
    }

    echo "--- validaciones de request (422) ---\n";
    $r[] = chk('emitir sin id_fecha_probable', $ctrl->getEmitirPago(req(['monto' => 100, 'id_forma_pago' => 7])), 422);
    $r[] = chk('emitir sin monto', $ctrl->getEmitirPago(req(['id_fecha_probable' => $fechas[0], 'id_forma_pago' => 7])), 422);
    $r[] = chk('emitir sin forma de pago', $ctrl->getEmitirPago(req(['id_fecha_probable' => $fechas[0], 'monto' => 100])), 422);
    $r[] = chk('validar-numero vacio', $ctrl->getValidarNumero(req([], ['numero' => '  '])), 422);
    $r[] = chk('rechazar sin motivo', $ctrl->getRechazar(req(['motivo_rechazo' => '']), 1), 422);
    $r[] = chk('acreditar sin fecha', $ctrl->getAcreditar(req([]), 1), 422);
    $r[] = chk('cambiar forma sin id_forma_pago', $ctrl->getCambiarFormaPago(req([]), 1), 422);

    echo "\n--- reglas de negocio (409) ---\n";
    // Monto 0 pasa la validacion de presencia del controller (el campo vino) y lo frena
    // el repositorio, que es quien conoce la regla -> 409 con mensaje para el usuario.
    $r[] = chk('emitir con monto cero', $ctrl->getEmitirPago(req([
        'id_fecha_probable' => $fechas[0], 'monto' => 0, 'id_forma_pago' => 7,
    ])), 409);
    $r[] = chk('emitir sobre una fecha inexistente', $ctrl->getEmitirPago(req([
        'id_fecha_probable' => 99999999, 'monto' => 100, 'id_forma_pago' => 7,
    ])), 409);

    echo "\n--- camino feliz: un eCheq y una transferencia ---\n";
    $resp = $ctrl->getEmitirPago(req([
        'id_fecha_probable' => $fechas[0], 'monto' => 800, 'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
    ]));
    $r[] = chk('emitir el primero como eCheq', $resp, 201);
    $idEcheq = json_decode($resp->getContent(), true)['data']['id_pago_parcial'] ?? null;

    $r[] = chk('emitir el segundo como transferencia', $ctrl->getEmitirPago(req([
        'id_fecha_probable' => $fechas[1], 'monto' => 200, 'id_forma_pago' => $FORMA_TRANSFERENCIA,
    ])), 201);

    $r[] = chk('no se emite dos veces la misma fecha', $ctrl->getEmitirPago(req([
        'id_fecha_probable' => $fechas[0], 'monto' => 50, 'id_forma_pago' => 7,
    ])), 409);

    echo "\n--- listado y numeros ---\n";
    $lista = json_decode($ctrl->getPendientesDeNumero(req([], []))->getContent(), true);
    $sinNumero = collect($lista['sin_numero'] ?? [])->where('id_orden_pago', $opa->id_orden_pago)->count();
    printf("  %-46s %d (esperado 1: solo el eCheq)  %s\n", 'eCheq esperando numero', $sinNumero, $sinNumero === 1 ? 'OK' : '<<< FALLA');
    $r[] = ($sinNumero === 1);

    $r[] = chk('validar un numero libre', $ctrl->getValidarNumero(req([], ['numero' => 'CTRL-AAA'])), 200);
    $r[] = chk('guardar el numero', $ctrl->getGuardarNumero(req(['numero' => 'CTRL-AAA']), $idEcheq), 200);

    $disp = json_decode($ctrl->getValidarNumero(req([], ['numero' => 'CTRL-AAA']))->getContent(), true)['disponible'];
    printf("  %-46s %s (esperado false)  %s\n", 'ese numero ya no esta disponible', var_export($disp, true), $disp === false ? 'OK' : '<<< FALLA');
    $r[] = ($disp === false);

    echo "\n--- confirmar, acreditar, rechazar ---\n";
    $r[] = chk('confirmar la emision', $ctrl->getConfirmarEmision(req(['id_orden_pago' => $opa->id_orden_pago])), 200);
    // Desde el 2026-09-15 un EMITIDO SI se puede corregir: al mudar la emision a Confirmar Pago,
    // el corte viejo ("antes de emitir") dejaba cero margen para arreglar un tipeo. El limite pasa
    // a ser lo que el banco ya resolvio (ACREDITADO / RECHAZADO / ANULADO).
    $r[] = chk('emitido: todavia se corrige el numero', $ctrl->getGuardarNumero(req(['numero' => 'CTRL-BBB']), $idEcheq), 200);
    $r[] = chk('emitido: todavia se cambia la forma', $ctrl->getCambiarFormaPago(req(['id_forma_pago' => 1]), $idEcheq), 200);
    // Se vuelve a eCheq para que el resto del test siga sobre un instrumento de ese tipo.
    $r[] = chk('vuelve a eCheq', $ctrl->getCambiarFormaPago(req(['id_forma_pago' => 7]), $idEcheq), 200);

    // Acreditar exige que el PAGO este confirmado (2026-09-10): el asiento contable y el descuento
    // del saldo salen de ahi. Primero se comprueba que sin eso rebote, y despues se confirma la
    // boleta para poder seguir con el resto del ciclo.
    $r[] = chk('acreditar sin pago confirmado', $ctrl->getAcreditar(req(['fecha_acreditacion' => '2026-10-01']), $idEcheq), 409);
    DB::table('tb_tes_pago')->where('id_orden_pago', $opa->id_orden_pago)
        ->whereNull('fecha_confirma_pago')
        ->update(['fecha_confirma_pago' => now()->toDateString()]);

    $r[] = chk('acreditar', $ctrl->getAcreditar(req(['fecha_acreditacion' => '2026-10-01']), $idEcheq), 200);
    $r[] = chk('acreditar de nuevo', $ctrl->getAcreditar(req(['fecha_acreditacion' => '2026-10-01']), $idEcheq), 409);
    $r[] = chk('rechazar (carga manual)', $ctrl->getRechazar(req(['motivo_rechazo' => 'Sin fondos']), $idEcheq), 200);
    $r[] = chk('rechazar de nuevo', $ctrl->getRechazar(req(['motivo_rechazo' => 'x']), $idEcheq), 409);

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
