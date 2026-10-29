<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\Tesoreria\TesCuentasBancariasEntity;
use App\Models\Tesoreria\TesMovientosCuentaBancariaEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class TesCuentasBancariasRepository
{
    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }
    public function findById($id)
    {
        return TesCuentasBancariasEntity::find($id);
    }
    public function findByCreate($params)
    {
        return TesCuentasBancariasEntity::create([
            'id_razon' => $params->id_razon,
            'numero_cuenta' => $params->numero_cuenta,
            'nombre_cuenta' => $params->nombre_cuenta,
            'id_tipo_cuenta' => $params->id_tipo_cuenta,
            'id_entidad_bancaria' => $params->id_entidad_bancaria,
            'saldo_total' => $params->saldo_total,
            'saldo_disponible' => $params->saldo_disponible,
            'activo' => $params->activo,
            'cbu' => $params->cbu,
            'alias' => $params->alias,
            'fecha_apertura' => $this->fechaActual,
            'cod_usuario' => $this->user->cod_usuario,
            'id_tipo_moneda' => $params->id_tipo_moneda
        ]);
    }

    public function findByUpdate($params)
    {
        $cuenta = TesCuentasBancariasEntity::find($params->id_cuenta_bancaria);
        $cuenta->id_razon = $params->id_razon;
        $cuenta->numero_cuenta = $params->numero_cuenta;
        $cuenta->nombre_cuenta = $params->nombre_cuenta;
        $cuenta->id_tipo_cuenta = $params->id_tipo_cuenta;
        $cuenta->id_entidad_bancaria = $params->id_entidad_bancaria;
        $cuenta->saldo_total = $params->saldo_total;
        $cuenta->saldo_disponible = $params->saldo_disponible;
        $cuenta->activo = $params->activo;
        $cuenta->cbu = $params->cbu;
        $cuenta->alias = $params->alias;
        $cuenta->fecha_apertura = $this->fechaActual;
        $cuenta->cod_usuario = $this->user->cod_usuario;
        $cuenta->id_tipo_moneda = $params->id_tipo_moneda;
        $cuenta->update();
        return $cuenta;
    }

    public function findByUpdateEstado($id, $estado)
    {
        $cuenta = TesCuentasBancariasEntity::find($id);
        $cuenta->activo = $estado;
        $cuenta->update();
        return $cuenta;
    }

    public function findByVerificarEstadoCuenta($id_cuenta, $id_estado)
    {
        return TesCuentasBancariasEntity::where('id_cuenta_bancaria', $id_cuenta)
            ->where('activo', $id_estado)
            ->exists();
    }

    /**
     * `$fecha`: la fecha real del movimiento cuando no es hoy (un pago cuyo comprobante se sube días
     * después de salir). Sin ella, el movimiento queda con la fecha de carga. (2026-10-28)
     */
    public function findByRegistrarMovimiento($id_cuenta_bancaria, $monto, $tipo_movimiento, $id_pago, $id_operacion, $obs, $fecha = null)
    {
        return TesMovientosCuentaBancariaEntity::create([
            'id_cuenta_bancaria' => $id_cuenta_bancaria,
            'id_usuario' => $this->user->cod_usuario,
            'fecha_movimiento' => $fecha ?? $this->fechaActual,
            'monto' => $monto,
            'tipo_movimiento' => $tipo_movimiento,
            'id_pago' => $id_pago,
            'id_operacion' => $id_operacion,
            'descripcion' => $obs
        ]);
    }

    public function findByRetiroCuenta($id_cuenta, $monto)
    {
        $cuenta = TesCuentasBancariasEntity::find($id_cuenta);
        $suma = $cuenta->saldo_disponible - $monto;
        $cuenta->saldo_disponible = $suma;
        $cuenta->update();
    }

    public function findByDepositoCuenta($id_cuenta, $monto)
    {
        $cuenta = TesCuentasBancariasEntity::find($id_cuenta);
        $suma = $cuenta->saldo_disponible + $monto;
        $cuenta->saldo_disponible = $suma;
        $cuenta->update();
    }

    public function findByVerificarSaldoCuenta($id_cuenta, $monto)
    {
        $cuenta = TesCuentasBancariasEntity::find($id_cuenta);

        $limitePermitido = $cuenta->limite_sobregiro ?? 50000000;

        $disponibleConSobregiro = $cuenta->saldo_disponible + $limitePermitido;

        if ($monto > $disponibleConSobregiro) {
            return false;
        }
        return true;
    }
}
