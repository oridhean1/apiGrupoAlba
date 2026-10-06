<?php

namespace App\Http\Controllers\Soporte;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Soporte › Tutoriales: videos de cómo se usan los circuitos (2026-10-05).
 *
 * Los archivos están en `storage/app/tutoriales`, fuera de la carpeta pública, y se sirven por acá
 * detrás del mismo `jwt.verify` que el resto: muestran datos reales de prestadores.
 */
class TutorialesController extends Controller
{
    private const CARPETA = 'tutoriales';

    /** GET /v1/ticketSoporte/tutoriales — los activos, en orden. */
    public function listar()
    {
        try {
            return response()->json(
                DB::table('tb_soporte_tutoriales')
                    ->where('activo', 1)
                    ->orderBy('orden')
                    ->get(['id_tutorial', 'modulo', 'titulo', 'descripcion', 'orden'])
            );
        } catch (\Throwable $e) {
            Log::error('Error listar tutoriales: ' . $e->getMessage());
            return response()->json(['message' => 'Error al listar los tutoriales'], 500);
        }
    }

    /** GET /v1/ticketSoporte/tutoriales/{id}/video — el archivo del video. */
    public function video($id)
    {
        $t = DB::table('tb_soporte_tutoriales')->where('id_tutorial', $id)->where('activo', 1)->first();

        if (!$t) {
            return response()->json(['message' => 'El tutorial no existe'], 404);
        }

        // basename(): el nombre sale de la base, pero igual no se deja salir de la carpeta.
        $ruta = storage_path('app/' . self::CARPETA . '/' . basename($t->archivo));

        if (!is_file($ruta)) {
            return response()->json(['message' => "Falta el archivo del video ({$t->archivo}) en el servidor"], 404);
        }

        return response()->file($ruta, ['Content-Type' => 'video/webm']);
    }
}
