<?php

namespace App\Models\PrestacionesMedicas;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PresupuestosMedicosEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_presupuestos';
    protected $primaryKey = 'id_presupuesto';
    public $timestamps = false;

    protected $fillable = [
        'cod_prestacion',
        'id_presupuesto_proveedor',
        'origen_proveedor',
        'fecha_presupuesto',
        'monto_presupuestado',
        'observaciones',
        'archivo_presupuesto',
        'id_usuario_crea',
        'fecha_carga',
        'estado',
        'id_usuario_autoriza',
        'fecha_autoriza',
        'motivo_rechazo',
        'observacion_autoriza'
    ];

    public function prestacion()
    {
        return $this->hasOne(PrestacionesPracticaLaboratorioEntity::class, 'cod_prestacion', 'cod_prestacion');
    }

    public function proveedor()
    {
        return $this->hasOne(ProveedorPresupuestosEntity::class, 'id_presupuesto_proveedor', 'id_presupuesto_proveedor');
    }
}
