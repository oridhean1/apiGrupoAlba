<?php
// Exportacion Excel + PDF del listado de eCheq pendientes de numero.
// Genera datos reales, exporta, y verifica que los archivos salgan con contenido.

use App\Exports\EcheqPendientesNumeroExport;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Services\TesInstrumentoPagoController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

Auth::login(\App\Models\User::first());
$out = sys_get_temp_dir();

// Dos OPAs de bancos distintos, para que el agrupado tenga algo que agrupar
// Se eligen OPAs con monto suficiente: desde el 2026-09-05 no se puede emitir por encima del
// monto pagable, y sobre una orden de $200 este escenario (1500 + 300 + 10) no entra.
$opas = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->where('monto_orden_pago', '>=', 5000)
    ->orderByDesc('id_orden_pago')->limit(2)->get();
if ($opas->count() < 2) { echo "SIN OPAS\n"; return; }

$r = [];
DB::beginTransaction();
try {
    $repo = new Inst();
    $ctrl = app(TesInstrumentoPagoController::class);

    $repo->crearInstrumentos($opas[0]->id_orden_pago, [
        ['monto' => 1500.25, 'fecha' => '2026-09-15', 'id_banco_emisor' => 2],
        ['monto' =>  300.75, 'fecha' => '2026-09-16', 'id_banco_emisor' => 2],
    ]);
    $repo->crearInstrumentos($opas[1]->id_orden_pago, [
        ['monto' => 900.00, 'fecha' => '2026-09-17', 'id_banco_emisor' => 3],
    ]);
    $repo->marcarPendienteEmision($opas[0]->id_orden_pago);
    $repo->marcarPendienteEmision($opas[1]->id_orden_pago);

    echo "--- datos base ---\n";
    $lista = $repo->listarPendientesDeNumero();
    echo "  pendientes totales: " . $lista->count() . "\n";
    foreach ($lista as $p) {
        echo "  OPA {$p->num_orden_pago} | banco="
            . ($p->bancoEmisor->descripcion_banco ?? 'SIN BANCO')
            . " | " . Inst::nombreBeneficiario($p->opa)
            . " | $" . $p->monto_pago . "\n";
    }
    $r[] = $lista->count() >= 3;

    echo "\n--- banco resuelto por relacion (no queda 'SIN BANCO') ---\n";
    $sinBanco = $lista->filter(fn($p) => is_null($p->bancoEmisor))->count();
    echo "  pagos sin banco resuelto: {$sinBanco} (esperado 0)\n";
    $r[] = ($sinBanco === 0);

    echo "\n--- filtro por banco ---\n";
    $soloBbva = $repo->listarPendientesDeNumero(2);
    $soloMacro = $repo->listarPendientesDeNumero(3);
    echo "  banco 2: " . $soloBbva->count() . " | banco 3: " . $soloMacro->count() . "\n";
    $r[] = ($soloBbva->count() === 2 && $soloMacro->count() === 1);

    echo "\n--- EXCEL ---\n";
    $xlsx = $out . '/echeq-pendientes.xlsx';
    @unlink($xlsx);
    // Excel::store necesita un disk configurado; raw() devuelve los bytes y alcanza para verificar.
    $bin = Excel::raw(new EcheqPendientesNumeroExport($repo, null), \Maatwebsite\Excel\Excel::XLSX);
    file_put_contents($xlsx, $bin);
    $okXlsx = file_exists($xlsx) && filesize($xlsx) > 3000;
    echo "  archivo: " . (file_exists($xlsx) ? filesize($xlsx) . ' bytes' : 'NO SE GENERO') . "\n";
    echo "  firma zip/xlsx: " . substr(file_get_contents($xlsx, false, null, 0, 2), 0, 2) . " (esperado PK)\n";
    $r[] = $okXlsx;

    echo "\n--- PDF ---\n";
    $resp = $ctrl->exportarPdfPendientes(Request::create('/test', 'GET', []));
    echo "  status: " . $resp->getStatusCode() . " | content-type: " . $resp->headers->get('content-type') . "\n";
    $pdfBin = $resp->getContent();
    $pdf = $out . '/echeq-pendientes.pdf';
    file_put_contents($pdf, $pdfBin);
    $okPdf = strlen($pdfBin) > 1000 && substr($pdfBin, 0, 4) === '%PDF';
    echo "  bytes: " . strlen($pdfBin) . " | firma: " . substr($pdfBin, 0, 4) . " (esperado %PDF)\n";
    $r[] = $okPdf;

    echo "\n--- PDF filtrado por banco (debe pesar menos) ---\n";
    $resp2 = $ctrl->exportarPdfPendientes(Request::create('/test', 'GET', ['id_banco' => 3]));
    echo "  bytes filtrado: " . strlen($resp2->getContent()) . " vs completo: " . strlen($pdfBin) . "\n";
    $r[] = (strlen($resp2->getContent()) > 1000 && strlen($resp2->getContent()) < strlen($pdfBin));

    echo "\n--- conflicto cuenta vs banco explicito -> debe cortar ---\n";
    // La cuenta tiene que ser de la MISMA razon social que la orden: emitir desde la cuenta de otra
    // entidad del grupo esta prohibido (`validarCuentaDeRazonSocial`, 2026-09-07). Tomar la primera
    // cuenta con banco, sin mirar la orden, hacia que este bloque fallara segun que OPA tocara.
    $razonesOpa = (new TestOrdenPagoRepository())->razonesSocialesDeOpa($opas[0]->id_orden_pago);
    $cuenta = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')
        ->when(!empty($razonesOpa), fn($q) => $q->whereIn('id_razon', $razonesOpa))
        ->first();
    if ($cuenta) {
        $otro = ((int)$cuenta->id_entidad_bancaria === 2) ? 3 : 2;
        try {
            $repo->crearInstrumentos($opas[0]->id_orden_pago, [[
                'monto' => 10, 'fecha' => '2026-09-18',
                'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
                'id_banco_emisor' => $otro,
            ]]);
            echo "  NO corto\n"; $r[] = false;
        } catch (\Throwable $e) { echo "  corto: {$e->getMessage()}\n"; $r[] = true; }

        echo "\n--- banco derivado de la cuenta cuando no lo mandan ---\n";
        $der = $repo->crearInstrumentos($opas[0]->id_orden_pago, [[
            'monto' => 10, 'fecha' => '2026-09-18',
            'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
        ]]);
        $d = $der[0]->refresh();
        echo "  cuenta {$cuenta->id_cuenta_bancaria} (banco {$cuenta->id_entidad_bancaria}) -> id_banco_emisor={$d->id_banco_emisor}\n";
        $r[] = ((int)$d->id_banco_emisor === (int)$cuenta->id_entidad_bancaria);
    }

    echo "\n--- FK rechaza un banco inexistente ---\n";
    try {
        $repo->crearInstrumentos($opas[0]->id_orden_pago, [[
            'monto' => 10, 'fecha' => '2026-09-18', 'id_banco_emisor' => 987654,
        ]]);
        echo "  NO corto -> la FK no protege\n"; $r[] = false;
    } catch (\Throwable $e) {
        echo "  corto: " . substr($e->getMessage(), 0, 95) . "\n"; $r[] = true;
    }

} catch (\Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $ok = count(array_filter($r));
    echo "\n=== {$ok}/" . count($r) . " OK " . ($ok === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
