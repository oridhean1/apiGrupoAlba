<?php
// Llamar a la API como lo hace el front: pasa por rutas, middleware (jwt, ConvertEmptyStringsToNull)
// y controlador. Devuelve [status, json]. No es un test: lo usan los tests de filtros.
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

if (!function_exists('apiGet')) {
    function apiGet(string $uri, array $params = []): array
    {
        static $token = null;
        $token ??= JWTAuth::fromUser(\App\Models\User::first());

        $req = \Illuminate\Http\Request::create($uri, 'GET', $params, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT'        => 'application/json',
        ]);
        $res = app(\Illuminate\Contracts\Http\Kernel::class)->handle($req);

        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    }

    /** Las filas de una respuesta, venga como lista o como {data: [...]}. */
    function filas($json): array
    {
        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        return is_array($json) && array_is_list($json) ? $json : [];
    }
}
