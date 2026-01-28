<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Flujo de Estados por Rol
    |--------------------------------------------------------------------------
    |
    | Aquí se definen las transiciones permitidas para cada rol.
    | Si un rol no está listado, se asume que tiene permisos completos (Admin/Gerente).
    |
    | Formato:
    | 'Rol' => [
    |    'Estado Actual' => ['Estado Destino 1', 'Estado Destino 2']
    | ]
    |
    */

    'Vendedor' => [
        // Visibilidad en Kanban (Solo referencia, se usa en Frontend)
        'visible_columns' => [
            'Asignado a vendedor', 'Llamado 1', 'Llamado 2', 'Llamado 3',
            'Esperando ubicacion', 'Asignar a agencia', 'Programado para mas tarde',
            'Programado para otro dia', 'Novedades', 'Novedad Solucionada',
            'Cancelado'
        ],

        // Transiciones Permitidas
        'transitions' => [
            'Nuevo'                => ['Asignado a vendedor'], // Caso borde
            'Asignado a vendedor' => ['Llamado 1', 'Cancelado', 'Asignado a vendedor'], // "Asignado a vendedor" permite re-asignarse a sí mismo si es necesario
            'Llamado 1'           => ['Llamado 2', 'Asignar a agencia', 'Esperando ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Cancelado'],
            'Llamado 2'           => ['Llamado 3', 'Asignar a agencia', 'Esperando ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Cancelado'],
            'Llamado 3'           => ['Asignar a agencia', 'Esperando ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Cancelado'],
            'Esperando ubicacion' => ['Asignar a agencia', 'Llamado 1', 'Llamado 2', 'Llamado 3', 'Programado para mas tarde', 'Programado para otro dia'],
            'Programado para mas tarde' => ['Asignado a vendedor', 'Llamado 1', 'Llamado 2', 'Llamado 3', 'Asignar a agencia'], // Al activarse, vuelven al flujo
            'Programado para otro dia'  => ['Asignado a vendedor', 'Llamado 1', 'Llamado 2', 'Llamado 3', 'Asignar a agencia'],
            'Novedades'           => ['Novedad Solucionada'],
            'Novedad Solucionada' => ['Asignado a agencia', 'Programado para otro dia', 'Programado para mas tarde', 'Cancelado'],
        ]
    ],

    'Agencia' => [
        'visible_columns' => [
            'Novedades', 'Novedad Solucionada', 'Asignado a agencia',
            'Asignado a repartidor', 'En ruta', 'Entregado', 'Cancelado'
        ],

        'transitions' => [
            'Asignado a agencia'    => ['Asignado a repartidor', 'Novedades', 'Cancelado'],
            'Asignado a repartidor' => ['En ruta', 'Novedades', 'Asignado a agencia', 'Cancelado'], // Correccion: Novedades directo permitido
            'En ruta'               => ['Entregado', 'Novedades'],
            'Novedad Solucionada'   => ['Asignado a repartidor', 'Asignado a agencia', 'En ruta'],
            'Novedades'             => ['Novedad Solucionada', 'Cancelado'], // Agencia puede cancelar si es inconsistente? Asumimos que reportan novedad solucionada
        ]
    ],
    
    // Repartidor (si usa la web)
    'Repartidor' => [
        'visible_columns' => [
            'Asignado a repartidor', 'En ruta', 'Entregado', 'Novedades', 'Novedad Solucionada'
        ],
        'transitions' => [
            'Asignado a repartidor' => ['En ruta'],
            'En ruta'               => ['Entregado', 'Novedades'],
        ]
    ]
];
