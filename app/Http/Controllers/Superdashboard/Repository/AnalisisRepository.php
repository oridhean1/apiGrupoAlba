<?php

namespace App\Http\Controllers\Superdashboard\Repository;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Datos de Análisis 360 y Proyecciones del Superdashboard (R-00000601, copia del de OSPF):
 * series diarias de recaudación y gasto, percibido y devengado.
 *
 * - Recaudación percibida: lo mismo que Finanzas (ARCA por fecproc + subsidios).
 * - Recaudación devengada: DDJJ (aporte + contribución) por fecha de presentación; los
 *   subsidios se toman igual que en percibido.
 * - Gasto percibido: egresos de Tesorería por destino.
 * - Gasto devengado: facturas cargadas (neto) por rubro, según el tipo de factura.
 */
class AnalisisRepository
{
    private FinanzasRepository $finanzasRepository;

    public function __construct()
    {
        $this->finanzasRepository = new FinanzasRepository();
    }

    /** Rubro del gasto de una factura (alias f), con los mismos grupos que el destino del egreso. */
    private static function rubroFactura(): string
    {
        return "CASE
            WHEN f.id_proveedor IS NOT NULL THEN 'prov'
            WHEN f.id_tipo_factura IN (" . implode(',', FinanzasRepository::TIPOS_DISCAPACIDAD) . ") THEN 'dis'
            WHEN f.id_tipo_factura IN (" . implode(',', FinanzasRepository::TIPOS_FARMACIA) . ") THEN 'far'
            WHEN f.id_tipo_factura IN (" . implode(',', FinanzasRepository::TIPOS_ALTO_COSTO) . ") THEN 'alt'
            ELSE 'pre' END";
    }

    /**
     * Series diarias entre dos fechas, alineadas día por día (índice 0 = $desde):
     * ['rec' => ['perc' => [origen => [..]], 'dev' => [..]], 'gas' => ['perc' => [rubro => [..]], 'dev' => [..]]].
     */
    public function getSeriesDiarias(string $desde, string $hasta): array
    {
        return Cache::remember("superdashboard.series.$desde.$hasta", now()->addMinutes(30), function () use ($desde, $hasta) {
            $dias = [];
            for ($d = strtotime($desde); $d <= strtotime($hasta); $d += 86400) {
                $dias[date('Y-m-d', $d)] = count($dias);
            }
            $vacia = array_fill(0, count($dias), 0);
            $serie = fn (array $claves) => array_fill_keys($claves, $vacia);

            $rec = ['perc' => $serie(['arca', 'sss', 'sdis']), 'dev' => $serie(['arca', 'sss', 'sdis'])];
            $gas = ['perc' => $serie(['pre', 'dis', 'far', 'alt', 'prov', 'rei', 'otr']), 'dev' => $serie(['pre', 'dis', 'far', 'alt', 'prov', 'rei', 'otr'])];

            foreach ($this->finanzasRepository->getIngresosPorDia($desde, $hasta) as $fecha => $origenes) {
                foreach ($origenes as $origen => $v) {
                    if (isset($dias[$fecha])) {
                        $rec['perc'][$origen][$dias[$fecha]] = $v['monto'];
                        // Los subsidios no tienen un "declarado": devengado = percibido
                        if ($origen !== 'arca') {
                            $rec['dev'][$origen][$dias[$fecha]] = $v['monto'];
                        }
                    }
                }
            }

            foreach ($this->getDdjjPorDia($desde, $hasta) as $f) {
                if (isset($dias[$f->dia])) {
                    $rec['dev']['arca'][$dias[$f->dia]] = round((float) $f->monto, 2);
                }
            }

            foreach ($this->finanzasRepository->getEgresosPorDiaYDestino($desde, $hasta) as $f) {
                if (isset($dias[$f->dia], $gas['perc'][$f->destino])) {
                    $gas['perc'][$f->destino][$dias[$f->dia]] = round((float) $f->monto, 2);
                }
            }

            foreach ($this->getFacturasPorDiaYRubro($desde, $hasta) as $f) {
                if (isset($dias[$f->dia])) {
                    $gas['dev'][$f->rubro][$dias[$f->dia]] = round((float) $f->monto, 2);
                }
            }

            return ['desde' => $desde, 'hasta' => $hasta, 'rec' => $rec, 'gas' => $gas];
        });
    }

