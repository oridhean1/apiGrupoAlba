<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngOrdenCobranzaEntity extends Model
{
    protected $table = 'tb_tes_ing_orden_cobranza';
    protected $primaryKey = 'id_orden_cobranza';
    public $timestamps = false;

    protected $fillable = [
        'numero',
        'id_razon',
        'id_cliente',
        'fecha_cobro',
        'id_estado',
        'saldo_anticipo',
        'observaciones',
        'id_asiento_contable',
        'id_asiento_anulacion',
        'fecha_confirma',
        'cod_usuario_confirma',
        'fecha_anula',
        'cod_usuario_anula',
        'motivo_anulacion',
        'cod_usuario',
        'fecha_registra',
        'cod_usuario_modifica',
        'fecha_modifica',
    ];
}
