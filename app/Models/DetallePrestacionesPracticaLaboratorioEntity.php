<?php

namespace App\Models;

use App\Models\pratricaMatriz\PracticaMatrizEntity;
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

   /*  public function practica()
    {
        return $this->hasOne(PracticaMatrizEntity::class, 'id_identificador_practica', 'id_identificador_practica');
    } */
}
