<?php

/**
 * Roles y permisos de la app.
 *
 * Todos los roles ven el Dashboard, la Trazabilidad, la proyección, el resumen de una compra
 * y pueden imprimir. Aquí solo se listan los permisos que distinguen a un rol de otro.
 */

const ROLES = [
    'trazabilidad'  => 'Trazabilidad',
    'gerente'       => 'Gerente',
    'desarrollador' => 'Desarrollador',
];

const PERMISOS = [
    'ver_ranking'              => ['gerente', 'desarrollador'],
    'ver_sincronizacion'       => ['gerente', 'desarrollador'],
    'ver_bitacora'             => ['gerente', 'desarrollador'],
    'reactivar_sincronizacion' => ['desarrollador'],
    'administrar_usuarios'     => ['desarrollador'],
];

/** Lista de permisos que tiene un rol. */
function permisosDeRol(string $rol): array
{
    return array_keys(array_filter(PERMISOS, fn($roles) => in_array($rol, $roles, true)));
}

/** true si el usuario de la sesión tiene el permiso. */
function puede(array $usuario, string $permiso): bool
{
    return in_array($usuario['rol'], PERMISOS[$permiso] ?? [], true);
}
