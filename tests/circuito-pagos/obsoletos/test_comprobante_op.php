<?php
// Comprobante de OP en sus DOS versiones desde la MISMA plantilla:
// inicial (sin numeros, para Tesoreria) y definitivo (con numeros, para el proveedor).
// Tambien verifica que una OPA vieja (sin instrumentos) siga imprimiendo como siempre.

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$out = sys_get_temp_dir();
echo 'base: ' . DB::connection()->getDatabaseName() . "\n\n";

$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
if (!$opa) { echo "SIN OPA\n"; return; }
echo "OPA {$opa->id_orden_pago} ({$opa->num_orden_pago})\n\n";

$r = [];
DB::beginTransaction();
try {
    $repo = new Inst();
    $ctrl = app(TesOrdenPagoController::class);

    // --- 0: una OPA sin instrumentos tiene que imprimir como siempre (no romper lo viejo)
    echo "--- 0: OPA legacy (sin instrumentos) sigue imprimiendo ---\n";
    $resp0 = $ctrl->printOrderPay($opa->id_orden_pago);
    $pdf0 = $resp0->getContent();
    echo "  bytes=" . strlen($pdf0) . " firma=" . substr($pdf0, 0, 4) . "\n";
    $r['legacy imprime'] = (substr($pdf0, 0, 4) === '%PDF' && strlen($pdf0) > 1000);

    // --- 1: version INICIAL, instrumentos sin numero
    echo "\n--- 1: version INICIAL (sin numeros, para Tesoreria) ---\n";
    $creados = $repo->crearInstrumentos($opa->id_orden_pago, [
        ['monto' => 5000.00, 'fecha' => '2026-09-20', 'id_banco_emisor' => 2],
        ['monto' => 2500.50, 'fecha' => '2026-09-25', 'id_banco_emisor' => 2],
    ]);
    $repo->marcarPendienteEmision($opa->id_orden_pago);

    $resp1 = $ctrl->printOrderPay($opa->id_orden_pago);
    $pdf1 = $resp1->getContent();
    file_put_contents($out . '/comprobante-inicial.pdf', $pdf1);
    echo "  bytes=" . strlen($pdf1) . " firma=" . substr($pdf1, 0, 4) . "\n";
    $r['inicial es pdf'] = (substr($pdf1, 0, 4) === '%PDF');
    $r['inicial mas grande que legacy'] = (strlen($pdf1) > strlen($pdf0));

    // El PDF esta comprimido, asi que se verifica el HTML que lo alimenta
    $html1 = view('orden_pago', datosDe($ctrl, $opa->id_orden_pago))->render();
    file_put_contents($out . '/comprobante-inicial.html', $html1);
    $tieneSello1 = str_contains($html1, 'PENDIENTE DE EMISION');
    $tieneNumero1 = str_contains($html1, 'ECHEQ-DEF-');
    echo "  sello 'PENDIENTE DE EMISION': " . var_export($tieneSello1, true) . "\n";
    echo "  muestra numeros de echeq: " . var_export($tieneNumero1, true) . " (esperado false)\n";
    echo "  linea en blanco para completar: " . var_export(str_contains($html1, 'border-bottom: 1px solid #94a3b8'), true) . "\n";
    $r['sello inicial'] = $tieneSello1;
    $r['inicial sin numeros'] = !$tieneNumero1;
    $r['inicial con linea en blanco'] = str_contains($html1, 'border-bottom: 1px solid #94a3b8');

    // --- 2: version DEFINITIVA, con numeros confirmados
    echo "\n--- 2: version DEFINITIVA (con numeros, para el proveedor) ---\n";
    foreach ($creados as $i => $c) {
        $repo->guardarBorradorNumero($c->id_pago_parcial, 'ECHEQ-DEF-' . (100 + $i));
    }
    $repo->confirmarEmisionDeOpa($opa->id_orden_pago, new TestOrdenPagoRepository());

    $html2 = view('orden_pago', datosDe($ctrl, $opa->id_orden_pago))->render();
    file_put_contents($out . '/comprobante-definitivo.html', $html2);
    $resp2 = $ctrl->printOrderPay($opa->id_orden_pago);
    $pdf2 = $resp2->getContent();
    file_put_contents($out . '/comprobante-definitivo.pdf', $pdf2);

    $tieneSello2 = str_contains($html2, 'COMPROBANTE DEFINITIVO');
    $tieneNumero2 = str_contains($html2, 'ECHEQ-DEF-100') && str_contains($html2, 'ECHEQ-DEF-101');
    echo "  bytes pdf=" . strlen($pdf2) . " firma=" . substr($pdf2, 0, 4) . "\n";
    echo "  sello 'COMPROBANTE DEFINITIVO': " . var_export($tieneSello2, true) . "\n";
    echo "  muestra los 2 numeros: " . var_export($tieneNumero2, true) . "\n";
    echo "  muestra el banco emisor: " . var_export(str_contains($html2, 'BBVA'), true) . "\n";
    $r['sello definitivo'] = $tieneSello2;
    $r['definitivo con numeros'] = $tieneNumero2;
    $r['definitivo con banco'] = str_contains($html2, 'BBVA');
    $r['definitivo es pdf'] = (substr($pdf2, 0, 4) === '%PDF');

    // --- 3: misma plantilla, no dos archivos distintos
    echo "\n--- 3: es la MISMA plantilla en las dos versiones ---\n";
    $mismoEncabezado = str_contains($html1, 'Valores Entregados') && str_contains($html2, 'Valores Entregados');
    echo "  ambas usan 'Valores Entregados': " . var_export($mismoEncabezado, true) . "\n";
    $r['misma plantilla'] = $mismoEncabezado;

    // --- 4: los montos de los instrumentos aparecen
    echo "\n--- 4: montos de los instrumentos ---\n";
    // Se comparan los montos REALMENTE emitidos, no valores fijos: el helper los ajusta al
    // monto pagable de la orden, que depende de la OPA real que agarre el test.
    $esperados = array_map(
        fn($a) => number_format((float) $a->monto_pago, 2, ',', '.'),
        $creados
    );
    $montos = true;
    foreach ($esperados as $e) { $montos = $montos && str_contains($html2, $e); }
    echo "  aparecen " . implode(' y ', $esperados) . ": " . var_export($montos, true) . "\n";
    $r['montos visibles'] = $montos;

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r['sin excepcion'] = false;
} finally {
    DB::rollBack();
    $ok = count(array_filter($r));
    echo "\n";
    foreach ($r as $k => $v) { printf("  %-32s %s\n", $k, $v ? 'OK' : '<<< FALLA'); }
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}

