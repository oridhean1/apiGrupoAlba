<?php

namespace App\Http\Controllers\Tesoreria\Services\Ingresos;

use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngComprobanteRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Circuito de ingresos — importación de comprobantes de venta de ARCA.
 *
 * Las dos rutas reciben el mismo archivo: `previsualizar` no graba nada; `confirmar` lo vuelve a
 * leer y valida de nuevo antes de grabar (no se confía en el resultado de la vista previa).
 * `asociaciones`: JSON {fila: id_comprobante | "fila:N"} con la factura de cada NC/ND.
 */
class TesIngComprobantesController extends Controller
{
    public function postPrevisualizar(Request $request, TesIngComprobanteRepository $repo)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt',
            'id_razon' => 'required|integer',
        ]);

        try {
            return response()->json($repo->previsualizar(
                $request->file('archivo')->getRealPath(),
                $request->input('id_razon'),
                $this->asociaciones($request)
            ));
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function postConfirmar(Request $request, TesIngComprobanteRepository $repo)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt',
            'id_razon' => 'required|integer',
        ]);

        try {
            $r = $repo->confirmar(
                $request->file('archivo')->getRealPath(),
                $request->file('archivo')->getClientOriginalName(),
                $request->input('id_razon'),
                $this->asociaciones($request)
            );

            return response()->json([
                'message' => "Se importaron {$r['importados']} comprobantes" . ($r['observados'] ? " ({$r['observados']} observados no se grabaron)" : ''),
                'data' => $r,
            ], 201);
        } catch (QueryException $e) {
            Log::error('Error importar comprobantes de venta: ' . $e->getMessage());
            return response()->json(['message' => 'Error al importar los comprobantes'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function asociaciones(Request $request): array
    {
        $a = $request->input('asociaciones', []);
        return is_string($a) ? (json_decode($a, true) ?: []) : (array) $a;
    }
}
