<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngRetencionEntity extends Model
{
    protected $table = 'tb_tes_ing_retencion';
    protected $primaryKey = 'id_retencion';
    public $timestamps = false;

    protected $fillable = [
        'id_orden_cobranza',
        'tipo_retencion',
        'numero_certificado',
        'fecha_retencion',
        'importe',
    ];
}