// Reconstruye el mismo arreglo de datos que arma printOrderPay, para poder mirar el HTML.
function datosDe($ctrl, $id) {
    $m = new ReflectionMethod($ctrl, 'printOrderPay');
    // printOrderPay devuelve el PDF ya armado; para inspeccionar el HTML se replica la
    // carga minima que necesita la vista.
    $q = \App\Models\Tesoreria\TesOrdenPagoEntity::with([
        'estado','opadetalle','opadetalle.detallefc','opadetalle.detallefc.razonSocial',
        'proveedor.datosBancarios','proveedor.tipoIva','prestador.datosBancarios','prestador.tipoIva',
        'pagos','pagos.formaPago','pagos.cuenta.entidadBancaria','pagos.pagosParciales',
        'pagos.fechaprobablepagos','pagos.bancoEmisor','pagos.estadoInstrumento',
    ])->find($id);

    $debito = 0;
    foreach ($q->opadetalle ?? [] as $d) { $debito += $d->detallefc->total_debitado_liquidacion ?? 0; }

    $instrumentos = ($q?->pagos ?? collect())->filter(fn($p) => !is_null($p->id_estado_instrumento))->values();
    $faltan = $instrumentos->contains(fn($p) => empty(trim((string) $p->numero_echeq)));
    $version = $instrumentos->isEmpty() ? null
        : ($faltan ? 'PENDIENTE DE EMISION - COPIA PARA TESORERIA' : 'COMPROBANTE DEFINITIVO');

    return [
        'instrumentos' => $instrumentos, 'version_comprobante' => $version,
        'comprobante_nro' => $q?->num_orden_pago, 'fecha_emision' => $q?->fecha_emision,
        'cuit_proveedor' => $q?->proveedor?->cuit ?? $q?->prestador?->cuit,
        'nombre_proveedor' => $q?->proveedor?->razon_social ?? $q?->prestador?->razon_social,
        'cbu_proveedor' => null, 'iva_proveedor' => null, 'domicilio_proveedor' => null,
        'facturas' => $q?->opadetalle, 'total' => $q?->monto_orden_pago, 'pagos' => $q?->pagos,
        'fecha_pago' => $q?->fecha_confirma_pago, 'debito' => $debito,
        'totalPagos' => '0.00', 'razon_social' => 'PRUEBA',
        'observaciones' => $q?->observaciones,
        'pagosParciales' => $q?->pagos?->pluck('pagosParciales')?->flatten() ?? collect(),
    ];
}
