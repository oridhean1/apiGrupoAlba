<?php

namespace App\Http\Controllers\facturacion\repository;

use App\Models\facturacion\FacturacionDetalleComprobantesEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Support\Facades\DB;

class FacturasPrestadoresRepository
{

    public function findByListAlls($params)
    {
        //  $placeholder = implode(',', array_fill(0, count($estados), '?'));

        $query = DB::table('vw_matriz_facturas_prestador');
        if ($params->desde && $params->hasta) {
            $query->whereBetween('fecha_registra', [$params->desde, $params->hasta]);
        }

        if ($params->vencimiento_desde && $params->vencimiento_hasta) {
            $query->whereBetween('fecha_vencimiento', [$params->vencimiento_desde, $params->vencimiento_hasta]);
        }

        if (!is_null($params->id_prestador)) {
            $query->where('id_prestador', $params->id_prestador);
        }

        if (!is_null($params->id_tipo_imputacion)) {
            $query->where('id_tipo_imputacion', $params->id_tipo_imputacion);
        }

        if (!is_null($params->id_tipo)) {
            $query->where('id_tipo_factura', $params->id_tipo);
        }

        if (!is_null($params->id_locatario)) {
            $query->where('id_razon', $params->id_locatario);
        }

        if (!is_null($params->num_comprobante)) {
            $query->where('comprobante', 'LIKE', "%" . $params->num_comprobante . "%");
        }

        if (!is_null($params->cuit_prestador)) {
            $query->where('cuit', 'LIKE', "%" . $params->cuit_prestador . "%");
        }

        if (!is_null($params->razon_social)) {
            $query->where('razon_social', 'LIKE', "%" . $params->razon_social . "%");
        }

        if (!is_null($params->liquidacion)) {
            $query->where('num_liquidacion', 'LIKE', "%" . $params->liquidacion . "%");
        }

        if (!is_null($params->estado) && $params->estado != '9') {
            $query->where('estado', $params->estado);
        }
        $query->orderByDesc('id_factura');
        /*  if ($params->estado == '9') {
            $query->where('estado',    $params->estado);
        } else {$request->hasta, ['1', '2', '3', '0', '5']
            $query->whereIn('estado', ['0', '1', '2', '3', '4', '5']);
        } */

        $facturas = $query->get();
        //['0', '1', '2', '3', '4', '5']
        return $facturas;
        /*  return DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE fecha_registra BETWEEN ? AND ?
          AND estado IN ($placeholder) ORDER BY id_factura desc", array_merge([$desde, $hasta], $estados)); */
    }

    public function findByListCuitPrestadorCuit($desde, $hasta, $cuit)
    {
        return DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE fecha_registra BETWEEN ? AND ?
         AND cuit LIKE ? ORDER BY id_factura desc", [$desde, $hasta, '%' . $cuit . '%']);
    }

    public function findByListCuitPrestadorRazonSocial($desde, $hasta, $rason_social)
    {
        return DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE fecha_registra BETWEEN ? AND ?
         AND   razon_social LIKE ? ORDER BY id_factura desc", [$desde, $hasta, '%' . $rason_social . '%']);
    }

    public function findByListCuitPrestadorAndNumFactura($desde, $hasta, $numFactura)
    {
        return DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE fecha_registra BETWEEN ? AND ?
         AND   comprobante LIKE ? ORDER BY id_factura desc", [$desde, $hasta, '%' . $numFactura . '%']);
    }

    public function findByListEstado($desde, $hasta, $estado)
    {
        return DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE fecha_registra BETWEEN ? AND ?
         AND estado = ? ORDER BY id_factura desc", [$desde, $hasta, $estado]);
    }

    public function findByUpdateEstado($factura, $estado)
    {
        return DB::update("UPDATE tb_facturacion_datos SET estado = ? WHERE id_factura = ? ", [$estado, $factura]);
    }

