<?php

namespace App\Http\Controllers\PrestacionesMedicas\Services;

use App\Models\PrestacionesMedicas\ProveedorPresupuestosEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProveedorPresupuestosController extends Controller
{

    public function findByProcesar(Request $request)
    {
        try {
            DB::beginTransaction();
            $user = Auth::user();
            if (empty($request->id_presupuesto_proveedor)) {
                $proveedor = ProveedorPresupuestosEntity::create([
                    'cuit' => $request->cuit,
                    'razon_social' => $request->razon_social,
                    'email' => $request->email,
                    'telefono' => $request->telefono,
                    'observaciones' => $request->observaciones,
                    'id_usuario_crea' => $user->cod_usuario,
                    'fecha_alta' => Carbon::now(),
                    'activo' => 1
                ]);
                DB::commit();
                return response()->json(['message' => 'Proveedor creado con éxito', 'data' => $proveedor]);
            } else {
                $proveedor = ProveedorPresupuestosEntity::find($request->id_presupuesto_proveedor);

                if (!is_null($proveedor)) {
                    $proveedor->cuit = $request->cuit;
                    $proveedor->razon_social = $request->razon_social;
                    $proveedor->email = $request->email;
                    $proveedor->telefono = $request->telefono;
                    $proveedor->observaciones = $request->observaciones;
                    $proveedor->update();
                }
                DB::commit();
                return response()->json(['message' => 'Proveedor modificado con éxito', 'data' => $proveedor]);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

    public function findByConsultar(Request $request)
    {
        $sql = ProveedorPresupuestosEntity::with([]);
        if (!is_null($request->search)) {
            $sql->where('cuit', 'LIKE', ["%$request->search%"])
                ->orWhere('razon_social', 'LIKE', ["%$request->search%"]);
        }

        $sql->orderBy('razon_social');
        $data = $sql->get();

        return response()->json($data);
    }
}
