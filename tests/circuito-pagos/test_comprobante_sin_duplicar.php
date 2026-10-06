<?php
// Bug real encontrado el 2026-09-05 mirando el PDF impreso: cada eCheq del circuito nuevo
// aparecia DOS VECES en "Valores Entregados" (el total no se duplicaba, pero la fila si).
// Causa: dos @foreach distintos en orden_pago.blade.php recorrian la MISMA coleccion de
// pagosParciales -> $instrumentos (filtrado por id_estado_instrumento) y el loop viejo
// ($pagos->pagosParciales sin filtrar). Fix: el loop viejo ahora excluye los que ya tienen
// id_estado_instrumento (esos ya salieron por $instrumentos).

use App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    // Monto minimo: el test emite $777,35 fijos y desde el 2026-09-05 no se puede emitir
    // por encima del monto pagable de la orden.
    $opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
        ->where('monto_orden_pago', '>=', 2000)
        ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
    if (!$opa) { echo "SIN OPA\n"; return; }

    $repo = new TesInstrumentoPagoRepository();
    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);
    $idFecha = DB::table('tb_tes_fecha_probable_pago')->insertGetId([
        'fecha_registra' => now(), 'fecha_probable_pago' => '2026-10-30',
        'orden_cuotas' => 1, 'id_pago' => $boleta->id_pago,
    ]);
    $abono = $repo->emitirPagoDeFecha($idFecha, [
        'monto' => 777.35, 'id_forma_pago' => TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ,
        'id_banco_emisor' => 1,
    ]);
    $repo->guardarBorradorNumero($abono->id_pago_parcial, 'DEDUP-TEST-001');

    $bin = app(TesOrdenPagoController::class)->printOrderPay($opa->id_orden_pago)->getContent();
    $tmp = tempnam(sys_get_temp_dir(), 'opa') . '.pdf';
    file_put_contents($tmp, $bin);
    $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>NUL') ?? '';
    @unlink($tmp);

    echo "--- el numero de eCheq aparece UNA sola vez ---\n";
    $vecesNumero = substr_count($texto, 'DEDUP-TEST-001');
    echo "  apariciones: {$vecesNumero} (esperado 1)\n";
    $r[] = ($vecesNumero === 1);
    echo $ok(end($r));

    echo "--- el monto del eCheq se lista UNA sola vez (fila + total = 2) ---\n";
    // Desde el 2026-09-10 la columna "Valores Entregados" cierra con `Entregado:` = la suma de
    // sus filas, asi que con un unico eCheq el importe aparece DOS veces de forma legitima: en
    // la fila y en el total. Si la plantilla volviera a duplicar la fila -el bug que este test
    // cuida- serian TRES.
    //
    // Se cuenta sobre el PDF entero: aislar la columna no sirve porque `pdftotext -layout`
    // pone las dos tablas en la misma linea de texto.
    $vecesMonto = substr_count($texto, '777,35');
    echo "  apariciones: {$vecesMonto} (esperado 2: la fila + el total)\n";
    $r[] = ($vecesMonto === 2);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
