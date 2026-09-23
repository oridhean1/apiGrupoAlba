<?php

namespace   App\Models\PrestacionesMedicas;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditarPrestacionesPracticaLaboratorioEntity extends Model
{
    use HasFactory;

    protected $table = 'tb_prestaciones_medicas_autorizadas';
    protected $primaryKey = 'cod_auditar';
    public $timestamps = false;

    protected $fillable = [
        'fecha_autorizacion',
        'cod_usuario_audita',
        'observaciones',
        'cod_tipo_rechazo',
        'cod_detalle',
        'estado_autoriza',
        'cod_recetario',
        'observacion_auditoria_medica'
    ];


    public function detalle()
    {
        return $this->hasMany(DetallePrestacionesPracticaLaboratorioEntity::class, 'cod_detalle', 'cod_detalle');
    }

    // Quien firmo la auditoria de la practica. Es el "AUTORIZADO POR" del
    // comprobante: el Jasper lo tomaba del auditor (cod_usuario_audita), no del
    // usuario que registro el tramite. (T-00000804)
    public function auditor()
    {
        return $this->hasOne(User::class, 'cod_usuario', 'cod_usuario_audita');
    }
}
