<?php

namespace App\Http\Controllers\Internaciones\Repository;

use App\Models\Internaciones\InternacionesEntity;
use App\Models\PrestacionesMedicas\DetallePrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;

class InternacionFiltrosRepository
{
    private $relaciones;
    public function __construct()
    {
        $this->relaciones = [
            "prestador",
            "tipoPrestacion",
            "tipoInternacion",
            "tipoHabitacion",
            "afiliado.obrasocial",
            "categoria",
            "especialidad",
            "tipoEgreso",
            "tipoDiagnostico",
            "usuario",
            "estadoPrestacion",
            "internacion",
            "prestaciones_principales",
            "autorizacion.detalle_prestacion.practica",
            "autorizacion.internacion",
            "recien_nacido.autorizacion.detalle_prestacion.practica"
        ];
    }

    // ======================================================
    // FUNCIONES ORIGINALES CON LÍMITE
    // ======================================================

    public function findByListDniLikeAndLimit($dni, $limit)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('dni_afiliado', 'like',  $dni . '%')
            ->orderBy('fecha_internacion', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findByListNombresLikeAndLimit($search, $limit)
    {
        return InternacionesEntity::with($this->relaciones)
            ->whereHas('afiliado', function ($query) use ($search) {
                $query->where('nombre', 'like',  $search . '%');
                $query->orWhere('apellidos', 'like',  $search . '%');
            })
            ->orderBy('fecha_internacion', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findByListEstadoLimit($estado, $limit)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('cod_tipo_estado',  $estado)
            ->orderBy('fecha_internacion', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findByListNewEstadoLimit($estado, $limit)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('estado',  $estado)
            ->orderBy('fecha_internacion', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findByListLimit($limit)
    {
        return InternacionesEntity::with($this->relaciones)
            ->orderBy('fecha_internacion', 'desc')
            ->limit($limit)
            ->get();
    }

    // ======================================================
    // NUEVAS FUNCIONES SIN LÍMITE (AGREGADAS)
    // ======================================================

    public function findByListDniLike($dni)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('dni_afiliado', 'like',  $dni . '%')
            ->orderBy('fecha_internacion', 'desc')
            ->get();
    }

    public function findByListNombresLike($request)
    {
        $query = InternacionesEntity::with($this->relaciones)
            ->when(!empty($request->search), function ($q) use ($request) {
                $q->whereHas('afiliado', function ($query) use ($request) {
                    $query->where('nombre', 'like', '%' . $request->search . '%')
                        ->orWhere('apellidos', 'like', '%' . $request->search . '%')
                        ->orWhere('dni', 'like', $request->search . '%');
                });
            })
            ->when(!empty($request->interestado), function ($q) use ($request) {
                $q->where('estado', $request->interestado);
            })
            ->when(!empty($request->persona), function ($q) use ($request) {
                $q->where('cod_usuario_registra', $request->persona);
            })
            ->when(!empty($request->desde) && !empty($request->hasta), function ($q) use ($request) {
                $q->whereBetween('fecha_ingresa', [
                    $request->desde,
                    $request->hasta
                ]);
            })
            ->orderBy('fecha_internacion', 'desc')
            ->limit('200')
            ->get();

        return $query;
    }

    public function findByListEstado($estado)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('cod_tipo_estado',  $estado)
            ->orderBy('fecha_internacion', 'desc')
            ->get();
    }

    public function findByListNewEstado($estado)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('estado',  $estado)
            ->orderBy('fecha_internacion', 'desc')
            ->get();
    }

    public function findByListUsuario($estado)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('cod_usuario_registra',  $estado)
            ->orderBy('fecha_internacion', 'desc')
            ->get();
    }

    public function findByList()
    {
        return InternacionesEntity::with($this->relaciones)
            ->orderBy('fecha_internacion', 'desc')
            ->get();
    }


    public function findById($id)
    {
        return InternacionesEntity::with($this->relaciones)
            ->find($id);
    }

    public function findByListDni($dni)
    {
        return InternacionesEntity::with($this->relaciones)
            ->where('dni_afiliado',    $dni)
            ->get();
    }

    public function finByListaDetallePrestaciones($id)
    {
        return  DetallePrestacionesPracticaLaboratorioEntity::with(["practica"])
            ->whereHas('prestacion', function ($query) use ($id) {
                $query->where('cod_internacion', $id);
            })
            ->get();
    }

    public function findByIdExistsAndEstado($id, $estado)
    {
        return InternacionesEntity::where('cod_tipo_estado', $estado)
            ->where('cod_internacion', $id)
            ->exists();
    }

    public function findByExistAndPrestaciones($id)
    {
        return   PrestacionesPracticaLaboratorioEntity::where('cod_internacion', $id)->exists();
    }
}
