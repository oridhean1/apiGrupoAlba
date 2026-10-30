<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngClienteEntity extends Model
{
    protected $table = 'tb_tes_ing_cliente';
    protected $primaryKey = 'id_cliente';
    public $timestamps = false;

    protected $fillable = [
        'cuit',
        'razon_social',
        'sigla',
        'condicion_iva',
        'id_razon_grupo',
        'id_obra',
        'activo',
        'cod_usuario',
        'fecha_registra',
        'cod_usuario_modifica',
        'fecha_modifica',
    ];
}
