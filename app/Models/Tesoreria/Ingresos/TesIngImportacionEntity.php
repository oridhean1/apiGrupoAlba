<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngImportacionEntity extends Model
{
    protected $table = 'tb_tes_ing_importacion';
    protected $primaryKey = 'id_importacion';
    public $timestamps = false;

    protected $fillable = [
        'id_razon',
        'nombre_archivo',
        'hash_archivo',
        'filas_total',
        'filas_importadas',
        'filas_observadas',
        'cod_usuario',
        'fecha_registra',
    ];
}
