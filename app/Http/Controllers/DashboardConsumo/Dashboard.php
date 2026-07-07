<?php

namespace App\Http\Controllers\DashboardConsumo;

use App\Http\Controllers\Controller;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\BonoClinicoEntity;
use App\Models\Internaciones\InternacionesEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as RoutingController;
use Illuminate\Support\Facades\DB;

class Dashboard extends RoutingController
{
    //
    public function getDashboard(Request $request)
    {


        $anio = $request->anio ?? Carbon::now()->year;

        $bonos = $this->aplicarFiltros(BonoClinicoEntity::query(), $request, 'fecha_registra', 'afiliado')->count();

        $autorizaciones = $this->aplicarFiltros(PrestacionesPracticaLaboratorioEntity::query(), $request, 'fecha_registra', 'afiliado')->count();

        $internacion = $this->aplicarFiltros(InternacionesEntity::query(), $request, 'fecha_internacion', 'afiliado')->count();

        // Liquidaciones query using vw_liquidacion_factura_unica
        $liqQuery = DB::table('vw_liquidacion_factura_unica');

        // Apply filters to liquidaciones query
        if ($request->id_locatario) {
            $liqQuery->where('id_locatorio', $request->id_locatario);
        }
        if ($request->desde && $request->hasta) {
            $liqQuery->whereBetween('fecha_registra_factura', [$request->desde, $request->hasta]);
        }
        if ($request->dni || $request->cuil_benef) {
            $liqQuery->whereIn('id_factura', function ($subQuery) use ($request) {
                $subQuery->select('l.id_factura')
                    ->from('tb_liquidaciones as l')
                    ->join('tb_padron as p', 'l.id_afiliado', '=', 'p.id')
                    ->when($request->dni, function ($q) use ($request) {
                        $q->where('p.dni', $request->dni);
                    })
                    ->when($request->cuil_benef, function ($q) use ($request) {
                        $q->where('p.cuil_benef', $request->cuil_benef);
                    });
            });
        }

        $cantidadLiquidaciones = $liqQuery->count();
        $totalLiquidaciones = $liqQuery->sum('total_neto') ?? 0;
        $totalLiqFormateado = number_format($totalLiquidaciones, 2, ',', '.');

        $importeBonos = $this->aplicarFiltros(
            BonoClinicoEntity::query()->select(DB::raw('SUM(costo_bono) as total')),
            $request,
            'fecha_registra'
        )->value('total') ?? 0;

        $importeAutorizaciones = $this->aplicarFiltros(
            PrestacionesPracticaLaboratorioEntity::query()->select(DB::raw('SUM(monto_pagar) as total')),
            $request,
            'fecha_registra'
        )->value('total') ?? 0;

        $bonosMensual = $this->aplicarFiltros(
            BonoClinicoEntity::query()
                ->select(
                    DB::raw('MONTH(fecha_registra) as mes'),
                    DB::raw('SUM(costo_bono) as total')
                )
                ->whereYear('fecha_registra', $anio)
                ->groupBy(DB::raw('MONTH(fecha_registra)')),
            $request,
            'fecha_registra'
        );

        $autMensual = $this->aplicarFiltros(
            PrestacionesPracticaLaboratorioEntity::query()
                ->select(
                    DB::raw('MONTH(fecha_registra) as mes'),
                    DB::raw('SUM(monto_pagar) as total')
                )
                ->whereYear('fecha_registra', $anio)
                ->groupBy(DB::raw('MONTH(fecha_registra)')),
            $request,
            'fecha_registra'
        );
        $union = $bonosMensual->unionAll($autMensual);

        $importe_mensual = DB::table(DB::raw("({$union->toSql()}) as t"))
            ->mergeBindings($union->getQuery())
            ->select(
                'mes',
                DB::raw('SUM(total) as total')
            )
            ->groupBy('mes')
            ->orderBy('mes')
            ->get()
            ->map(function ($item) {
                $item->total_formateado = number_format($item->total, 2, ',', '.');
                return $item;
            });

        $importesFamiliaBonos = $this->aplicarFiltros(
            BonoClinicoEntity::query()->selectRaw("
                SUM(CASE 
                    WHEN tb_padron.id_parentesco = '00' THEN costo_bono 
                    ELSE 0 
                END) as total_titular,

                SUM(CASE 
                    WHEN tb_padron.id_parentesco != '00' THEN costo_bono 
                    ELSE 0 
                END) as total_familiar
            ")
            ->join('tb_padron', 'tb_padron.dni', '=', 'tb_bonos_medicos.dni_afiliado'),
            $request,
            'fecha_registra'
        )->first();

        $importesFamiliaAuto = $this->aplicarFiltros(
            PrestacionesPracticaLaboratorioEntity::query()->selectRaw("
                SUM(CASE 
                    WHEN tb_padron.id_parentesco = '00' THEN monto_pagar 
                    ELSE 0 
                END) as total_titular,

                SUM(CASE 
                    WHEN tb_padron.id_parentesco != '00' THEN monto_pagar 
                    ELSE 0 
                END) as total_familiar
            ")
            ->join('tb_padron', 'tb_padron.dni', '=', 'tb_prestaciones_medicas.dni_afiliado'),
            $request,
            'fecha_registra'
        )->first();

        $totalTitularVal = ($importesFamiliaAuto->total_titular ?? 0) + ($importesFamiliaBonos->total_titular ?? 0);
        $totalFamiliarVal = ($importesFamiliaAuto->total_familiar ?? 0) + ($importesFamiliaBonos->total_familiar ?? 0);
        
        $totalImporte = number_format(($importeAutorizaciones + $importeBonos), 2, ',', '.');
        $totalBonos = number_format(($importeBonos), 2, ',', '.');
        $totalAuto = number_format(($importeAutorizaciones), 2, ',', '.');
        
        $totalTitular = number_format($totalTitularVal, 2, ',', '.');
        $totalfamiliar = number_format($totalFamiliarVal, 2, ',', '.');

        return response()->json([
            'success' => true,
            'data' => [
                'bonos' => $bonos,
                'autorizaciones' => $autorizaciones,
                'internacion' => $internacion,
                'importe' => $totalImporte,
                'importe_bonos' => $totalBonos,
                'importe_auto' => $totalAuto,
                'importe_anual' => $importe_mensual,
                'cantidad_liquidaciones' => $cantidadLiquidaciones,
                'total_liquidaciones' => $totalLiqFormateado,
                'titular' => $totalTitular,
                'familia' => $totalfamiliar
            ]
        ]);

    }

    public function getDetallesConsumosAfiliado(Request $request)
    {
        $afiliado = AfiliadoPadronEntity::with(['obrasocial', 'tipoParentesco', 'sexo'])
            ->when($request->dni, function ($q) use ($request) {
                $q->where('dni', $request->dni);
            })
            ->when($request->cuil_benef, function ($q) use ($request) {
                $q->where('cuil_benef', $request->cuil_benef);
            })
            ->first();

        if (!$afiliado) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un afiliado con los criterios de búsqueda.'
            ], 404);
        }

