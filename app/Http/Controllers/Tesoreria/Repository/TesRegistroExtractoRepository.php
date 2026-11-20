<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository;
use App\Models\Contabilidad\RazonCuentaContableEntity;
use App\Models\Tesoreria\TesConciliacionMatcheoEntity;
use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesExtractosBancariosEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registrar desde el Visor de Conciliación una línea del extracto que no nació en Tesorería
 * (gastos e impuestos bancarios, sueldos, FCI, seguros, traspasos entre cuentas propias):
 * movimiento + saldo + asiento, y la línea queda conciliada contra ese movimiento.
 * R-00000689 E5 (respuestas P2 y P5 del cliente).
 *
 * La línea queda marcada con `tipo_origen_interno = REGISTRO_BANCARIO`. No se desconcilia a
 * secas: se anula el registro (contraasiento + movimiento inverso). Todo corta si falta un dato
 * (cuenta bancaria, razón social, período, cuenta contable): ver la regla de CLAUDE.md.
 */
class TesRegistroExtractoRepository
{
    public const ORIGEN = 'REGISTRO_BANCARIO';
    public const MODELO_ASIENTO = 'REGISTRO_BANCARIO';

    private $user;

    public function __construct(
        private AsientoContableRepository $asientos,
        private PeriodosContablesRepository $periodos
    ) {
        $this->user = Auth::user();
    }

    /** Conceptos para la línea: sugerido, cuáles tienen cuenta y las cuentas destino de un traspaso. */
    public function conceptosParaExtracto($idExtracto): array
    {
        $extracto = TesExtractosBancariosEntity::findOrFail($idExtracto);
        $sentido = TesConciliacionMatchingRepository::tipoMovimientoDeImporte($extracto->importe);
        $idRazon = $this->razonDe($extracto, false);

        $configurados = $idRazon
            ? RazonCuentaContableEntity::where('id_razon', $idRazon)->where('vigente', 1)
                ->whereNotNull('id_detalle_plan')->pluck('tipo_contraparte')->all()
            : [];

        $conceptos = [];
        foreach (ConceptosBancarios::CATALOGO as $codigo => $c) {
            if ($sentido && !ConceptosBancarios::admiteSentido($codigo, $sentido)) {
                continue;
            }
            $conceptos[] = [
                'codigo' => $codigo,
                'descripcion' => $c['descripcion'],
                'configurado' => $c['tipo'] === null || in_array($c['tipo'], $configurados, true),
            ];
        }

        return [
            'sugerido' => ConceptosBancarios::sugerir($extracto->concepto, $sentido),
            // Para que el modal diga de qué cuenta es la línea: el traspaso se registra desde
            // cualquiera de las dos puntas y la de origen no figura entre los destinos (2026-11-19).
            'cuenta_origen' => TesCuentasBancariasEntity::where('id_cuenta_bancaria', $extracto->id_cuenta_bancaria)->value('nombre_cuenta'),
            'sentido' => $sentido,
            'conceptos' => $conceptos,
            'cuentas_destino' => $idRazon
                ? TesCuentasBancariasEntity::where('id_razon', $idRazon)
                    ->where('id_cuenta_bancaria', '!=', $extracto->id_cuenta_bancaria)
                    ->where('activo', 1)
                    ->get(['id_cuenta_bancaria', 'nombre_cuenta'])
                : [],
        ];
    }

