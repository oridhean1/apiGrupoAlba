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
            'matcheos.movimiento.medio.orden.cliente', // cobros (R-00000689 E3)
            'matcheos.abono.pago.opa.proveedor',        // eCheq a acreditar (E4)
            'matcheos.abono.pago.opa.prestador',
        ]);
    }

    /**
     * Confirma o rechaza el match sugerido por el motor (o uno elegido a mano por el usuario).
     */
    public function findByConfirmarMatch($idExtracto, $aprobar, $idMovimientoInterno = null, $observaciones = null, $sinContraparte = false)
    {
        // Una sola transacción: confirmar un eCheq lo acredita (asiento + movimiento) y eso tiene
        // que quedar junto con la conciliación, o nada.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($idExtracto, $aprobar, $idMovimientoInterno, $observaciones, $sinContraparte) {
            $extracto = TesExtractosBancariosEntity::lockForUpdate()->findOrFail($idExtracto);
            $matcheo = TesConciliacionMatcheoEntity::where('id_extracto_bancario', $idExtracto)
                ->latest('id_matching')
                ->first();

            if (!$matcheo) {
                $matcheo = new TesConciliacionMatcheoEntity(['id_extracto_bancario' => $idExtracto]);
            }

            // Un registro bancario (E5) tiene asiento y movimiento propios: no se desconcilia a secas.
            if ($matcheo->tipo_origen_interno === TesRegistroExtractoRepository::ORIGEN && $matcheo->estado) {
                throw new \Exception('Esta línea se registró con asiento propio. Para deshacerla usá "Anular registro".');
            }

            if ($sinContraparte) {
                // "Conciliado sin contraparte" (comisión, etc.): no se usa la sugerencia.
                $matcheo->id_movimiento_interno = null;
                $matcheo->id_pago_parcial = null;
            } elseif ($idMovimientoInterno) {
                $matcheo->id_movimiento_interno = $idMovimientoInterno; // el usuario eligió un candidato distinto al sugerido
                $matcheo->id_pago_parcial = null;
            }

            $matching = app(TesConciliacionMatchingRepository::class);

            // eCheq emitido sugerido: confirmar lo acredita con la fecha del extracto (R-00000689 P1).
            if ($aprobar && $matcheo->id_pago_parcial && !$matcheo->id_movimiento_interno) {
                $matcheo->id_movimiento_interno = $matching->acreditarDesdeExtracto($extracto, $matcheo->id_pago_parcial);
            }

            // El movimiento puede venir elegido a mano: misma cuenta (⇒ misma razón), mismo sentido y
            // no conciliado contra otra línea. Sin candidato = "conciliado sin contraparte" (comisión, etc.).
            if ($aprobar && $matcheo->id_movimiento_interno) {
                $matching->validarParaConfirmar($extracto, $matcheo->id_movimiento_interno, $extracto->id_extracto);
            }

            $matcheo->estado = $aprobar ? 1 : 0;
            $matcheo->id_usuario_aprobador = $this->user?->cod_usuario;
            $matcheo->fecha_matching = $this->fechaActual;
            if ($observaciones) {
                $matcheo->observaciones = $observaciones;
            }
            $matcheo->save();

            $extracto->estado_conciliacion = $aprobar ? 'CONCILIADO_MANUAL' : 'PENDIENTE';
            $extracto->id_movimiento_match = $aprobar ? $matcheo->id_movimiento_interno : null;
            $extracto->id_usuario_confirma = $this->user?->cod_usuario;
            $extracto->fecha_confirma = $this->fechaActual;
            $extracto->save();

            return $extracto;
        });
    }
}
