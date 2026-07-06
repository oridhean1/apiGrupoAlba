<?php

namespace App\Http\Controllers\PrestacionesMedicas\Repository;

use App\Models\Internaciones\AutorizacionDatosRNEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\PrestacionMedicaFile;
use Illuminate\Support\Facades\Auth;

class PrestacionesmedicasFiltrosRepository
{
    private $user;
    private $allRelations;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->allRelations = ['detalle', 'detalle.practica', 'estadoPrestacion', 'afiliado', 'afiliado.obrasocial', 'usuario', 'prestador', 'profesional', 'datosTramite', 'datosTramite.tramite', 'datosTramite.prioridad', 'datosTramite.obrasocial', 'documentacion'];
    }

    public function findByListFechaRegistraBetweenAndDniAfiliado($desde, $hasta, $dni, $tramite)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->whereBetween('fecha_registra', [$desde, $hasta])
            ->where('dni_afiliado', $dni);

        if (!empty($tramite)) {
            $query->where('numero_tramite', 'like', $tramite . '%');
        }

        $results = $query->orderByDesc('cod_prestacion')->get();

        return $results;
    }

    public function findByListFechaRegistraBetweenAndCuilAfiliado($desde, $hasta, $cuil, $tramite)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->whereBetween('fecha_registra', [$desde, $hasta])
            ->whereHas('afiliado', function ($query) use ($cuil) {
                $query->where('cuil_benef', $cuil);
            });

        if (!empty($tramite)) {
            $query->where('numero_tramite', 'like', $tramite . '%');
        }
        $results = $query->orderByDesc('cod_prestacion')->get();
        return $results;
    }

    public function findByListFechaRegistraBetweenAndDniAfiliadoLike($desde, $hasta, $dni, $tramite)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->whereBetween('fecha_registra', [$desde, $hasta])
            ->where('dni_afiliado', 'LIKE', $dni . '%');

        if (!empty($tramite)) {
            $query->where('numero_tramite', 'like', $tramite . '%');
        }
        $results = $query->orderByDesc('fecha_registra')->get();
        return $results;
    }

    public function findByListFechaRegistraBetweenAndNombresAfiliadoLike($desde, $hasta, $nombres, $tramite)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->whereBetween('fecha_registra', [$desde, $hasta])
            ->whereHas('afiliado', function ($query) use ($nombres) {
                $query->where(function ($q) use ($nombres) {
                    $q
                        ->where('apellidos', 'like', '%' . $nombres . '%')
                        ->orWhere('nombre', 'like', '%' . $nombres . '%');
                });
            });

        if (!empty($tramite)) {
            $query->where('numero_tramite', 'like', $tramite . '%');
        }
        $results = $query->orderByDesc('fecha_registra')->get();
        return $results;
    }

    public function findByListEstado($estado, $usuario)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations);

        if (!empty($estado)) {
            $query->where('cod_tipo_estado', $estado);
        }

        if (!empty($usuario)) {
            $query->where('usuario_registra', $usuario);
        }

        $results = $query
            ->orderByDesc('fecha_registra')
            ->get();
        return $results;
    }

    public function findByListFechaRegistraBetweenAndLimit($limit, $request)
    {
        $query = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->when(!empty($request->desde) && !empty($request->hasta), function ($q) use ($request) {
                $q->whereBetween('fecha_registra', [$request->desde, $request->hasta]);
            })
            ->when(!empty($request->tramite), function ($q) use ($request) {
                $q->where('numero_tramite', 'like', $request->tramite . '%');
            })
            ->when(!empty($request->search), function ($q) use ($request) {
                $q->where(function ($query) use ($request) {
                    $query->where('dni_afiliado', 'like', $request->search . '%')
                        ->orWhereHas('afiliado', function ($afiliado) use ($request) {
                            $afiliado->where('apellidos', 'like', '%' . $request->search . '%')
                                ->orWhere('nombre', 'like', '%' . $request->search . '%');
                        });
                });
            })
            ->when(!empty($request->cuil), function ($q) use ($request) {
                $q->whereHas('afiliado', function ($afiliado) use ($request) {
                    $afiliado->where('cuil_benef', $request->cuil);
                });
            })
            ->when(!empty($request->estado), function ($q) use ($request) {
                $q->where('cod_tipo_estado', $request->estado);
            })
            ->when(!empty($request->usuario), function ($q) use ($request) {
                $q->where('usuario_registra', $request->usuario);
            });

        $results = $query
            ->orderByRaw("
        CASE
            WHEN fecha_modifica IS NULL OR fecha_modifica = ''
            THEN fecha_registra
            ELSE fecha_modifica
                END DESC
            ")
            ->orderBy('cod_prestacion', 'desc')
            ->limit($limit)
            ->get();

        return $results;
    }

    public function findById($id)
    {
        $anioTrabajo = date('Y');
        $prestacion = PrestacionesPracticaLaboratorioEntity::with($this->allRelations)->find($id);

        /*
         * $listFile = PrestacionMedicaFile::where('cod_prestacion', $prestacion->cod_prestacion)->get();
         *
         * $files = $listFile->map(function ($file) use ($anioTrabajo) {
         *     $file->url = url("/storage/prestaciones/{$anioTrabajo}/{$file->archivo}");
         *     return $file;
         * });
         *
         * $prestacion->setRelation('url', $files);
         */
        return $prestacion;
    }

    public function findByDeleteId($id)
    {
        // return PrestacionesPracticaLaboratorioEntity::find($id)->delete();

        $registro = PrestacionesPracticaLaboratorioEntity::find($id);

        if ($registro) {
            $registro->cod_tipo_estado = 7;
            $registro->save();
        }
    }

    public function findByListDniAfiliado($dni)
    {
        return PrestacionesPracticaLaboratorioEntity::with($this->allRelations)
            ->where('dni_afiliado', $dni)
            ->orderByDesc('cod_prestacion')
            ->get();
    }

    public function findByListAutorizacionLimit($shared)
    {
        $query = PrestacionesPracticaLaboratorioEntity::orderBy('cod_prestacion', 'desc');

        if (!empty($shared)) {
            $query->where('numero_tramite', 'like', "%{$shared}%");
        } else {
            $query->limit(20);
        }

        return $query->get();
    }

    public function findByListAutorizacionIds($ids)
    {
        return PrestacionesPracticaLaboratorioEntity::whereIn('cod_prestacion', $ids)->get();
    }

    public function findByFiltersNewborn($request)
    {
        $relations = [
            'detalle',
            'detalle.practica',
            'estadoPrestacion',
            'usuario',
            'prestador',
            'profesional',
            'recien_nacido',
            'recien_nacido.internacion',
            'recien_nacido.internacion.afiliado'
        ];

        $query = AutorizacionDatosRNEntity::with($relations);

        if ($request->filled('desde') && $request->filled('hasta')) {
            $query->whereBetween('fecha_registra', [$request->desde, $request->hasta]);
        }

        if ($request->filled('tramite')) {
            $query->where('cod_prestacion_rn', 'like', $request->tramite . '%');
        }

        if ($request->filled('estado')) {
            $query->where('cod_tipo_estado', $request->estado);
        }

        if ($request->filled('persona')) {
            $query->where('usuario_registra', $request->persona);
        }

        // Filter for Newborn DNI
        if ($request->filled('dni_rn')) {
            $query->whereHas('recien_nacido', function ($q) use ($request) {
                $q->where('dni_rn', 'like', $request->dni_rn . '%');
            });
        }

        // Filter for Mother's DNI
        if ($request->filled('dni_madre')) {
            $query->whereHas('recien_nacido.internacion', function ($q) use ($request) {
                $q->where('dni_afiliado', 'like', $request->dni_madre . '%');
            });
        }

        // Filter for newborn's name / newborn's DNI / mother's DNI / transaction ID (search box)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q
                    ->where('cod_prestacion_rn', 'like', $search . '%')
                    ->orWhereHas('recien_nacido', function ($rnQuery) use ($search) {
                        $rnQuery
                            ->where('nombre_rn', 'like', '%' . $search . '%')
                            ->orWhere('apellidos_rn', 'like', '%' . $search . '%')
                            ->orWhere('dni_rn', 'like', $search . '%');
                    })
                    ->orWhereHas('recien_nacido.internacion', function ($intQuery) use ($search) {
                        $intQuery->where('dni_afiliado', 'like', $search . '%');
                    });
            });
        }

        $results = $query->orderByDesc('cod_prestacion_rn')->get();
        return $results;
    }
}
