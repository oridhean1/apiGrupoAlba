<?php

namespace App\Http\Controllers\Internaciones\Services;

use App\Exports\Internacion\InternacionExport;
use App\Http\Controllers\Internaciones\Repository\InternacionesAutorizacionRepository;
use App\Http\Controllers\Internaciones\Repository\InternacionesRepository;
use App\Http\Controllers\Internaciones\Repository\InternacionFiltrosRepository;
use App\Models\Internaciones\InternacionesEntity;
use App\Models\Internaciones\InternacionesNotasEntity;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class InternacionesController  extends Controller
{

    public function getObtenerInternacionId(InternacionesRepository $repoInternacion, Request $request)
    {
        $data = $repoInternacion->findByPrestacionInternacionId($request->id, $request->cod_prestacion);
        return response()->json($data);
    }

    // ESTA ES LA API DE LA GRILLA (LA QUE FUNCIONA)
    public function getConsultarInternaciones(InternacionFiltrosRepository $repoInternacionFiltro, Request $request)
    {
        $data = [];

        $data = $repoInternacionFiltro->findByListNombresLike($request);
        foreach ($data as $key) {
            $detalle = $repoInternacionFiltro->finByListaDetallePrestaciones($key->cod_internacion);
            $key->setAttribute('show', false);
            $key->setAttribute('detalle', $detalle);
        }

        return response()->json($data, 200);
    }

    public function getProcesarInternacion(
        InternacionesRepository $repoInternacion,
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        try {
            DB::beginTransaction();
            $message = "Internación registrado correctamente.";
            if (!is_null($request->cod_internacion)) {
                $repoInternacion->findByUpdate($request);
                $repoInterAut->findByUpdate($request->id_internacion_autorizacion, $request->cod_internacion);
                $message = "Internación actualizado correctamente.";
            } else {
                $repo = $repoInternacion->findBySave($request);
                $repoInterAut->findBySave($request->id_internacion_autorizacion, $repo->cod_internacion);
            }
            DB::commit();
            return response()->json(["message" => $message], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getId(InternacionFiltrosRepository $repoInternacion, Request $request)
    {
        $data = $repoInternacion->findById($request->id);
        $detalle = $repoInternacion->finByListaDetallePrestaciones($data->cod_internacion);
        $data->setAttribute('show', false);
        $data->setAttribute('detalle', $detalle);

        return response()->json($data, 200);
    }

    public function getBuscarInternacionesDNI(InternacionFiltrosRepository $repoInternacion, Request $request)
    {
        $data = $repoInternacion->findByListDni($request->dni);
        return response()->json($data, 200);
    }

    public function getEliminarInternacion(InternacionFiltrosRepository $repoFiltroInternacion, InternacionesRepository $repoInternacion, Request $request)
    {
        if ($repoFiltroInternacion->findByIdExistsAndEstado($request->id, 2)) {
            if (!$repoFiltroInternacion->findByExistAndPrestaciones($request->id)) {
                $repoInternacion->findByDeleteId($request->id);
                return response()->json(['message' => 'Internacion eliminada correctamente']);
            } else {
                return response()->json(['message' => 'No se puede eliminar una internacion cuando ya tiene cargada mas de una prestacion medica'], 409);
            }
        } else {
            return response()->json(['message' => 'No se puede eliminar una internacion ya auditado'], 409);
        }
    }

    public function postUpdateEstado(Request $request)
    {
        $fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
        InternacionesEntity::where('cod_internacion', $request->cod_internacion)->update(['estado' => $request->estado, 'fecha_egreso' => $fechaActual]);
        return response()->json(['message' => 'Estado Cambiado Correctamente'], 200);
    }

    public function validarInternacion(Request $request)
    {
        $validar = InternacionesEntity::where('dni_afiliado', $request->dni_afiliado)->where('estado', 1)->first();
        if ($validar) {
            return response()->json(["message" => 'el afiliado tiene una internacion abierta, tiene que cerrarla'], 500);
        } else {
            return response()->json(["message" => 'el afiliado no tiene pendientes'], 200);
        }
    }

    public function postSaveNotasInternacion(Request $request)
    {
        $user = Auth::user();
        $fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
        InternacionesNotasEntity::create([
            'dni_afiliado' => $request->dni_afiliado,
            'cod_usuario' => $user->cod_usuario,
            'fecha_registra' => $fechaActual,
            'descripcion' => $request->descripcion,
        ]);
        return response()->json(['message' => 'Nota Registrado Correctamente'], 200);
    }

    // ESTA ES LA API DE EXPORTAR (CORREGIDA)
    public function getExportInternacion(InternacionFiltrosRepository $repoInternacionFiltro, Request $request)
    {
        try {

            $data = [];

            if (!empty($request->search)) {
                if (is_numeric($request->search)) {
                    $data = $repoInternacionFiltro->findByListDniLike($request->search);
                } else {
                    $data = $repoInternacionFiltro->findByListNombresLike($request->search);
                }
            } elseif (!empty($request->estado)) {
                $data = $repoInternacionFiltro->findByListEstado($request->estado);
            } elseif (!empty($request->interestado)) {
                $data = $repoInternacionFiltro->findByListNewEstado($request->interestado);
            } else {
                $data = $repoInternacionFiltro->findByList();
            }


            // Pasa los datos YA FILTRADOS y los filtros al Excel
            return Excel::download(new InternacionExport($data, $request), 'Internacion.xlsx');
        } catch (\Exception $e) {

            return response()->json([
                'error' => 'Error al generar el archivo Excel',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function postSavePrestacionesAutorizadas(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findBySavePrestacionVinculada($request);
        return response()->json(['message' => 'Autorizacion vinculado Correctamente'], 200);
    }

    public function postDeletePrestacionesAutorizadas(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findByDeletePrestacionVinculada($request->cod_prestacion);
        return response()->json(['message' => 'Autorizacion desvinculado Correctamente'], 200);
    }

    public function postSaveRN(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findBySaveRN($request);
        return response()->json(['message' => 'Recien nacido registrado Correctamente'], 200);
    }

    public function postDeleteRN(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findByDeleteRN($request);
        return response()->json(['message' => 'Recien nacido eliminado Correctamente'], 200);
    }

     public function postSavePrestacionesAutorizadasRN(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findBySavePrestacionVinculadaRN($request);
        return response()->json(['message' => 'Autorizacion vinculado Correctamente'], 200);
    }

    public function getListAutorizadasRN(
        InternacionFiltrosRepository $repoInterAut,
        Request $request
    ) {
        $data = $repoInterAut->findById($request->cod_internacion);
        
        if ($data && !empty($data->recien_nacido)) {
            foreach ($data->recien_nacido as $rn) {
                // Fetch direct newborn authorizations
                $directAuths = \App\Models\Internaciones\AutorizacionDatosRNEntity::with(['detalle_prestacion.practica'])
                    ->where('cod_recien_nacido', $rn->cod_recien_nacido)
                    ->get();
                
                // Merge direct authorizations into the existing autorizacion collection
                $merged = $rn->autorizacion->merge($directAuths);
                
                // Assign the merged collection back to the relation
                $rn->setRelation('autorizacion', $merged);
            }
        }
        
        return response()->json($data, 200);
    }

    public function postDeletePrestacionesAutorizadasRN(
        InternacionesAutorizacionRepository $repoInterAut,
        Request $request
    ) {
        $repoInterAut->findByDeletePrestacionVinculadaRN($request->cod_prestacion);
        return response()->json(['message' => 'Autorizacion desvinculado Correctamente'], 200);
    }
}
