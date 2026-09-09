<?php

namespace App\Http\Controllers\Contabilidad\Repository;

use App\Http\Controllers\Prestadores\repository\PrestadorRepository;
use App\Models\Contabilidad\AsientosContablesEntity;
use App\Models\Contabilidad\BancoCuentasContableEntity;
use App\Models\Contabilidad\DetalleAsientosContablesEntity;
use App\Models\Contabilidad\FamiliaCuentaContableEntity;
use App\Models\Contabilidad\ImputacionesCuentaContableEntity;
use App\Models\Contabilidad\ImputacionesProveedoresCuentaContableEntity;
use App\Models\Contabilidad\ProveedorCuentaContableEntity;
use App\Models\Contabilidad\RazonCuentaContableEntity;
use App\Models\Contabilidad\FormasPagoCuentasContableEntity;
use App\Models\Contabilidad\RetencionCuentasContablesEntity;
use App\Models\Contabilidad\TipoPrestadorCuentaContableEntity;
use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Http\Controllers\proveedor\Repository\ProveedorRepository;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Log;

class AsientoContableRepository
{

    private $user;
    private $fechaActual;
    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findByCrearAsiento($id_tipo_asiento, $asiento_modelo, $asiento_leyenda, $numero, $id_periodo_contable, $numero_referencia, $vigente, $id_razon = null)
    {
        return AsientosContablesEntity::create([
            'id_tipo_asiento' => $id_tipo_asiento,
            'fecha_asiento' => $this->fechaActual->toDateString(),
            'asiento_modelo' => $asiento_modelo,
            'asiento_leyenda' => $asiento_leyenda,
            'numero' => $numero,
            'numero_referencia' => $numero_referencia,
            'id_periodo_contable' => $id_periodo_contable,
            'id_razon' => $id_razon,
            'cod_usuario_crea' => $this->user->cod_usuario,
            'fecha_registra' => $this->fechaActual,
            'vigente' => $vigente
        ]);
    }

    public function findByCrearDetalleAsiento($params)
    {
        return DetalleAsientosContablesEntity::create([
            'id_asiento_contable' => $params['id_asiento_contable'],
            'cod_proveedor' => $params['cod_proveedor'] ?? null,
            'id_imputacion_proveedor' => $params['id_imputacion_proveedor'] ?? null,
            'cod_prestador' => $params['cod_prestador'] ?? null,
            'id_imputacion_prestador' => $params['id_imputacion_prestador'] ?? null,
            'id_proveedor_cuenta_contable' => $params['id_proveedor_cuenta_contable'] ?? null,
            'id_tipo_prestador_cuenta_contable' => $params['id_tipo_prestador_cuenta_contable'] ?? null,
            'id_forma_pago_cuenta_contable' => $params['id_forma_pago_cuenta_contable'] ?? null,
            'id_familia_cuenta_contable' => $params['id_familia_cuenta_contable'] ?? null,
            'id_cuenta_bancaria_cuenta_contable' => $params['id_cuenta_bancaria_cuenta_contable'] ?? null,
            'id_retencion_cuenta_contable' => $params['id_retencion_cuenta_contable'] ?? null,
            'monto_debe' => $params['monto_debe'],
            'monto_haber' => $params['monto_haber'],
            'observaciones' => $params['observaciones'],
            'id_detalle_plan' => $params['id_detalle_plan'],
            'recursor' => (int) $params['monto_debe'] > 0 ? '1' : '0'
        ]);
    }

    public function findByUpdateAsiento(
        $id_tipo_asiento,
        $fecha_asiento,
        $asiento_modelo,
        $asiento_leyenda,
        $numero,
        $id_periodo_contable,
        $numero_referencia,
        $asiento_observaciones,
        $idAsiento
    ) {
        $asiento = AsientosContablesEntity::find($idAsiento);
        $asiento->id_tipo_asiento = $id_tipo_asiento;
        $asiento->fecha_asiento = $fecha_asiento;
        $asiento->asiento_modelo = $asiento_modelo;
        $asiento->asiento_leyenda = $asiento_leyenda;
        $asiento->numero = $numero;
        $asiento->numero_referencia = $numero_referencia;
        $asiento->asiento_observaciones = $asiento_observaciones;
        $asiento->id_periodo_contable = $id_periodo_contable;
        $asiento->cod_usuario_modifica = $this->user->cod_usuario;
        $asiento->fecha_modifica = $this->fechaActual;
        return $asiento->update();
    }

    public function findByUpdateDetalleItemAsiento($params, $id)
    {
        $item = DetalleAsientosContablesEntity::find($id);
        $item->id_asiento_contable = $params['id_asiento_contable'];
        $item->id_proveedor_cuenta_contable = $params['id_proveedor_cuenta_contable'] ?? null;
        $item->id_tipo_prestador_cuenta_contable = $params['id_tipo_prestador_cuenta_contable'] ?? null;
        $item->id_forma_pago_cuenta_contable = $params['id_forma_pago_cuenta_contable'];
        $item->id_familia_cuenta_contable = $params['id_familia_cuenta_contable'] ?? null;
        $item->id_cuenta_bancaria_cuenta_contable = $params['id_cuenta_bancaria_cuenta_contable'] ?? null;
        $item->monto_debe = $params['monto_debe'];
        $item->monto_haber = $params['monto_haber'];
        $item->observaciones = $params['observaciones'];
        $item->id_detalle_plan = $params['id_detalle_plan'];
        return $item->update();
    }

    public function findByListar($params)
    {
        $query = AsientosContablesEntity::with(['tipo']);

        if (!is_null($params->id_razon ?? null) && !empty($params->id_razon)) {
            $query->where('id_razon', $params->id_razon);
        }

        if (!is_null($params->id_periodo_contable)) {
            $query->where('id_periodo_contable', $params->id_periodo_contable);
        }

        if (!is_null($params->numero)) {
            $query->where('numero', 'LIKE', "%$params->numero");
        }

        if (!is_null($params->desde) && !is_null($params->hasta)) {
            $query->whereBetween('fecha_asiento', [$params->desde, $params->hasta]);
        }

        if (!is_null($params->leyenda)) {
            $query->where('asiento_leyenda', 'LIKE', "%$params->leyenda%");
        }

        $query->orderByDesc('numero');

        return $query->get();
    }

    public function findById($id)
    {
        return AsientosContablesEntity::with(['detalle', 'detalle.planCuenta'])
            ->find($id);
    }

