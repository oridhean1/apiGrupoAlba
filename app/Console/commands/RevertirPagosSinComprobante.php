<?php

namespace App\Console\Commands;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\AsientosPagoHistorialRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de "pago ejecutado al subir el comprobante" (R-00000439, 2026-10-28).
 *
 * Con el circuito anterior, CONFIRMAR un pago debitaba la cuenta y generaba el asiento aunque
 * todavía no hubiera comprobante. Desde la migración 2026_10_28_100000 eso pasa recién al subir el
 * comprobante, y lo que se confirmó sin comprobante queda IMPAGO. Este comando deja esos pagos como
 * si se hubieran cargado con el circuito nuevo (decidido con el usuario, opción B):
 *
 * - **Revierte**: contraasiento de los asientos de pago de la boleta y devolución del débito a cada
 *   cuenta; el pago queda cargado sin confirmar (IMPAGO). Al subir el comprobante se vuelve a
 *   debitar y asentar con la fecha real.
 * - **No revierte** un eCheq ACREDITADO: el banco ya lo debitó. Lo marca ejecutado con la fecha de
 *   acreditación.
 * - **No toca** una boleta MIXTA (parte con comprobante y parte sin, o con un acreditado): el
 *   asiento es uno solo para todos los pagos y no se puede partir con seguridad. Se lista para
 *   revisarla a mano.
 *
 * Sin `--ejecutar` corre todo dentro de una transacción y la deshace: muestra exactamente lo que
 * haría.
 *
 * Ver docs/circuito-pagos/plan-pago-ejecutado-con-comprobante.md.
 */
class RevertirPagosSinComprobante extends Command
{
    protected $signature = 'pagos:revertir-sin-comprobante
                            {--usuario= : cod_usuario que queda registrado en contraasientos y movimientos}
                            {--corte=2026-09-12 : lo confirmado antes de esta fecha se da por ejecutado, no se revierte}
                            {--database= : base a usar (por defecto la del .env)}
                            {--ejecutar : aplica los cambios; sin esto es una simulación}
                            {--sql= : en simulación, escribe en este archivo el SQL que aplicaría (para correrlo en prod)}';

    /**
     * Tablas donde el comando inserta filas nuevas. El SQL generado lleva sus ids literales, así que
     * sólo es válido si en prod el último id sigue siendo el de la copia: el script lo verifica
     * antes de escribir nada.
     */
    private const TABLAS_CON_ALTA = [
        'tb_cont_asientos_contables'          => 'id_asiento_contable',
        'tb_cont_asientos_contables_detalle'  => 'id_asiento_contable_detalle',
        'tb_cont_asientos_pago_historial'     => 'id_pago_asiento',
        'tb_tes_movimiento_cuenta_bancaria'   => 'id_movimiento',
    ];

    protected $description = 'Revierte débito y asiento de los pagos confirmados sin comprobante (pasan a IMPAGO).';

