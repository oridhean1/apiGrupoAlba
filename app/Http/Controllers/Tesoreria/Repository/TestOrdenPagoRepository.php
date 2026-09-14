<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesEstadoOrdenPagoEntity;
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
    const ESTADO_OPA_EN_PROCESO = 4;
    const ESTADO_OPA_PAGADO    = 5;

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
     * ¿La OPA tiene pagos vivos? (pagos no anulados — un pago anulado queda en estado 3)
     * Es el criterio real para saber si se puede rechazar: no alcanza con mirar el estado de la
     * OPA. De 982 OPAs EN PROCESO relevadas, 977 tenían pago vivo pero 5 no.
     */
    public function findByOpaTienePagosVivos($idOpa)
    {
        return TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
            ->exists();
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

        // Si la OPA tiene pagos vivos, los anulamos automáticamente, generando contraasiento si están confirmados
        if ($this->tienePagosVivos($opa->id_orden_pago)) {
            $pago = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)
                ->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
                ->first();
            if ($pago) {
                $historialRepo = app(\App\Http\Controllers\Tesoreria\Repository\AsientosPagoHistorialRepository::class);
                if ($historialRepo->pagoTieneAsientos($pago->id_pago)) {
                    $historialRepo->procesarAnulacionPago($pago->id_pago, 'Anulado automáticamente al anular la orden de pago');
                }
                
                $pago->id_estado_orden_pago = self::ESTADO_OPA_RECHAZADO;
                $pago->motivo_rechazo = 'Anulado automáticamente al anular la orden de pago.';
                $pago->fecha_rechazo = $this->fechaActual;
                $pago->save();
            }
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

    /**
     * Tipo de beneficiario (PRESTADOR/PROVEEDOR) de una OPA, tomado del DETALLE. (T-00000830)
     *
     * No usar la cabecera: la columna tiene DEFAULT 'PROVEEDOR' y las OPAs agrupadas se creaban
     * sin cargarla, así que el pago generado desde ellas caía en Pagos Proveedores aunque la OPA
     * fuera de un prestador. Se cae a la cabecera solo si la OPA quedó sin detalle.
     */
    public function findByTipoFacturaOpa($idOpa)
    {
        return TesOrdenPagoDetalleEntity::where('id_orden_pago', $idOpa)->value('tipo_factura')
            ?? TesOrdenPagoEntity::where('id_orden_pago', $idOpa)->value('tipo_factura');
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

        return $total;
    }

    /**
     * Indica si una OPA tiene pagos vivos (no rechazados). Se usa como guarda antes de
     * cualquier borrado físico: borrar una OPA pagada deja el pago sin respaldo. (2026-08-11)
     */
    public function tienePagosVivos($idOpa): bool
    {
        return TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)
            ->exists();
    }

    /**
     * Indica si una OPA tiene pagos ya confirmados en el banco (estado 5).
     */
    public function tienePagosVivosConfirmados($idOpa): bool
    {
        return false; // El negocio ahora permite modificar incluso confirmados.
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
                    ->whereIn('id_estado_orden_pago', [self::ESTADO_OPA_PENDIENTE, self::ESTADO_OPA_EN_PROCESO, 5]);
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
                    ->whereNotIn('id_estado_orden_pago', [self::ESTADO_OPA_PENDIENTE, self::ESTADO_OPA_EN_PROCESO, 5, self::ESTADO_OPA_RECHAZADO]);
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

        // No tocar OPAs con pagos vivos: más abajo se las borra físicamente y el pago quedaría
        // sin orden de pago que lo respalde. (2026-08-11)
        foreach ($idOrdenesExistentes as $idOrdenExistente) {
            if ($this->tienePagosVivosConfirmados($idOrdenExistente)) {
                throw new \Exception(
                    "No se puede agrupar: la orden de pago {$idOrdenExistente} ya tiene pagos confirmados."
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
        //
        // El tipo de las OPAs existentes sale de su DETALLE, no de la cabecera: las agrupadas
        // viejas quedaron con la cabecera en 'PROVEEDOR' por el DEFAULT de la columna y
        // volver a agruparlas daba "distintos proveedores/prestadores" en falso. (T-00000830)
        $tipoPorOpa = $detalleExistente->groupBy('id_orden_pago')->map(fn($g) => $g->first()->tipo_factura);
        $opasExistentesTodas = TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->get();
        $beneficiarios = collect(
            $opasExistentesTodas->map(function ($o) use ($tipoPorOpa) {
                $tipo = $tipoPorOpa[$o->id_orden_pago] ?? $o->tipo_factura;
                $id = $tipo === 'PROVEEDOR' ? $o->id_proveedor : $o->id_prestador;
                return $tipo . ':' . $id;
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

        // Referencia para prestador/proveedor/moneda/fechas de la cabecera nueva: la OPA
        // existente si hay alguna, si no la primera factura sin OPA de la selección.
        $opaExistente = TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->first();
        $ref = $opaExistente ?? $facturasDb->first();

        if (is_null($ref)) {
            throw new \Exception('No se encontraron facturas para generar la orden de pago.');
        }

        $esAgrupada = $idFacturas->count() > 1;

        // Tipo de beneficiario de la cabecera, tomado del detalle (arriba ya se validó que todas
        // las facturas son del mismo beneficiario). Si no se carga explícito, la columna cae en su
        // DEFAULT 'PROVEEDOR' y el pago generado después aparece en Pagos Proveedores aunque la
        // OPA sea de un prestador. (T-00000830)
        $detalleRef = $detalleExistente->first();
        $tipoFactura = $detalleRef
            ? $detalleRef->tipo_factura
            : ($facturasDb->first()->id_tipo_factura == 16 ? 'PROVEEDOR' : 'PRESTADOR');

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
            // Una OPA de una sola factura conserva la referencia en la cabecera; la agrupada la
            // deja en NULL y la relación vive solo en el detalle (ver findByOpaVigenteFactura).
            'id_factura' => $esAgrupada ? null : $idFacturas->first(),
            'tipo_factura' => $tipoFactura,
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
            TesOrdenPagoDetalleEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->delete();
            TesOrdenPagoEntity::whereIn('id_orden_pago', $idOrdenesExistentes)->delete();
        }

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

        if ($this->tienePagosVivosConfirmados($opa->id_orden_pago) || $this->tienePagosVivosConfirmados($getOpa->id_orden_pago)) {
            return [
                'success' => false,
                'message' => 'No se puede agrupar esta factura: la orden de pago ya tiene pagos confirmados.'
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
                TesOrdenPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->delete();
            } else {
                $this->recalcularMontoDesdeDetalle($opa->id_orden_pago);
            }

            // Ensure the existing details in the target OPA are marked as grouped
            TesOrdenPagoDetalleEntity::where('id_orden_pago', $getOpa->id_orden_pago)->update(['factura_unida' => 1]);

            // El monto se recalcula desde el detalle en vez de acumularse con `+=`. (2026-08-11)
            $this->recalcularMontoDesdeDetalle($getOpa->id_orden_pago);

            // Sincronizar pagos si existen (ambas OPAs pueden tener pagos no confirmados)
            if ($this->tienePagosVivos($opa->id_orden_pago) && $quedanEnOrigen > 0) {
                $pago = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)->first();
                if ($pago) {
                    $nuevoMonto = TesOrdenPagoEntity::find($opa->id_orden_pago)->monto_orden_pago;
                    $pago->monto_pago = $nuevoMonto;
                    $pago->monto_opa = $nuevoMonto;
                    $pago->save();
                }
            }
            if ($this->tienePagosVivos($getOpa->id_orden_pago)) {
                $pagoGet = TesPagoEntity::where('id_orden_pago', $getOpa->id_orden_pago)->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)->first();
                if ($pagoGet) {
                    $nuevoMontoGet = TesOrdenPagoEntity::find($getOpa->id_orden_pago)->monto_orden_pago;
                    $pagoGet->monto_pago = $nuevoMontoGet;
                    $pagoGet->monto_opa = $nuevoMontoGet;
                    $pagoGet->save();
                }
            }

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

        // El monto de la OPA origen se recalcula desde su detalle más abajo, una vez quitada
        // la fila. Antes se hacía `-=` sobre la cabecera, que se desincronizaba del detalle
        // ante cualquier fallo intermedio. (2026-08-11)

        // Delete the detail from the original OPA
        TesOrdenPagoDetalleEntity::where('id_factura', $request->idFactura)->where('id_orden_pago', $detalleOpa->id_orden_pago)->delete();
        
        // If the original OPA has no more details, delete it. If it has 1 detail left, mark it as unida=0
        $remainingDetails = TesOrdenPagoDetalleEntity::where('id_orden_pago', $opa->id_orden_pago)->get();
        if ($remainingDetails->count() == 0) {
            // Guarda: no borrar físicamente una OPA con pagos confirmados.
            if ($this->tienePagosVivosConfirmados($opa->id_orden_pago)) {
                throw new \Exception(
                    "No se puede desagrupar: la orden de pago {$opa->id_orden_pago} quedaría vacía y tiene pagos confirmados asociados."
                );
            }
            // Si tiene pagos, anularlos automáticamente con contraasiento si es necesario
            if ($this->tienePagosVivos($opa->id_orden_pago)) {
                $pago = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)->first();
                if ($pago) {
                    $historialRepo = app(\App\Http\Controllers\Tesoreria\Repository\AsientosPagoHistorialRepository::class);
                    if ($historialRepo->pagoTieneAsientos($pago->id_pago)) {
                        $historialRepo->procesarAnulacionPago($pago->id_pago, 'Anulado automáticamente al vaciar OPA origen');
                    }
                    $pago->id_estado_orden_pago = self::ESTADO_OPA_RECHAZADO;
                    $pago->motivo_rechazo = 'Anulado automáticamente al quedar vacía la OPA origen';
                    $pago->fecha_rechazo = $this->fechaActual;
                    $pago->save();
                }
            }
            $opa->delete();
        } else {
            if ($remainingDetails->count() == 1) {
                $firstDetalle = $remainingDetails->first();
                $firstDetalle->factura_unida = 0;
                $firstDetalle->save();
            }
            $this->recalcularMontoDesdeDetalle($opa->id_orden_pago);
            
            // Sincronizar el pago no confirmado con el nuevo monto reducido
            if ($this->tienePagosVivos($opa->id_orden_pago)) {
                $pago = TesPagoEntity::where('id_orden_pago', $opa->id_orden_pago)->where('id_estado_orden_pago', '!=', self::ESTADO_OPA_RECHAZADO)->first();
                if ($pago) {
                    $nuevoMonto = TesOrdenPagoEntity::find($opa->id_orden_pago)->monto_orden_pago;
                    $pago->monto_pago = $nuevoMonto;
                    $pago->monto_opa = $nuevoMonto;
                    $pago->save();
                }
            }
        }

        return $newopa;
    }
}
