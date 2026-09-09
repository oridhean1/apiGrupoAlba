<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Exports\EcheqPendientesNumeroExport;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Ciclo de vida del instrumento de pago (eCheq).
 *
 * Convención de códigos en este controller:
 *  - 422 → falta un dato del request.
 *  - 409 → la operación choca contra una regla de negocio (número duplicado, faltan números,
 *          el instrumento ya se emitió). El mensaje del repositorio se devuelve tal cual porque
 *          está redactado para que lo lea el usuario de Pagos.
 *  - 500 → error inesperado. Acá NO se filtra el mensaje interno.
 */
class TesInstrumentoPagoController extends Controller
{
    protected $repository;
    protected $opaRepository;

    public function __construct(
        TesInstrumentoPagoRepository $repository,
        TestOrdenPagoRepository $opaRepository
    ) {
        $this->repository    = $repository;
        $this->opaRepository = $opaRepository;
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/pendientes-opa/{idOpa}
     *
     * Fechas del cronograma que todavia no tienen su pago emitido.
     */
    public function getPendientesDeOpa($idOpa)
    {
        try {
            return response()->json($this->repository->fechasPendientesDeEmitir($idOpa), 200);
        } catch (\Throwable $e) {
            Log::error('Error listar fechas pendientes de emitir: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar las fechas pendientes'], 500);
        }
    }

