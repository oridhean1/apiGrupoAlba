<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use Illuminate\Support\Facades\DB;

class TesCuentasFiltrosRepository
{

    public function findByListAlls($idRazon = null)
    {
        return TesCuentasBancariasEntity::with(['tipoCuenta', 'entidadBancaria', 'tipoMoneda', 'razonSocial'])
            ->when($idRazon, fn ($q) => $q->where('id_razon', $idRazon))
            ->orderByDesc('id_cuenta_bancaria')
            ->get();
    }

    public function findByListBanco($banco)
    {
        return TesCuentasBancariasEntity::with(['tipoCuenta', 'entidadBancaria', 'tipoMoneda'])
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
        return TesMovientosCuentaBancariaEntity::with(['cuenta'])
            ->whereBetween(DB::raw('DATE(fecha_movimiento)'), [$desde, $hasta])
            ->orderByDesc('id_movimiento')
            ->get();
    }

    public function findByListMovimientosIdCuenta($desde, $hasta, $cuenta)
    {
        return TesMovientosCuentaBancariaEntity::with(['cuenta'])
            ->whereBetween(DB::raw('DATE(fecha_movimiento)'), [$desde, $hasta])
            ->where('id_cuenta_bancaria', $cuenta)
            ->orderByDesc('id_movimiento')
            ->get();
    }
}
