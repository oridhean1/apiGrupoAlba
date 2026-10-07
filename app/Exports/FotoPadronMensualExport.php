<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;

class FotoPadronMensualExport implements FromQuery, WithHeadings, ShouldAutoSize, WithStyles
{
    protected $periodo;
    protected $origenes;

    public function __construct($periodo, $origenes = [])
    {
        $this->periodo = $periodo;
        $this->origenes = $origenes;
    }

    public function query()
    {
        return DB::table('tb_padron_foto_mensual as f')
            ->leftJoin('tb_comercial_caja as c', 'c.id_comercial_caja', '=', 'f.id_comercial_caja')
            ->leftJoin('tb_comercial_origen as o', 'o.id_comercial_origen', '=', 'f.id_comercial_origen')
            ->leftJoin('tb_parentesco as pa', 'pa.id_parentesco', '=', 'f.id_parentesco')
            ->leftJoin('tb_locatorio as loc', 'loc.id_locatorio', '=', 'f.id_locatario')
            ->select([
                'f.periodo',
                DB::raw("DATE_FORMAT(f.fecha_generacion, '%d/%m/%Y') as fecha_generacion"),
                'f.dni',
                'f.cuil_benef',
                'f.cuil_tit',
                DB::raw("CONCAT(f.apellidos, ' ', f.nombre) as afiliado"),
                'pa.parentesco',
                DB::raw("IF(f.activo = 1, 'ACTIVO', 'BAJA') as estado"),
                DB::raw("DATE_FORMAT(f.fe_alta, '%d/%m/%Y') as fe_alta"),
                // En el padrón 1900-01-01 / 1970-01-01 se usan como "sin baja"
                DB::raw("IF(f.fe_baja IS NULL OR f.fe_baja <= '1970-01-01', '', DATE_FORMAT(f.fe_baja, '%d/%m/%Y')) as fe_baja"),
                'c.detalle_comercial_caja',
                'o.detalle_comercial_origen',
                'loc.locatorio',
            ])
            ->where('f.periodo', $this->periodo)
            ->when(!empty($this->origenes), function ($q) {
                $q->whereIn('f.id_comercial_origen', $this->origenes);
            })
            ->orderBy('f.id');
    }

    public function headings(): array
    {
        return [
            'PERIODO',
            'FECHA FOTO',
            'DNI',
            'CUIL',
            'CUIL TIT',
            'AFILIADO',
            'PARENTESCO',
            'ESTADO',
            'FECH ALTA',
            'FECH BAJA',
            'OBRA SOCIAL',
            'ORIGEN',
            'LOCATARIO',
        ];
    }

    public function styles($excel)
    {
        return [
            'A1:M1' => ['font' => ['bold' => true]],
        ];
    }
}
