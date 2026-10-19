<?php

namespace App\Http\Controllers\Superdashboard\Services;

use App\Http\Controllers\Superdashboard\Repository\ActividadRepository;
use App\Http\Controllers\Superdashboard\Repository\AnalisisRepository;
use App\Http\Controllers\Superdashboard\Repository\FinanzasRepository;
use App\Http\Controllers\Superdashboard\Repository\ProyeccionesRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Superdashboard para directivos (R-00000601, copia del de OSPF R-00000578). Un endpoint por
 * pantalla con todo lo que la pantalla necesita; el front lo vuelve a pedir cada 30 s (polling,
 * sin websocket). Montos en pesos con 2 decimales y fechas ISO: el front formatea.
 */
class SuperdashboardController extends Controller
{
    // Perfiles (tb_perfiles.cod_perfil) que ven el Superdashboard: solo Administrador
    private const PERFILES_SUPERDASHBOARD = [2];

    /** Segundos que se reutiliza una respuesta entre varios usuarios mirando la misma pantalla. */
    private const CACHE_SEG = 30;

    private $finanzasRepository;
    private $actividadRepository;
    private $analisisRepository;
    private $proyeccionesRepository;

    public function __construct()
    {
        $this->finanzasRepository = new FinanzasRepository();
        $this->actividadRepository = new ActividadRepository();
        $this->analisisRepository = new AnalisisRepository();
        $this->proyeccionesRepository = new ProyeccionesRepository();
    }

