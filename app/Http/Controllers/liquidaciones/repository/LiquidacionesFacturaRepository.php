<?php

namespace App\Http\Controllers\liquidaciones\repository;

use App\Http\Controllers\liquidaciones\dto\FacturaLiquidacionCabeceraDto;
use App\Http\Controllers\liquidaciones\dto\LiquidacionesFacturaDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiquidacionesFacturaRepository
{

    public function findByIdFactura($idFactura)
    {
        $data = DB::select("SELECT
            num_liquidacion,cuit,prestador,prestador_fantasia,subtotal,total_iva,total_neto,total_debito,tipo_carga_detalle,
            delegacion,periodo,id_factura,id_prestador,estado,total_aprobado_liquidacion,total_facturado_liquidacion,comprobante, imputacion_contable
            FROM vw_liquidacion_factura_unica WHERE id_factura = ? ", [$idFactura]);

        return $this->collectCabecera($data);
    }

    public function findTopByFechaRecepcionBetweenAndEstadoAndNumFacturaLike($params)
    {

        $query = DB::table('vw_liquidacion_factura_unica');

        if (!empty($params->num_factura)) {
            $query->where('comprobante', 'like', '%' . $params->num_factura . '%');
        }

        if (!empty($params->desde) && !empty($params->hasta)) {
            $query->whereBetween('fecha_registra_factura', [$params->desde, $params->hasta]);
        }

        if (!empty($params->periodo)) {
            $query->where('periodo', $params->periodo);
        }

        if (!empty($params->estado)) {
            $query->where('estado', $params->estado);
        }

        if (!empty($params->id_locatario)) {
            $query->where('id_locatorio', $params->id_locatario);
        }

        if (!empty($params->cuit_prestador)) {
            $query->where(function ($q) use ($params) {
                $q->where('cuit', 'like', "%$params->cuit_prestador%")
                    ->orWhere('prestador', 'like', "%$params->cuit_prestador%")
                    ->orWhere('prestador_fantasia', 'like', "%$params->cuit_prestador%");
            });
        }

        $data = $query
            ->orderByDesc('fecha_registra_factura')
            ->orderBy('prestador_fantasia')
            ->orderByDesc('total_neto')
            ->get();

        return $this->collectAlls($data);
    }

    public function findTopByFechaRecepcionBetweenAndEstadoAndNumFacturaLikeDetallado($params)
    {
        $query = DB::table('vw_liquidacion_factura_unica');

        if (!empty($params->num_factura)) {
            $query->where('comprobante', 'like', '%' . $params->num_factura . '%');
        }
        if (!empty($params->desde) && !empty($params->hasta)) {
            $query->whereBetween('fecha_registra_factura', [$params->desde, $params->hasta]);
        }
        if (!empty($params->periodo)) {
            $query->where('periodo', $params->periodo);
        }
        if (!empty($params->estado)) {
            $query->where('estado', $params->estado);
        }
        if (!empty($params->id_locatario)) {
            $query->where('id_locatorio', $params->id_locatario);
        }
        if (!empty($params->cuit_prestador)) {
            $query->where(function ($q) use ($params) {
                $q->where('cuit', 'like', "%$params->cuit_prestador%")
                    ->orWhere('prestador', 'like', "%$params->cuit_prestador%")
                    ->orWhere('prestador_fantasia', 'like', "%$params->cuit_prestador%");
            });
        }

        $facturas = $query->pluck('id_factura')->toArray();
        
        if (count($facturas) == 0) {
            return [];
        }

        // Dividir el array en chunks de 500 para evitar errores en queries largos
        $chunks = array_chunk($facturas, 500);
        $results = [];

        foreach ($chunks as $chunk) {
            $facturasStr = implode(',', $chunk);

            $sql = "
                SELECT 
                    fa.prestador as dni_prestador,
                    fa.cuit,
                    fa.razon_social,
                    fa.num_liquidacion,
                    fa.fecha_recepcion,
                    fa.fecha_vencimiento,
                    fa.fecha_liquidacion,
                    fa.comprobante,
                    fa.refacturacion,
                    fa.imputacion_contable,
                    fa.subtotal,
                    fa.total_iva,
                    fa.total_neto,
                    fa.total_debito as factura_total_debito,
                    fa.delegacion,
                    fa.periodo,
                    fa.tipo_carga_detalle,
                    fa.locatorio as origen, 
                    fa.fecha_registra_factura as fecha_registro_factura,
                    
                    det.codigo_practica,
                    det.practica,
                    det.monto_facturado as detalle_monto_facturado,
                    det.monto_aprobado as detalle_monto_aprobado,
                    det.monto_debitado as detalle_monto_debitado,
                    det.coseguro,
                    det.debita_coseguro,
                    det.motivo_debito,
                    det.observacion_debito,
                    det.afiliado,
                    det.edad_afiliado,
                    det.dni_afiliado,
                    det.fecha_prestacion,
                    det.tipo as detalle_tipo,
                    
                    padron.cuil_tit as cuil_titular,
                    padron.cuil_benef as cuil_beneficiario,
                    
                    COALESCE(l.diagnostico, lm.diagnostico) as diagnostico,
                    COALESCE(l.observaciones, lm.observaciones) as observaciones,
                    COALESCE(l.fecha_registra, lm.fecha_registra) as fecha_registro_liq,
                    COALESCE(l.fecha_actualiza, lm.fecha_actualiza) as fecha_actualiza_liq
                FROM vw_liquidacion_factura_unica fa
                LEFT JOIN (
                    SELECT  codigo_practica, practica, monto_facturado, monto_aprobado, monto_debitado, 
                            coseguro, debita_coseguro, motivo_debito, observacion_debito, afiliado, 
                            edad_afiliado, dni_afiliado, tipo, id_factura, fecha_prestacion
                    FROM vw_detalle_liquidaciones WHERE id_factura IN ($facturasStr)
                    UNION ALL
                    SELECT id_medicamento as codigo_practica, medicamento as practica, monto_facturado, 
                           0 as monto_aprobado, 0 as monto_debitado, 0 as coseguro, 0 as debita_coseguro, 
                           motivo_debito, '' as observacion_debito, '' as afiliado, '' as edad_afiliado, 
                           '' as dni_afiliado, tipo, id_factura, '' as fecha_prestacion
                    FROM vw_detalle_medicamentos WHERE id_factura IN ($facturasStr)
                ) as det ON fa.id_factura = det.id_factura
                LEFT JOIN tb_padron padron ON padron.dni = det.dni_afiliado AND det.dni_afiliado != ''
                LEFT JOIN tb_liquidaciones l ON l.id_factura = fa.id_factura AND l.id_afiliado = padron.id AND det.tipo = 'Practica'
                LEFT JOIN tb_liquidaciones_medicamentos lm ON lm.id_factura = fa.id_factura AND lm.id_afiliado = padron.id AND det.tipo = 'Medicamento'
                WHERE fa.id_factura IN ($facturasStr)
                ORDER BY fa.fecha_registra_factura DESC, fa.prestador_fantasia ASC
            ";
            
            $chunkResults = DB::select($sql);
            $results = array_merge($results, $chunkResults);
        }

        return $results;
    }

    public function collectAlls($params)
    {
        return collect($params)
            ->map(function ($row) {
                return new LiquidacionesFacturaDto(
                    $row->cuit,
                    $row->prestador_fantasia,
                    $row->num_liquidacion,
                    $row->fecha_recepcion,
                    $row->fecha_vencimiento,
                    $row->fecha_liquidacion,
                    $row->comprobante,
                    ($row->refacturacion === '1' ? 'SI' : 'NO'),
                    $row->prestacion_externa,
                    $row->imputacion_contable,
                    $row->subtotal,
                    $row->total_iva,
                    $row->total_neto,
                    $row->total_debito,
                    $row->delegacion,
                    $row->periodo,
                    $row->tipo_carga_detalle,
                    $row->id_factura,
                    $row->id_tipo_factura,
                    $row->cod_sindicato,
                    $row->id_tipo_comprobante,
                    $row->id_tipo_imputacion_sintetizada,
                    $row->id_prestador,
                    $row->id_locatorio,
                    $row->estado,
                    $row->email_prestador,
                    $row->id_estado_orden_pago,
                    $row->id_orden_pago,
                    $row->estado_pago,
                    $row->locatorio,
                    $row->razon_social,
                    $row->tipo_prestador,
                    $row->tipo_proveedor,
                    $row->fecha_registra_factura,
                    $row->factura_unida,
                );
            });
    }

    public function collectCabecera($params)
    {
        return collect($params)
            ->map(function ($row) {
                return new FacturaLiquidacionCabeceraDto(
                    $row->num_liquidacion,
                    $row->cuit,
                    $row->prestador,
                    $row->prestador_fantasia,
                    $row->subtotal,
                    $row->total_iva,
                    $row->total_neto,
                    $row->total_debito,
                    $row->tipo_carga_detalle,
                    $row->delegacion,
                    $row->periodo,
                    $row->id_factura,
                    $row->id_prestador,
                    $row->estado,
                    $row->comprobante,
                    $row->imputacion_contable
                );
            });
    }
}
