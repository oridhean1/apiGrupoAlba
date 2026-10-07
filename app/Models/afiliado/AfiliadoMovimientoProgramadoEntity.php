<?php

namespace App\Models\afiliado;

use App\Models\ComercialCajaModel;
use App\Models\ComercialOrigenModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AfiliadoMovimientoProgramadoEntity extends Model
{
    private static $habilitado = null;

    /**
     * R-00000352 es solo para ALBA: la tabla se crea únicamente en esa base. Donde no existe (OSV)
     * los traspasos quedan deshabilitados y el padrón se comporta como antes.
     */
    public static function habilitado(): bool
    {
        if (self::$habilitado === null) {
            self::$habilitado = Schema::hasTable('tb_afiliado_movimiento_programado');
        }
        return self::$habilitado;
    }

    protected $table = 'tb_afiliado_movimiento_programado';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_vinculo',
        'dni',
        'cuil_tit',
        'tipo_movimiento',
        'id_comercial_caja',
        'id_comercial_origen',
        'fecha_vigencia',
        'traspaso_grupo',
        'estado',
        'detalle_error',
        'cod_usuario',
        'fecha_carga',
        'fecha_aplicacion'
    ];

    public function origen()
    {
        return $this->hasOne(ComercialOrigenModel::class, 'id_comercial_origen', 'id_comercial_origen');
    }

    public function caja()
    {
        return $this->hasOne(ComercialCajaModel::class, 'id_comercial_caja', 'id_comercial_caja');
    }

    public function usuario()
    {
        return $this->hasOne(User::class, 'cod_usuario', 'cod_usuario');
    }
}