    public function getFinanzas(Request $request)
    {
        if (!$this->puedeVer()) {
            return $this->sinPermiso();
        }

        try {
            $request->validate(['fecha' => 'nullable|date']);
            $hoy = FinanzasRepository::hoy($request->fecha);

            $data = Cache::remember('superdashboard.finanzas.' . $hoy->toDateString(), self::CACHE_SEG,
                fn () => $this->armarFinanzas($hoy));

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Finanzas obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las finanzas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getActividad(Request $request)
    {
        if (!$this->puedeVer()) {
            return $this->sinPermiso();
        }

        try {
            $request->validate([
                'fecha' => 'nullable|date',
                'periodo' => 'nullable|in:hoy,semana,mes',
            ]);
            $hoy = FinanzasRepository::hoy($request->fecha);
            $periodo = $request->periodo ?: 'hoy';

            $data = Cache::remember("superdashboard.actividad.$periodo." . $hoy->toDateString(), self::CACHE_SEG,
                fn () => $this->armarActividad($hoy, $periodo));

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Actividad obtenida exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la actividad: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAnalisis(Request $request)
    {
        return $this->responder($request, 'análisis 360', 'Análisis obtenido exitosamente', function (Carbon $hoy) {
            return Cache::remember('superdashboard.analisis.' . $hoy->toDateString(), self::CACHE_SEG,
                fn () => $this->armarAnalisis($hoy));
        });
    }

    public function getProyecciones(Request $request)
    {
        return $this->responder($request, 'proyecciones', 'Proyecciones obtenidas exitosamente', function (Carbon $hoy) {
            return Cache::remember('superdashboard.proyecciones.' . $hoy->toDateString(), self::CACHE_SEG,
                fn () => $this->armarProyecciones($hoy));
        });
    }

    /** Permisos, validación de la fecha y respuesta estándar de las pantallas. */
    private function responder(Request $request, string $pantalla, string $mensaje, callable $armar)
    {
        if (!$this->puedeVer()) {
            return $this->sinPermiso();
        }

        try {
            $request->validate(['fecha' => 'nullable|date']);
            $hoy = FinanzasRepository::hoy($request->fecha);

            return response()->json([
                'success' => true,
                'data' => $armar($hoy, $request),
                'message' => $mensaje
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Error al obtener $pantalla: " . $e->getMessage()
            ], 500);
        }
    }

    private function armarAnalisis(Carbon $hoy): array
    {
        $h = $hoy->toDateString();
        $series = $this->analisisRepository->getSeriesDiarias($hoy->copy()->subDays(729)->toDateString(), $h);
        $ingresosHoy = $this->finanzasRepository->getIngresosPorDia($h, $h)[$h] ?? [];
        $egresosHoy = $this->finanzasRepository->getEgresosPorDia($h, $h)[$h] ?? ['monto' => 0, 'cantidad' => 0];

        return [
            'fecha' => $h,
            'generado' => now()->toIso8601String(),
            'series' => $series,
            'hoy' => [
                'arca' => $ingresosHoy['arca'] ?? ['monto' => 0, 'cantidad' => 0],
                'facturas' => $this->analisisRepository->getFacturasDelDia($h),
                'pagos' => $egresosHoy,
            ],
            'lotes_arca' => $this->analisisRepository->getLotesArca($h, 15),
        ];
    }

    private function armarProyecciones(Carbon $hoy): array
    {
        $inicioMes = $hoy->copy()->startOfMonth();
        $desde = $inicioMes->copy()->subMonths(12)->toDateString();
        $hasta = $inicioMes->copy()->subDay()->toDateString();
        // Períodos de DDJJ ya cerrados: 2, 3 y 4 meses atrás (los aportes se transfieren el mes siguiente)
        $periodos = collect([2, 3, 4])->map(fn ($n) => $inicioMes->copy()->subMonths($n)->format('ym'))->all();

        return [
            'fecha' => $hoy->toDateString(),
            'generado' => now()->toIso8601String(),
            'historia' => $this->proyeccionesRepository->getHistoriaMensual($desde, $hasta),
            'padron' => $this->proyeccionesRepository->getPadron($hoy->toDateString()),
            'altas_bajas' => $this->proyeccionesRepository->getAltasBajasMensuales($inicioMes->copy()->subMonths(3)->toDateString(), $hasta),
            'morosidad' => $this->proyeccionesRepository->getMorosidad($periodos),
        ];
    }

    private function armarFinanzas(Carbon $hoy): array
    {
        $repo = $this->finanzasRepository;
        $h = $hoy->toDateString();
        $inicioMes = $hoy->copy()->startOfMonth()->toDateString();
        $desde30 = $hoy->copy()->subDays(29)->toDateString();
        $desde = min($desde30, $inicioMes);

        $ingresos = $repo->getIngresosPorDia($desde, $h);
        $egresos = $repo->getEgresosPorDia($desde, $h);

        $sumaDia = fn (array $origenes, string $campo) => array_sum(array_column($origenes, $campo));

        $dias = [];
        for ($d = $hoy->copy()->subDays(29); $d->lte($hoy); $d->addDay()) {
            $f = $d->toDateString();
            $dias[] = [
                'fecha' => $f,
                'ingresos' => round($sumaDia($ingresos[$f] ?? [], 'monto'), 2),
                'egresos' => $egresos[$f]['monto'] ?? 0,
            ];
        }

        $entradasMes = ['arca' => 0, 'sss' => 0, 'sdis' => 0];
        foreach ($ingresos as $fecha => $origenes) {
            if ($fecha >= $inicioMes) {
                foreach ($origenes as $origen => $v) {
                    $entradasMes[$origen] += $v['monto'];
                }
            }
        }
        $salidasMes = $repo->getEgresosPorDestino($inicioMes, $h);

        $circuito = $repo->getCircuitoPago($inicioMes, $h);
        $indicadores = $repo->getIndicadoresPago($inicioMes, $h);
        $saldos = $repo->getAntiguedadSaldos($h);

        return [
            'fecha' => $h,
            'generado' => now()->toIso8601String(),
            // Sin fuente confiable del saldo bancario (igual que en OSPF): el front muestra caja de ejemplo.
            'caja' => null,
            'hoy' => [
                'ingresos' => round($sumaDia($ingresos[$h] ?? [], 'monto'), 2),
                'cant_ingresos' => (int) $sumaDia($ingresos[$h] ?? [], 'cantidad'),
                'egresos' => $egresos[$h]['monto'] ?? 0,
                'cant_egresos' => $egresos[$h]['cantidad'] ?? 0,
            ],
            'deuda_prestadores' => $repo->getDeudaPrestadores(),
            'dias' => $dias,
            'flujo_mes' => [
                'entradas' => collect($entradasMes)->map(fn ($m, $id) => ['id' => $id, 'monto' => round($m, 2)])->values()->all(),
                'salidas' => collect($salidasMes)->map(fn ($m, $id) => ['id' => $id, 'monto' => $m])->values()->all(),
            ],
            'movimientos' => $this->armarMovimientos($ingresos, $repo->getUltimosEgresos($h, 14)),
            'circuito' => $circuito['etapas'],
            'indicadores' => [
                'dias_recepcion_pago' => $indicadores['dias_recepcion_pago'],
                'ops_sin_confirmar' => $repo->getOpasSinConfirmar(),
                'debitos_mes' => $circuito['debitos'],
                'retenciones_mes' => $indicadores['retenciones'],
            ],
            // 5 semanas desde el lunes de esta semana (la grilla del calendario va de lunes a domingo)
            'calendario_desde' => $hoy->copy()->startOfWeek()->toDateString(),
            'vencimientos' => $repo->getVencimientos($hoy->copy()->startOfWeek()->toDateString(), $hoy->copy()->startOfWeek()->addDays(34)->toDateString()),
            'antiguedad' => $saldos['antiguedad'],
            'top_deudas' => $saldos['top_deudas'],
        ];
    }

    /**
     * Últimos 14 movimientos: los ingresos llegan por lote diario (uno por origen y día) y los
     * egresos de a uno desde Tesorería.
     */
    private function armarMovimientos(array $ingresos, array $egresos): array
    {
        $conceptos = [
            'arca' => ['Transferencia aportes ARCA', 'transferencias'],
            'sss' => ['Subsidios SSS (SANO / SUMA)', 'registros'],
            'sdis' => ['Subsidios de discapacidad', 'subsidios'],
        ];

        $movs = [];
        foreach ($ingresos as $fecha => $origenes) {
            foreach ($origenes as $origen => $v) {
                $movs[] = [
                    'id' => "in-$origen-$fecha",
                    'fecha' => $fecha,
                    'sentido' => 'in',
                    'categoria' => $origen,
                    'concepto' => $conceptos[$origen][0],
                    'detalle' => 'Lote del ' . Carbon::parse($fecha)->format('d/m') . ' · '
                        . number_format($v['cantidad'], 0, ',', '.') . ' ' . $conceptos[$origen][1],
                    'monto' => $v['monto'],
                ];
            }
        }

        foreach ($egresos as $e) {
            $movs[] = [
                'id' => 'out-' . $e->id,
                'fecha' => $e->fecha,
                'sentido' => 'out',
                'categoria' => $e->destino,
                'concepto' => $e->id_orden_pago ? 'Pago OP ' . $e->id_orden_pago : ($e->descripcion ?: 'Egreso'),
                'detalle' => $e->beneficiario,
                'monto' => round((float) $e->monto, 2),
            ];
        }

        usort($movs, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));

        return array_slice($movs, 0, 14);
    }

    private function armarActividad(Carbon $hoy, string $periodo): array
    {
        $repo = $this->actividadRepository;
        $h = $hoy->toDateString();
        $desde = match ($periodo) {
            'semana' => $hoy->copy()->startOfWeek()->toDateString(),
            'mes' => $hoy->copy()->startOfMonth()->toDateString(),
            default => $h,
        };

        $altasBajas = $repo->getAltasBajasPorDia($hoy->copy()->subDays(13)->toDateString(), $h);
        $dias14 = [];
        for ($d = $hoy->copy()->subDays(13); $d->lte($hoy); $d->addDay()) {
            $f = $d->toDateString();
            $dias14[] = ['fecha' => $f, 'altas' => $altasBajas[$f]['altas'] ?? 0, 'bajas' => $altasBajas[$f]['bajas'] ?? 0];
        }

        return [
            'fecha' => $h,
            'periodo' => $periodo,
            'desde' => $desde,
            'generado' => now()->toIso8601String(),
            'tipos' => $repo->getConteos($desde, $h),
            'por_hora' => $repo->getPorHora($h),
            'feed' => $repo->getFeed($h, 16),
            'padron' => [
                'activos' => $repo->getPadronActivo(),
                'dias' => $dias14,
                'tipos_modificacion' => $repo->getTiposModificacion($desde, $h, 5),
                // En Alba el motivo de baja se carga en la ficha (en OSPF este bloque es de ejemplo)
                'motivos_baja' => $repo->getMotivosBaja($hoy->copy()->startOfMonth()->toDateString(), $h, 5),
            ],
            'circuito' => $repo->getCircuitoDocumental($desde, $h),
            'asientos' => $repo->getAsientos($desde, $h),
            'liquidaciones' => $repo->getUltimasLiquidaciones($h, 6),
            'usuarios' => $repo->getUsuarios($desde, $h, 7),
        ];
    }

    private function puedeVer(): bool
    {
        return in_array((int) Auth::user()?->cod_perfil, self::PERFILES_SUPERDASHBOARD, true);
    }

    private function sinPermiso()
    {
        return response()->json([
            'success' => false,
            'message' => 'No cuenta con permisos para ver el Superdashboard'
        ], 403);
    }
}
