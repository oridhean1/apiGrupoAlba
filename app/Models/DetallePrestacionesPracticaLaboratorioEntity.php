<?php

namespace App\Models;

use App\Models\pratricaMatriz\PracticaMatrizEntity;
use App\Models\PrestacionesMedicas\AuditarPrestacionesPracticaLaboratorioEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'cod_prestacion'
    ];

    public function prestacion()
    {
        return $this->hasOne(PrestacionesPracticaLaboratorioEntity::class, 'cod_prestacion', 'cod_prestacion');
    }

    public function practica()
    {
        return $this->hasOne(PracticaMatrizEntity::class, 'id_identificador_practica', 'id_identificador_practica');
    }

    // Misma auditoria que declara el detalle de App\Models\PrestacionesMedicas.
    // Este modelo es el que usan las autorizaciones vinculadas de la internacion
    // (InternacionAutorizacionEntity::detalle_prestacion), asi que tambien la
    // necesita o el eager loading del visor falla. (T-00000804)
    public function auditoria()
    {
        return $this->hasOne(AuditarPrestacionesPracticaLaboratorioEntity::class, 'cod_detalle', 'cod_detalle');
    }

   /*  public function practica()
    {
        return $this->hasOne(PracticaMatrizEntity::class, 'id_identificador_practica', 'id_identificador_practica');
    } */
}
