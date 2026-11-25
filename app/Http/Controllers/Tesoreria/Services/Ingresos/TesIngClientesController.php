<?php

namespace App\Http\Controllers\Tesoreria\Services\Ingresos;

use App\Exports\CuentaCorrienteClienteExport;
use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngClienteRepository;
use Maatwebsite\Excel\Facades\Excel;
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
        $request->validate(['id_razon' => 'required|integer', 'id_cliente' => 'required|integer', 'desde' => 'nullable|date', 'hasta' => 'nullable|date']);
        return response()->json($repo->cuentaCorriente($request->input('id_razon'), $request->input('id_cliente'), $request->input('hasta'), $request->input('desde')));
    }

    /** GET ingresos/cuenta-corriente/excel?id_razon=&id_cliente=&desde=&hasta= — R-00000684 */
    public function exportCuentaCorriente(Request $request, TesIngClienteRepository $repo)
    {
        $request->validate(['id_razon' => 'required|integer', 'id_cliente' => 'required|integer', 'desde' => 'nullable|date', 'hasta' => 'nullable|date']);

        try {
            return Excel::download(
                new CuentaCorrienteClienteExport($repo, $request->input('id_razon'), $request->input('id_cliente'), $request->input('desde'), $request->input('hasta')),
                'cuenta-corriente-cliente-' . $request->input('id_cliente') . '.xlsx'
            );
        } catch (\Throwable $e) {
            Log::error('Error exportar cuenta corriente de cliente: ' . $e->getMessage());
            return response()->json(['message' => 'Error al exportar la cuenta corriente'], 500);
        }
    }
}
