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
    */

    'Vendedor' => [
        'visible_columns' => [
            'Reprogramado para hoy', 'Asignado a vendedor', 'Llamado 1', 'Llamado 2', 'Llamado 3',
            'Esperando Ubicacion', 'Asignar a agencia', 'Programado para mas tarde',
            'Programado para otro dia', 'Novedades', 'Novedad Solucionada',
            'Cancelado', 'Entregado'
        ],

        'transitions' => [
            'Novedades'                 => ['Novedad Solucionada', 'Programado para otro dia', 'Programado para mas tarde', 'Cancelado'],
            // Vendedor ya no mueve de Novedad Solucionada, eso lo hace la Agencia.
            // Pero si necesita corregir, podría necesitarlo. Por ahora lo dejamos restringido según instrucción.
            'Novedad Solucionada'       => [], 
            'Reprogramado'              => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Reprogramado para hoy'     => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Asignado a vendedor'       => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 1'                 => ['Llamado 2', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 2'                 => ['Llamado 3', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 3'                 => ['Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Esperando Ubicacion'       => ['Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Programado para mas tarde' => ['Llamado 1', 'Esperando Ubicacion', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Programado para otro dia'  => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Asignar a agencia', 'Cancelado'],
            'Asignar a agencia'         => [], // 🔒 BLOQUEADO
            'Entregado'                 => [], // 🔒 BLOQUEADO
        ]
    ],

    'Agencia' => [
        'visible_columns' => [
            'Novedades', 'Novedad Solucionada', 'Asignar a agencia',
            'Asignado a repartidor', 'En ruta', 'Entregado'
        ],

        'transitions' => [
            'Asignar a agencia'     => ['Asignado a repartidor', 'Novedades'],
            'Asignado a repartidor' => ['En ruta', 'Novedades'],
            'En ruta'               => ['Entregado', 'Novedades'],
            // Novedad Solucionada -> Entregado o En Ruta (condicional en Controller)
            'Novedad Solucionada'   => ['Entregado', 'En ruta', 'Novedades'], 
        ]
    ],

    'Repartidor' => [
        'visible_columns' => [
            'Asignar repartidor', 'En ruta', 'Entregado', 'Novedades', 'Novedad Solucionada'
        ],
        'transitions' => [
            'Asignar repartidor'    => ['En ruta'],
            'En ruta'               => ['Entregado', 'Novedades'],
        ]
    ]
];
