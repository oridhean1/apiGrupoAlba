<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository;
use App\Http\Controllers\Tesoreria\Repository\TesExtractoBancariosRepository;
use App\Models\Tesoreria\TesConciliacionMatcheoEntity;
use App\Models\Tesoreria\TesExtractosBancariosEntity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class TesExtractosBacariosController extends Controller
{
    /**
     * Parsea el Excel y calcula el matcheo de cada fila, pero NO guarda nada en la base.
     * El guardado real ocurre una sola vez, en getGuardarConciliacion, cuando el usuario
     * confirma en "Cargar Data" después de revisar los checks.
     */
    public function getPrevisualizarExtracto(
        Request $request,
        TesConciliacionMatchingRepository $matching
    ) {
        try {
            $data = json_decode($request->data);
            $archivo = $request->file('archivo');

            if (!$archivo) {
                return response()->json([
                    'message' => 'Se necesita adjuntar un archivo para continuar.'
                ], 409);
            }

            if (empty($data->id_razon)) {
                return response()->json([
                    'message' => 'Debe seleccionar una razón social para importar el extracto.'
                ], 409);
            }

            $importacion = new \App\Imports\ExtractoBancarioCygnusImport($data->id_razon, $data->observaciones ?? null, $matching);
            Excel::import($importacion, $archivo);

            if ($importacion->message == 'INVALID') {
                return response()->json([
                    'message' => 'El archivo seleccionado no cumple con la estructura del modelo unificado.'
                ], 409);
            }

            $filas = $importacion->sheetImport->filasPreview ?? [];
            $conCandidato = count(array_filter($filas, fn ($f) => !empty($f['id_movimiento_match'])));

            return response()->json([
                'message' => $filas ? count($filas) . ' movimientos leídos, ' . $conCandidato . ' con sugerencia de conciliación.' : 'El archivo no tiene movimientos para importar.',
                'data' => $filas
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Guarda de una sola vez todas las filas que el usuario revisó en la previsualización.
     * Las tildadas ("aprobado") quedan CONCILIADO_MANUAL; el resto queda SUGERIDO/PENDIENTE
     * para revisión posterior desde el visor.
     */
    public function getGuardarConciliacion(Request $request)
    {
        try {
            $filas = $request->filas ?? [];
            if (empty($filas)) {
                return response()->json(['message' => 'No hay movimientos para guardar.'], 409);
            }

            DB::beginTransaction();

            $user = Auth::user();
            $fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
            $creados = 0;
            $aprobados = 0;
            $omitidosDuplicados = 0;

            foreach ($filas as $fila) {
                $fila = (object) $fila;
                $aprobar = (bool) ($fila->aprobado ?? false);
                $tieneCandidato = !empty($fila->id_movimiento_match);

                // Re-chequeo defensivo: puede haber pasado tiempo entre la previsualización y este guardado.
                $yaExiste = TesExtractosBancariosEntity::where('id_razon', $fila->id_razon)
                    ->where('banco', $fila->banco ?? null)
                    ->where('fecha', $fila->fecha ?? null)
                    ->where('concepto', $fila->concepto ?? null)
                    ->whereRaw('ABS(importe - ?) < 0.01', [$fila->importe ?? 0])
                    ->exists();

                if ($yaExiste) {
                    $omitidosDuplicados++;
                    continue;
                }

                $extracto = TesExtractosBancariosEntity::create([
                    'id_cuenta_bancaria' => $fila->id_cuenta_bancaria ?? null,
                    'id_razon' => $fila->id_razon,
                    'fecha' => $fila->fecha ?? null,
                    'banco' => $fila->banco ?? null,
                    'concepto' => $fila->concepto ?? null,
                    'importe' => $fila->importe ?? 0,
                    'saldo' => $fila->saldo ?? 0,
                    'referencia' => $fila->referencia ?? null,
                    'detalle' => $fila->detalle ?? null,
                    'detalle_nombre' => $fila->detalle_nombre ?? null,
                    'detalle_cuit' => $fila->detalle_cuit ?? null,
                    'estado_conciliacion' => $aprobar ? 'CONCILIADO_MANUAL' : ($tieneCandidato ? 'SUGERIDO' : 'PENDIENTE'),
                    'score_matching' => $fila->score_matching ?? null,
                    'id_movimiento_match' => $aprobar ? ($fila->id_movimiento_match ?? null) : null,
                    'id_usuario' => $user->cod_usuario ?? 1,
                    'fecha_registra' => $fechaActual,
                    'id_usuario_confirma' => $aprobar ? ($user->cod_usuario ?? null) : null,
                    'fecha_confirma' => $aprobar ? $fechaActual : null,
                    'observaciones' => $fila->observaciones ?? null,
                ]);
                $creados++;

                if ($tieneCandidato) {
                    TesConciliacionMatcheoEntity::create([
                        'id_extracto_bancario' => $extracto->id_extracto,
                        'id_movimiento_interno' => $fila->id_movimiento_match,
                        'tipo_origen_interno' => $fila->tipo_origen_interno ?? null,
                        'score_obtenido' => $fila->score_matching ?? 0,
                        'reglas_cumplidas' => $fila->reglas_cumplidas ?? [],
                        'estado' => $aprobar ? 1 : 0,
                        'id_usuario_aprobador' => $aprobar ? ($user->cod_usuario ?? null) : null,
                        'fecha_matching' => $fechaActual,
                    ]);
                }

                if ($aprobar) {
                    $aprobados++;
                }
            }

            DB::commit();

            $mensaje = $creados . ' movimientos cargados, ' . $aprobados . ' conciliados.';
            if ($omitidosDuplicados > 0) {
                $mensaje .= ' ' . $omitidosDuplicados . ' se omitieron por estar ya importados.';
            }

            return response()->json([
                'message' => $mensaje,
                'creados' => $creados,
                'aprobados' => $aprobados,
                'omitidos_duplicados' => $omitidosDuplicados
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getFiltrar(Request $request, TesExtractoBancariosRepository $repoExtracto)
    {
        return response()->json($repoExtracto->findByList(
            $request->desde,
            $request->hasta,
            $request->id_razon,
            $request->id_entidad_bancaria,
            $request->estado_conciliacion
        ));
    }

    public function getConfirmarMatch(Request $request, TesExtractoBancariosRepository $repoExtracto)
    {
        try {
            $extracto = $repoExtracto->findByConfirmarMatch(
                $request->id_extracto,
                (bool) $request->aprobar,
                $request->id_movimiento_interno,
                $request->observaciones
            );

            return response()->json([
                'message' => $request->aprobar ? 'Conciliación confirmada.' : 'Sugerencia rechazada, vuelve a pendiente.',
                'data' => $extracto
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
