<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use Illuminate\Support\Facades\DB;

/**
 * Cuenta corriente de prestadores y proveedores (punto 10).
 *
 * Responde "¿cuánto le debemos a este prestador y por qué?": las facturas que generaron deuda, lo
 * que se le fue pagando, los anticipos con saldo a favor, y el neto.
 *
 * Se apoya entera en el FIFO y en los anticipos: no guarda ningún saldo propio. Un saldo
 * almacenado se desfasa el día que alguien anula un pago por otro camino.
 *
 * ═══ Qué cuenta como deuda ═══
 *
 * **Todas las facturas del beneficiario, salvo las ANULADAS (estado 4).**
 *
 * Definido por Contaduría el 2026-09-04: las facturas todavía no valorizadas también son deuda
 * — la obligación con el prestador nace cuando presenta la factura, no cuando Liquidaciones
 * termina de valorizarla.
 *
 * Impacto medido al adoptarlo, sobre Alba: la deuda pasó de $3.144.956.901,99 (4.288 facturas)
 * a $3.871.904.778,71 (4.878), sumando 590 facturas por $726.947.876,72 — en su mayoría las 509
 * en estado ABIERTA. Es el número correcto según el criterio del área, pero conviene tenerlo
 * presente al comparar contra reportes viejos.
 */
class TesCuentaCorrienteRepository
{
    const ESTADO_FACTURA_ANULADA            = 4;

    private $fifo;
    private $anticipos;

    public function __construct(TesImputacionFifoRepository $fifo, TesAnticipoRepository $anticipos)
    {
        $this->fifo      = $fifo;
        $this->anticipos = $anticipos;
    }

