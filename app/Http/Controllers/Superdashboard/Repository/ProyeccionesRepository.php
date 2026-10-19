<?php

namespace App\Http\Controllers\Superdashboard\Repository;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Base histórica y valores sugeridos de Proyecciones del Superdashboard (R-00000601, copia del
 * de OSPF). Los simuladores calculan en pantalla; acá solo se entrega lo que ya ocurrió.
 */
class ProyeccionesRepository
{
    private FinanzasRepository $finanzasRepository;
    private AnalisisRepository $analisisRepository;
    private ActividadRepository $actividadRepository;

    public function __construct()
    {
        $this->finanzasRepository = new FinanzasRepository();
        $this->analisisRepository = new AnalisisRepository();
        $this->actividadRepository = new ActividadRepository();
    }

    /**
     * Recaudación percibida (ARCA, subsidios, cobranzas de fiscalización) y gasto devengado por
     * rubro, mes a mes. [['mes' => 'YYYY-MM', 'arca' =>, 'sss' =>, 'rec' =>, 'gasto' => [rubro => ..]]]
     */
    public function getHistoriaMensual(string $desde, string $hasta): array
    {
        return Cache::remember("superdashboard.historia.$desde.$hasta", now()->addMinutes(30), function () use ($desde, $hasta) {
            $meses = [];
            for ($m = strtotime($desde); $m <= strtotime($hasta); $m = strtotime('+1 month', $m)) {
                $meses[date('Y-m', $m)] = ['mes' => date('Y-m', $m), 'arca' => 0, 'sss' => 0, 'rec' => 0,
                    'gasto' => ['pre' => 0, 'dis' => 0, 'far' => 0, 'alt' => 0, 'prov' => 0]];
            }

            foreach ($this->finanzasRepository->getIngresosPorDia($desde, $hasta) as $fecha => $origenes) {
                $k = substr($fecha, 0, 7);
                if (!isset($meses[$k])) {
                    continue;
                }
                foreach ($origenes as $origen => $v) {
                    $meses[$k][$origen === 'arca' ? 'arca' : 'sss'] += $v['monto'];
                }
            }

            $cobranzas = DB::table('tb_fisca_cobranzas')
                ->whereBetween('fecha_pago', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
                ->get(['fecha_pago', 'cobro_total']);
            foreach ($cobranzas as $c) {
                $k = substr($c->fecha_pago, 0, 7);
                if (isset($meses[$k])) {
                    $meses[$k]['rec'] += (float) $c->cobro_total;
                }
            }

            foreach ($this->analisisRepository->getFacturasPorDiaYRubro($desde, $hasta) as $f) {
                $k = substr($f->dia, 0, 7);
                if (isset($meses[$k])) {
                    $meses[$k]['gasto'][$f->rubro] += (float) $f->monto;
                }
            }

            return array_values(array_map(function ($m) {
                foreach (['arca', 'sss', 'rec'] as $c) {
                    $m[$c] = round($m[$c], 2);
                }
                $m['gasto'] = array_map(fn ($v) => round($v, 2), $m['gasto']);
                return $m;
            }, $meses));
        });
    }

    /** Afiliados activos (tb_padron.activo, lo mantiene el evento desactivar_padron_por_fecha) y cuántos tienen 60 años o más. */
    public function getPadron(string $hoy): array
    {
        $t = DB::table('tb_padron as p')
            ->where('p.activo', 1)
            ->selectRaw('COUNT(DISTINCT p.dni) activos, COUNT(DISTINCT IF(p.fe_nac <= DATE_SUB(?, INTERVAL 60 YEAR), p.dni, NULL)) mayores', [$hoy])
            ->first();

        return ['activos' => (int) $t->activos, 'mayores_60' => (int) $t->mayores];
    }

    /**
     * Morosidad de los últimos períodos cerrados: (declarado en DDJJ − transferido para ese
     * período) / declarado. Períodos en formato AAMM.
     */
    public function getMorosidad(array $periodos): array
    {
        return Cache::remember('superdashboard.morosidad.' . implode('-', $periodos), now()->addHours(6), function () use ($periodos) {
            $declarado = DB::table('tb_declaraciones_juradas')
                ->whereIn('periodo', $periodos)
                ->groupBy('periodo')
                ->get(['periodo', DB::raw('SUM(IFNULL(apobsoc, 0) + IFNULL(conos, 0)) as monto')])
                ->keyBy('periodo');

            $transferido = DB::table('tb_transferencias')
                ->where('inddbcr', 'C')
                ->whereNotIn('codconc', FinanzasRepository::CONCEPTOS_EXCLUIDOS)
                ->whereIn('periodo', $periodos)
                ->groupBy('periodo')
                ->get(['periodo', DB::raw('SUM(importe) as monto')])
                ->keyBy('periodo');

            return collect($periodos)->map(function ($p) use ($declarado, $transferido) {
                $d = (float) ($declarado[$p]->monto ?? 0);
                $t = (float) ($transferido[$p]->monto ?? 0);
                return [
                    'periodo' => $p,
                    'declarado' => round($d, 2),
                    'transferido' => round($t, 2),
                    'morosidad' => $d > 0 ? round(max(0, ($d - $t) / $d * 100), 1) : null,
                ];
            })->all();
        });
    }

    /** Altas y bajas de padrón por mes: [YYYY-MM => ['altas' =>, 'bajas' =>]]. */
    public function getAltasBajasMensuales(string $desde, string $hasta): array
    {
        $meses = [];
        foreach ($this->actividadRepository->getAltasBajasPorDia($desde, $hasta) as $fecha => $v) {
            $k = substr($fecha, 0, 7);
            $meses[$k]['altas'] = ($meses[$k]['altas'] ?? 0) + $v['altas'];
            $meses[$k]['bajas'] = ($meses[$k]['bajas'] ?? 0) + $v['bajas'];
        }
        ksort($meses);

        return $meses;
    }
}
