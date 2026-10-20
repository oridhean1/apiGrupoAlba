<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\configuracion\RazonSocialModelo;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;

class OrdenesPagoExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
{
    /**
     * @return \Illuminate\Support\Collection
     */
    protected $params;
    public function __construct($param)
    {
        $this->params = $param;
    }
    public function collection()
    {
        // Mismo filtro que la grilla del Gestor de OPAs: lo que se ve es lo que se exporta.
        // Antes tenía su copia propia y pedía la relación `factura` (una OPA = una factura), que
        // dejó de existir cuando las OPAs pasaron a agrupar facturas: el export daba error 500
        // en Alba y OSV. (2026-10-20)
        $opas = app(TestOrdenPagoRepository::class)->getFiltroDinamico($this->params);

        $razones = RazonSocialModelo::pluck('razon_social', 'id_razon');

        return $opas
            ->sortBy([['id_estado_orden_pago', 'asc'], ['fecha_probable_pago', 'desc']])
            ->values()
            ->map(function ($item) use ($razones) {
                // Facturas de la orden; un ANTICIPO no tiene: las suyas viven en sus aplicaciones.
                $facturas = $item->opadetalle->pluck('detallefc')->filter();
                if ($facturas->isEmpty()) {
                    $facturas = $item->aplicaciones
                        ->flatMap(fn($ap) => $ap->opadetalle->pluck('detallefc'))
                        ->filter();
                }
                $beneficiario = $item->proveedor ?? $item->prestador;
                // Razón social: la de las facturas o, sin facturas (anticipo), la de la orden.
                $idRazon = optional($facturas->first())->id_locatorio ?? $item->id_razon;

                return [
                    'id_orden_pago' => $item->id_orden_pago,
                    'numero_orden_pago' => $item->num_orden_pago,
                    'tipo_opa' => $item->tipo_opa,
                    'fecha_probable_pago' => $item->fecha_probable_pago,
                    'fecha_confirmar_pago' => $item->fecha_confirma_pago,
                    'n_factura' => $facturas->pluck('numero')->filter()->unique()->implode(', '),
                    'n_liquidacion' => $facturas->pluck('num_liquidacion')->filter()->unique()->implode(', '),
                    'tipo_beneficiario' => $item->tipo_factura,
                    'beneficiario' => $beneficiario ? $beneficiario->cuit . ' - ' . $beneficiario->razon_social : '',
                    'razon_social' => $razones[$idRazon] ?? '',
                    'monto' => $item->monto_orden_pago,
                    'estado' => optional($item->estado)->descripcion_estado,
                    'observaciones' => $item->observaciones,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'ID ORDEN DE PAGO',
            'NUMERO ORDEN DE PAGO',
            'TIPO OPA',
            'FECHA PROBABLE PAGO',
            'FECHA CONFIRMAR PAGO',
            'N° FACTURA',
            'N° LIQUIDACIÓN',
            'TIPO BENEFICIARIO',
            'BENEFICIARIO',
            'RAZÓN SOCIAL',
            'MONTO',
            'ESTADO',
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
