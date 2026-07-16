<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesCuentaCatalogoRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentasFiltrosRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TesCuentasFilterController extends Controller
{

    public function getFiltrar(Request  $request, TesCuentasFiltrosRepository $repo)
    {
        $data = [];

        if (!is_null($request->banco)) {
            $data = $repo->findByListBanco($request->banco);
        } else {
            $data = $repo->findByListAlls($request->id_razon);
        }

        return response()->json($data);
    }

    public function getListarEntidadesBancarias(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListEntidadesBancarias());
    }

    public function getListarTipoCuentas(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoCuentas());
    }

    public function getListarTipoMonedas(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoMoneda());
    }

    public function getListarMovimientos(Request $request, TesCuentasFiltrosRepository $repo)
    {
        $data = [];

        if (!is_null($request->cuenta)) {
            $data = $repo->findByListMovimientosIdCuenta($request->desde, $request->hasta, $request->cuenta);
        } else {
            $data = $repo->findByListMovimientos($request->desde, $request->hasta);
        }

        return response()->json($data);
    }

    public function getListarTipoTransaciones(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoTransaccion());
    }

    public function findById(Request $request, TesCuentasFiltrosRepository $repo)
    {
        return response()->json($repo->findById($request->id));
    }
}