    public function registrar($idExtracto, string $codigo, $idCuentaDestino = null, ?string $observaciones = null): TesExtractosBancariosEntity
    {
        return DB::transaction(function () use ($idExtracto, $codigo, $idCuentaDestino, $observaciones) {
            $extracto = TesExtractosBancariosEntity::lockForUpdate()->findOrFail($idExtracto);
            if (in_array($extracto->estado_conciliacion, ['CONCILIADO_MANUAL', 'CONCILIADO_AUTO'], true)) {
                throw new Exception('La línea ya está conciliada.');
            }
            $concepto = ConceptosBancarios::get($codigo);
            $sentido = TesConciliacionMatchingRepository::tipoMovimientoDeImporte($extracto->importe);
            if (!$sentido) {
                throw new Exception('La línea tiene importe cero: no hay nada que registrar.');
            }
            if (!ConceptosBancarios::admiteSentido($codigo, $sentido)) {
                throw new Exception("\"{$concepto['descripcion']}\" no corresponde a un " . ($sentido === 'EGRESO' ? 'débito.' : 'crédito.'));
            }
            if (empty($extracto->fecha)) {
                throw new Exception('La línea no tiene fecha.');
            }

            $idRazon = $this->razonDe($extracto, true);
            $cuenta = TesCuentasBancariasEntity::find($extracto->id_cuenta_bancaria);
            $fecha = Carbon::parse($extracto->fecha)->toDateString();
            $importe = round(abs((float) $extracto->importe), 2);

            $periodo = $this->periodos->findByPeriodoContableEnFecha($fecha, $idRazon);
            if (!$periodo) {
                throw new Exception("No hay un período contable activo para el {$fecha}: no se puede generar el asiento.");
            }

            $marca = "[EXT {$extracto->id_extracto}]";
            $detalle = trim($concepto['descripcion'] . ' - ' . $extracto->concepto . ($observaciones ? " - {$observaciones}" : ''));

            $destino = null;
            if ($codigo === ConceptosBancarios::TRASPASO) {
                $destino = $this->cuentaDestinoValida($idCuentaDestino, $cuenta, $idRazon);
                // Un traspaso se registra desde UNA sola punta: si ya se registró desde la línea de la
                // otra cuenta, acá ya existe su movimiento espejo y registrar de nuevo duplica la plata
                // y el asiento. Esta línea se concilia contra ese movimiento (2026-11-19).
                $espejo = self::movimientosVigentes()
                    ->where('id_cuenta_bancaria', $cuenta->id_cuenta_bancaria)
                    ->where('tipo_movimiento', $sentido)
                    ->where('monto', $importe)
                    ->whereDate('fecha_movimiento', $fecha)
                    ->where('descripcion', 'like', 'TRASPASO desde/hacia %')
                    ->whereDoesntHave('matcheos', fn($q) => $q->where('estado', 1))
                    ->first();
                if ($espejo) {
                    throw new Exception("Este traspaso ya se registró desde la línea de la otra cuenta: en {$cuenta->nombre_cuenta} "
                        . "ya está el movimiento \"{$espejo->descripcion}\". No lo registres de nuevo: conciliá esta línea con "
                        . '"Revisar / buscar manual" eligiendo ese movimiento.');
                }
                $contra = ['id_cuenta_bancaria' => $destino->id_cuenta_bancaria, 'nombre' => $destino->nombre_cuenta];
            } else {
                try {
                    $idPlan = $this->asientos->obtenerCuentaVentaByRazon($idRazon, $concepto['tipo']);
                } catch (Exception $e) {
                    // Cuentas pendientes de definir por Contabilidad (respuesta P5 del cliente).
                    throw new Exception("Contabilidad todavía no cargó la cuenta contable de \"{$concepto['descripcion']}\" para esta razón social ({$concepto['tipo']}). Sin esa cuenta no se puede generar el asiento: se carga en Contabilidad › Relaciones Contables › Cuentas por razón social.");
                }
                $contra = ['id_detalle_plan' => $idPlan, 'nombre' => $concepto['descripcion']];
            }

            $asiento = $this->asientos->crearAsientoRegistroBancario([
                'id_extracto' => $extracto->id_extracto,
                'id_razon' => $idRazon,
                'id_cuenta_bancaria' => $cuenta->id_cuenta_bancaria,
                'nombre_cuenta' => $cuenta->nombre_cuenta,
                'sentido' => $sentido,
                'contra' => $contra,
                'importe' => $importe,
                'descripcion' => $detalle,
                'fecha' => $fecha,
            ], $periodo->id_periodo_contable);

            $mov = $this->mover($cuenta->id_cuenta_bancaria, $importe, $sentido, $fecha, "{$detalle} {$marca}");
            if ($destino) {
                // La otra punta del traspaso: queda como candidato para la línea de la otra cuenta.
                $this->mover($destino->id_cuenta_bancaria, $importe, $sentido === 'EGRESO' ? 'INGRESO' : 'EGRESO', $fecha,
                    "TRASPASO desde/hacia {$cuenta->nombre_cuenta} {$marca}");
            }

            $matcheo = TesConciliacionMatcheoEntity::where('id_extracto_bancario', $extracto->id_extracto)->latest('id_matching')->first()
                ?? new TesConciliacionMatcheoEntity(['id_extracto_bancario' => $extracto->id_extracto]);
            $matcheo->fill([
                'id_movimiento_interno' => $mov->id_movimiento,
                'id_pago_parcial' => null,
                'tipo_origen_interno' => self::ORIGEN,
                'score_obtenido' => 100,
                'reglas_cumplidas' => ['Registrado: ' . $concepto['descripcion']],
                'estado' => 1,
                'id_usuario_aprobador' => $this->user?->cod_usuario,
                'fecha_matching' => Carbon::now('America/Argentina/Buenos_Aires'),
                'observaciones' => mb_substr("{$concepto['descripcion']} · asiento {$asiento->numero}" . ($observaciones ? " · {$observaciones}" : ''), 0, 255),
            ]);
            $matcheo->save();

            $extracto->estado_conciliacion = 'CONCILIADO_MANUAL';
            $extracto->id_movimiento_match = $mov->id_movimiento;
            $extracto->id_usuario_confirma = $this->user?->cod_usuario;
            $extracto->fecha_confirma = Carbon::now('America/Argentina/Buenos_Aires');
            $extracto->save();

            return $extracto->fresh();
        });
    }