    public function findByUpdateEstadoAndmontoFacturadoAndmontoAprobadoAndmontoDebitadoAndIdfacturaAndfechaLiquida($estado, $montoFcaturado, $montoAprobado, $montoDebitado, $fechaLiquida, $idFactura)
    {
        return DB::update(
            "UPDATE tb_facturacion_datos SET estado = ?, total_debitado_liquidacion = ?,
         total_aprobado_liquidacion = ?, total_facturado_liquidacion = ?, fecha_liquidacion = ? WHERE id_factura = ? ",
            [$estado, $montoDebitado, $montoAprobado, $montoFcaturado, $fechaLiquida, $idFactura]
        );
    }

    public function findByUpdateEstadoAndmontoFacturadoAndmontoAprobadoAndmontoDebitadoAndIdfactura($estado, $montoFcaturado, $montoAprobado, $montoDebitado, $idFactura)
    {
        return DB::update(
            "UPDATE tb_facturacion_datos SET estado = ?, total_debitado_liquidacion = ?,
         total_aprobado_liquidacion = ?, total_facturado_liquidacion = ? WHERE id_factura = ? ",
            [$estado, $montoDebitado, $montoAprobado, $montoFcaturado, $idFactura]
        );
    }

    public function findByFacturaId($id)
    {
        $data = DB::select("SELECT * FROM vw_matriz_facturas_prestador WHERE id_factura = ?", [$id]);
        return count($data) === 0 ? null : $data[0];
    }

    public function findByUpdateTipodetalleAndIdFactura($tipoDetalle, $idFactura)
    {
        return DB::update("UPDATE tb_facturacion_datos SET tipo_carga_detalle = ? WHERE id_factura = ? ", [$tipoDetalle, $idFactura]);
    }

    public function findByUpdateImputacionAndIdFactura($tipo, $idFactura)
    {
        return DB::update("UPDATE tb_facturacion_datos SET id_tipo_imputacion_sintetizada = ? WHERE id_factura = ? ", [$tipo, $idFactura]);
    }

