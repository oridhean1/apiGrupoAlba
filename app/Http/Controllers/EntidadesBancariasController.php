<?php

namespace App\Http\Controllers;

use App\Models\Tesoreria\TesEntidadesBancariasEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class EntidadesBancariasController extends Controller
{
    //
    public function getEntidadBancariaActivo()
    {
        $agentes =  TesEntidadesBancariasEntity::where('vigente', '1')->get();
        return response()->json($agentes, 200);
    }

    public function getEntidadBancaria()
    {
        $agentes =  TesEntidadesBancariasEntity::get();
        return response()->json($agentes, 200);
    }

    public function filterEntidadBancaria($id)
    {
        return TesEntidadesBancariasEntity::where('id_entidad_bancaria', $id)->first();
    }

    public function saveEntidadBancaria(Request $request)
    {
        // El listado de bancos de Tesorería se cachea (TesCuentaCatalogoRepository), hay que invalidarlo
        Cache::forget('catalog_tes_bancos');

        if ($request->id_entidad_bancaria != '') {
            $query = TesEntidadesBancariasEntity::where('id_entidad_bancaria', $request->id_entidad_bancaria)->first();
            $query->descripcion_banco = $request->descripcion_banco;
            $query->vigente = $request->vigente;
            $query->save();
            return response()->json(['message' => 'Datos de la entidad bancaria actualizado correctamente'], 200);
        } else {
            TesEntidadesBancariasEntity::create([
                'descripcion_banco' => $request->descripcion_banco,
                'vigente' => $request->vigente,
            ]);
            return response()->json(['message' => 'Datos de la entidad bancaria registrados correctamente'], 200);
        }
    }

    public function updateEstado(Request $request)
    {
        TesEntidadesBancariasEntity::where('id_entidad_bancaria', $request->id)->update(['vigente' => $request->activo,]);
        Cache::forget('catalog_tes_bancos');
        return response()->json(['message' => 'Estado cambiado correctamente'], 200);
    }
}
