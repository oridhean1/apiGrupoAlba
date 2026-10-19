<?php

namespace App\Http\Controllers\Superdashboard\Repository;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Datos de la pantalla Finanzas del Superdashboard (R-00000601, copia del de OSPF R-00000578).
 *
 * Mismos criterios que OSPF, adaptados al esquema de Alba:
 * - Ingresos = transferencias ARCA (créditos por fecproc) + subsidios SSS (SANO, SUMA, SUMARTE,
 *   SUMA70, por fecha_proceso) + subsidios de discapacidad (por fecha_registra).
 * - Egresos = movimientos de EGRESO de las cuentas bancarias de Tesorería.
 * - Deuda con prestadores = saldo por factura (neto - débitos - imputado en OPAs con pago
 *   confirmado). Se calcula acá: el SaldosRepository de Alba usa columnas que no existen.
 * - Caja: sin fuente confiable del saldo bancario.
 * - Alba no tiene cheques, notas internas, retenciones en el pago ni OPAs de reintegro.
 */
class FinanzasRepository
{
    /** Conceptos de ARCA que no son recaudación (mismo filtro que OSPF). */
    public const CONCEPTOS_EXCLUIDOS = ['C02', 'C03', 'D01', 'D18'];

    /** tb_tes_pago.id_estado_orden_pago (tb_tes_estado_orden_pago): pagado, pago parcial */
    private const PAGO_CONFIRMADO = [5, 6];
    /** rechazado */
    private const PAGO_NO_VIGENTE = [3];

    /** tb_tes_estado_orden_pago: rechazado, pagado, consumida */
    private const OPA_CERRADA = [3, 5, 7];
    /** rechazado */
    private const OPA_NO_VIGENTE = [3];

    /** tb_facturacion_datos.estado: anulado */
    public const FACTURA_ANULADA = 4;
    /** tb_facturacion_datos.estado: cerrada, valorización final (1 y 5 = en auditoría) */
    public const FACTURA_AUDITADA = [2, 3];

    /** tb_facturacion_tipo agrupados para el destino del egreso y el rubro del gasto */
    public const TIPOS_DISCAPACIDAD = [2, 25];
    public const TIPOS_FARMACIA = [8, 26, 35];
    public const TIPOS_ALTO_COSTO = [7, 9, 13];

    /** Minutos de cache de lo que sale de tb_transferencias (sin índice por fecproc). */
    private const CACHE_ARCA_MIN = 10;

    // ================================================================== ingresos

    /**
     * Ingresos por día y origen entre dos fechas: [fecha => [origen => [monto, cantidad]]].
     */
    public function getIngresosPorDia(string $desde, string $hasta): array
    {
        $filas = collect()
            ->concat($this->ingresosArca($desde, $hasta))
            ->concat($this->ingresosSubsidiosSss($desde, $hasta))
            ->concat($this->ingresosSubsidiosDiscapacidad($desde, $hasta));

        $dias = [];
        foreach ($filas as $f) {
            $dias[$f->fecha][$f->origen] = [
                'monto' => round((float) $f->monto, 2),
                'cantidad' => (int) $f->cantidad,
            ];
        }

        return $dias;
    }

    private function ingresosArca(string $desde, string $hasta)
    {
        return Cache::remember(
            "superdashboard.arca.$desde.$hasta",
            now()->addMinutes(self::CACHE_ARCA_MIN),
            fn () => DB::table('tb_transferencias')
                ->where('inddbcr', 'C')
                ->whereNotIn('codconc', self::CONCEPTOS_EXCLUIDOS)
                ->whereBetween('fecproc', [$desde, $hasta])
                ->groupBy('fecproc')
                ->get([
                    'fecproc as fecha',
                    DB::raw("'arca' as origen"),
                    DB::raw('SUM(importe) as monto'),
                    DB::raw('COUNT(*) as cantidad'),
                ])
        );
    }

    private function ingresosSubsidiosSss(string $desde, string $hasta)
    {
        $programas = [
            'tb_subsidio_sano' => 'subsidio',
            'tb_subsidio_suma' => 'subsidio_total',
            'tb_subsidio_sumarte' => 'subsidio_total',
            'tb_subsidio_suma70' => 'subsidio_total',
        ];

        $union = null;
        foreach ($programas as $tabla => $columna) {
            $q = DB::table($tabla)
                ->whereBetween(DB::raw('DATE(fecha_proceso)'), [$desde, $hasta])
                ->select([DB::raw('DATE(fecha_proceso) as fecha'), DB::raw("$columna as monto")]);
            $union = $union ? $union->unionAll($q) : $q;
        }

        return DB::query()->fromSub($union, 's')
            ->groupBy('fecha')
            ->get([
                'fecha',
                DB::raw("'sss' as origen"),
                DB::raw('SUM(monto) as monto'),
                DB::raw('COUNT(*) as cantidad'),
            ]);
    }

