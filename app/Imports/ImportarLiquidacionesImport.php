<?php

namespace App\Imports;

use App\Exports\DetalleLiquidacionesNoEncontradasExport;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\liquidaciones\LiqTipoMotivoDebitoEntity;
use App\Models\pratricaMatriz\PracticaMatrizEntity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Facades\Excel;

class ImportarLiquidacionesImport implements ToCollection, WithStartRow
{
    public $practicasNoEncontradas = [];
    public $detalleLiquidaciones = [];
    public $id_liquidacion;

    public function __construct($id_liquidacion)
    {
        $this->id_liquidacion = $id_liquidacion;
    }

    public function collection(Collection $rows)
    {
        $codigosPracticas = $rows->pluck(2)->unique()->values()->all();
        $codigosAfiliados = $rows->pluck(0)->unique()->values()->all();
        $codigosDebitos   = $rows->pluck(6)->merge($rows->pluck(7))->unique()->values()->all();

        // 2. Traer de la BD en bloque y preparar diccionarios
        $practicas = PracticaMatrizEntity::whereIn('codigo_practica', $codigosPracticas)
            ->get()
            ->keyBy('codigo_practica');

        $afiliados = AfiliadoPadronEntity::whereIn('cuil_benef', $codigosAfiliados)
            ->orWhereIn('dni', $codigosAfiliados)
            ->get()
            ->mapWithKeys(function ($af) {
                // Indexar tanto por CUIL como por DNI
                return [
                    $af->cuil_benef => $af,
                    $af->dni        => $af
                ];
            });

        $debitos = LiqTipoMotivoDebitoEntity::whereIn('patalogia_cie', $codigosDebitos)
            ->orWhereIn('id_tipo_motivo_debito', $codigosDebitos)
            ->get()
            ->mapWithKeys(function ($d) {
                return [
                    $d->patalogia_cie       => $d,
                    $d->id_tipo_motivo_debito => $d
                ];
            });

        foreach ($rows as $row) {
            $practica = $practicas[$row[2]] ?? null;
            $debito   = $debitos[$row[7]] ?? $debitos[$row[6]] ?? null;
            $afiliado = $afiliados[$row[0]] ?? null;
            $costo = str_replace(['$', ','], '', $row[4]);
            $monto = ($row[5] === null || $row[5] === '') ? 0 : str_replace(['$', ','], '', $row[5]);

            if (!is_null($row[2]) && !is_null($row[0])) {
                if ($practica && $afiliado) {
                    $this->detalleLiquidaciones[] = [
                        'id_liquidacion'           => $this->id_liquidacion,
                        'fecha_prestacion'         => $this->parseFecha($row[1]),
                        'id_identificador_practica' => $practica->id_identificador_practica,
                        'costo_practica'           => $costo,
                        'cantidad'                 => $row[3],
                        'porcentaje_hon'           => 100,
                        'porcentaje_gast'          => 100,
                        'monto_facturado'          => $costo,
                        'monto_aprobado'           => $monto?? 0,
                        'coseguro'                 => $row[9] ?? '0',
                        'debita_coseguro'          => '0',
                        'debita_iva'               => '0',
                        'id_tipo_motivo_debito'    => $debito->id_tipo_motivo_debito ?? null,
                        'observacion_debito'       => $row[8],
                        'monto_debitado'           => $row[6],
                        'id_afiliado'              => $afiliado->id,
                        'dni_afiliado'             => $afiliado->dni,
                        'hospital'                 =>null,
                        'periodo'                  =>null,
                        'tipo_hospital'            =>null,
                    ];
                } else {
                    $this->practicasNoEncontradas[] = [
                        'error'            => is_null($practica) ? 'Codigo Practica no encontrado'
                            : (is_null($afiliado) ? 'CUIL del Afiliado no existe'
                                : 'Codigo Motivo Debito no encontrado'),
                        'codigo'           => is_null($practica) ? $row[2]
                            : (is_null($afiliado) ? $row[0] : $row[7]),
                        'fecha_prestacion' => $row[1],
                        'monto_facturado'  => $row[4]
                    ];
                }
            }
        }

        if (!empty($this->practicasNoEncontradas)) {
            $this->generateLog();
        }
    }

    public function generateLog()
    {
        $filePath = 'public/logs/logs_imports_liquidaciones' . $this->id_liquidacion . '.xlsx';
        Excel::store(new DetalleLiquidacionesNoEncontradasExport($this->practicasNoEncontradas), $filePath);
    }

    public function startRow(): int
    {
        return 2;
    }

    public function parseFecha($fecha)
    {
        if (!$fecha || trim($fecha) == '') {
            return null;
        }

        try {

            if (is_numeric($fecha)) {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fecha))
                    ->format('Y-m-d');
            }

            if (strpos($fecha, '/') !== false) {
                return Carbon::createFromFormat('d/m/Y', $fecha)->format('Y-m-d');
            }
            return Carbon::createFromFormat('d-m-Y', $fecha)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
