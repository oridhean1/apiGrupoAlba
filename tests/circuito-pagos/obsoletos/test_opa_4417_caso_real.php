<?php
// Replica el caso real reportado: OPA-4417 (id 1137), boleta 2557, cronograma con la fecha 2306 y
// 2307 ambas el 2026-09-18 (cuota 2 y 3). El abono 2418 sobre la fecha 2307 esta RECHAZADO, asi
// que esa fecha esta libre; la 2306 esta ocupada por el abono 2417 (EMITIDO, vivo).

use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";

DB::beginTransaction();
$r = [];
try {
    $repo = new TesPagosRepository();
    $opaRepo = new TestOrdenPagoRepository();

    $idOpa = 1137; $idBoleta = 2557;
    $opa = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $idOpa)->first();
    if (!$opa) { echo "No existe la OPA 1137 en esta base, se saltea\n"; DB::rollBack(); return; }

    $razones = $opaRepo->razonesSocialesDeOpa($idOpa);
    $cta = DB::table('tb_tes_cuentas_bancarias')->whereIn('id_razon', $razones ?: [1])->first();
    if (!$cta) { echo "Sin cuenta de la razon social, se saltea\n"; DB::rollBack(); return; }

    echo "OPA {$opa->num_orden_pago} (id {$idOpa}) estado={$opa->id_estado_orden_pago}\n";
    foreach (DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $idBoleta)->get() as $f) {
        $ocupada = DB::table('tb_tes_pago_parcial')->where('id_fecha_probable', $f->id_fecha_probable)
            ->where(function ($q) { $q->whereNull('id_estado_instrumento')->orWhereNotIn('id_estado_instrumento', [5, 6]); })
            ->first();
        echo "  fecha {$f->id_fecha_probable} {$f->fecha_probable_pago} cuota {$f->orden_cuotas} -> "
            . ($ocupada ? "ocupada por abono {$ocupada->id_pago_parcial}" : "LIBRE") . "\n";
    }

    $libre = DB::table('tb_tes_fecha_probable_pago as fp')->where('fp.id_pago', $idBoleta)
        ->whereNotExists(function ($q) {
            $q->select(DB::raw(1))->from('tb_tes_pago_parcial as pp')
                ->whereColumn('pp.id_fecha_probable', 'fp.id_fecha_probable')
                ->where(function ($w) { $w->whereNull('pp.id_estado_instrumento')->orWhereNotIn('pp.id_estado_instrumento', [5, 6]); });
        })->first();

    if (!$libre) { echo "\nSin fechas libres, se saltea el resto\n"; DB::rollBack(); return; }

    echo "\n--- Eligiendo especificamente la fecha libre {$libre->id_fecha_probable} ({$libre->fecha_probable_pago}) por id ---\n";
    $repo->findByConfirmarPago((object) [
        'id_pago' => $idBoleta, 'id_orden_pago' => $idOpa,
        'id_cuenta_bancaria' => '', 'anticipo' => '0', 'monto_anticipado' => '0',
        'num_cheque' => null, 'fecha_probable_pago' => null, 'observaciones' => null,
        'id_forma_cobro' => null, 'monto_cobro' => null, 'fecha_confirma_cobro' => null,
        'cuenta_bancaria' => null, 'imputacion_contable' => null, 'banco' => null,
        'archivos_eliminados' => null, 'monto_pago' => 1,
        'lista_pagos' => [(object) [
            'id_pago_parcial' => null, 'fecha_confirma_pago' => $libre->fecha_probable_pago,
            'id_forma_pago' => 1, 'monto_pago' => 1, 'monto_opa' => $opa->monto_orden_pago,
            'num_cheque' => null, 'monto_restante' => 0,
            'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
            'id_fecha_probable' => $libre->id_fecha_probable,
        ]],
    ]);

    $nuevo = DB::table('tb_tes_pago_parcial')->where('id_pago', $idBoleta)
        ->orderByDesc('id_pago_parcial')->first();
    echo "  abono {$nuevo->id_pago_parcial} quedo con id_fecha_probable={$nuevo->id_fecha_probable} "
        . "(esperado {$libre->id_fecha_probable})\n";
    $r[] = ((int) $nuevo->id_fecha_probable === (int) $libre->id_fecha_probable);
    echo $ok(end($r));

} catch (\Throwable $e) {
    echo "EXCEPCION NO MANEJADA: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $okc = count(array_filter($r));
    echo "\n=== {$okc}/" . count($r) . " OK " . ($okc === count($r) ? '' : 'HAY FALLAS') . " (rollback hecho, nada quedo guardado)\n";
}
