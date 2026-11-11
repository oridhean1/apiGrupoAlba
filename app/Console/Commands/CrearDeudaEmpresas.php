<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CrearDeudaEmpresas extends Command
{
    protected $signature = 'insert:monthly-deuda';
    protected $description = 'Inserta datos mensuales en la tabla tb_fisca_deudas_aportes_empresa';

    public function handle()
    {
        $periodo = now()->subMonth()->format('ym'); // ejemplo: 2507

        DB::statement("
            INSERT INTO tb_fisca_deudas_aportes_empresa (
                anio, mes, importe_sueldo, aporte, contribucion,
                contribucion_extraordinaria, fecha_recalculo, fecha_vencimiento,
                id_empresa, intereses, monto_deuda,
                monto_estudio_juridico, monto_gestion_morosidad,
                tipo_deuda, estado, fecha_creacion,
                monto_transferido, monto_revertido, monto_retenido
            )
            SELECT
            CONCAT('20', SUBSTRING(da.periodo,1,2)) AS anio,
            SUBSTRING(da.periodo,3,2) AS mes,
            da.total_remimpo AS importe_sueldo,
            ROUND(da.total_aporte,2) AS aporte,
            ROUND(da.total_contribucion,2) AS contribucion,
            NULL,
            NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY),
            emp.id_empresa, 0,
            ROUND((da.total_remimpo * 0.09) - IFNULL(ta.total_transferido,0),2),
            NULL, NULL, 'APORTE',
            CASE WHEN IFNULL(ta.total_transferido,0) >= (da.total_remimpo * 0.09)
                THEN 'Finalizado' ELSE 'Vigente' END,
            NOW(), IFNULL(ta.total_transferido,0), 0, 0
            FROM (
            SELECT u.cuit, u.periodo,
                    SUM(u.remimpo) AS total_remimpo,
                    SUM(u.remimpo)*0.03 AS total_aporte,
                    SUM(u.remimpo)*0.06 AS total_contribucion
            FROM (
                SELECT d.*
                FROM tb_declaraciones_juradas d
                INNER JOIN (
                SELECT cuit, cuil, periodo, MAX(fecha_proceso) AS fecha_ultima
                FROM tb_declaraciones_juradas
                GROUP BY cuit, cuil, periodo
                ) ult
                ON d.cuit = ult.cuit AND d.cuil = ult.cuil AND d.periodo = ult.periodo
                AND d.fecha_proceso = ult.fecha_ultima
            ) u
            GROUP BY u.cuit, u.periodo
            ) da
            JOIN tb_empresa emp ON emp.cuit = da.cuit
            LEFT JOIN (
            SELECT cuitcont, periodo, SUM(importe) AS total_transferido
            FROM tb_transferencias
            GROUP BY cuitcont, periodo
            ) ta
            ON ta.cuitcont = da.cuit AND ta.periodo = da.periodo
            WHERE da.periodo = :periodo
            AND NOT EXISTS (
                SELECT 1
                FROM tb_fisca_deudas_aportes_empresa f
                WHERE f.id_empresa = emp.id_empresa
                    AND f.anio = CONCAT('20', SUBSTRING(da.periodo,1,2))
                    AND f.mes  = SUBSTRING(da.periodo,3,2)
            );

        ", ['periodo' => $periodo]);

        $this->info("Datos insertados correctamente para período $periodo ✅");
        return 0;
    }
}
