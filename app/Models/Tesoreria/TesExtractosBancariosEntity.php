<?php

namespace App\Models\Tesoreria;

use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesExtractosBancariosEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_extracto_bancarios';
    protected $primaryKey = 'id_extracto';
    public $timestamps = false;

    protected $fillable = [
        'id_cuenta_bancaria',
        'id_razon',
        'fecha',
        'banco',
        'concepto',
        'importe',
        'saldo',
        'referencia',
        'detalle',
        'detalle_nombre',
        'detalle_cuit',
        // Campos Cygnus Finance AI
        'estado_conciliacion',
        'score_matching',
        'id_movimiento_match',
        // Campos de auditoría
        'id_usuario',
        'fecha_registra',
        'id_usuario_confirma',
        'fecha_confirma',
        'observaciones'
    ];

    public function cuentaBancaria()
    {
        return $this->hasOne(TesCuentasBancariasEntity::class, 'id_cuenta_bancaria', 'id_cuenta_bancaria');
    }

    public function razonSocial()
    {
        return $this->hasOne(RazonSocialModelo::class, 'id_razon', 'id_razon');
    }

    public function movimientoMatch()
    {
        return $this->hasOne(TesMovientosCuentaBancariaEntity::class, 'id_movimiento', 'id_movimiento_match');
    }

    public function matcheos()
    {
        return $this->hasMany(TesConciliacionMatcheoEntity::class, 'id_extracto_bancario', 'id_extracto');
    }
}
