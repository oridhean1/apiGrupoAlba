<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngClienteRepository;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cuenta corriente de un cliente (circuito de ingresos) a Excel — R-00000684.
 *
 * Mismo formato que CuentaCorrienteExport (prestadores/proveedores): encabezado, saldo anterior al
 * período y movimientos con Debe / Haber / Saldo. Cada orden de cobranza va en UN renglón; si tiene
 * varios medios de cobro se listan juntos separados por " / " (en el mismo orden en las 3 columnas).
 */
class CuentaCorrienteClienteExport implements FromArray, ShouldAutoSize, WithStyles
{
    private const SEP = ' / ';

    private int $filaEncabezado = 1;

    public function __construct(
        private TesIngClienteRepository $repo,
        private $idRazon,
        private $idCliente,
        private ?string $desde,
        private ?string $hasta
    ) {
    }

    public function array(): array
    {
        $datos = $this->repo->cuentaCorriente($this->idRazon, $this->idCliente, $this->hasta, $this->desde);

        $cliente = DB::table('tb_tes_ing_cliente')->where('id_cliente', $this->idCliente)->first(['razon_social', 'cuit']);
        $razon = DB::table('tb_razones_sociales')->where('id_razon', $this->idRazon)->first(['razon_social', 'cuit']);

        $filas = [
            ['Cuenta corriente', $cliente->razon_social ?? '', 'CUIT ' . ($cliente->cuit ?? '')],
            ['Razón social receptora', $razon->razon_social ?? '', 'CUIT ' . ($razon->cuit ?? '')],
            ['Período', ($this->desde ?: 'inicio') . ' a ' . ($this->hasta ?: 'hoy')],
            [],
            ['Fecha', 'Tipo', 'Comprobante', 'Medios de cobro', 'Cuenta bancaria', 'N° transferencia / comprobante',
                'Importe', 'Retención Ganancias', 'Detalle', 'Aplicación', 'Debe', 'Haber', 'Saldo'],
        ];
        $this->filaEncabezado = count($filas);

        foreach ($datos['movimientos'] as $m) {
            if ($m['tipo'] === 'SALDO_ANTERIOR') {
                $filas[] = [$m['fecha'], 'SALDO ANTERIOR', $m['comprobante'], '', '', '', '', '', '', '', '', '', round($m['saldo'], 2)];
                continue;
            }

            $medios = $m['medios'] ?? [];
            $col = fn(string $k) => implode(self::SEP, array_map(fn($x) => (string) ($x[$k] ?? '') !== '' ? $x[$k] : '-', $medios));

            $filas[] = [
                $m['fecha'],
                $m['tipo'],
                $m['comprobante'],
                $col('forma'),
                $col('cuenta'),
                $col('referencia'),
                $medios ? round(array_sum(array_column($medios, 'importe')), 2) : '',
                !empty($m['retencion']) ? round($m['retencion'], 2) : '',
                $m['observaciones'] ?? '',
                $m['detalle'],
                $m['debe'] ?: '',
                $m['haber'] ?: '',
                round($m['saldo'], 2),
            ];
        }

        return $filas;
    }

    public function styles(Worksheet $sheet)
    {
        $h = $this->filaEncabezado;

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("A{$h}:M{$h}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF9']],
        ]);
        foreach (['G', 'H', 'K', 'L', 'M'] as $col) {
            $sheet->getStyle("{$col}:{$col}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        // N° de transferencia como texto: si no, Excel le come los ceros de adelante.
        $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode('@');

        return [];
    }
}
