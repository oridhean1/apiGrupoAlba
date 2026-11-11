<?php

namespace App\Models\Tesoreria\Ingresos;

use Illuminate\Database\Eloquent\Model;

class TesIngComprobanteEntity extends Model
{
    protected $table = 'tb_tes_ing_comprobante';
    protected $primaryKey = 'id_comprobante';
    public $timestamps = false;

    protected $fillable = [
        'id_razon',
        'id_cliente',
        'id_tipo_comprobante',
        'punto_venta',
        'numero',
        'fecha_emision',
        'periodo_desde',
        'periodo_hasta',
        'importe_neto_gravado',
        'importe_no_gravado',
        'importe_exento',
        'importe_iva',
        'importe_otros_tributos',
        'importe_total',
        'saldo',
        'cae',
        'id_comprobante_asociado',
        'id_importacion',
        'id_asiento_contable',
        'anulado',
        'cod_usuario',
        'fecha_registra',
        'cod_usuario_modifica',
        'fecha_modifica',
    ];
}
