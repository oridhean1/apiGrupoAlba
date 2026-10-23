<?php
// "Pagos a emitir": eCheq y transferencias cargados cuya fecha todavia no tiene comprobante.
// Es el archivo que Pagos lleva al banco, agrupado por banco emisor. Regla del comprobante por
// fecha desde el 2026-10-23 (antes el pago salia al cargarle el numero).
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

DB::beginTransaction();
try {
    $repo = new Inst();

    // Una boleta sin comprobantes, con DOS fechas del cronograma: el comprobante es por fecha.
    $fila = DB::table('tb_tes_pago as p')
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'p.id_orden_pago')
        ->join('tb_tes_fecha_probable_pago as fp', 'fp.id_pago', '=', 'p.id_pago')
        ->where('o.id_estado_orden_pago', '!=', 3)
        ->where('p.id_estado_orden_pago', '!=', 3)
        ->whereNotExists(fn($c) => $c->select(DB::raw(1))->from('tb_test_pago_detalle_comprobantes as c')
            ->whereColumn('c.id_pago', 'p.id_pago'))
        ->whereRaw('(SELECT COUNT(*) FROM tb_tes_fecha_probable_pago f2 WHERE f2.id_pago = p.id_pago) >= 2')
        ->select('p.id_pago', 'o.id_orden_pago', 'o.num_orden_pago', 'fp.id_fecha_probable', 'fp.fecha_probable_pago')
        ->first();

    $cta = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')->first();

    if (!$fila || !$cta) {
        echo "SE SALTEA: no hay boleta sin comprobante con 2 fechas, o ninguna cuenta con banco\n";
    } else {
        $otraFecha = DB::table('tb_tes_fecha_probable_pago')->where('id_pago', $fila->id_pago)
            ->where('id_fecha_probable', '!=', $fila->id_fecha_probable)->value('id_fecha_probable');
        $pagosRepo = new \App\Http\Controllers\Tesoreria\Repository\TesPagosRepository();
        $comprobante = fn($idFecha) => DB::table('tb_test_pago_detalle_comprobantes')->insertGetId([
            'id_pago' => $fila->id_pago, 'id_fecha_probable' => $idFecha, 'nombre_archivo' => 'test.pdf',
            'fecha_registra' => now(), 'cod_usuario_registra' => 1, 'estado' => 1,
        ]);
        $base = [
            'fecha_registra' => now(), 'monto_pago' => 12345.67, 'monto_opa' => 12345.67,
            'id_usuario' => 1, 'id_pago' => $fila->id_pago, 'monto_restante' => 0,
            'id_fecha_probable' => $fila->id_fecha_probable,
            'id_cuenta_bancaria' => $cta->id_cuenta_bancaria,
            'id_banco_emisor' => $cta->id_entidad_bancaria,
        ];

        $enListado = fn() => collect($repo->listarPagosAEmitir())
            ->where('num_orden_pago', $fila->num_orden_pago);

        echo "--- 1: un eCheq SIN numero aparece, con CBU vacio ---\n";
        $echeq = TesPagosParciales::create($base + [
            'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
            'id_estado_instrumento' => Inst::PENDIENTE_EMISION,
            'numero_echeq' => null,
        ]);
        $f = $enListado()->firstWhere('metodo_pago', 'ECHEQ');
        echo "  " . ($f ? "aparece: banco={$f->banco} monto={$f->monto} fecha={$f->fecha_pago} cbu='" . $f->cbu . "'" : 'NO aparece') . "\n";
        $r[] = ($f && $f->cbu === '' && (float) $f->monto === 12345.67
            && $f->fecha_pago === $fila->fecha_probable_pago);
        echo $ok(end($r));

        echo "--- 2: sin numero (o provisorio) NO deja adjuntar el comprobante ---\n";
        [, $motivo] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [$fila->id_fecha_probable], 1);
        $echeq->numero_echeq = Inst::PREFIJO_NUMERO_PROVISORIO . $echeq->id_pago_parcial;
        $echeq->numero_provisorio = true;
        $echeq->save();
        [, $motivoProv] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [$fila->id_fecha_probable], 1);
        echo "  sin numero: {$motivo}\n  provisorio: {$motivoProv}\n";
        $r[] = (bool) $motivo && (bool) $motivoProv;
        echo $ok(end($r));

        echo "--- 3: con el numero REAL sigue en el listado (sale con el comprobante, no con el numero) ---\n";
        $echeq->numero_echeq = 'BANCO-TEST-' . $echeq->id_pago_parcial;
        $echeq->numero_provisorio = false;
        $echeq->save();
        $r[] = $enListado()->where('metodo_pago', 'ECHEQ')->isNotEmpty();
        echo "  sigue: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));

        echo "--- 4: varias fechas y no se dijo cual -> corta; fecha ajena -> corta ---\n";
        [, $sinDecir] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [], 1);
        [, $ajena] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [999999999], 1);
        echo "  {$sinDecir} | {$ajena}\n";
        $r[] = (bool) $sinDecir && (bool) $ajena;
        echo $ok(end($r));

        echo "--- 5: comprobante de OTRA fecha no lo saca; el de SU fecha si ---\n";
        $idOtro = $comprobante($otraFecha);
        $sigue = $enListado()->where('metodo_pago', 'ECHEQ')->isNotEmpty();
        [$fechas, $motivo] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [$fila->id_fecha_probable], 1);
        $idSuyo = $comprobante($fila->id_fecha_probable);
        $salio = $enListado()->where('metodo_pago', 'ECHEQ')->isEmpty();
        echo "  otra fecha -> sigue: " . var_export($sigue, true) . " | valida: " . var_export(is_null($motivo), true) . " | su fecha -> salio: " . var_export($salio, true) . "\n";
        $r[] = $sigue && is_null($motivo) && $fechas === [(int) $fila->id_fecha_probable] && $salio;
        echo $ok(end($r));
        DB::table('tb_test_pago_detalle_comprobantes')->whereIn('id_comprobante', [$idOtro, $idSuyo])->delete();

        echo "--- 5b: un comprobante viejo SIN fecha cubre toda la boleta ---\n";
        $idViejo = $comprobante(null);
        $r[] = $enListado()->where('metodo_pago', 'ECHEQ')->isEmpty();
        echo "  salio: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));
        DB::table('tb_test_pago_detalle_comprobantes')->where('id_comprobante', $idViejo)->delete();

        $transf = TesPagosParciales::create($base + [
            'id_forma_pago' => Inst::FORMA_PAGO_TRANSFERENCIA,
            'id_estado_instrumento' => null,
            'num_cheque' => null,
        ]);
        echo "--- 5c: transferencia sin N de operacion: aparece y no deja adjuntar ---\n";
        [, $motivo] = $pagosRepo->fechasDeComprobantes($fila->id_pago, [$fila->id_fecha_probable], 1);
        echo "  {$motivo}\n";
        $r[] = (bool) $motivo && $enListado()->where('metodo_pago', 'TRANSFERENCIA')->isNotEmpty();
        echo $ok(end($r));

        echo "--- 6: un abono ANULADO no se lleva al banco ---\n";
        $transf->num_cheque = null;
        $transf->id_estado_instrumento = Inst::ANULADO;
        $transf->save();
        $r[] = $enListado()->where('metodo_pago', 'TRANSFERENCIA')->isEmpty();
        echo "  excluido: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));

        echo "--- 7: un CHEQUE nunca entra (nace EMITIDO, su numero se sabe al librarlo) ---\n";
        TesPagosParciales::create($base + [
            'id_forma_pago' => Inst::FORMA_PAGO_CHEQUE,
            'id_estado_instrumento' => Inst::EMITIDO,
            'num_cheque' => null,
        ]);
        $r[] = $enListado()->whereNotIn('metodo_pago', ['ECHEQ', 'TRANSFERENCIA'])->isEmpty();
        echo "  no aparece ninguna forma fuera de ECHEQ/TRANSFERENCIA: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));

        echo "--- 8: el listado sale ordenado por BANCO EMISOR ---\n";
        // Es la razon de ser del archivo: emitir en tandas, un banco por vez.
        // Los que no tienen banco van al final (2026-10-06).
        $bancos = collect($repo->listarPagosAEmitir())->pluck('banco')->all();
        $conBanco = array_values(array_filter($bancos, fn($b) => $b !== 'SIN BANCO ASIGNADO'));
        $sinBanco = array_values(array_filter($bancos, fn($b) => $b === 'SIN BANCO ASIGNADO'));
        sort($conBanco);
        $ordenados = array_merge($conBanco, $sinBanco);
        echo "  bancos: " . (implode(', ', array_unique($bancos)) ?: '(listado vacio)') . "\n";
        $r[] = ($bancos === $ordenados);
        echo $ok(end($r));
    }
} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