    /**
     * POST /v1/tesoreria/instrumentos-pago/emitir
     * Body: { id_fecha_probable, monto, id_forma_pago, id_cuenta_bancaria?, id_banco_emisor? }
     *
     * Emite UN pago sobre una fecha planificada. Aca se definen el monto y la forma de pago:
     * confirmar la orden solo dejo el cronograma.
     */
    public function getEmitirPago(Request $request)
    {
        try {
            $idFecha = $request->input('id_fecha_probable');

            if (!$idFecha) {
                return response()->json(['message' => 'id_fecha_probable es requerido'], 422);
            }

            if (!$request->filled('monto')) {
                return response()->json(['message' => 'El monto es requerido'], 422);
            }

            if (!$request->filled('id_forma_pago')) {
                return response()->json(['message' => 'La forma de pago es requerida'], 422);
            }

            // Un eCheq siempre se libra contra un banco: sin esto quedaba emitido con
            // id_banco_emisor NULL y nunca se podía saber contra qué cuenta cobrarlo (encontrado
            // el 2026-09-05 con un caso real en producción, OPA-1409).
            $esEcheq = (int) $request->input('id_forma_pago') === TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ;

            if ($esEcheq && !$request->filled('id_cuenta_bancaria') && !$request->filled('id_banco_emisor')) {
                return response()->json(['message' => 'Indicá el banco emisor del eCheq'], 422);
            }

            $abono = $this->repository->emitirPagoDeFecha($idFecha, [
                'monto'              => $request->input('monto'),
                'id_forma_pago'      => $request->input('id_forma_pago'),
                'id_cuenta_bancaria' => $request->input('id_cuenta_bancaria'),
                'id_banco_emisor'    => $request->input('id_banco_emisor'),
            ]);

            return response()->json(['message' => 'Pago emitido', 'data' => $abono], 201);
        } catch (QueryException $e) {
            Log::error('Error emitir pago: ' . $e->getMessage());
            return response()->json(['message' => 'Error al emitir el pago'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/validar-numero?numero=&id_pago=
     *
     * Validación en vivo mientras el usuario tipea. `id_pago` es opcional y sirve para que al
     * reeditar un número, el propio pago no se cuente como duplicado.
     *
     * Devuelve 200 siempre: "no disponible" es una respuesta válida, no un error.
     */
    public function getValidarNumero(Request $request)
    {
        try {
            $numero = $request->query('numero');
            $idPago = $request->query('id_pago');

            if (is_null($numero) || trim((string) $numero) === '') {
                return response()->json(['message' => 'numero es requerido'], 422);
            }

            $disponible = $this->repository->numeroEcheqDisponible($numero, $idPago);

            return response()->json([
                'disponible' => $disponible,
                'message'    => $disponible
                    ? 'Número disponible'
                    : 'Este número de eCheq ya está usado en otro pago',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error validar número de eCheq: ' . $e->getMessage());
            return response()->json(['message' => 'Error al validar el número'], 500);
        }
    }

    /**
     * PUT /v1/tesoreria/instrumentos-pago/{idPago}/numero
     * Body: { numero }
     *
     * Guarda el número como borrador. El instrumento NO avanza de estado.
     */
    public function getGuardarNumero(Request $request, $idPago)
    {
        try {
            $pago = $this->repository->guardarBorradorNumero($idPago, $request->input('numero'));

            return response()->json([
                'message' => 'Número guardado',
                'data'    => $pago,
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error guardar número de eCheq: ' . $e->getMessage());
            return response()->json(['message' => 'Error al guardar el número'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * PUT /v1/tesoreria/instrumentos-pago/{idPago}/forma-pago
     * Body: { id_forma_pago }
     */
    public function getCambiarFormaPago(Request $request, $idPago)
    {
        try {
            $forma = $request->input('id_forma_pago');

            if (!$forma) {
                return response()->json(['message' => 'id_forma_pago es requerido'], 422);
            }

            return response()->json([
                'message' => 'Forma de pago actualizada',
                'data'    => $this->repository->cambiarFormaPago($idPago, $forma),
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error cambiar forma de pago: ' . $e->getMessage());
            return response()->json(['message' => 'Error al cambiar la forma de pago'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * POST /v1/tesoreria/instrumentos-pago/confirmar-emision
     * Body: { id_orden_pago }
     *
     * Confirma TODOS los eCheq de la OP juntos. Falla si alguno quedó sin número.
     */
    public function getConfirmarEmision(Request $request)
    {
        try {
            $idOpa = $request->input('id_orden_pago');

            if (!$idOpa) {
                return response()->json(['message' => 'id_orden_pago es requerido'], 422);
            }

            $n = $this->repository->confirmarEmisionDeOpa($idOpa, $this->opaRepository);

            return response()->json([
                'message' => "Se confirmaron {$n} eCheq",
                'data'    => ['emitidos' => $n],
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error confirmar emisión de eCheq: ' . $e->getMessage());
            return response()->json(['message' => 'Error al confirmar la emisión'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * POST /v1/tesoreria/instrumentos-pago/{idPago}/acreditar
     * Body: { fecha_acreditacion }
     */
    public function getAcreditar(Request $request, $idPago)
    {
        try {
            $fecha = $request->input('fecha_acreditacion');

            if (!$fecha) {
                return response()->json(['message' => 'fecha_acreditacion es requerida'], 422);
            }

            $pago = $this->repository->marcarAcreditado($idPago, $fecha, $this->opaRepository);

            return response()->json([
                'message' => 'eCheq acreditado',
                'data'    => $pago,
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error acreditar eCheq: ' . $e->getMessage());
            return response()->json(['message' => 'Error al acreditar el eCheq'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * POST /v1/tesoreria/instrumentos-pago/{idPago}/rechazar
     * Body: { motivo_rechazo }
     *
     * Carga MANUAL del rechazo: no llega desde la conciliación bancaria.
     */
    public function getRechazar(Request $request, $idPago)
    {
        try {
            $motivo = $request->input('motivo_rechazo');

            if (is_null($motivo) || trim((string) $motivo) === '') {
                return response()->json(['message' => 'El motivo del rechazo es requerido'], 422);
            }

            $pago = $this->repository->marcarRechazado($idPago, $motivo, $this->opaRepository);

            return response()->json([
                'message' => 'eCheq marcado como rechazado',
                'data'    => $pago,
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error rechazar eCheq: ' . $e->getMessage());
            return response()->json(['message' => 'Error al rechazar el eCheq'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * PUT /v1/tesoreria/instrumentos-pago/{idPago}/editar
     *
     * Corrige la cuenta de origen y/o el monto de un pago que todavía no salió.
     *
     * El requerimiento lo habilita: *"mientras el pago esté creado pero sin confirmar, la orden
     * es editable por completo"*. Antes, corregir una cuenta mal elegida obligaba a anular y
     * reemitir la ORDEN entera, con número de OPA nuevo. (2026-09-07)
     */
    public function getEditarAbono(Request $request, $idPago)
    {
        try {
            $datos = [];

            // `array_key_exists`, no `filled`: mandar la cuenta en null es un cambio válido
            // (quitarla), y un monto ausente significa "no lo toques", que es distinto de cero.
            if ($request->has('id_cuenta_bancaria')) {
                $cuenta = $request->input('id_cuenta_bancaria');
                $datos['id_cuenta_bancaria'] = ($cuenta === '' || is_null($cuenta)) ? null : (int) $cuenta;
            }

            if ($request->has('monto')) {
                $datos['monto'] = $request->input('monto');
            }

            if (empty($datos)) {
                return response()->json(['message' => 'No se indicó qué cambiar del pago'], 422);
            }

            $pago = $this->repository->editarAbonoNoEmitido($idPago, $datos, $this->opaRepository);

            return response()->json([
                'message' => 'Pago actualizado',
                'data'    => $pago,
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error editar abono: ' . $e->getMessage());
            return response()->json(['message' => 'Error al editar el pago'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * POST /v1/tesoreria/instrumentos-pago/{idPago}/anular
     *
     * Anula un pago que todavía no salió y devuelve su fecha al plan, para reemitirla bien.
     *
     * Distinto de `getRechazar`: el rechazo es para un eCheq que YA salió y el banco devolvió.
     * Esto es para uno que nunca tendría que haber existido.
     */
    public function getAnularAbono(Request $request, $idPago)
    {
        try {
            $motivo = $request->input('motivo_rechazo');

            if (is_null($motivo) || trim((string) $motivo) === '') {
                return response()->json(['message' => 'El motivo de la anulación es requerido'], 422);
            }

            $pago = $this->repository->anularAbonoNoEmitido($idPago, $motivo, $this->opaRepository);

            return response()->json([
                'message' => 'Pago anulado. Su fecha vuelve a estar disponible para emitir.',
                'data'    => $pago,
            ], 200);
        } catch (QueryException $e) {
            Log::error('Error anular abono: ' . $e->getMessage());
            return response()->json(['message' => 'Error al anular el pago'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/pendientes-numero?id_banco=
     *
     * OPs vigentes con eCheq todavía sin número, agrupadas por banco emisor.
     *
     * `numero_opa` e `id_razon` (agregados el 2026-09-05, este ultimo corregido para filtrar
     * por la entidad pagadora del grupo -no por proveedor/prestador- filtran las tres vistas.
     */
    public function getPendientesDeNumero(Request $request)
    {
        try {
            $data = $this->repository->listarPendientesDeNumero(
                $request->query('id_banco'),
                $request->query('numero_opa'),
                $request->query('id_razon')
            );
            return response()->json($data, 200);
        } catch (\Exception $e) {
            Log::error('Error listar pendientes de número: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar los pagos pendientes'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/emitidos?id_banco=&numero_opa=&id_razon=
     *
     * eCheq ya emitidos: los que esperan acreditacion o pueden rechazarse.
     */
    public function getEmitidos(Request $request)
    {
        try {
            $data = $this->repository->listarEmitidos(
                $request->query('id_banco'),
                $request->query('numero_opa'),
                $request->query('id_razon')
            );
            return response()->json($data, 200);
        } catch (\Exception $e) {
            Log::error('Error listar eCheq emitidos: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar los eCheq emitidos'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/pendientes-numero/excel?id_banco=&numero_opa=&id_razon=
     */
    public function exportarExcelPendientes(Request $request)
    {
        try {
            return Excel::download(
                new EcheqPendientesNumeroExport(
                    $this->repository,
                    $request->query('id_banco'),
                    $request->query('numero_opa'),
                    $request->query('id_razon')
                ),
                'echeq-pendientes-emision.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Error exportar Excel de eCheq pendientes: ' . $e->getMessage());
            return response()->json(['message' => 'Error al generar el Excel'], 500);
        }
    }

    /**
     * GET /v1/tesoreria/instrumentos-pago/pendientes-numero/pdf?id_banco=&numero_opa=&id_razon=
     *
     * Es el papel que Pagos manda a Tesorería: sale agrupado por banco, con la columna del
     * número de eCheq en blanco para completar con lo que devuelva el banco.
     */
    public function exportarPdfPendientes(Request $request)
    {
        try {
            $pagos = $this->repository->listarPendientesDeNumero(
                $request->query('id_banco'),
                $request->query('numero_opa'),
                $request->query('id_razon')
            )['sin_numero'];

            $grupos = $pagos
                ->map(function ($p) {
                    return [
                        'banco'        => $p->bancoEmisor->descripcion_banco ?? 'SIN BANCO ASIGNADO',
                        'num_opa'      => $p->num_orden_pago,
                        'beneficiario' => TesInstrumentoPagoRepository::nombreBeneficiario($p->pago->opa ?? null),
                        'numero_echeq' => $p->numero_echeq ?? '',
                        'monto_raw'    => (float) $p->monto_pago,
                        'monto'        => number_format((float) $p->monto_pago, 2, ',', '.'),
                        'fecha_pago'   => $p->fecha_emision_echeq,
                    ];
                })
                ->groupBy('banco');

            $subtotales = $grupos->map(
                fn($g) => number_format($g->sum('monto_raw'), 2, ',', '.')
            )->all();

            $datos = [
                'grupos'          => $grupos,
                'subtotales'      => $subtotales,
                'total_general'   => number_format($pagos->sum('monto_pago'), 2, ',', '.'),
                'total_registros' => $pagos->count(),
                'fecha_emision'   => now('America/Argentina/Buenos_Aires')->format('d/m/Y H:i'),
            ];

            $pdf = Pdf::loadView('tesoreria.echeq_pendientes_numero', $datos);
            $pdf->setPaper('A4');

            return $pdf->download('echeq-pendientes-emision.pdf');
        } catch (\Exception $e) {
            Log::error('Error exportar PDF de eCheq pendientes: ' . $e->getMessage());
            return response()->json(['message' => 'Error al generar el PDF'], 500);
        }
    }
}
