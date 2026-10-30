<?php

namespace App\Http\Controllers\Tesoreria\Services\Ingresos;

use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngOrdenCobranzaRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Circuito de ingresos — orden de cobranza. La lógica y sus reglas viven en el repositorio.
 */
class TesIngOrdenCobranzaController extends Controller
{
    public function getClientes(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        return response()->json($repo->clientes($request->input('texto')));
    }

    public function getFacturasPendientes(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        $request->validate(['id_razon' => 'required|integer', 'id_cliente' => 'required|integer']);
        return response()->json($repo->facturasPendientes($request->input('id_razon'), $request->input('id_cliente')));
    }

    public function getAnticiposDisponibles(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        $request->validate(['id_razon' => 'required|integer', 'id_cliente' => 'required|integer']);
        return response()->json($repo->anticiposDisponibles(
            $request->input('id_razon'),
            $request->input('id_cliente'),
            $request->input('excluir_orden')
        ));
    }

    public function getListar(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        return response()->json($repo->listar($request->only(['id_razon', 'id_cliente', 'id_estado', 'desde', 'hasta'])));
    }

    public function getDetalle($id, TesIngOrdenCobranzaRepository $repo)
    {
        return response()->json($repo->detalle($id));
    }

    public function postGuardar(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        return $this->ejecutar(fn() => response()->json([
            'message' => 'Borrador guardado',
            'data' => $repo->guardarBorrador($request->all(), $request->input('id_orden_cobranza')),
        ]), 'guardar orden de cobranza');
    }

    public function postConfirmar($id, TesIngOrdenCobranzaRepository $repo)
    {
        return $this->ejecutar(function () use ($id, $repo) {
            $r = $repo->confirmar($id);
            return response()->json(['message' => "Orden {$r['numero_formateado']} confirmada", 'data' => $r]);
        }, 'confirmar orden de cobranza');
    }

    public function postAnular($id, Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        $request->validate(['motivo' => 'required|string|max:500']);
        return $this->ejecutar(fn() => response()->json([
            'message' => 'Orden anulada',
            'data' => $repo->anular($id, $request->input('motivo')),
        ]), 'anular orden de cobranza');
    }

    public function deleteBorrador($id, TesIngOrdenCobranzaRepository $repo)
    {
        return $this->ejecutar(function () use ($id, $repo) {
            $repo->eliminarBorrador($id);
            return response()->json(['message' => 'Borrador eliminado']);
        }, 'eliminar borrador de orden de cobranza');
    }

    private function ejecutar(callable $fn, string $contexto)
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            Log::error("Error {$contexto}: " . $e->getMessage());
            return response()->json(['message' => "Error al {$contexto}"], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