    private static function aCentavos($monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    private static function aPesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    private static function campoBeneficiario(string $tipo): string
    {
        return strtoupper(trim($tipo)) === 'PROVEEDOR' ? 'id_proveedor' : 'id_prestador';
    }

    /** Facturas que representan deuda de este beneficiario. Ver el criterio en la cabecera. */
    public function facturasConDeuda($idBeneficiario, string $tipo, ?string $desde = null, ?string $hasta = null)
    {
        $campo = self::campoBeneficiario($tipo);

        return DB::table('tb_facturacion_datos as f')
            ->where("f.{$campo}", $idBeneficiario)
            ->where('f.estado', '!=', self::ESTADO_FACTURA_ANULADA)
            ->when($desde, fn($q) => $q->whereDate('f.fecha_comprobante', '>=', $desde))
            ->when($hasta, fn($q) => $q->whereDate('f.fecha_comprobante', '<=', $hasta))
            ->select([
                'f.id_factura', 'f.numero', 'f.periodo', 'f.fecha_comprobante', 'f.estado',
                'f.total_neto', 'f.total_debitado_liquidacion',
            ])
            ->orderBy('f.fecha_comprobante')
            ->orderBy('f.id_factura')
            ->get();
    }

    /**
     * Resumen de la cuenta corriente.
     *
     * `saldo_neto` es lo que realmente habría que pagarle hoy: la deuda pendiente menos el saldo
     * a favor que ya se le adelantó. Puede dar negativo — significa que se le pagó de más.
     */
    public function resumen($idBeneficiario, string $tipo, ?string $desde = null, ?string $hasta = null): array
    {
        $facturas = $this->facturasConDeuda($idBeneficiario, $tipo, $desde, $hasta);

        $facturado = 0;
        $pagado    = 0;

        foreach ($facturas as $f) {
            $aPagar = self::aCentavos($f->total_neto) - self::aCentavos($f->total_debitado_liquidacion ?? 0);
            $facturado += $aPagar;
            $pagado += self::aCentavos($this->fifo->pagadoDeFactura($f->id_factura));
        }

        $anticipos = self::aCentavos($this->anticipos->saldoAFavor($idBeneficiario, $tipo));
        $pendiente = $facturado - $pagado;

        return [
            'id_beneficiario'       => $idBeneficiario,
            'tipo_beneficiario'     => strtoupper(trim($tipo)),
            'cantidad_facturas'     => $facturas->count(),
            'total_facturado'       => self::aPesos($facturado),
            'total_pagado'          => self::aPesos($pagado),
            'deuda_pendiente'       => self::aPesos(max(0, $pendiente)),
            'anticipos_disponibles' => self::aPesos($anticipos),
            'saldo_neto'            => self::aPesos($pendiente - $anticipos),
        ];
    }

    /**
     * Movimientos en orden cronológico, con saldo acumulado.
     *
     * Cada factura suma al debe; cada peso efectivamente cobrado suma al haber. Los anticipos
     * van aparte: no cancelan una factura hasta que se aplican, así que mostrarlos mezclados
     * haría parecer saldada una deuda que sigue viva.
     */
    public function movimientos($idBeneficiario, string $tipo, ?string $desde = null, ?string $hasta = null): array
    {
        $facturas = $this->facturasConDeuda($idBeneficiario, $tipo, $desde, $hasta);
        $movimientos = [];

        foreach ($facturas as $f) {
            $aPagar = self::aCentavos($f->total_neto) - self::aCentavos($f->total_debitado_liquidacion ?? 0);
            $cobrado = self::aCentavos($this->fifo->pagadoDeFactura($f->id_factura));

            $movimientos[] = [
                'fecha'       => $f->fecha_comprobante,
                'tipo'        => 'FACTURA',
                'comprobante' => $f->numero,
                'periodo'     => $f->periodo,
                'detalle'     => 'Factura ' . $f->numero,
                'debe'        => self::aPesos($aPagar),
                'haber'       => 0.0,
                'id_factura'  => $f->id_factura,
            ];

            if ($cobrado > 0) {
                $movimientos[] = [
                    'fecha'       => $f->fecha_comprobante,
                    'tipo'        => 'COBRO',
                    'comprobante' => $f->numero,
                    'periodo'     => $f->periodo,
                    'detalle'     => 'Pagos imputados a la factura ' . $f->numero,
                    'debe'        => 0.0,
                    'haber'       => self::aPesos($cobrado),
                    'id_factura'  => $f->id_factura,
                ];
            }
        }

        // Orden cronológico estable: ante misma fecha, primero la factura y después su cobro.
        usort($movimientos, function ($a, $b) {
            if ($a['fecha'] === $b['fecha']) {
                if ($a['id_factura'] === $b['id_factura']) {
                    return $a['tipo'] === 'FACTURA' ? -1 : 1;
                }

                return $a['id_factura'] <=> $b['id_factura'];
            }

            return strcmp((string) $a['fecha'], (string) $b['fecha']);
        });

        $acumulado = 0;

        foreach ($movimientos as &$m) {
            $acumulado += self::aCentavos($m['debe']) - self::aCentavos($m['haber']);
            $m['saldo'] = self::aPesos($acumulado);
        }

        return $movimientos;
    }

    /**
     * Busca beneficiarios que tengan movimientos de cuenta corriente.
     *
     * Usa EXACTAMENTE el mismo criterio de deuda que el detalle, para que el buscador y la
     * pantalla no se contradigan: si acá aparece, al abrirlo tiene que haber algo.
     *
     * No devuelve el saldo de cada uno: calcularlo exige recorrer el FIFO de todas sus facturas,
     * y hacerlo para una lista de búsqueda sería carísimo. El saldo se ve al abrir el detalle.
     */
    public function buscarBeneficiarios(
        string $tipo,
        ?string $texto = null,
        int $limite = 25,
        bool $soloConMovimientos = true
    ): array {
        $tipo  = strtoupper(trim($tipo));
        $esProv = $tipo === 'PROVEEDOR';

        $tabla = $esProv ? 'tb_proveedor' : 'tb_prestador';
        $pk    = $esProv ? 'cod_proveedor' : 'cod_prestador';
        $campo = $esProv ? 'id_proveedor' : 'id_prestador';

        $texto = trim((string) $texto);

        // Para dar un ANTICIPO no hace falta que el beneficiario tenga facturas: se le puede
        // adelantar plata a alguien con quien recién se empieza a trabajar. Por eso el filtro
        // de deuda es opcional, y en ese caso el join va por izquierda.
        return DB::table("{$tabla} as b")
            ->when(
                $soloConMovimientos,
                fn($q) => $q->join('tb_facturacion_datos as f', "f.{$campo}", '=', "b.{$pk}"),
                fn($q) => $q->leftJoin('tb_facturacion_datos as f', "f.{$campo}", '=', "b.{$pk}")
            )
            ->when($soloConMovimientos, fn($q) => $q->where('f.estado', '!=', self::ESTADO_FACTURA_ANULADA))
            ->when($texto !== '', function ($q) use ($texto) {
                $q->where(function ($w) use ($texto) {
                    $w->where('b.razon_social', 'like', "%{$texto}%")
                        ->orWhere('b.cuit', 'like', "%{$texto}%");
                });
            })
            ->groupBy("b.{$pk}", 'b.cuit', 'b.razon_social')
            ->orderBy('b.razon_social')
            ->limit($limite)
            ->select([
                DB::raw("b.{$pk} as id_beneficiario"),
                'b.cuit',
                'b.razon_social',
                DB::raw('COUNT(DISTINCT f.id_factura) as cantidad_facturas'),
            ])
            ->get()
            ->map(fn($b) => (array) $b + ['tipo_beneficiario' => $esProv ? 'PROVEEDOR' : 'PRESTADOR'])
            ->all();
    }

    /**
     * Movimientos con DOS saldos en paralelo: económico y financiero. (doc "Cuenta corriente del
     * proveedor — saldo económico y saldo financiero", Micaela, 2026-10-01)
     *
     *   - ECONÓMICO: baja apenas el pago se imputa a la factura, aunque el eCheq todavía no se haya
     *     hecho efectivo. Es la deuda "en los papeles".
     *   - FINANCIERO: baja recién cuando la plata sale del banco. Para una transferencia es al
     *     confirmarla; para un cheque/eCheq, cuando se ACREDITA. Mientras el eCheq figura emitido, el
     *     saldo financiero queda congelado.
     *
     * No hizo falta el campo nuevo que proponía el doc (`fecha_acreditación_real`): ya existe, es
     * `tb_tes_pago_parcial.fecha_confirma_pago`, y SOLO lo completa `marcarAcreditado()` — es decir,
     * el criterio conservador que pide el doc: no se asume la acreditación en la fecha pactada.
     *
     * Un renglón por PAGO (abono), no un cobro agregado por factura: es lo que permite ver qué eCheq
     * está emitido y cuál ya salió del banco. Las aplicaciones de anticipo bajan los dos saldos a
     * la vez: la plata salió del banco cuando se pagó el anticipo.
     *
     * Un eCheq rechazado o anulado no aparece (sólo abonos vivos): su imputación se revierte, y los
     * dos saldos vuelven juntos — el criterio del doc ante un rechazo.
     */
    public function movimientosDosSaldos($idBeneficiario, string $tipo, ?string $desde = null, ?string $hasta = null): array
    {
        $facturas = $this->facturasConDeuda($idBeneficiario, $tipo, $desde, $hasta);
        $idsFacturas = $facturas->pluck('id_factura')->all();
        $filas = [];

        foreach ($facturas as $f) {
            $filas[] = [
                'fecha'            => substr((string) $f->fecha_comprobante, 0, 10),
                'orden'            => 0,
                'tipo'             => 'FACTURA',
                'comprobante'      => 'Factura ' . $f->numero,
                'periodo'          => $f->periodo,
                'debe'             => self::aPesos(self::aCentavos($f->total_neto) - self::aCentavos($f->total_debitado_liquidacion ?? 0)),
                'haber'            => 0.0,
                'baja_economico'   => false,
                'baja_financiero'  => false,
                'fecha_pactada'    => null,
                'fecha_acreditada' => null,
                'estado'           => null,
            ];
        }

        // Órdenes que imputan facturas de este beneficiario, con cuánto imputa cada una a ESTAS
        // facturas: si el período deja afuera otras facturas de la misma orden, sus pagos no se
        // pueden contar enteros.
        $imputadoPorOpa = empty($idsFacturas) ? collect() : DB::table('tb_tes_opa_factura as pf')
            ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'pf.id_orden_pago')
            ->whereIn('pf.id_factura', $idsFacturas)
            ->where('o.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->groupBy('o.id_orden_pago', 'o.num_orden_pago', 'o.tipo_opa', 'o.fecha_genera')
            ->select('o.id_orden_pago', 'o.num_orden_pago', 'o.tipo_opa', 'o.fecha_genera',
                DB::raw('SUM(pf.monto_aplicado) as imputado'))
            ->get();

        $enCuenta = array_flip($idsFacturas);

        foreach ($imputadoPorOpa as $op) {
            $tope = self::aCentavos($op->imputado);

            // Los pagos de la orden recorren sus facturas en el MISMO orden que el FIFO
            // (`facturasOrdenadas`). Si la orden tiene facturas que no entran en esta cuenta
            // —anuladas, fuera del período—, la parte del pago que el FIFO les asigna a ellas no
            // baja esta deuda. Antes se topeaba por orden y no por factura: el prestador 128 de
            // Alba (OPA-1225, número duplicado) daba $23.854,18 de menos. (2026-10-02)
            $cola = [];
            foreach ($this->fifo->facturasOrdenadas($op->id_orden_pago) as $fo) {
                $cola[] = [self::aCentavos($fo->monto_aplicado), isset($enCuenta[$fo->id_factura])];
            }
            $consumir = function (int $centavos) use (&$cola): int {
                $enEsta = 0;
                while ($centavos > 0 && !empty($cola)) {
                    $toma = min($centavos, $cola[0][0]);
                    if ($cola[0][1]) { $enEsta += $toma; }
                    $cola[0][0] -= $toma;
                    $centavos -= $toma;
                    if ($cola[0][0] <= 0) { array_shift($cola); }
                }
                return $enEsta;
            };

            if ($op->tipo_opa === TesAnticipoRepository::TIPO_APLICACION) {
                $filas[] = [
                    'fecha'            => substr((string) $op->fecha_genera, 0, 10),
                    'orden'            => 1,
                    'tipo'             => 'APLICACION',
                    'comprobante'      => $op->num_orden_pago . ' — aplicación de anticipo',
                    'periodo'          => null,
                    'debe'             => 0.0,
                    'haber'            => self::aPesos($tope),
                    'baja_economico'   => true,
                    'baja_financiero'  => true,
                    'fecha_pactada'    => null,
                    'fecha_acreditada' => null,
                    'estado'           => 'APLICADO',
                ];
                continue;
            }

            $boletas = DB::table('tb_tes_pago')
                ->where('id_orden_pago', $op->id_orden_pago)
                ->where('id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
                ->get();

            foreach ($boletas as $b) {
                $abonos = DB::table('tb_tes_pago_parcial as pp')
                    ->leftJoin('tb_tes_fecha_probable_pago as fp', 'fp.id_fecha_probable', '=', 'pp.id_fecha_probable')
                    ->leftJoin('tb_tes_formas_pago as fo', 'fo.id_forma_pago', '=', 'pp.id_forma_pago')
                    ->leftJoin('tb_tes_estado_instrumento as ei', 'ei.id_estado_instrumento', '=', 'pp.id_estado_instrumento')
                    ->where('pp.id_pago', $b->id_pago)
                    ->where(fn($w) => $w->whereNull('pp.id_estado_instrumento')
                        ->orWhereNotIn('pp.id_estado_instrumento', [
                            TesInstrumentoPagoRepository::RECHAZADO,
                            TesInstrumentoPagoRepository::ANULADO,
                        ]))
                    ->select('pp.*', 'fp.fecha_probable_pago', 'fo.tipo_pago', 'ei.descripcion_estado')
                    ->orderBy('pp.id_pago_parcial')
                    ->get();

                if ($abonos->isEmpty()) {
                    // Pago viejo, anterior al detalle por abono: la boleta confirmada es el pago.
                    $confirmada = (int) $b->id_estado_orden_pago === TestOrdenPagoRepository::ESTADO_OPA_PAGADO
                        || !is_null($b->fecha_confirma_pago);

                    if (!$confirmada) {
                        continue;
                    }

                    $monto = $consumir(self::aCentavos($b->monto_pago ?? $b->monto_opa ?? 0));
                    if ($monto <= 0) {
                        continue;
                    }

                    $filas[] = [
                        'fecha'            => substr((string) ($b->fecha_confirma_pago ?? $b->fecha_registra), 0, 10),
                        'orden'            => 1,
                        'tipo'             => 'PAGO',
                        'comprobante'      => $op->num_orden_pago . ' — pago',
                        'periodo'          => null,
                        'debe'             => 0.0,
                        'haber'            => self::aPesos($monto),
                        'baja_economico'   => true,
                        'baja_financiero'  => true,
                        'fecha_pactada'    => null,
                        'fecha_acreditada' => substr((string) ($b->fecha_confirma_pago ?? ''), 0, 10) ?: null,
                        'estado'           => 'PAGADO',
                    ];
                    continue;
                }

                foreach ($abonos as $a) {

                    $esInstrumento = !is_null($a->id_estado_instrumento);
                    // Económico: el pago está imputado en cuanto entró en un pago confirmado (o,
                    // para los abonos viejos, en cuanto tiene fecha de cobro).
                    $imputado = !is_null($a->fecha_confirmado_en_pago) || !is_null($a->fecha_confirma_pago);
                    // Financiero: la plata salió del banco. Para un instrumento eso es acreditarlo.
                    $salio = !is_null($a->fecha_confirma_pago);

                    if (!$imputado) {
                        continue;   // borrador: todavía no se imputó a nada
                    }

                    $monto = $consumir(self::aCentavos($a->monto_pago));
                    if ($monto <= 0) {
                        continue;   // todo este pago cae en facturas de fuera de esta cuenta
                    }

                    $numero = trim((string) ($a->numero_echeq ?: $a->num_cheque));
                    $detalle = $op->num_orden_pago . ' — ' . ($a->tipo_pago ?: 'Pago')
                        . ($numero !== '' && !$a->numero_provisorio ? ' ' . $numero : '');

                    $filas[] = [
                        'fecha'            => substr((string) ($a->fecha_confirmado_en_pago ?? $a->fecha_confirma_pago ?? $a->fecha_registra), 0, 10),
                        'orden'            => 1,
                        'tipo'             => $esInstrumento ? 'INSTRUMENTO' : 'PAGO',
                        'comprobante'      => $detalle,
                        'periodo'          => null,
                        'debe'             => 0.0,
                        'haber'            => self::aPesos($monto),
                        'baja_economico'   => true,
                        'baja_financiero'  => $salio,
                        'fecha_pactada'    => $esInstrumento
                            ? substr((string) ($a->fecha_probable_pago ?? $a->fecha_emision_echeq ?? ''), 0, 10) ?: null
                            : null,
                        'fecha_acreditada' => $salio ? substr((string) $a->fecha_confirma_pago, 0, 10) : null,
                        'estado'           => $esInstrumento ? ($a->descripcion_estado ?: 'EMITIDO') : 'PAGADO',
                    ];
                }
            }
        }

        // Cronológico; a igual fecha, la factura antes que sus pagos.
        usort($filas, fn($x, $y) => [$x['fecha'], $x['orden']] <=> [$y['fecha'], $y['orden']]);

        $economico = 0;
        $financiero = 0;

        foreach ($filas as &$m) {
            $debe  = self::aCentavos($m['debe']);
            $haber = self::aCentavos($m['haber']);

            $economico  += $debe - ($m['baja_economico'] ? $haber : 0);
            $financiero += $debe - ($m['baja_financiero'] ? $haber : 0);

            $m['saldo_economico']  = self::aPesos($economico);
            $m['saldo_financiero'] = self::aPesos($financiero);
            // Compatibilidad: `saldo` era el saldo de la vista vieja, que era la financiera, y la
            // pantalla lee el texto en `detalle`.
            $m['saldo'] = $m['saldo_financiero'];
            $m['detalle'] = $m['comprobante'];
            unset($m['orden']);
        }
        unset($m);

        return $filas;
    }

    /** Cuenta corriente completa: resumen, movimientos y anticipos con saldo. */
    public function cuentaCorriente($idBeneficiario, string $tipo, ?string $desde = null, ?string $hasta = null): array
    {
        $movimientos = $this->movimientosDosSaldos($idBeneficiario, $tipo, $desde, $hasta);
        $resumen = $this->resumen($idBeneficiario, $tipo, $desde, $hasta);
        $ultimo = end($movimientos) ?: null;

        // Los dos saldos al cierre. El financiero coincide con `deuda_pendiente` (verificado contra
        // el FIFO en 766 beneficiarios de las dos bases); la diferencia con el económico es lo que
        // está en eCheq emitidos todavía no debitados.
        $resumen['saldo_economico']  = $ultimo['saldo_economico'] ?? 0.0;
        $resumen['saldo_financiero'] = $ultimo['saldo_financiero'] ?? 0.0;
        $resumen['en_echeq_emitidos'] = round($resumen['saldo_financiero'] - $resumen['saldo_economico'], 2);

        return [
            'resumen'     => $resumen,
            // Un renglón por pago, con saldo económico y financiero (ver movimientosDosSaldos).
            'movimientos' => $movimientos,
            'anticipos'   => $this->anticipos->anticiposConSaldo($idBeneficiario, $tipo),
        ];
    }
}
