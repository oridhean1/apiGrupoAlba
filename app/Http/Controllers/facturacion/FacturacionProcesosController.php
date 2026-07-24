<?php

namespace App\Http\Controllers\facturacion;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository;
use App\Http\Controllers\facturacion\repository\FacturaRepository;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Contabilidad\Repository\AsientosFacturacionHistorialRepository;
use App\Http\Controllers\Utils\ManejadorDeArchivosUtils;
use App\Http\Controllers\Utils\GeneradorCodigosUtils;
use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FacturacionProcesosController extends Controller
{

    public function postProcesarFactura(
        FacturaRepository $repo,
        TestOrdenPagoRepository $tesoreria,
        TesPagosRepository $tesPagosRepository,
        ManejadorDeArchivosUtils $storageFile,
        AsientoContableRepository $asientoContableRepository,
        AsientosFacturacionHistorialRepository $historialAsientosRepository,
        GeneradorCodigosUtils $generadorCodigos,
        PeriodosContablesRepository $periodoContableRepositorio,
        Request $request
    ) {
        DB::beginTransaction();
        try {
            $fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
            $user = Auth::user();

            $cabecera = json_decode($request->cabecera);
            $detalle = json_decode($request->detalle);
            $impuestos = json_decode($request->impuestos);
            $descuentos = json_decode($request->descuentos);

            // id_locatorio es el campo que el frontend usa para la razón social
            if (empty($cabecera->id_razon) && !empty($cabecera->id_locatorio)) {
                $cabecera->id_razon = $cabecera->id_locatorio;
            }

            $nombre_archivo = null;
            // @SUBIR ARCHIVO

            // $nombre_archivo = $storageFile->findBycargarArchivo("FACTURA_" . $cabecera->tipo_letra . $cabecera->numero . $cabecera->sucursal, 'facturacion/comprobantes', $request);
            if (empty($cabecera->id_factura)) {

                if ($repo->findByExistsFacturaPrestadorOrPrestador($cabecera)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "La factura que intenta registrar ya éxiste"
                    ], 409);
                }

                // @VALIDAR CONFIGURACIÓN CONTABLE ANTES DE PROCESAR LA FACTURA
                if (!is_null($cabecera->id_proveedor) && $cabecera->id_tipo_factura == 16) {
                    // Verificar que el proveedor tenga cuenta contable asignada
                    // if (!$asientoContableRepository->verificarProveedorTieneCuentaContable($cabecera->id_proveedor)) {
                    //     DB::rollBack();
                    //     return response()->json([
                    //         'message' => "No se puede procesar la factura. El proveedor seleccionado no tiene una cuenta contable asignada. Por favor, configure la relación proveedor-cuenta contable antes de continuar."
                    //     ], 422);
                    // }

                    // Verificar que tenga cuenta de gasto definida
                    // if (!$asientoContableRepository->verificarFamiliaTieneCuentaContable($cabecera->id_tipo_factura)) {
                    //     DB::rollBack();
                    //     return response()->json([
                    //         'message' => "No se puede procesar la factura. La imputacion seleccionada no tiene una cuenta contable asignada. Por favor, configure la relación imputacion-cuenta contable antes de continuar."
                    //     ], 422);
                    // }

                    // if (is_null($cabecera->id_tipo_imputacion_sintetizada)) {
                    //     DB::rollBack();
                    //     return response()->json([
                    //         'message' => "No se puede procesar la factura. Debe seleccionar una cuenta de imputación contable para el gasto."
                    //     ], 422);
                    // }
                }

                $facturacion = $repo->findBySaveDatosFactura($cabecera, $user->cod_usuario, $nombre_archivo);

                if (count($detalle) > 0) {
                    $repo->findBySaveDetalleFactura($detalle, $facturacion->id_factura);
                }

                if (count($impuestos) > 0) {
                    $repo->findBySaveDetalleImpuestoFactura($impuestos, $facturacion->id_factura);
                }

                if (count($descuentos) > 0) {
                    $repo->findBySaveDetalleDescuentosFactura($descuentos, $facturacion->id_factura);
                }

                if (count($request->archivos) > 0) {
                    $archivosAdjuntos = $storageFile->findByCargaMasivaArchivos("FACTURA_" . $cabecera->tipo_letra . $cabecera->numero . $cabecera->sucursal, 'facturacion/comprobantes', $request);
                    $repo->findBySaveDetalleComprobantesFactura($archivosAdjuntos, $facturacion->id_factura);
                }

                // ============================================================
                // CREAR ASIENTO CONTABLE AUTOMÁTICO
                // ============================================================
                // @CREAR ASIENTO CONTABLE AUTOMÁTICO PARA FACTURAS DE PROVEEDOR
                if ($cabecera->idImputacionDebe) {
                    if (empty($cabecera->id_razon)) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Falta la razón social para registrar el asiento contable. Por favor contacte con el administrador.'
                        ], 422);
                    }
                    //Período contable activo
                    try {
                        $formatoCorto = substr($cabecera->periodo, 2, 2) . substr($cabecera->periodo, 5, 2);
                        $periodoContableActivo = $periodoContableRepositorio->findByExistsPeriodoActivo($formatoCorto, $cabecera->id_razon ?? null);

                        if (!$periodoContableActivo) {
                            throw new \Exception("No se encontró un período contable activo para registrar el asiento contable de la factura.");
                        }
                    } catch (\Throwable $th) {
                        DB::rollBack();
                        return response()->json([
                            'message' => $th->getMessage()
                        ], 404);
                    }
                    // Obtener datos del proveedor desde la factura
                    $facturaConProveedor = $repo->findById($facturacion->id_factura);

                    $datosFactura = [
                        'id_factura'    => $facturacion->id_factura,
                        'id_proveedor'  => $facturacion->id_proveedor,
                        'id_prestador'  => $facturacion->id_prestador,
                        'id_razon'      => $cabecera->id_razon ?? null,
                        'cuit'          => $facturaConProveedor->proveedor->cuit ?? $facturaConProveedor->prestador->cuit,
                        'nombre'        => $facturaConProveedor->proveedor->razon_social ?? $facturaConProveedor->prestador->razon_social,
                        'numero_factura'=> $cabecera->tipo_letra . ' ' . $cabecera->sucursal . '-' . $cabecera->numero,
                        'fecha_registra'=> $facturacion->fecha_registra,
                        'total_factura' => $facturacion->total_neto,
                        'id_tipo_factura' => $facturacion->id_tipo_factura,
                        'idImputacionDebe' => $cabecera->idImputacionDebe,
                    ];
                    // Crear asiento contable (las validaciones ya se hicieron arriba)
                    try {
                        // Crear asiento contable
                        $asientoContable = $asientoContableRepository->crearAsientoFactura($datosFactura, $periodoContableActivo->id_periodo_contable);

                        // Guardar en historial como ALTA
                        $historialAsientosRepository->guardarHistorial(
                            $facturacion->id_factura,
                            $asientoContable->id_asiento_contable,
                            'ALTA',
                            false,
                            null,
                            'Asiento contable creado automáticamente al registrar la factura'
                        );

                    } catch (\Exception $e) {
                        DB::rollBack();

                        // Verificar si el error es específico de imputación/familia
                        if (strpos($e->getMessage(), 'imputación') !== false && strpos($e->getMessage(), 'cuenta contable asignada') !== false) {
                            return response()->json([
                                'message' => $e->getMessage()
                            ], 422);
                        }

                        // Para otros errores de validación contable
                        return response()->json([
                            'message' => $e->getMessage()
                        ], 423);
                    }
                }


                /* || $facturacion->id_tipo_factura == 17 */
                if (!is_null($facturacion->id_proveedor) || !is_null($facturacion->id_prestador)) {
                    $opaData = (object) [
                        "id_proveedor" => $facturacion->id_proveedor,
                        "id_prestador" => $facturacion->id_prestador,
                        "monto_orden_pago" => $facturacion->total_neto,
                        "id_moneda" => '1',
                        "fecha_emision" => $facturacion->fecha_comprobante,
                        "fecha_vencimiento" => $facturacion->fecha_vencimiento,
                        "fecha_probable_pago" => null,
                        "id_estado_orden_pago" => $facturacion->id_tipo_factura == 20 ? 2 : 1,
                        "monto_anticipado" => 0.00,
                        "observaciones" => '',
                        "id_factura" => $facturacion->id_factura,
                        "tipo_factura" => !is_null($facturacion->id_prestador) ? 'PRESTADOR' : 'PROVEEDOR'
                    ];
                    $opaCreada = $tesoreria->findByCreate($opaData);

                    // Auto-crear pago para facturas tipo 20
                    if ($facturacion->id_tipo_factura == 20) {
                        $pagoData = [
                            'id_orden_pago' => $opaCreada->id_orden_pago,
                            'id_cuenta_bancaria' => 1, // Usar cuenta bancaria por defecto o configurar según necesidad
                            'fecha_confirma_pago' => $fechaActual,
                            'anticipo' => '0',
                            'comprobante' => null,
                            'id_forma_pago' => 1, // Usar forma de pago por defecto o configurar según necesidad
                            'monto_pago' => $facturacion->total_neto,
                            'observaciones' => 'Pago automático para factura tipo 20',
                            'id_estado_orden_pago' => 1, //debe ser el estado de pago
                            'monto_opa' => $facturacion->total_neto,
                            'recursor' => '0',
                            'fecha_probable_pago' => $fechaActual,
                            'tipo_factura' => !is_null($facturacion->id_prestador) ? 'PRESTADOR' : 'PROVEEDOR',
                            'pago_emergencia' => '0'
                        ];

                        $boletaPago = $tesPagosRepository->findByCrearPago($pagoData);
                        $codigoVerificado = $generadorCodigos->getGenerarCodigoUnico($boletaPago->id_pago);
                        $tesPagosRepository->findByAsignarCodigoVerificacion($boletaPago->id_pago, $codigoVerificado);

                        // Actualizar estado de la OPA a "En proceso"
                        $tesoreria->findByUpdateEstado($opaCreada->id_orden_pago, 4);
                    }
                }
            } else {
                // ============================================================
                // MODIFICAR FACTURA EXISTENTE CON HISTORIAL CONTABLE
                // ============================================================

                // Verificar si la factura tiene asientos contables
                $tieneAsientos = $historialAsientosRepository->facturaTieneAsientos($cabecera->id_factura);


                $facturacion = $repo->findByUpdateDatosFactura($cabecera, $fechaActual, $nombre_archivo);

                if (count($detalle) > 0) {
                    $repo->findByUpdateDetalleFactura($detalle, $facturacion->id_factura);
                }

                $repo->findByDeleteDetalleImpuestos($facturacion->id_factura);
                if (count($impuestos) > 0) {
                    $repo->findBySaveDetalleImpuestoFactura($impuestos, $facturacion->id_factura);
                }

                $repo->findByDeleteDetalleDescuentosFactura($facturacion->id_factura);
                if (count($descuentos) > 0) {
                    $repo->findBySaveDetalleDescuentosFactura($descuentos, $facturacion->id_factura);
                }

                if (count($request->archivos) > 0) {
                    $archivosAdjuntos = $storageFile->findByCargaMasivaArchivos("FACTURA_" . $cabecera->tipo_letra . $cabecera->numero . $cabecera->sucursal, 'facturacion/comprobantes', $request);
                    $repo->findBySaveDetalleComprobantesFactura($archivosAdjuntos, $facturacion->id_factura);
                }

                // Procesar modificación contable si tiene asientos y datos contables
                if ($tieneAsientos && $cabecera->idImputacionDebe) {
                    if (empty($cabecera->id_razon)) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Falta la razón social para modificar el asiento contable. Por favor contacte con el administrador.'
                        ], 422);
                    }
                    try {
                        $formatoCorto = substr($cabecera->periodo, 2, 2) . substr($cabecera->periodo, 5, 2);
                        $periodoContableActivo = $periodoContableRepositorio->findByExistsPeriodoActivo($formatoCorto, $cabecera->id_razon ?? null);

                        if (!$periodoContableActivo) {
                            throw new \Exception("No se encontró un período contable activo para modificar el asiento contable de la factura.");
                        }

                        // Obtener datos actualizados de la factura
                        $facturaConProveedor = $repo->findById($facturacion->id_factura);

                        $nuevosDatosFactura = [
                            'id_factura'    => $facturacion->id_factura,
                            'id_proveedor'  => $facturacion->id_proveedor,
                            'id_prestador'  => $facturacion->id_prestador,
                            'id_razon'      => $cabecera->id_razon ?? null,
                            'cuit'          => $facturaConProveedor->proveedor->cuit ?? $facturaConProveedor->prestador->cuit,
                            'nombre'        => $facturaConProveedor->proveedor->razon_social ?? $facturaConProveedor->prestador->razon_social,
                            'numero_factura'=> $cabecera->tipo_letra . ' ' . $cabecera->sucursal . '-' . $cabecera->numero,
                            'fecha_registra'=> $facturacion->fecha_registra,
                            'total_factura' => $facturacion->total_neto,
                            'id_tipo_factura' => $facturacion->id_tipo_factura,
                            'idImputacionDebe' => $cabecera->idImputacionDebe,
                        ];

                        // Procesar modificación contable
                        $historialAsientosRepository->procesarModificacionFactura(
                            $facturacion->id_factura,
                            $nuevosDatosFactura,
                            $periodoContableActivo->id_periodo_contable,
                            'Asiento modificado por actualización de datos de factura'
                        );

                    } catch (\Exception $e) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Error al procesar la modificación contable: ' . $e->getMessage()
                        ], 423);
                    }
                }

                //@UPDATE OPA Y PRE-PAGO TESORERIA
                $opa = $tesoreria->findByIdFacturaEnProcesoOrPendiente($facturacion->id_factura, $facturacion->total_neto);
                if ($opa != null) {
                    $tesPagosRepository->findByUpdateOpaPagoFacturaLiquidaciones($opa->id_orden_pago, $facturacion->total_neto);
                }
            }

            DB::commit();
            return response()->json(["message" => "Factura procesada correctamente"]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getBuscarFacturaId(FacturaRepository $repo, Request $request)
    {
        return response()->json($repo->findByIdFactura($request->id));
    }

    public function deleteFacturaDetalle(
        AsientosFacturacionHistorialRepository $historialAsientosRepository,
        Request $request
    ) {
        DB::beginTransaction();
        try {
            $factura = FacturacionDatosEntity::find($request->id_factura);

            if (!$factura) {
                return response()->json([
                    'message' => 'Factura no encontrada'
                ], 404);
            }

            // Verificar si la factura tiene asientos contables
            $tieneAsientos = $historialAsientosRepository->facturaTieneAsientos($request->id_factura);

            if ($tieneAsientos) {
                // Procesar anulación contable
                try {
                    $historialAsientosRepository->procesarAnulacionFactura(
                        $request->id_factura,
                        'Factura anulada por el usuario'
                    );
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Error al anular asientos contables: ' . $e->getMessage()
                    ], 423);
                }
            }

            // Cambiar estado de la factura a anulada
            $factura->estado = '4';
            $factura->update();

            DB::commit();
            return response()->json([
                "message" => "La Factura N° " . $factura->num_liquidacion . " fue anulada correctamente"
            ]);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al procesar la anulación: ' . $th->getMessage()
            ], 500);
        }
    }

    public function getBuscarNumeroFactura(FacturaRepository $repo, Request $request)
    {
        return response()->json($repo->findByNumeroFactura($request->num_factura));
    }

    public function getListarDetalleComprobantes(FacturaRepository $repo, Request $request)
    {
        return response()->json($repo->findByListDetalleArchivos($request->id));
    }

    public function getVerAdjunto(FacturaRepository $pago, ManejadorDeArchivosUtils $storageFile, Request $request)
    {
        $path = "facturacion/comprobantes/";
        $data = $pago->findByIdDetalleArchivo($request->id);
        $anioTrabaja = Carbon::parse($data->fecha_carga)->year;
        $path .= "{$anioTrabaja}/$data->archivo";

        return $storageFile->findByObtenerArchivo($path);
    }

    public function getEliminarAdjunto(Request $request, FacturaRepository $pago)
    {
        $pago->findByIdDeleteDetalleArchivo($request->id);

        return response()->json(["message" => "Adjunto eliminado con éxito"]);
    }

    public function printComprobanteFacturacion(FacturaRepository $repo, Request $request)
    {
        $factura = $repo->findByIdFactura($request->id);

        $neto = 0.0;
        $impuestos = 0.0;

        foreach ($factura->detalle as $item) {
            $neto += $item->subtotal;
        }

        foreach ($factura->impuesto as $item) {
            $impuestos += $item->importe;
        }

        $datos = [
            "comprobante_nro" => $factura->numero,
            "tipoComprobante" => $factura->tipoComprobante->descripcion,
            "tipo_letra" => $factura->tipo_letra,
            "fecha_emision" => $factura->fecha_comprobante,
            "fecha_vencimiento" => $factura->fecha_vencimiento,
            "cuit_proveedor" => $factura->proveedor ? $factura->proveedor?->cuit : $factura->prestador?->cuit,
            "nombre_proveedor" => $factura->proveedor ? $factura->proveedor?->razon_social : $factura->prestador?->razon_social,
            "iva_proveedor" => $factura->proveedor ? $factura->proveedor?->tipoIva?->descripcion_iva : $factura->prestador?->tipoIva?->descripcion_iva,
            "periodo" => $factura->periodo,
            "tipo" => $factura->tipoFactura->descripcion,
            "sucursal" => $factura->sucursal,
            "numero" => $factura->numero,
            "detalle" => $factura->detalle,
            "impuesto" => $factura->impuesto,
            "neto" => $neto,
            "impuestos" => $impuestos,
            "descuentos" => $factura->total_debitado_liquidacion,
            "total" => $neto + $impuestos,
            "locatario" => $factura->cod_sindicato,
            'razon_social' => $factura->razonSocial,
            'id_factura' => $factura->id_factura,
            'fecha_confirma_pago' => $factura->opa?->fechapagos?->fecha_probable_pago,
            "codigo_opa" => !empty($factura->opa) > 0 ? $factura->opa->num_orden_pago : '000',
        ];

        $pdf = Pdf::loadView('comprobante-facturacion', $datos);
        $pdf->setPaper('A4');
        return $pdf->download('comprobante-facturacion' . $factura->numero . '.pdf');
    }

    public function selectComprobanteRelacionado(Request $request)
    {
        $query = FacturacionDatosEntity::with(['proveedor', 'prestador', 'razonSocial'])->whereIn('id_tipo_comprobante', [3, 4, 5]);

        if (!empty($request->id_proveedor)) {
            $query->where('id_proveedor', $request->id_proveedor);
        }

        if (!empty($request->id_prestador)) {
            $query->where('id_prestador', $request->id_prestador);
        }


        $facturas = $query->get();

        return response()->json($facturas);
    }
}
