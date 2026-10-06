<?php
// Comprobante de una OPA recien confirmada: solo tiene cronograma (fecha + cuota), todavia
// sin ningun pago emitido. Bug real encontrado en pruebas manuales el 2026-09-05: el PDF salia
// sin sello y sin mostrar las fechas planificadas porque printOrderPay() solo miraba
// pagosParciales (que no existen hasta que se emite algo). Fix: agregar "fechas_pendientes"
// al array de datos y ajustar la condicion del sello.

use App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

Auth::login(\App\Models\User::first());

$opa = TesOrdenPagoEntity::where('id_estado_orden_pago', 1)
    ->whereHas('opadetalle')->orderByDesc('id_orden_pago')->first();
if (!$opa) { echo "SIN OPA\n"; return; }

$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $boleta = TesPagoEntity::create([
        'id_orden_pago' => $opa->id_orden_pago, 'fecha_registra' => now(),
        'monto_opa' => $opa->monto_orden_pago, 'monto_anticipado' => 0,
        'anticipo' => 0, 'recursor' => 0, 'pago_emergencia' => 0,
        'id_forma_pago' => 7, 'id_estado_orden_pago' => 1, 'id_usuario' => 1,
        'tipo_factura' => $opa->tipo_factura ?: 'PRESTADOR',
    ]);

    DB::table('tb_tes_fecha_probable_pago')->insert([
        ['fecha_registra' => now(), 'fecha_probable_pago' => '2026-10-15', 'orden_cuotas' => 1, 'id_pago' => $boleta->id_pago],
        ['fecha_registra' => now(), 'fecha_probable_pago' => '2026-11-15', 'orden_cuotas' => 2, 'id_pago' => $boleta->id_pago],
    ]);

    $ctrl = app(TesOrdenPagoController::class);
    $bin = app(TesOrdenPagoController::class)->printOrderPay($opa->id_orden_pago)->getContent();

    echo "--- pdf generado ---\n";
    $r[] = strlen($bin) > 500 && substr($bin, 0, 4) === '%PDF';
    echo $ok(end($r));

    $tmp = tempnam(sys_get_temp_dir(), 'opa') . '.pdf';
    file_put_contents($tmp, $bin);
    $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>NUL') ?? '';
    @unlink($tmp);

    echo "--- sello de pendiente de emision ---\n";
    $r[] = str_contains($texto, 'PENDIENTE DE EMISION');
    echo $ok(end($r));

    echo "--- muestra las dos fechas planificadas, sin monto ---\n";
    $r[] = str_contains($texto, '2026-10-15') && str_contains($texto, '2026-11-15')
        && substr_count($texto, 'Pendiente de emitir') === 2;
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