    // Obtener detalle de asiento por id (necesario para validar periodo antes de borrar)
    public function findDetalleById($id)
    {
        return DetalleAsientosContablesEntity::find($id);
    }

    public function findByDeleteDetalleId($id)
    {
        return DetalleAsientosContablesEntity::find($id)->delete();
    }

    public function findByAnularAsientoContableId($id, $vigente)
    {
        $asiento = AsientosContablesEntity::find($id);
        $asiento->vigente = $vigente;
        return $asiento->update();
    }

    public function findByContraAsientoContableId($numero, $numero_referencia, $vigente)
    {
        $asiento = AsientosContablesEntity::where('numero', $numero)->first();

        if (!$asiento) {
            throw new \Exception("No se encontró el asiento contable con número: {$numero}");
        }

        $asiento->numero_referencia = $numero_referencia;
        $asiento->vigente = $vigente;
        return $asiento->update();
    }

    //Metodos Franco

    //======================================


    //Proveedor

    public function verificarProveedorTieneCuentaContable($idProveedor)
    {
        return ProveedorCuentaContableEntity::where('id_proveedor', $idProveedor)
            // ->where('vigente', 1)
            ->exists();
    }

    public function obtenerCuentaContableProveedor($idProveedor)
    {
        return ProveedorCuentaContableEntity::where('id_proveedor', $idProveedor)
            // ->where('vigente', 1)
            ->first();
    }
    //Tipos de prestadores

    public function verificarTipoPrestadorTieneCuentaContable($cod_tipo_prestador)
    {
        return TipoPrestadorCuentaContableEntity::where('cod_tipo_prestador', $cod_tipo_prestador)
            // ->where('vigente', 1)
            ->exists();
    }

    public function obtenerCuentaContableTipoPrestador($cod_tipo_prestador)
    {
        return TipoPrestadorCuentaContableEntity::where('cod_tipo_prestador', $cod_tipo_prestador)
            // ->where('vigente', 1)
            ->first();
    }

    //Metodos de pago
    public function verificarMetodoPagoTieneCuentaContable($idMetodoPago)
    {
        return FormasPagoCuentasContableEntity::where('id_forma_pago', $idMetodoPago)
            ->exists();
    }
    public function obtenerCuentaContableMetodoPago($idMetodoPago)
    {
        return FormasPagoCuentasContableEntity::where('id_forma_pago', $idMetodoPago)
            ->first();
    }
    //Cuentas bancarias
    public function verificarCuentaBancariaTieneCuentaContable($idCuentaBancaria)
    {
        // Verificar si existe una relación en la tabla de banco-cuentas contables
        return BancoCuentasContableEntity::where('id_cuenta_bancaria', $idCuentaBancaria)
            ->where('vigente', '1')
            ->exists();
    }
    /**
     * Cuenta contable de BANCO/CAJA de una cuenta bancaria.
     *
     * El filtro por `tipo` es imprescindible: desde 2026-09-04 una misma cuenta bancaria puede
     * tener dos mapeos — el de banco y el de eCheq diferido. Sin filtrar, `->first()` devolvía
     * uno u otro según el orden de inserción, que es un bug silencioso y difícil de rastrear.
     */
    public function obtenerCuentaContableByCuentaBancaria($idCuentaBancaria)
    {
        return BancoCuentasContableEntity::where('id_cuenta_bancaria', $idCuentaBancaria)
            ->where('tipo', BancoCuentasContableEntity::TIPO_BANCO)
            ->where('vigente', '1')
            ->first();
    }

    /**
     * Cuenta puente de pasivo para los eCheq emitidos y todavía no debitados.
     *
     * Devuelve null si Contabilidad no cargó el mapeo para esa cuenta bancaria — el llamador
     * decide si eso es un error o si simplemente todavía no se opera con eCheq en esa cuenta.
     */
    public function obtenerCuentaContableEcheqDiferido($idCuentaBancaria)
    {
        return BancoCuentasContableEntity::where('id_cuenta_bancaria', $idCuentaBancaria)
            ->where('tipo', BancoCuentasContableEntity::TIPO_ECHEQ_DIFERIDO)
            ->where('vigente', '1')
            ->first();
    }
    //Familia de articulos
    public function verificarFamiliaTieneCuentaContable($idFamilia)
    {
        return FamiliaCuentaContableEntity::where('id_tipo_familia', $idFamilia)
            ->where('vigente', 1)
            ->exists();
    }

    public function obtenerCuentaContableFamilia($idFamilia)
    {
        return FamiliaCuentaContableEntity::where('id_tipo_familia', $idFamilia)
            ->where('vigente', 1)
            ->first();
    }

    public function obtenerCuentaBancariaPorPlanContable($idDetallePlan)
    {
        return BancoCuentasContableEntity::where('id_detalle_plan', $idDetallePlan)
            ->where('vigente', '1')
            ->first();
    }

    //Imputaciones genericas
    public function obtenerImputacionPorPlanContable($idDetallePlan)
    {
        return ImputacionesCuentaContableEntity::where('id_detalle_plan', $idDetallePlan)
            ->where('vigente', '1')
            ->first();
    }

    //Obtener cuenta contable de imputacion prestador con el id de imputacion
    public function obtenerCuentaContableImputacion($idImputacion)
    {
        return ImputacionesCuentaContableEntity::where('id_imputacion_cuenta_contable', $idImputacion)
            ->where('vigente', '1')
            ->first();
    }

    //Obtener cuenta contable de imputacion proveedor con el id de imputacion
    public function obtenerCuentaContableImputacionProveedor($idImputacion)
    {
        return ImputacionesProveedoresCuentaContableEntity::where('id_imputacion_proveedor_cuenta_contable', $idImputacion)
            ->where('vigente', '1')
            ->first();
    }

