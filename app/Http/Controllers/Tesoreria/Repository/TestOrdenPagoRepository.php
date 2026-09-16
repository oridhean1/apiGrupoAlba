<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesEstadoOrdenPagoEntity;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesFechaProbablePagoEntity;
use App\Models\Tesoreria\TesOrdenPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesPagoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestOrdenPagoRepository
{
    // Estados de tb_tes_estado_orden_pago
    const ESTADO_OPA_PENDIENTE = 1;
    const ESTADO_OPA_APROBADO  = 2;
    const ESTADO_OPA_RECHAZADO = 3;
    const ESTADO_OPA_EN_PROCESO = 4;   // legacy — el circuito nuevo no lo produce
    const ESTADO_OPA_PAGADO    = 5;
    const ESTADO_OPA_PAGO_PARCIAL = 6;
    const ESTADO_OPA_CONSUMIDA = 7;    // anticipos (fase 3)

    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findByListTipoEstado()
    {
        return TesEstadoOrdenPagoEntity::get();
    }

    public function findListAlls($desde, $hasta)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor', 'prestador'])
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnEstado($estado, $desde, $hasta)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor', 'prestador'])
            ->where('id_estado_orden_pago', $estado)
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnTipo($tipo, $desde, $hasta)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor',  'prestador'])
            ->where('tipo_factura', $tipo)
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnTipoAndEstado($tipo, $estado, $desde, $hasta)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor', 'prestador'])
            ->where('tipo_factura', $tipo)
            ->where('id_estado_orden_pago', $estado)
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnTipoAndEstadoAndMontos($tipo, $estado, $desde, $hasta, $montDesde, $montHasta)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor',  'prestador'])
            ->where('tipo_factura', $tipo)
            ->where('id_estado_orden_pago', $estado)
            ->whereBetween('monto_orden_pago', [$montDesde, $montHasta])
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnTipoAndEstadoAndMontosAndBeneficiario($tipo, $estado, $desde, $hasta, $montDesde, $montHasta, $beneficiario)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor',  'prestador'])
            ->where('tipo_factura', $tipo)
            ->where('id_estado_orden_pago', $estado)
            ->whereBetween('monto_orden_pago', [$montDesde, $montHasta])
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->where(function ($query) use ($beneficiario) {
                $query->whereHas('proveedor', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                })->orWhereHas('prestador', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                });
            })
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAnEstadoAndBeneficiario($estado, $desde, $hasta, $beneficiario)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor', 'prestador'])
            ->where('id_estado_orden_pago', $estado)
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->where(function ($query) use ($beneficiario) {
                $query->whereHas('proveedor', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                })->orWhereHas('prestador', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                });
            })
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function findListBetweenAndBeneficiario($desde, $hasta, $beneficiario)
    {
        return TesOrdenPagoEntity::with(['estado', 'proveedor', 'prestador'])
            ->whereBetween(DB::raw('DATE(fecha_genera)'), [$desde, $hasta])
            ->where(function ($query) use ($beneficiario) {
                $query->whereHas('proveedor', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                })->orWhereHas('prestador', function ($q) use ($beneficiario) {
                    $q->where('razon_social', 'LIKE', "{$beneficiario}%");
                });
            })
            ->orderByDesc('id_orden_pago')
            ->get();
    }

    public function getFiltroDinamico($params)
    {
        $query = TesOrdenPagoEntity::with([
            'estado',
            'opadetalle.detallefc.razonSocial',
            'opadetalle.detallefc.comprobantes',
            'proveedor',
            'prestador',
            'pagoFecha.fechaprobablepagos',
            'opadetalle',
            'opadetalle.detallefc',
        ]);

        $query = TesOrdenPagoEntity::with([
            'estado',
            'opadetalle.detallefc.razonSocial',
            'opadetalle.detallefc.comprobantes',
            'proveedor',
            'prestador',
            'pagoFecha.fechaprobablepagos',
            'opadetalle',
            'opadetalle.detallefc',
        ]);

        if (!is_null($params->tipo)) {
            $query->where(function ($q) use ($params) {
                $q->whereHas('opadetalle.detallefc', function ($subQuery) use ($params) {
                    $subQuery->where('tipo_factura', $params->tipo);
                });
            });
        }

        if (!is_null($params->estado)) {
            $query->where('id_estado_orden_pago', $params->estado);
        }

        if (!is_null($params->monto_desde) && !is_null($params->monto_hasta)) {
            $query->whereBetween('monto_orden_pago', [$params->monto_desde, $params->monto_hasta]);
        }

        if (!is_null($params->desde) && !is_null($params->hasta)) {
            $query->whereBetween(DB::raw('DATE(fecha_genera)'), [$params->desde, $params->hasta]);
        }

        if (!is_null($params->beneficiario)) {
            $query->where(function ($q) use ($params) {
                $q->whereHas('proveedor', function ($subQuery) use ($params) {
                    $subQuery->where('razon_social', 'LIKE', "{$params->beneficiario}%");
                })->orWhereHas('prestador', function ($subQuery) use ($params) {
                    $subQuery->where('razon_social', 'LIKE', "{$params->beneficiario}%");
                });
            });
        }

        if (!is_null($params->id_locatorio)) {
            $query->where(function ($q) use ($params) {
                $q->whereHas('opadetalle.detallefc', function ($subQuery) use ($params) {
                    $subQuery->where('id_locatorio', $params->id_locatorio);
                });
            });
        }

        if (!is_null($params->id_tipo_imputacion)) {
            $query->where(function ($q) use ($params) {
                $q->whereHas('opadetalle.detallefc', function ($subQuery) use ($params) {
                    $subQuery->where('id_tipo_imputacion_sintetizada', $params->id_tipo_imputacion);
                });
            });
        }

        if ($params->pago_urgente == '1') {
            $query->where('pago_emergencia', $params->pago_urgente);
        }

        if (!is_null($params->n_factura)) {
            $query->where(function ($q) use ($params) {
                $q->whereHas('opadetalle.detallefc', function ($subQuery) use ($params) {
                    $subQuery->where('numero', $params->n_factura);
                });
            });
        }

        // Busqueda por numero de orden. Se hace con LIKE porque el usuario tipea indistinto
        // "OPA-1435", "1435" o incluso "opa 1435" — pedirle el formato exacto de un numero que
        // arma un trigger seria trasladarle un detalle interno. (2026-09-04)
        if (!empty($params->num_orden_pago)) {
            $numero = preg_replace('/\D/', '', (string) $params->num_orden_pago);

            if ($numero !== '') {
                // Comparacion NUMERICA: los numeros viejos traen ceros a la izquierda
                // ('OPA-0999') y los nuevos no ('OPA-14358'). Con LIKE, buscar 1435 devolvia
                // tambien OPA-14358; con igual textual, "999" no encontraba "OPA-0999".
                $query->whereRaw(
                    "CAST(REPLACE(num_orden_pago, 'OPA-', '') AS UNSIGNED) = ?",
                    [(int) $numero]
                );
            }
        }

        return $query
            //->leftJoin('tb_prestador as p', 'p.cod_prestador', '=', 'tb_tes_orden_pago.id_prestador')
            //->leftJoin('tb_proveedor as prov', 'prov.cod_proveedor', '=', 'tb_tes_orden_pago.id_proveedor')
            //->orderByRaw('COALESCE(p.razon_social, prov.razon_social)')
            ->orderBy('monto_orden_pago')
            ->get();
    }

    public function findByOpaFactura($idFactura, $estado)
    {
        return TesOrdenPagoEntity::where('id_factura', $idFactura)
            ->where('id_estado_orden_pago', $estado)->first();
    }

    /**
     * OPA vigente de una factura: cualquier estado MENOS RECHAZADO (3), que se considera muerta
     * y por lo tanto habilita generar una nueva.
     *
     * Existe porque buscar solo por estado PENDIENTE (1) hacía que, si la OPA ya había avanzado
     * a EN PROCESO/PAGADO, el sistema creyera que no existía y generara una segunda OPA para la
     * misma factura (se detectaron 6 casos así en producción). Ver docs/pendientes.md.
     */
    public function findByOpaVigenteFactura($idFactura)
    {
        // OJO: en las OPAs AGRUPADAS la cabecera tiene id_factura = NULL — el vínculo con las
        // facturas vive solo en tb_tes_orden_pago_detalle. Por eso hay que buscar por los dos
        // lados: mirar solo la cabecera dejaba fuera a todas las agrupadas.
        return TesOrdenPagoEntity::where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
            ->where(function ($q) use ($idFactura) {
                $q->where('id_factura', $idFactura)
                    ->orWhereExists(function ($sub) use ($idFactura) {
                        $sub->select(DB::raw(1))
                            ->from('tb_tes_orden_pago_detalle as d')
                            ->whereColumn('d.id_orden_pago', 'tb_tes_orden_pago.id_orden_pago')
                            ->where('d.id_factura', $idFactura);
                    });
            })
            ->orderBy('id_orden_pago')
            ->first();
    }

    public function findByExistsOpaFacturaEstado($idFactura, $estado)
    {
        return TesOrdenPagoEntity::where('id_factura', $idFactura)
            ->where('id_estado_orden_pago', $estado)->exists();
    }

    public function findByCreate($param)
    {
        $opa = TesOrdenPagoEntity::create([
            'id_proveedor' => $param->id_proveedor,
            'id_prestador' => $param->id_prestador,
            'monto_orden_pago' => $param->monto_orden_pago,
            'id_moneda' => $param->id_moneda,
            'fecha_emision' => $param->fecha_emision,
            'fecha_vencimiento' => $param->fecha_vencimiento,
            'fecha_probable_pago' => $param->fecha_probable_pago,
            'id_estado_orden_pago' => $param->id_estado_orden_pago,
            'monto_anticipado' => $param->monto_anticipado,
            'observaciones' => $param->observaciones,
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_genera' => $this->fechaActual,
            'id_factura' => $param->id_factura,
            'tipo_factura' => $param->tipo_factura
        ]);

        TesOrdenPagoDetalleEntity::create([
            'id_orden_pago' => $opa->id_orden_pago,
            'id_factura' => $param->id_factura,
            'monto_factura' => $param->monto_orden_pago,
            'tipo_factura' => $param->tipo_factura,
            'factura_unida' => 0
        ]);

        // La puente se escribe junto con el detalle: si no, toda OPA nueva nacería sin
        // imputación y su estado derivado daría PENDIENTE aunque estuviera pagada. (2026-09-03)
        $this->sincronizarPuenteDesdeDetalle($opa->id_orden_pago);

        return $opa;
    }

    public function findByUpdateOpaFactura($param)
    {
        $opa = TesOrdenPagoEntity::find($param->id_orden_pago);
        $opa->monto_orden_pago = $param->monto_orden_pago;
        $opa->update();
    }

    /**
     * Actualiza el monto de UNA factura dentro de una OPA (individual o agrupada) y recalcula
     * la cabecera desde el detalle. Se usa al re-valorizar una liquidación cuya OPA sigue
     * PENDIENTE: la factura puede cambiar de monto (se editó el detalle) y la OPA todavía no
     * avanzó a ningún estado que la comprometa. (2026-08-13)
     *
     * Nunca tocar la cabecera directo con el monto nuevo: si la OPA es agrupada, haría que el
     * total deje de ser la suma real del resto de las facturas agrupadas.
     */
    public function findByRevalorizarOpaFactura($idOrdenPago, $idFactura, $montoNuevo)
    {
        TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOrdenPago)
            ->where('id_factura', $idFactura)
            ->update(['monto_factura' => $montoNuevo]);

        $this->recalcularMontoDesdeDetalle($idOrdenPago);
    }

    public function findByUpdate($param)
    {
        $tes = TesOrdenPagoEntity::find($param->id_orden_pago);
        $tes->id_moneda = $param->id_moneda;
        $tes->fecha_emision = $param->fecha_emision;
        $tes->fecha_vencimiento = $param->fecha_vencimiento;
        $tes->fecha_probable_pago = $param->fecha_probable_pago;
        $tes->observaciones = $param->observaciones;
        $tes->pago_emergencia = $param->pago_emergencia;
        $tes->update();

        //$idsFinales[]=[];
        $id_pago = $param->fechaprobablepagos[0]['id_pago'];
        foreach ($param->fechaprobablepagos as $index => $item) {
            $orden = $index + 1;
            if (!empty($item['id_fecha_probable'])) {
                $detalle = TesFechaProbablePagoEntity::find($item['id_fecha_probable']);
                if ($detalle) {
                    $detalle->fecha_probable_pago = $item['fecha_probable_pago'];
                    $detalle->orden_cuotas = $item['orden_cuotas'];
                    $detalle->update();
                }
                $idsFinales[] = $detalle->id_fecha_probable;
            } else {
                $nuevo = TesFechaProbablePagoEntity::create([
                    'fecha_registra' => $item['fecha_registra'],
                    'fecha_probable_pago' => $item['fecha_probable_pago'],
                    'orden_cuotas' => $orden,
                    'id_pago' => $id_pago,
                ]);
                $idsFinales[] = $nuevo->id_fecha_probable;
            }
        }

        TesFechaProbablePagoEntity::where('id_pago', $id_pago)
            ->whereNotIn('id_fecha_probable', $idsFinales)
            ->delete();
        return $tes;
    }

    public function findByUpdateEstado($id_opa, $estado, $motivoRechazo = null)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);
        $tes->id_estado_orden_pago = $estado;
        if ($estado == '3') {
            $tes->motivo_rechazo = $motivoRechazo;
            $tes->fecha_rechazo = $this->fechaActual;
            $tes->cod_usuario_rechaza = $this->user->cod_usuario ?? null;
        }
        $tes->update();
        return $tes;
    }

    /**
     * @deprecated Duplicaba la lógica de tienePagosConfirmados() con el criterio viejo (contaba
     * pagos sin confirmar). Ahora delega, para que el criterio viva en un solo lugar.
     */
    public function findByOpaTienePagosVivos($idOpa)
    {
        return $this->tienePagosConfirmados($idOpa);
    }

    /**
     * Anula (rechaza) la OPA vigente de una factura, dejando constancia del motivo y del usuario.
     *
     * - Si la OPA agrupa varias facturas, primero desagrupa ESTA factura a una OPA propia
     *   (findRemoveFacturaMultiple) y rechaza solo esa: las demás facturas del grupo no se tocan.
     * - Si la OPA tiene pagos vivos, NO anula y devuelve el motivo del bloqueo.
     *
     * No abre transacción propia: depende de la del controlador.
     *
     * @return array{anulada: bool, message: string, opa: TesOrdenPagoEntity|null}
     */
    public function findByAnularOpaDeFactura($idFactura, $motivo = null)
    {
        $opa = $this->findByOpaVigenteFactura($idFactura);

        if (is_null($opa)) {
            return ['anulada' => false, 'message' => 'La factura no tiene una orden de pago vigente.', 'opa' => null];
        }

        if ($this->findByOpaTienePagosVivos($opa->id_orden_pago)) {
            return [
                'anulada' => false,
                'opa' => $opa,
                'message' => 'No se puede anular: la orden de pago ' . $opa->num_orden_pago
                    . ' ya tiene pagos confirmados. Primero hay que anular esos pagos.'
            ];
        }

        // Si la OPA agrupa varias facturas, desagrupar esta a una OPA propia antes de rechazar,
        // para no afectar a las demás facturas del grupo.
        $cantFacturasEnOpa = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)->count();

        if ($cantFacturasEnOpa > 1) {
            $opa = $this->findRemoveFacturaMultiple((object) [
                'idFactura'    => $idFactura,
                'idOrdenPago'  => $opa->id_orden_pago,
            ]);
        }

        $opa = $this->findByUpdateEstado($opa->id_orden_pago, self::ESTADO_OPA_RECHAZADO, $motivo);
        // refresh: si la OPA se acaba de crear al desagrupar, el num_orden_pago lo asigna la base
        // y el modelo en memoria todavía no lo tiene.
        $opa->refresh();

        return [
            'anulada' => true,
            'opa' => $opa,
            'message' => 'Se anuló la orden de pago ' . $opa->num_orden_pago . '.'
        ];
    }

    public function findByConfirmarEstado($id_opa, $fechaPago, $estado)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);
        $tes->id_estado_orden_pago = $estado;
        $tes->fecha_confirma_pago = $fechaPago;
        $tes->update();
        return $tes->load(['proveedor']);
    }

    /**
     * Registra la fecha de confirmación del pago y DERIVA el estado de la OPA, en vez de
     * forzarlo a PAGADO. (2026-09-03)
     *
     * Reemplaza a findByConfirmarEstado($id, $fecha, '5') en el flujo de confirmación. La
     * diferencia importa: forzar el 5 es lo que dejó 5 órdenes cerradas como "pagadas" con
     * menos plata de la imputada (~$15,4M entre las dos bases — ver docs/circuito-pagos/plan-fase1-pagos.md
     * §6.bis). Con el estado derivado, un pago que no cubre el total deja la OPA en PAGO
     * PARCIAL, que es lo que pide el punto 4 del requerimiento.
     */
    public function findByConfirmarPagoDerivandoEstado($id_opa, $fechaPago)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);

        if (is_null($tes)) {
            return null;
        }

        $tes->fecha_confirma_pago = $fechaPago;
        $tes->update();

        $this->recalcularEstadoOpa($id_opa);

        return $tes->refresh()->load(['proveedor']);
    }

    public function findByConfirmarFechaProbablePago($id_opa, $fechaProbablePago, $cuotas)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);
        $tes->fecha_probable_pago = $fechaProbablePago;
        $tes->cuotas = $cuotas;
        $tes->update();
        return $tes->load(['proveedor']);
    }

    public function findByConfirmarPagoEmergencia($id_opa, $emergencia)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);
        $tes->pago_emergencia = $emergencia;
        $tes->update();
        return $tes->load(['proveedor']);
    }

    public function findByAnticipoPago($id_opa, $montoAnticipo)
    {
        $tes = TesOrdenPagoEntity::find($id_opa);
        $tes->monto_anticipado = $montoAnticipo;
        $tes->update();
        return $tes->load(['proveedor']);
    }

    public function findByExistsOpaEstado($id, $estado)
    {
        return TesOrdenPagoEntity::where('id_orden_pago', $id)
            ->where('id_estado_orden_pago', $estado)
            ->exists();
    }

    public function findById($idOpa)
    {
        return TesOrdenPagoEntity::find($idOpa);
    }

    public function findByIdFacturaEnProcesoOrPendiente($factura, $montoFactura)
    {
        // $idEstados = is_array([1, 4]);
        $opa = TesOrdenPagoEntity::where('id_factura', $factura)
            ->where('tipo_factura', 'PRESTADOR')
            ->whereIn('id_estado_orden_pago', [1, 4])
            ->first();
        if ($opa != null) {
            $opa->monto_orden_pago = $montoFactura;
            $opa->update();
        }
        return $opa ?? null;
    }

    /**
     * Recalcula el monto de la cabecera de una OPA sumando su detalle. (2026-08-11)
     *
     * La cabecera NUNCA debe mantenerse con `+=` / `-=`: si una operación fallaba a mitad,
     * el monto quedaba desincronizado del detalle y el error se propagaba en cada agrupado
     * posterior (se detectaron OPAs con el monto duplicado exacto en cadena).
     * El detalle es la fuente de verdad.
     */
    private function recalcularMontoDesdeDetalle($idOpa): float
    {
        $total = (float) TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOpa)
            ->sum('monto_factura');

        TesOrdenPagoEntity::where('id_orden_pago', $idOpa)
            ->update(['monto_orden_pago' => $total]);

        // La puente y el estado derivado se recalculan juntos: si cambió el detalle, cambió lo
        // imputado, y de ahí sale el estado. Engancharlo acá cubre de una todos los flujos que
        // tocan el detalle, en vez de repetirlo en cada uno. (2026-09-03)
        $this->sincronizarPuenteDesdeDetalle($idOpa);
        $this->recalcularEstadoOpa($idOpa);

        return $total;
    }

    /**
     * Borra las filas de la tabla puente de una OPA. Hay que llamarlo ANTES de borrar la OPA
     * físicamente: la FK fk_opaf_orden_pago es RESTRICT y bloquea el DELETE.
     *
     * Sin esto, agrupar y desagrupar se rompen apenas la puente tiene datos — verificado contra
     * la base: el DELETE falla con error 1451. (2026-09-03)
     */
    private function limpiarPuenteDeOpa($idOpa): void
    {
        TesFacturasOpaEntity::where('id_orden_pago', $idOpa)->delete();
    }

    /**
     * Indica si una OPA tiene pagos CONFIRMADOS, o sea: si ya salió plata. Es la guarda previa
     * a cualquier operación que modifique o borre una OPA — hacerlo con un pago confirmado
     * dejaría ese pago sin respaldo.
     *
     * OJO con el criterio (corregido 2026-09-03): antes contaba cualquier pago no rechazado,
     * incluidos los CREADOS PERO SIN CONFIRMAR. Un pago sin confirmar es solo "la orden se mandó
     * a Pagos con una fecha" — todavía no se movió un peso. Con el criterio viejo quedaban
     * bloqueadas 1.020 OPAs en OSV y 69 en Alba sin motivo: no se podían editar, ni gestionar
     * sus facturas, ni anular.
     *
     * ⚠️ **Se mide por lo COBRADO, no por un campo de la boleta** (corregido 2026-09-11).
     *
     * Antes alcanzaba con que la BOLETA tuviera `fecha_confirma_pago` o estado PAGADO. El
     * problema: ese campo de la boleta no prueba que haya salido plata — se completa al confirmar
     * la orden, cuando recién se arma el cronograma. Resultado: órdenes EN PROCESO sin un peso
     * cobrado rebotaban con *"la orden tiene pagos confirmados"*, un mensaje que además no dice
     * qué hacer. Reportado el 2026-09-11 al intentar "Anular y reemitir" una orden en proceso.
     *
     * `montoPagadoOpa()` ya sabe distinguir los dos circuitos y es la fuente de verdad del estado
     * derivado: si la boleta tiene ABONOS, mandan ellos (solo los que tienen fecha de
     * confirmación); si no tiene, vale la cabecera (el circuito viejo registraba el pago ahí, sin
     * desglose). Atar la guarda a esa misma función es lo que evita que la orden se bloquee por
     * una razón y se muestre pagada por otra.
     *
     * Un eCheq EMITIDO y todavía no acreditado no cuenta acá —no se cobró—, pero igual impide
     * anular: lo frena la guarda de instrumentos emitidos de `motivoQueImpideAnular()`, con un
     * mensaje que sí explica el paso a seguir (rechazarlo o anularlo primero).
     *
     * Un pago RECHAZADO nunca bloquea: se revirtió.
     */
    public function tienePagosConfirmados($idOpa): bool
    {
        return $this->montoPagadoOpa($idOpa) > 0.01;
    }

    /**
     * Deja la tabla puente (tb_tes_opa_factura) alineada con el detalle de una OPA.
     *
     * Durante la convivencia, tb_tes_orden_pago_detalle sigue siendo la que escriben los flujos
     * viejos (crear, agrupar, desagrupar). Sin esta sincronización, la puente quedaría como una
     * foto del momento de la migración y se iría desfasando con cada operación — y como de ella
     * salen el monto imputado y el estado derivado, todo lo nuevo empezaría a mentir.
     *
     * Se llama DESPUÉS de cualquier operación que toque el detalle. Es idempotente: agrega lo
     * que falta, actualiza el monto de lo que cambió y borra lo que ya no está.
     *
     * `monto_aplicado = monto_factura` es válido mientras no exista imputación parcial (hoy no
     * existe). Cuando llegue el FIFO (fase 4), esta sincronización se retira y la puente pasa a
     * escribirse directo. (2026-09-03)
     */
    public function sincronizarPuenteDesdeDetalle($idOpa): void
    {
        $detalle = TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOpa)->get();

        foreach ($detalle as $d) {
            TesFacturasOpaEntity::updateOrCreate(
                ['id_orden_pago' => $idOpa, 'id_factura' => $d->id_factura],
                [
                    'monto_aplicado'   => $d->monto_factura,
                    'fecha_imputacion' => $this->fechaActual,
                    'cod_usuario'      => $this->user->cod_usuario ?? null,
                ]
            );
        }

        // Las facturas que ya no están en el detalle salen también de la puente.
        TesFacturasOpaEntity::where('id_orden_pago', $idOpa)
            ->whereNotIn('id_factura', $detalle->pluck('id_factura')->all() ?: [0])
            ->delete();
    }

    /**
     * Total imputado a las facturas de una OPA (la tabla puente es la fuente de verdad).
     */
    public function montoImputadoOpa($idOpa): float
    {
        return (float) TesFacturasOpaEntity::where('id_orden_pago', $idOpa)->sum('monto_aplicado');
    }

    /**
     * Lo que realmente hay que pagarle al beneficiario por esta OPA.
     *
     * NO es lo mismo que `montoImputadoOpa()`. La imputación arrastra el **bruto** de la factura
     * (`total_neto`) — es la convención de siempre del sistema: relevado el 2026-09-05, de las
     * facturas con débito presentes en un detalle de OPA, 779 guardan el bruto y 0 el neto. Pero
     * al prestador se le paga el bruto **menos el débito de liquidación**, que es lo que la
     * grilla de Pagos ya venía mostrando como "Monto Neto OPA".
     *
     * Sin esta distinción pasaban dos cosas, las dos malas (ver
     * `docs/circuito-pagos/revisar-debito-no-descontado.md`):
     *
     *   - Pagar lo correcto dejaba la orden trabada en PAGO PARCIAL para siempre, porque lo
     *     pagado nunca alcanzaba el bruto.
     *   - Para cerrar la orden había que pagar el bruto, es decir, pagar de más. En el caso que
     *     lo destapó eran $1.060.351,04 de sobrepago sobre una orden de $78.960 reales.
     *
     * El `min()` es deliberado: una OPA nunca puede valer más que lo que la factura permite
     * cobrar, ni más de lo que se le imputó. Cubre también las facturas sobre-imputadas de
     * `reporte-danos-opa.md`, que antes inflaban el total.
     *
     * Para una OPA sin facturas (un ANTICIPO) devuelve 0, igual que `montoImputadoOpa()`.
     */
    public function montoPagableOpa($idOpa): float
    {
        $filas = DB::table('tb_tes_opa_factura as pf')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'pf.id_factura')
            ->where('pf.id_orden_pago', $idOpa)
            ->select('pf.monto_aplicado', 'f.total_neto', 'f.total_debitado_liquidacion')
            ->get();

        $total = 0.0;

        foreach ($filas as $fila) {
            $pagableFactura = max(
                0.0,
                (float) $fila->total_neto - (float) $fila->total_debitado_liquidacion
            );

            $total += min((float) $fila->monto_aplicado, $pagableFactura);
        }

        return round($total, 2);
    }

    /**
     * Razón social (entidad pagadora del grupo) a la que pertenece una OPA.
     *
     * No es una columna propia de la orden: sale de sus facturas
     * (`tb_tes_orden_pago_detalle` -> `tb_facturacion_datos.id_locatorio`), igual que el filtro
     * de Carga de eCheq. Un ANTICIPO no tiene facturas imputadas, así que devuelve null y las
     * validaciones que dependan de esto no se aplican.
     */
    public function razonSocialDeOpa($idOpa): ?int
    {
        $razones = $this->razonesSocialesDeOpa($idOpa);

        // Con más de una razón social no hay respuesta única, y devolver cualquiera de ellas es
        // peor que no contestar: el `->value()` que había acá agarraba una arbitraria. En la
        // OPA-1102 (id 3152) devolvía la razón 1, que son $273.838 de un total de $12.312.369 —
        // el 2%. El sistema exigía pagar los $12,3M desde una cuenta de esa razón y rechazaba la
        // que corresponde al 98% restante. (2026-09-09)
        return count($razones) === 1 ? $razones[0] : null;
    }

    /**
     * TODAS las razones sociales (entidades pagadoras del grupo) que aparecen en las facturas de
     * una OPA.
     *
     * Normalmente es una sola: una orden se le paga a un beneficiario por cuenta de una entidad.
     * Que haya más es una anomalía de datos —una sola en todo el sistema al 2026-09-09, la
     * OPA-1102— pero el código no puede resolverla eligiendo una al azar.
     *
     * Un ANTICIPO no tiene facturas imputadas: devuelve vacío, y las validaciones que dependen
     * de esto no se aplican.
     */
    public function razonesSocialesDeOpa($idOpa): array
    {
        return DB::table('tb_tes_orden_pago_detalle as od')
            ->join('tb_facturacion_datos as fd', 'fd.id_factura', '=', 'od.id_factura')
            ->where('od.id_orden_pago', $idOpa)
            ->whereNotNull('fd.id_locatorio')
            ->distinct()
            ->pluck('fd.id_locatorio')
            ->map(fn($r) => (int) $r)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Total efectivamente pagado de una OPA: suma de sus pagos CONFIRMADOS.
     *
     * OJO con el monto: `monto_pago` viene NULL en buena parte de los pagos confirmados
     * (100 de 251 en Alba, 74 de 103 en OSV — relevado 2026-09-03), mientras que `monto_opa`
     * nunca lo está. Usar solo `monto_pago` daría 0 para la mayoría de los pagos de OSV y el
     * estado derivado saldría mal.
     */
    public function montoPagadoOpa($idOpa): float
    {
        $boletas = TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
            ->get();

        if ($boletas->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($boletas as $boleta) {
            // Si la boleta tiene ABONOS, ellos son la verdad: cada uno es un cheque/eCheq con su
            // propio monto y su propia fecha de confirmación. La cabecera puede venir con
            // `monto_pago` vacío (100 de 251 boletas con abonos lo tienen así), y caer al
            // `monto_opa` de respaldo reportaría el total de la orden como si estuviera cobrado.
            // (2026-09-04)
            $abonos = \App\Models\Tesoreria\TesPagosParciales::where('id_pago', $boleta->id_pago)->get();

            if ($abonos->isNotEmpty()) {
                $total += (float) $abonos
                    ->filter(fn($a) => !is_null($a->fecha_confirma_pago))
                    ->sum('monto_pago');

                continue;
            }

            // Sin abonos, vale la cabecera. El respaldo a `monto_opa` se mantiene porque en OSV
            // la mayoría de los pagos viejos tiene `monto_pago` en null y sin él el estado
            // derivado saldría mal.
            $confirmada = (int) $boleta->id_estado_orden_pago === self::ESTADO_OPA_PAGADO
                || !is_null($boleta->fecha_confirma_pago);

            if ($confirmada) {
                $total += (float) ($boleta->monto_pago ?? $boleta->monto_opa ?? 0);
            }
        }

        return $total;
    }

    /**
     * Deriva y persiste el estado de una OPA comparando lo imputado contra lo pagado.
     *
     * El requerimiento pide explícitamente que el estado NO se pueda setear a mano
     * (punto 4 del Circuito de Pagos): se calcula. Hasta ahora se asignaba imperativamente en
     * cada lugar donde pasaba algo, que es de donde salían las inconsistencias.
     *
     *   pagado == 0            -> PENDIENTE
     *   0 < pagado < imputado  -> PAGO PARCIAL
     *   pagado >= imputado     -> PAGADO
     *
     * Una OPA RECHAZADA/anulada no se recalcula: es una decisión administrativa, no un estado
     * derivable de los montos. Devuelve el estado resultante.
     */
    /**
     * Lo que efectivamente cubre a una OP.
     *
     * Para una OP normal es lo pagado por sus instrumentos. Para una de tipo APLICACION es su
     * propio monto: esa OP no tiene pagos porque la plata salió cuando se pagó el ANTICIPO del
     * que consume saldo. Sin esta distinción, una aplicación quedaría PENDIENTE para siempre y
     * el FIFO diría que sus facturas no se pagaron nunca. (2026-09-03)
     *
     * `montoPagadoOpa()` se deja como está: sigue significando "plata que entró por un
     * instrumento", que es la verdad sobre los instrumentos y lo que necesita la tesorería.
     */
    public function montoCubiertoOpa($idOpa): float
    {
        $opa = TesOrdenPagoEntity::find($idOpa);

        if (is_null($opa)) {
            return 0.0;
        }

        if ($opa->tipo_opa === TesAnticipoRepository::TIPO_APLICACION) {
            return (float) $opa->monto_orden_pago;
        }

        return $this->montoPagadoOpa($idOpa);
    }

    /**
     * Pone el estado de las BOLETAS de una orden al día, con el mismo criterio que la orden.
     *
     * La grilla de Pagos muestra `tb_tes_pago.id_estado_orden_pago` directo como badge, así que
     * cuando la boleta y su OPA usan criterios distintos el usuario ve dos verdades sobre lo
     * mismo. Pasó en los dos sentidos:
     *
     *  - Confirmar el pago movía la boleta y no la orden (corregido el 2026-09-10).
     *  - Acreditar un eCheq movía la orden y no la boleta: la orden quedaba PAGADA y la grilla de
     *    Pagos seguía mostrando PAGO PARCIAL hasta que alguien volvía a confirmar. (2026-09-10)
     *
     * Cuenta lo mismo que la orden: abonos VIVOS y CONFIRMADOS. Un eCheq emitido pero todavía sin
     * acreditar no es plata cobrada.
     *
     * Una boleta RECHAZADA no se toca: esa está dada de baja a propósito. Y una boleta SIN abonos
     * tampoco: son las del circuito viejo, donde el pago se registraba en la cabecera sin
     * desglose, y no hay de dónde derivarle un estado.
     */
    public function recalcularEstadoBoletas($idOpa): void
    {
        $pagable = $this->montoPagableOpa($idOpa);

        foreach (TesPagoEntity::where('id_orden_pago', $idOpa)->get() as $boleta) {
            if ((int) $boleta->id_estado_orden_pago === self::ESTADO_OPA_RECHAZADO) {
                continue;
            }

            $abonos = \App\Models\Tesoreria\TesPagosParciales::where('id_pago', $boleta->id_pago)
                ->vivos()
                ->get();

            // ⚠️ Una boleta SIN abonos vivos puede ser dos cosas distintas, y tratarlas igual era
            // el bug: si nunca tuvo abonos es del circuito viejo —el pago vive en la cabecera— y
            // no hay que tocarla; si los tuvo y se dieron todos de baja, hay que **bajarla** de
            // estado. Se distinguen mirando si existe alguna fila, viva o muerta.
            //
            // Reportado sobre la OPA-1408 (id 4479): se rechazaron los dos eCheq, la orden volvió
            // a EN PROCESO —correcto— pero la boleta quedó en PAGO PARCIAL, que es lo que muestra
            // la grilla de Pagos. (2026-09-15)
            $tuvoAbonos = \App\Models\Tesoreria\TesPagosParciales::where('id_pago', $boleta->id_pago)
                ->exists();

            if ($abonos->isEmpty() && !$tuvoAbonos) {
                continue;
            }

            $tope = $pagable > 0 ? $pagable : (float) $boleta->monto_opa;

            if ($tope <= 0) {
                continue;
            }

            $cobrado = (float) $abonos
                ->filter(fn($a) => !is_null($a->fecha_confirma_pago))
                ->sum('monto_pago');

            $aCentavos = fn($m) => (int) round(((float) $m) * 100);

            if ($aCentavos($cobrado) >= $aCentavos($tope)) {
                $estado = self::ESTADO_OPA_PAGADO;
            } elseif ($cobrado > 0.01) {
                $estado = self::ESTADO_OPA_PAGO_PARCIAL;
            } else {
                // Nada cobrado, pero la boleta y su cronograma existen: EN PROCESO, igual que la
                // orden. Antes esta rama era un `continue`, así que el estado sólo podía SUBIR —
                // una boleta que llegó a PAGO PARCIAL se quedaba ahí para siempre aunque después
                // se dieran de baja todos sus pagos.
                $estado = self::ESTADO_OPA_EN_PROCESO;
            }

            if ((int) $boleta->id_estado_orden_pago !== $estado) {
                $boleta->id_estado_orden_pago = $estado;
                $boleta->save();
            }
        }
    }

    public function recalcularEstadoOpa($idOpa): int
    {
        $opa = TesOrdenPagoEntity::find($idOpa);

        if (is_null($opa)) {
            return 0;
        }

        if ((int) $opa->id_estado_orden_pago === self::ESTADO_OPA_RECHAZADO) {
            return self::ESTADO_OPA_RECHAZADO;
        }

        // Se compara contra lo PAGABLE (imputado menos débito de liquidación), no contra lo
        // imputado a secas: la imputación arrastra el bruto de la factura y comparar contra eso
        // dejaba trabada en PAGO PARCIAL a toda orden que pagara lo que corresponde. (2026-09-05)
        $imputado = $this->montoPagableOpa($idOpa);
        $pagado   = $this->montoCubiertoOpa($idOpa);

        if ($imputado > 0 && $pagado >= $imputado - 0.01) {
            $nuevo = self::ESTADO_OPA_PAGADO;
        } elseif ($pagado > 0.01) {
            $nuevo = self::ESTADO_OPA_PAGO_PARCIAL;
        } elseif ($this->tieneAlgunPago($idOpa)) {
            // Sin plata cobrada todavía, pero la orden YA tiene su boleta y su cronograma: eso
            // es EN PROCESO, no PENDIENTE. Sin esta rama el recálculo devolvía la orden a
            // PENDIENTE apenas se acreditaba o rechazaba cualquier abono, deshaciendo el estado
            // que pone `findByCrearPago` al confirmarla — y con eso volvía el 409 de
            // "esta orden ya tiene un pago generado" al reaparecer el botón Confirmar OPA.
            // (2026-09-05)
            $nuevo = self::ESTADO_OPA_EN_PROCESO;
        } else {
            $nuevo = self::ESTADO_OPA_PENDIENTE;
        }

        if ((int) $opa->id_estado_orden_pago !== $nuevo) {
            $opa->id_estado_orden_pago = $nuevo;
            $opa->save();
        }

        // Las boletas van con la orden. Engancharlo acá y no en cada llamador cubre de una todos
        // los caminos que mueven plata —confirmar, acreditar, rechazar, anular— y hace imposible
        // que la grilla de Pagos y el visor de OPA vuelvan a mostrar estados distintos.
        $this->recalcularEstadoBoletas($idOpa);

        return $nuevo;
    }

    /**
     * ¿La OPA tiene ALGÚN pago registrado, confirmado o no (excluyendo los rechazados)?
     *
     * Es una guarda MÁS ESTRICTA que tienePagosConfirmados(), y se usa únicamente antes de un
     * BORRADO FÍSICO de la OPA. La diferencia importa: para editar o anular alcanza con que no
     * haya plata movida, porque la OPA sigue existiendo y el pago sin confirmar mantiene su
     * referencia. Pero si la OPA se borra, cualquier pago que la apunte queda huérfano — que es
     * exactamente el problema que se limpió en agosto. (2026-09-03)
     */
    public function tieneAlgunPago($idOpa): bool
    {
        return TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
            ->exists();
    }

    /**
     * @deprecated Usar tienePagosConfirmados(). Se mantiene el nombre viejo por compatibilidad
     * con los llamadores existentes, pero el criterio es el nuevo.
     */
    public function tienePagosVivos($idOpa): bool
    {
        return $this->tienePagosConfirmados($idOpa);
    }

    /**
     * Genera una OPA a partir de una o varias facturas seleccionadas.
     *
     * Hasta el 2026-08-13 asumía que toda factura seleccionada YA tenía su OPA (creada
     * automáticamente al valorizar) y solo las fusionaba. Al dejar de generarse sola en
     * Valorización Final, las facturas de prestador llegan acá SIN OPA: con la versión vieja
     * `$first` quedaba en null, el método no hacía nada y el controller devolvía éxito igual
     * (bug silencioso).
     *
     * Ahora resuelve cada factura por separado: las que ya tienen OPA VIGENTE se fusionan como
     * antes (circuito proveedor, que sigue generándola al cargar la factura), y las que no
     * tienen (incluida una factura cuya única OPA está RECHAZADA) arman su detalle directo desde
     * tb_facturacion_datos. Crear una sola OPA individual y agrupar varias quedan unificadas en
     * el mismo camino.
     *
     * Una OPA rechazada NO cuenta como "ya tiene OPA": se ignora a propósito, no se fusiona ni
     * se borra, para no perder el motivo_rechazo. Si no se excluyera, una factura con una OPA
     * anulada quedaría sin forma de generar una nueva (el front la oculta) o, peor, la
     * generación borraría el historial de por qué se rechazó la anterior. (2026-08-13)
     */
    /**
     * Genera una OPA desde la pantalla Tesorería › Crear OPA, imputando un monto por factura.
     *
     * Es el reemplazo del checkbox + "Generar OPA" del visor de liquidaciones. La diferencia de
     * fondo con `findByIdFacturaMultiple()` es que acá cada factura entra con **su** monto y no
     * necesariamente entera, así que:
     *
     *   - no fusiona ni borra OPAs existentes (no hace falta: si una factura ya está imputada a
     *     una orden pendiente, lo que queda libre es el saldo y eso es lo que se ofrece);
     *   - `monto_aplicado` es NETO de débito. Ver el encabezado de `FacturasOpaRepository`.
     *
     * `monto_factura` del detalle viejo se escribe con el MISMO valor que `monto_aplicado` de la
     * puente. No es casualidad ni redundancia: `recalcularMontoDesdeDetalle()` suma `monto_factura`
     * para la cabecera y después llama a `sincronizarPuenteDesdeDetalle()`, que pisa
     * `monto_aplicado` con `monto_factura`. Si se escribieran distinto, la primera operación que
     * tocara el detalle borraría la imputación parcial y la dejaría en el bruto de la factura.
     * El invariante es: **detalle.monto_factura == puente.monto_aplicado == lo imputado**. Para
     * una OPA por la factura entera coincide con `total_neto`, igual que siempre.
     *
     * Todo el monto se revalida contra el saldo del servidor: el que viene del front es una
     * sugerencia. Y las facturas se toman con `lockForUpdate()` ANTES de calcular saldos, si no
     * dos usuarios imputando a la vez la misma factura pueden pasar los dos la validación.
     *
     * @param  object $request  id_prestador?, observaciones?, fecha_emision?, fecha_vencimiento?,
     *                          fecha_probable_pago?, id_moneda?,
     *                          facturas: [{id_factura, monto_aplicado}, ...]
     * @return TesOrdenPagoEntity
     */
    public function procesarOpaAgrupada($request)
    {
        $saldos = new FacturasOpaRepository();

        $lineas = collect($request->facturas ?? [])
            ->map(fn($f) => (object) (array) $f)
            ->filter(fn($f) => !empty($f->id_factura))
            ->values();

        if ($lineas->isEmpty()) {
            throw new \Exception('No se recibieron facturas para generar la orden de pago.');
        }

        $ids = $lineas->pluck('id_factura');
        if ($ids->unique()->count() !== $ids->count()) {
            throw new \Exception('Hay una factura repetida en la selección.');
        }

        // Bloqueo ANTES de leer saldos: con la fila tomada, el saldo que se calcula abajo no puede
        // cambiar por debajo hasta el commit.
        $facturas = FacturacionDatosEntity::whereIn('id_factura', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id_factura');

        $faltantes = $ids->diff($facturas->keys());
        if ($faltantes->isNotEmpty()) {
            throw new \Exception('No se encontró la factura ' . $faltantes->implode(', ') . '.');
        }

        // Todas del mismo TIPO. No se mezcla una factura de proveedor con una de prestador: el
        // beneficiario de la orden es uno solo y sale de una tabla distinta en cada caso.
        //
        // `id_tipo_factura == 16` (BIENES Y SERVICIOS) es la señal canónica de proveedor, no
        // "tiene id_proveedor": hay facturas con los dos ids cargados a la vez (dato sucio) y
        // decidir por cuál no es null las clasifica mal en un sentido o en el otro.
        $tipos = $facturas
            ->map(fn($f) => (int) $f->id_tipo_factura === FacturasOpaRepository::TIPO_FACTURA_PROVEEDOR)
            ->unique();

        if ($tipos->count() > 1) {
            throw new \Exception(
                'No se puede generar: hay facturas de proveedor y de prestador en la misma selección.'
            );
        }

        $esProveedor = (bool) $tipos->first();
        $campoBeneficiario = $esProveedor ? 'id_proveedor' : 'id_prestador';
        $etiqueta = $esProveedor ? 'proveedor' : 'prestador';

        // Y todas del mismo beneficiario. El front ya lo valida, pero quien pegue al endpoint
        // directo se lo saltea: sin esto se podían mezclar dos en una OPA y el beneficiario
        // quedaba mal asignado para uno de los dos. (mismo criterio que findByIdFacturaMultiple,
        // 2026-08-13)
        $beneficiarios = $facturas->pluck($campoBeneficiario)->unique()->values();

        if ($beneficiarios->count() > 1) {
            throw new \Exception(
                "No se puede generar: las facturas seleccionadas pertenecen a distintos {$etiqueta}es."
            );
        }

        $idBeneficiario = $beneficiarios->first();
        if (empty($idBeneficiario)) {
            throw new \Exception("Las facturas seleccionadas no tienen {$etiqueta} asignado.");
        }

        // ⚠️ Y todas de la MISMA RAZÓN SOCIAL.
        //
        // `id_locatorio` es cuál de nuestras empresas le debe la factura. Una orden se paga desde
        // **una** cuenta bancaria, que pertenece a una sola razón social: si la orden mezcla dos,
        // el desplegable de cuentas ofrece las de las dos entidades y se termina pagando la deuda
        // de una empresa con la plata de la otra. Las dos contabilidades quedan cruzadas.
        //
        // Mismo prestador NO implica misma razón social: un prestador le puede facturar a dos
        // empresas del grupo. Eso no es un error de datos — simplemente necesita una orden por
        // cada una.
        //
        // Reportado el 2026-09-16 sobre la OPA-16002 (id 6395): mezclaba una factura de GRUPO ALBA
        // con una de MEDICINA PRIVADA, y al ir a pagar aparecían las cuentas de Alba en una orden
        // de Medicina. La validación de prestador ya estaba; esta faltaba.
        $razones = $facturas->pluck('id_locatorio')->filter()->unique()->values();

        if ($razones->count() > 1) {
            $nombres = DB::table('tb_razones_sociales')
                ->whereIn('id_razon', $razones)
                ->pluck('razon_social')
                ->implode(' y ');

            throw new \Exception(
                'No se puede generar: las facturas seleccionadas son de distintas razones sociales ('
                    . $nombres . '). Una orden de pago se paga desde una sola cuenta bancaria, que '
                    . 'pertenece a una sola empresa — hay que generar una orden por cada una.'
            );
        }

        $montoTotal = 0.0;
        $imputaciones = [];

        foreach ($lineas as $linea) {
            $factura = $facturas[$linea->id_factura];

            // La misma columna `estado` usa dos catalogos: para prestador el 3 es Valorizacion
            // Final, y para proveedor su equivalente es el 1 (CONFIRMADA) — no pasa por
            // liquidacion, nace final. Ver las constantes de FacturasOpaRepository.
            $estadoQueHabilita = $esProveedor
                ? FacturasOpaRepository::ESTADO_FACTURA_PROVEEDOR_CONFIRMADA
                : FacturasOpaRepository::ESTADO_FACTURA_VALORIZACION_FINAL;

            if ((int) $factura->estado !== $estadoQueHabilita) {
                $exigido = $esProveedor ? 'confirmada' : 'en Valorización Final';

                throw new \Exception(
                    "La factura {$factura->numero} no está {$exigido}, no se le puede generar una orden de pago."
                );
            }

            $monto = round((float) ($linea->monto_aplicado ?? 0), 2);
            $saldo = $saldos->saldoImputableFactura($factura);

            if ($monto <= 0) {
                throw new \Exception("El monto a imputar a la factura {$factura->numero} tiene que ser mayor a 0.");
            }

            // Tolerancia de un centavo: el front redondea a 2 decimales y no puede quedar
            // rebotando por un error de coma flotante cuando pide exactamente el saldo entero.
            if ($monto > $saldo + 0.01) {
                throw new \Exception(
                    "El monto imputado a la factura {$factura->numero} ($" . number_format($monto, 2, ',', '.')
                    . ") supera su saldo disponible ($" . number_format($saldo, 2, ',', '.') . ")."
                );
            }

            $monto = min($monto, $saldo);
            $montoTotal += $monto;
            $imputaciones[$factura->id_factura] = $monto;
        }

        $esAgrupada = count($imputaciones) > 1;
        $primera = $facturas->first();

        $opa = TesOrdenPagoEntity::create([
            'id_proveedor' => $esProveedor ? $idBeneficiario : null,
            'id_prestador' => $esProveedor ? null : $idBeneficiario,
            'monto_orden_pago' => round($montoTotal, 2),
            'id_moneda' => $request->id_moneda ?? 1,
            // `?? null` antes del `?:`: el `?:` solo NO emite warning cuando el operando existe.
            // Con un Request da igual (devuelve null para lo que no vino), pero con un stdClass
            // —como lo llaman los tests, o cualquier llamador interno— tira
            // "Undefined property" por cada fecha opcional que no se mandó.
            'fecha_emision' => ($request->fecha_emision ?? null) ?: ($primera->fecha_comprobante ?? $this->fechaActual),
            'fecha_vencimiento' => ($request->fecha_vencimiento ?? null) ?: ($primera->fecha_vencimiento ?? null),
            'fecha_probable_pago' => ($request->fecha_probable_pago ?? null) ?: null,
            'id_estado_orden_pago' => self::ESTADO_OPA_PENDIENTE,
            'monto_anticipado' => 0,
            'observaciones' => $request->observaciones ?? '',
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_genera' => $this->fechaActual,
            // Sin esto la columna toma su DEFAULT, que es 'PROVEEDOR': la OPA nacía etiquetada al
            // revés, el beneficiario salía vacío en los comprobantes y el agrupado se rompía
            // porque la clave del beneficiario se arma con este campo. 129 OPAs quedaron así
            // antes de detectarlo. (2026-09-04)
            'tipo_factura' => $esProveedor ? 'PROVEEDOR' : 'PRESTADOR',
            // Una OPA de una sola factura conserva la referencia en la cabecera; la agrupada la
            // deja en NULL y el vínculo vive en el detalle/puente (ver findByOpaVigenteFactura).
            'id_factura' => $esAgrupada ? null : array_key_first($imputaciones),
        ]);

        foreach ($imputaciones as $idFactura => $monto) {
            TesOrdenPagoDetalleEntity::create([
                'id_orden_pago' => $opa->id_orden_pago,
                'id_factura' => $idFactura,
                'monto_factura' => $monto,
                'tipo_factura' => $esProveedor ? 'PROVEEDOR' : 'PRESTADOR',
                'factura_unida' => $esAgrupada ? 1 : 0,
            ]);
        }

        // Deja la puente alineada con el detalle y recalcula el estado derivado. La cabecera se
        // recalcula de paso desde el detalle, así que el monto de arriba es solo el valor inicial.
        $this->recalcularMontoDesdeDetalle($opa->id_orden_pago);

        // num_orden_pago lo asigna la base por trigger: sin refresh vuelve null al front.
        $opa->refresh();

        return $opa;
    }

    /**
     * @deprecated 2026-09-16 — **sin llamadores desde la interfaz.**
     *
     * Era el "Generar OPA" de los dos visores: el de liquidaciones (prestadores) y el de facturas
     * de proveedores. Los dos se sacaron, y las órdenes se arman ahora en **Tesorería › Crear
     * OPA** (`procesarOpaAgrupada()`), que además permite imputar un monto parcial por factura.
     *
     * Se conserva un tiempo en vez de borrarlo: son ~180 líneas con guardas que costó afinar
     * (fusión de OPAs pendientes, pagos vivos, beneficiario único, razón social única), y si
     * apareciera un consumidor que no vimos conviene que falle ruidosamente arriba y no acá.
     * Sacarlo —junto con la ruta `generar-multiple_fc` y `getGenerarMultipleOpa`— cuando el
     * circuito nuevo esté validado en producción.
     *
     * OJO: `findAddFacturaMultiple()` es OTRO método y **sigue vivo** (agregar una factura a una
     * OPA existente, desde el modal de OPAs agrupadas). No se borra con este.
     */
    public function findByIdFacturaMultiple($idFacturas)
    {
        $idFacturas = collect($idFacturas)->filter()->unique()->values();

        if ($idFacturas->isEmpty()) {
            throw new \Exception('No se recibieron facturas para generar la orden de pago.');
        }

        // Solo se fusionan OPAs PENDIENTES: es el único estado que significa "todavía no la tocó
        // Tesorería". Una RECHAZADA se ignora (ver arriba). Una APROBADA/EN PROCESO/PAGADA, aunque
        // no tenga un pago registrado todavía, ya representa una decisión tomada — fusionarla la
        // borraría en silencio sin que nadie la autorizara. (2026-08-13)
        $detalleExistente = TesOrdenPagoDetalleEntity::whereIn('id_factura', $idFacturas)
            ->whereIn('id_orden_pago', function ($q) {
                $q->select('id_orden_pago')
                    ->from('tb_tes_orden_pago')
                    ->where('id_estado_orden_pago', self::ESTADO_OPA_PENDIENTE);
            })
            ->get();
        $idOrdenesExistentes = $detalleExistente->pluck('id_orden_pago')->unique()->values()->toArray();

        // Facturas cuya única OPA está en un estado distinto de PENDIENTE/RECHAZADO (APROBADA,
        // EN PROCESO, PAGADA): no se pueden agrupar ni regenerar. Se avisa explícito en vez de
        // ignorarlas en silencio, para que quede claro por qué no aparecen en el resultado.
        $opasNoAgrupables = TesOrdenPagoDetalleEntity::whereIn('id_factura', $idFacturas)
            ->whereIn('id_orden_pago', function ($q) {
                $q->select('id_orden_pago')
                    ->from('tb_tes_orden_pago')
                    ->whereNotIn('id_estado_orden_pago', [self::ESTADO_OPA_PENDIENTE, self::ESTADO_OPA_RECHAZADO]);
            })
            ->get();
        if ($opasNoAgrupables->isNotEmpty()) {
            $ordenes = TesOrdenPagoEntity::whereIn('id_orden_pago', $opasNoAgrupables->pluck('id_orden_pago')->unique())
                ->get()->keyBy('id_orden_pago');
            $detalle = $opasNoAgrupables->map(function ($d) use ($ordenes) {
                $num = $ordenes[$d->id_orden_pago]->num_orden_pago ?? $d->id_orden_pago;
                return "factura {$d->id_factura} (orden de pago {$num})";
            })->implode(', ');
            throw new \Exception(
                "No se puede agrupar: " . $detalle . " ya tiene una orden de pago que avanzó de estado. "
                . "Para modificarla, primero debe anularse o rechazarse."
            );
        }

        // Guarda ESTRICTA (tieneAlgunPago, no tienePagosConfirmados): más abajo estas OPAs se
        // borran físicamente, así que cualquier pago que las apunte quedaría huérfano — incluso
        // uno sin confirmar. (2026-08-11, criterio precisado 2026-09-03)
        foreach ($idOrdenesExistentes as $idOrdenExistente) {
            if ($this->tieneAlgunPago($idOrdenExistente)) {
                throw new \Exception(
                    "No se puede agrupar: la orden de pago {$idOrdenExistente} ya tiene pagos registrados."
                );
            }
        }

        // Facturas seleccionadas que todavía no están en ninguna OPA
        $facturasSinOpa = $idFacturas->diff($detalleExistente->pluck('id_factura'));
        $facturasDb = FacturacionDatosEntity::whereIn('id_factura', $facturasSinOpa)
            ->get()
            ->keyBy('id_factura');

        $faltantes = $facturasSinOpa->diff($facturasDb->keys());
        if ($faltantes->isNotEmpty()) {
            throw new \Exception('No se encontró la factura ' . $faltantes->implode(', ') . '.');
        }

        // Todas las facturas (las que ya tenían OPA pendiente y las que no) tienen que ser del
        // mismo beneficiario. El frontend ya lo valida, pero eso no alcanza: quien llame al
        // endpoint directo se lo saltea. Sin esto, se podía fusionar facturas de dos prestadores
        // distintos en una sola OPA y quedarle mal asignado el beneficiario a una de ellas.
        // (2026-08-13)
        //
        // OJO: no usar `id_proveedor ?? id_prestador` — hay facturas con los dos campos
        // cargados a la vez (dato sucio, ver FacturacionProcesosController). Elegir el campo
        // por el `tipo_factura`/`id_tipo_factura==16`, nunca por cuál no sea null.
        //
        // ->all() + collect(): Eloquent\Collection::merge() asume que fusiona modelos (usa
        // getKey() en cada item) y explota si le pasás strings, aunque map() ya los haya
        // convertido — hay que salir a una Collection plana antes de mezclar.
        $opasExistentesTodas = TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->get();
        $beneficiarios = collect(
            $opasExistentesTodas->map(function ($o) {
                $id = $o->tipo_factura === 'PROVEEDOR' ? $o->id_proveedor : $o->id_prestador;
                return $o->tipo_factura . ':' . $id;
            })->all()
        )->merge(
            $facturasDb->map(function ($f) {
                $tipo = $f->id_tipo_factura == 16 ? 'PROVEEDOR' : 'PRESTADOR';
                $id = $tipo === 'PROVEEDOR' ? $f->id_proveedor : $f->id_prestador;
                return $tipo . ':' . $id;
            })->all()
        )->unique();

        if ($beneficiarios->count() > 1) {
            throw new \Exception(
                'No se puede generar: las facturas seleccionadas pertenecen a distintos proveedores/prestadores.'
            );
        }

        // Y de la MISMA RAZÓN SOCIAL, por el mismo motivo que en `procesarOpaAgrupada()`: la orden
        // se paga desde una sola cuenta bancaria, y esa cuenta pertenece a una sola empresa del
        // grupo. Mezclarlas termina pagando la deuda de una con la plata de la otra.
        //
        // Este camino quedó sirviendo solo al visor de PROVEEDORES: el botón "Generar OPA" de
        // liquidaciones se sacó el 2026-09-16 y los prestadores van por Tesorería › Crear OPA. Se
        // le pone igual la guarda porque era la misma puerta abierta — de acá salió la OPA-1102,
        // anotada como anomalía preexistente en `revisar-pagos-razon-social-cruzada.md`.
        //
        // Se mira `id_locatorio` de TODAS las facturas involucradas: las que ya tenían OPA
        // pendiente (vía su detalle) y las que se agregan ahora.
        $razonesInvolucradas = FacturacionDatosEntity::whereIn(
            'id_factura',
            $detalleExistente->pluck('id_factura')->merge($facturasDb->keys())->unique()
        )->pluck('id_locatorio')->filter()->unique()->values();

        if ($razonesInvolucradas->count() > 1) {
            $nombres = DB::table('tb_razones_sociales')
                ->whereIn('id_razon', $razonesInvolucradas)
                ->pluck('razon_social')
                ->implode(' y ');

            throw new \Exception(
                'No se puede generar: las facturas seleccionadas son de distintas razones sociales ('
                    . $nombres . '). Una orden de pago se paga desde una sola cuenta bancaria, que '
                    . 'pertenece a una sola empresa — hay que generar una orden por cada una.'
            );
        }

        // Referencia para prestador/proveedor/moneda/fechas de la cabecera nueva: la OPA
        // existente si hay alguna, si no la primera factura sin OPA de la selección.
        $opaExistente = TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->first();
        $ref = $opaExistente ?? $facturasDb->first();

        if (is_null($ref)) {
            throw new \Exception('No se encontraron facturas para generar la orden de pago.');
        }

        $esAgrupada = $idFacturas->count() > 1;

        // Mismo criterio que la validación de beneficiario de más arriba: el tipo lo decide
        // `id_tipo_factura == 16`, nunca "cuál de los dos campos no es null".
        $tipoBeneficiario = $opaExistente->tipo_factura
            ?? (($facturasDb->first()->id_tipo_factura ?? null) == 16 ? 'PROVEEDOR' : 'PRESTADOR');

        // El total sale del DETALLE (existente + a crear), nunca de sumar cabeceras: si alguna
        // venía con el monto desincronizado, sumarlas propagaba el error. (2026-08-11)
        $totalMonto = (float) $detalleExistente->sum('monto_factura')
            + (float) $facturasDb->sum('total_neto');

        $newOpa = TesOrdenPagoEntity::create([
            'id_proveedor' => $ref->id_proveedor ?? null,
            'id_prestador' => $ref->id_prestador ?? null,
            'monto_orden_pago' => $totalMonto,
            'id_moneda' => $ref->id_moneda ?? 1,
            'fecha_emision' => $ref->fecha_emision ?? $ref->fecha_comprobante ?? $this->fechaActual,
            'fecha_vencimiento' => $ref->fecha_vencimiento ?? null,
            'fecha_probable_pago' => $ref->fecha_probable_pago ?? null,
            'id_estado_orden_pago' => $opaExistente->id_estado_orden_pago ?? self::ESTADO_OPA_PENDIENTE,
            'monto_anticipado' => $opaExistente->monto_anticipado ?? 0,
            'observaciones' => $opaExistente->observaciones ?? '',
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_genera' => $this->fechaActual,
            // Sin esto la columna tomaba su DEFAULT, que es 'PROVEEDOR': toda OPA generada desde
            // liquidaciones nacía etiquetada como proveedor aunque la factura fuera de prestador.
            // Rompía el agrupado ("pertenecen a distintos prestadores", porque la clave del
            // beneficiario se arma con este campo) y hacía que el beneficiario saliera vacío en
            // los listados y comprobantes. 129 OPAs quedaron así. (2026-09-04)
            'tipo_factura' => $tipoBeneficiario,
            // Una OPA de una sola factura conserva la referencia en la cabecera; la agrupada la
            // deja en NULL y la relación vive solo en el detalle (ver findByOpaVigenteFactura).
            'id_factura' => $esAgrupada ? null : $idFacturas->first(),
        ]);

        // Detalle heredado de las OPAs que se fusionan
        foreach ($detalleExistente as $det) {
            TesOrdenPagoDetalleEntity::create([
                'id_orden_pago' => $newOpa->id_orden_pago,
                'id_factura' => $det->id_factura,
                'monto_factura' => $det->monto_factura,
                'tipo_factura' => $det->tipo_factura,
                'factura_unida' => $esAgrupada ? 1 : 0,
            ]);
        }

        // Detalle de las facturas que no tenían OPA
        foreach ($facturasDb as $factura) {
            TesOrdenPagoDetalleEntity::create([
                'id_orden_pago' => $newOpa->id_orden_pago,
                'id_factura' => $factura->id_factura,
                'monto_factura' => $factura->total_neto,
                // id_tipo_factura == 16 ("BIENES Y SERVICIOS") es la señal real de proveedor.
                'tipo_factura' => $factura->id_tipo_factura == 16 ? 'PROVEEDOR' : 'PRESTADOR',
                'factura_unida' => $esAgrupada ? 1 : 0,
            ]);
        }

        if (!empty($idOrdenesExistentes)) {
            // La puente PRIMERO: su FK es RESTRICT y bloquea el borrado de la OPA.
            foreach ($idOrdenesExistentes as $idOrdenExistente) {
                $this->limpiarPuenteDeOpa($idOrdenExistente);
            }
            TesOrdenPagoDetalleEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->delete();
            TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->delete();
        }

        // La OPA nueva arranca con su puente y su estado ya calculados.
        $this->sincronizarPuenteDesdeDetalle($newOpa->id_orden_pago);
        $this->recalcularEstadoOpa($newOpa->id_orden_pago);

        // num_orden_pago lo asigna la base por trigger: sin refresh vuelve null al front.
        $newOpa->refresh();

        return $newOpa;
    }

    public function findAddFacturaMultiple($request)
    {
        // Se acepta idOrdenPagoOrigen para desambiguar. Antes se hacía `->first()` a secas: si la
        // factura figuraba en el detalle de más de una OPA, tomaba una cualquiera (sin orden
        // definido) y agrupaba desde la OPA equivocada, salteándose incluso la guarda de pagos.
        // Mismo defecto que ya se había corregido en findRemoveFacturaMultiple. (2026-08-11)
        $candidatos = TesOrdenPagoDetalleEntity::where('id_factura', $request->idFactura)
            ->when(
                !empty($request->idOrdenPagoOrigen),
                fn($q) => $q->where('id_orden_pago', $request->idOrdenPagoOrigen)
            )
            ->get();

        if ($candidatos->count() > 1) {
            $opasAmbiguas = $candidatos->pluck('id_orden_pago')->implode(', ');
            return [
                'success' => false,
                'message' => "La factura {$request->idFactura} figura en más de una orden de pago ({$opasAmbiguas}). "
                    . "Indicá desde cuál se debe agrupar."
            ];
        }

        $detalleOpa = $candidatos->first();

        if (!$detalleOpa) {
            return [
                'success' => false,
                'message' => 'No se encontró la factura'
            ];
        }

        if ($detalleOpa->factura_unida == 1) {
            return [
                'success' => false,
                'message' => 'No puede agrupar esta factura porque ya está agrupada'
            ];
        }

        if ($detalleOpa->id_orden_pago == $request->idOrdenPago) {
            return [
                'success' => false,
                'message' => 'La factura ya pertenece a esta OPA'
            ];
        }

        $opa = TesOrdenPagoEntity::where('id_orden_pago', $detalleOpa->id_orden_pago)->first();
        $getOpa = TesOrdenPagoEntity::where('id_orden_pago', $request->idOrdenPago)->first();

        if (!$opa || !$getOpa) {
            return [
                'success' => false,
                'message' => 'No se encontró la OPA'
            ];
        }

        // Guarda ESTRICTA: más abajo la OPA origen se borra físicamente si queda vacía, así que
        // bloquea cualquier pago registrado, confirmado o no. (criterio precisado 2026-09-03)
        if ($this->tieneAlgunPago($opa->id_orden_pago)) {
            return [
                'success' => false,
                'message' => 'No se puede agrupar esta factura: su orden de pago ya tiene pagos registrados.'
            ];
        }

        // Todo el movimiento va en una sola transacción. Antes no lo estaba: si fallaba entre
        // el borrado del detalle y el de la cabecera, la OPA origen quedaba viva SIN detalle
        // y sin id_factura — imposible de rastrear. Se detectaron 8 casos así en producción
        // por $22.873.016. (2026-08-11)
        $newOpa = DB::transaction(function () use ($opa, $getOpa, $detalleOpa) {
            $detalleCreado = TesOrdenPagoDetalleEntity::create([
                'id_orden_pago' => $getOpa->id_orden_pago,
                'id_factura' => $detalleOpa->id_factura,
                'monto_factura' => $detalleOpa->monto_factura,
                'tipo_factura' => $detalleOpa->tipo_factura,
                'factura_unida' => 1
            ]);

            // Se borra SOLO la fila migrada, no todo el detalle de la OPA origen: el borrado
            // masivo hacía desaparecer las demás facturas dejando su monto sumado acá. (2026-08-11)
            TesOrdenPagoDetalleEntity::where('id_orden_pago', $detalleOpa->id_orden_pago)
                ->where('id_factura', $detalleOpa->id_factura)
                ->delete();

            $quedanEnOrigen = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)->count();
            if ($quedanEnOrigen === 0) {
                // La puente primero: su FK es RESTRICT y bloquearía el borrado.
                $this->limpiarPuenteDeOpa($opa->id_orden_pago);
                TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->delete();
            } else {
                $this->recalcularMontoDesdeDetalle($opa->id_orden_pago);
            }

            // Ensure the existing details in the target OPA are marked as grouped
            TesOrdenPagoDetalleEntity::where('id_orden_pago', $getOpa->id_orden_pago)->update(['factura_unida' => 1]);

            // El monto se recalcula desde el detalle en vez de acumularse con `+=`. (2026-08-11)
            $this->recalcularMontoDesdeDetalle($getOpa->id_orden_pago);

            return $detalleCreado;
        });

        return [
            'success' => true,
            'message' => 'Se agregó una factura a la OPA',
            'data' => $newOpa
        ];
    }

    public function findRemoveFacturaMultiple($request)
    {
        // Se filtra TAMBIÉN por id_orden_pago: buscar solo por id_factura tomaba una fila
        // cualquiera (sin orden definido) y, si la factura estaba en más de una OPA, desagrupaba
        // de la OPA equivocada. Si no viene idOrdenPago se mantiene el comportamiento anterior
        // por compatibilidad con las llamadas existentes.
        $detalleOpa = TesOrdenPagoDetalleEntity::where('id_factura', $request->idFactura)
            ->when(
                !empty($request->idOrdenPago),
                fn($q) => $q->where('id_orden_pago', $request->idOrdenPago)
            )
            ->first();

        if (!$detalleOpa) {
            throw new \Exception("No se encontró el detalle de la factura {$request->idFactura} en la orden de pago indicada.");
        }

        $opa = TesOrdenPagoEntity::where('id_orden_pago', $detalleOpa->id_orden_pago)->first();

        $newopa = TesOrdenPagoEntity::create([
            'id_proveedor' => $opa->id_proveedor,
            'id_prestador' => $opa->id_prestador,
            'monto_orden_pago' => $detalleOpa->monto_factura,
            'id_moneda' => $opa->id_moneda,
            'fecha_emision' => $opa->fecha_emision,
            'fecha_vencimiento' => $opa->fecha_vencimiento,
            'fecha_probable_pago' => $opa->fecha_probable_pago,
            'id_estado_orden_pago' => 1,
            'monto_anticipado' => $opa->monto_anticipado,
            'observaciones' => $opa->observaciones,
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_genera' => $this->fechaActual,
            'id_factura' => $detalleOpa->id_factura,
            'tipo_factura' => $detalleOpa->tipo_factura
        ]);

        TesOrdenPagoDetalleEntity::create([
            'id_orden_pago' => $newopa->id_orden_pago,
            'id_factura' => $detalleOpa->id_factura,
            'monto_factura' => $detalleOpa->monto_factura,
            'tipo_factura' => $detalleOpa->tipo_factura,
            'factura_unida' => 0
        ]);

        // La OPA desagrupada también necesita su fila en la puente: sin esto nacía con detalle
        // pero sin imputación, y su estado derivado hubiera dado PENDIENTE con monto 0.
        // (2026-09-03)
        $this->sincronizarPuenteDesdeDetalle($newopa->id_orden_pago);
        $this->recalcularEstadoOpa($newopa->id_orden_pago);

        // El monto de la OPA origen se recalcula desde su detalle más abajo, una vez quitada
        // la fila. Antes se hacía `-=` sobre la cabecera, que se desincronizaba del detalle
        // ante cualquier fallo intermedio. (2026-08-11)

        // Delete the detail from the original OPA
        TesOrdenPagoDetalleEntity::where('id_factura', $request->idFactura)->where('id_orden_pago', $detalleOpa->id_orden_pago)->delete();
        
        // If the original OPA has no more details, delete it. If it has 1 detail left, mark it as unida=0
        $remainingDetails = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)->get();
        if ($remainingDetails->count() == 0) {
            // Guarda ESTRICTA: acá se borra físicamente la OPA, así que bloquea cualquier pago
            // registrado, esté confirmado o no. (2026-08-11, criterio precisado 2026-09-03)
            if ($this->tieneAlgunPago($opa->id_orden_pago)) {
                throw new \Exception(
                    "No se puede desagrupar: la orden de pago {$opa->id_orden_pago} quedaría vacía y tiene pagos registrados."
                );
            }
            // La puente primero: su FK es RESTRICT y bloquearía el borrado.
            $this->limpiarPuenteDeOpa($opa->id_orden_pago);
            $opa->delete();
        } else {
            if ($remainingDetails->count() == 1) {
                $firstDetalle = $remainingDetails->first();
                $firstDetalle->factura_unida = 0;
                $firstDetalle->save();
            }
            $this->recalcularMontoDesdeDetalle($opa->id_orden_pago);
        }

        return $newopa;
    }

    const TIPO_OPA_NORMAL    = 'NORMAL';
    const TIPO_OPA_REEMPLAZO = 'REEMPLAZO';

    /**
     * Anula una OP y emite otra por las mismas facturas, dejando el vínculo entre las dos.
     *
     * Es el punto 7 del circuito. Hasta ahora anular y volver a generar eran dos actos sueltos:
     * la orden vieja quedaba rechazada y la nueva nacía sin ninguna relación con ella, así que
     * no había forma de reconstruir por qué se rehízo ni cuál reemplazó a cuál.
     *
     * La orden nueva queda con `id_opa_reemplazada` apuntando a la vieja y `tipo_opa` en
     * REEMPLAZO. La vieja conserva su motivo de rechazo. Ninguna de las dos se borra.
     *
     * @return array{ok: bool, message: string, anulada: ?TesOrdenPagoEntity, nueva: ?TesOrdenPagoEntity}
     */
    /**
     * ¿Se puede anular esta orden? Devuelve el motivo del bloqueo, o null si se puede.
     *
     * Las guardas viven acá y no repetidas en cada método: `anularOpa()` y `anularYReemitir()`
     * tienen que frenar exactamente por lo mismo. Si una fuera más permisiva que la otra, el
     * camino laxo se volvería la puerta de atrás del estricto.
     */
    public function motivoQueImpideAnular($idOpa): ?string
    {
        $opa = TesOrdenPagoEntity::find($idOpa);

        if (is_null($opa)) {
            return "No se encontró la orden de pago {$idOpa}.";
        }

        if ((int) $opa->id_estado_orden_pago === self::ESTADO_OPA_RECHAZADO) {
            return 'Esta orden de pago ya está anulada.';
        }

        // Guarda 1: plata efectivamente pagada. Anular por debajo de un pago confirmado dejaría
        // el movimiento bancario sin ninguna orden que lo respalde.
        if ($this->tienePagosConfirmados($idOpa)) {
            return 'No se puede anular: la orden tiene pagos confirmados.';
        }

        // Guarda 2: un eCheq ya emitido está circulando aunque todavía no se haya acreditado.
        // Hay que rechazarlo o anularlo primero; anular la OP por debajo dejaría el papel suelto.
        // Los eCheq son ABONOS de la boleta, no la boleta misma (ver 2026_09_04_100900).
        $emitidos = \App\Models\Tesoreria\TesPagosParciales::whereIn(
            'id_pago',
            TesPagoEntity::where('id_orden_pago', $idOpa)->pluck('id_pago')
        )->whereIn('id_estado_instrumento', [
            TesInstrumentoPagoRepository::EMITIDO,
            TesInstrumentoPagoRepository::ACREDITADO,
        ])->count();

        if ($emitidos > 0) {
            return "No se puede anular: hay {$emitidos} eCheq ya emitido(s). "
                . 'Primero hay que rechazarlos o anularlos.';
        }

        return null;
    }

    /**
     * Da de baja lo que cuelga de una orden que se está anulando.
     *
     * Los instrumentos que todavía no salieron pasan a ANULADO, y las boletas de la orden a
     * RECHAZADO. Sin lo segundo la boleta seguía en su estado anterior y la orden anulada
     * continuaba apareciendo como pendiente en la grilla de Pagos de Prestador/Proveedor, que
     * muestra `tb_tes_pago.id_estado_orden_pago` directo como badge.
     */
    private function darDeBajaLoQueCuelgaDe($idOpa): void
    {
        $idsBoleta = TesPagoEntity::where('id_orden_pago', $idOpa)->pluck('id_pago');

        \App\Models\Tesoreria\TesPagosParciales::whereIn('id_pago', $idsBoleta)
            ->whereIn('id_estado_instrumento', [
                TesInstrumentoPagoRepository::BORRADOR,
                TesInstrumentoPagoRepository::PENDIENTE_EMISION,
            ])->update(['id_estado_instrumento' => TesInstrumentoPagoRepository::ANULADO]);

        TesPagoEntity::whereIn('id_pago', $idsBoleta)
            ->update(['id_estado_orden_pago' => self::ESTADO_OPA_RECHAZADO]);
    }

    /**
     * Anula una orden de pago, sin reemplazarla.
     *
     * Es el hermano simple de `anularYReemitir()`: mismas guardas, pero acá la orden muere y no
     * nace ninguna otra. Se usa cuando la orden directamente no tendría que existir —a diferencia
     * del reemplazo, que es para rehacerla.
     *
     * **Las facturas quedan libres.** No se toca el puente (`tb_tes_opa_factura`): la imputación
     * ocurrió y es historia. Lo que las libera es que todos los cálculos de saldo e imputación
     * excluyen las órdenes en estado RECHAZADO, así que la factura vuelve sola a estar disponible
     * para una orden nueva.
     *
     * No se borra nada: la orden queda en RECHAZADO con su motivo, fecha y usuario.
     *
     * @return array{ok: bool, message: string, anulada: ?TesOrdenPagoEntity}
     */
    public function anularOpa($idOpa, ?string $motivo = null): array
    {
        return DB::transaction(function () use ($idOpa, $motivo) {
            if (empty(trim((string) $motivo))) {
                return ['ok' => false, 'message' => 'Hay que indicar el motivo de la anulación.',
                        'anulada' => null];
            }

            $bloqueo = $this->motivoQueImpideAnular($idOpa);

            if (!is_null($bloqueo)) {
                return ['ok' => false, 'message' => $bloqueo, 'anulada' => null];
            }

            $opa = TesOrdenPagoEntity::find($idOpa);

            $this->darDeBajaLoQueCuelgaDe($idOpa);

            $opa->id_estado_orden_pago = self::ESTADO_OPA_RECHAZADO;
            $opa->motivo_rechazo       = $motivo;
            $opa->fecha_rechazo        = $this->fechaActual;
            $opa->cod_usuario_rechaza  = $this->user->cod_usuario ?? null;
            $opa->save();

            return [
                'ok'      => true,
                'message' => "Orden {$opa->num_orden_pago} anulada. "
                    . 'Sus facturas vuelven a estar disponibles.',
                'anulada' => $opa->refresh(),
            ];
        });
    }

    public function anularYReemitir($idOpa, ?string $motivo = null): array
    {
        return DB::transaction(function () use ($idOpa, $motivo) {
            if (empty(trim((string) $motivo))) {
                return ['ok' => false, 'message' => 'Hay que indicar el motivo de la anulación.',
                        'anulada' => null, 'nueva' => null];
            }

            // Mismas guardas que `anularOpa()`: viven en un solo lugar para que los dos caminos
            // de anulación no puedan divergir.
            $bloqueo = $this->motivoQueImpideAnular($idOpa);

            if (!is_null($bloqueo)) {
                return ['ok' => false, 'message' => $bloqueo, 'anulada' => null, 'nueva' => null];
            }

            $original = TesOrdenPagoEntity::find($idOpa);

            $detalle = TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOpa)->get();

            if ($detalle->isEmpty()) {
                return ['ok' => false,
                        'message' => 'La orden de pago no tiene facturas: no hay nada que reemitir.',
                        'anulada' => null, 'nueva' => null];
            }

            // 1) Los instrumentos que todavía no salieron, y las boletas, se dan de baja junto
            //    con la orden.
            $this->darDeBajaLoQueCuelgaDe($idOpa);

            // 2) Anular la original. Se conserva su puente: la imputación ocurrió y es historia.
            $original->id_estado_orden_pago = self::ESTADO_OPA_RECHAZADO;
            $original->motivo_rechazo       = $motivo;
            $original->fecha_rechazo        = $this->fechaActual;
            $original->cod_usuario_rechaza  = $this->user->cod_usuario ?? null;
            $original->save();

            // 3) Emitir la nueva por las mismas facturas.
            $nueva = TesOrdenPagoEntity::create([
                'id_proveedor'         => $original->id_proveedor,
                'id_prestador'         => $original->id_prestador,
                'monto_orden_pago'     => $original->monto_orden_pago,
                'id_moneda'            => $original->id_moneda,
                'fecha_emision'        => $this->fechaActual->toDateString(),
                'fecha_vencimiento'    => $original->fecha_vencimiento,
                'fecha_probable_pago'  => $original->fecha_probable_pago,
                'id_estado_orden_pago' => self::ESTADO_OPA_PENDIENTE,
                'monto_anticipado'     => $original->monto_anticipado,
                'observaciones'        => $original->observaciones,
                'cod_usuario'          => $this->user->cod_usuario ?? null,
                'fecha_genera'         => $this->fechaActual,
                'id_factura'           => $original->id_factura,
                'tipo_factura'         => $original->tipo_factura,
                'tipo_opa'             => self::TIPO_OPA_REEMPLAZO,
                'id_opa_reemplazada'   => $original->id_orden_pago,
            ]);

            foreach ($detalle as $d) {
                TesOrdenPagoDetalleEntity::create([
                    'id_orden_pago' => $nueva->id_orden_pago,
                    'id_factura'    => $d->id_factura,
                    'monto_factura' => $d->monto_factura,
                    'tipo_factura'  => $d->tipo_factura,
                    'factura_unida' => $d->factura_unida,
                ]);
            }

            $this->sincronizarPuenteDesdeDetalle($nueva->id_orden_pago);
            $this->recalcularMontoDesdeDetalle($nueva->id_orden_pago);

            // El num_orden_pago lo pone el trigger: sin refresh vuelve en null.
            $nueva->refresh();

            return [
                'ok'      => true,
                'message' => "Orden {$original->num_orden_pago} anulada y reemplazada por "
                    . "{$nueva->num_orden_pago}.",
                'anulada' => $original->refresh(),
                'nueva'   => $nueva,
            ];
        });
    }

    /**
     * Cadena completa de reemplazos de una OP, de la más vieja a la más nueva.
     *
     * Sirve para contestar "¿por qué existe esta orden?" sin ir saltando de a una.
     * Corta a las 50 vueltas: si algún día un dato malo arma un ciclo, es preferible devolver
     * una cadena incompleta que colgar el request.
     */
    public function cadenaDeReemplazos($idOpa): array
    {
        $actual = TesOrdenPagoEntity::find($idOpa);

        if (is_null($actual)) {
            return [];
        }

        // Hacia atrás, hasta el origen.
        $cadena = [$actual];
        $vistos = [(int) $actual->id_orden_pago => true];
        $cursor = $actual;

        while (!is_null($cursor->id_opa_reemplazada) && count($cadena) < 50) {
            $previa = TesOrdenPagoEntity::find($cursor->id_opa_reemplazada);

            if (is_null($previa) || isset($vistos[(int) $previa->id_orden_pago])) {
                break;
            }

            array_unshift($cadena, $previa);
            $vistos[(int) $previa->id_orden_pago] = true;
            $cursor = $previa;
        }

        // Hacia adelante, por si esta orden ya fue reemplazada a su vez.
        $cursor = $actual;

        while (count($cadena) < 50) {
            $siguiente = TesOrdenPagoEntity::where('id_opa_reemplazada', $cursor->id_orden_pago)
                ->orderBy('id_orden_pago')->first();

            if (is_null($siguiente) || isset($vistos[(int) $siguiente->id_orden_pago])) {
                break;
            }

            $cadena[] = $siguiente;
            $vistos[(int) $siguiente->id_orden_pago] = true;
            $cursor = $siguiente;
        }

        return $cadena;
    }

}
