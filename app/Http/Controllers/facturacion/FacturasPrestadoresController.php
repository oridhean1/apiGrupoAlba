<?php

namespace App\Http\Controllers\facturacion;

use App\Exports\Facturas\FacturaPrestadorExport;
use App\Exports\Facturas\FacturaProveedorExport;
use App\Http\Controllers\facturacion\repository\FacturasPrestadoresRepository;
use App\Http\Controllers\liquidaciones\repository\LiquidacionesDetalleRepository;
use App\Http\Controllers\liquidaciones\repository\LiquidacionesRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Models\Tesoreria\TesEstadoOrdenPagoEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class FacturasPrestadoresController extends Controller
{
    public function getFacturasPrestadores(FacturasPrestadoresRepository $repo, Request $request)
    {
        $data = [];

        /* if ($request->estado === '9' && !is_null($request->cuit_prestador) && is_null($request->razon_social)) {
            $data = $repo->findByListCuitPrestadorCuit($request->desde, $request->hasta, $request->cuit_prestador);
        } else if ($request->estado === '9' && is_null($request->cuit_prestador) && !is_null($request->razon_social)) {
            $data = $repo->findByListCuitPrestadorRazonSocial($request->desde, $request->hasta, $request->razon_social);
        } else if ($request->estado !== '9' && is_null($request->cuit_prestador) && is_null($request->razon_social)) {
            $data = $repo->findByListEstado($request->desde, $request->hasta, $request->estado);
        } else {
            $data = $repo->findByListAlls($request->desde, $request->hasta, ['0', '1', '2', '3', '4', '5']);
    } */

        //Probando traer comprobantes con metodo nuevo
        // $data = $repo->findByListAlls($request);
        $data = $repo->findByListAllsWithComprobantes($request);

        return response()->json($data);
    }


    public function getFacturasPrestadorLiquidaciones(FacturasPrestadoresRepository $repo, Request $request)
    {
        $data = [];

        /* if ($request->estado === '9' && !is_null($request->cuit_prestador) && is_null($request->num_factura)) {
            $data = $repo->findByListCuitPrestadorCuit($request->desde, $request->hasta, $request->cuit_prestador);
        } else if ($request->estado === '9' && is_null($request->cuit_prestador) && !is_null($request->num_factura)) {
            $data = $repo->findByListCuitPrestadorAndNumFactura($request->desde, $request->hasta, $request->num_factura);
        } else if ($request->estado !== '9' && is_null($request->cuit_prestador) && is_null($request->num_factura)) {
            $data = $repo->findByListEstado($request->desde, $request->hasta, $request->estado);
        } else {
            //$data = $repo->findByListAlls($request->desde, $request->hasta, ['1', '2', '3', '0', '5']);
        } */
        $data = $repo->findByListAlls($request);
        return response()->json($data);
    }


    public function getActualizarEstadoLiquidacion(
        FacturasPrestadoresRepository $repo,
        LiquidacionesRepository $repoLiqui,
        LiquidacionesDetalleRepository $repoDetalle,
        TestOrdenPagoRepository $opa,
        Request $request
    ) {
        try {
            DB::beginTransaction();

            // @BLOQUEO / ANULACIÓN EN CASCADA
            // Una vez generada la OPA, la factura/liquidación no puede cambiar de estado libremente
            // (sin esta guarda, volver a marcar "valorización final" generaba una SEGUNDA OPA).
            // Excepción: ANULAR la factura (estado 4) sí se permite, y en ese caso se anula también
            // la OPA — que es lo que faltaba y dejó órdenes de pago vivas de facturas anuladas.
            // Una OPA RECHAZADA no bloquea: se considera muerta.
            //
            // Segunda excepción (2026-08-13): si la OPA sigue PENDIENTE y no tiene ningún pago
            // registrado, todavía no la tocó nadie de Tesorería — no hay nada que proteger. Se
            // permite el cambio de estado, y si es una re-valorización (estado 3) el monto de
            // esa factura dentro de la OPA se actualiza al nuevo total (ver más abajo).
            $opaVigente = $opa->findByOpaVigenteFactura($request->factura);
            if (!is_null($opaVigente)) {
                if ($request->estado == '4') {
                    // Anular siempre pasa por la cascada, sin importar el estado de la OPA —
                    // una pendiente-sin-tocar también necesita rechazarse, no puede quedar huérfana.
                    $motivo = trim('Anulación de la factura. ' . ($request->motivo_anulacion ?? ''));
                    $resultado = $opa->findByAnularOpaDeFactura($request->factura, $motivo);

                    if (!$resultado['anulada']) {
                        DB::rollBack();
                        return response()->json(['message' => $resultado['message']], 409);
                    }
                    $opaVigente = null;
                } else {
                    $opaPendienteSinTocar = $opaVigente->id_estado_orden_pago == TestOrdenPagoRepository::ESTADO_OPA_PENDIENTE
                        && !$opa->tienePagosVivos($opaVigente->id_orden_pago);

                    if (!$opaPendienteSinTocar) {
                        DB::rollBack();
                        $estadoOpa = TesEstadoOrdenPagoEntity::find($opaVigente->id_estado_orden_pago);
                        return response()->json([
                            'message' => 'No se puede cambiar el estado: la factura ya tiene la orden de pago '
                                . $opaVigente->num_orden_pago . ' en estado ' . ($estadoOpa->descripcion_estado ?? $opaVigente->id_estado_orden_pago)
                                . '. Para modificarla, primero debe anularse o rechazarse esa orden de pago.'
                        ], 409);
                    }
                }
            }

            $liquidaciones = $repoLiqui->findByLiquidacionFactura($request->factura);

            $repo->findByUpdateEstado($request->factura, $request->estado);

            /* if (!empty($liquidaciones)) {
                $repoDetalle->findByUpdateDetalleEstado($request->estado, $liquidaciones->id_liquidacion);
            } */
            if (count($liquidaciones) > 0) {
                $ids = collect($liquidaciones)->pluck('id_liquidacion')->toArray();
                $repoDetalle->findByUpdateDetalleEstado($request->estado, $ids);
                /* foreach ($liquidaciones as $key) {
                    $repoDetalle->findByUpdateDetalleEstado($request->estado, $key->id_liquidacion);
                } */
            }

            $message = $request->estado === '4' ? 'Factura ANULADO correctamente'
                : ($request->estado === '2' ? 'Factura CERRADA correctamente'
                    : ($request->estado === '1' ? 'Factura se reabrio correctamente'
                        : ($request->estado === '3' ? 'Se asigno la VALORIZACION FINAL correctamente' : 'Factura en proceso de AUDITORIA')));

            // @VALORIZACIÓN FINAL YA NO GENERA LA OPA (2026-08-13)
            // Antes, al pasar a estado 3 se creaba la OPA automáticamente. Eso impedía que
            // Liquidaciones pudiera reabrir una factura valorizada por error: el bloqueo de
            // arriba (findByOpaVigenteFactura) no deja cambiar de estado si hay OPA vigente.
            // Ahora la factura queda en Valorización Final SIN OPA, y la OPA se genera a demanda
            // desde el visor (checkbox + botón "Generar OPA" -> findByIdFacturaMultiple).
            // Mientras nadie la genere, reabrir sigue siendo posible.
            //
            // Si $opaVigente sigue seteada acá, es porque pasó el guard de arriba como
            // "pendiente y sin tocar" — se le refresca el monto a lo que quedó la re-valorización.
            if ($request->estado == '3' && !is_null($opaVigente)) {
                $facturaDb = $repo->findByFacturaId($request->factura);
                $opa->findByRevalorizarOpaFactura($opaVigente->id_orden_pago, $request->factura, $facturaDb->total_neto);
            }
            DB::commit();
            return response()->json(["message" => $message]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getFacturaCompletaId(FacturasPrestadoresRepository $repo, Request $request)
    {
        return response()->json($repo->findByFacturaId($request->id));
    }

    public function updateDetalleCarga(FacturasPrestadoresRepository $repo, Request $request)
    {
        $repo->findByUpdateTipodetalleAndIdFactura($request->tipo, $request->id);
        return response()->json(["message" => "Tipo detalle cambiado correctamente"]);
    }

    public function updateImputacion(FacturasPrestadoresRepository $repo, Request $request)
    {
        $repo->findByUpdateImputacionAndIdFactura($request->tipo, $request->id);
        return response()->json(["message" => "Imputación contable cambiado correctamente"]);
    }

    // R-00000700: exportando sin filtros (todos los comprobantes) PhpSpreadsheet supera los 128M
    // por defecto (~142M con ~4.300 filas de detalle en nov/2026). Se sube el límite solo para estas exportaciones.
    public function getExportFacturaPrestador(Request $request)
    {
        ini_set('memory_limit', '512M');
        return Excel::download(new FacturaPrestadorExport($request), 'facturacionPrestador.xlsx');
    }

    public function getExportFacturaProveedor(Request $request)
    {
        ini_set('memory_limit', '512M');
        return Excel::download(new FacturaProveedorExport($request), 'facturacionProveedor.xlsx');
    }

    public function getGenerarMultipleOpa(
        TestOrdenPagoRepository $opa,
        Request $request
    ) {
        try {
            DB::beginTransaction();
            $opaGenerada = $opa->findByIdFacturaMultiple($request->facturas);
            DB::commit();

            $cantidad = count((array) $request->facturas);
            $detalle = $cantidad > 1 ? "con {$cantidad} facturas" : 'para la factura seleccionada';

            return response()->json([
                "message" => "Se generó la orden de pago {$opaGenerada->num_orden_pago} {$detalle}.",
                "id_orden_pago" => $opaGenerada->id_orden_pago,
                "num_orden_pago" => $opaGenerada->num_orden_pago,
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function findAddFacturaMultiple(
        TestOrdenPagoRepository $repo,
        Request $request
    ) {
        try {
            DB::beginTransaction();

            $result = $repo->findAddFacturaMultiple($request);

            if (!$result['success']) {
                DB::rollBack();
                return response()->json($result, 400);
            }

            DB::commit();

            return response()->json($result);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getRemoveMultipleOpa(
        TestOrdenPagoRepository $opa,
        Request $request
    ) {
        try {
            DB::beginTransaction();
            $opa->findRemoveFacturaMultiple($request);
            DB::commit();
            return response()->json(["message" => "Se genero una nueva OPA"]);
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