    /**
     * Cuenta contable de contraparte (prestadores/proveedores) según la razón social.
     *
     * Es el pasivo que se acredita al facturar y se debita al pagar: ambos lados tienen que
     * resolver la MISMA cuenta o la deuda nunca se cancela. Antes estaba hardcodeado 35/36,
     * que solo era válido para Alba; ahora sale de tb_cont_razon_cuenta_contable, que cada
     * base (Alba / OSV) carga con sus propios id_detalle_plan.
     */
    public function obtenerCuentaContraparteByRazon($idRazon, $esProveedor)
    {
        $tipo = $esProveedor
            ? RazonCuentaContableEntity::TIPO_PROVEEDOR
            : RazonCuentaContableEntity::TIPO_PRESTADOR;

        if (empty($idRazon)) {
            throw new Exception("Falta la razón social para determinar la cuenta contable de {$tipo}. Por favor contacte con el administrador.");
        }

        $relacion = RazonCuentaContableEntity::where('id_razon', $idRazon)
            ->where('tipo_contraparte', $tipo)
            ->where('vigente', 1)
            ->first();

        if (!$relacion || empty($relacion->id_detalle_plan)) {
            throw new Exception("La razón social seleccionada no tiene configurada la cuenta contable de {$tipo}. Por favor contacte con Contabilidad.");
        }

        return $relacion->id_detalle_plan;
    }

    //Retenciones

    public function verificarRetencionTieneCuentaContable($idRetencion)
    {
        return RetencionCuentasContablesEntity::where('id_retencion', $idRetencion)
            ->where('vigente', 1)
            ->exists();
    }

    public function obtenerCuentaContableRetencion($idRetencion)
    {
        return RetencionCuentasContablesEntity::where('id_retencion', $idRetencion)
            ->first();
    }



    //======================================

    public function obtenerSiguienteNumeroAsiento()
    {
        $ultimoAsiento = AsientosContablesEntity::orderBy('numero', 'desc')->first();
        return $ultimoAsiento ? $ultimoAsiento->numero + 1 : 1;
    }

    /**
     * Crear un asiento contable para una factura
     * Etapa 1: Verificar que el proveedor tenga una cuenta contable asignada
     * Etapa 2: Crear el asiento contable con los detalles correspondientes (Verificar Debe id_detalle_plan)
     * Asiento creado para proveedores ok
     */
    private function obtenerCodProveedorReal($datosFactura)
    {
        // Si viene directamente como proveedor, usar ese ID
        if (!empty($datosFactura['id_proveedor']) && empty($datosFactura['id_prestador'])) {
            return $datosFactura['id_proveedor'];
        }

        // Si es prestador, buscar en proveedores por CUIT -- suspendemos este por ahora
        // Prestadores lo vamos a guardar por tipo de prestador
        if (!empty($datosFactura['id_prestador']) && !empty($datosFactura['cuit'])) {
            $proveedorRepo = new ProveedorRepository();
            $proveedor = $proveedorRepo->findByCuit($datosFactura['cuit']);

            if ($proveedor) {
                \Log::info('Prestador encontrado como proveedor por CUIT:', [
                    'cuit' => $datosFactura['cuit'],
                    'cod_proveedor' => $proveedor->cod_proveedor
                ]);
                return $proveedor->cod_proveedor;
            }
        }

        return null;
    }

    private function validarPeriodoContable($idPeriodoContable, $contexto)
    {
        if (is_null($idPeriodoContable) || !is_numeric($idPeriodoContable) || (int) $idPeriodoContable <= 0) {
            throw new Exception("No se recibió un período contable válido para {$contexto}.");
        }
    }

    private function validarCamposRequeridos(array $datos, array $camposRequeridos, $contexto)
    {
        $faltantes = [];

        foreach ($camposRequeridos as $campo) {
            if (!array_key_exists($campo, $datos) || is_null($datos[$campo]) || $datos[$campo] === '') {
                $faltantes[] = $campo;
            }
        }

        if (!empty($faltantes)) {
            throw new Exception("Faltan campos obligatorios para {$contexto}: " . implode(', ', $faltantes));
        }
    }