    public function handle()
    {
        if ($this->option('database')) {
            config(['database.connections.mysql.database' => $this->option('database')]);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $usuario = User::find($this->option('usuario'));
        if (is_null($usuario)) {
            $this->error('Indicá un --usuario válido (cod_usuario).');
            return 1;
        }
        Auth::login($usuario);

        $ejecutar = (bool) $this->option('ejecutar');
        $this->info('Base: ' . DB::connection()->getDatabaseName() . ' — ' . ($ejecutar ? 'EJECUTANDO' : 'SIMULACIÓN (se deshace al final)'));

        $asientoRepo   = new AsientoContableRepository();
        $historialRepo = new AsientosPagoHistorialRepository($asientoRepo);
        $cuentasRepo   = new TesCuentasBancariasRepository();
        $opaRepo       = new TestOrdenPagoRepository();

        $corte = (string) $this->option('corte');
        $filas = [];
        $mixtas = [];
        $viejas = [];
        $totales = ['revertidas' => 0, 'acreditados' => 0, 'contraasientos' => 0, 'devuelto' => 0.0];

        // ── Captura del SQL para producción (en prod no se corren comandos: sólo SQL) ──
        $archivoSql = $this->option('sql');
        $sentencias = [];
        $maximos = [];
        if ($archivoSql) {
            foreach (self::TABLAS_CON_ALTA as $tabla => $pk) {
                $maximos[$tabla] = (int) DB::table($tabla)->max($pk);
            }
            $pdo = DB::getPdo();
            DB::listen(function ($q) use (&$sentencias, $pdo) {
                if (!preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                    return;
                }
                $sql = $q->sql;
                foreach ($q->bindings as $b) {
                    if (is_null($b)) {
                        $v = 'NULL';
                    } elseif (is_bool($b)) {
                        $v = $b ? '1' : '0';
                    } elseif (is_int($b) || is_float($b)) {
                        $v = (string) $b;
                    } elseif ($b instanceof \DateTimeInterface) {
                        $v = $pdo->quote($b->format('Y-m-d H:i:s'));
                    } else {
                        $v = $pdo->quote((string) $b);
                    }
                    $sql = preg_replace('/\?/', str_replace(['\\', '$'], ['\\\\', '\\$'], $v), $sql, 1);
                }

                // El INSERT lleva su id explícito: las sentencias siguientes lo referencian, y el
                // contador AUTO_INCREMENT de prod podría estar más adelante que el último id.
                if (preg_match('/^\s*insert into `(\w+)` \(/i', $sql, $m)
                    && isset(self::TABLAS_CON_ALTA[$m[1]])) {
                    $id = (int) $pdo->lastInsertId();
                    $pk = self::TABLAS_CON_ALTA[$m[1]];
                    $sql = preg_replace('/^(\s*insert into `\w+` \()/i', '$1`' . $pk . '`, ', $sql, 1);
                    $sql = preg_replace('/\) values \(/i', ') values (' . $id . ', ', $sql, 1);
                }

                $sentencias[] = $sql . ';';
            });
        }

        DB::beginTransaction();
        try {
            $boletas = TesPagoEntity::where('id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)->get();

            foreach ($boletas as $boleta) {
                $tuvoAbonos = TesPagosParciales::where('id_pago', $boleta->id_pago)->exists();
                $abonos = TesPagosParciales::where('id_pago', $boleta->id_pago)->vivos()->get();

                if ($tuvoAbonos) {
                    $pendientes = $abonos->filter(fn($a) => is_null($a->fecha_ejecucion)
                        && (!is_null($a->fecha_confirmado_en_pago) || !is_null($a->fecha_confirma_pago)));

                    if ($pendientes->isEmpty()) {
                        continue;
                    }

                    $acreditados = $pendientes->filter(fn($a) => (int) $a->id_estado_instrumento === TesInstrumentoPagoRepository::ACREDITADO);
                    $revertibles = $pendientes->reject(fn($a) => (int) $a->id_estado_instrumento === TesInstrumentoPagoRepository::ACREDITADO);
                    $ejecutados  = $abonos->filter(fn($a) => !is_null($a->fecha_ejecucion));

                    // El banco ya debitó: queda ejecutado con esa fecha.
                    foreach ($acreditados as $a) {
                        $a->fecha_ejecucion = substr((string) $a->fecha_confirma_pago, 0, 10);
                        $a->save();
                        $totales['acreditados']++;
                    }

                    if ($revertibles->isEmpty()) {
                        $opaRepo->recalcularEstadoOpa($boleta->id_orden_pago);
                        continue;
                    }

                    if ($ejecutados->isNotEmpty() || $acreditados->isNotEmpty()) {
                        $mixtas[] = [$boleta->id_pago, $boleta->id_orden_pago, $revertibles->count(), number_format($revertibles->sum('monto_pago'), 2, ',', '.')];
                        continue;
                    }
                } else {
                    // Circuito viejo: el pago está en la cabecera.
                    $confirmada = (int) $boleta->id_estado_orden_pago === TestOrdenPagoRepository::ESTADO_OPA_PAGADO
                        || !is_null($boleta->fecha_confirma_pago);
                    $conComprobante = DB::table('tb_test_pago_detalle_comprobantes')
                        ->where('id_pago', $boleta->id_pago)->where('estado', '1')->exists();

                    if (!$confirmada || $conComprobante) {
                        continue;
                    }
                    // Anterior al corte: el código ya la da por pagada
                    // (TestOrdenPagoRepository::CORTE_COMPROBANTE_OBLIGATORIO).
                    if (!is_null($boleta->fecha_confirma_pago)
                        && substr((string) $boleta->fecha_confirma_pago, 0, 10) < $corte) {
                        continue;
                    }
                    $revertibles = collect();
                }

                $asientos = DB::table('tb_cont_asientos_pago_historial as h')
                    ->join('tb_cont_asientos_contables as a', 'a.id_asiento_contable', '=', 'h.id_asiento_contable')
                    ->where('h.id_pago', $boleta->id_pago)
                    ->where('h.es_contraasiento', 0)
                    ->where('a.vigente', 'ACTIVO')
                    ->where('a.asiento_modelo', 'PAGO')
                    ->distinct()
                    ->pluck('h.id_asiento_contable');

                // ── Opción B (2026-10-28): lo viejo se da por ejecutado, no se revierte ──
                //
                // Confirmado antes del corte, o sin asiento (circuito anterior a los asientos):
                // casi seguro salió de verdad y nadie subió el comprobante porque no se pedía.
                // Revertirlo inflaría el saldo de la cuenta. Queda ejecutado con su fecha de
                // confirmación, sin tocar saldo ni contabilidad.
                $fechaConf = $revertibles
                    ->map(fn($a) => substr((string) ($a->fecha_confirma_pago ?? $a->fecha_confirmado_en_pago), 0, 10))
                    ->filter()
                    ->min();

                if ($tuvoAbonos && ($asientos->isEmpty() || ($fechaConf && $fechaConf < $corte))) {
                    foreach ($revertibles as $a) {
                        $a->fecha_ejecucion = substr((string) ($a->fecha_confirma_pago ?? $a->fecha_confirmado_en_pago), 0, 10);
                        $a->save();
                    }
                    $opaRepo->recalcularEstadoOpa($boleta->id_orden_pago);
                    $num = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $boleta->id_orden_pago)->value('num_orden_pago');
                    $viejas[] = [$boleta->id_pago, $num, $revertibles->count(), $fechaConf ?: '—', $asientos->isEmpty() ? 'sin asiento' : 'antes del corte'];
                    continue;
                }

                // ── Reversión completa de la boleta ──
                $contras = 0;

                foreach ($asientos as $idAsiento) {
                    $historialRepo->generarContraasiento(
                        $idAsiento,
                        'REVERSION',
                        $boleta->id_pago,
                        'Pago confirmado sin comprobante: se revierte hasta que se suba (2026-10-28)'
                    );
                    $contras++;
                }

                $devuelto = 0.0;
                $netos = DB::table('tb_tes_movimiento_cuenta_bancaria')
                    ->where('id_pago', $boleta->id_pago)
                    ->whereNull('id_operacion')
                    ->groupBy('id_cuenta_bancaria')
                    ->select('id_cuenta_bancaria', DB::raw("SUM(CASE WHEN tipo_movimiento = 'EGRESO' THEN monto ELSE -monto END) AS neto"))
                    ->get();

                foreach ($netos as $n) {
                    if ((float) $n->neto <= 0.009) {
                        continue;
                    }
                    $cuentasRepo->findByDepositoCuenta($n->id_cuenta_bancaria, (float) $n->neto);
                    $cuentasRepo->findByRegistrarMovimiento($n->id_cuenta_bancaria, (float) $n->neto, 'INGRESO', $boleta->id_pago, null, 'REVERSION PAGO SIN COMPROBANTE');
                    $devuelto += (float) $n->neto;
                }

                foreach ($revertibles as $a) {
                    $a->fecha_confirma_pago = null;
                    $a->fecha_confirmado_en_pago = null;
                    // Un eCheq vuelve a "pendiente de emisión": se emite al subir su comprobante.
                    if ((int) $a->id_estado_instrumento === TesInstrumentoPagoRepository::EMITIDO
                        && (int) $a->id_forma_pago === TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ) {
                        $a->id_estado_instrumento = TesInstrumentoPagoRepository::PENDIENTE_EMISION;
                    }
                    $a->save();
                }

                $opaRepo->recalcularEstadoOpa($boleta->id_orden_pago);

                $num = DB::table('tb_tes_orden_pago')->where('id_orden_pago', $boleta->id_orden_pago)->value('num_orden_pago');
                $filas[] = [$boleta->id_pago, $num, $tuvoAbonos ? $revertibles->count() : 'cabecera', $contras, number_format($devuelto, 2, ',', '.'),
                    DB::table('tb_tes_pago')->where('id_pago', $boleta->id_pago)->value('id_estado_orden_pago')];
                $totales['revertidas']++;
                $totales['contraasientos'] += $contras;
                $totales['devuelto'] += $devuelto;
            }

            $this->table(['boleta', 'OPA', 'pagos revertidos', 'contraasientos', 'débito devuelto', 'estado final'], $filas);

            if ($viejas) {
                $this->info("Dadas por ejecutadas sin revertir (anteriores al {$corte} o sin asiento):");
                $this->table(['boleta', 'OPA', 'pagos', 'confirmado', 'motivo'], $viejas);
            }

            if ($mixtas) {
                $this->warn('Boletas MIXTAS (no se tocaron, revisar a mano):');
                $this->table(['boleta', 'OPA (id)', 'pagos sin comprobante', 'monto'], $mixtas);
            }

            $this->info(sprintf(
                'Boletas revertidas: %d · contraasientos: %d · débito devuelto: $%s · dadas por ejecutadas: %d · eCheq acreditados marcados ejecutados: %d · mixtas: %d',
                $totales['revertidas'], $totales['contraasientos'], number_format($totales['devuelto'], 2, ',', '.'),
                count($viejas), $totales['acreditados'], count($mixtas)
            ));

            if ($ejecutar) {
                DB::commit();
                $this->info('Cambios aplicados.');
            } else {
                DB::rollBack();
                $this->info('Simulación: no se guardó nada.');
            }

            if ($archivoSql && !$ejecutar) {
                $this->escribirScript($archivoSql, $sentencias, $maximos);
                $this->info("SQL para producción: {$archivoSql} (" . count($sentencias) . ' sentencias)');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Error, no se guardó nada: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return 1;
        }

        return 0;
    }

    /**
     * Script para pegar en phpMyAdmin. Arranca verificando que prod no cambió desde la copia (si
     * cambió, la subconsulta devuelve 2 filas, MySQL corta con error 1242 y no se escribe nada), y
     * va todo en una transacción.
     */
    private function escribirScript(string $archivo, array $sentencias, array $maximos): void
    {
        $base = DB::connection()->getDatabaseName();
        $l = [];
        $l[] = '-- Reversión de pagos confirmados sin comprobante (R-00000439, fase 3).';
        $l[] = "-- Generado el " . date('Y-m-d H:i') . " contra la copia `{$base}` con corte {$this->option('corte')}.";
        $l[] = '-- Correr en la base de prod de la que salió esa copia, sin cargar pagos en el medio.';
        $l[] = '-- Si una guarda de abajo falla (error 1242), prod cambió: NO seguir, pedir una copia nueva.';
        $l[] = '';
        $l[] = 'START TRANSACTION;';
        $l[] = '';
        $l[] = '-- Guardas: el último id de cada tabla tiene que ser el de la copia.';
        foreach ($maximos as $tabla => $max) {
            $pk = self::TABLAS_CON_ALTA[$tabla];
            $l[] = "SELECT (SELECT 1 UNION ALL SELECT 2 FROM DUAL WHERE (SELECT COALESCE(MAX({$pk}),0) FROM {$tabla}) <> {$max}) AS guarda_{$tabla};";
        }
        $l[] = '';
        foreach ($sentencias as $s) {
            $l[] = $s;
        }
        $l[] = '';
        $l[] = 'COMMIT;';
        file_put_contents($archivo, implode("\n", $l) . "\n");
    }
}
