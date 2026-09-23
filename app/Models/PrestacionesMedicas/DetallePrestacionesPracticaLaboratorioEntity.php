<?php

namespace   App\Models\PrestacionesMedicas;

use App\Models\pratricaMatriz\PracticaMatrizEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetallePrestacionesPracticaLaboratorioEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_prestaciones_medicas_detalle';
    protected $primaryKey = 'cod_detalle';
    public $timestamps = false;

    protected $fillable = [
        'cantidad_solicitada',
        'cantidad_autorizada',
        'precio_unitario',
        'monto_pagar',
        'id_identificador_practica',
        'cod_prestacion',
        'estado_imprimir'
    ];

    public function prestacion()
    {
        return $this->hasOne(PrestacionesPracticaLaboratorioEntity::class, 'cod_prestacion', 'cod_prestacion');
    }

    public function practica()
    {
        return $this->hasOne(PracticaMatrizEntity::class, 'id_identificador_practica', 'id_identificador_practica');
    }

    // Auditoria de esta practica (tb_prestaciones_medicas_autorizadas): fecha en
    // que Auditoria Medica la autorizo y quien firmo. Es la fecha del tramite; la
    // de tb_internaciones_auditadas es una sola para toda la internacion y se usa
    // como respaldo. OJO: existe un modelo espejo de este detalle en App\Models,
    // que tambien la declara. (T-00000804)
    public function auditoria()
    {
        return $this->hasOne(AuditarPrestacionesPracticaLaboratorioEntity::class, 'cod_detalle', 'cod_detalle');
    }
}
