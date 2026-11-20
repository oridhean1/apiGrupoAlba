<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesCuentasBancariasRepository;
use App\Http\Controllers\Tesoreria\Repository\TestChequesRepository;
use App\Http\Controllers\Utils\ManejadorDeArchivosUtils;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TesChequesController extends Controller
{

    public function getProcesar(
        Request $request,
        TestChequesRepository $repoCheque,
        ManejadorDeArchivosUtils $storage,
        TesCuentasBancariasRepository $cuenta
    ) {
        try {
            DB::beginTransaction();
            $params = json_decode($request->data);
            $menssage = "";
            $archivo = $storage->findBycargarArchivo("COMP_CHEQUE_" . $params->id_cuenta_bancaria . $params->tipo_cheque, 'tesoreria/comprobantes_cheques', $request);

            //@VALIDAR SI ES UN REMPLAZO
            if ($params->estado == 'REEMPLAZADO') {
                if (!$repoCheque->findByExistsNumCheque($params->numero_cheque_anterior)){
                    DB::rollBack();
                    return response()->json(['message' => "El número de cheque anterior <b>$params->numero_cheque_anterior</b> no éxiste. Se solicita ingresar un número correcto."], 409);
                }
            }

            if (is_numeric($params->id_cheque)) {
                $repoCheque->findByUpdate($params, $archivo, $params->id_cheque);
                $menssage = "El cheque de N° <b>$params->numero_cheque</b> fue actualizado con éxito";
            } else {
                $chequeTransito =   $repoCheque->findByCrear($params, $archivo);

                //@SI ES UN REMPLAZO DESACTIVAMOS EL CHEQUE ANTERIOR POR NUMERO DE CHEQUE
                if ($params->estado == 'REEMPLAZADO') {
                    $repoCheque->findByDesactivarCheque($params->numero_cheque_anterior);
                    //@AL NUEVO CHEQUE SE ACTUALIZA COMO ACTIVO
                    $repoCheque->findByDesactivarCheque($params->numero_cheque, "ACTIVO");
                }

                //@AFECTAMOS LA CUENTA DE ORIGEN Y SE REGISTRA EL MOVIMIENTO
                if ($params->estado == 'ACTIVO') {
                    if ($params->tipo == 'EMISION') {
                        //@REALIZAR UN RETIRO DE MI CUENTA | EMISION
                        $cuenta->findByRetiroCuenta($params->id_cuenta_bancaria, $params->monto);
                        $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria, $params->monto, 'EGRESO', null, $chequeTransito->id_cheque, 'CHEQUE - EMISION');
                    } else {
                        //@REALIZAR UN DEPOSITO A MI CUNETA | RECEPCION
                        $cuenta->findByDepositoCuenta($params->id_cuenta_bancaria, $params->monto);
                        $cuenta->findByRegistrarMovimiento($params->id_cuenta_bancaria, $params->monto, 'INGRESO', null, $chequeTransito->id_cheque, 'CHEQUE - RECEPCION');
                    }
                }


                $menssage = "El cheque de N° <b>$params->numero_cheque</b> fue registrado con éxito";
            }

            DB::commit();
            return response()->json(['message' => $menssage]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }


    public function getListarCheques(Request $request, TestChequesRepository $repoCheque)
    {
        $data = [];

        $data = $repoCheque->findByList($request->desde, $request->hasta);

        return response()->json($this->soloDeLaRazon($data, $request), 200);
    }

    public function getVerAdjunto(TestChequesRepository $pago, ManejadorDeArchivosUtils $storageFile, Request $request)
    {
        $path = "tesoreria/comprobantes_cheques/";
        $data = $pago->findById($request->id);
        $anioTrabaja = Carbon::parse($data->fecha_registra)->year;
        $path .= "{$anioTrabaja}/$data->archivo_adjunto";

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