    public function crearAsientoFactura($datosFactura, $idPeriodoContable)
    {
        $idRazon = $datosFactura['id_razon'] ?? null;
        if (!$idRazon) {
            throw new Exception("Falta la razón social para registrar el asiento de la factura. Por favor contacte con el administrador.");
        }

        // Determinar tipo: prestador o proveedor
        $esPrestador = !empty($datosFactura['id_prestador']);
        $esProveedor = !empty($datosFactura['id_proveedor']);

        if (!$esPrestador && !$esProveedor) {
            throw new Exception("No se pudo determinar si la factura es de prestador o proveedor para registrar el asiento. Por favor contacte con Contabilidad.");
        }

        // Validar imputación DEBE
        $idImputacionDebe = $datosFactura['idImputacionDebe'] ?? null;
        if (!$idImputacionDebe) {
            throw new Exception("Falta la imputación contable (DEBE) para registrar el asiento. Por favor contacte con Contabilidad.");
        }

        // Prestador → tb_cont_imputacion_prestadores_cuenta_contable
        // Proveedor → tb_cont_imputacion_proveedores_cuenta_contable
        $imputacionDebe = $esPrestador
            ? $this->obtenerCuentaContableImputacion($idImputacionDebe)
            : $this->obtenerCuentaContableImputacionProveedor($idImputacionDebe);

        if (!$imputacionDebe) {
            throw new Exception("La imputación contable seleccionada no tiene una cuenta contable asignada. Por favor contacte con Contabilidad.");
        }

        // Cuenta de contraparte según razón social (antes hardcodeado: 35 prestador / 36 proveedor).
        // El asiento de pago debita esta MISMA cuenta para cancelar la deuda (ver crearAsientoPago).
        $idDetallePlanHaber = $this->obtenerCuentaContraparteByRazon($idRazon, !$esPrestador);

        // Leyenda: FACTURA - CUIT - NOMBRE - NUMERO - FECHA
        $leyenda = 'FACTURA - ' . ($datosFactura['cuit'] ?? '') . ' - ' .
            ($datosFactura['nombre'] ?? '') . ' - ' .
            $datosFactura['numero_factura'] . ' - ' .
            'FECHA: ' . $datosFactura['fecha_registra'];

        $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

        $asiento = $this->findByCrearAsiento(
            1,
            'FACTURA',
            $leyenda,
            $numeroCorrelativo,
            $idPeriodoContable,
            $datosFactura['id_factura'],
            'ACTIVO',
            $idRazon
        );

        $esFacturaProveedor = $datosFactura['id_tipo_factura'] == 16;

        // DEBE — imputación dinámica desde tb_cont_imputacion_prestadores/proveedores_cuenta_contable
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'cod_proveedor' => null,
            'id_imputacion_proveedor' => $esFacturaProveedor ? $idImputacionDebe : null,
            'cod_prestador' => null,
            'id_imputacion_prestador' => !$esFacturaProveedor ? $idImputacionDebe : null,
            'id_proveedor_cuenta_contable' => null,
            'id_tipo_prestador_cuenta_contable' => null,
            'id_forma_pago_cuenta_contable' => null,
            'id_familia_cuenta_contable' => null,
            'id_cuenta_bancaria_cuenta_contable' => null,
            'id_retencion_cuenta_contable' => null,
            'monto_debe' => $datosFactura['total_factura'],
            'monto_haber' => 0,
            'observaciones' => 'Gasto - ' . ($imputacionDebe->imputacion ?? ''),
            'id_detalle_plan' => $imputacionDebe->id_detalle_plan,
        ]);

        // HABER — hardcodeado: prestador = 35 (DEUDAS PRESTACIONALES), proveedor = 36 (PROVEEDORES)
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'cod_proveedor' => $esFacturaProveedor ? ($datosFactura['id_proveedor'] ?? null) : null,
            'cod_prestador' => !$esFacturaProveedor ? ($datosFactura['id_prestador'] ?? null) : null,
            'id_proveedor_cuenta_contable' => null,
            'id_tipo_prestador_cuenta_contable' => null,
            'id_forma_pago_cuenta_contable' => null,
            'id_familia_cuenta_contable' => null,
            'id_cuenta_bancaria_cuenta_contable' => null,
            'id_retencion_cuenta_contable' => null,
            'monto_debe' => 0,
            'monto_haber' => $datosFactura['total_factura'],
            'observaciones' => $esFacturaProveedor ? 'Proveedores a pagar' : 'Deudas prestacionales',
            'id_detalle_plan' => $idDetallePlanHaber,
        ]);

        return $asiento;
    }

    public function crearAsientoPago($datosPago, $idPeriodoContable)
    {
        $idRazon = $datosPago['id_razon'] ?? null;
        if (!$idRazon) {
            throw new Exception("Falta la razón social para registrar el asiento del pago. Por favor contacte con el administrador.");
        }

        $idProveedor  = $datosPago['id_proveedor'] ?? null;
        $idPrestador  = $datosPago['id_prestador'] ?? null;

        if (!$idProveedor && !$idPrestador) {
            throw new Exception("No se pudo determinar si el pago es de proveedor o prestador. Por favor contacte con Contabilidad.");
        }

        // id_tipo_factura == 16 ("BIENES Y SERVICIOS") es la señal real de proveedor. Si no viene
        // (payloads viejos que no lo mandan), cae al criterio anterior por presencia de id_proveedor.
        $idTipoFactura = $datosPago['id_tipo_factura'] ?? null;
        $esFacturaProveedor = !is_null($idTipoFactura) ? ($idTipoFactura == 16) : !empty($idProveedor);

        // Validar cuenta bancaria. Si vino el desglose por cuenta, cada una se valida en el loop
        // del HABER — chequear ademas `id_cuenta_bancaria` acá haría fallar por una cuenta que ni
        // siquiera se va a usar.
        if (empty($datosPago['cuentas'])) {
            $cuentaBancaria = $this->obtenerCuentaContableByCuentaBancaria($datosPago['id_cuenta_bancaria']);
            if (!$cuentaBancaria) {
                throw new Exception("La cuenta bancaria seleccionada no tiene una cuenta contable asignada. Por favor contacte con Contabilidad.");
            }
        }

        $montoTotal = (float) $datosPago['monto_total'];
        if ($montoTotal <= 0) {
            throw new Exception("El monto del pago debe ser mayor a cero para registrar el asiento contable.");
        }

        // DEBE: misma cuenta de contraparte que acreditó la factura (antes hardcodeado 35/36).
        // Tiene que resolver igual que crearAsientoFactura o la deuda nunca se cancela.
        $idDetallePlanDebe = $this->obtenerCuentaContraparteByRazon($idRazon, $esFacturaProveedor);
        $idRazon = $datosPago['id_razon'] ?? null;

        // Leyenda: PAGO - CUIT - NOMBRE - NRO_PAGO - FECHA
        $leyenda = 'PAGO - ' . ($datosPago['cuit'] ?? '') . ' - ' .
            ($datosPago['nombre'] ?? '') . ' - ' .
            $datosPago['numero_pago'] . ' - ' .
            'FECHA: ' . $datosPago['fecha_registra'];

        $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

        // numero_referencia = id_pago para que agregarDetalleRetencionAlAsientoPago lo pueda localizar
        $asiento = $this->findByCrearAsiento(
            1,
            'PAGO',
            $leyenda,
            $numeroCorrelativo,
            $idPeriodoContable,
            $datosPago['id_pago'],
            'ACTIVO',
            $idRazon
        );

        // DEBE — reduce el pasivo (proveedor/prestador a pagar)
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable'               => $asiento->id_asiento_contable,
            'cod_proveedor'                     => $esFacturaProveedor ? $idProveedor : null,
            'cod_prestador'                     => !$esFacturaProveedor ? $idPrestador : null,
            'id_proveedor_cuenta_contable'      => null,
            'id_tipo_prestador_cuenta_contable' => null,
            'id_forma_pago_cuenta_contable'     => null,
            'id_familia_cuenta_contable'        => null,
            'id_cuenta_bancaria_cuenta_contable'=> null,
            'id_retencion_cuenta_contable'      => null,
            'monto_debe'                        => $montoTotal,
            'monto_haber'                       => 0,
            'observaciones'                     => $esFacturaProveedor ? 'Cancelación deuda proveedor' : 'Cancelación deuda prestacional',
            'id_detalle_plan'                   => $idDetallePlanDebe,
        ]);

        // HABER — salida de fondos, UNA LINEA POR CUENTA BANCARIA.
        //
        // Desde el 2026-09-06 una orden puede pagarse desde dos cuentas de bancos distintos (cada
        // abono trae la suya). Con una sola linea por el total, el asiento acreditaba TODO a un
        // banco: la contabilidad decia que salieron $300 del Macro cuando en realidad salieron
        // $100 del Macro y $200 del BBVA. Con una sola cuenta -el caso habitual- esto produce
        // exactamente el mismo asiento que antes. (2026-09-09)
        $desglose = $datosPago['cuentas'] ?? [];

        if (empty($desglose)) {
            $desglose = [$datosPago['id_cuenta_bancaria'] => $montoTotal];
        }

        foreach ($desglose as $idCuenta => $montoCuenta) {
            $cuentaDelHaber = $this->obtenerCuentaContableByCuentaBancaria($idCuenta);

            if (!$cuentaDelHaber) {
                throw new Exception(
                    "La cuenta bancaria seleccionada no tiene una cuenta contable asignada. "
                        . "Por favor contacte con Contabilidad."
                );
            }

            $this->findByCrearDetalleAsiento([
                'id_asiento_contable'               => $asiento->id_asiento_contable,
                'cod_proveedor'                     => null,
                'cod_prestador'                     => null,
                'id_proveedor_cuenta_contable'      => null,
                'id_tipo_prestador_cuenta_contable' => null,
                'id_forma_pago_cuenta_contable'     => null,
                'id_familia_cuenta_contable'        => null,
                'id_cuenta_bancaria_cuenta_contable'=> $cuentaDelHaber->id_cuenta_bancaria_cuenta_contable ?? null,
                'id_retencion_cuenta_contable'      => null,
                'monto_debe'                        => 0,
                'monto_haber'                       => round((float) $montoCuenta, 2),
                'observaciones'                     => 'Salida de fondos - Cuenta: ' . $idCuenta,
                'id_detalle_plan'                   => $cuentaDelHaber->id_detalle_plan,
            ]);
        }

        return $asiento;
    }


    public function crearAsientoTransaccion($datosTransaccion, $idPeriodoContable)
    {

        // Mapeo de tipo de transacción
        // (Podés ajustar los IDs según tu configuración real)
        $tipos = [
            1 => 'INGRESO',
            2 => 'EGRESO',
            3 => 'MOVIMIENTO ENTRE CUENTAS'
        ];

        $tipoId = $datosTransaccion['id_tipo_transaccion'];

        $tipo = isset($tipos[$tipoId]) ? $tipos[$tipoId] : 'DESCONOCIDO';

        // Obtener cuentas contables
        $cuentaOrigen = $this->obtenerCuentaContableByCuentaBancaria($datosTransaccion['id_cuenta_bancaria']);
        $cuentaDestino = null;

        if (!empty($datosTransaccion['id_cuenta_bancaria_destino'])) {
            $cuentaDestino = $this->obtenerCuentaContableByCuentaBancaria($datosTransaccion['id_cuenta_bancaria_destino']);
        }

        $montoOperacion = (float) $datosTransaccion['monto_operacion'];
        $montoRetencion = (float) $datosTransaccion['monto_retencion'];
        $montoTotal = $montoOperacion - $montoRetencion;


        // Leyenda descriptiva
        $leyenda = strtoupper($tipo) . ' - ' .
            'Cuenta Origen: ' . $datosTransaccion['id_cuenta_bancaria'] .
            (!empty($datosTransaccion['id_cuenta_bancaria_destino']) ?
                ' → Destino: ' . $datosTransaccion['id_cuenta_bancaria_destino'] : '') .
            ' | Monto: $' . number_format($montoOperacion, 2) .
            (!empty($datosTransaccion['num_factura']) ? ' | Factura: ' . $datosTransaccion['num_factura'] : '') .
            ' | Fecha: ' . $datosTransaccion['fecha_operacion'];

        // Obtener número correlativo
        $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

        // Crear asiento principal
        $asiento = $this->findByCrearAsiento(
            id_tipo_asiento: 1,
            asiento_modelo: $tipo,
            asiento_leyenda: $leyenda,
            numero: $numeroCorrelativo,
            id_periodo_contable: $idPeriodoContable,
            numero_referencia: $datosTransaccion['id_operacion'],
            vigente: 'ACTIVO',
            id_razon: $datosTransaccion['id_razon'] ?? null
        );

        // === CREACIÓN DE DETALLES ===
        switch ($tipo) {
            case 'INGRESO':
                // DEBE: Caja/Banco (entra dinero)
                $this->findByCrearDetalleAsiento([
                    'id_asiento_contable' => $asiento->id_asiento_contable,
                    'monto_debe' => $montoTotal,
                    'monto_haber' => 0,
                    'observaciones' => 'Ingreso de fondos',
                    'id_detalle_plan' => $cuentaOrigen['id_detalle_plan']
                ]);

                // HABER: Cuenta de ingresos o contraparte
                if ($cuentaDestino) {
                    $this->findByCrearDetalleAsiento([
                        'id_asiento_contable' => $asiento->id_asiento_contable,
                        'monto_debe' => 0,
                        'monto_haber' => $montoTotal,
                        'observaciones' => 'Reconocimiento de ingreso',
                        'id_detalle_plan' => $cuentaDestino['id_detalle_plan']
                    ]);
                }
                break;

            case 'EGRESO':
                // DEBE: Gasto o proveedor
                if ($cuentaDestino) {
                    $this->findByCrearDetalleAsiento([
                        'id_asiento_contable' => $asiento->id_asiento_contable,
                        'monto_debe' => $montoTotal,
                        'monto_haber' => 0,
                        'observaciones' => 'Registro de egreso',
                        'id_detalle_plan' => $cuentaDestino['id_detalle_plan']
                    ]);
                }

                // HABER: Caja o Banco (sale dinero)
                $this->findByCrearDetalleAsiento([
                    'id_asiento_contable' => $asiento->id_asiento_contable,
                    'monto_debe' => 0,
                    'monto_haber' => $montoTotal,
                    'observaciones' => 'Salida de fondos',
                    'id_detalle_plan' => $cuentaOrigen['id_detalle_plan']
                ]);
                break;

            case 'MOVIMIENTO ENTRE CUENTAS':
                if (!$cuentaDestino) {
                    throw new Exception('Debe indicar la cuenta destino para un movimiento entre cuentas.');
                }

                // DEBE: Cuenta destino (recibe dinero)
                $this->findByCrearDetalleAsiento([
                    'id_asiento_contable' => $asiento->id_asiento_contable,
                    'monto_debe' => $montoTotal,
                    'monto_haber' => 0,
                    'observaciones' => 'Transferencia recibida',
                    'id_detalle_plan' => $cuentaDestino['id_detalle_plan']
                ]);

                // HABER: Cuenta origen (sale dinero)
                $this->findByCrearDetalleAsiento([
                    'id_asiento_contable' => $asiento->id_asiento_contable,
                    'monto_debe' => 0,
                    'monto_haber' => $montoTotal,
                    'observaciones' => 'Transferencia emitida',
                    'id_detalle_plan' => $cuentaOrigen['id_detalle_plan']
                ]);
                break;
        }
    }

    public function crearAsientoReintegro($datosReintegro, $idPeriodoContable)
    {
        \Log::info('Creando asiento para reintegro con datos:', ['datos_reintegro' => $datosReintegro]);


        // Crear leyenda: ID_AFILIADO - NOMBRE_AFILIADO - MOTIVO - FECHA
        $leyenda = 'REINTEGRO - ' .
            'ID: ' . $datosReintegro['id_afiliados'] . ' - ' .
            ($datosReintegro['nombre_afiliado'] ?? 'SIN NOMBRE') . ' - ' .
            $datosReintegro['motivo'] . ' - ' .
            'FECHA: ' . $datosReintegro['fecha_solicitud'];

        // Obtener siguiente número correlativo
        $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

        // Crear asiento
        $asiento = $this->findByCrearAsiento(
            1,
            'REINTEGRO',
            $leyenda,
            $numeroCorrelativo,
            $idPeriodoContable,
            null,
            'ACTIVO',
            $datosReintegro['id_razon'] ?? null
        );

        $montoReintegro = (float) $datosReintegro['importe_reconocido_total'];

        // Crear detalle: DEBE - Cuenta 341 (REINTEGROS)
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'id_proveedor_cuenta_contable' => null,
            'id_tipo_prestador_cuenta_contable' => null,
            'id_forma_pago_cuenta_contable' => null,
            'id_familia_cuenta_contable' => null,
            'id_cuenta_bancaria_cuenta_contable' => null,
            'monto_debe' => $montoReintegro,
            'monto_haber' => 0,
            'observaciones' => 'Reintegro a afiliado - Gasto',
            'id_detalle_plan' => 341 // Cuenta 341 - REINTEGROS (hardcodeado)
        ]);

        // Crear detalle: HABER - Cuenta 171 (REINTEGRO A PAGAR)
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'id_proveedor_cuenta_contable' => null,
            'id_tipo_prestador_cuenta_contable' => null,
            'id_forma_pago_cuenta_contable' => null,
            'id_familia_cuenta_contable' => null,
            'id_cuenta_bancaria_cuenta_contable' => null,
            'monto_debe' => 0,
            'monto_haber' => $montoReintegro,
            'observaciones' => 'Reintegro pendiente de pago',
            'id_detalle_plan' => 171 // Cuenta 171 - REINTEGRO A PAGAR (hardcodeado)
        ]);

        \Log::info('Asiento contable creado exitosamente para reintegro con ID: ' . $asiento->id_asiento_contable);
        return $asiento;
    }

    /**
     * Crear asiento contable para pago de reintegros
     * DEBE: 171 - REINTEGRO A PAGAR (reduce el pasivo)
     * HABER: Cuenta bancaria (reduce el activo)
     */
    public function crearAsientoPagoReintegro($datosReintegrosPago, $idPeriodoContable)
    {
        \Log::info('Iniciando creación de asiento contable para pago de reintegros', $datosReintegrosPago);

        try {

            // Obtener las cuentas contables
            $cuentaBancariaContable = $this->obtenerCuentaContableByCuentaBancaria($datosReintegrosPago['id_cuenta_bancaria']);
            if (!$cuentaBancariaContable) {
                throw new \Exception("No se encontró la configuración contable para la cuenta bancaria seleccionada.");
            }


            // Obtener siguiente número correlativo
            $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

            // Crear descripción del asiento
            $reintegrosIds = collect($datosReintegrosPago['reintegros'])->pluck('id_reintegro')->implode(', ');
            $afiliados = collect($datosReintegrosPago['reintegros'])->pluck('reintegro.afiliado')->filter()->unique()->implode(', ');
            $leyenda = "PAGO REINTEGROS - IDs: {$reintegrosIds} - Afiliados: {$afiliados} - {$datosReintegrosPago['numero_pago']}";

            // Crear asiento principal
            $asiento = $this->findByCrearAsiento(
                1,
                'PAGO REINTEGROS',
                $leyenda,
                $numeroCorrelativo,
                $idPeriodoContable,
                null,
                'ACTIVO',
                $datosReintegrosPago['id_razon'] ?? null
            );

            // DEBE: 171 - REINTEGRO A PAGAR (reduce el pasivo - se está pagando la deuda)
            $this->findByCrearDetalleAsiento([
                'id_asiento_contable' => $asiento->id_asiento_contable,
                'id_proveedor_cuenta_contable' => null,
                'id_tipo_prestador_cuenta_contable' => null,
                'id_forma_pago_cuenta_contable' => null,
                'id_familia_cuenta_contable' => null,
                'id_cuenta_bancaria_cuenta_contable' => null,
                'monto_debe' => $datosReintegrosPago['monto_total_pago'],
                'monto_haber' => 0,
                'observaciones' => 'DEBE - Pago de reintegros - Reducción de pasivo',
                'id_detalle_plan' => 171 // REINTEGRO A PAGAR (hardcodeado como solicitado)
            ]);

            // HABER: Cuenta bancaria (reduce el activo - sale dinero del banco)
            $this->findByCrearDetalleAsiento([
                'id_asiento_contable' => $asiento->id_asiento_contable,
                'id_proveedor_cuenta_contable' => null,
                'id_tipo_prestador_cuenta_contable' => null,
                'id_forma_pago_cuenta_contable' => null,
                'id_familia_cuenta_contable' => null,
                'id_cuenta_bancaria_cuenta_contable' => $cuentaBancariaContable->id_banco_cuenta_contable,
                'monto_debe' => 0,
                'monto_haber' => $datosReintegrosPago['monto_total_pago'],
                'observaciones' => 'HABER - Pago de reintegros - Salida de dinero de cuenta bancaria',
                'id_detalle_plan' => $cuentaBancariaContable->id_detalle_plan
            ]);

            \Log::info('Asiento contable de pago de reintegros creado exitosamente', [
                'id_asiento' => $asiento->id_asiento_contable,
                'numero_correlativo' => $numeroCorrelativo,
                'monto_total' => $datosReintegrosPago['monto_total_pago'],
                'reintegros_Count' => count($datosReintegrosPago['reintegros'])
            ]);


            return $asiento;

        } catch (\Exception $e) {
            \Log::error('Error al crear asiento contable para pago de reintegros', [
                'error' => $e->getMessage(),
                'datos' => $datosReintegrosPago
            ]);
            throw $e;
        }
    }

    /**
     * Agrega un detalle de retención al asiento de pago existente
     * Busca el asiento de pago por id_pago (número_referencia) y agrega un detalle con la retención
     */
    public function agregarDetalleRetencionAlAsientoPago($retencion, $pago, $regla)
    {
        try {
            \Log::info('Agregando detalle de retención al asiento de pago', ['id_pago' => $pago->id_pago, 'id_pago_retencion' => $retencion->id_pago_retencion]);

            // Buscar el asiento de pago existente usando id_pago como numero_referencia
            $asientoPago = AsientosContablesEntity::where('numero_referencia', $pago->id_pago)
                ->where('asiento_modelo', 'PAGO')
                ->latest('id_asiento_contable')
                ->first();

            // Si no existe el asiento de pago, crearlo
            if (!$asientoPago) {
                \Log::info('No se encontró asiento de pago, intentando crear nuevo asiento', ['id_pago' => $pago->id_pago]);

                try {
                    // Obtener período contable activo
                    $periodosRepo = new PeriodosContablesRepository();
                    $periodoActivo = $periodosRepo->findByPeriodoContableActivoNow();

                    if (!$periodoActivo) {
                        throw new Exception('No hay período contable activo para crear el asiento de pago');
                    }

                    \Log::info('Preparando datos para crear asiento de pago', [
                        'id_pago' => $pago->id_pago,
                        'id_cuenta_bancaria' => $pago->id_cuenta_bancaria,
                        'id_proveedor' => $pago->opa->id_proveedor ?? null,
                        'id_prestador' => $pago->opa->id_prestador ?? null,
                        'monto_pago' => $pago->monto_pago
                    ]);

                    // Preparar datos para crear asiento de pago
                    $datosPago = [
                        'id_proveedor' => $pago->opa->id_proveedor ?? null,
                        'id_prestador' => $pago->opa->id_prestador ?? null,
                        'id_cuenta_bancaria' => $pago->id_cuenta_bancaria,
                        'cuit' => $pago->opa->proveedor->cuit ?? $pago->opa->prestador->cod_prestador ?? 'N/A',
                        'nombre' => $pago->opa->proveedor->razon_social ?? $pago->opa->prestador->nombre_prestador ?? 'Desconocido',
                        'numero_pago' => $pago->id_pago,
                        'fecha_registra' => $pago->fecha_registra ?? $this->fechaActual->toDateString(),
                        'ImputacionHaber' => [
                            'totalImporteHaber' => $pago->monto_pago ?? 0
                        ]
                    ];

                    $asientoPago = $this->crearAsientoPago($datosPago, $periodoActivo->id_periodo_contable);
                    \Log::info('✓ Asiento de pago creado exitosamente', [
                        'id_asiento' => $asientoPago->id_asiento_contable,
                        'id_pago' => $pago->id_pago
                    ]);
                } catch (\Exception $e) {
                    \Log::error('✗ Error al crear asiento de pago', [
                        'error' => $e->getMessage(),
                        'id_pago' => $pago->id_pago,
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    // Re-lanzar la excepción para que se propague al try-catch superior
                    throw $e;
                }
            }

            $opa = $pago->opa;
            if (!$opa) {
                throw new Exception("No se encontró información de la OPA para el pago");
            }

            // Obtener cuentas contables según tipo de beneficiario
            $cuentaBeneficiario = null;
            $codProveedor = null;
            $codPrestador = null;

            if (!empty($opa->id_proveedor)) {
                $codProveedor = $opa->id_proveedor;
            } elseif (!empty($opa->id_prestador)) {
                $codPrestador = $opa->id_prestador;
                $prestadorRepo = new PrestadorRepository();
                $prestador = $prestadorRepo->findByExistCodPrestador($codPrestador);

                if ($prestador) {
                    $cuentaBeneficiario = $this->obtenerCuentaContableTipoPrestador($prestador->cod_tipo_prestador);
                }
            }

            // Obtener cuenta de retenciones
            $cuentaRetencion = $this->obtenerCuentaContableRetencion($retencion->id_retencion);

            // Crear observación con detalles de la retención
            $observacionRetencion = 'Retención - ' . ($retencion->tipoRetencion->descripcion ?? '') .
                ' | Base: ' . number_format($retencion->base_imponible, 2) .
                ' | Porcentaje: ' . $retencion->porcentaje . '%' .
                (isset($retencion->observaciones) && $retencion->observaciones ? ' | Obs: ' . $retencion->observaciones : '');

            // Solo lleva Haber
            // Agregar detalle: HABER al beneficiario (ajuste por retención)
            $this->findByCrearDetalleAsiento([
                'id_asiento_contable' => $asientoPago->id_asiento_contable,
                'cod_proveedor' => $codProveedor,
                'cod_prestador' => $codPrestador,
                'id_proveedor_cuenta_contable' => null,
                'id_tipo_prestador_cuenta_contable' => null,
                'id_forma_pago_cuenta_contable' => null,
                'id_familia_cuenta_contable' => null,
                'id_retencion_cuenta_contable' => $cuentaRetencion->id_retencion_cuenta_contable,
                'id_pago_retencion' => $retencion->id_pago_retencion,
                'monto_debe' => 0,
                'monto_haber' => $retencion->monto,
                'observaciones' => $observacionRetencion,
                'id_detalle_plan' => $cuentaRetencion->id_detalle_plan,
            ]);

            \Log::info('Detalle de retención agregado al asiento de pago exitosamente', [
                'id_asiento' => $asientoPago->id_asiento_contable,
                'id_pago_retencion' => $retencion->id_pago_retencion
            ]);

            return $asientoPago;
        } catch (\Exception $e) {
            \Log::error('Error al agregar detalle de retención al asiento de pago', [
                'error' => $e->getMessage(),
                'id_pago' => $pago->id_pago ?? 'N/A',
                'id_pago_retencion' => $retencion->id_pago_retencion ?? 'N/A'
            ]);
            throw $e;
        }
    }

    //======================================
    // MÉTODOS PARA DISCAPACIDAD
    //======================================

    /**
     * Crear asiento contable automático para prestaciones de discapacidad
     */
    public function crearAsientoDiscapacidad($datosDiscapacidad, $idPeriodoContable)
    {
        $this->validarPeriodoContable($idPeriodoContable, 'asiento de discapacidad');
        $this->validarCamposRequeridos($datosDiscapacidad, [
            'id_discapacidad',
            'cuil_beneficiario',
            'periodo_prestacion',
            'fecha_registra',
            'monto_solicitado'
        ], 'asiento de discapacidad');

        // Crear leyenda descriptiva
        $leyenda = 'PRESTACIÓN DISCAPACIDAD - ' .
            'CUIL: ' . $datosDiscapacidad['cuil_beneficiario'] . ' - ' .
            'PRESTADOR: ' . ($datosDiscapacidad['razon_social_prestador'] ?? 'SIN NOMBRE') . ' - ' .
            'PERÍODO: ' . $datosDiscapacidad['periodo_prestacion'] . ' - ' .
            'FECHA: ' . $datosDiscapacidad['fecha_registra'];

        // Obtener siguiente número correlativo
        $numeroCorrelativo = $this->obtenerSiguienteNumeroAsiento();

        // Crear asiento principal
        $asiento = $this->findByCrearAsiento(
            1, // Tipo asiento automático
            'DISCAPACIDAD',
            $leyenda,
            $numeroCorrelativo,
            $idPeriodoContable,
            $datosDiscapacidad['id_discapacidad'], // referencia
            'ACTIVO',
            $datosDiscapacidad['id_razon'] ?? null
        );

        $montoTotal = (float) $datosDiscapacidad['monto_solicitado'];

        // DEBE - Cuenta de gastos prestaciones discapacidad
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'cod_prestador' => $datosDiscapacidad['cod_prestador'] ?? null,
            'monto_debe' => $montoTotal,
            'monto_haber' => 0,
            'observaciones' => 'Gasto prestación discapacidad',
            'id_detalle_plan' => 260, // SANATORIALES
        ]);

        // HABER - Cuenta a pagar prestadores discapacidad
        $this->findByCrearDetalleAsiento([
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'cod_prestador' => $datosDiscapacidad['cod_prestador'] ?? null,
            'monto_debe' => 0,
            'monto_haber' => $montoTotal,
            'observaciones' => 'A pagar prestador discapacidad',
            'id_detalle_plan' => 131,  // ACREED. POR PRESTACIONES ASISTENCIALES
        ]);

        \Log::info('Asiento contable creado para discapacidad', [
            'id_discapacidad' => $datosDiscapacidad['id_discapacidad'],
            'id_asiento_contable' => $asiento->id_asiento_contable,
            'monto' => $montoTotal
        ]);

        return $asiento;
    }

    /**
     * Verificar si una prestación de discapacidad tiene asientos contables
     * (Deprecated: usar AsientosDiscapacidadHistorialRepository->discapacidadTieneAsientos)
     */
    public function discapacidadTieneAsientos($idDiscapacidad)
    {
        // Este método se mantiene por compatibilidad pero se recomienda usar el historial
        return AsientosContablesEntity::where('numero_referencia', $idDiscapacidad)
            ->where('asiento_modelo', 'DISCAPACIDAD')
            ->whereIn('vigente', ['ACTIVO', 'S', '1'])
            ->exists();
    }

    /**
     * Obtener el último asiento de una prestación de discapacidad
     * (Deprecated: usar AsientosDiscapacidadHistorialRepository->obtenerAsientoVigenteDiscapacidad)
     */
    public function obtenerUltimoAsientoDiscapacidad($idDiscapacidad)
    {
        // Este método se mantiene por compatibilidad pero se recomienda usar el historial
        return AsientosContablesEntity::where('numero_referencia', $idDiscapacidad)
            ->where('asiento_modelo', 'DISCAPACIDAD')
            ->whereIn('vigente', ['ACTIVO', 'S', '1'])
            ->orderByDesc('id_asiento_contable')
            ->first();
    }

    /**
     * Crear contraasiento para modificación de prestación de discapacidad
     * (Deprecated: usar AsientosDiscapacidadHistorialRepository->procesarModificacionDiscapacidad)
     */
    public function crearContraAsientoDiscapacidad($idDiscapacidad, $datosDiscapacidad, $idPeriodoContable)
    {
        // Obtener asiento original
        $asientoOriginal = $this->obtenerUltimoAsientoDiscapacidad($idDiscapacidad);

        if (!$asientoOriginal) {
            \Log::warning('No se encontró asiento original para crear contraasiento', [
                'id_discapacidad' => $idDiscapacidad
            ]);
            return null;
        }

        // Crear contraasiento del asiento original
        $this->findByContraAsientoContableId(
            $asientoOriginal->numero,
            $idDiscapacidad,
            'CONTRAASIENTO'
        );

        // Crear nuevo asiento con datos actualizados
        $nuevoAsiento = $this->crearAsientoDiscapacidad($datosDiscapacidad, $idPeriodoContable);

        return $nuevoAsiento;
    }

    /**
     * Anular asiento de prestación de discapacidad
     * (Deprecated: usar AsientosDiscapacidadHistorialRepository->procesarAnulacionDiscapacidad)
     */
    public function anularAsientoDiscapacidad($idDiscapacidad)
    {
        $asientoOriginal = $this->obtenerUltimoAsientoDiscapacidad($idDiscapacidad);

        if (!$asientoOriginal) {
            \Log::warning('No se encontró asiento para anular', [
                'id_discapacidad' => $idDiscapacidad
            ]);
            return false;
        }

        // Anular el asiento
        $resultado = $this->findByAnularAsientoContableId(
            $asientoOriginal->id_asiento_contable,
            'ANULADO'
        );

        \Log::info('Asiento contable anulado para discapacidad', [
            'id_discapacidad' => $idDiscapacidad,
            'id_asiento_contable' => $asientoOriginal->id_asiento_contable
        ]);

        return $resultado;
    }

    /**
     * Verificar si el prestador de discapacidad tiene cuenta contable asignada
     */
    public function verificarPrestadorDiscapacidadTieneCuentaContable($codPrestador)
    {
        if (!$codPrestador) {
            return false;
        }

        // Buscar por tipo de prestador
        $prestadorRepo = new \App\Http\Controllers\Prestadores\repository\PrestadorRepository();
        $prestador = $prestadorRepo->findByExistCodPrestador($codPrestador);

        if ($prestador) {
            return $this->verificarTipoPrestadorTieneCuentaContable($prestador->cod_tipo_prestador);
        }

        return false;
    }

}