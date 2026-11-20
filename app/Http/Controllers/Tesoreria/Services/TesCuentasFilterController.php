<?php

namespace App\Http\Controllers\Tesoreria\Services;

use App\Http\Controllers\Tesoreria\Repository\TesCuentaCatalogoRepository;
use App\Http\Controllers\Tesoreria\Repository\TesCuentasFiltrosRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TesCuentasFilterController extends Controller
{

    public function getFiltrar(Request  $request, TesCuentasFiltrosRepository $repo)
    {
        // OJO: antes esto usaba `!is_null($request->banco)`, y el front manda `banco=''` cuando
        // no hay filtro. Como '' no es null, SIEMPRE entraba por la rama del banco y filtraba
        // por un id vacio: la pantalla salia sin ninguna cuenta hasta elegir un banco. Ademas
        // la razon social quedaba ignorada cada vez que se elegia uno. (2026-09-04)
        return response()->json(
            $repo->findByListFiltrado(
                $request->filled('id_razon') ? $request->id_razon : null,
                $request->filled('banco') ? $request->banco : null
            )
        );
    }

    public function getListarEntidadesBancarias(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListEntidadesBancarias());
    }

    public function getListarTipoCuentas(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoCuentas());
    }

    public function getListarTipoMonedas(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoMoneda());
    }

    public function getListarMovimientos(Request $request, TesCuentasFiltrosRepository $repo)
    {
        $data = [];

        // El front manda cuenta='' sin cuenta elegida: con is_null filtraba por id vacio y no traia nada.
        if ($request->filled('cuenta')) {
            $data = $repo->findByListMovimientosIdCuenta($request->desde, $request->hasta, $request->cuenta, $request->boolean('conciliables'));
        } else {
            $data = $repo->findByListMovimientos($request->desde, $request->hasta);
        }

        return response()->json($this->soloDeLaRazon($data, $request));
    }

    public function getListarTipoTransaciones(TesCuentaCatalogoRepository $repo)
    {
        return response()->json($repo->findByListTipoTransaccion());
    }

    public function findById(Request $request, TesCuentasFiltrosRepository $repo)
    {
        return response()->json($repo->findById($request->id));
    }

    /**
     * Pantalla Cuentas con razón social elegida arriba (2026-11-18): sólo lo de las cuentas de esa
     * razón, así no se mezclan las entidades del grupo. Sin `id_razon`, como antes.
     */
    private function soloDeLaRazon($data, Request $request)
    {
        if (!$request->filled('id_razon')) {
            return $data;
        }
        $ids = \App\Models\Tesoreria\TesCuentasBancariasEntity::where('id_razon', $request->id_razon)
            ->pluck('id_cuenta_bancaria')->map(fn($i) => (int) $i)->all();
        return collect($data)->filter(fn($r) => in_array((int) $r->id_cuenta_bancaria, $ids, true))->values();
    }
}
