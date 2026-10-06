<?php
// Numero repetido al cargar un pago. Reglas distintas por forma:
//   eCheq  -> unicidad GLOBAL (la columna ya tiene un indice UNIQUE).
//   cheque -> unicidad dentro de la CUENTA: el numero es el de la chequera, y dos bancos pueden
//             tener perfectamente el cheque 1234. Validar global rechazaria cargas correctas.
// Hasta el 2026-09-16 el ALTA de un abono no validaba nada: el eCheq repetido moria en el indice
// con un SQLSTATE 23000 crudo y el cheque repetido entraba sin aviso.
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository as Inst;
use App\Models\Tesoreria\TesPagosParciales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::login(\App\Models\User::first());
$ok = fn($c) => $c ? ">>> OK\n" : ">>> FALLA\n";
$r = [];

DB::beginTransaction();
try {
    $inst = new Inst();

    $boleta = DB::table('tb_tes_pago')->where('id_estado_orden_pago', '!=', 3)->orderByDesc('id_pago')->first();
    $ctas = DB::table('tb_tes_cuentas_bancarias')->limit(2)->pluck('id_cuenta_bancaria')->all();
    if (!$boleta || count($ctas) < 2) { echo "SE SALTEA: hacen falta una boleta y dos cuentas\n"; }
    else {
        [$ctaA, $ctaB] = $ctas;

        $base = [
            'fecha_registra' => now(), 'id_forma_pago' => Inst::FORMA_PAGO_CHEQUE,
            'monto_pago' => 1, 'monto_opa' => 1, 'id_usuario' => 1, 'id_pago' => $boleta->id_pago,
            'monto_restante' => 0, 'id_estado_instrumento' => Inst::EMITIDO,
        ];
        $chequeA = TesPagosParciales::create($base + ['num_cheque' => 'CHQ-TEST-1', 'id_cuenta_bancaria' => $ctaA]);

        echo "--- 1: el MISMO cheque en la MISMA cuenta -> rechaza ---\n";
        try { $inst->exigirNumeroLibre('CHQ-TEST-1', Inst::FORMA_PAGO_CHEQUE, $ctaA); echo "  NO corto\n"; $r[] = false; }
        catch (\Throwable $e) { echo "  {$e->getMessage()}\n"; $r[] = str_contains($e->getMessage(), 'misma cuenta'); }
        echo $ok(end($r));

        echo "--- 2: el mismo numero en OTRA cuenta -> se permite (es otra chequera) ---\n";
        $libre = $inst->numeroChequeDisponible('CHQ-TEST-1', $ctaB);
        echo "  disponible en la cuenta {$ctaB}: " . var_export($libre, true) . "\n";
        $r[] = ($libre === true);
        echo $ok(end($r));

        echo "--- 3: revalidar el propio numero no choca consigo mismo ---\n";
        $propio = $inst->numeroChequeDisponible('CHQ-TEST-1', $ctaA, $chequeA->id_pago_parcial);
        echo "  disponible excluyendose: " . var_export($propio, true) . "\n";
        $r[] = ($propio === true);
        echo $ok(end($r));

        echo "--- 4: un cheque ANULADO libera su numero ---\n";
        $chequeA->id_estado_instrumento = Inst::ANULADO;
        $chequeA->save();
        $trasAnular = $inst->numeroChequeDisponible('CHQ-TEST-1', $ctaA);
        echo "  disponible tras anular: " . var_export($trasAnular, true) . "\n";
        $r[] = ($trasAnular === true);
        echo $ok(end($r));
        $chequeA->id_estado_instrumento = Inst::EMITIDO;
        $chequeA->save();

        echo "--- 5: eCheq repetido -> rechaza, y NO le importa la cuenta ---\n";
        $echeq = TesPagosParciales::create($base + [
            'id_forma_pago' => Inst::FORMA_PAGO_ECHEQ,
            'numero_echeq' => 'ECHQ-TEST-9', 'num_cheque' => 'ECHQ-TEST-9', 'id_cuenta_bancaria' => $ctaA,
        ]);
        try { $inst->exigirNumeroLibre('ECHQ-TEST-9', Inst::FORMA_PAGO_ECHEQ, $ctaB); echo "  NO corto\n"; $r[] = false; }
        catch (\Throwable $e) { echo "  {$e->getMessage()}\n"; $r[] = str_contains($e->getMessage(), 'ya está usado'); }
        echo $ok(end($r));

        echo "--- 6: un numero vacio no se valida (el eCheq puede no tenerlo todavia) ---\n";
        try { $inst->exigirNumeroLibre('', Inst::FORMA_PAGO_ECHEQ, $ctaA); echo "  paso sin cortar\n"; $r[] = true; }
        catch (\Throwable $e) { echo "  corto mal: {$e->getMessage()}\n"; $r[] = false; }
        echo $ok(end($r));

        echo "--- 7: una transferencia con el mismo numero no se bloquea ---\n";
        // `num_cheque` guarda tambien la referencia de transferencia. La regla es de cheques.
        try { $inst->exigirNumeroLibre('CHQ-TEST-1', 1, $ctaA); echo "  paso sin cortar\n"; $r[] = true; }
        catch (\Throwable $e) { echo "  corto mal: {$e->getMessage()}\n"; $r[] = false; }
        echo $ok(end($r));
    }
} catch (\Throwable $e) {
    echo "EXCEPCION: {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n"; $r[] = false;
} finally {
    DB::rollBack();
    $c = count(array_filter($r));
    echo "\n=== {$c}/" . count($r) . " OK " . ($c === count($r) ? '' : '<<< HAY FALLAS') . " (rollback hecho)\n";
}
