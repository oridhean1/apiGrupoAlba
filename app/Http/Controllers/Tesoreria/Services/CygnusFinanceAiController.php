<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesConciliacionMatchingRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class CygnusFinanceAiController extends Controller
{
    /**
     * Motor de matching de conciliación bancaria (Cygnus Finance AI).
     * Corre sobre extractos puntuales (recién importados) o, sin ids, sobre todo lo pendiente.
     */
    public function ejecutarMotorMatching(Request $request, TesConciliacionMatchingRepository $matching)
    {
        try {
            DB::beginTransaction();

            $ids = $request->ids_extracto ?? null;
            $estadisticas = $matching->ejecutarMatching($ids);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Motor de Cygnus AI ejecutado correctamente.',
                'estadisticas' => $estadisticas
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el motor de IA: ' . $th->getMessage()
            ], 500);
        }
    }
}
