<?php

namespace App\Exports\Prestadores;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;

class PrestadoresListadoExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, WithColumnWidths
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return [
            'Código',
            'CUIT',
            'Razón Social',
            'Nombre Fantasía',
            'Email',
            'Teléfono / Celular',
            'Dirección',
            'Localidad',
            'Tipo Prestador',
            'Condición IVA',
            'Impuesto Ganancias',
            'Fecha Alta',
            'Fecha Baja',
            'Usuario Registra',
            'Estado'
        ];
    }

    public function map($row): array
    {
        $telefono = trim(($row->celular ?? '') . ' / ' . ($row->codigo_postal_telefono ?? ''), ' /');
        if (empty($telefono)) {
            $telefono = '-';
        }

        $fechaAlta = $row->fecha_alta ? Carbon::parse($row->fecha_alta)->format('d/m/Y') : '-';
        $fechaBaja = $row->fecha_baja ? Carbon::parse($row->fecha_baja)->format('d/m/Y') : '-';
        $estado = ($row->vigente === 1 || $row->vigente == '1') ? 'Activo' : 'Inactivo';

        return [
            $row->cod_prestador ?? '-',
            $row->cuit ?? '-',
            $row->razon_social ?? '-',
            $row->nombre_fantasia ?? '-',
            $row->email ?? '-',
            $telefono,
            $row->direccion ?? '-',
            $row->localidad?->localidad ?? '-',
            $row->tipoPrestador?->descripcion ?? '-',
            $row->tipoIva?->descripcion ?? '-',
            $row->tipoImpuesto?->descripcion ?? '-',
            $fechaAlta,
            $fechaBaja,
            $row->usuario?->nombre_apellidos ?? '-',
            $estado
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10, // Código
            'B' => 15, // CUIT
            'C' => 35, // Razón Social
            'D' => 30, // Nombre Fantasía
            'E' => 25, // Email
            'F' => 22, // Teléfono / Celular
            'G' => 30, // Dirección
            'H' => 20, // Localidad
            'I' => 25, // Tipo Prestador
            'J' => 20, // Condición IVA
            'K' => 22, // Impuesto Ganancias
            'L' => 15, // Fecha Alta
            'M' => 15, // Fecha Baja
            'N' => 25, // Usuario Registra
            'O' => 12, // Estado
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $totalRows = count($this->data) + 1;

        return [
            1 => ['font' => ['bold' => true, 'size' => 11]],
            'A1:O' . $totalRows => [
                'alignment' => [
                    'vertical' => 'center'
                ]
            ],
        ];
    }

    public function title(): string
    {
        return 'Listado de Prestadores';
    }
}
