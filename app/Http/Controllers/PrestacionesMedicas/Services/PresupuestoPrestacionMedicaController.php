<?php

namespace App\Http\Controllers\PrestacionesMedicas\Services;

use App\Models\PrestacionesMedicas\PresupuestosMedicosEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PresupuestoPrestacionMedicaController extends Controller
{

    public function procesar(Request $request)
    {
        try {
            DB::beginTransaction();
            $user = Auth::user();
            if (empty($request->id_presupuesto)) {
                PresupuestosMedicosEntity::create([
                    'cod_prestacion' => $request->cod_prestacion,
                    'id_presupuesto_proveedor' => $request->id_presupuesto_proveedor,
                    'origen_proveedor' => $request->origen_proveedor,
                    'fecha_presupuesto' => $request->fecha_presupuesto,
                    'monto_presupuestado' => $request->monto_presupuestado,
                    'observaciones' => $request->observaciones,
                    'id_usuario_crea' => $user->cod_usuario,
                    'fecha_carga' => Carbon::now(),
                    'estado' => $request->estado
                ]);
                DB::commit();
                return response()->json(['message' => 'Presupuesto cargado con éxito']);
            } else {
                $presupuesto = PresupuestosMedicosEntity::find($request->id_presupuesto);
                $presupuesto->cod_prestacion = $request->cod_prestacion;
                $presupuesto->id_presupuesto_proveedor = $request->id_presupuesto_proveedor;
                $presupuesto->origen_proveedor = $request->origen_proveedor;
                $presupuesto->fecha_presupuesto = $request->fecha_presupuesto;
                $presupuesto->monto_presupuestado = $request->monto_presupuestado;
                $presupuesto->observaciones = $request->observaciones;
                $presupuesto->update();
                DB::commit();
                return response()->json(['message' => 'Presupuesto modificado con éxito']);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

    public function listar(Request $request)
    {
        $data = [];
        $user = Auth::user();
        $sql = PresupuestosMedicosEntity::with(['prestacion', 'prestacion.afiliado', 'proveedor'])
            ->whereBetween('fecha_presupuesto', [$request->desde, $request->hasta]);

        if (!is_null($user->id_prestador)) {
            $sql->whereHas('prestacion', function ($q) use ($user) {
                $q->where('cod_prestador', $user->id_prestador);
            });
        }

        if (!is_null($request->num_tramite)) {
            $sql->whereHas('prestacion', function ($q) use ($request) {
                $q->where('numero_tramite', $request->num_tramite);
            });
        }

        if (!is_null($request->afiliado)) {
            $sql->whereHas('prestacion.afiliado', function ($q) use ($request) {
                $q->where('dni', 'LIKE', "%$request->afiliado%");
            });
        }

        if (!is_null($request->prestador)) {
            $sql->whereHas('proveedor', function ($q) use ($request) {
                $q->where('cuit', 'LIKE', "%$request->prestador%")
                    ->orWhere('razon_social', 'LIKE', "%$request->prestador%");
            });
        }

        $sql->orderByDesc('id_presupuesto');
        $data = $sql->get();
        return response()->json($data);
    }

    public function findById(Request $request)
    {
        return response()->json(PresupuestosMedicosEntity::with(['prestacion', 'prestacion.afiliado', 'prestacion.prestador', 'proveedor'])->find($request->id));
    }

    public function autoriza(Request $request)
    {
        $presupuesto = PresupuestosMedicosEntity::find($request->id);
        $user = Auth::user();
        if (!is_null($presupuesto)) {
            $presupuesto->estado = 'APROBADO';
            $presupuesto->id_usuario_autoriza = $user->cod_usuario;
            $presupuesto->fecha_autoriza = Carbon::now();
            $presupuesto->observacion_autoriza = $request->observacion;
            $presupuesto->update();
            return response()->json(['message' => 'Presupuesto AUTORIZADO con éxito']);
        } else {
            return response()->json(['message' => 'Registo no encontrado'], 500);
        }
    }

    public function anular(Request $request)
    {
        $presupuesto = PresupuestosMedicosEntity::find($request->id);
        $user = Auth::user();
        if (!is_null($presupuesto)) {
            $presupuesto->estado = 'RECHAZADO';
            $presupuesto->id_usuario_autoriza = $user->cod_usuario;
            $presupuesto->fecha_autoriza = Carbon::now();
            $presupuesto->motivo_rechazo = $request->observacion;
            $presupuesto->update();
            return response()->json(['message' => 'Presupuesto RECHAZADO con éxito']);
        } else {
            return response()->json(['message' => 'Registo no encontrado'], 500);
        }
    }

    public function findByPresupuestoPrestacion(Request $request)
    {
        return response()->json(PresupuestosMedicosEntity::with(['proveedor'])->where('cod_prestacion', $request->prestacion)->get());
    }
}
