<?php

namespace App\Exports;

use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\configuracion\RazonSocialModelo;
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
        // Mismo filtro que la grilla del Gestor de OPAs: lo que se ve es lo que se exporta.
        // Antes tenía su copia propia y pedía la relación `factura` (una OPA = una factura), que
        // dejó de existir cuando las OPAs pasaron a agrupar facturas: el export daba error 500
        // en Alba y OSV. (2026-10-20)
        $opas = app(TestOrdenPagoRepository::class)->getFiltroDinamico($this->params);

        $razones = RazonSocialModelo::pluck('razon_social', 'id_razon');

        $cuotasPorOpa = $this->cuotasDeOpas($opas->pluck('id_orden_pago')->all());

        // `tb_tes_orden_pago.fecha_probable_pago` ya no se llena: desde el circuito nuevo las fechas
        // de pago viven en el cronograma (`tb_tes_fecha_probable_pago`). Ordenar por esa columna
        // no ordenaba nada; se toma la primera fecha del cronograma. (T-00001236)
        $primeraFecha = fn($item) => optional($cuotasPorOpa->get($item->id_orden_pago, collect())->first())->fecha_probable_pago
            ?? $item->fecha_probable_pago;

        // Una fila por cada fecha de pago asignada, repitiendo los datos de la orden (pedido por
        // soporte en T-00001236). Una orden sin cronograma sale igual, en una sola fila.
        return $opas
            ->sortBy([
                fn($a, $b) => $a->id_estado_orden_pago <=> $b->id_estado_orden_pago,
                fn($a, $b) => $primeraFecha($b) <=> $primeraFecha($a),
            ])
            ->values()
            ->flatMap(function ($item) use ($razones, $cuotasPorOpa) {
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

                $fila = [
                    'id_orden_pago' => $item->id_orden_pago,
                    'numero_orden_pago' => $item->num_orden_pago,
                    'tipo_opa' => $item->tipo_opa,
                    'fecha_pago_asignada' => $item->fecha_probable_pago,
                    'importe_cuota' => '',
                    'fecha_confirmar_pago' => $item->fecha_confirma_pago,
                    'n_factura' => $facturas->pluck('numero')->filter()->unique()->implode(', '),
                    'tipo_beneficiario' => $item->tipo_factura,
                    'beneficiario' => $beneficiario ? $beneficiario->cuit . ' - ' . $beneficiario->razon_social : '',
                    'razon_social' => $razones[$idRazon] ?? '',
                    // Número, no texto: si llega como string Excel no le aplica el formato Contabilidad.
                    'monto' => (float) $item->monto_orden_pago,
                    'estado' => optional($item->estado)->descripcion_estado,
                    'observaciones' => $item->observaciones,
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
            'TIPO OPA',
            'FECHA DE PAGO ASIGNADA',
            'IMPORTE CUOTA',
            'FECHA CONFIRMAR PAGO',
            'N° FACTURA',
            'TIPO BENEFICIARIO',
            'BENEFICIARIO',
            'RAZÓN SOCIAL',
            'MONTO',
            'ESTADO',
            'OBSERVACIONES'
        ];
    }

    /**
     * Montos con formato Contabilidad (T-00001236). E = IMPORTE CUOTA, K = MONTO: si cambia el
     * orden de headings(), ajustar estas letras.
     */
    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_ACCOUNTING_USD,
            'K' => NumberFormat::FORMAT_ACCOUNTING_USD,
        ];
    }

    public function styles($excel)
    {
        return [
            'A1:BB1' => ['font' => ['bold' => true]],
        ];
    }
}
