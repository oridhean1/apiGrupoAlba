<?php
// Acreditar un eCheq con fecha FUTURA se rechaza (2026-10-06).
//
// El front proponía la fecha en UTC: desde las 21 hs de Argentina ya era "mañana", y el asiento de
// acreditación quedaba fechado al día siguiente. Acreditar dice que el banco YA debitó.
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];
$inst = app(Inst::class);
$opaRepo = app(TestOrdenPagoRepository::class);

$abono = DB::table('tb_tes_pago_parcial')
    ->where('id_estado_instrumento', Inst::EMITIDO)
    ->whereNotNull('fecha_confirmado_en_pago')
    ->orderByDesc('id_pago_parcial')->first();

if (!$abono) {
    echo "SE SALTEA: no hay un eCheq emitido y cargado en un pago para acreditar\n";
    return;
}

DB::beginTransaction();
try {
    // Que tenga número real: con uno provisorio la acreditación corta por otra guarda.
    DB::table('tb_tes_pago_parcial')->where('id_pago_parcial', $abono->id_pago_parcial)
        ->update(['numero_provisorio' => 0, 'numero_echeq' => 'FUT-' . $abono->id_pago_parcial]);

    $hoy = \Carbon\Carbon::now('America/Argentina/Buenos_Aires');

    echo "--- 1: con fecha de MAÑANA, corta ---\n";
    $msg = '';
    try {
        $inst->marcarAcreditado($abono->id_pago_parcial, $hoy->copy()->addDay()->toDateString(), $opaRepo);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
    }
    echo "  " . ($msg ?: 'NO CORTO') . "\n";
    $r[] = str_contains($msg, 'posterior a hoy');
    echo $ok(end($r));

    echo "--- 2: sigue EMITIDO (no quedó a medias) ---\n";
    $estado = DB::table('tb_tes_pago_parcial')->where('id_pago_parcial', $abono->id_pago_parcial)->value('id_estado_instrumento');
    $r[] = ((int) $estado === Inst::EMITIDO);
    echo $ok(end($r));
} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
}

$c = count(array_filter($r));
echo "\n=== {$c}/" . count($r) . ' OK ' . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
