<?php

namespace App\Http\Controllers\facturacion;

use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class FacturasProveedoresController extends Controller
{
    //Se sustituyo por el metodo de abajo que trae los comprobantes asociados a cada factura
    // public function getFacturasProveedores(Request $request)
    // {
    //     $data = [];
    //     $query = DB::table('vw_matriz_facturas_proveedor');
    //     if ($request->desde && $request->hasta) {
    //         $query->whereBetween('fecha_registra', [$request->desde, $request->hasta]);
    //     }

    //     if ($request->vencimiento_desde && $request->vencimiento_hasta) {
    //         $query->whereBetween('fecha_vencimiento', [$request->vencimiento_desde, $request->vencimiento_hasta]);
    //     }

    //     if (!is_null($request->id_tipo)) {
    //         $query->where('id_tipo_factura', $request->id_tipo);
    //     }

    //     if (!is_null($request->id_locatario)) {
    //         $query->where('id_locatorio', $request->id_locatario);
    //     }

    //     if (!is_null($request->num_comprobante)) {
    //         $query->where('comprobante', 'LIKE', "%" . $request->num_comprobante . "%");
    //     }

    //     if (!is_null($request->cuit_prestador)) {
    //         $query->where('cuit', 'LIKE', "%" . $request->cuit_prestador . "%");
    //     }

    //     if (!is_null($request->razon_social)) {
    //         $query->where('razon_social', 'LIKE', "%" . $request->razon_social . "%");
    //     }

    //     if (!is_null($request->liquidacion)) {
    //         $query->where('num_liquidacion', 'LIKE', "%" . $request->liquidacion . "%");
    //     }

    //     if (!is_null($request->estado)) {
    //         $query->where('estado', $request->estado);
    //     }

    //     $query->orderByDesc('id_factura');
    //     /*  if ($request->estado == '9') {
    //         $query->where('estado',    $request->estado);
    //     } else {$request->hasta, ['1', '2', '3', '0', '5']
    //         $query->whereIn('estado', ['0', '1', '2', '3', '4', '5']);
    //     } */

    //     $data = $query->get();
    //     /// $data = DB::select("SELECT * FROM vw_matriz_facturas_proveedor ORDER BY id_factura desc");

    //     return response()->json($data);
    // }

    public function getFacturasProveedoresWithComprobantes(Request $request)
    {
        // Primero obtenemos las facturas con los filtros aplicados
        $query = DB::table('vw_matriz_facturas_proveedor');

        if (!is_null($request->id_tipo_imputacion) && $request->id_tipo_imputacion != '') {
            $query->where('id_tipo_imputacion', $request->id_tipo_imputacion);
        }

        if ($request->desde && $request->hasta) {
            $query->whereBetween('fecha_registra', [$request->desde, $request->hasta]);
        }

        if ($request->vencimiento_desde && $request->vencimiento_hasta) {
            $query->whereBetween('fecha_vencimiento', [$request->vencimiento_desde, $request->vencimiento_hasta]);
        }

        if (!is_null($request->id_tipo) && $request->id_tipo !== '') {
            $query->where('id_tipo_factura', '=', (int) $request->id_tipo);
        }

        // R-00000700: filtro que antes solo tenía la exportación
        if (!is_null($request->id_tipo_comprobante)) {
            $query->where('id_tipo_comprobante', $request->id_tipo_comprobante);
        }

        if (!is_null($request->id_locatario)) {
            $query->where('id_razon', $request->id_locatario);
        }

        if (!is_null($request->num_comprobante)) {
            $query->where('comprobante', 'LIKE', "%" . $request->num_comprobante . "%");
        }

        if (!is_null($request->cuit_prestador)) {
            $query->where('cuit', 'LIKE', "%" . $request->cuit_prestador . "%");
        }

        if (!is_null($request->razon_social)) {
            $query->where('razon_social', 'LIKE', "%" . $request->razon_social . "%");
        }

        if (!is_null($request->liquidacion)) {
            $query->where('num_liquidacion', 'LIKE', "%" . $request->liquidacion . "%");
        }

        if (!is_null($request->estado)) {
            $query->where('estado', $request->estado);
        }

        if (!is_null($request->estado_pago) && $request->estado_pago != '') {
            $query->where('estado_pago', $request->estado_pago);
        }



        $query->orderByDesc('id_factura');
        $facturas = $query->get();

        // Ahora para cada factura obtenemos sus comprobantes usando el modelo
        $facturasWithComprobantes = [];
        foreach ($facturas as $factura) {
            $facturaArray = (array) $factura;

            $detalleOpa = \App\Models\Tesoreria\TesOrdenPagoDetalleEntity::where('id_factura', $factura->id_factura)->first();
            $facturaArray['factura_unida'] = $detalleOpa ? $detalleOpa->factura_unida : 0;

            // Buscar pagos relacionados con esta factura y sus comprobantes
            $pagos = TesPagoEntity::with([
                'comprobantes' => function ($query) {
                    $query->where('estado', 1);
                },
                'opa',
                'detalleopa'
            ])
                ->where('tipo_factura', 'PROVEEDOR')
                ->whereHas('detalleopa', function ($query) use ($factura) {
                    $query->where('id_factura', $factura->id_factura);
                })
                ->get();

            // Recopilar todos los comprobantes de todos los pagos y el número de orden de pago
            $comprobantes = [];
            $id_orden_pago = null;
            foreach ($pagos as $pago) {
                // Obtener el número de orden de pago si existe
                if ($pago->opa && !$id_orden_pago) {
                    $id_orden_pago = $pago->opa->id_orden_pago;
                }
                foreach ($pago->comprobantes as $comprobante) {
                    $comprobantes[] = [
                        'id_comprobante' => $comprobante->id_comprobante,
                        'nombre_archivo' => $comprobante->nombre_archivo,
                        'fecha_comprobante' => $comprobante->fecha_registra,
                        'estado_comprobante' => $comprobante->estado
                    ];
                }
            }

            $facturaArray['comprobantes'] = $comprobantes;
            $facturaArray['id_orden_pago'] = $id_orden_pago;
            $facturasWithComprobantes[] = $facturaArray;
        }

        return response()->json($facturasWithComprobantes);
    }
}
