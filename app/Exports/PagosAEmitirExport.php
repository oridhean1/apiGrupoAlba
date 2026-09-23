<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Pagos a emitir": eCheq y transferencias ya definidos que todavía no se emitieron en el banco.
 *
 * Es el archivo que Pagos lleva al banco o al home banking. Sale ordenado por BANCO EMISOR para
 * poder emitir en tandas, que es la razón de ser del listado.
 *
 * No trae el número de eCheq a propósito: en este momento del proceso el banco todavía no lo
 * asignó. Para eso está el otro listado (`EcheqPendientesNumeroExport`), que es el de después.
 */
class PagosAEmitirExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
{
    protected $repository;
    protected $idBanco;
    protected $numeroOpa;
    protected $idRazon;

    public function __construct(TesInstrumentoPagoRepository $repository, $idBanco = null, $numeroOpa = null, $idRazon = null)
    {
        $this->repository = $repository;
        $this->idBanco    = $idBanco;
        $this->numeroOpa  = $numeroOpa;
        $this->idRazon    = $idRazon;
    }

    public function collection()
    {
        return $this->repository
            ->listarPagosAEmitir($this->idBanco, $this->numeroOpa, $this->idRazon)
            ->map(fn($p) => [
                'num_orden_pago' => $p->num_orden_pago,
                'locatario'      => $p->locatario,
                'cuit'           => $p->cuit,
                'razon_social'   => $p->razon_social,
                'banco'          => $p->banco,
                'metodo_pago'    => $p->metodo_pago,
                // Vacío en eCheq: el CBU es la cuenta destino de una transferencia y en un eCheq
                // no corresponde.
                'cbu'            => $p->cbu,
                'monto'          => $p->monto,
                'fecha_pago'     => $p->fecha_pago,
                'observaciones'  => $p->observaciones,
            ]);
    }

    public function headings(): array
    {
        return [
            'NRO ORDEN DE PAGO',
            'LOCATARIO',
            'CUIT PRESTADOR',
            'RAZON SOCIAL PRESTADOR',
            'BANCO',
            'METODO DE PAGO',
            'CBU',
            'MONTO',
            'FECHA DE PAGO',
            'OBSERVACIONES',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFF00'],
            ],
        ]);

        // El CBU es un número largo: como número, Excel lo muestra en notación científica y el
        // operador copia algo que no es el CBU. Va como texto.
        $sheet->getStyle('G:G')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('H:H')->getNumberFormat()->setFormatCode('#,##0.00');

        return [];
    }
}
