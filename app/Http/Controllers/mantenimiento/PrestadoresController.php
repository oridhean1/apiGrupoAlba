<?php

namespace App\Http\Controllers\mantenimiento;

use App\Exports\Prestadores\PrestadoresListadoExport;
use App\Http\Controllers\Prestadores\repository\ImputacionesPrestadoreRepository;
use App\Http\Controllers\prestadores\repository\PrestadorRepository;
use App\Models\prestadores\DatosBancariosPrestadorEntity;
use App\Models\prestadores\PrestadorEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class PrestadoresController extends Controller
{
    public function exportarExcelPrestadores(Request $request)
    {
        try {
            $query = PrestadorEntity::with(["tipoPrestador", "tipoImpuesto", "tipoIva", "localidad", "datosBancarios", "usuario"]);

            if (!empty($request->search) && empty($request->prestador)) {
                $query->where(function ($q) use ($request) {
                    $q->where("cuit", 'LIKE', $request->search . '%')
                      ->orWhere('razon_social', 'LIKE', '%' . $request->search . '%')
                      ->orWhere('nombre_fantasia', 'LIKE', '%' . $request->search . '%');
                });
            } else if (empty($request->search) && !empty($request->prestador)) {
                $query->where("cod_tipo_prestador", $request->prestador);
            } else if (!empty($request->search) && !empty($request->prestador)) {
                $query->where("cod_tipo_prestador", $request->prestador)
                      ->where(function ($q) use ($request) {
                          $q->where("cuit", 'LIKE', $request->search . '%')
                            ->orWhere('razon_social', 'LIKE', '%' . $request->search . '%')
                            ->orWhere('nombre_fantasia', 'LIKE', '%' . $request->search . '%');
                      });
            }

            $dataLista = $query->orderByDesc("cod_prestador")->get();

            $filename = 'listado_prestadores_' . date('Y-m-d_H-i-s') . '.xlsx';

            return Excel::download(
                new PrestadoresListadoExport($dataLista),
                $filename
            );
        } catch (\Throwable $th) {
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }
    public function getConsultarPrestadores(Request $request)
    {
        try {
            $dataLista = [];

            if (!is_null($request->search) && is_null($request->prestador)) {
                $dataLista = PrestadorEntity::with(["tipoPrestador", "tipoImpuesto", "tipoIva", "localidad", "datosBancarios", "usuario"])
                    ->where("cuit", 'LIKE', $request->search . '%')
                    ->orWhere('razon_social', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('nombre_fantasia', 'LIKE', '%' . $request->search . '%')
                    ->orderByDesc("cod_prestador")
                    ->get();
            } else if (is_null($request->search) && !is_null($request->prestador)) {
                $dataLista = PrestadorEntity::with(["tipoPrestador", "tipoImpuesto", "tipoIva", "localidad", "datosBancarios", "usuario"])
                    ->where("cod_tipo_prestador", $request->prestador)
                    ->orderByDesc("cod_prestador")
                    ->get();
            } else {
                $dataLista = PrestadorEntity::with(["tipoPrestador", "tipoImpuesto", "tipoIva", "localidad", "datosBancarios", "usuario"])
                    ->orderByDesc("cod_prestador")
                    ->get();
            }

            return response()->json($dataLista);
        } catch (\Throwable $th) {
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }
    public function postRegistrarPrestador(PrestadorRepository $repo, Request $request, ImputacionesPrestadoreRepository $repImputacion)
    {
        DB::beginTransaction();
        try {

            if (!is_null($request->cod_prestador)) {
                $prestador = $repo->findByUpdatePrestador($request);

                if (is_null($request->datos_bancarios["cod_banco_empresa"])) {
                    $repo->findByCrearDatosBancarios($request->datos_bancarios, $prestador->cod_prestador, $prestador->cuit);
                } else {
                    $repo->findByUpdateDatosBancarios($request->datos_bancarios, $prestador->cod_prestador, $prestador->cuit);
                }

                if (is_null($request->metodo_pago["id_pago_proveedor"])) {
                    $repo->findByCrearMetodoPago($request->metodo_pago, $prestador->cod_prestador);
                } else {
                    $repo->findByUpdateMetodoPago($request->metodo_pago, $prestador->cod_prestador);
                }

                foreach ($request->detalle as $key) {
                    if (!is_null($key['id_imputacion_prestador'])) {
                        $repImputacion->findByUpdateImputaciones($key, $prestador->cod_prestador);
                    } else {
                        $repImputacion->findByAgregarImputaciones($key, $prestador->cod_prestador);
                    }
                }

                DB::commit();
                return response()->json(["message" => "Prestador actualizado correctamente."], 200);
            } else {

                if (!$repo->findByExistCuit($request->cuit)) {
                    $prestador = $repo->findByCrearPrestador($request);
                    $repo->findByCrearDatosBancarios($request->datos_bancarios, $prestador->cod_prestador, $prestador->cuit);
                    $repo->findByCrearMetodoPago($request->metodo_pago, $prestador->cod_prestador);

                    foreach ($request->detalle as $key) {
                        $repImputacion->findByAgregarImputaciones($key, $prestador->cod_prestador);
                    }

                    DB::commit();
                    return response()->json(["message" => "Prestador registrado correctamente."], 200);
                } else {
                    DB::rollBack();
                    return response()->json([
                        'message' => "El N° de CUIT <b>" . $request->cuit . "</b>, ya se encuentra registrado en nuestro sistema."
                    ], 409);
                }
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getBuscarPrestadorId($id)
    {
        return response()->json(PrestadorEntity::with(["tipoPrestador", "tipoImpuesto", "tipoIva", "localidad", "datosBancarios"])->find($id), 200);
    }

    public function getEliminarPrestador(Request $request)
    {
        DB::delete("DELETE FROM tb_datos_bancarios_prestador WHERE cod_prestador = ? ", [$request->id]);

        $prestador = PrestadorEntity::find($request->id);
        $prestador->delete();

        return response()->json(["message" => "Prestador eliminado correctamente"], 200);
    }

    public function getFiltrarPrestador(Request $request)
    {
        $dtListaData = [];

        if (!empty($request->search)) {
            if (is_numeric($request->search)) {
                $dtListaData = PrestadorEntity::where('cuit', 'LIKE', $request->search . '%')
                    ->orderByDesc('razon_social')
                    ->get();
            } else {
                $dtListaData = PrestadorEntity::where(function ($query) use ($request) {
                    $query->where('razon_social', 'LIKE', ['%' . $request->search . '%'])
                        ->orWhere('nombre_fantasia', 'LIKE', ['%' . $request->search . '%']);
                })

                    ->orderByDesc('razon_social')
                    ->get();
            }
        } else {
            $dtListaData = PrestadorEntity::orderByDesc('razon_social')
                ->limit(10)
                ->get();
        }


        return response()->json($dtListaData, 200);
    }
}
