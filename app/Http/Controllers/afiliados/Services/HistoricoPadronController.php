<?php

namespace App\Http\Controllers\afiliados\Services;

use App\Exports\FotoPadronMensualExport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Histórico de Origen por afiliado y foto mensual del padrón (R-00000352, parte 2, solo ALBA).
 * El histórico lo llenan los triggers de tb_padron y la foto el command afiliados:foto-padron-mensual.
 * Donde las tablas no existen (OSV) los endpoints no devuelven datos.
 */
class HistoricoPadronController extends Controller
{
    public function getHistoricoOrigen($dni)
    {
        if (!Schema::hasTable('tb_afiliado_historico_origen')) {
            return response()->json([], 200);
        }

        $historico = DB::table('tb_afiliado_historico_origen as h')
            ->leftJoin('tb_comercial_caja as c', 'c.id_comercial_caja', '=', 'h.id_comercial_caja')
            ->leftJoin('tb_comercial_origen as o', 'o.id_comercial_origen', '=', 'h.id_comercial_origen')
            ->select([
                'h.*',
                'c.detalle_comercial_caja',
                'o.detalle_comercial_origen',
            ])
            ->where('h.dni', $dni)
            ->orderBy('h.vigencia_desde', 'desc')
            ->orderBy('h.id', 'desc')
            ->get();

        return response()->json($historico, 200);
    }

    public function getPeriodosFoto()
    {
        if (!Schema::hasTable('tb_padron_foto_mensual')) {
            return response()->json([], 200);
        }

        $periodos = DB::table('tb_padron_foto_mensual')
            ->select([
                'periodo',
                DB::raw('MAX(fecha_generacion) as fecha_generacion'),
                DB::raw('COUNT(*) as afiliados'),
                DB::raw('SUM(activo = 1) as activos'),
            ])
            ->groupBy('periodo')
            ->orderBy('periodo', 'desc')
            ->get();

        return response()->json($periodos, 200);
    }

    public function exportFotoPadron(Request $request)
    {
        // Mismos usuarios que la exportación del padrón de Afiliaciones (PadronController::exportPadron) + Administrador (2)
        $user = Auth::user();
        if (!in_array($user->cod_usuario, [2, 23, 25])) {
            return response()->json(['message' => 'No tiene permisos para descargar'], 403);
        }
        if (!Schema::hasTable('tb_padron_foto_mensual')) {
            return response()->json(['message' => 'La foto mensual del padrón no está habilitada'], 403);
        }
        if (empty($request->periodo) || !DB::table('tb_padron_foto_mensual')->where('periodo', $request->periodo)->exists()) {
            return response()->json(['message' => 'No existe foto del padrón para el período indicado'], 500);
        }

        $origenes = array_filter((array) $request->input('id_origen', []));
        return Excel::download(new FotoPadronMensualExport($request->periodo, $origenes), 'FotoPadron_' . $request->periodo . '.xlsx');
    }
}
