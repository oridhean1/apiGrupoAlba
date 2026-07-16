<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesConciliacionMatcheoEntity;
use App\Models\Tesoreria\TesEntidadesBancariasEntity;
use App\Models\Tesoreria\TesExtractosBancariosEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class TesExtractoBancariosRepository
{

    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findByList($desde, $hasta, $idRazon = null, $idEntidadBancaria = null, $estado = null)
    {
        return $this->queryConDetalle()
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($idRazon, fn ($q) => $q->where('id_razon', $idRazon))
            ->when($idEntidadBancaria, fn ($q) => $this->filtrarPorBanco($q, $idEntidadBancaria))
            ->when($estado, fn ($q) => $q->where('estado_conciliacion', $estado))
            ->orderByDesc('fecha')
            ->get();
    }

    /**
     * Filtra por banco: matchea tanto por la cuenta bancaria resuelta (id_entidad_bancaria)
     * como por el texto crudo de la columna "banco" del Excel, para no perder filas cuyo
     * banco no se pudo resolver a una cuenta propia.
     */
    private function filtrarPorBanco($query, $idEntidadBancaria)
    {
        $entidad = TesEntidadesBancariasEntity::find($idEntidadBancaria);

        return $query->where(function ($q) use ($idEntidadBancaria, $entidad) {
            $q->whereHas('cuentaBancaria', fn ($q2) => $q2->where('id_entidad_bancaria', $idEntidadBancaria));
            if ($entidad) {
                // La columna "banco" trae texto libre y corto (ej. "Frances"); el catálogo tiene
                // el nombre completo (ej. "BBVA (BANCO FRANCES)"). Se matchea en cualquier sentido.
                $q->orWhereRaw('? LIKE CONCAT("%", banco, "%")', [$entidad->descripcion_banco])
                  ->orWhere('banco', 'LIKE', '%' . $entidad->descripcion_banco . '%');
            }
        });
    }

    /**
     * Extractos puntuales por id, con el mismo detalle que findByList (para mostrar
     * el resultado de un import recién hecho sin otro viaje al listado general).
     */
    public function findByIds(array $ids)
    {
        return $this->queryConDetalle()
            ->whereIn('id_extracto', $ids)
            ->orderByDesc('fecha')
            ->get();
    }

    private function queryConDetalle()
    {
        return TesExtractosBancariosEntity::with([
            'cuentaBancaria.entidadBancaria',
            'razonSocial',
            'matcheos' => fn ($q) => $q->latest('id_matching'),
            'matcheos.movimiento.pago.opa.proveedor',
            'matcheos.movimiento.pago.opa.prestador',
            'matcheos.movimiento.operacion',
        ]);
    }

    /**
     * Confirma o rechaza el match sugerido por el motor (o uno elegido a mano por el usuario).
     */
    public function findByConfirmarMatch($idExtracto, $aprobar, $idMovimientoInterno = null, $observaciones = null)
    {
        $extracto = TesExtractosBancariosEntity::findOrFail($idExtracto);
        $matcheo = TesConciliacionMatcheoEntity::where('id_extracto_bancario', $idExtracto)
            ->latest('id_matching')
            ->first();

        if (!$matcheo) {
            $matcheo = new TesConciliacionMatcheoEntity(['id_extracto_bancario' => $idExtracto]);
        }

        if ($idMovimientoInterno) {
            $matcheo->id_movimiento_interno = $idMovimientoInterno; // el usuario eligió un candidato distinto al sugerido
        }

        $matcheo->estado = $aprobar ? 1 : 0;
        $matcheo->id_usuario_aprobador = $this->user?->cod_usuario;
        $matcheo->fecha_matching = $this->fechaActual;
        if ($observaciones) {
            $matcheo->observaciones = $observaciones;
        }
        $matcheo->save();

        $extracto->estado_conciliacion = $aprobar ? 'CONCILIADO_MANUAL' : 'PENDIENTE';
        $extracto->id_usuario_confirma = $this->user?->cod_usuario;
        $extracto->fecha_confirma = $this->fechaActual;
        $extracto->save();

        return $extracto;
    }
}
