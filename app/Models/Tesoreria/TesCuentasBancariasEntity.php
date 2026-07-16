<?php

namespace App\Models\Tesoreria;

use App\Models\configuracion\RazonSocialModelo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesCuentasBancariasEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_cuentas_bancarias';
    protected $primaryKey = 'id_cuenta_bancaria';
    public $timestamps = false;

    protected $fillable = [
        'id_razon',
        'numero_cuenta',
        'nombre_cuenta',
        'id_tipo_cuenta',
        'id_entidad_bancaria',
        'saldo_total',
        'saldo_disponible',
        'activo',
        'cbu',
        'alias',
        'fecha_apertura',
        'cod_usuario',
        'id_tipo_moneda',
        'limite_sobregiro'
    ];

    public function razonSocial()
    {
        return $this->hasOne(RazonSocialModelo::class, 'id_razon', 'id_razon');
    }

    public function tipoCuenta()
    {
        return $this->hasOne(TesTipoCuentasBancariasEntity::class, 'id_tipo_cuenta', 'id_tipo_cuenta');
    }

    public function entidadBancaria()
    {
        return $this->hasOne(TesEntidadesBancariasEntity::class, 'id_entidad_bancaria', 'id_entidad_bancaria');
    }

    public function tipoMoneda()
    {
        return $this->hasOne(TesTipoMonedasEntity::class, 'id_tipo_moneda', 'id_tipo_moneda');
    }
}
