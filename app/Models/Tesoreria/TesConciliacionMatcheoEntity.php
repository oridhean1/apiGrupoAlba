<?php

namespace App\Models\Tesoreria;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TesConciliacionMatcheoEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_tes_conciliacion_matcheo';
    protected $primaryKey = 'id_matching';
    public $timestamps = false;

    protected $fillable = [
        'id_extracto_bancario',
        'id_movimiento_interno',
        'tipo_origen_interno', // PAGO / TRANSFERENCIA / OPERACION_MANUAL (informativo)
        'score_obtenido',
        'reglas_cumplidas',
        'estado', // 0 = cargado/sugerido, 1 = aprobado
        'id_usuario_aprobador',
        'fecha_matching',
        'observaciones'
    ];

    protected $casts = [
        'reglas_cumplidas' => 'array',
        'estado' => 'boolean',
        'fecha_matching' => 'datetime'
    ];

    public function extracto()
    {
        return $this->belongsTo(TesExtractosBancariosEntity::class, 'id_extracto_bancario', 'id_extracto');
    }

    public function movimiento()
    {
        return $this->belongsTo(TesMovientosCuentaBancariaEntity::class, 'id_movimiento_interno', 'id_movimiento');
    }

    public function usuarioAprobador()
    {
        return $this->belongsTo(User::class, 'id_usuario_aprobador', 'cod_usuario');
    }
}
