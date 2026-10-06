<?php
// Circuito de punta a punta como lo hace el usuario: confirmar la OPA eligiendo eCheq con dos
// cuotas -> los eCheq aparecen en Carga de eCheq -> cargar numeros -> confirmar -> acreditar.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Tesoreria\Services\TesPagosController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$r = [];
$opaRepo = new TestOrdenPagoRepository();
$inst = new Inst();

// Una OPA pendiente, sin pagos, con facturas
$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereHas('opadetalle')->whereDoesntHave('pagos')
    ->orderByDesc('id_orden_pago')->first();

if (!$opa) { echo "SIN OPA LIBRE\n"; return; }

$cuenta = DB::table('tb_tes_cuentas_bancarias')->where('activo', 1)->whereNotNull('id_entidad_bancaria')->first();

DB::beginTransaction();
try {
    echo "OPA {$opa->num_orden_pago} monto " . number_format($opa->monto_orden_pago, 2, ',', '.') . "\n\n";

    // ── 1) Confirmar OPA eligiendo eCheq y DOS cuotas ────────────────────
    echo "--- 1: confirmar la OPA con forma de pago eCheq y 2 cuotas ---\n";
    $mitad = round(((float) $opa->monto_orden_pago) / 2, 2);
    $resto = round(((float) $opa->monto_orden_pago) - $mitad, 2);

    $payload = [[
        'id_pago' => '', 'id_orden_pago' => $opa->id_orden_pago,
        'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
        'fecha_registra' => date('Y-m-d'), 'anticipo' => '0', 'comprobante' => '',
        'monto_pago' => $opa->monto_orden_pago,
        'id_forma_pago' => (string) Inst::FORMA_PAGO_ECHEQ,   // eCheq
        'observaciones' => '', 'id_estado_orden_pago' => '1',
        'monto_opa' => $opa->monto_orden_pago, 'recursor' => '0', 'num_cheque' => '',
        'fecha_confirma_pago' => null, 'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
        'pago_emergencia' => false,
        'cuotas' => [
            ['orden_cuotas' => 1, 'fecha_probable_pago' => '2026-10-10', 'monto' => $mitad],
            ['orden_cuotas' => 2, 'fecha_probable_pago' => '2026-11-10', 'monto' => $resto],
        ],
    ]];

    $ctrl = app(TesPagosController::class);
    $resp = $ctrl->getCrearPago(
        Request::create('/t', 'POST', $payload),
        app(App\Http\Controllers\Tesoreria\Repository\TesPagosRepository::class),
        $opaRepo,
        app(App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository::class),
        app(App\Http\Controllers\Utils\GeneradorCodigosUtils::class)
    );
    echo '  respuesta: ' . $resp->getStatusCode() . ' ' . (json_decode($resp->getContent(), true)['message'] ?? '') . "\n";
    $r['confirma la opa'] = ($resp->getStatusCode() === 200);

    $boleta = DB::table('tb_tes_pago')->where('id_orden_pago', $opa->id_orden_pago)->first();
    $fechas = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $boleta->id_pago)->count();
    $abonos = TesPagosParciales::where('id_pago', $boleta->id_pago)->get();
    echo "  boleta {$boleta->id_pago}: {$fechas} fechas probables, " . $abonos->count() . " abonos\n";
    $r['una sola boleta'] = (DB::table('tb_tes_pago')->where('id_orden_pago', $opa->id_orden_pago)->count() === 1);
    $r['dos fechas'] = ($fechas === 2);
    $r['dos echeq creados'] = ($abonos->count() === 2);
    $r['nacen en borrador'] = $abonos->every(fn($a) => (int) $a->id_estado_instrumento === Inst::BORRADOR);
    $r['nacen sin numero'] = $abonos->every(fn($a) => is_null($a->numero_echeq));
    $r['banco resuelto'] = $abonos->every(fn($a) => !is_null($a->id_banco_emisor));
    printf("  montos: %s (suman %s de %s)\n",
        $abonos->pluck('monto_pago')->implode(' + '),
        number_format($abonos->sum('monto_pago'), 2, ',', '.'),
        number_format($opa->monto_orden_pago, 2, ',', '.'));
    $r['montos cierran'] = (abs($abonos->sum('monto_pago') - (float) $opa->monto_orden_pago) < 0.01);

    // ── 2) Aparecen en Carga de eCheq ────────────────────────────────────
    echo "\n--- 2: aparecen en Carga de eCheq ---\n";
    $inst->marcarPendienteEmision($opa->id_orden_pago);
    $lista = $inst->listarPendientesDeNumero();
    $deEsta = $lista->where('id_orden_pago', $opa->id_orden_pago);
    echo "  pendientes de esta OPA: " . $deEsta->count() . "\n";
    foreach ($deEsta as $p) {
        echo "    {$p->num_orden_pago} | " . ($p->bancoEmisor->descripcion_banco ?? '?')
            . ' | ' . Inst::nombreBeneficiario($p->pago->opa ?? null)
            . ' | $' . $p->monto_pago . ' | ' . $p->fecha_emision_echeq . "\n";
    }
    $r['aparecen en carga'] = ($deEsta->count() === 2);
    $r['con beneficiario'] = $deEsta->every(fn($p) => Inst::nombreBeneficiario($p->pago->opa ?? null) !== 'SIN BENEFICIARIO');

    // ── 3) Cargar numeros y confirmar ────────────────────────────────────
    echo "\n--- 3: cargar numeros y confirmar la emision ---\n";
    $ids = $deEsta->pluck('id_pago_parcial')->values();
    $inst->guardarBorradorNumero($ids[0], 'E2E-001');

    try { $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo); $r['exige todos los numeros'] = false; }
    catch (\Throwable $e) { echo '  falta uno -> ' . $e->getMessage() . "\n"; $r['exige todos los numeros'] = true; }

    $inst->guardarBorradorNumero($ids[1], 'E2E-002');
    $n = $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $opaRepo);
    echo "  confirmados: {$n}\n";
    $r['confirma en bloque'] = ($n === 2);
    $r['sale del listado'] = ($inst->listarPendientesDeNumero()->where('id_orden_pago', $opa->id_orden_pago)->count() === 0);

    // ── 4) Acreditar de a uno mueve el estado de la OPA ──────────────────
    echo "\n--- 4: acreditar de a uno ---\n";
    $inst->marcarAcreditado($ids[0], '2026-10-10', $opaRepo);
    $e1 = (int) $opa->refresh()->id_estado_orden_pago;
    echo "  tras el primero: estado OPA = {$e1} (6 = PAGO PARCIAL)\n";
    $r['parcial tras el primero'] = ($e1 === 6);

    $inst->marcarAcreditado($ids[1], '2026-11-10', $opaRepo);
    $e2 = (int) $opa->refresh()->id_estado_orden_pago;
    echo "  tras el segundo:  estado OPA = {$e2} (5 = PAGADO)\n";
    $r['pagado tras el segundo'] = ($e2 === 5);

    // ── 5) Rechazar uno lo saca del computo ──────────────────────────────
    echo "\n--- 5: rechazar uno (carga manual) ---\n";
    $inst->marcarRechazado($ids[1], 'Sin fondos', $opaRepo);
    $e3 = (int) $opa->refresh()->id_estado_orden_pago;
    echo "  estado OPA = {$e3} (vuelve a 6)\n";
    $r['rechazo revierte'] = ($e3 === 6);

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-26s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
