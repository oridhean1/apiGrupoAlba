<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrdenesPagoExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles, WithColumnFormatting
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

        // Las facturas se leen del detalle, como en el Gestor OPAs: en las OPAs agrupadas la
        // cabecera tiene id_factura = NULL y el modelo ya no tiene la relación `factura`.
        $query = TesOrdenPagoEntity::with(['estado', 'opadetalle.detallefc.razonSocial', 'proveedor', 'prestador']);

        if (!is_null($this->params->tipo)) {
            $query->where('tipo_factura', $this->params->tipo);
        }

        if (!is_null($this->params->estado)) {
            $query->where('id_estado_orden_pago', $this->params->estado);
        }

        if (!is_null($this->params->monto_desde) && !is_null($this->params->monto_hasta)) {
            $query->whereBetween('monto_orden_pago', [$this->params->monto_desde, $this->params->monto_hasta]);
        }

        if (!is_null($this->params->desde) && !is_null($this->params->hasta)) {
            $query->whereBetween(DB::raw('DATE(fecha_genera)'), [$this->params->desde, $this->params->hasta]);
        }

        if (!is_null($this->params->beneficiario)) {
            $beneficiario = $this->params->beneficiario;
            $query->where(function ($q) use ($beneficiario) {
                $q->whereHas('proveedor', function ($subQuery) use ($beneficiario) {
                    $subQuery->where('razon_social', 'LIKE', "{$beneficiario}%");
                })->orWhereHas('prestador', function ($subQuery) use ($beneficiario) {
                    $subQuery->where('razon_social', 'LIKE', "{$beneficiario}%");
                });
            });
        }

        if (!is_null($this->params->id_locatorio)) {
            $locatorio = $this->params->id_locatorio;
            $query->where(function ($q) use ($locatorio) {
                $q->whereHas('opadetalle.detallefc', function ($subQuery) use ($locatorio) {
                    $subQuery->where('id_locatorio', $locatorio);
                });
            });
        }

        if ($this->params->pago_urgente == '1') {
            $query->where('pago_emergencia', $this->params->pago_urgente);
        }

        // `tb_tes_orden_pago.fecha_probable_pago` ya no se llena: desde el circuito nuevo las fechas
        // de pago viven en el cronograma (`tb_tes_fecha_probable_pago`). Ordenar por esa columna
        // no ordenaba nada; se toma la primera fecha del cronograma. (T-00001236)
        $opas = $query
            ->orderBy('id_estado_orden_pago')
            ->orderByRaw(
                'COALESCE('
                    . '(SELECT MIN(fp.fecha_probable_pago) FROM tb_tes_fecha_probable_pago fp'
                    . ' JOIN tb_tes_pago p ON p.id_pago = fp.id_pago'
                    . ' WHERE p.id_orden_pago = tb_tes_orden_pago.id_orden_pago'
                    . ' AND p.id_estado_orden_pago != ' . TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO . '),'
                    . ' tb_tes_orden_pago.fecha_probable_pago'
                . ') DESC'
            )
            ->get();

        $cuotasPorOpa = $this->cuotasDeOpas($opas->pluck('id_orden_pago')->all());

        // Una fila por cada fecha de pago asignada, repitiendo los datos de la orden (pedido por
        // soporte en T-00001236). Una orden sin cronograma sale igual, en una sola fila.
        return $opas->flatMap(function ($item) use ($cuotasPorOpa) {
            $facturas = $item->opadetalle->pluck('detallefc')->filter();
            $fila = [
                'id_orden_pago' => $item->id_orden_pago,
                'numero_orden_pago' => $item->num_orden_pago,
                'fecha_pago_asignada' => $item->fecha_probable_pago,
                'importe_cuota' => '',
                'fecha_confirmar_pago' => $item->fecha_confirma_pago,
                'n_factura' => $facturas->pluck('numero')->filter()->unique()->implode(' / '),
                'tipo_beneficiario' => $item->tipo_factura,
                'beneficiario' => ($benef = $item->proveedor ?? $item->prestador) ? $benef->cuit . ' - ' . $benef->razon_social : '',
                'proveedor' => $facturas->map(fn($f) => optional($f->razonSocial)->razon_social)->filter()->unique()->implode(' / '),
                // Número, no texto: si llega como string Excel no le aplica el formato Contabilidad.
                'monto' => (float) $item->monto_orden_pago,
                'descripcion' => $item->observaciones,
                'estado' => $item->estado->descripcion_estado,
                'observaciones'=>$item->observaciones
            ];

            $cuotas = $cuotasPorOpa->get($item->id_orden_pago, collect());
            if ($cuotas->isEmpty()) {
                return [$fila];
            }

            return $cuotas->map(function ($cuota) use ($fila, $cuotas, $item) {
                $fila['fecha_pago_asignada'] = $cuota->fecha_probable_pago;
                $fila['importe_cuota'] = $this->importeCuota($cuota, $cuotas->count(), $item->monto_orden_pago);
                return $fila;
            })->all();
        });
    }

    /**
     * Cronograma de pagos de cada orden: una fila por cuota, con lo emitido sobre ella.
     *
     * Al generar la orden solo se cargan las FECHAS; el importe de cada cuota recién existe cuando
     * Tesorería emite su pago (`tb_tes_pago_parcial`). Los abonos ANULADOS o RECHAZADOS no cuentan,
     * con el mismo criterio que `TesInstrumentoPagoRepository::fechasPendientesDeEmitir()`.
     */
    private function cuotasDeOpas(array $idsOpa)
    {
        if (empty($idsOpa)) {
            return collect();
        }

        $emitido = DB::table('tb_tes_pago_parcial')
            ->whereNotNull('id_fecha_probable')
            ->where(function ($w) {
                $w->whereNull('id_estado_instrumento')
                    ->orWhereNotIn('id_estado_instrumento', [
                        TesInstrumentoPagoRepository::RECHAZADO,
                        TesInstrumentoPagoRepository::ANULADO,
                    ]);
            })
            ->groupBy('id_fecha_probable')
            ->select('id_fecha_probable', DB::raw('SUM(monto_pago) as monto_emitido'));

        return DB::table('tb_tes_fecha_probable_pago as fp')
            ->join('tb_tes_pago as p', 'p.id_pago', '=', 'fp.id_pago')
            ->leftJoinSub($emitido, 'em', 'em.id_fecha_probable', '=', 'fp.id_fecha_probable')
            ->whereIn('p.id_orden_pago', $idsOpa)
            ->where('p.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->orderBy('fp.orden_cuotas')
            ->get(['p.id_orden_pago', 'fp.fecha_probable_pago', 'fp.orden_cuotas', 'em.monto_emitido'])
            ->groupBy('id_orden_pago');
    }

    /**
     * Importe de una cuota: lo emitido si ya se pagó; el total de la orden si es cuota única;
     * vacío si hay varias cuotas y esta todavía no se emitió (repartir sería inventar el dato).
     */
    private function importeCuota($cuota, int $cantidadCuotas, $montoOrden)
    {
        if (!is_null($cuota->monto_emitido)) {
            return (float) $cuota->monto_emitido;
        }

        return $cantidadCuotas === 1 ? (float) $montoOrden : '';
    }

    public function headings(): array
    {
        return [
            'ID ORDEN DE PAGO',
            'NUMERO ORDEN DE PAGO',
            'FECHA DE PAGO ASIGNADA',
            'IMPORTE CUOTA',
            'FECHA CONFIRMAR PAGO',
            'N° FACTURA',
            'TIPO BENEFICIARIO',
            'PRESTADOR',
            'OBRA SOCIAL',
            'MONTO',
            'DESCRIPCIÓN',
            'ESTADO',
            'OBSERVACIONES'
        ];
    }

    /**
     * Montos con formato Contabilidad (T-00001236). D = IMPORTE CUOTA, J = MONTO: si cambia el
     * orden de headings(), ajustar estas letras.
     */
    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_ACCOUNTING_USD,
            'J' => NumberFormat::FORMAT_ACCOUNTING_USD,
        ];
    }

    public function styles($excel)
    {
        return [
            'A1:BB1' => ['font' => ['bold' => true]],
        ];
    }
}
