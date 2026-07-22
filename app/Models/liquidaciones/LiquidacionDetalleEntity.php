<?php

namespace App\Models\liquidaciones;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiquidacionDetalleEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_liquidaciones_detalle';
    protected $primaryKey = 'id_detalle';
    public $timestamps = false;

    protected $fillable = [
        'id_liquidacion',
        'fecha_prestacion',
        'id_identificador_practica',
        'costo_practica',
        'cantidad',
        'porcentaje_hon',
        'porcentaje_gast',
        'monto_facturado',
        'monto_aprobado',
        'coseguro',
        'debita_coseguro',
        'debita_iva',
        'id_tipo_motivo_debito',
        'observacion_debito',
        'monto_debitado',
        'estado',
        'hospital',
        'periodo',
        'tipo_hospital'
    ];
}
