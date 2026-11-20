<?php

namespace App\Exports\Facturas;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;

class FacturaPrestadorExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
{
    /**
     * @return \Illuminate\Support\Collection
     */
    use Exportable;
    protected $params;

    public function __construct($param)
    {
        $this->params = $param;
    }

    public function collection()
    {
        //
        // Desde el cambio de carga de facturación (08/2026) el detalle guarda en id_articulo el id de
        // la imputación contable elegida, no un artículo. Los comprobantes anteriores siguen teniendo
        // un id de artículo, así que se resuelve el nombre contra las dos tablas.
        $sql = "SELECT vwm.cuit, vwm.razon_social, vwm.comprobante, vwm.refacturacion,
                vwm.delegacion, vwm.periodo, COALESCE(imp.imputacion, ma.articulo) AS articulo,
                tfd.cantidad, tfd.precio_neto, tfd.subtotal, tfd.monto_iva,                
                COALESCE(tfdi.total_impuestos, 0) AS total_impuestos, tfd.total_importe,
                vwm.fecha_comprobante, vwm.fecha_registra, vwm.total_aprobado, vwm.total_facturado,
                vwm.total_debitado, vwm.r_social, vwm.tipo_comprobante, vwm.observaciones
        FROM vw_matriz_facturas_prestador AS vwm 
        LEFT JOIN tb_facturacion_detalle tfd ON tfd.id_factura = vwm.id_factura
        LEFT JOIN (
            SELECT id_factura, SUM(importe) AS total_impuestos FROM tb_facturacion_detalle_impuesto GROUP BY id_factura
        ) tfdi ON tfdi.id_factura = vwm.id_factura
        LEFT JOIN tb_facturacion_detalle_descuento tfdd ON tfdd.id_factura = vwm.id_factura
        LEFT JOIN vw_matriz_articulos ma ON ma.id_articulo = tfd.id_articulo
        LEFT JOIN tb_cont_imputacion_prestadores_cuenta_contable imp ON imp.id_imputacion_cuenta_contable = tfd.id_articulo";

        // R-00000700: se exporta con los mismos filtros (y el mismo criterio) que la búsqueda de
        // FacturasPrestadoresRepository::findByListAllsWithComprobantes. Si se cambia uno, cambiar el otro.
        $p = $this->params;
        $params = [];
        $where = [];

        if ($p->desde && $p->hasta) {
            $where[] = "vwm.fecha_registra BETWEEN ? AND ?";
            $params[] = $p->desde;
            $params[] = $p->hasta;
        }

        if ($p->vencimiento_desde && $p->vencimiento_hasta) {
            $where[] = "vwm.fecha_vencimiento BETWEEN ? AND ?";
            $params[] = $p->vencimiento_desde;
            $params[] = $p->vencimiento_hasta;
        }

        if (!is_null($p->id_prestador)) {
            $where[] = "vwm.id_prestador = ?";
            $params[] = $p->id_prestador;
        }

        if (!is_null($p->id_tipo_imputacion)) {
            $where[] = "vwm.id_tipo_imputacion = ?";
            $params[] = $p->id_tipo_imputacion;
        }

        if (!is_null($p->id_tipo)) {
            $where[] = "vwm.id_tipo_factura = ?";
            $params[] = $p->id_tipo;
        }

        if (!is_null($p->id_tipo_comprobante)) {
            $where[] = "vwm.id_tipo_comprobante = ?";
            $params[] = $p->id_tipo_comprobante;
        }

        // "locatario" es el nombre que usaba el panel "Exportar datos" (todavía lo manda la copia de convenios en frontOsv)
        $idRazon = $p->id_locatario ?? $p->locatario;
        if (!is_null($idRazon)) {
            $where[] = "vwm.id_razon = ?";
            $params[] = $idRazon;
        }

        if (!is_null($p->num_comprobante)) {
            $where[] = "vwm.comprobante LIKE ?";
            $params[] = "%" . $p->num_comprobante . "%";
        }

        if (!is_null($p->cuit_prestador)) {
            $where[] = "vwm.cuit LIKE ?";
            $params[] = "%" . $p->cuit_prestador . "%";
        }

        if (!is_null($p->razon_social)) {
            $where[] = "vwm.razon_social LIKE ?";
            $params[] = "%" . $p->razon_social . "%";
        }

        if (!is_null($p->liquidacion)) {
            $where[] = "vwm.num_liquidacion LIKE ?";
            $params[] = "%" . $p->liquidacion . "%";
        }

        if (!is_null($p->estado) && $p->estado != '9') {
            $where[] = "vwm.estado = ?";
            $params[] = $p->estado;
        }

        if (!is_null($p->estado_pago) && $p->estado_pago != '') {
            $where[] = "vwm.estado_pago = ?";
            $params[] = $p->estado_pago;
        }

        if (count($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY vwm.id_factura";

        $data = DB::select($sql, $params);
        return collect($data);
    }

    public function headings(): array
    {
        return [
            'CUIT',
            'PRESTADOR',
            'COMPROBANTE',
            'REFACTURACION',
            'DELEGACION',
            'PERIODO',
            'ARTICULO',
            'CANTIDAD',
            'PRECIO NETO',
            'SUBTOTAL',
            'TOTAL IVA',
            'TOTAL IMPUESTO',
            'TOTAL NETO',
            'FECHA COMPROBANTE',
            'FECHA REGISTRA',
            'TOTAL APROBADO',
            'TOTAL FACTURADO',
            'TOTAL DEBITADO',
            'RAZON SOCIAL',
            'TIPO COMPROBANTE',
            'OBSERVACIONES'
        ];
    }

    public function styles($excel)
    {
        return [
            'A1:BB1' => ['font' => ['bold' => true]],
        ];
    }
}