    /**
     * Deshace un registro: contraasiento (con la fecha del registro, P7), movimiento(s)
     * inverso(s) y la línea vuelve a PENDIENTE. Si la otra punta de un traspaso ya se concilió,
     * corta: primero hay que desconciliar esa.
     */
    public function anular($idExtracto, ?string $motivo = null): TesExtractosBancariosEntity
    {
        return DB::transaction(function () use ($idExtracto, $motivo) {
            $extracto = TesExtractosBancariosEntity::lockForUpdate()->findOrFail($idExtracto);
            $matcheo = TesConciliacionMatcheoEntity::where('id_extracto_bancario', $idExtracto)->latest('id_matching')->first();
            if (!$matcheo || $matcheo->tipo_origen_interno !== self::ORIGEN || !$matcheo->estado) {
                throw new Exception('La línea no tiene un registro bancario vigente.');
            }
            $idRazon = $this->razonDe($extracto, true);
            $marca = "[EXT {$extracto->id_extracto}]";

            // Sólo los movimientos del registro VIGENTE: si la línea ya se registró y anuló antes,
            // los de ese ciclo (y sus ANULACION) comparten la marca y no se vuelven a revertir (2026-11-19).
            $base = TesMovientosCuentaBancariaEntity::where('descripcion', 'like', "%{$marca}")
                ->whereNull('id_pago')->whereNull('id_operacion')->whereNull('id_orden_medio');
            $ultimaAnulacion = (clone $base)->where('descripcion', 'like', 'ANULACION %')->max('id_movimiento') ?? 0;
            $movs = (clone $base)->where('descripcion', 'not like', 'ANULACION %')
                ->where('id_movimiento', '>', $ultimaAnulacion)->get();
            foreach ($movs as $m) {
                if ($m->id_movimiento != $matcheo->id_movimiento_interno && $m->matcheos()->where('estado', 1)->exists()) {
                    throw new Exception('La otra punta del traspaso ya está conciliada con una línea de la otra cuenta: desconciliala primero.');
                }
            }

            $asiento = DB::table('tb_cont_asientos_contables')
                ->where('asiento_modelo', self::MODELO_ASIENTO)
                ->where('numero_referencia', $extracto->id_extracto)
                ->where('vigente', 'ACTIVO')
                ->first();
            if (!$asiento) {
                throw new Exception('No se encontró el asiento del registro: no se puede anular sin revertirlo.');
            }
            // Misma fecha contable que el registro (respuesta P7 del cliente): anular un registro es
            // corregir una clasificación, el banco no se movió dos veces. Si ese período ya cerró, corta.
            $fecha = Carbon::parse($asiento->fecha_asiento)->toDateString();
            $periodo = $this->periodos->findByPeriodoContableEnFecha($fecha, $idRazon);
            if (!$periodo) {
                throw new Exception("El período contable del registro ({$fecha}) no está activo: no se puede generar el contraasiento con esa fecha.");
            }
            $this->asientos->contraasientoCompleto($asiento->id_asiento_contable, 'ANULACION REGISTRO' . ($motivo ? " - {$motivo}" : ''), $periodo->id_periodo_contable, $fecha);

            foreach ($movs as $m) {
                $inverso = $m->tipo_movimiento === 'EGRESO' ? 'INGRESO' : 'EGRESO';
                $this->mover($m->id_cuenta_bancaria, (float) $m->monto, $inverso, $fecha, "ANULACION {$m->descripcion}");
            }

            $matcheo->estado = 0;
            $matcheo->id_movimiento_interno = null;
            $matcheo->tipo_origen_interno = null;
            $matcheo->observaciones = mb_substr('Registro anulado' . ($motivo ? ": {$motivo}" : ''), 0, 255);
            $matcheo->save();

            $extracto->estado_conciliacion = 'PENDIENTE';
            $extracto->id_movimiento_match = null;
            $extracto->save();

            return $extracto->fresh();
        });
    }

