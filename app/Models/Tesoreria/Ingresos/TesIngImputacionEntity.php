<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngImputacionEntity extends Model
{
    protected $table = 'tb_tes_ing_imputacion';
    protected $primaryKey = 'id_imputacion';
    public $timestamps = false;

    protected $fillable = [
        'id_comprobante',
        'id_orden_cobranza',
        'id_comprobante_nc',
        'importe',
        'anulado',
        'cod_usuario',
        'fecha_registra',
    ];
}
