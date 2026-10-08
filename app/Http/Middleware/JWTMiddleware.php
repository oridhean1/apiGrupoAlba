<?php

namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JWTAuth;
use Exception;
use PDOException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;

class JWTMiddleware{
    public function handle($request, Closure $next)
    {
        try {
            $user = $this->autenticar();
        } catch (Exception  $e) {
            if ($e instanceof TokenInvalidException){
                return response()->json(['status' => 401,'message' => 'Lo sentimos su sessión ha expirado, por su seguridad vuelva a ingresar.','expired' => true],401);
            }else if ($e instanceof TokenExpiredException){
                return response()->json(['status' => 401,'message' => 'Lo sentimos su sessión ha expirado.', 'expired' => true],401);
            }else if ($e instanceof PDOException){
                // El token es válido pero no se pudo leer el usuario de la base (T-00001083)
                $this->registrarError('JWT E02055', $e, $request);
                return response()->json(['status' => 500,'message' => 'No pudimos conectar con el servidor de datos, intente nuevamente en unos segundos. E02055', 'expired' => false],500);
            }else{
                $this->registrarError('JWT E02054', $e, $request);
                return response()->json(['status' => 401,'message' => 'Estimado usuario necesitas un token de acceso. E02054', 'expired' => false],401);
            }
        }

       /*  if (!$user) {
            return response()->json(['error' => 'Lo sentimos se solicita un token de validación.'], 401);
        } */

        // Autenticar al usuario
        Auth::login($user);

        return $next($request);
    }

    // Si la base corta momentáneamente al leer el usuario, se reconecta y se reintenta una vez
    private function autenticar()
    {
        try {
            return \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::parseToken()->authenticate();
        } catch (PDOException $e) {
            Log::warning('JWT reintento por error de base', ['message' => $e->getMessage()]);
            usleep(300000);
            DB::reconnect();
            return \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::parseToken()->authenticate();
        }
    }

    private function registrarError($titulo, Exception $e, $request)
    {
        Log::error($titulo, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'url' => $request->method() . ' ' . $request->fullUrl(),
            'has_auth' => $request->hasHeader('Authorization'),
            'ip' => $request->ip(),
        ]);
    }
}
