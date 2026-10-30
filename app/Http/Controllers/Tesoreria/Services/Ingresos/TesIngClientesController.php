<?php

namespace App\Http\Controllers\Tesoreria\Services\Ingresos;

use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngClienteRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Circuito de ingresos — clientes (alta / edición) y su cuenta corriente.
 */
class TesIngClientesController extends Controller
{
    public function getListar(Request $request, TesIngClienteRepository $repo)
    {
        return response()->json([
            'clientes' => $repo->listar($request->input('texto'), $request->boolean('incluir_inactivos')),
            'condiciones_iva' => TesIngClienteRepository::CONDICIONES_IVA,
        ]);
    }

    public function getObrasSociales(Request $request, TesIngClienteRepository $repo)
    {
        return response()->json($repo->obrasSociales($request->input('texto')));
    }

    public function postGuardar(Request $request, TesIngClienteRepository $repo)
    {
        try {
            $c = $repo->guardar($request->all(), $request->input('id_cliente'));
            return response()->json(['message' => 'Cliente guardado', 'data' => $c]);
        } catch (QueryException $e) {
            Log::error('Error guardar cliente de ingresos: ' . $e->getMessage());
            return response()->json(['message' => 'Error al guardar el cliente'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function getCuentaCorriente(Request $request, TesIngClienteRepository $repo)
    {
        $request->validate(['id_razon' => 'required|integer', 'id_cliente' => 'required|integer', 'hasta' => 'nullable|date']);
        return response()->json($repo->cuentaCorriente($request->input('id_razon'), $request->input('id_cliente'), $request->input('hasta')));
    }
}
