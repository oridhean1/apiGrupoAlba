<?php
// "Pagos a emitir": eCheq y transferencias ya definidos que todavia no se emitieron en el banco.
// Es el archivo que Pagos lleva al banco, agrupado por banco emisor.
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

    // Una boleta viva de una OPA viva, con una fecha del cronograma libre para colgar el abono.
    $fila = DB::table('tb_tes_pago as p')
        ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'p.id_orden_pago')
        ->join('tb_tes_fecha_probable_pago as fp', 'fp.id_pago', '=', 'p.id_pago')
        ->whereIn('o.id_estado_orden_pago', [1, 2, 4, 6])
        ->where('p.id_estado_orden_pago', '!=', 3)
        ->select('p.id_pago', 'o.id_orden_pago', 'o.num_orden_pago', 'fp.id_fecha_probable', 'fp.fecha_probable_pago')
        ->first();

    $cta = DB::table('tb_tes_cuentas_bancarias')->whereNotNull('id_entidad_bancaria')->first();

    if (!$fila || !$cta) {
        echo "SE SALTEA: no hay boleta viva con cronograma, o ninguna cuenta con banco\n";
    } else {
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

        echo "--- 2: el numero PROVISORIO no cuenta como emitido: sigue apareciendo ---\n";
        // Lo puso el sistema para no frenar la carga del pago, no es el del banco.
        $echeq->numero_echeq = Inst::PREFIJO_NUMERO_PROVISORIO . $echeq->id_pago_parcial;
        $echeq->numero_provisorio = true;
        $echeq->save();
        $r[] = $enListado()->where('metodo_pago', 'ECHEQ')->isNotEmpty();
        echo "  sigue en el listado: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));

        echo "--- 3: con el numero REAL del banco desaparece ---\n";
        $echeq->numero_echeq = 'BANCO-TEST-' . $echeq->id_pago_parcial;
        $echeq->numero_provisorio = false;
        $echeq->save();
        $r[] = $enListado()->where('metodo_pago', 'ECHEQ')->isEmpty();
        echo "  salio del listado: " . var_export(end($r), true) . "\n";
        echo $ok(end($r));

        echo "--- 4: una transferencia sin referencia aparece, y SI trae el CBU destino ---\n";
        $transf = TesPagosParciales::create($base + [
            'id_forma_pago' => Inst::FORMA_PAGO_TRANSFERENCIA,
            'id_estado_instrumento' => null,
            'num_cheque' => null,
        ]);
        $f = $enListado()->firstWhere('metodo_pago', 'TRANSFERENCIA');
        echo "  " . ($f ? "aparece con cbu='" . $f->cbu . "' locatario='{$f->locatario}'" : 'NO aparece') . "\n";
        $r[] = (bool) $f;
        echo $ok(end($r));

        echo "--- 5: cargada la referencia, la transferencia desaparece ---\n";
        $transf->num_cheque = 'TRANSF-TEST';
        $transf->save();
        $r[] = $enListado()->where('metodo_pago', 'TRANSFERENCIA')->isEmpty();
        echo "  salio del listado: " . var_export(end($r), true) . "\n";
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
