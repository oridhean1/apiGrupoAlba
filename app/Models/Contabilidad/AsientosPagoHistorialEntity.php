<?php

namespace App\Models\Contabilidad;

use App\Models\Tesoreria\TesPagoEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsientosPagoHistorialEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_cont_asientos_pago_historial';
    protected $primaryKey = 'id_pago_asiento';
    public $timestamps = false;

    protected $fillable = [
        'id_pago',
        // El evento puede referirse a UN ABONO y a UNA LINEA del asiento, no solo a la boleta:
        // sin esto Eloquent los descartaba en silencio y el circuito de eCheq no podia saber que
        // linea revertir al rechazar un instrumento. (2026-09-12)
        'id_pago_parcial',
        'id_asiento_contable',
        'id_asiento_contable_detalle',
        'tipo_evento',
        'es_contraasiento',
        'id_asiento_origen',
        'observacion',
        'cod_usuario',
        'fecha_registra'
    ];

    protected $casts = [
        'es_contraasiento' => 'boolean',
        'fecha_registra'   => 'datetime'
    ];

    public function pago()
    {
        return $this->belongsTo(TesPagoEntity::class, 'id_pago', 'id_pago');
    }

    public function asientoContable()
    {
        return $this->belongsTo(AsientosContablesEntity::class, 'id_asiento_contable', 'id_asiento_contable');
    }

    public function asientoOrigen()
    {
        return $this->belongsTo(AsientosContablesEntity::class, 'id_asiento_origen', 'id_asiento_contable');
    }
}
