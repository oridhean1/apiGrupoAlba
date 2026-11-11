<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngAnticipoAplicacionEntity extends Model
{
    protected $table = 'tb_tes_ing_anticipo_aplicacion';
    protected $primaryKey = 'id_anticipo_aplicacion';
    public $timestamps = false;

    protected $fillable = [
        'id_orden_cobranza',
        'id_orden_anticipo',
        'importe',
        'anulado',
        'cod_usuario',
        'fecha_registra',
    ];
}
