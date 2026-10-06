<?php
// Punto 7: anular una OP y reemitir otra dejando el vinculo entre las dos. Con rollback.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
if (!$opa) { echo "SIN OPA\n"; return; }

$r = [];
DB::beginTransaction();
try {
    $repo = new TestOrdenPagoRepository();
    $inst = new Inst();

    $facturasOriginales = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)
        ->orderBy('id_factura')->pluck('monto_factura', 'id_factura')->toArray();
    echo "OPA {$opa->id_orden_pago} ({$opa->num_orden_pago}) con "
        . count($facturasOriginales) . " factura(s)\n\n";

    echo "--- 1: sin motivo -> debe cortar ---\n";
    $res = $repo->anularYReemitir($opa->id_orden_pago, '  ');
    echo "  {$res['message']}\n";
    $r['exige motivo'] = ($res['ok'] === false);

    echo "\n--- 2: con eCheq EMITIDO -> debe cortar (papel circulando) ---\n";
    $c = $inst->crearInstrumentos($opa->id_orden_pago, [['monto' => 50, 'fecha' => '2026-09-30', 'id_banco_emisor' => 2]]);
    $inst->marcarPendienteEmision($opa->id_orden_pago);
    $inst->guardarBorradorNumero($c[0]->id_pago, 'REEMP-TEST-1');
    $inst->confirmarEmisionDeOpa($opa->id_orden_pago, $repo);
    $res = $repo->anularYReemitir($opa->id_orden_pago, 'error de carga');
    echo "  {$res['message']}\n";
    $r['bloquea con echeq emitido'] = ($res['ok'] === false && str_contains($res['message'], 'emitido'));

    echo "\n--- 3: rechazado el echeq, ya se puede anular ---\n";
    $inst->marcarRechazado($c[0]->id_pago, 'anulacion operativa', $repo);
    // Un instrumento nuevo, sin emitir: tiene que quedar ANULADO junto con la orden
    $c2 = $inst->crearInstrumentos($opa->id_orden_pago, [['monto' => 70, 'fecha' => '2026-10-01', 'id_banco_emisor' => 2]]);
    $repo->recalcularEstadoOpa($opa->id_orden_pago);
    $opa->refresh();
    echo "  estado OPA antes de anular: {$opa->id_estado_orden_pago}\n";

    $res = $repo->anularYReemitir($opa->id_orden_pago, 'Se cargo mal el importe');
    echo "  {$res['message']}\n";
    $r['anula y reemite'] = ($res['ok'] === true);

    if (!$res['ok']) { throw new \Exception('no se pudo anular, corto el test'); }

    $vieja = $res['anulada'];
    $nueva = $res['nueva'];

    echo "\n--- 4: la vieja quedo anulada con motivo y usuario ---\n";
    echo "  estado={$vieja->id_estado_orden_pago} motivo='{$vieja->motivo_rechazo}' fecha={$vieja->fecha_rechazo}\n";
    $r['vieja anulada'] = ((int) $vieja->id_estado_orden_pago === 3
        && $vieja->motivo_rechazo === 'Se cargo mal el importe'
        && !is_null($vieja->fecha_rechazo));

    echo "\n--- 5: la nueva apunta a la vieja (TRAZABILIDAD) ---\n";
    echo "  nueva {$nueva->id_orden_pago} ({$nueva->num_orden_pago})"
        . " tipo_opa={$nueva->tipo_opa} id_opa_reemplazada={$nueva->id_opa_reemplazada}\n";
    $r['vinculo'] = ((int) $nueva->id_opa_reemplazada === (int) $vieja->id_orden_pago);
    $r['tipo REEMPLAZO'] = ($nueva->tipo_opa === 'REEMPLAZO');
    $r['nueva tiene numero'] = !empty($nueva->num_orden_pago);
    $r['nueva pendiente'] = ((int) $nueva->id_estado_orden_pago === 1);

    echo "\n--- 6: la nueva lleva las MISMAS facturas y montos ---\n";
    $facturasNuevas = TesOrdenPagoDetalleEntity::where('id_orden_pago', $nueva->id_orden_pago)
        ->orderBy('id_factura')->pluck('monto_factura', 'id_factura')->toArray();
    echo "  original: " . json_encode($facturasOriginales) . "\n";
    echo "  nueva   : " . json_encode($facturasNuevas) . "\n";
    $r['mismas facturas'] = (array_keys($facturasOriginales) === array_keys($facturasNuevas));

    echo "\n--- 7: la puente de la nueva quedo alineada ---\n";
    $puente = TesFacturasOpaEntity::where('id_orden_pago', $nueva->id_orden_pago)
        ->orderBy('id_factura')->pluck('monto_aplicado', 'id_factura')->toArray();
    echo "  puente nueva: " . json_encode($puente) . "\n";
    $r['puente alineada'] = (array_keys($puente) === array_keys($facturasNuevas));

    echo "\n--- 8: el instrumento sin emitir quedo ANULADO ---\n";
    $estadoInst = TesPagoEntity::find($c2[0]->id_pago)->id_estado_instrumento;
    echo "  instrumento {$c2[0]->id_pago} -> estado={$estadoInst} (esperado " . Inst::ANULADO . ")\n";
    $r['instrumento anulado'] = ((int) $estadoInst === Inst::ANULADO);

    echo "\n--- 9: la factura quedo LIBRE y su vigente es la nueva ---\n";
    $primeraFactura = array_key_first($facturasOriginales);
    $vigente = $repo->findByOpaVigenteFactura($primeraFactura);
    echo "  factura {$primeraFactura} -> OPA vigente: "
        . ($vigente ? "{$vigente->id_orden_pago} ({$vigente->num_orden_pago})" : 'ninguna') . "\n";
    $r['vigente es la nueva'] = ($vigente && (int) $vigente->id_orden_pago === (int) $nueva->id_orden_pago);

    echo "\n--- 10: no se puede anular dos veces ---\n";
    $res2 = $repo->anularYReemitir($vieja->id_orden_pago, 'otra vez');
    echo "  {$res2['message']}\n";
    $r['no re-anula'] = ($res2['ok'] === false);

    echo "\n--- 11: cadena de reemplazos ---\n";
    $res3 = $repo->anularYReemitir($nueva->id_orden_pago, 'segundo error');
    $tercera = $res3['nueva'];
    foreach ([$vieja, $nueva, $tercera] as $x) {
        $cadena = $repo->cadenaDeReemplazos($x->id_orden_pago);
        $ids = array_map(fn($o) => $o->id_orden_pago, $cadena);
        echo "  desde {$x->id_orden_pago}: " . json_encode($ids) . "\n";
    }
    $cadena = $repo->cadenaDeReemplazos($vieja->id_orden_pago);
    $ids = array_map(fn($o) => (int) $o->id_orden_pago, $cadena);
    $esperado = [(int) $vieja->id_orden_pago, (int) $nueva->id_orden_pago, (int) $tercera->id_orden_pago];
    echo "  esperado: " . json_encode($esperado) . "\n";
    $r['cadena completa'] = ($ids === $esperado);
    $r['cadena igual desde el medio'] = (array_map(fn($o) => (int) $o->id_orden_pago,
        $repo->cadenaDeReemplazos($nueva->id_orden_pago)) === $esperado);

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-30s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
