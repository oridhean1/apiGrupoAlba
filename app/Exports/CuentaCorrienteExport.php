<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\TesCuentaCorrienteRepository;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cuenta corriente de un beneficiario a Excel, con UN solo saldo: el económico o el financiero.
 *
 * Mica pidió que se exporte uno u otro, no los dos a la vez (2026-10-05). Respeta los mismos
 * filtros que la pantalla —criterio de fecha y período, con saldo anterior—.
 *
 * Cada pago sale en UN RENGLÓN POR FACTURA QUE CANCELA, con el saldo bajando de a una: así la
 * factura cancelada queda nombrada y la planilla se puede filtrar por factura. Lo que canceló cada
 * pago sale del mismo recorrido FIFO que calcula los saldos (`movimientosDosSaldos`).
 */
class CuentaCorrienteExport implements FromArray, ShouldAutoSize, WithStyles
{
    const SALDO_ECONOMICO  = 'economico';
    const SALDO_FINANCIERO = 'financiero';

    private int $filaEncabezado = 1;

    public function __construct(
        private TesCuentaCorrienteRepository $cc,
        private $idBeneficiario,
        private string $tipo,
        private ?string $desde,
        private ?string $hasta,
        private ?string $criterio,
        private string $saldo
    ) {
        $this->saldo = $saldo === self::SALDO_FINANCIERO ? self::SALDO_FINANCIERO : self::SALDO_ECONOMICO;
    }

    public function array(): array
    {
        $datos = $this->cc->cuentaCorriente($this->idBeneficiario, $this->tipo, $this->desde, $this->hasta, $this->criterio);
        $campoSaldo = $this->saldo === self::SALDO_FINANCIERO ? 'saldo_financiero' : 'saldo_economico';
        $bajaFlag   = $this->saldo === self::SALDO_FINANCIERO ? 'baja_financiero' : 'baja_economico';

        $campo = strtoupper($this->tipo) === 'PROVEEDOR' ? 'cod_proveedor' : 'cod_prestador';
        $tabla = strtoupper($this->tipo) === 'PROVEEDOR' ? 'tb_proveedor' : 'tb_prestador';
        $benef = DB::table($tabla)->where($campo, $this->idBeneficiario)->first();

        $criterios = [
            'recepcion'   => 'Recepción de la factura',
            'comprobante' => 'Fecha del comprobante',
            'emision_opa' => 'Emisión de la OPA',
        ];

        $filas = [
            ['Cuenta corriente', $benef->razon_social ?? '', 'CUIT ' . ($benef->cuit ?? '')],
            ['Saldo', $this->saldo === self::SALDO_FINANCIERO
                ? 'FINANCIERO — baja cuando la plata sale del banco (eCheq: al acreditarse)'
                : 'ECONÓMICO — baja cuando el pago se imputa a la factura'],
            ['Período', ($this->desde ?: 'inicio') . ' a ' . ($this->hasta ?: 'hoy'),
                'Filtrado por: ' . ($criterios[$datos['resumen']['criterio'] ?? 'recepcion'] ?? '')],
            [],
            ['Fecha', 'Tipo', 'Comprobante', 'OPA', 'Factura cancelada', 'Debe', 'Haber',
                'Pago pactado', 'Acreditado', 'Estado', 'Saldo'],
        ];
        $this->filaEncabezado = count($filas);

        $saldo = null;

        foreach ($datos['movimientos'] as $m) {
            if ($m['tipo'] === 'SALDO_ANTERIOR') {
                $saldo = (float) $m[$campoSaldo];
                $filas[] = [$m['fecha'], 'SALDO ANTERIOR', $m['detalle'], '', '', '', '', '', '', '', round($saldo, 2)];
                continue;
            }

            if ($m['tipo'] === 'FACTURA' || empty($m['facturas'])) {
                $saldo = (float) $m[$campoSaldo];
                $filas[] = [
                    $m['fecha'], $m['tipo'], $m['detalle'], $m['num_orden_pago'] ?? '',
                    $m['tipo'] === 'FACTURA' ? str_replace('Factura ', '', $m['detalle']) : '',
                    $m['debe'] ?: '', $m['haber'] ?: '', $m['fecha_pactada'] ?? '', $m['fecha_acreditada'] ?? '',
                    $m['estado'] ?? '', round($saldo, 2),
                ];
                continue;
            }

            // Un renglón por factura cancelada. El saldo baja de a una; si el pago no afecta el
            // saldo elegido (un eCheq emitido, en el financiero), queda igual en todos.
            $base = (float) $m[$campoSaldo] + ($m[$bajaFlag] ? (float) $m['haber'] : 0.0);
            foreach ($m['facturas'] as $f) {
                if ($m[$bajaFlag]) {
                    $base -= (float) $f['monto'];
                }
                $filas[] = [
                    $m['fecha'], $m['tipo'], $m['detalle'], $m['num_orden_pago'] ?? '', $f['numero'],
                    '', (float) $f['monto'], $m['fecha_pactada'] ?? '', $m['fecha_acreditada'] ?? '',
                    $m['estado'] ?? '', round($base, 2),
                ];
            }
            $saldo = (float) $m[$campoSaldo];
        }

        return $filas;
    }

    public function styles(Worksheet $sheet)
    {
        $h = $this->filaEncabezado;

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("A{$h}:K{$h}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF9']],
        ]);
        foreach (['F', 'G', 'K'] as $col) {
            $sheet->getStyle("{$col}:{$col}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        // N° de factura como texto: si no, Excel le come los ceros de adelante (00000067).
        $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode('@');

        return [];
    }
}
