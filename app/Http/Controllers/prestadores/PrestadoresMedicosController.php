<?php

namespace App\Http\Controllers\prestadores;

use App\Models\prestadores\PrestadorMedicosEntity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrestadoresMedicosController extends Controller
{

    public function postCrearProfesional(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->cod_profesional > 0) {
                $profesional = PrestadorMedicosEntity::find($request->cod_profesional);
                $profesional->update($request->all());
                DB::commit();
                return response()->json(["message" => "Profesional actualizado correctamente."], 200);
            } else {
                $user = Auth::user();
                $request->merge(['cod_usuario_registra' => $user->cod_usuario]);
                $existsProfesional = PrestadorMedicosEntity::where('dni', $request->dni)->exists();

                if (!$existsProfesional) {
                    PrestadorMedicosEntity::create($request->all());

                    DB::commit();
                    return response()->json(["message" => "Profesional registrado correctamente."], 200);
                } else {
                    DB::rollBack();
                    return response()->json([
                        'message' => "El N° de DNI <b>" . $request->dni . "</b>, ya se encuentra registrado en nuestro sistema."
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

    public function getConsultarProfesionales(Request $request)
    {
        $dtListaData = [];

        // Modo paginado (front ALBA): filtra por fecha_alta y matrícula/apellidos y nombres.
        // Sin "page" se mantiene la respuesta legacy (array completo) que consume frontOsv.
        if ($request->filled('page')) {
            $query = PrestadorMedicosEntity::with(["tipoMatricula", "especialidad", "prestador"]);

            if (!empty($request->desde)) {
                $query->whereDate('fecha_alta', '>=', $request->desde);
            }
            if (!empty($request->hasta)) {
                $query->whereDate('fecha_alta', '<=', $request->hasta);
            }
            if (!empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('numero_matricula', 'LIKE', $request->search . '%')
                      ->orWhere('apellidos_nombres', 'LIKE', '%' . $request->search . '%');
                });
            }

            $perPage = (int) ($request->per_page ?: 10);
            return response()->json(
                $query->orderByDesc('cod_profesional')
                    ->paginate($perPage, ['*'], 'page', (int) $request->page),
                200
            );
        }

        $dtListaData = PrestadorMedicosEntity::with(["tipoMatricula", "especialidad", "prestador"])
            ->orderByDesc('cod_profesional')
            ->get();

        return response()->json($dtListaData, 200);
    }

    public function getBuscarProfesionalId($id)
    {
        $dtData = null;

        $dtData = PrestadorMedicosEntity::with(["tipoMatricula", "especialidad", "prestador"])
            ->find($id);

        return response()->json($dtData, 200);
    }

    public function getBuscarProfesionalesSegunPrestador(Request $request)
    {
        $dtData = [];

        if (!is_null($request->id)) {
            $dtData = PrestadorMedicosEntity::where('cod_prestador', $request->id)
                ->where('vigente', '1')
                ->get();
        } else {
            $dtData = PrestadorMedicosEntity::where('vigente', '1')
                ->orderBy('apellidos_nombres')
                ->limit(50)
                ->get();
        }

        return response()->json($dtData, 200);
    }

    public function getListarTodoPrestadorProfesional()
    {
        $dtData = [];

        $dtData = PrestadorMedicosEntity::where('vigente', '1')
            ->orderBy('apellidos_nombres')
            ->get();


        return response()->json($dtData, 200);
    }
}
