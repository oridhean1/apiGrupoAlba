<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository;
use App\Http\Controllers\Tesoreria\Repository\TesExtractoBancariosRepository;
use App\Http\Controllers\Tesoreria\Repository\TesRegistroExtractoRepository;
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
            $conCandidato = count(array_filter($filas, fn ($f) => !empty($f['id_movimiento_match']) || !empty($f['id_pago_parcial_match'])));
            $yaImportados = count(array_filter($filas, fn ($f) => !empty($f['ya_importado'])));

            return response()->json([
                'message' => $filas
                    ? count($filas) . ' movimientos leídos, ' . $conCandidato . ' con sugerencia de conciliación.'
                        . ($yaImportados ? " {$yaImportados} ya estaban importados (no se vuelven a cargar)." : '')
                    : 'El archivo no tiene movimientos para importar.',
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
            $ocurrencias = [];
            $existentes = [];
            $movimientosAprobados = [];
            $abonosAprobados = [];
            $matching = app(\App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository::class);

            foreach ($filas as $fila) {
                $fila = (object) $fila;
                $aprobar = (bool) ($fila->aprobado ?? false);
                $idAbonoMatch = $fila->id_pago_parcial_match ?? null; // eCheq a acreditar (E4)
                $tieneCandidato = !empty($fila->id_movimiento_match) || !empty($idAbonoMatch);

                // Re-chequeo defensivo: puede haber pasado tiempo entre la previsualización y este guardado.
                // Se compara por CANTIDAD (ver contarIguales): la N-ésima línea igual del lote se omite
                // sólo si ya había N iguales guardadas ANTES de este lote (el conteo se cachea antes de insertar).
                $clave = TesExtractosBancariosEntity::claveLinea($fila->id_razon, $fila->banco ?? null, $fila->fecha ?? null, $fila->concepto ?? null, $fila->importe ?? 0);
                $ocurrencias[$clave] = ($ocurrencias[$clave] ?? 0) + 1;
                $existentes[$clave] ??= TesExtractosBancariosEntity::contarIguales(
                    $fila->id_razon, $fila->banco ?? null, $fila->fecha ?? null, $fila->concepto ?? null, $fila->importe ?? 0
                );
                $yaExiste = $ocurrencias[$clave] <= $existentes[$clave];

                if ($yaExiste) {
                    $omitidosDuplicados++;
                    continue;
                }

                // eCheq emitido: confirmarlo lo ACREDITA (asiento + débito) y se concilia contra el
                // movimiento que deja. R-00000689 P1.
                if ($aprobar && !empty($idAbonoMatch)) {
                    if (in_array($idAbonoMatch, $abonosAprobados)) {
                        throw new \Exception("El eCheq {$idAbonoMatch} está tildado en dos líneas del extracto. Dejá tildada una sola.");
                    }
                    $abonosAprobados[] = $idAbonoMatch;
                    $fila->id_movimiento_match = $matching->acreditarDesdeExtracto(new TesExtractosBancariosEntity([
                        'id_cuenta_bancaria' => $fila->id_cuenta_bancaria ?? null,
                        'fecha' => $fila->fecha ?? null,
                        'concepto' => $fila->concepto ?? null,
                        'referencia' => $fila->referencia ?? null,
                        'importe' => $fila->importe ?? 0,
                    ]), $idAbonoMatch);
                }

                // Sin movimiento no se concilia (se quitó el "conciliado sin contraparte", 2026-11-27).
                if ($aprobar && empty($fila->id_movimiento_match)) {
                    throw new \Exception('Hay una línea tildada sin movimiento de Tesorería para conciliar (' . ($fila->concepto ?? '') . '). Destildala y registrala desde el Visor.');
                }

                // Lo que se concilia en esta carga pasa por las mismas guardas que el visor.
                if ($aprobar && !empty($fila->id_movimiento_match)) {
                    if (in_array($fila->id_movimiento_match, $movimientosAprobados)) {
                        throw new \Exception("El movimiento {$fila->id_movimiento_match} está tildado en dos líneas del extracto. Dejá tildada una sola.");
                    }
                    $matching->validarParaConfirmar(new TesExtractosBancariosEntity([
                        'id_cuenta_bancaria' => $fila->id_cuenta_bancaria ?? null,
                        'importe' => $fila->importe ?? 0,
                    ]), $fila->id_movimiento_match);
                    $movimientosAprobados[] = $fila->id_movimiento_match;
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
                        'id_movimiento_interno' => $fila->id_movimiento_match ?? null,
                        'id_pago_parcial' => $idAbonoMatch,
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

    /** GET: conceptos para registrar una línea (sugerido, con/sin cuenta, cuentas destino). E5. */
    public function getConceptosBancarios(Request $request, TesRegistroExtractoRepository $repo)
    {
        try {
            return response()->json($repo->conceptosParaExtracto($request->id_extracto));
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 409);
        }
    }

    /** POST: registra la línea (movimiento + asiento) y la concilia. E5. */
    public function getRegistrarMovimientoExtracto(Request $request, TesRegistroExtractoRepository $repo)
    {
        try {
            $extracto = $repo->registrar($request->id_extracto, (string) $request->concepto, $request->id_cuenta_destino, $request->observaciones);
            return response()->json(['message' => 'Movimiento registrado y conciliado.', 'data' => $extracto]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 409);
        }
    }

    /** POST: anula un registro (contraasiento + movimiento inverso); la línea vuelve a pendiente. E5. */
    public function getAnularRegistroExtracto(Request $request, TesRegistroExtractoRepository $repo)
    {
        try {
            $extracto = $repo->anular($request->id_extracto, $request->motivo);
            return response()->json(['message' => 'Registro anulado. La línea volvió a pendiente.', 'data' => $extracto]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 409);
        }
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
