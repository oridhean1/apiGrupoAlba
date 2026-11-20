<?php

namespace App\Http\Controllers\liquidaciones;

use App\Http\Controllers\liquidaciones\repository\LiquidacionesFacturaRepository;
use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\prestadores\PrestadorEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LiquidacionesFacturaController extends Controller
{

    public function getFacturaLiquidaciones(LiquidacionesFacturaRepository $repo, Request $request)
    {
        return $repo->findTopByFechaRecepcionBetweenAndEstadoAndNumFacturaLike($request);
    }

    public function getFacturaLiquidacionesDetallado(LiquidacionesFacturaRepository $repo, Request $request)
    {
        $filas = $repo->findTopByFechaRecepcionBetweenAndEstadoAndNumFacturaLikeDetallado($request);

        // Se responde en streaming (fila por fila) porque sin filtro de fechas el detalle puede superar
        // las 150k filas y armar el JSON completo en memoria excede el memory_limit
        return response()->stream(function () use ($filas) {
            echo '[';
            $primera = true;
            $i = 0;
            foreach ($filas as $fila) {
                echo ($primera ? '' : ',') . json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $primera = false;
                if (++$i % 2000 === 0) {
                    flush();
                }
            }
            echo ']';
        }, 200, ['Content-Type' => 'application/json']);
    }


    public function getCabeceraFacturaLiquidacion(LiquidacionesFacturaRepository $repo, Request $request)
    {
        $factura = $repo->findByIdFactura($request->id);
        return response()->json(count($factura) > 0 ? $factura[0] : null);
    }

    public function getPeriodos()
    {
        $periodos = FacturacionDatosEntity::select('periodo')->distinct()->orderBy('periodo')->get();
        $periodos = $periodos->map(function ($item) {
            return [
                'val' => $item->periodo,
                'label' => $item->periodo
            ];
        });
        return response()->json($periodos);
    }

    public function getIvaPrestador($cuit)
    {
        $iva = PrestadorEntity::with('tipoIva')->where('cuit', $cuit)->first();

        $ivaArray = [
            [
                "cod_tipo_iva" => $iva->tipoIva->cod_tipo_iva,
                "descripcion_iva" => $iva->tipoIva->descripcion_iva
            ]
        ];

        return response()->json($ivaArray);
    }
}
