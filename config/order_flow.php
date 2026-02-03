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
            'Asignado a vendedor', 'Llamado 1', 'Llamado 2', 'Llamado 3',
            'Esperando Ubicacion', 'Asignar a agencia', 'Programado para mas tarde',
            'Programado para otro dia', 'Novedades', 'Novedad Solucionada',
            'Cancelado', 'Entregado'
        ],

        'transitions' => [
            'Novedades'                 => ['Novedad Solucionada', 'Cancelado'],
            'Novedad Solucionada'       => ['En ruta', 'Entregado'],
            'Reprogramado'              => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Asignado a vendedor'       => ['Llamado 1', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 1'                 => ['Llamado 2', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 2'                 => ['Llamado 3', 'Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Llamado 3'                 => ['Esperando Ubicacion', 'Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Esperando Ubicacion'       => ['Programado para mas tarde', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Programado para mas tarde' => ['Esperando Ubicacion', 'Programado para otro dia', 'Asignar a agencia', 'Cancelado'],
            'Programado para otro dia'  => ['Esperando Ubicacion', 'Asignar a agencia', 'Cancelado'],
            'Asignar a agencia'         => [], // 🔒 BLOQUEADO: Vendedor no puede moverla una vez aquí.
            'Entregado'         => [], // 🔒 BLOQUEADO: Vendedor no puede moverla una vez aquí.
            // Confirmado -> 'QUITAR ESTE ESTATUS' (No incluido)
            // Entregado -> No modification
            // Cancelado -> No modification
        ]
    ],

    'Agencia' => [
        'visible_columns' => [
            'Novedades', 'Novedad Solucionada', 'Asignar a agencia',
            'Asignado a repartidor', 'En ruta', 'Entregado', 'Cancelado'
        ],

        'transitions' => [
            'Asignar a agencia'     => ['Asignar repartidor', 'Novedades'],
            'Asignar repartidor'    => ['En ruta', 'Novedades'],
            'En ruta'               => ['Entregado', 'Novedades'],
            'Novedad Solucionada'   => ['Asignar repartidor', 'Asignar a agencia', 'En ruta'], 
            // Nota: 'Asignado a repartidor' en DB suele ser 'Asignar repartidor' o 'Asignado a repartidor'. Ajustar segun DB real.
            // Asumiré 'Asignar repartidor' según spreadsheet, pero revisaré si en DB es distinto.
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
