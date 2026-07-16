<?php

namespace App\Imports;

use App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository;
use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesExtractosBancariosEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * Parsea el Excel y calcula el matcheo de cada fila SIN persistir nada en la base.
 * El guardado real ocurre recién cuando el usuario confirma en "Cargar Data"
 * (ver TesExtractosBacariosController::getGuardarConciliacion).
 */
class ExtractoBancarioCygnusSheetImport implements ToCollection, WithStartRow
{
    private $user;
    public $id_razon;
    public $obs;
    public $message = 'VALID';
    public $filasPreview = [];

    /** @var \Illuminate\Support\Collection cuentas bancarias de la razón social, con su entidadBancaria cargada */
    private $cuentasRazon;
    private TesConciliacionMatchingRepository $matching;

    public function __construct($idRazon, $obs, TesConciliacionMatchingRepository $matching)
    {
        $this->id_razon = $idRazon;
        $this->obs = $obs;
        $this->matching = $matching;
        $this->user = Auth::user();
        $this->cuentasRazon = TesCuentasBancariasEntity::with('entidadBancaria')
            ->where('id_razon', $idRazon)
            ->get();
    }

    public function collection(Collection $rows)
    {
        $nextRow = 1;
        foreach ($rows as $row) {
            // Saltamos encabezados o filas vacías si StartRow no lo atrapa
            if ($nextRow == 1 && (strtoupper(trim($row[0])) == 'FECHA' || strtoupper(trim($row[1])) == 'BANCO')) {
                $nextRow++;
                continue;
            }

            if ($this->message == 'VALID' && !empty($row[0])) {
                try {
                    $detalle = $row[6] ?? null;
                    [$detalleNombre, $detalleCuit] = $this->parseDetalle($detalle);
                    $bancoFila = $row[1] ?? '-';
                    $idCuentaBancaria = $this->resolverCuentaBancaria($bancoFila);

                    // Modelo transitorio (no se guarda) solo para poder reusar el motor de matching tal cual.
                    $extractoTransient = new TesExtractosBancariosEntity([
                        'id_cuenta_bancaria' => $idCuentaBancaria,
                        'id_razon' => $this->id_razon,
                        'fecha' => $this->formatFecha($row[0]),
                        'banco' => $bancoFila,
                        'concepto' => $row[2] ?? '-',
                        'importe' => $this->formatMonto($row[3] ?? 0),
                        'saldo' => $this->formatMonto($row[4] ?? 0),
                        'referencia' => $row[5] ?? null,
                        'detalle' => $detalle,
                        'detalle_nombre' => $detalleNombre,
                        'detalle_cuit' => $detalleCuit,
                    ]);

                    $resultado = $idCuentaBancaria
                        ? $this->matching->calcularMejorCandidato($extractoTransient)
                        : ['movimiento' => null, 'score' => 0, 'reglas' => [], 'tipo_origen' => null];

                    $mov = $resultado['movimiento'];

                    $this->filasPreview[] = [
                        'id_cuenta_bancaria' => $idCuentaBancaria,
                        'id_razon' => $this->id_razon,
                        'fecha' => $extractoTransient->fecha,
                        'banco' => $bancoFila,
                        'concepto' => $extractoTransient->concepto,
                        'importe' => $extractoTransient->importe,
                        'saldo' => $extractoTransient->saldo,
                        'referencia' => $extractoTransient->referencia,
                        'detalle' => $detalle,
                        'detalle_nombre' => $detalleNombre,
                        'detalle_cuit' => $detalleCuit,
                        'observaciones' => $this->obs,
                        'score_matching' => $resultado['score'],
                        'id_movimiento_match' => $mov?->id_movimiento,
                        'tipo_origen_interno' => $resultado['tipo_origen'],
                        'reglas_cumplidas' => $resultado['reglas'],
                        'ya_importado' => $this->yaImportado($bancoFila, $extractoTransient),
                        // Misma forma que usa el listado normal (matcheos[0].movimiento...) para reusar el front tal cual.
                        'matcheos' => $mov ? [[
                            'id_movimiento_interno' => $mov->id_movimiento,
                            'tipo_origen_interno' => $resultado['tipo_origen'],
                            'score_obtenido' => $resultado['score'],
                            'reglas_cumplidas' => $resultado['reglas'],
                            'movimiento' => $mov->toArray(),
                        ]] : [],
                    ];
                } catch (\Exception $e) {
                    Log::error('Error previsualizando fila ' . $nextRow . ': ' . $e->getMessage());
                }
            }

            $nextRow++;
        }
    }

