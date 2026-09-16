<?php

namespace App\Http\Controllers\Contabilidad\Repository;

use App\Models\Contabilidad\ImputacionesCuentaContableEntity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ImputacionCuentaContableRepository
{
    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now();
    }

    public function findByCrear($params)
    {
        return ImputacionesCuentaContableEntity::create([
            'id_detalle_plan' => $params->id_detalle_plan,
            'id_razon' => $params->id_razon ?? null,
            'imputacion' => $params->descripcion,
            'codigo' => $params->codigo_cuenta,
            'vigente' => $params->vigente ?? true,
            'cod_usuario' => $this->user->cod_usuario,
            'fecha_registra' => $this->fechaActual
        ]);
    }

    public function findByExisteRelacion($id_detalle_plan, $codigo)
    {
        return ImputacionesCuentaContableEntity::where('id_detalle_plan', $id_detalle_plan)
            ->where('codigo', $codigo)
            ->where('vigente', true)
            ->exists();
    }

    public function findByUpdate($params, $id)
    {
        $imputacion = ImputacionesCuentaContableEntity::find($id);
        $imputacion->id_detalle_plan = $params->id_detalle_plan;
        $imputacion->id_razon = $params->id_razon ?? $imputacion->id_razon;
        $imputacion->imputacion = $params->imputacion;
        $imputacion->codigo = $params->codigo;
        $imputacion->vigente = $params->vigente ?? $imputacion->vigente;
        $imputacion->cod_usuario_modifica = $this->user->cod_usuario;
        $imputacion->fecha_modifica = $this->fechaActual;
        return $imputacion->update();
    }

    public function findByListar()
    {
        return ImputacionesCuentaContableEntity::with(['detallePlan'])
            ->get();
    }

    public function findByBuscarRelacionImputacion($idDetallePlan, $codigo)
    {
        return ImputacionesCuentaContableEntity::where('id_detalle_plan', $idDetallePlan)
            ->where('codigo', $codigo)
            ->where('vigente', true)
            ->first();
    }

    public function findByListarConFiltros($filtros = [])
    {
        $query = ImputacionesCuentaContableEntity::with(['detallePlan']);

        // aplicar por defecto vigente = 1 si no se envía el filtro
        if (!isset($filtros['vigente'])) {
            $query->where('vigente', 1);
        }

        if (isset($filtros['vigente'])) {
            $query->where('vigente', $filtros['vigente']);
        }

        if (isset($filtros['id_detalle_plan'])) {
            $query->where('id_detalle_plan', $filtros['id_detalle_plan']);
        }

        if (isset($filtros['id_razon']) && !empty($filtros['id_razon'])) {
            $query->where('id_razon', $filtros['id_razon']);
        }

        if (isset($filtros['codigo'])) {
            $query->where('codigo', 'like', '%' . $filtros['codigo'] . '%');
        }

        if (isset($filtros['imputacion'])) {
            $query->where('imputacion', 'like', '%' . $filtros['imputacion'] . '%');
        }

        // Búsqueda libre: por código o por nombre de imputación
        if (isset($filtros['search']) && trim($filtros['search']) !== '') {
            $search = '%' . trim($filtros['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', $search)
                    ->orWhere('imputacion', 'like', $search);
            });
        }

        return $query->get();
    }

    // Nuevo: obtener por id (para editar)
    public function findById($id)
    {
        return ImputacionesCuentaContableEntity::with(['detallePlan'])
            ->where('id_imputacion_cuenta_contable', $id)
            ->first();
    }

    // Nuevo: wrapper para 'editar' con mapeo de campos esperado por la UI
    public function findByEditar($id)
    {
        $registro = $this->findById($id);
        if (!$registro) {
            return null;
        }

        return (object) [
            'id_imputacion_cuenta_contable' => $registro->id_imputacion_cuenta_contable,
            'id_detalle_plan' => $registro->id_detalle_plan,
            'id_razon' => $registro->id_razon,
            'descripcion' => $registro->imputacion,    // coincide con create que usaba 'descripcion'
            'codigo_cuenta' => $registro->codigo,      // coincide con create que usaba 'codigo_cuenta'
            'codigo' => $registro->codigo,             // para compatibilidad con update que usa 'codigo'
            'vigente' => $registro->vigente,
            'detallePlan' => $registro->detallePlan ?? null
        ];
    }

    // Nuevo: "eliminar" marcando como no vigente y registrando usuario/fecha
    public function findByEliminar($id)
    {
        $imputacion = ImputacionesCuentaContableEntity::find($id);
        if (!$imputacion) {
            return false;
        }

        $imputacion->vigente = false;
        $imputacion->cod_usuario_modifica = $this->user->cod_usuario;
        $imputacion->fecha_modifica = $this->fechaActual;

        return $imputacion->update();
    }
}
