<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        try {
            DB::statement("ALTER TABLE `tb_liquidaciones_detalle` ADD INDEX `idx_liq_det_id_liquidacion` (`id_liquidacion`)");
        } catch (\Exception $e) {
            // Index might already exist
        }

        try {
            DB::statement("ALTER TABLE `tb_padron` ADD INDEX `idx_padron_dni` (`dni`)");
        } catch (\Exception $e) {
            // Index might already exist
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        try {
            DB::statement("ALTER TABLE `tb_liquidaciones_detalle` DROP INDEX `idx_liq_det_id_liquidacion`");
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            DB::statement("ALTER TABLE `tb_padron` DROP INDEX `idx_padron_dni`");
        } catch (\Exception $e) {
            // Index might not exist
        }
    }
};