        $bonos = $this->aplicarFiltros(BonoClinicoEntity::with(['tipoBono', 'medico', 'usuario']), $request, 'fecha_registra', 'afiliado')->get();

        $autorizaciones = $this->aplicarFiltros(PrestacionesPracticaLaboratorioEntity::with(['estadoPrestacion', 'prestador', 'profesional']), $request, 'fecha_registra', 'afiliado')->get();

        $internaciones = $this->aplicarFiltros(InternacionesEntity::with(['prestador', 'tipoInternacion', 'tipoHabitacion', 'categoria', 'estadoPrestacion']), $request, 'fecha_internacion', 'afiliado')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'afiliado' => $afiliado,
                'bonos' => $bonos,
                'autorizaciones' => $autorizaciones,
                'internaciones' => $internaciones
            ]
        ]);
    }

    private function aplicarFiltros($query, $request, $campoFecha, $relacion = 'afiliado')
    {
        return $query
            ->when($request->id_locatario, function ($q) use ($request, $relacion) {
                $q->whereHas($relacion, function ($sub) use ($request) {
                    $sub->where('id_locatario', $request->id_locatario);
                });
            })
            ->when($request->filial, function ($q) use ($request, $relacion) {
                $q->whereHas($relacion, function ($sub) use ($request) {
                    $sub->where('id_delegacion', $request->filial);
                });
            })
            ->when($request->desde && $request->hasta, function ($q) use ($request, $campoFecha) {
                $q->whereBetween($campoFecha, [$request->desde, $request->hasta]);
            })
            ->when($request->dni, function ($q) use ($request, $relacion) {
                $q->whereHas($relacion, function ($sub) use ($request) {
                    $sub->where('dni', $request->dni);
                });
            })
            ->when($request->cuil_benef, function ($q) use ($request, $relacion) {
                $q->whereHas($relacion, function ($sub) use ($request) {
                    $sub->where('cuil_benef', $request->cuil_benef);
                });
            });
    }
}
