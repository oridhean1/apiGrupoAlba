<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\facturacion\repository\FacturaRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository;
use App\Http\Controllers\Tesoreria\Repository\TesOperacionesManuelesRepository;
use App\Http\Controllers\Utils\ManejadorDeArchivosUtils;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TesOperacionesManualesController extends Controller
{

    public function getNuevaOperacion(
        Request $request,
        TesOperacionesManuelesRepository $operacion,
        TesCuentasBancariasRepository $cuenta,
        ManejadorDeArchivosUtils $storage,
        FacturaRepository $repoFactus
    ) {
        try {
            DB::beginTransaction();
            $params = json_decode($request->data);

            //@ VARIFICAR EL ESTADO DE LA CUENTA ORIGEN
            if (!$cuenta->findByVerificarEstadoCuenta($params->id_cuenta_bancaria, '1')) {
                DB::rollBack();
                return response()->json(['message' => 'No hemos podido procesar tu operación porque la cuenta bancaria de origen se encuentra temporalmente <b>Bloqueada</b>. Por favor, revisa la cuenta e inténtalo otra vez.'], 409);
            }

            // @ [RETIRO] VERIFICAMOS SI TENEMOS UN FONDOS EN LA CUENTA DE PAGO
            if (
                !$cuenta->findByVerificarSaldoCuenta($params->id_cuenta_bancaria, $params->monto_operacion)
                && ($params->id_tipo_transaccion == '2' || $params->id_tipo_transaccion == '3')
            ) {
                DB::rollBack();
                return response()->json(['message' => 'No hemos podido procesar tu operación porque la cuenta bancaria seleccionada no tiene fondos suficientes. Por favor, revisa tu saldo e inténtalo otra vez.'], 409);
            }

            // @SUBIR ARCHIVO
            $archivo = $storage->findBycargarArchivo("COMP_PAG_" . $params->id_tipo_transaccion . $params->id_cuenta_bancaria, 'tesoreria/operaciones_manuales', $request);

            //@REGISTRAR LA OPERACION MANUAL
            $opDb = $operacion->findByNuevaOperacion($params, $archivo);

            //@CONFIRMAMOS LA FACTURA
            if (is_numeric($params->id_factura)) {
                $repoFactus->findByUpdateEstado($params->id_factura, 1);
            }

            // @AFECTAMOS LA CUENTA BANCARIA
            // @TIPO [1] = INGRESO
            if ($params->id_tipo_transaccion == '1') {
                $cuenta->findByDepositoCuenta($params->id_cuenta_bancaria, $params->monto_operacion);
                $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria, $params->monto_operacion, 'INGRESO', null, $opDb->id_operacion, 'OPER. MANUAL');
            } else if ($params->id_tipo_transaccion == '2') {
                // @TIPO [2] = RETIRO
                $cuenta->findByRetiroCuenta($params->id_cuenta_bancaria, $params->monto_operacion);
                $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria, $params->monto_operacion, 'EGRESO', null, $opDb->id_operacion, 'OPER. MANUAL');
            } else if ($params->id_tipo_transaccion == '3') {
                // @TIPO [2] = TRANSFERENCIA

                //@VERIFICAR EL ESTADO DE CUENTA DESTINO
                if (!$cuenta->findByVerificarEstadoCuenta($params->id_cuenta_bancaria_destino, '1')) {
                    DB::rollBack();
                    return response()->json(['message' => 'No hemos podido procesar tu operación porque la cuenta bancaria de destino se encuentra temporalmente <b>Bloqueada</b>. Por favor, revisa la cuenta e inténtalo otra vez.'], 409);
                }
                //VARIFICAMOS QUE SEAN DISTINTAS CUENTAS
                if ($params->id_cuenta_bancaria == $params->id_cuenta_bancaria_destino) {
                    DB::rollBack();
                    return response()->json(['message' => 'La cuenta de <b>DESTINO</b> no puede ser igual a la cuenta de <b>ORIGEN</b>, se solicita su correción.'], 409);
                }
                //REGISTRAR OPERACION
                $cuenta->findByDepositoCuenta($params->id_cuenta_bancaria_destino, $params->monto_operacion);
                //@REALIZAMOS EL RETIRO CUENTA ORIGEN Y REGISTRAMOS EL MOVIMIENTO EGRESO
                $cuenta->findByRetiroCuenta($params->id_cuenta_bancaria, $params->monto_operacion);
                $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria, $params->monto_operacion, 'EGRESO', null, $opDb->id_operacion, 'OPER. MANUAL - TRANSFERENCIA');
                //@MOVIMIENTO INGRESO
                $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria_destino, $params->monto_operacion, 'INGRESO', null, $opDb->id_operacion, 'OPER. MANUAL - TRANSFERENCIA');
            }

            DB::commit();
            return response()->json(['message' => 'Operación realizada con éxito.']);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getEnalazarFacturaObraSocial(
        Request $request,
        TesOperacionesManuelesRepository $operacion,
        ManejadorDeArchivosUtils $storage,
        FacturaRepository $repoFactus
    ) {
        try {
            DB::beginTransaction();
            $params = json_decode($request->data);

            // @SUBIR ARCHIVO
            $archivo = $storage->findBycargarArchivo("COMP_PAG_FAT_" . $params->id_factura . $params->id_operacion, 'tesoreria/operaciones_manuales', $request);

            //@MODIFICAR LA OPERACION MANUAL
            $operacion->findByEnlazarOperacionFacturaObraSocial($params, $archivo);

            //@CONFIRMAMOS LA FACTURA
            if (is_numeric($params->id_factura)) {
                $repoFactus->findByUpdateEstado($params->id_factura, 1);
            }


            DB::commit();
            return response()->json(['message' => 'Operación realizada con éxito.']);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getListarTransaciones(
        Request $request,
        TesOperacionesManuelesRepository $operacion
    ) {
        $data = [];

        if (!is_null($request->cuenta)) {
            $data = $operacion->findByListarBetweenCuenta($request->desde, $request->hasta, $request->cuenta);
        } else {
            $data = $operacion->findByListarBetween($request->desde, $request->hasta);
        }

        return response()->json($this->soloDeLaRazon($data, $request));
    }

    public function getVerAdjunto(TesOperacionesManuelesRepository $pago, ManejadorDeArchivosUtils $storageFile, Request $request)
    {
        $path = "tesoreria/operaciones_manuales/";
        $data = $pago->findById($request->id);
        $anioTrabaja = Carbon::parse($data->fecha_registra)->year;
        $path .= "{$anioTrabaja}/$data->comprobante";

        return $storageFile->findByObtenerArchivo($path);
    }

    /**
     * Pantalla Cuentas con razón social elegida arriba (2026-11-18): sólo lo de las cuentas de esa
     * razón, así no se mezclan las entidades del grupo. Sin `id_razon`, como antes.
     */
    private function soloDeLaRazon($data, Request $request)
    {
        if (!$request->filled('id_razon')) {
            return $data;
        }
        $ids = \App\Models\Tesoreria\TesCuentasBancariasEntity::where('id_razon', $request->id_razon)
            ->pluck('id_cuenta_bancaria')->map(fn($i) => (int) $i)->all();
        return collect($data)->filter(fn($r) => in_array((int) $r->id_cuenta_bancaria, $ids, true))->values();
    }
}
