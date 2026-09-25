<?php

namespace App\Http\Controllers\afiliados\Services;

use App\Models\afiliado\AfiliadoMovimientoProgramadoEntity;
use App\Models\ComercialOrigenModel;
use App\Models\PadronComercialModelo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Traspasos programados de afiliados entre Orígenes (R-00000352).
 * Cada traspaso genera, por afiliado, un movimiento de BAJA en el Origen actual y uno de ALTA
 * en el nuevo Origen (al día siguiente), unidos por id_vinculo. Los aplica el command
 * afiliados:aplicar-movimientos-programados.
 */
class MovimientoProgramadoController extends Controller
{
    public function getListarMovimientos(Request $request)
    {
        if (!AfiliadoMovimientoProgramadoEntity::habilitado()) {
            return response()->json([], 200);
        }

        $query = AfiliadoMovimientoProgramadoEntity::from('tb_afiliado_movimiento_programado as m')
            ->leftJoin('tb_padron_comercial as p', 'p.dni', '=', 'm.dni')
            ->leftJoin('tb_comercial_origen as o', 'o.id_comercial_origen', '=', 'm.id_comercial_origen')
            ->leftJoin('tb_comercial_caja as c', 'c.id_comercial_caja', '=', 'm.id_comercial_caja')
            ->leftJoin('tb_usuarios as u', 'u.cod_usuario', '=', 'm.cod_usuario')
            ->select([
                'm.*',
                'p.nombre',
                'p.apellidos',
                'p.id_parentesco',
                'o.detalle_comercial_origen',
                'c.detalle_comercial_caja',
                'u.nombre_apellidos as usuario'
            ]);

        if (!empty($request->estado)) {
            $query->where('m.estado', $request->estado);
        }
        if (!empty($request->dni)) {
            $query->where('m.dni', $request->dni);
        }

        $movimientos = $query->orderBy('m.fecha_carga', 'desc')
            ->orderBy('m.id_vinculo')
            ->orderBy('m.dni')
            ->orderBy('m.fecha_vigencia')
            ->get();

        return response()->json($movimientos, 200);
    }

    public function postSaveTraspaso(Request $request)
    {
        if (!AfiliadoMovimientoProgramadoEntity::habilitado()) {
            return response()->json(['message' => 'Los traspasos programados no están habilitados'], 403);
        }

        $hoy = Carbon::now('America/Argentina/Buenos_Aires')->startOfDay();

        if (empty($request->dni) || empty($request->fecha_baja) || empty($request->id_comercial_caja) || empty($request->id_comercial_origen)) {
            return response()->json(['message' => 'Debe indicar afiliado, fecha de baja, caja y origen nuevo'], 500);
        }

        $fechaBaja = Carbon::parse($request->fecha_baja, 'America/Argentina/Buenos_Aires')->startOfDay();
        if ($fechaBaja->lt($hoy)) {
            return response()->json(['message' => 'La fecha de baja no puede ser anterior a hoy'], 500);
        }
        // Definido con negocio: el alta en el nuevo Origen es siempre el día siguiente a la baja
        $fechaAlta = $fechaBaja->copy()->addDay();

        $origenNuevo = ComercialOrigenModel::where('id_comercial_origen', $request->id_comercial_origen)->first();
        if (!$origenNuevo || $origenNuevo->id_comercial_caja != $request->id_comercial_caja) {
            return response()->json(['message' => 'El origen seleccionado no corresponde a la caja indicada'], 500);
        }

        $afiliado = PadronComercialModelo::where('dni', $request->dni)->first();
        if (!$afiliado) {
            return response()->json(['message' => 'No se encontró el afiliado'], 500);
        }
        if ($afiliado->activo != 1) {
            return response()->json(['message' => 'El afiliado no está activo'], 500);
        }
        if ($afiliado->id_comercial_origen == $request->id_comercial_origen) {
            return response()->json(['message' => 'El afiliado ya pertenece al origen seleccionado'], 500);
        }

        // Mismo criterio que la baja: el titular arrastra a su grupo familiar activo, el familiar se traspasa solo
        $traspasoGrupo = $afiliado->id_parentesco == '00';
        if ($traspasoGrupo) {
            $afiliados = PadronComercialModelo::where('cuil_tit', $afiliado->cuil_tit)
                ->where('activo', 1)
                ->where('id_comercial_origen', '!=', $request->id_comercial_origen)
                ->get();
        } else {
            $afiliados = collect([$afiliado]);
        }

        $pendientes = AfiliadoMovimientoProgramadoEntity::whereIn('dni', $afiliados->pluck('dni'))
            ->where('estado', 'PENDIENTE')
            ->pluck('dni')
            ->unique();
        if ($pendientes->count() > 0) {
            return response()->json(['message' => 'Ya existe un movimiento pendiente para el/los DNI: ' . $pendientes->implode(', ')], 500);
        }

        $user = Auth::user();
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $vinculo = (string) Str::uuid();

        DB::beginTransaction();
        try {
            foreach ($afiliados as $item) {
                AfiliadoMovimientoProgramadoEntity::create([
                    'id_vinculo' => $vinculo,
                    'dni' => $item->dni,
                    'cuil_tit' => $item->cuil_tit,
                    'tipo_movimiento' => 'BAJA',
                    'id_comercial_caja' => $item->id_comercial_caja,
                    'id_comercial_origen' => $item->id_comercial_origen,
                    'fecha_vigencia' => $fechaBaja->format('Y-m-d'),
                    'traspaso_grupo' => $traspasoGrupo ? 1 : 0,
                    'estado' => 'PENDIENTE',
                    'cod_usuario' => $user->cod_usuario,
                    'fecha_carga' => $now->format('Y-m-d H:i:s'),
                ]);
                AfiliadoMovimientoProgramadoEntity::create([
                    'id_vinculo' => $vinculo,
                    'dni' => $item->dni,
                    'cuil_tit' => $item->cuil_tit,
                    'tipo_movimiento' => 'ALTA',
                    'id_comercial_caja' => $request->id_comercial_caja,
                    'id_comercial_origen' => $request->id_comercial_origen,
                    'fecha_vigencia' => $fechaAlta->format('Y-m-d'),
                    'traspaso_grupo' => $traspasoGrupo ? 1 : 0,
                    'estado' => 'PENDIENTE',
                    'cod_usuario' => $user->cod_usuario,
                    'fecha_carga' => $now->format('Y-m-d H:i:s'),
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['message' => $th->getMessage()], 500);
        }

        // El proceso corre el día 1 de cada mes: si el alta no cae un día 1, se aplica el 1 del mes siguiente
        $fechaProceso = $fechaAlta->day == 1 ? $fechaAlta : $fechaAlta->copy()->addMonthNoOverflow()->startOfMonth();

        return response()->json([
            'message' => 'Traspaso programado para ' . $afiliados->count() . ' afiliado(s). Se aplicará en el proceso del ' . $fechaProceso->format('d/m/Y')
        ], 200);
    }
}
