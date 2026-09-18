<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesFechaProbablePagoEntity;
use App\Http\Controllers\Tesoreria\Repository\TesInstrumentoPagoRepository;
use App\Models\Tesoreria\TestChequesEntity;
use App\Models\Tesoreria\TesPagoEntity;
use App\Models\Tesoreria\TesPagosParciales;
use App\Models\Tesoreria\TestDetalleComprobantesPagoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Tesoreria\TesPagoDetalleEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use App\Models\Tesoreria\TesEstadoOrdenPagoEntity;
use App\Models\Tesoreria\TesEstadoPagoEntity;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Http\Controllers\Tesoreria\Repository\FacturasOpaRepository;
use App\Models\Tesoreria\PagoRetencionesEntity;

class TesPagosRepository
{
    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findByCrearPago($params)
    {
        $pago = TesPagoEntity::create([
            'id_orden_pago'         => $params['id_orden_pago'],
            'id_cuenta_bancaria'    => $params['id_cuenta_bancaria'],
            'fecha_registra'        => $this->fechaActual,
            'fecha_confirma_pago'   => $params['fecha_confirma_pago'],
            'anticipo'              => $params['anticipo'],
            'comprobante'           => $params['comprobante'],
            'id_forma_pago'         => $params['id_forma_pago'],
            'monto_pago'            => $params['monto_pago'],
            'observaciones'         => $params['observaciones'],
            'id_estado_orden_pago'  => $params['id_estado_orden_pago'],
            'id_usuario'            => $this->user->cod_usuario,
            'monto_opa'             => $params['monto_opa'],
            'recursor'              => $params['recursor'],
            'fecha_probable_pago'   => null,
            'tipo_factura'          => $params['tipo_factura'],
            'pago_emergencia'       => $params['pago_emergencia'],
        ]);

        // Confirmar la orden define SOLO el cronograma: en que fechas se va a pagar y en
        // cuantas cuotas. El monto y la forma de pago se deciden al emitir cada pago, que es
        // cuando Tesoreria sabe con que lo va a pagar. Ver 2026_09_04_101000. (2026-09-04)
        foreach ($params['cuotas'] as $cuotas) {
            TesFechaProbablePagoEntity::create([
                'fecha_registra' => $this->fechaActual,
                'fecha_probable_pago' => $cuotas['fecha_probable_pago'],
                'orden_cuotas' => $cuotas['orden_cuotas'],
                'id_pago' => $pago->id_pago,
            ]);
        }

        // La orden deja de estar PENDIENTE apenas tiene su cronograma: "PENDIENTE" es "todavia no
        // se definio cuando se paga", y eso ya se resolvio. EN_PROCESO es el estado del catalogo
        // que existia para esto y nunca se usaba (el comentario decia "el circuito nuevo no lo
        // produce" -- resulta que si correspondia, solo que nadie lo estaba seteando). Sin esto
        // la orden quedaba viendose PENDIENTE para siempre aunque ya tuviera boleta, y el boton
        // "Confirmar OPA" invitaba a un segundo click que chocaba con "ya tiene un pago generado"
        // (hallado el 2026-09-05, 68 ordenes reales en ese estado -> ver
        // sql-backfill-estado-en-proceso.md). No se toca si la orden ya esta en otro estado (por
        // ejemplo, si esto se llama sobre una orden ya PAGADA por error, no la hace retroceder).
        TesOrdenPagoEntity::where('id_orden_pago', $params['id_orden_pago'])
            ->where('id_estado_orden_pago', TestOrdenPagoRepository::ESTADO_OPA_PENDIENTE)
            ->update(['id_estado_orden_pago' => TestOrdenPagoRepository::ESTADO_OPA_EN_PROCESO]);

        return $pago;
    }

    /** Banco emisor a partir de la cuenta bancaria del pago. Null si no se informó cuenta. */
    private function bancoDeCuenta($idCuenta): ?int
    {
        if (empty($idCuenta)) {
            return null;
        }

        return \App\Models\Tesoreria\TesCuentasBancariasEntity::where('id_cuenta_bancaria', $idCuenta)
            ->value('id_entidad_bancaria');
    }

    public function findByAsignarCodigoVerificacion($id, $codigo)
    {
        $pago = TesPagoEntity::find($id);
        $pago->num_pago = $codigo;
        $pago->update();
        return $pago;
    }

    public function findByListPagosBetween($desde, $hasta)
    {
        return TesPagoEntity::with(['estado', 'cuenta', 'formaPago', 'opa.proveedor', 'opa.factura.razonSocial', 'comprobantes'])
            ->whereBetween(DB::raw('DATE(fecha_probable_pago)'), [$desde, $hasta])
            ->orderBy('id_estado_orden_pago', 'asc')
            ->orderBy('fecha_probable_pago')
            ->get();
    }