    private function ingresosSubsidiosDiscapacidad(string $desde, string $hasta)
    {
        return DB::table('tb_subsidios_discapacidad')
            ->whereBetween('fecha_registra', [$desde, $hasta])
            ->groupBy('fecha_registra')
            ->get([
                'fecha_registra as fecha',
                DB::raw("'sdis' as origen"),
                DB::raw('SUM(importe_subsidiado) as monto'),
                DB::raw('COUNT(*) as cantidad'),
            ]);
    }

    // ================================================================== egresos

    /**
     * Saca los pases entre cuentas propias: un egreso cuya operación tiene también un ingreso
     * ("OPER. MANUAL - TRANSFERENCIA") no es gasto, es plata que cambia de cuenta.
     */
    public static function sinPasesEntreCuentas($q, string $alias)
    {
        return $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))
            ->from('tb_tes_movimiento_cuenta_bancaria as espejo')
            ->where('espejo.tipo_movimiento', 'INGRESO')
            ->whereNotNull("$alias.id_operacion")
            ->whereColumn('espejo.id_operacion', "$alias.id_operacion"));
    }

    /** Egresos por día: [fecha => [monto, cantidad]]. */
    public function getEgresosPorDia(string $desde, string $hasta): array
    {
        $q = DB::table('tb_tes_movimiento_cuenta_bancaria as m')
            ->where('m.tipo_movimiento', 'EGRESO')
            ->whereBetween('m.fecha_movimiento', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);

        return self::sinPasesEntreCuentas($q, 'm')
            ->groupBy(DB::raw('DATE(m.fecha_movimiento)'))
            ->get([
                DB::raw('DATE(m.fecha_movimiento) as fecha'),
                DB::raw('SUM(m.monto) as monto'),
                DB::raw('COUNT(*) as cantidad'),
            ])
            ->mapWithKeys(fn ($f) => [$f->fecha => [
                'monto' => round((float) $f->monto, 2),
                'cantidad' => (int) $f->cantidad,
            ]])
            ->all();
    }

    /** Egresos agrupados por destino entre dos fechas: [destino => monto]. */
    public function getEgresosPorDestino(string $desde, string $hasta): array
    {
        return DB::query()->fromSub($this->queryEgresos($desde, $hasta), 'e')
            ->groupBy('destino')
            ->get(['destino', DB::raw('SUM(monto) as monto')])
            ->mapWithKeys(fn ($f) => [$f->destino => round((float) $f->monto, 2)])
            ->all();
    }

    /** Egresos por día y destino: filas (dia, destino, monto, cantidad). */
    public function getEgresosPorDiaYDestino(string $desde, string $hasta)
    {
        $porMovimiento = DB::query()->fromSub($this->queryEgresos($desde, $hasta), 'e')
            ->select([DB::raw('DATE(e.fecha) as dia'), 'e.destino', 'e.monto']);

        return DB::query()->fromSub($porMovimiento, 'x')
            ->groupBy('dia', 'destino')
            ->get(['dia', 'destino', DB::raw('SUM(monto) as monto'), DB::raw('COUNT(*) as cantidad')]);
    }

    /** Últimos egresos con su beneficiario, para el feed de movimientos. */
    public function getUltimosEgresos(string $hasta, int $limite): array
    {
        return DB::query()->fromSub($this->queryEgresos(null, $hasta), 'e')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->all();
    }

    /**
     * Una fila por movimiento de egreso con su destino (prestador, discapacidad, farmacia,
     * alto costo, proveedor u otro) deducido de la OPA y sus facturas.
     */
    private function queryEgresos(?string $desde, string $hasta)
    {
        $tipoOpa = DB::table('tb_tes_opa_factura as of')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'of.id_factura')
            ->groupBy('of.id_orden_pago')
            ->select(['of.id_orden_pago', DB::raw('MIN(f.id_tipo_factura) as id_tipo_factura')]);

        $dis = implode(',', self::TIPOS_DISCAPACIDAD);
        $far = implode(',', self::TIPOS_FARMACIA);
        $alt = implode(',', self::TIPOS_ALTO_COSTO);

        return DB::table('tb_tes_movimiento_cuenta_bancaria as m')
            ->leftJoin('tb_tes_pago as p', 'p.id_pago', '=', 'm.id_pago')
            ->leftJoin('tb_tes_orden_pago as opa', 'opa.id_orden_pago', '=', 'p.id_orden_pago')
            ->leftJoinSub($tipoOpa, 't', 't.id_orden_pago', '=', 'opa.id_orden_pago')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'opa.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'opa.id_proveedor')
            ->where('m.tipo_movimiento', 'EGRESO')
            ->where(fn ($q) => self::sinPasesEntreCuentas($q, 'm'))
            ->when($desde, fn ($q) => $q->where('m.fecha_movimiento', '>=', $desde . ' 00:00:00'))
            ->where('m.fecha_movimiento', '<=', $hasta . ' 23:59:59')
            ->select([
                'm.id_movimiento as id',
                'm.fecha_movimiento as fecha',
                'm.monto',
                'm.descripcion',
                'opa.id_orden_pago',
                DB::raw("CASE
                    WHEN opa.id_proveedor IS NOT NULL THEN 'prov'
                    WHEN opa.id_prestador IS NOT NULL AND t.id_tipo_factura IN ($dis) THEN 'dis'
                    WHEN opa.id_prestador IS NOT NULL AND t.id_tipo_factura IN ($far) THEN 'far'
                    WHEN opa.id_prestador IS NOT NULL AND t.id_tipo_factura IN ($alt) THEN 'alt'
                    WHEN opa.id_prestador IS NOT NULL THEN 'pre'
                    ELSE 'otr' END as destino"),
                DB::raw("COALESCE(pr.razon_social, pv.razon_social, m.descripcion, 'Egreso') as beneficiario"),
            ]);
    }

    // ================================================================== deuda y circuito

    /**
     * Una fila por factura auditada con su saldo:
     *   saldo = total_neto - total_debitado_liquidacion - imputado en OPAs vigentes con pago confirmado
     */
    private function queryFacturasConSaldo()
    {
        $opasPagadas = DB::table('tb_tes_pago')
            ->whereIn('id_estado_orden_pago', self::PAGO_CONFIRMADO)
            ->groupBy('id_orden_pago')
            ->select('id_orden_pago');

        $pagado = DB::table('tb_tes_opa_factura as of')
            ->join('tb_tes_orden_pago as opa', 'opa.id_orden_pago', '=', 'of.id_orden_pago')
            ->joinSub($opasPagadas, 'op', 'op.id_orden_pago', '=', 'of.id_orden_pago')
            ->whereNotIn('opa.id_estado_orden_pago', self::OPA_NO_VIGENTE)
            ->groupBy('of.id_factura')
            ->select(['of.id_factura', DB::raw('SUM(of.monto_aplicado) as pagado')]);

        return DB::table('tb_facturacion_datos as f')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'f.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'f.id_proveedor')
            ->leftJoinSub($pagado, 'pg', 'pg.id_factura', '=', 'f.id_factura')
            ->whereIn('f.estado', self::FACTURA_AUDITADA)
            ->where(fn ($w) => $w->whereNotNull('f.id_proveedor')->orWhereNotNull('f.id_prestador'))
            ->select([
                'f.id_factura',
                'f.fecha_registra',
                DB::raw("IF(f.id_proveedor IS NOT NULL, 'PROVEEDOR', 'PRESTADOR') as tipo"),
                DB::raw('IF(f.id_proveedor IS NOT NULL, f.id_proveedor, f.id_prestador) as id'),
                DB::raw('IF(f.id_proveedor IS NOT NULL, pv.razon_social, pr.razon_social) as razon_social'),
                DB::raw('ROUND(f.total_neto - IFNULL(f.total_debitado_liquidacion, 0) - IFNULL(pg.pagado, 0), 2) as saldo'),
            ]);
    }

    public function getDeudaPrestadores(): float
    {
        return Cache::remember('superdashboard.deuda', now()->addMinutes(5),
            fn () => round((float) DB::query()->fromSub($this->queryFacturasConSaldo(), 'fx')->sum('fx.saldo'), 2));
    }

    /**
     * Antigüedad de la deuda (días desde la registración de la factura) y los 5 acreedores
     * con más saldo, con los días de su factura impaga más vieja.
     */
    public function getAntiguedadSaldos(string $hoy): array
    {
        return Cache::remember("superdashboard.antiguedad.$hoy", now()->addMinutes(5), function () use ($hoy) {
            $base = DB::query()->fromSub($this->queryFacturasConSaldo(), 'fx')
                ->where('fx.saldo', '>', 0.01)
                ->select(['fx.tipo', 'fx.id', 'fx.razon_social', 'fx.saldo',
                    DB::raw("GREATEST(0, DATEDIFF('$hoy', fx.fecha_registra)) as dias")]);

            $tramos = DB::query()->fromSub($base, 'b')
                ->selectRaw('SUM(IF(dias <= 30, saldo, 0)) t30,
                    SUM(IF(dias BETWEEN 31 AND 60, saldo, 0)) t60,
                    SUM(IF(dias BETWEEN 61 AND 90, saldo, 0)) t90,
                    SUM(IF(dias > 90, saldo, 0)) tmas')
                ->first();

            $top = DB::query()->fromSub($base, 'b')
                ->groupBy('tipo', 'id')
                ->orderByDesc(DB::raw('SUM(saldo)'))
                ->limit(5)
                ->get([DB::raw('MAX(razon_social) as nombre'), DB::raw('SUM(saldo) as monto'), DB::raw('MAX(dias) as dias')]);

            return [
                'antiguedad' => [
                    ['tramo' => '0–30 días', 'monto' => round((float) $tramos->t30, 2)],
                    ['tramo' => '31–60 días', 'monto' => round((float) $tramos->t60, 2)],
                    ['tramo' => '61–90 días', 'monto' => round((float) $tramos->t90, 2)],
                    ['tramo' => '+90 días', 'monto' => round((float) $tramos->tmas, 2)],
                ],
                'top_deudas' => $top->map(fn ($t) => [
                    'nombre' => $t->nombre ?: 'Sin razón social',
                    'monto' => round((float) $t->monto, 2),
                    'dias' => (int) $t->dias,
                ])->all(),
            ];
        });
    }

    /**
     * Circuito de pago de las facturas registradas en el período: cada etapa cuenta las
     * facturas que llegaron al menos hasta ahí (recibidas ⊇ auditadas ⊇ con OPA ⊇ pago ⊇ confirmado).
     */
    public function getCircuitoPago(string $desde, string $hasta): array
    {
        $confirmado = implode(',', self::PAGO_CONFIRMADO);
        $auditada = implode(',', self::FACTURA_AUDITADA);

        $opas = DB::table('tb_tes_opa_factura as of')
            ->join('tb_tes_orden_pago as opa', 'opa.id_orden_pago', '=', 'of.id_orden_pago')
            ->leftJoin('tb_tes_pago as p', fn ($j) => $j->on('p.id_orden_pago', '=', 'opa.id_orden_pago')
                ->whereNotIn('p.id_estado_orden_pago', self::PAGO_NO_VIGENTE))
            ->whereNotIn('opa.id_estado_orden_pago', self::OPA_NO_VIGENTE)
            ->groupBy('of.id_factura')
            ->select([
                'of.id_factura',
                DB::raw('MAX(p.id_pago IS NOT NULL) as con_pago'),
                DB::raw("MAX(p.id_estado_orden_pago IN ($confirmado)) as confirmado"),
            ]);

        $t = DB::table('tb_facturacion_datos as f')
            ->leftJoinSub($opas, 'o', 'o.id_factura', '=', 'f.id_factura')
            ->where('f.estado', '<>', self::FACTURA_ANULADA)
            ->whereBetween('f.fecha_registra', [$desde, $hasta])
            ->selectRaw("COUNT(*) c0, SUM(f.total_neto) m0,
                SUM(f.estado IN ($auditada)) c1, SUM(IF(f.estado IN ($auditada), f.total_neto, 0)) m1,
                SUM(o.id_factura IS NOT NULL) c2, SUM(IF(o.id_factura IS NOT NULL, f.total_neto, 0)) m2,
                SUM(IFNULL(o.con_pago, 0)) c3, SUM(IF(o.con_pago = 1, f.total_neto, 0)) m3,
                SUM(IFNULL(o.confirmado, 0)) c4, SUM(IF(o.confirmado = 1, f.total_neto, 0)) m4,
                SUM(IFNULL(f.total_debitado_liquidacion, 0)) debitos")
            ->first();

        $etapas = ['Recibidos', 'Auditados', 'OPA generada', 'Pago generado', 'Confirmado'];

        return [
            'etapas' => collect($etapas)->map(fn ($etapa, $i) => [
                'etapa' => $etapa,
                'cantidad' => (int) ($t->{"c$i"} ?? 0),
                'monto' => round((float) ($t->{"m$i"} ?? 0), 2),
            ])->all(),
            'debitos' => round((float) ($t->debitos ?? 0), 2),
        ];
    }

    /** OPAs vigentes que todavía no tienen un pago confirmado. */
    public function getOpasSinConfirmar(): array
    {
        $confirmadas = DB::table('tb_tes_pago')
            ->whereIn('id_estado_orden_pago', self::PAGO_CONFIRMADO)
            ->select('id_orden_pago');

        $t = DB::table('tb_tes_orden_pago')
            ->whereNotIn('id_estado_orden_pago', self::OPA_CERRADA)
            ->whereNotIn('id_orden_pago', $confirmadas)
            ->selectRaw('COUNT(*) cantidad, SUM(monto_orden_pago) monto')
            ->first();

        return ['cantidad' => (int) $t->cantidad, 'monto' => round((float) $t->monto, 2)];
    }

    /**
     * Días promedio factura → pago de los pagos con egreso en el período. Alba no registra
     * retenciones en el pago: van en 0.
     */
    public function getIndicadoresPago(string $desde, string $hasta): array
    {
        $pagos = DB::table('tb_tes_movimiento_cuenta_bancaria as m')
            ->join('tb_tes_pago as p', 'p.id_pago', '=', 'm.id_pago')
            ->where('m.tipo_movimiento', 'EGRESO')
            ->whereBetween('m.fecha_movimiento', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->groupBy('p.id_pago', 'p.id_orden_pago')
            ->select(['p.id_pago', 'p.id_orden_pago', DB::raw('MIN(m.fecha_movimiento) as fecha_pago')]);

        $dias = DB::query()->fromSub($pagos, 'x')
            ->join('tb_tes_opa_factura as of', 'of.id_orden_pago', '=', 'x.id_orden_pago')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'of.id_factura')
            ->avg(DB::raw('DATEDIFF(x.fecha_pago, f.fecha_registra)'));

        return [
            'retenciones' => 0,
            'dias_recepcion_pago' => $dias === null ? null : (int) round((float) $dias),
        ];
    }

    /**
     * Pagos programados por día (OPAs vigentes sin pagar, por vencimiento), con los 4
     * beneficiarios principales de cada día. Alba no tiene cheques en Tesorería.
     */
    public function getVencimientos(string $desde, string $hasta): array
    {
        $confirmadas = DB::table('tb_tes_pago')
            ->whereIn('id_estado_orden_pago', self::PAGO_CONFIRMADO)
            ->select('id_orden_pago');

        $filas = DB::table('tb_tes_orden_pago as opa')
            ->leftJoin('tb_prestador as pr', 'pr.cod_prestador', '=', 'opa.id_prestador')
            ->leftJoin('tb_proveedor as pv', 'pv.cod_proveedor', '=', 'opa.id_proveedor')
            ->whereNotIn('opa.id_estado_orden_pago', self::OPA_CERRADA)
            ->whereNotIn('opa.id_orden_pago', $confirmadas)
            ->whereBetween('opa.fecha_vencimiento', [$desde, $hasta])
            ->get([
                DB::raw('DATE(opa.fecha_vencimiento) as fecha'),
                DB::raw("COALESCE(pr.razon_social, pv.razon_social, CONCAT('OP ', opa.id_orden_pago)) as nombre"),
                DB::raw("IF(opa.id_proveedor IS NOT NULL, 'Proveedor', 'OP prestador') as tipo"),
                DB::raw('opa.monto_orden_pago - IFNULL(opa.monto_anticipado, 0) as monto'),
            ]);

        return $filas->groupBy('fecha')->map(function ($items, $fecha) {
            return [
                'fecha' => $fecha,
                'monto' => round((float) $items->sum('monto'), 2),
                'items' => $items->sortByDesc('monto')->take(4)->map(fn ($i) => [
                    'nombre' => $i->nombre,
                    'tipo' => $i->tipo,
                    'monto' => round((float) $i->monto, 2),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** Fecha de hoy en la zona del sistema (YYYY-MM-DD). */
    public static function hoy(?string $fecha): Carbon
    {
        return $fecha ? Carbon::parse($fecha)->startOfDay() : Carbon::today();
    }
}
