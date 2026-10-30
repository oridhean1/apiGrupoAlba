<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngOrdenMedioEntity extends Model
{
    protected $table = 'tb_tes_ing_orden_medio';
    protected $primaryKey = 'id_orden_medio';
    public $timestamps = false;

    protected $fillable = [
        'id_orden_cobranza',
        'id_forma_pago',
        'id_cuenta_bancaria',
        'referencia',
        'importe',
    ];
}