    public function findByListPagosFiltroPrincipal($params)
    {
        $jquery = "";
        $tipoRelacion = $params->tipo === 'PROVEEDOR' ? 'proveedor' : 'prestador';
        $tipoFactura = $params->tipo === 'PROVEEDOR' ? 'PROVEEDOR' : 'PRESTADOR';

        $jquery = TesPagoEntity::with([
            'estado',
            'cuenta',
            'formaPago',
            "opa.$tipoRelacion",
            "opa.$tipoRelacion.datosBancarios",
            //'opa.factura.razonSocial',
            'opa.opadetalle.detallefc',
            'comprobantes',
            // Solo los abonos VIVOS: un eCheq anulado o rechazado no es un pago. Sin este filtro
            // el modal de Confirmar Pago seguía listando los eCheq que se habían dado de baja y
            // los sumaba como si fueran plata. (2026-09-07, OPA-4284)
            'pagosParciales' => fn($q) => $q->vivos(),
            'pagosParciales.formaPago',
            // La cuenta de origen es del abono desde 2026_09_06_100000: el modal de Confirmar
            // Pago la muestra en vez de volver a preguntarla.
            'pagosParciales.cuentaBancaria',
            'pagosParciales.bancoEmisor',
            // Para que el listado pueda mostrar los pagos ya cargados con su estado y a que cuota
            // corresponden: la fila mostraba solo el importe y no habia forma de ver si el eCheq
            // estaba emitido, acreditado o todavia sin numero. (2026-09-16)
            'pagosParciales.estadoInstrumento',
            'pagosParciales.fechaProbable',
            'fechaprobablepagos',
            'detalleopa.detallefc',
            'detalleopa.detallefc.razonSocial'
        ]);

        $jquery->where('tipo_factura', $tipoFactura);

        if (!empty($params->beneficiario)) {
            $jquery->whereHas("opa.$tipoRelacion", function ($query) use ($params) {
                $query->where(function ($q) use ($params) {
                    $q->where('razon_social', 'like', '%' . $params->beneficiario . '%')
                        ->orWhere('nombre_fantasia', 'like', '%' . $params->beneficiario . '%');
                });
            });
        }

        if (!is_null($params->desde) && !is_null($params->hasta)) {
            $jquery->whereHas('fechaprobablepagos', function ($query) use ($params) {
                $query->whereBetween(DB::raw('DATE(fecha_probable_pago)'), [$params->desde, $params->hasta]);
            });
        }

        /*  if (!is_null($params->monto_desde) && !is_null($params->monto_hasta)) {
            $jquery->whereBetween('monto_pago', [$params->monto_desde, $params->monto_hasta]);
        } */

        // Dos bugs juntos, arreglados el 2026-09-04:
        //  1. filtraba con `$params->numero` (el N° de FACTURA) sobre la columna del N° de OPA,
        //     asi que buscar por orden nunca encontraba nada;
        //  2. `!is_null('')` es true, y el front manda '' cuando el campo esta vacio -> la
        //     pantalla quedaba en CERO pagos salvo que se tipeara algo.
        // Se usa LIKE y se limpia el prefijo porque el usuario tipea indistinto "OPA-1435",
        // "1435" o "opa 1435".
        if (!empty($params->numero_opa)) {
            $numero = preg_replace('/\D/', '', (string) $params->numero_opa);

            if ($numero !== '') {
                $jquery->whereHas('opa', function ($query) use ($numero) {
                    // Comparacion NUMERICA del correlativo, no textual: los numeros viejos vienen
                    // con ceros a la izquierda ('OPA-0999') y los nuevos no ('OPA-14358'), asi que
                    // un LIKE devolvia de mas (buscar 1435 traia OPA-14358) y un igual textual
                    // fallaba con los rellenados. Asi "999", "0999" y "OPA-0999" encuentran lo mismo.
                    $query->whereRaw(
                        "CAST(REPLACE(num_orden_pago, 'OPA-', '') AS UNSIGNED) = ?",
                        [(int) $numero]
                    );
                });
            }
        }

        if (!empty($params->estado)) {
            $jquery->where('id_estado_orden_pago', $params->estado);
        }

        if ($params->pago_urgente == '1') {
            $jquery->where('pago_emergencia', $params->pago_urgente);
        }

        if (!empty($params->id_locatario)) {
            $jquery->whereHas('detalleopa.detallefc', function ($query) use ($params) {
                $query->where('id_locatorio', $params->id_locatario);
            });
        }

        if (!empty($params->id_tipo_imputacion)) {
            $jquery->whereHas('detalleopa.detallefc', function ($query) use ($params) {
                $query->where('id_tipo_imputacion_sintetizada', $params->id_tipo_imputacion);
            });
        }

        // Mismo caso: con '' filtraba por numero de factura vacio y dejaba la lista en cero.
        if (!empty($params->numero)) {
            $jquery->whereHas('detalleopa.detallefc', function ($query) use ($params) {
                $query->where('numero', $params->numero);
            });
        }

        if (!is_null($params->id_tipo) && $params->id_tipo !== '') {
            $jquery->whereHas('detalleopa.detallefc', function ($query) use ($params) {
                $query->where('id_tipo_factura', '=', (int) $params->id_tipo);
            });
        }

        // $jquery->orderBy('id_estado_orden_pago', 'asc');
        $jquery->orderBy('fecha_probable_pago');

        $boletas = $jquery->get();

        $this->agregarMontoPagable($boletas);

        return $boletas;
    }

    /**
     * Agrega `monto_pagable` a cada boleta: lo que realmente hay que pagarle al beneficiario.
     *
     * Es el mismo criterio que `montoPagableOpa()` y que el freno de sobrepago — por cada factura
     * imputada, `min(monto_aplicado, neto - debito)` — resuelto en una sola consulta agrupada.
     *
     * El modal de Confirmar Pago lo necesita para proponer el monto a pagar. Lo calculaba solo,
     * como `monto_orden_pago - debito`, y eso falla por dos lados: la cabecera puede estar
     * desincronizada con lo realmente imputado (la OPA-1206 dice $2.266.110,16 cuando sus facturas
     * suman $2.214.425,55), y no aplica el tope por factura. El resultado era proponer un importe
     * que el backend despues rechazaba por pasarse. (2026-09-09)
     */
    private function agregarMontoPagable($boletas): void
    {
        $idsOpa = collect($boletas)->pluck('id_orden_pago')->filter()->unique()->values();

        if ($idsOpa->isEmpty()) {
            return;
        }

        $pagables = DB::table('tb_tes_opa_factura as pf')
            ->join('tb_facturacion_datos as f', 'f.id_factura', '=', 'pf.id_factura')
            ->whereIn('pf.id_orden_pago', $idsOpa)
            ->groupBy('pf.id_orden_pago')
            ->select('pf.id_orden_pago', DB::raw(
                'SUM(LEAST(pf.monto_aplicado, GREATEST(0, f.total_neto - COALESCE(f.total_debitado_liquidacion, 0)))) AS pagable'
            ))
            ->pluck('pagable', 'pf.id_orden_pago');

        foreach ($boletas as $boleta) {
            $pagable = (float) ($pagables[$boleta->id_orden_pago] ?? 0);

            // Una orden sin facturas imputadas (un ANTICIPO) no tiene debito que descontar: su
            // tope es su propio monto. Mismo criterio que `topeDeOpa()`.
            if ($pagable <= 0) {
                $pagable = (float) $boleta->monto_opa;
            }

            $boleta->monto_pagable = round($pagable, 2);
        }
    }