    public function findByListAllsWithComprobantes($params)
    {
        // Primero obtenemos las facturas con los filtros aplicados
        $query = DB::table('vw_matriz_facturas_prestador');

        if ($params->desde && $params->hasta) {
            $query->whereBetween('fecha_registra', [$params->desde, $params->hasta]);
        }

        if ($params->vencimiento_desde && $params->vencimiento_hasta) {
            $query->whereBetween('fecha_vencimiento', [$params->vencimiento_desde, $params->vencimiento_hasta]);
        }

        if (!is_null($params->id_prestador)) {
            $query->where('id_prestador', $params->id_prestador);
        }

        if (!is_null($params->id_tipo_imputacion)) {
            $query->where('id_tipo_imputacion', $params->id_tipo_imputacion);
        }

        if (!is_null($params->id_tipo)) {
            $query->where('id_tipo_factura', $params->id_tipo);
        }

        // R-00000700: filtro que antes solo tenía la exportación
        if (!is_null($params->id_tipo_comprobante)) {
            $query->where('id_tipo_comprobante', $params->id_tipo_comprobante);
        }

        if (!is_null($params->id_locatario)) {
            $query->where('id_razon', $params->id_locatario);
        }

        if (!is_null($params->num_comprobante)) {
            $query->where('comprobante', 'LIKE', "%" . $params->num_comprobante . "%");
        }

        if (!is_null($params->cuit_prestador)) {
            $query->where('cuit', 'LIKE', "%" . $params->cuit_prestador . "%");
        }

        if (!is_null($params->razon_social)) {
            $query->where('razon_social', 'LIKE', "%" . $params->razon_social . "%");
        }

        if (!is_null($params->liquidacion)) {
            $query->where('num_liquidacion', 'LIKE', "%" . $params->liquidacion . "%");
        }

        if (!is_null($params->estado) && $params->estado != '9') {
            $query->where('estado', $params->estado);
        }

        if (!is_null($params->estado_pago) && $params->estado_pago != '') {
            $query->where('estado_pago', $params->estado_pago);
        }

        $query->orderByDesc('id_factura');
        $facturas = $query->get();

        // Ahora para cada factura obtenemos sus comprobantes usando el modelo
        $facturasWithComprobantes = [];
        foreach ($facturas as $factura) {
            $facturaArray = (array) $factura;

            $archivo_comprobantes = FacturacionDetalleComprobantesEntity::where('id_factura', $factura->id_factura)->get();

            $facturaArray['archivos_comprobante'] = $archivo_comprobantes;

            // Buscar pagos relacionados con esta factura y sus comprobantes
            $pagos = TesPagoEntity::with([
                'comprobantes' => function ($query) {
                    $query->where('estado', 1);
                },
                'opa',
                'detalleopa'
            ])
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

        return $facturasWithComprobantes;

        if ($params->desde && $params->hasta) {
            $query->whereBetween('vw_matriz_facturas_prestador.fecha_registra', [$params->desde, $params->hasta]);
        }

        if ($params->vencimiento_desde && $params->vencimiento_hasta) {
            $query->whereBetween('vw_matriz_facturas_prestador.fecha_vencimiento', [$params->vencimiento_desde, $params->vencimiento_hasta]);
        }

        if (!is_null($params->id_prestador)) {
            $query->where('vw_matriz_facturas_prestador.id_prestador', $params->id_prestador);
        }

        if (!is_null($params->id_tipo_imputacion)) {
            $query->where('vw_matriz_facturas_prestador.id_tipo_imputacion', $params->id_tipo_imputacion);
        }

        if (!is_null($params->id_tipo)) {
            $query->where('vw_matriz_facturas_prestador.id_tipo_factura', $params->id_tipo);
        }

        if (!is_null($params->id_locatario)) {
            $query->where('vw_matriz_facturas_prestador.id_locatorio', $params->id_locatario);
        }

        if (!is_null($params->num_comprobante)) {
            $query->where('vw_matriz_facturas_prestador.comprobante', 'LIKE', "%" . $params->num_comprobante . "%");
        }

        if (!is_null($params->cuit_prestador)) {
            $query->where('vw_matriz_facturas_prestador.cuit', 'LIKE', "%" . $params->cuit_prestador . "%");
        }

        if (!is_null($params->razon_social)) {
            $query->where('vw_matriz_facturas_prestador.razon_social', 'LIKE', "%" . $params->razon_social . "%");
        }

        if (!is_null($params->liquidacion)) {
            $query->where('vw_matriz_facturas_prestador.num_liquidacion', 'LIKE', "%" . $params->liquidacion . "%");
        }

        if (!is_null($params->estado) && $params->estado != '9') {
            $query->where('vw_matriz_facturas_prestador.estado', $params->estado);
        }

        // Filtrar solo comprobantes activos
        $query->where(function ($q) {
            $q->whereNull('tb_test_pago_detalle_comprobantes.estado')
                ->orWhere('tb_test_pago_detalle_comprobantes.estado', 1);
        });

        $query->orderByDesc('vw_matriz_facturas_prestador.id_factura');

        $result = $query->get();

        // Agrupar comprobantes por factura
        $facturas = [];
        foreach ($result as $row) {
            $facturaId = $row->id_factura;

            if (!isset($facturas[$facturaId])) {
                $factura = (array) $row;
                // Remover campos de comprobante del objeto factura principal
                unset(
                    $factura['id_comprobante'],
                    $factura['nombre_archivo'],
                    $factura['fecha_comprobante'],
                    $factura['estado_comprobante']
                );

                $factura['comprobantes'] = [];
                $facturas[$facturaId] = $factura;
            }

            // Agregar comprobante si existe
            if ($row->id_comprobante) {
                $facturas[$facturaId]['comprobantes'][] = [
                    'id_comprobante' => $row->id_comprobante,
                    'nombre_archivo' => $row->nombre_archivo,
                    'fecha_comprobante' => $row->fecha_comprobante,
                    'estado_comprobante' => $row->estado_comprobante
                ];
            }
        }

        return array_values($facturas);
    }
}