    /** DDJJ presentadas por día: aporte + contribución declarados. */
    private function getDdjjPorDia(string $desde, string $hasta)
    {
        return DB::table('tb_declaraciones_juradas')
            ->whereBetween('fecpresent', [$desde, $hasta])
            ->groupBy('fecpresent')
            ->get(['fecpresent as dia', DB::raw('SUM(IFNULL(apobsoc, 0) + IFNULL(conos, 0)) as monto')]);
    }

    /** Facturas cargadas (neto) por día de registración y rubro. */
    public function getFacturasPorDiaYRubro(string $desde, string $hasta)
    {
        $facturas = DB::table('tb_facturacion_datos as f')
            ->where('f.estado', '<>', FinanzasRepository::FACTURA_ANULADA)
            ->whereBetween('f.fecha_registra', [$desde, $hasta])
            ->select([DB::raw('DATE(f.fecha_registra) as dia'), DB::raw(self::rubroFactura() . ' as rubro'), 'f.total_neto']);

        return DB::query()->fromSub($facturas, 'x')
            ->groupBy('dia', 'rubro')
            ->get(['dia', 'rubro', DB::raw('SUM(total_neto) as monto'), DB::raw('COUNT(*) as cantidad')]);
    }

    /** Facturas cargadas en un día: cantidad y neto. */
    public function getFacturasDelDia(string $fecha): array
    {
        $t = DB::table('tb_facturacion_datos')
            ->where('estado', '<>', FinanzasRepository::FACTURA_ANULADA)
            ->where('fecha_registra', $fecha)
            ->selectRaw('COUNT(*) c, SUM(total_neto) m')
            ->first();

        return ['cantidad' => (int) $t->c, 'monto' => round((float) $t->m, 2)];
    }

    /**
     * Últimos lotes de ARCA (un lote por fecha de proceso): monto, transferencias y qué parte
     * corresponde a empleadores registrados en el sistema (imputable por CUIT).
     */
    public function getLotesArca(string $hasta, int $cantidad): array
    {
        return Cache::remember("superdashboard.lotes.$hasta.$cantidad", now()->addMinutes(10), function () use ($hasta, $cantidad) {
            // El cruce se hace en memoria: un JOIN de tb_transferencias contra los CUIT normalizados
            // de tb_empresa no usa índice y tarda minutos.
            $empresas = DB::table('tb_empresa')
                ->whereNotNull('cuit')
                ->pluck(DB::raw("REPLACE(cuit, '-', '')"))
                ->flip();

            $porEmpleador = DB::table('tb_transferencias')
                ->where('inddbcr', 'C')
                ->whereNotIn('codconc', FinanzasRepository::CONCEPTOS_EXCLUIDOS)
                ->whereBetween('fecproc', [date('Y-m-d', strtotime($hasta . ' -40 days')), $hasta])
                ->groupBy('fecproc', 'cuitcont')
                ->get(['fecproc', 'cuitcont', DB::raw('SUM(importe) as monto'), DB::raw('COUNT(*) as cantidad')]);

            $lotes = [];
            foreach ($porEmpleador as $f) {
                $l = $lotes[$f->fecproc] ?? ['fecha' => $f->fecproc, 'monto' => 0, 'transferencias' => 0, 'imputado' => 0, 'sin_empresa' => 0];
                $l['monto'] += (float) $f->monto;
                $l['transferencias'] += (int) $f->cantidad;
                if (isset($empresas[$f->cuitcont])) {
                    $l['imputado'] += (float) $f->monto;
                } else {
                    $l['sin_empresa'] += (int) $f->cantidad;
                }
                $lotes[$f->fecproc] = $l;
            }
            ksort($lotes);

            return array_map(function ($l) {
                $l['monto'] = round($l['monto'], 2);
                $l['imputado'] = round($l['imputado'], 2);
                return $l;
            }, array_values(array_slice($lotes, -$cantidad)));
        });
    }
}
