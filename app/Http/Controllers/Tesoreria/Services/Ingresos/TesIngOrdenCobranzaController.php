<?php

namespace App\Http\Controllers\Tesoreria\Services\Ingresos;

use App\Http\Controllers\Tesoreria\Repository\Ingresos\TesIngOrdenCobranzaRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    /** Recibo de cobro en PDF. Sólo de órdenes confirmadas; una anulada sale con la marca ANULADO. */
    public function getRecibo($id, TesIngOrdenCobranzaRepository $repo)
    {
        $d = $repo->detalle($id);
        $o = $d['orden'];

        if ($o->id_estado == TesIngOrdenCobranzaRepository::BORRADOR) {
            return response()->json(['message' => 'Un borrador no tiene recibo: confirmá la orden primero.'], 409);
        }

        try {
            // Totales desde las filas, sin filtrar `anulado`: al anular se marcan todas juntas, y el
            // recibo de una orden anulada tiene que mostrar lo que fue, no "todo a cuenta".
            $total = round($d['medios']->sum('importe') + $d['retenciones']->sum('importe') + $d['anticipos']->sum('importe'), 2);
            $aplicado = round($d['imputaciones']->sum('importe'), 2);
            $totales = ['total_imputable' => $total, 'excedente' => round(max(0, $total - $aplicado), 2)];

            $razon = DB::table('tb_razones_sociales')->where('id_razon', $o->id_razon)->first();
            $pdf = Pdf::loadView('tesoreria.recibo_cobranza', [
                'numero' => $d['numero_formateado'],
                'fecha_cobro' => Carbon::parse($o->fecha_cobro)->format('d/m/Y'),
                'razon' => $razon,
                'cliente' => $d['cliente'],
                'medios' => $d['medios'],
                'retenciones' => $d['retenciones'],
                'anticipos' => $d['anticipos'],
                'imputaciones' => $d['imputaciones'],
                'totales' => $totales,
                'observaciones' => $o->observaciones,
                'anulada' => $o->id_estado == TesIngOrdenCobranzaRepository::ANULADA,
                'fecha_anula' => $o->fecha_anula ? Carbon::parse($o->fecha_anula)->format('d/m/Y') : null,
                'motivo_anulacion' => $o->motivo_anulacion,
                'emitido' => Carbon::now('America/Argentina/Buenos_Aires')->format('d/m/Y H:i'),
            ]);
            $pdf->setPaper('A4');

            return $pdf->stream("recibo-{$d['numero_formateado']}.pdf");
        } catch (\Exception $e) {
            Log::error('Error generar recibo de cobranza: ' . $e->getMessage());
            return response()->json(['message' => 'Error al generar el recibo'], 500);
        }
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

    /** Cheques / eCheq recibidos. estado: pendientes (default) | depositados | todos. */
    public function getValores(Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        return response()->json($repo->valores($request->only(['id_razon', 'estado'])));
    }

    public function postDepositarValor($id, Request $request, TesIngOrdenCobranzaRepository $repo)
    {
        $request->validate(['id_cuenta_bancaria' => 'required|integer', 'fecha_deposito' => 'required|date']);
        return $this->ejecutar(fn() => response()->json([
            'message' => 'Valor depositado',
            'data' => $repo->depositarValor($id, $request->input('id_cuenta_bancaria'), $request->input('fecha_deposito')),
        ]), 'depositar el valor');
    }

    public function postAnularDepositoValor($id, TesIngOrdenCobranzaRepository $repo)
    {
        return $this->ejecutar(fn() => response()->json([
            'message' => 'Depósito anulado: el valor volvió a cartera',
            'data' => $repo->anularDepositoValor($id),
        ]), 'anular el depósito');
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