    public function startRow(): int
    {
        return 2;  // Empezar en la fila 2 (asumiendo que la 1 son los encabezados)
    }

    /**
     * Resuelve la cuenta bancaria propia según el texto de la columna "banco" del Excel,
     * cruzando contra las cuentas de la razón social elegida. Si no hay match único
     * (0 o más de 1 coincidencia), devuelve null y esa fila queda sin candidatos para matchear.
     */
    public function resolverCuentaBancaria(?string $bancoFila): ?int
    {
        $bancoFila = trim((string) $bancoFila);
        if ($bancoFila === '' || $bancoFila === '-') {
            return null;
        }

        $coincidencias = $this->cuentasRazon->filter(function ($cuenta) use ($bancoFila) {
            $descripcion = trim($cuenta->entidadBancaria->descripcion_banco ?? '');
            if ($descripcion === '') {
                return false;
            }
            return stripos($descripcion, $bancoFila) !== false || stripos($bancoFila, $descripcion) !== false;
        });

        return $coincidencias->count() === 1 ? $coincidencias->first()->id_cuenta_bancaria : null;
    }

    /**
     * Detecta si esta fila ya fue importada antes (mismo banco/fecha/importe/concepto para la
     * misma razón social), para avisar en el preview y no duplicar al reimportar el mismo Excel.
     */
    public function yaImportado(string $bancoFila, TesExtractosBancariosEntity $extracto): bool
    {
        if (!$extracto->fecha) {
            return false;
        }

        return TesExtractosBancariosEntity::where('id_razon', $this->id_razon)
            ->where('banco', $bancoFila)
            ->where('fecha', $extracto->fecha)
            ->where('concepto', $extracto->concepto)
            ->whereRaw('ABS(importe - ?) < 0.01', [$extracto->importe])
            ->exists();
    }

    /**
     * Parsea el campo "detalle" con formato "NOMBRE | CUIT | INFO ADICIONAL".
     * Devuelve [nombre, cuit], cualquiera de los dos puede venir null si no está presente.
     */
    public function parseDetalle($detalle)
    {
        if (empty($detalle) || !is_string($detalle)) {
            return [null, null];
        }

        $partes = array_map('trim', explode('|', $detalle));

        $nombre = $partes[0] ?? null;
        $cuit = null;

        // El CUIT puede venir en cualquier segmento; nos quedamos con el primero de 11 dígitos.
        foreach ($partes as $parte) {
            $soloDigitos = preg_replace('/\D/', '', $parte);
            if (strlen($soloDigitos) === 11) {
                $cuit = $soloDigitos;
                break;
            }
        }

        return [$nombre ?: null, $cuit];
    }

    public function formatFecha($fecha)
    {
        if (!$fecha || trim($fecha) == '') {
            return null;
        }
        try {
            if (is_numeric($fecha)) {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fecha))->format('Y-m-d');
            }
            if (strpos($fecha, '/') !== false) {
                return Carbon::createFromFormat('d/m/Y', $fecha)->format('Y-m-d');
            }
            if (strpos($fecha, '-') !== false) {
                // Intentar varios formatos con guion
                return Carbon::parse($fecha)->format('Y-m-d');
            }
            return Carbon::parse($fecha)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::error('Error al convertir fecha: ' . $fecha);
            return null;
        }
    }

    public function formatMonto($valor)
    {
        if (!$valor)
            return 0;

        if (is_numeric($valor)) {
            return $valor;
        }

        // Limpiar formato string (Ej: "$ 1.500,50")
        $valor = str_replace('$', '', $valor);
        $valor = trim($valor);

        // Si tiene punto de miles y coma decimal
        if (strpos($valor, '.') !== false && strpos($valor, ',') !== false) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } elseif (strpos($valor, ',') !== false) {
            // Si solo tiene coma, asumimos que es decimal
            $valor = str_replace(',', '.', $valor);
        }

        return (float) $valor;
    }
}

class ExtractoBancarioCygnusImport implements WithMultipleSheets
{
    private $idRazon;
    private $obs;
    private TesConciliacionMatchingRepository $matching;
    public $message;
    public $sheetImport;

    public function __construct($idRazon, $obs, TesConciliacionMatchingRepository $matching)
    {
        $this->idRazon = $idRazon;
        $this->obs = $obs;
        $this->matching = $matching;
    }

    public function sheets(): array
    {
        $sheet = new ExtractoBancarioCygnusSheetImport($this->idRazon, $this->obs, $this->matching);
        $this->message = &$sheet->message;
        $this->sheetImport = $sheet;
        return [0 => $sheet];
    }
}