    /** Razón social de la línea = la de su cuenta bancaria. Sin cuenta, o si no coinciden, corta. */
    private function razonDe(TesExtractosBancariosEntity $extracto, bool $estricto): ?int
    {
        $cuenta = $extracto->id_cuenta_bancaria ? TesCuentasBancariasEntity::find($extracto->id_cuenta_bancaria) : null;
        if (!$cuenta || empty($cuenta->id_razon)) {
            if ($estricto) {
                throw new Exception('La línea no tiene una cuenta bancaria propia (con razón social) asignada: no se puede registrar.');
            }
            return null;
        }
        if ($extracto->id_razon && (int) $extracto->id_razon !== (int) $cuenta->id_razon) {
            if ($estricto) {
                throw new Exception('La cuenta bancaria de la línea es de otra razón social que el extracto.');
            }
            return null;
        }
        return (int) $cuenta->id_razon;
    }

    private function cuentaDestinoValida($idCuentaDestino, TesCuentasBancariasEntity $origen, int $idRazon): TesCuentasBancariasEntity
    {
        if (empty($idCuentaDestino)) {
            throw new Exception('Para un traspaso hay que elegir la otra cuenta propia.');
        }
        $destino = TesCuentasBancariasEntity::find($idCuentaDestino);
        if (!$destino || !$destino->activo) {
            throw new Exception('La cuenta destino no existe o está inactiva.');
        }
        if ((int) $destino->id_cuenta_bancaria === (int) $origen->id_cuenta_bancaria) {
            throw new Exception('La cuenta destino tiene que ser distinta de la de la línea.');
        }
        // Traspaso = misma titularidad. Entre razones sociales distintas es plata de una entidad
        // pasando a otra: no es un traspaso y no se registra así (regla de CLAUDE.md).
        if ((int) $destino->id_razon !== $idRazon) {
            throw new Exception("La cuenta {$destino->nombre_cuenta} es de otra razón social: un traspaso es sólo entre cuentas propias de la misma razón.");
        }
        return $destino;
    }

    /**
     * Movimientos que siguen valiendo: sin los "ANULACION ..." ni los que esas anulaciones revirtieron
     * (mismo texto con "ANULACION " adelante, misma cuenta, posterior). Lo usan la guarda del traspaso
     * y la lista de candidatos para conciliar a mano (2026-11-19).
     */
    public static function movimientosVigentes()
    {
        return TesMovientosCuentaBancariaEntity::query()
            ->where('descripcion', 'not like', 'ANULACION %')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tb_tes_movimiento_cuenta_bancaria as an')
                    ->whereColumn('an.id_cuenta_bancaria', 'tb_tes_movimiento_cuenta_bancaria.id_cuenta_bancaria')
                    ->whereColumn('an.id_movimiento', '>', 'tb_tes_movimiento_cuenta_bancaria.id_movimiento')
                    ->whereRaw("an.descripcion = CONCAT('ANULACION ', tb_tes_movimiento_cuenta_bancaria.descripcion)");
            });
    }

    private function mover($idCuenta, float $importe, string $tipo, string $fecha, string $descripcion): TesMovientosCuentaBancariaEntity
    {
        $cuentas = new TesCuentasBancariasRepository();
        $tipo === 'INGRESO' ? $cuentas->findByDepositoCuenta($idCuenta, $importe) : $cuentas->findByRetiroCuenta($idCuenta, $importe);
        return $cuentas->findByRegistrarMovimiento($idCuenta, $importe, $tipo, null, null, $descripcion, $fecha);
    }
}
