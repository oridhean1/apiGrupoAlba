<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Contabilidad\Repository\AsientoContableRepository;
use App\Http\Controllers\Contabilidad\Repository\AsientosPagoHistorialRepository;
use App\Http\Controllers\Contabilidad\Repository\FormaPagoCuentaContableRepository;
use App\Http\Controllers\Contabilidad\Repository\PeriodosContablesRepository;
use App\Http\Controllers\Contabilidad\Repository\ProveedorPlanesCuentaRepository;
use App\Http\Controllers\facturacion\repository\FacturaRepository;
use App\Http\Controllers\Tesoreria\Dto\PagosDto;
use App\Http\Controllers\Tesoreria\Repository\TesCuentaCatalogoRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository;
use App\Http\Controllers\Tesoreria\Repository\TesPagosRepository;
use App\Http\Controllers\Tesoreria\Repository\TestOrdenPagoRepository;
use App\Http\Controllers\Utils\CorrelativosOspfRepository;
use App\Http\Controllers\Utils\GeneradorCodigosUtils;
use App\Http\Controllers\Utils\ManejadorDeArchivosUtils;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TesPagosController extends Controller
{
    private $periodoContableRepositorio;
    private $proveedorPlanesCuentaRepository;
    private $formaPagoCuentaContableRepository;
    private $correlativosOspfRepository;

    public function __construct(
        PeriodosContablesRepository $periodoContableRepositorio,
        ProveedorPlanesCuentaRepository $proveedorPlanesCuentaRepository,
        FormaPagoCuentaContableRepository $formaPagoCuentaContableRepository,
        CorrelativosOspfRepository $correlativosOspfRepository
    ) {
        $this->periodoContableRepositorio = $periodoContableRepositorio;
        $this->proveedorPlanesCuentaRepository = $proveedorPlanesCuentaRepository;
        $this->formaPagoCuentaContableRepository = $formaPagoCuentaContableRepository;
        $this->correlativosOspfRepository = $correlativosOspfRepository;
    }


    public function getListarTipoFormaPago(TesCuentaCatalogoRepository $repository)
    {
        return response()->json($repository->findByListTipoFormaPagos());
    }

    public function getCrearPago(Request $request, TesPagosRepository $pago, TestOrdenPagoRepository $opa, TesCuentasBancariasRepository $cuenta, GeneradorCodigosUtils $generadorCodigos)
    {
        $data = $request->all();
        try {
            DB::beginTransaction();
            foreach ($data as $param) {
                //@SI LA CUENTA ESTA INACTIVA NOTIFICAMOS
                if ($cuenta->findByVerificarEstadoCuenta($param['id_cuenta_bancaria'], '0')) {
                    DB::rollBack();
                    return response()->json(['message' => 'La cuenta seleccionada se encuentra <b>BLOQUEADA</b>'], 409);
                }

                // @UNA OPA NO PUEDE TENER DOS PAGOS GENERADOS (2026-09-03)
                // Hasta ahora esto estaba protegido POR ACCIDENTE: crear el pago mueve la OPA a
                // estado 4, y la validación de abajo exige estado 1. Al migrar los estados
                // legacy (las 1.019 OPAs de OSV en "EN PROCESO" vuelven a "PENDIENTE"), esa
                // protección implícita desaparece y quedaría la puerta abierta a generar un
                // segundo pago sobre una orden que ya tiene uno. Se hace explícita.
                // Un pago RECHAZADO no cuenta: ahí sí corresponde poder generar uno nuevo.
                if ($opa->tieneAlgunPago($param['id_orden_pago'])) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Esta orden de pago ya tiene un pago generado. '
                            . 'Para generar otro, primero hay que anular el existente.'
                    ], 409);
                }

                //@VALIDAMOS QUE LA OPA ESTE EN ESTADO PENDIENTE
                if (!$opa->findByExistsOpaEstado($param['id_orden_pago'], '1')) {
                    DB::rollBack();
                    return response()->json(['message' => 'La OPA se encuentra bloqueado.'], 409);
                }
                //@EL TIPO (PRESTADOR/PROVEEDOR) SALE DEL DETALLE DE LA OPA, NO DEL FRONT: el envío
                // masivo mandaba el de la cabecera, que en las agrupadas quedaba en 'PROVEEDOR'
                // por DEFAULT y el pago terminaba en Pagos Proveedores. (T-00000830)
                $param['tipo_factura'] = $opa->findByTipoFacturaOpa($param['id_orden_pago'])
                    ?? ($param['tipo_factura'] ?? null);
                //@GENERAMOS EL PAGO
                $boletaPago = $pago->findByCrearPago($param);
                //@ASIGNAMOS CODDIGO BARRAS
                $codigoVerificado = $generadorCodigos->getGenerarCodigoUnico($boletaPago->id_pago);
                $pago->findByAsignarCodigoVerificacion($boletaPago->id_pago, $codigoVerificado);
                //@ACTUALIZAMOS ESTADO DE LA OPA *[4] EN PROCESO*
                $opa->findByUpdateEstado($param['id_orden_pago'], 4);
                //$opa->findByConfirmarFechaProbablePago($param['id_orden_pago'], $param['fecha_probable_pago'], $param['cuotas']);
                $opa->findByConfirmarPagoEmergencia($param['id_orden_pago'], $param['pago_emergencia']);
            }
            ;
            DB::commit();
            return response()->json(['message' => 'Opa confirmada correctamente, enviada a Pagos']);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getListarPagos(Request $request, TesPagosRepository $pago)
    {
        $data = [];
        $data = $pago->findByListPagosFiltroPrincipal($request);

        return response()->json($data);
    }

    public function getConfirmarPago(
        Request $request,
        TesPagosRepository $pago,
        TestOrdenPagoRepository $opa,
        TesCuentasBancariasRepository $cuenta,
        ManejadorDeArchivosUtils $storage,
        GeneradorCodigosUtils $generadorCodigos,
        FacturaRepository $facturaRepository,
        AsientoContableRepository $asientoContableRepository,
        AsientosPagoHistorialRepository $historialPagoRepository,
    ) {
        try {

            DB::beginTransaction();
            $params = json_decode($request->data);
            $opaFactus = null;

            // @VALIDAMOS QUE LAS CUENTAS DE PAGO SEAN DE LA MISMA RAZÓN SOCIAL QUE LA OPA/FACTURA.
            // Si no, el pago debita una cuenta bancaria y el asiento imputa la deuda en el plan
            // de cuentas de otra razón social — quedan dos contabilidades descuadradas entre sí.
            //
            // Se revisan TODAS las cuentas involucradas, no solo la de la boleta: desde el
            // 2026-09-06 la cuenta de origen vive en cada abono (`tb_tes_pago_parcial`), para
            // poder pagar una orden desde dos bancos distintos. Mirar solo `id_cuenta_bancaria`
            // dejaba pasar abonos de otra razón social sin que nadie los frenara. (2026-09-07)
            if (!empty($params->id_razon)) {
                $cuentasAValidar = collect([$params->id_cuenta_bancaria ?? null])
                    ->merge(collect($params->lista_pagos ?? [])->pluck('id_cuenta_bancaria'))
                    ->filter()
                    ->unique();

                foreach ($cuentasAValidar as $idCuentaAValidar) {
                    $cuentaPago = $cuenta->findById($idCuentaAValidar);

                    if ($cuentaPago && (int) $cuentaPago->id_razon !== (int) $params->id_razon) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'La cuenta bancaria seleccionada no pertenece a la razón social de la OPA/factura. Elegí una cuenta de la misma razón social para continuar.'
                        ], 422);
                    }
                }
            }

            // @VERIFICAMOS SI TENEMOS UN FONDOS EN LA CUENTA DE PAGO
            $monto_total = $params->anticipo == '1' ? $params->monto_anticipado : 0;
            foreach ($params->lista_pagos as $pagos) {
                $monto_Validar = $pagos->monto_pago;
                // Restricción de fondos previos deshabilitada a pedido (TK R-0306, 2026-07-27):
                // se permite emitir el pago aunque la cuenta no tenga saldo suficiente.
                // if (!$cuenta->findByVerificarSaldoCuenta($params->id_cuenta_bancaria, $monto_Validar)) {
                //     DB::rollBack();
                //     return response()->json(['message' => 'No hemos podido procesar tu solicitud de pago porque la cuenta bancaria seleccionada no tiene fondos suficientes. Por favor, revisa tu saldo e inténtalo otra vez.'], 409);
                // }
                $monto_total = $monto_total + $monto_Validar;
            }

            // ═══ Cargar un pago ≠ ejecutarlo (2026-10-28) ═══
            //
            // Hasta acá, confirmar el pago debitaba la cuenta y generaba el asiento. Pero confirmar
            // es cuando Pagos PLANIFICA: muchas veces todavía no sabe con qué va a pagar, y después
            // cambiaba la forma de pago de algo ya asentado (abono 524). Desde ahora:
            //
            //   1. `findByConfirmarPago()` guarda la planificación: sin débito ni asiento.
            //   2. Los comprobantes que se suben EJECUTAN los pagos de su fecha, con la fecha real
            //      del pago: recién ahí se debita y se asienta.
            //
            // Ver docs/circuito-pagos/plan-pago-ejecutado-con-comprobante.md.

            // @CONFIRMAMOS EL PAGO || GUARDA LA PLANIFICACIÓN
            $pagoDb = $pago->findByConfirmarPago($params);

            //@ELIMINAR COMPRANTES
            // No se quita el comprobante que respalda pagos ya ejecutados. (2026-10-28)
            if ($params->archivos_eliminados) {
                foreach ($params->archivos_eliminados as $archivo) {
                    $motivo = $pago->motivoQueImpideQuitarComprobante($archivo->id_comprobante);
                    if ($motivo) {
                        DB::rollBack();
                        return response()->json(['message' => $motivo], 422);
                    }
                    $pago->findByDeleteComprobantePago($archivo->id_comprobante);
                }
            }

            //@SUBIR DETALLE DE COMPROBANTES
            // Un comprobante por fecha del cronograma, y sólo sobre pagos con su número cargado.
            // Se valida antes de escribir a disco. (2026-10-23)
            $cantidadArchivos = $request->hasFile('archivos') ? count($request->file('archivos')) : 0;
            $fechasArchivos = [];
            $fechaRealPorFecha = [];
            if ($cantidadArchivos > 0) {
                [$fechasArchivos, $motivo] = $pago->fechasDeComprobantes(
                    $params->id_pago,
                    (array) ($params->archivos_fechas ?? []),
                    $cantidadArchivos
                );
                if ($motivo) {
                    DB::rollBack();
                    return response()->json(['message' => $motivo], 422);
                }

                // Fecha real en que salió cada pago, una por comprobante. Sin ella, hoy.
                $fechasPago = (array) ($params->archivos_fechas_pago ?? []);
                foreach ($fechasArchivos as $i => $idFecha) {
                    $fechaPago = trim((string) ($fechasPago[$i] ?? ''));
                    if ($fechaPago !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
                        DB::rollBack();
                        return response()->json(['message' => 'La fecha del pago no es válida.'], 422);
                    }
                    $clave = $idFecha ?? 'sin';
                    if ($fechaPago !== '' && !isset($fechaRealPorFecha[$clave])) {
                        $fechaRealPorFecha[$clave] = $fechaPago;
                    }
                }
            }
            $arrayArchivos = $storage->findByCargaMasivaArchivos("COMP" . $params->id_pago . $params->num_pago, 'tesoreria/comprobantes_pago', $request);
            foreach ($arrayArchivos as $i => $key) {
                $pago->findByCargarComprobantePago($key['nombre'], $params->id_pago, $fechasArchivos[$i] ?? null);
            }

            // @EJECUTAMOS LOS PAGOS CON COMPROBANTE
            $ejecutados = $pago->ejecutarPagosConComprobante($params->id_pago, $fechaRealPorFecha);
            $hoy = Carbon::now('America/Argentina/Buenos_Aires')->toDateString();

            // @CONFIRMAMOS LA OPA || **PAGO ANTICIPADO**

            if ($params->anticipo == '1') {
                //@OBTENER LA DATA DE LA OPA
                $dataOpa = $opa->findById($params->id_pago);
                //@OBTENER LA SUMA DE LOS PAGOS ANTICIPADOS
                $sumMontosTotalAnticipados = $pago->findBySumarDetallePagosAnticipados($params->id_pago);
                //@VALIDAR QUE EL ANTICIPO NO SEA MAYOR AL VALOR DE LA OPA
                if ($sumMontosTotalAnticipados > $dataOpa->monto_orden_pago) {
                    DB::rollBack();
                    return response()->json(['message' => "El monto anticipado es mayor al monto total de la OPA."], 409);
                }

                if ($sumMontosTotalAnticipados == $dataOpa->monto_orden_pago) {
                    //@INCREMENTAR EL TOTAL DEL ANTICIPO EN LA OPA
                    $opaFactus = $opa->findByAnticipoPago($params->id_orden_pago, $sumMontosTotalAnticipados);
                    //@CONFIRMAMOS LA OPA YA QUE SE PAGO EN SU TOTALIDAD
                    $opa->findByConfirmarEstado($params->id_orden_pago, $pagoDb->fecha_procesamiento, '5');
                    //@CONFIRMAMOS A ESTADO PAGADO LA FACTURA
                    $facturaRepository->findByUpdateFactusPagoId($opaFactus->id_factura, '1');
                } else {
                    //@INCREMENTAR EL TOTAL DEL ANTICIPO EN LA OPA
                    $opa->findByAnticipoPago($params->id_orden_pago, $sumMontosTotalAnticipados);
                    //@CREAMOS UN PAGO DE DEUDA DE LA OPA ES DECIR DE LO RESTANTE
                    $totalDebe = $dataOpa->monto_orden_pago - $sumMontosTotalAnticipados;
                    $boletaPago = $pago->findByCrearPago(new PagosDto(
                        $params->id_orden_pago,
                        $params->id_cuenta_bancaria,
                        $params->fecha_probable_pago,
                        '1',
                        null,
                        $params->id_forma_pago,
                        $totalDebe,
                        null,
                        1,
                        $dataOpa->monto_orden_pago,
                        '1',
                        $params->fecha_confirma_pago,
                        // Mismo criterio que getCrearPago: el tipo sale del detalle de la OPA. (T-00000830)
                        $opa->findByTipoFacturaOpa($params->id_orden_pago) ?? $params->tipo_factura
                    ));
                    //@ASIGNAMOS CODDIGO BARRAS
                    $codigoVerificado = $generadorCodigos->getGenerarCodigoUnico($boletaPago->id_pago);
                    $pago->findByAsignarCodigoVerificacion($boletaPago->id_pago, $codigoVerificado);
                }

                //@VALIDAMOS SI EL MONTO DE LA OPA Y EL ANTICIPADO AU
            } else {
                //@PAGO NORMAL
                // El estado de la OPA ya no se fuerza a PAGADO: se DERIVA de comparar lo pagado
                // contra lo imputado (punto 4 del requerimiento). Si el pago no cubre el total,
                // la orden queda en PAGO PARCIAL en vez de cerrarse como pagada. Forzar el 5 es
                // lo que dejó 5 órdenes cerradas con menos plata de la imputada (~$15,4M entre
                // las dos bases). Ver docs/circuito-pagos/plan-fase1-pagos.md §6.bis. (2026-09-03)
                $opaFactus = $opa->findByConfirmarPagoDerivandoEstado(
                    $params->id_orden_pago,
                    $pagoDb->fecha_confirma_pago
                );

                // La factura solo se marca pagada si la OPA quedó efectivamente PAGADA.
                if (!is_null($opaFactus)
                    && (int) $opaFactus->id_estado_orden_pago === TestOrdenPagoRepository::ESTADO_OPA_PAGADO
                    && !is_null($opaFactus->id_factura)) {
                    $facturaRepository->findByUpdateFactusPagoId($opaFactus->id_factura, '1');
                }
            }

            // @DÉBITO Y ASIENTO DE LO EJECUTADO
            //
            // Sólo lo que se acaba de ejecutar (comprobante subido), agrupado por FECHA REAL: cada
            // grupo lleva su asiento con esa fecha y en el período de esa fecha, y su movimiento
            // bancario con esa fecha. Un pago que salió hace dos semanas no queda fechado hoy.
            //
            // Débito por la cuenta de CADA pago (una orden puede salir de dos bancos). Un
            // cheque/eCheq no mueve saldo acá: eso pasa al acreditarlo (`marcarAcreditado`), y en
            // el asiento va contra cheques diferidos, no contra el banco. (2026-09-12)
            $grupos = [];   // fecha => ['cuentas' => [id => monto], 'desglose' => [...]]

            foreach ($ejecutados as $e) {
                $abono = $e['abono'];
                $idCuentaAbono = $abono->id_cuenta_bancaria ?: $params->id_cuenta_bancaria;

                // Sin cuenta no se puede debitar ni asentar: se corta en vez de dejar el pago
                // ejecutado sin rastro en el banco.
                if (empty($idCuentaAbono)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Uno de los pagos de esta orden no tiene cuenta de origen, '
                            . 'así que no se puede descontar el saldo. Indicá la cuenta bancaria '
                            . 'antes de subir el comprobante.'
                    ], 422);
                }

                $grupos[$e['fecha']]['desglose'][] = [
                    'id_cuenta_bancaria' => $idCuentaAbono,
                    'monto'              => (float) $abono->monto_pago,
                    'diferido'           => $e['diferido'],
                    'id_pago_parcial'    => $abono->id_pago_parcial,
                ];

                if (!$e['diferido']) {
                    $grupos[$e['fecha']]['cuentas'][$idCuentaAbono] =
                        ($grupos[$e['fecha']]['cuentas'][$idCuentaAbono] ?? 0) + (float) $abono->monto_pago;
                }
            }

            // Boleta del circuito viejo, sin abonos (un anticipo): se ejecuta entera cuando tiene
            // comprobante y todavía no tiene su asiento.
            $abonosVivos = $pago->findByPagosParcialesVivos($params->id_pago);
            if ($abonosVivos->isEmpty() && !empty($params->id_cuenta_bancaria) && $monto_total > 0
                && $cantidadArchivos > 0
                && is_null($historialPagoRepository->obtenerAsientoVigentePago($params->id_pago))) {
                $fechaBoleta = $fechaRealPorFecha['sin'] ?? (reset($fechaRealPorFecha) ?: $hoy);
                $grupos[$fechaBoleta]['cuentas'][$params->id_cuenta_bancaria] = $monto_total;
                $grupos[$fechaBoleta]['desglose'][] = [
                    'id_cuenta_bancaria' => $params->id_cuenta_bancaria,
                    'monto'              => (float) $monto_total,
                    'diferido'           => false,
                    'id_pago_parcial'    => null,
                ];
            }

            foreach ($grupos as $fechaPago => $grupo) {
                foreach (($grupo['cuentas'] ?? []) as $idCuentaBancaria => $montoCuenta) {
                    $cuenta->findByRetiroCuenta($idCuentaBancaria, $montoCuenta);
                    // @REGISTRAMOS EL MOVIMIENTO DE LA CUENTA BANCARIA, con la fecha real
                    $cuenta->findByRegistrarMovimiento($idCuentaBancaria, $montoCuenta, 'EGRESO', $params->id_pago, null, 'OPA', $fechaPago);
                }
            }

            // ============================================================
            // ASIENTO CONTABLE AUTOMÁTICO DE PAGO — uno por fecha real
            // ============================================================
            if (!is_null($opaFactus) && !empty($grupos)) {
                if (empty($params->id_razon)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Falta la razón social para registrar el asiento contable del pago. Por favor contacte con el administrador.'
                    ], 422);
                }
                try {
                    // Cargar relaciones de OPA — independiente de cuántas facturas tenga
                    $opaFactus->loadMissing(['proveedor', 'prestador']);
                    $proveedorPrestador = $opaFactus->proveedor ?? $opaFactus->prestador;

                    // id_tipo_factura == 16 es la señal real de proveedor. id_proveedor/id_prestador
                    // solos no alcanzan: hay facturas con los dos ids cargados a la vez (dato sucio),
                    // que crearAsientoPago clasificaba mal según cuál de los dos mirara.
                    $facturaDelPago = $facturaRepository->findById($opaFactus->id_factura);

                    foreach ($grupos as $fechaPago => $grupo) {
                        // Período mensual de la FECHA REAL del pago, no de hoy.
                        $periodoContable = $this->periodoContableRepositorio->findByPeriodoContableEnFecha($fechaPago, $params->id_razon ?? null);
                        if (is_null($periodoContable)) {
                            throw new \Exception('No hay un período contable abierto para el '
                                . Carbon::parse($fechaPago)->format('d/m/Y')
                                . ', la fecha del pago. Abrilo en Contabilidad o revisá la fecha.');
                        }

                        $desglose = $grupo['desglose'];
                        $primeraCuentaDirecta = array_key_first($grupo['cuentas'] ?? []);

                        $datosPago = [
                            'id_pago'            => $pagoDb->id_pago,
                            'id_proveedor'       => $opaFactus->id_proveedor,
                            'id_prestador'       => $opaFactus->id_prestador,
                            'id_tipo_factura'    => $facturaDelPago->id_tipo_factura ?? null,
                            'id_razon'           => $params->id_razon ?? null,
                            'cuit'               => $proveedorPrestador->cuit ?? '',
                            'nombre'             => $proveedorPrestador->razon_social ?? '',
                            'numero_pago'        => 'PAGO-' . $pagoDb->num_pago,
                            'fecha_registra'     => Carbon::parse($fechaPago)->format('d/m/Y'),
                            'fecha_asiento'      => $fechaPago,
                            'id_cuenta_bancaria' => $params->id_cuenta_bancaria
                                ?: ($primeraCuentaDirecta ?? $desglose[0]['id_cuenta_bancaria'] ?? $pagoDb->id_cuenta_bancaria),
                            // Desglose del HABER: una linea por pago, marcando cual es diferido.
                            'cuentas'            => $desglose,
                            'monto_total'        => round(array_sum(array_column($desglose, 'monto')), 2),
                        ];

                        $asiento = $asientoContableRepository->crearAsientoPago($datosPago, $periodoContable->id_periodo_contable);

                        $historialPagoRepository->guardarHistorial(
                            $pagoDb->id_pago,
                            $asiento->id_asiento_contable,
                            'ALTA',
                            false,
                            null,
                            'Asiento contable creado automáticamente al ejecutar el pago (comprobante)'
                        );

                        // Una fila por pago con SU línea del asiento: permite revertir un solo pago
                        // (rechazo de un eCheq, anulación) sin tocar los demás del mismo asiento.
                        foreach (($asiento->lineasDeInstrumento ?? []) as $idAbono => $idDetalle) {
                            $historialPagoRepository->guardarHistorial(
                                $pagoDb->id_pago,
                                $asiento->id_asiento_contable,
                                'EMISION',
                                false,
                                null,
                                'Pasivo por instrumento diferido, pendiente de débito bancario',
                                $idAbono,
                                $idDetalle
                            );
                        }
                        foreach (($asiento->lineasDirectas ?? []) as $idAbono => $idDetalle) {
                            $historialPagoRepository->guardarHistorial(
                                $pagoDb->id_pago,
                                $asiento->id_asiento_contable,
                                'EJECUCION',
                                false,
                                null,
                                'Salida de fondos del pago',
                                $idAbono,
                                $idDetalle
                            );
                        }
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Error al registrar el asiento contable del pago: ' . $e->getMessage()
                    ], 423);
                }
            }
            // El estado de la ORDEN se deriva de lo efectivamente cobrado. Confirmar el pago
            // actualizaba la BOLETA pero nunca la orden, asi que quedaban diciendo cosas distintas:
            // la boleta en PAGO PARCIAL y la OPA todavia en EN PROCESO. Solo se recalculaba al
            // anular. (2026-09-10, reportado sobre la OPA-4284)
            //
            // OJO: un eCheq emitido pero NO acreditado no cuenta como cobrado —esa plata todavia
            // no salio—, asi que una orden pagada solo con eCheq sigue en EN PROCESO hasta que se
            // acredite en Carga de eCheq. Es el diseño del circuito, no una falla de esto.
            $opa->recalcularEstadoOpa($params->id_orden_pago);

            DB::commit();
            return response()->json(['message' => 'El Pago ha sido confirmado y procesado con éxito.']);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getAnularPago(
        Request $request,
        TesPagosRepository $pago,
        TestOrdenPagoRepository $opa,
        AsientosPagoHistorialRepository $historialPagoRepository
    ) {
        try {
            DB::beginTransaction();

            // Contraasiento contable si tiene asiento registrado
            if ($historialPagoRepository->pagoTieneAsientos($request->id_pago)) {
                $historialPagoRepository->procesarAnulacionPago(
                    $request->id_pago,
                    'Pago anulado por el usuario'
                );
            }

            // Anular el pago y RECALCULAR el estado de la orden.
            //
            // CAMBIO DE COMPORTAMIENTO (2026-09-03): antes, anular un pago dejaba la ORDEN en
            // RECHAZADO. Eso mezcla dos cosas distintas — que se caiga un pago no es lo mismo
            // que dar de baja la orden, que es una decisión administrativa aparte (punto 7).
            // El requerimiento lo pide explícito para el eCheq rechazado: "la OP recalcula su
            // estado (vuelve a parcialmente pagada o pendiente)".
            //
            // Con esto, anular el pago devuelve la orden a PENDIENTE (o PAGO PARCIAL si quedaban
            // otros pagos confirmados) y queda lista para generar un pago nuevo. El pago anulado
            // queda en estado 3, así que no bloquea la generación del siguiente.
            $pago->findByAnularPago($request->id_pago, $request->motivo_rechazo);
            $opa->recalcularEstadoOpa($request->id_orden_pago);

            DB::commit();
            return response()->json(['message' => 'El Pago ha sido anulado con éxito.']);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Anula UN pago ya ejecutado (con comprobante): contraasiento de su línea, devolución de lo
     * debitado, y su fecha del cronograma queda libre para cargar el correcto. (2026-10-28)
     */
    public function getAnularPagoEjecutado(Request $request, TestOrdenPagoRepository $opa)
    {
        try {
            (new \App\Http\Controllers\Tesoreria\Repository\TesAnulacionPagoRepository())
                ->anularPagoEjecutado($request->id_pago_parcial, $request->motivo, $opa);

            return response()->json(['message' => 'El pago se anuló: se revirtió su asiento y lo debitado volvió a la cuenta.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 422);
        }
    }

    public function getVerAdjunto(ManejadorDeArchivosUtils $storageFile, Request $request)
    {
        $path = "tesoreria/comprobantes_pago/";
        // $data = $pago->findById($request->id);
        $anioTrabaja = Carbon::parse($request->fecha_registra)->year;
        $path .= "{$anioTrabaja}/$request->nombre_archivo";

        return $storageFile->findByObtenerArchivo($path);
    }

    public function getListarDetallePago(Request $request, TesPagosRepository $repoPago)
    {
        return response()->json($repoPago->findByListDetallePagosAnticipadosConfirmados($request->id), 200);
    }
}
