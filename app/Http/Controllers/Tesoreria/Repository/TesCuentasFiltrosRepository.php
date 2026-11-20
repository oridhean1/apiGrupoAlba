<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use Illuminate\Support\Facades\DB;

class TesCuentasFiltrosRepository
{

    /**
     * Listado de cuentas con filtros COMBINABLES.
     *
     * Reemplaza al par findByListAlls/findByListBanco, que se elegian con un if y por eso nunca
     * podian aplicarse juntos: pedir banco descartaba la razon social. (2026-09-04)
     */
    public function findByListFiltrado($idRazon = null, $banco = null)
    {
        return TesCuentasBancariasEntity::with([
            'tipoCuenta', 'entidadBancaria', 'tipoMoneda', 'razonSocial',
            'cuentaContableBanco', 'cuentaContableEcheq',
        ])
            ->when($idRazon, fn($q) => $q->where('id_razon', $idRazon))
            ->when($banco, fn($q) => $q->where('id_entidad_bancaria', $banco))
            ->orderByDesc('id_cuenta_bancaria')
            ->get();
    }

    public function findByListAlls($idRazon = null)
    {
        return TesCuentasBancariasEntity::with([
            'tipoCuenta', 'entidadBancaria', 'tipoMoneda', 'razonSocial',
            // Para que el visor pueda avisar si la cuenta no esta relacionada contablemente.
            'cuentaContableBanco', 'cuentaContableEcheq',
        ])
            ->when($idRazon, fn ($q) => $q->where('id_razon', $idRazon))
            ->orderByDesc('id_cuenta_bancaria')
            ->get();
    }

    public function findByListBanco($banco)
    {
        return TesCuentasBancariasEntity::with([
            'tipoCuenta', 'entidadBancaria', 'tipoMoneda',
            'cuentaContableBanco', 'cuentaContableEcheq',
        ])
            ->where('id_entidad_bancaria', $banco)
            ->orderByDesc('id_cuenta_bancaria')
            ->get();
    }

    public function findById($id)
    {
        return TesCuentasBancariasEntity::with(['tipoCuenta', 'entidadBancaria', 'tipoMoneda'])
            ->find($id);
    }

    public function findByListMovimientos($desde, $hasta)
    {
        return $this->movimientosQuery($desde, $hasta)->get();
    }

    public function findByListMovimientosIdCuenta($desde, $hasta, $cuenta, bool $conciliables = false)
    {
        return $this->movimientosQuery($desde, $hasta, $conciliables)
            ->where('id_cuenta_bancaria', $cuenta)
            ->get();
    }

    /**
     * Movimientos más nuevos primero (por fecha). Cada extremo de fecha filtra sólo si viene:
     * sin filtros de fecha se listan todos (2026-11-19).
     */
    /**
     * `$conciliables` (buscador manual del Visor): sólo lo que se puede elegir para conciliar, sin
     * movimientos anulados ni sus ANULACION ni los ya conciliados con otra línea (2026-11-19).
     */
    private function movimientosQuery($desde, $hasta, bool $conciliables = false)
    {
        $base = $conciliables
            ? TesRegistroExtractoRepository::movimientosVigentes()
                ->whereDoesntHave('matcheos', fn($q) => $q->where('estado', 1))
            : TesMovientosCuentaBancariaEntity::query();
        return $base->with(['cuenta'])
            ->when(!empty($desde), fn($q) => $q->where(DB::raw('DATE(fecha_movimiento)'), '>=', $desde))
            ->when(!empty($hasta), fn($q) => $q->where(DB::raw('DATE(fecha_movimiento)'), '<=', $hasta))
            ->orderByDesc('fecha_movimiento')
            ->orderByDesc('id_movimiento');
    }
}
