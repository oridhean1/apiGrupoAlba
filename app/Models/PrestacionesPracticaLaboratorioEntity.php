<?php

namespace App\Models;

use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\prestadores\PrestadorEntity;
use App\Models\prestadores\PrestadorMedicosEntity;
use App\Models\PrestacionesMedicas\DetalleTramitePrestacionMedicaEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrestacionesPracticaLaboratorioEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_prestaciones_medicas';
    protected $primaryKey = 'cod_prestacion';
    public $timestamps = false;

    protected $fillable = [
        'fecha_registra',
        'observaciones',
        'fecha_impresion',
        'vigente',
        'monto_pagar',
        'archivo_adjunto',
        'usuario_registra',
        'usuario_imprime',
        'cod_prestador',
        'cod_profesional',
        'dni_afiliado',
        'estado_impresion',
        'cod_tipo_estado',
        'diagnostico',
        'domicilio_prestador',
        'domicilio_profesional',
        'edad_afiliado',
        'cod_internacion'
    ];

    public function detalle()
    {
        return $this->hasMany(DetallePrestacionesPracticaLaboratorioEntity::class, 'cod_prestacion', 'cod_prestacion');
    }

    public function estadoPrestacion()
    {
        return $this->hasOne(TipoEstadoPrestacionEntity::class, 'cod_tipo_estado', 'cod_tipo_estado');
    }

    public function afiliado()
    {
        return $this->hasOne(AfiliadoPadronEntity::class, 'dni', 'dni_afiliado');
    }

    public function usuario()
    {
        return $this->hasOne(User::class, 'cod_usuario', 'usuario_registra');
    }

    public function prestador()
    {
        return $this->hasOne(PrestadorEntity::class, 'cod_prestador', 'cod_prestador');
    }

    public function profesional()
    {
        return $this->hasOne(PrestadorMedicosEntity::class, 'cod_profesional', 'cod_profesional');
    }

    public function datosTramite()
    {
        return $this->hasOne(DetalleTramitePrestacionMedicaEntity::class, 'id_detalle_tramite', 'id_detalle_tramite');
    }
}
