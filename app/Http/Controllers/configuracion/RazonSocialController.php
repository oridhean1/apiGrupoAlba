<?php

namespace App\Http\Controllers\configuracion;

use App\Http\Controllers\Controller;
use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as RoutingController;

class RazonSocialController extends RoutingController
{
    //
    public function getListaRazonSocial()
    {
        return RazonSocialModelo::where('activo', 1)->get();
    }

    public function saveRazonSocial(Request $request)
    {
        if ($request->id_razon) {
            $query = RazonSocialModelo::where('id_razon', $request->id_razon)->first();
            $query->razon_social = $request->razon_social;
            $query->activo = $request->activo;
            $query->save();
            return response()->json(['message' => 'Razón Social actualizado correctamente'], 200);
        } else {
            RazonSocialModelo::create([
                'razon_social' => $request->razon_social,
                'activo' => 1
            ]);
            return response()->json(['message' => 'Razón Social registrado correctamente'], 200);
        }
    }

    public function updateEstado(Request $request)
    {
        RazonSocialModelo::where('id_razon', $request->id)->update(['activo' => $request->activo,]);
        return response()->json(['message' => 'Estado cambiado correctamente'], 200);
    }

    public function getIdRazonSocial(Request $request)
    {
        return RazonSocialModelo::where('id_razon', $request->id)->first();
    }
}