    /**
     * Abonos de una boleta que representan plata que efectivamente sale.
     *
     * Excluye RECHAZADOS (5) y ANULADOS (6): esa plata no salió o volvió, así que no corresponde
     * debitarla de ninguna cuenta. Mismo criterio que el freno de sobrepago de
     * `emitirPagoDeFecha()`.
     */
    public function findByPagosParcialesVivos($idPago)
    {
        return TesPagosParciales::where('id_pago', $idPago)
            ->where(function ($q) {
                $q->whereNull('id_estado_instrumento')
                    ->orWhereNotIn('id_estado_instrumento', [
                        TesInstrumentoPagoRepository::RECHAZADO,
                        TesInstrumentoPagoRepository::ANULADO,
                    ]);
            })
            ->get();
    }

    /**
     * Normaliza una fecha a 'Y-m-d' para poder compararla con el cronograma.
     *
     * El front manda a veces '2026-09-05' y a veces '2026-09-05 00:00:00'; el cronograma guarda
     * `date`. Sin normalizar, la comparación de strings falla y el pago parece no pertenecer a
     * ninguna fecha planificada.
     */
    private function soloFecha($valor): ?string
    {
        if (empty($valor)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($valor)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Cuenta de origen que viene en una fila del modal, normalizada.
     *
     * El front manda '' cuando el select quedó sin elegir, y la columna es int: MySQL rechaza el
     * string vacío con "1366 Incorrect integer value".
     */
    private function cuentaDeLaFila($fila): ?int
    {
        $valor = $fila->id_cuenta_bancaria ?? null;

        return ($valor === '' || is_null($valor)) ? null : (int) $valor;
    }

    public function findByConfirmarPago($params)
    {

        $pagosparciales = 0;
        $estado = null;
        $pago = TesPagoEntity::find($params->id_pago);

        // Las guardas del instrumento (tope de sobrepago, razón social de la cuenta) viven en su
        // repositorio y se comparten: los dos caminos que crean abonos tienen que validar igual.
        $instrumentos = new TesInstrumentoPagoRepository();
        $opaRepo = new TestOrdenPagoRepository();

        // Fechas del cronograma de esta boleta. $fechasBoleta queda indexado por
        // id_fecha_probable (clave real y única); $plan es un índice AUXILIAR por fecha, para el
        // camino viejo que solo manda la fecha como string.
        //
        // ⚠️ `$plan` no sirve como única fuente: dos cuotas del cronograma pueden caer el MISMO
        // día (pasa en la práctica — ver OPA-4417, cuotas 2 y 3 ambas el 2026-09-18), y un
        // `pluck()` indexado por fecha colapsa esos duplicados a una sola entrada (la última que
        // recorra). Antes esta era la ÚNICA forma de resolver la fecha, así que con un cronograma
        // así el sistema no podía distinguir a cuál de las dos cuotas se refería el usuario, ni
        // garantizar cuál quedaba libre. Ahora el front manda `id_fecha_probable` explícito
        // cuando lo sabe (lo eligió de un desplegable ya filtrado a fechas libres, con la fecha
        // como clave única); `$plan` queda de respaldo para pagos que solo traen la fecha como
        // texto libre (el circuito viejo: 292 de 302 abonos en Alba están así). (2026-09-11)
        //
        // ⚠️ Los abonos que nacían acá NO guardaban `id_fecha_probable`: quedaban sueltos del
        // cronograma. Por eso no ocupaban ninguna fecha y nada impedía cargar una transferencia
        // en la misma fecha en que ya había un eCheq emitido — el circuito de eCheq sí lo
        // bloquea (`emitirPagoDeFecha`), pero este camino se lo saltaba entero.
        // (2026-09-08, reportado sobre la OPA-4284)
        $fechasBoleta = DB::table('tb_tes_fecha_probable_pago')
            ->where('id_pago', $pago->id_pago)
            ->get(['id_fecha_probable', 'fecha_probable_pago'])
            ->keyBy('id_fecha_probable');

        $plan = $fechasBoleta->pluck('id_fecha_probable', 'fecha_probable_pago');

        $ocupadas = TesPagosParciales::where('id_pago', $pago->id_pago)
            ->whereNotNull('id_fecha_probable')
            ->vivos()
            ->pluck('id_pago_parcial', 'id_fecha_probable');

        foreach ($params->lista_pagos as $pagos) {
            if (empty($pagos->id_pago_parcial)) {
                // Se prefiere el id EXPLÍCITO que manda el front (elegido de un desplegable con
                // id como clave, sin ambigüedad). Se valida que pertenezca a esta boleta antes de
                // confiar en él — no se toma ciego lo que llega del cliente. Si no vino, o no es
                // de esta boleta, se cae al camino viejo por fecha.
                $idFechaExplicito = (int) ($pagos->id_fecha_probable ?? 0);

                if ($idFechaExplicito > 0 && $fechasBoleta->has($idFechaExplicito)) {
                    $idFecha = $idFechaExplicito;
                } else {
                    // La fecha elegida se resuelve contra el cronograma. Si no coincide con
                    // ninguna fecha planificada queda en null: es un pago libre del circuito
                    // viejo, y así seguía funcionando antes.
                    $fechaElegida = $this->soloFecha($pagos->fecha_confirma_pago);
                    $idFecha = $fechaElegida ? ($plan[$fechaElegida] ?? null) : null;
                }

                if (!is_null($idFecha) && isset($ocupadas[$idFecha])) {
                    // La fecha para el mensaje sale del CRONOGRAMA por `$idFecha`, no de
                    // `$fechaElegida`: esa variable solo se define en el camino viejo (por
                    // fecha) y quedaba indefinida —con warning— cuando el id vino explícito.
                    throw new \Exception(sprintf(
                        'La fecha %s ya tiene un pago cargado en esta orden (abono %s). '
                            . 'Cada fecha del cronograma admite un solo pago: elegí otra fecha, '
                            . 'o dá de baja el pago que ya está.',
                        $fechasBoleta[$idFecha]->fecha_probable_pago ?? '',
                        $ocupadas[$idFecha]
                    ));
                }

                // La cuenta de origen se guarda EN EL ABONO. Hasta el 2026-09-09 este create no
                // la incluía: los pagos que nacían acá quedaban con la cuenta en NULL y el retiro
                // de fondos caía de rebote a la cuenta de la boleta, así que dos transferencias de
                // bancos distintos se debitaban las dos de la misma. El modelo lo soportaba desde
                // 2026_09_06_100000; este camino no lo usaba.
                $idCuentaFila = $this->cuentaDeLaFila($pagos) ?? $this->cuentaDeLaFila($params);

                // Freno de sobrepago. Vive en TesInstrumentoPagoRepository y lo comparten los dos
                // caminos que crean abonos: hasta el 2026-09-12 solo lo aplicaba `emitirPagoDeFecha`
                // y este quedaba con la puerta abierta al mismo sobrepago que esa validación cerró.
                $limites = $instrumentos->validarTopeDeOpa(
                    $pago->id_orden_pago,
                    (float) $pagos->monto_pago,
                    null,
                    $opaRepo
                );

                // ═══ El pago con cheque/eCheq nace como INSTRUMENTO ═══
                //
                // Desde el 2026-09-12 el eCheq se emite acá y no en una pestaña aparte: la pantalla
                // de emisión pedía exactamente estos mismos tres datos (monto, forma de pago y
                // cuenta de origen), así que eran dos pantallas para un solo acto.
                $estadoInstrumento = TesInstrumentoPagoRepository::estadoInicialDeInstrumento(
                    $pagos->id_forma_pago
                );
                $esDiferido = !is_null($estadoInstrumento);

                if ($esDiferido) {
                    // La cuenta de origen tiene que ser de la razón social de la orden. Si el pago
                    // debita una cuenta de una razón y el asiento imputa la deuda en el plan de
                    // otra, quedan dos contabilidades descuadradas entre sí.
                    $instrumentos->validarCuentaDeRazonSocial(
                        $idCuentaFila,
                        $pago->id_orden_pago,
                        $opaRepo
                    );
                }

                // El numero no puede estar repetido. Reglas por forma: el eCheq es unico
                // global, el cheque lo es dentro de su chequera (= su cuenta). Sin esto un eCheq
                // repetido llegaba al indice UNIQUE y volvia como SQLSTATE 23000, y un cheque
                // repetido entraba sin aviso. (2026-09-16)
                // Cada forma trae su numero en su propia columna: el eCheq en `numero_echeq`,
                // el cheque en `num_cheque`. No alcanza con mirar `$esDiferido`, que es true para
                // las dos.
                $instrumentos->exigirNumeroLibre(
                    (int) $pagos->id_forma_pago === TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ
                        ? ($pagos->numero_echeq ?? null)
                        : ($pagos->num_cheque ?? null),
                    $pagos->id_forma_pago,
                    $idCuentaFila
                );

                $nuevo = TesPagosParciales::create([
                    'fecha_registra' => $this->fechaActual,
                    // ⚠️ Un instrumento NO nace cobrado: se entregó el documento, pero el banco
                    // todavía no lo debitó. `montoPagadoOpa()` cuenta por esta fecha, así que
                    // setearla acá haría que la orden figure PAGADA con plata que no salió. Se
                    // completa al acreditar (`marcarAcreditado`), que es cuando el banco debita.
                    'fecha_confirma_pago' => $esDiferido ? null : $pagos->fecha_confirma_pago,
                    'id_forma_pago' => $pagos->id_forma_pago,
                    'monto_pago' => $pagos->monto_pago,
                    'monto_opa' => $pagos->monto_opa,
                    'num_cheque' => $pagos->num_cheque,
                    'id_usuario' => $this->user->cod_usuario,
                    'id_pago' => $pago->id_pago,
                    // El restante sale del cálculo del tope, no del front: es el mismo número
                    // contra el que se acaba de validar.
                    'monto_restante' => $limites['restante'],
                    'id_fecha_probable' => $idFecha,
                    'id_cuenta_bancaria' => $idCuentaFila,
                    'id_banco_emisor' => $this->bancoDeCuenta($idCuentaFila),
                    'id_estado_instrumento' => $estadoInstrumento,
                    // El numero del banco, si Pagos ya lo tiene al cargar el pago. Si no vino, mas
                    // abajo se le pone uno provisorio: la carga del pago no se frena por un dato
                    // que llega despues. (2026-09-15)
                    'numero_echeq' => $esDiferido ? ($pagos->numero_echeq ?? null) : null,
                    // Fecha de emisión = la cuota del cronograma que este pago cubre. El número
                    // del eCheq no se carga acá: lo asigna el banco y entra por *Sin número*.
                    'fecha_emision_echeq' => $esDiferido && !is_null($idFecha)
                        ? ($fechasBoleta[$idFecha]->fecha_probable_pago ?? null)
                        : null,
                    // Este abono nace dentro de un pago confirmado.
                    'fecha_confirmado_en_pago' => $this->fechaActual,
                ]);

                // Se hace DESPUES del insert porque el numero provisorio se arma con el id del
                // abono: asi es unico por construccion y no puede chocar contra el indice UNIQUE
                // de `numero_echeq`.
                if ($esDiferido) {
                    TesInstrumentoPagoRepository::asignarNumeroProvisorio($nuevo);
                }

                $pagosparciales += (float) $pagos->monto_pago;

                if (!is_null($idFecha)) {
                    $ocupadas[$idFecha] = $nuevo->id_pago_parcial;
                }
            }else{
                $query=TesPagosParciales::find($pagos->id_pago_parcial);

                // Un abono del circuito de eCheq NO se modifica desde acá.
                //
                // Se reconoce por tener `id_estado_instrumento`. Esos se gestionan en *Carga de
                // eCheq*, que aplica las guardas del instrumento: solo se tocan antes de emitir,
                // la cuenta tiene que ser de la razón social de la orden y el monto no puede
                // pasarse del tope. Este camino no aplica ninguna, así que dejaba cambiarle la
                // forma de pago, el monto o la cuenta a un eCheq que YA tenía número del banco.
                //
                // Se saltean solo los campos que son del instrumento, y NO se corta con una
                // excepción: el front manda la lista completa —los eCheq incluidos— así que
                // rechazar la fila haría fallar la confirmación entera de una orden que tiene un
                // eCheq emitido. La fecha y los montos de referencia se siguen actualizando, que
                // es el comportamiento de siempre. (2026-09-09)
                $esInstrumento = !is_null($query->id_estado_instrumento);

                $query->fecha_registra=$this->fechaActual;

                // ⚠️ A un INSTRUMENTO no se le toca la fecha de confirmación desde acá.
                //
                // `montoPagadoOpa()` cuenta como cobrado todo abono con `fecha_confirma_pago`, sin
                // mirar el estado del instrumento. Setearla al confirmar el pago hacía que un eCheq
                // apenas emitido —con la plata todavía en la cuenta— contara como cobrado y la orden
                // pasara a PAGADO. Quedaron 5 abonos así en Alba (0 en OSV).
                //
                // La fecha del instrumento la pone `marcarAcreditado()`, que es el momento en que el
                // banco efectivamente debita. Es además lo que `emitirPagoDeFecha` ya documentaba
                // ("no nace confirmado: se confirma cuando el banco acredita") y este camino
                // contradecía en silencio. (2026-09-12)
                if (!$esInstrumento) {
                    $query->fecha_confirma_pago=$pagos->fecha_confirma_pago;
                }

                $query->monto_opa=$pagos->monto_opa;
                $query->id_usuario=$this->user->cod_usuario;
                // Solo si viene: es un abono que YA pertenece a esta boleta, y tomar el id del
                // request para reescribirlo no aporta nada. Si el payload no lo trae, la columna
                // es NOT NULL y la confirmacion se cae con un 1048 en vez de guardar. (2026-09-16)
                if (!empty($pagos->id_pago)) {
                    $query->id_pago = $pagos->id_pago;
                }

                // Queda registrado que este abono entro en un pago confirmado. Es lo que habilita
                // acreditarlo despues: un eCheq emitido sobre una boleta YA confirmada no hereda
                // ese permiso, tiene que pasar por acá. (2026-09-10, OPA-1120)
                $query->fecha_confirmado_en_pago = $this->fechaActual;

                // ═══ El número del eCheq SÍ se edita desde acá ═══
                //
                // Es la única excepción a "un instrumento no se toca desde el pago", y existe
                // porque reemplaza a la pantalla *Carga de eCheq › Sin número*, que se eliminó el
                // 2026-09-15. Cuando el banco asigna el número definitivo, se reemplaza el
                // provisorio acá mismo, sobre el pago ya cargado.
                //
                // No reasienta nada: el número no aparece en ninguna línea contable. Lo que sí hace
                // es limpiar la marca de provisorio, que es lo que después permite saber cuáles
                // siguen pendientes del banco.
                if ($esInstrumento) {
                    // El cheque trae su número en `num_cheque` y el eCheq en `numero_echeq`: las
                    // dos formas son "instrumento", así que hay que mirar cuál es.
                    $esEcheq = (int) $query->id_forma_pago === TesInstrumentoPagoRepository::FORMA_PAGO_ECHEQ;
                    $numeroNuevo = trim((string) (($esEcheq ? $pagos->numero_echeq : $pagos->num_cheque) ?? ''));
                    $numeroActual = (string) ($esEcheq ? $query->numero_echeq : $query->num_cheque);

                    if ($numeroNuevo !== '' && $numeroNuevo !== $numeroActual) {
                        $instrumentos->exigirNumeroLibre(
                            $numeroNuevo,
                            $query->id_forma_pago,
                            $query->id_cuenta_bancaria,
                            $query->id_pago_parcial
                        );

                        if ($esEcheq) {
                            $query->numero_echeq = $numeroNuevo;
                            // `num_cheque` se mantiene en sincronía: es la columna que leen las
                            // pantallas viejas y el comprobante de pago.
                            $query->num_cheque = $numeroNuevo;
                            $query->numero_provisorio = false;
                        } else {
                            $query->num_cheque = $numeroNuevo;
                        }
                    }
                }

                if (!$esInstrumento) {
                    $cuentaFila = $this->cuentaDeLaFila($pagos) ?? $query->id_cuenta_bancaria;

                    // Mismo control que en el alta, para los abonos sin ciclo de instrumento
                    // (los anteriores al circuito, que igual pueden ser cheques).
                    $instrumentos->exigirNumeroLibre(
                        $pagos->num_cheque ?? null,
                        $pagos->id_forma_pago,
                        $cuentaFila,
                        $query->id_pago_parcial
                    );

                    $query->id_forma_pago=$pagos->id_forma_pago;
                    $query->monto_pago=$pagos->monto_pago;
                    $query->num_cheque=$pagos->num_cheque;

                    // La cuenta editada en la fila también se guarda. Si la fila no la trae, se
                    // deja la que el abono ya tenía: no se pisa con null.
                    $cuentaEditada = $this->cuentaDeLaFila($pagos);
                    if (!is_null($cuentaEditada)) {
                        $query->id_cuenta_bancaria = $cuentaEditada;
                        $query->id_banco_emisor    = $this->bancoDeCuenta($cuentaEditada);
                    }
                }
                $query->monto_restante=$pagos->monto_restante;
                $query->update();

                // Para el estado de la boleta cuenta lo que quedó GUARDADO, no lo que mandó el
                // front: el monto de un abono del circuito de eCheq no se modifica acá, así que
                // sumar el del request haría derivar el estado de un número que nunca se escribió.
                $pagosparciales += $esInstrumento
                    ? (float) $query->monto_pago
                    : (float) $pagos->monto_pago;
            }
        }
        // Se compara contra lo PAGABLE (imputado menos débito de liquidación), no contra
        // `$pago->monto_opa` — ese es el bruto que trajo la boleta al crearse, mismo problema que
        // se corrigió para el estado derivado de la OPA (ver montoPagableOpa). Comparación en
        // centavos: sumar floats de a uno arrastra error de redondeo y `==` nunca da exacto.
        //
        // ⚠️ Antes de este fix, `$estado` se calculaba y NUNCA se usaba: dos líneas más abajo
        // `id_estado_orden_pago` quedaba hardcodeado en 5 (PAGADO) sin importar cuánto se hubiera
        // pagado. Por eso el modal exigía "exactamente N abonos, uno por cada fecha planificada"
        // antes de dejar confirmar: era la única forma de garantizar que el 5 hardcodeado no
        // mintiera. Con el cálculo real acá, ya no hace falta esa restricción — se puede
        // confirmar un pago PARCIAL (menos abonos que fechas) y la boleta queda en 6, no en 5.
        // (2026-09-07)
        $pagable = (new TestOrdenPagoRepository())->montoPagableOpa($pago->id_orden_pago);

        if ($pagable <= 0) {
            $pagable = (float) $pago->monto_opa;
        }

        $aCentavos = fn($monto) => (int) round(((float) $monto) * 100);

        // Si el cronograma declara N fechas y ya se cargaron las N, los abonos TIENEN que cubrir
        // lo pagable. Un pago parcial es válido mientras falten fechas por cargar —ahí se sabe que
        // el resto viene después—, pero con todas las fechas usadas y la suma corta, la orden
        // quedaría en PAGO PARCIAL sin ninguna fecha libre donde cargar la diferencia: trabada,
        // y sin que nadie se entere hasta que alguien la busque. (2026-09-09)
        $fechasDelPlan = DB::table('tb_tes_fecha_probable_pago')
            ->where('id_pago', $pago->id_pago)
            ->count();

        $abonosCargados = count($params->lista_pagos);

        if (
            $fechasDelPlan > 0
            && $abonosCargados >= $fechasDelPlan
            && $aCentavos($pagosparciales) < $aCentavos($pagable)
        ) {
            $falta = $pagable - $pagosparciales;

            throw new \Exception(sprintf(
                'Los montos cargados no cubren lo que hay que pagar en esta orden. '
                    . 'A pagar: $%s. Cargado: $%s. Falta: $%s. '
                    . 'Ya se usaron las %d fecha(s) del cronograma, así que no queda ninguna libre '
                    . 'para la diferencia: corregí los importes, o agregá otra fecha de pago a la orden.',
                number_format($pagable, 2, ',', '.'),
                number_format($pagosparciales, 2, ',', '.'),
                number_format($falta, 2, ',', '.'),
                $fechasDelPlan
            ));
        }

        if ($aCentavos($pagosparciales) >= $aCentavos($pagable)) {
            $estado = TestOrdenPagoRepository::ESTADO_OPA_PAGADO;
        } elseif ($pagosparciales > 0) {
            $estado = TestOrdenPagoRepository::ESTADO_OPA_PAGO_PARCIAL;
        } else {
            // Sin ningún abono (por ejemplo, un anticipo que todavía no se pagó): no se toca el
            // estado previo de la boleta.
            $estado = $pago->id_estado_orden_pago;
        }

        // El string vacío no es un id: la columna es int y MySQL lo rechaza con
        // "1366 Incorrect integer value". Llega vacío cuando la orden ya tiene sus pagos emitidos
        // y el modal no pide la cuenta (la define cada abono desde 2026_09_06_100000), o cuando
        // el campo quedó sin completar en el circuito viejo. (2026-09-06)
        // Cuenta a nivel BOLETA: desde el 2026-09-09 el modal ya no la pregunta —cada abono trae
        // la suya— así que acá suele venir vacía. Es informativa: la que vale para debitar es la
        // de cada abono.
        //
        // Si no viene, NO se pisa con null: eso borraría la cuenta de las 255 boletas que ya la
        // tienen, y es el valor al que cae de rebote el retiro de fondos de los abonos viejos que
        // no tienen cuenta propia. En su defecto se toma la del primer abono, para que ese
        // rebote siga teniendo a dónde caer.
        $cuentaDelRequest = $this->cuentaDeLaFila($params);

        if (!is_null($cuentaDelRequest)) {
            $pago->id_cuenta_bancaria = $cuentaDelRequest;
        } elseif (is_null($pago->id_cuenta_bancaria)) {
            $pago->id_cuenta_bancaria = collect($params->lista_pagos)
                ->map(fn($f) => $this->cuentaDeLaFila($f))
                ->filter()
                ->first();
        }
        $pago->fecha_confirma_pago = $this->fechaActual;
        $pago->id_forma_pago = 0;
        $pago->monto_pago = $params->anticipo == '1' ? $params->monto_anticipado : $params->monto_pago;
        $pago->id_estado_orden_pago = $estado;
        $pago->anticipo = $params->anticipo;
        $pago->monto_anticipado = $params->monto_anticipado;
        $pago->num_cheque = $params->num_cheque;
        $pago->fecha_probable_pago = $params->fecha_probable_pago;
        $pago->observaciones = $params->observaciones;

        $pago->id_forma_cobro = is_numeric($params->id_forma_cobro) ? $params->id_forma_cobro : null;
        $pago->monto_cobro = $params->monto_cobro;
        $pago->fecha_confirma_cobro = $params->fecha_confirma_cobro;
        $pago->cuenta_bancaria = $params->cuenta_bancaria;
        $pago->imputacion_contable = $params->imputacion_contable;
        $pago->banco = $params->banco;

        $pago->update();

        // ═══ Confirmar el pago EMITE los instrumentos de la orden ═══
        //
        // Hasta el 2026-09-15 esto lo hacía `confirmarEmisionDeOpa()`, desde la pestaña *Carga de
        // eCheq › Sin número*, que exigía tener el número del banco para dejar emitir. Esa pantalla
        // se eliminó: el número puede ser provisorio y ya no frena nada.
        //
        // Confirmar el pago es el momento real en que Tesorería dice "esto sale", y es una sola
        // acción por orden — así que conserva el "todos los eCheq juntos" que pedía el circuito.
        //
        // Importa el orden: va DESPUÉS de guardar la boleta, porque emitir es la consecuencia de
        // que el pago quedó confirmado, no un paso previo.
        $instrumentos->emitirInstrumentosDeBoleta($pago->id_pago);

        return $pago;
    }

    public function findByAnularPago($id_pago, $observacion)
    {
        $pago = TesPagoEntity::find($id_pago);
        $pago->motivo_rechazo = $observacion;
        $pago->id_estado_orden_pago = 3;
        $pago->fecha_rechazo = $this->fechaActual;
        $pago->update();
        return $pago;
    }

    public function findByUpdatePagoPorOpa($params, $idOpa)
    {
        $pago = TesPagoEntity::where('id_orden_pago', $idOpa)->first();
        $pago->fecha_probable_pago = $params->fecha_probable_pago;
        $pago->pago_emergencia = $params->pago_emergencia;
        $pago->update();
        return $pago;
    }

    public function findById($id)
    {
        return TesPagoEntity::find($id);
    }

    public function findBySumarDetallePagosAnticipados($idOpa)
    {
        return (float) TesPagoEntity::where('id_orden_pago', $idOpa)->sum('monto_anticipado');
    }

    public function findByListDetallePagosAnticipadosConfirmados($idOpa)
    {
        return TesPagoEntity::with(['formaPago', 'comprobantes'])
            ->where('id_orden_pago', $idOpa)
            ->where('id_estado_orden_pago', '5')
            ->get();
    }

    public function findByCargarComprobantePago($archivo, $idPago)
    {
        return TestDetalleComprobantesPagoEntity::create([
            'id_pago' => $idPago,
            'nombre_archivo' => $archivo,
            'fecha_registra' => $this->fechaActual,
            'cod_usuario_registra' => $this->user->cod_usuario,
            'estado' => '1'
        ]);
    }

    public function findByDeleteComprobantePago($idPago)
    {
        $archivo = TestDetalleComprobantesPagoEntity::findOrFail($idPago);
        $archivo->cod_usuario_elimina = $this->user->cod_usuario;
        $archivo->fecha_elimina = $this->fechaActual;
        $archivo->estado = '0';
        $archivo->update();
        return $archivo;
    }

    public function findByUpdateOpaPagoFacturaLiquidaciones($idOpa, $monto)
    {
        if (TesPagoEntity::where('id_orden_pago', $idOpa)->where('id_estado_orden_pago', '1')->exists()) {

            if (TesPagoEntity::where('id_orden_pago', $idOpa)->where('id_estado_orden_pago', '1')->where('anticipo', '0')->exists()) {
                DB::update("UPDATE tb_tes_pago SET monto_opa = ?, monto_pago = ? WHERE tipo_factura = 'PRESTADOR' AND id_orden_pago = ? ", [$monto, $monto, $idOpa]);
            } else {
                DB::update("UPDATE tb_tes_pago SET monto_opa = ? WHERE tipo_factura = 'PRESTADOR' AND id_orden_pago = ? ", [$monto, $idOpa]);
            }

            return true;
        }
        return false;
    }

    public function findByCrearCheque($cheque)
    {
        return TestChequesEntity::create([
            'id_cuenta_bancaria' => $cheque->id_cuenta_bancaria,
            'tipo_cheque' => 'TERCERO',
            'numero_cheque' => $cheque->num_cheque,
            'monto' => $cheque->monto_pago,
            'fecha_emision' => $cheque->fecha_confirma_pago,
            'fecha_vencimiento' => $cheque->fecha_confirma_pago,
            'tipo' => 'EMISION',
            'estado' => 'ACTIVO',
            'descripcion' => null,
            'archivo_adjunto' => null,
            'cod_usuario_registra' => $this->user->cod_usuario,
            'fecha_registra' => $this->fechaActual,
            'beneficiario' => $cheque->beneficiario,
            'numero_cheque_anterior' => null,
            'is_echeck' => 0,
            'id_chequera' => $cheque->id_chequera
        ]);
    }

    public function findByListTipoEstado()
    {
        return TesEstadoPagoEntity::get();
    }

    /**
     * Determina si es el primer pago del mes para un prestador específico
     * 
     * @param int $idPrestador ID del prestador
     * @param string $fecha Fecha del pago a verificar (Y-m-d o Carbon)
     * @return bool true si es el primer pago del mes, false si no
     */
    public function esPrimerPagoDelMes($idPrestador, $fecha)
    {
        try {
            $fechaPago = $fecha instanceof Carbon ? $fecha : Carbon::parse($fecha);

            $primerDiaMes = $fechaPago->copy()->startOfMonth()->toDateString();
            $ultimoDiaMes = $fechaPago->copy()->endOfMonth()->toDateString();

            // Obtener IDs de pagos del prestador en ese mes que tengan detalles confirmados
            $pagosDelPrestador = TesPagoEntity::whereHas('opa.prestador', function ($q) use ($idPrestador) {
                $q->where('cod_prestador', $idPrestador);
            })
                ->where('tipo_factura', 'PRESTADOR')
                ->pluck('id_pago');

            // Verificar si alguno de esos pagos ya tiene detalles con fecha_acreditacion en el mes
            $detallesEnElMes = TesPagoDetalleEntity::whereIn('id_pago', $pagosDelPrestador)
                ->whereBetween(DB::raw('DATE(fecha_acreditacion)'), [$primerDiaMes, $ultimoDiaMes])
                ->exists();

            return !$detallesEnElMes;

        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Determina si es el primer pago del mes basado en el ID del pago
     * 
     * @param int $idPago ID del pago a verificar
     * @return bool true si es el primer pago del mes, false si no
     */
    public function esPrimerPagoDelMesPorIdPago($idPago)
    {
        try {
            $pago = TesPagoEntity::with('opa.prestador')->find($idPago);

            if (!$pago || !$pago->opa || !$pago->opa->prestador) {
                return false;
            }

            return $this->esPrimerPagoDelMes($pago->opa->prestador->cod_prestador, Carbon::now());

        } catch (\Throwable $e) {
            return false;
        }
    }

        /**
     * Recalcula y actualiza el monto total pagado para un pago (sum de sus detalles)
     */
    public function recalcPagoTotal($idPago)
    {
        $total = (float) TesPagoDetalleEntity::where('id_pago', $idPago)
            ->selectRaw('SUM(monto) as total')
            ->value('total');

        $totalRetenido = (float) PagoRetencionesEntity::where('id_pago', $idPago)
            ->sum('monto');

        $pago = TesPagoEntity::find($idPago);
        if ($pago) {
            $pago->monto_total_pagado = round($total, 2);
            $pago->monto_total_retenido = round($totalRetenido, 2);
            $pago->update();
        }
        return $total;
    }

    /**
     * Recalcula el estado del pago a partir de sus detalles (monto pagado)
     * - Si suma == 0 => GENERADO (1)
     * - Si 0 < suma < monto_opa => EN PROCESO (2)
     * - Si suma >= monto_opa => CONFIRMADO (3)
     * Actualiza `monto_total_pagado` y `id_estado_pago`, y recalcula el estado de la OPA.
     */
    public function recalcPagoEstadoFromDetalles($idPago)
    {
        try {
            $sumDetalles = (float) TesPagoDetalleEntity::where('id_pago', $idPago)->sum('monto');
            $sumRetenciones = (float) PagoRetencionesEntity::where('id_pago', $idPago)->sum('monto');
            $pago = TesPagoEntity::find($idPago);
            if (!$pago) {
                return null;
            }

            $montoOpa = (float) ($pago->monto_opa ?? 0);

            $generadoId = $this->getPagoEstadoIdByName('GENERADO') ?? 1;
            $enProcesoId = $this->getPagoEstadoIdByName('EN PROCESO') ?? 2;
            $confirmadoId = $this->getPagoEstadoIdByName('CONFIRMADO') ?? 3;

            if (abs($sumDetalles) < 0.0001) {
                $nuevoEstado = $generadoId;
            } elseif ($sumDetalles + $sumRetenciones + 0.0001 >= $montoOpa) {
                $nuevoEstado = $confirmadoId;
            } else {
                $nuevoEstado = $enProcesoId;
            }

            $pago->monto_total_pagado = round($sumDetalles, 2);
            $pago->monto_total_retenido = round($sumRetenciones, 2);
            $pago->id_estado_pago = $nuevoEstado;
            $pago->update();

            // Recalcular estado de la OPA asociado al pago
            if (isset($pago->id_orden_pago)) {
                $this->recalcOpaTotalsAndState($pago->id_orden_pago);
            }

            return $pago;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Recalcula el total pagado de la OPA (sum de monto_total_pagado en tb_tes_pago)
     * y actualiza el estado de la OPA según la regla proporcionada.
     */
    public function recalcOpaTotalsAndState($idOpa)
    {
        // Nuevo comportamiento:
        // - total_pagado_confirmado = SUM(monto_total_pagado) WHERE id_estado_pago = CONFIRMADO
        // - si existen pagos EN PROCESO -> OPA = EN PROCESO
        // - si total = 0 -> PENDIENTE
        // - si 0 < total < monto_orden -> PARCIALMENTE PAGADA
        // - si total >= monto_orden -> PAGADA

        $opa = TesOrdenPagoEntity::find($idOpa);
        if (!$opa)
            return null;

        $montoOrden = (float) ($opa->monto_orden_pago ?? 0);

        $confirmadoId = $this->getPagoEstadoIdByName('CONFIRMADO') ?? 3;
        $enProcesoPagoId = $this->getPagoEstadoIdByName('EN PROCESO') ?? 2;

        $totalConfirmado = (float) TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_pago', $confirmadoId)
            ->sum(DB::raw('monto_total_pagado + monto_total_retenido'));

        $existenEnProceso = TesPagoEntity::where('id_orden_pago', $idOpa)
            ->where('id_estado_pago', $enProcesoPagoId)
            ->exists();

        $pendienteId = $this->getEstadoIdByName('Pendiente') ?? 1;
        $enProcesoOpaId = $this->getEstadoIdByName('En Proceso') ?? 2;
        $parcialmentePagadaId = $this->getEstadoIdByName('Parcialmente Pagada') ?? $this->getEstadoIdByName('Parcial') ?? 3;
        $pagadaId = $this->getEstadoIdByName('Pagada') ?? 4;

        if ($existenEnProceso) {
            $nuevoEstado = $enProcesoOpaId;
        } elseif (abs($totalConfirmado) < 0.0001) {
            $nuevoEstado = $pendienteId;
        } elseif ($totalConfirmado > 0 && $totalConfirmado < $montoOrden) {
            $nuevoEstado = $parcialmentePagadaId;
        } else {
            $nuevoEstado = $pagadaId;
        }

        if (!is_null($nuevoEstado)) {
            $opa->id_estado_orden_pago = $nuevoEstado;
            $opa->update();
        }

        return [
            'total_pagado_confirmado' => $totalConfirmado,
            'monto_orden' => $montoOrden,
            'estado' => $nuevoEstado,
            'existen_pagos_en_proceso' => $existenEnProceso
        ];
    }

    private function getPagoEstadoIdByName($name)
    {
        if (empty($name))
            return null;
        $estado = TesEstadoPagoEntity::whereRaw('LOWER(descripcion_estado) = ?', [strtolower($name)])->first();
        return $estado ? $estado->id_estado_pago : null;
    }

    private function getEstadoIdByName($name)
    {
        if (empty($name))
            return null;
        $estado = TesEstadoOrdenPagoEntity::whereRaw('LOWER(descripcion_estado) = ?', [strtolower($name)])->first();
        return $estado ? $estado->id_estado_orden_pago : null;
    }

}
