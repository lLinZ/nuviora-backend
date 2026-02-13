# Documentación de la API de Nuviora

Esta documentación detalla los endpoints disponibles para el backend de Nuviora. La API utiliza Laravel Sanctum para la autenticación y devuelve respuestas en formato JSON.

## Tabla de Contenidos
1. [Autenticación](#autenticación)
2. [Usuarios y Personalización](#usuarios-y-personalización)
3. [Órdenes](#órdenes)
4. [Logística y Automatización](#logística-y-automatización)
5. [Revisiones y Aprobaciones](#revisiones-y-aprobaciones)
6. [Inventario y Almacenes](#inventario-y-almacenes)
7. [Comisiones y Ganancias](#comisiones-y-ganancias)
8. [Gestión de Repartidores (Stock)](#gestión-de-repartidores-stock)
9. [Métricas, Reportes y Marketing](#métricas-reportes-y-marketing)
10. [Roles y Permisos](#roles-y-permisos)
11. [Cuentas de Empresa](#cuentas-de-empresa)
12. [Configuración y Referencia](#configuración-y-referencia)
13. [Gestión de Tiendas y Horarios](#gestión-de-tiendas-y-horarios)
14. [Webhooks y Pruebas](#webhooks-y-pruebas)

---

## Estructura de Respuesta Común
Casi todos los endpoints devuelven un objeto JSON con una estructura similar:
```json
{
    "status": true,
    "message": "Operación exitosa",
    "data": { ... } // O la entidad directamente
}
```

---

## 1. Autenticación

### Login
`POST /api/login`
- **Request Body:**
```json
{
    "email": "vendedor@nuviora.com",
    "password": "password123"
}
```
- **Respuesta:** Token `auth_token` y datos del usuario.

### Logout
`GET /api/logout` (Requiere Auth)
- Invalida la sesión actual.

---

## 2. Usuarios y Personalización

### Datos de Perfil
- `GET /api/user/data`: Datos del usuario autenticado.
- `GET /api/users`: Lista general de usuarios (filtros: `role`).
- `PUT /api/user/{user}`: Actualizar información básica.

### Personalización y Seguridad
- `PUT /api/user/{user}/change/color`: Cambia el color preferido del usuario.
- `PUT /api/user/{user}/change/theme`: Cambia entre modo claro/oscuro.
- `PUT /api/user/{user}/change/password`: Actualiza la contraseña.

---

## 3. Órdenes

### Consulta y Gestión
- `GET /api/orders`: Listado (filtros: `search`, `status`, `agent_id`, `agency_id`, `date_from/to`).
- `GET /api/orders/{id}`: Detalle completo.
- `GET /api/orders/{id}/products`: Lista simplificada de los items.
- `POST /api/orders`: Creación manual de órdenes.
- `GET /api/orders/{id}/activities`: Historial de cambios y auditoría.
- `GET /api/orders/pending-vueltos`: Órdenes con cambios/vueltos pendientes.
- `GET /api/orders/lite/counts`: Contadores rápidos para el dashboard Lite.

**Ejemplo de Respuesta (Detalle de Orden):**
```json
{
    "status": true,
    "order": {
        "id": 505,
        "name": "#1023",
        "current_total_price": 45.00,
        "client": { "first_name": "Maria", "phone": "04241234567" },
        "status": { "id": 3, "description": "En ruta" },
        "products": [
            { "id": 10, "title": "Perfume Nuviora Blue", "price": 45.0, "quantity": 1, "has_stock": true }
        ],
        "payments": [ { "method": "DOLARES_EFECTIVO", "amount": 50.0 } ],
        "change_amount": 5.0
    }
}
```

### Acciones en la Orden
- `PUT /api/orders/{id}/status`: Cambio de estado.
- `GET /api/orders/{id}/available-statuses`: Estados permitidos según flujo.
- `POST /api/orders/{id}/updates`: Agregar notas/bitácora.
- `POST /api/orders/{id}/upsell`: Agregar productos adicionales.
- `DELETE /api/orders/{id}/upsell/{itemId}`: Quitar productos adicionales.
- `POST /api/orders/{id}/create-return`: Crear orden de devolución.
- `POST /api/orders/{id}/postpone`: Reprogramar fecha.

---

## 4. Logística y Automatización

- `POST /api/orders/auto-assign-cities`: Asigna agencias según ciudad.
- `POST /api/orders/auto-assign-logistics`: Procesa logística por lote.
- `POST /api/orders/assign-backlog`: Asigna órdenes a agentes activos.

---

## 5. Revisiones y Aprobaciones

- `GET /api/cancellations` / `PUT /api/cancellations/{id}/review`: Gestión de cancelaciones.
- `PUT /api/orders/delivery-review/{id}/approve`: Revisión de entrega.
- `PUT /api/orders/location-review/{id}/approve`: Revisión de ubicación.
- `PUT /api/orders/rejection-review/{id}/approve`: Revisión de rechazo de cliente.

---

## 6. Inventario y Almacenes

- `GET /api/warehouses`: Lista de almacenes.
- `GET /api/warehouses/{id}/inventory`: Stock por almacén.
- `POST /api/inventory-movements/transfer`: Transferencia entre bodegas.
- `POST /api/stock/adjust`: Ajuste manual.

---

## 7. Comisiones y Ganancias

- `GET /api/commissions/me/today`: Mis comisiones hoy.
- `GET /api/commissions/admin/summary`: Resumen global administrativo.
- `GET /api/earnings/summary`: Utilidades brutas/netas.

---

## 8. Gestión de Repartidores (Stock)

- `GET /api/deliverer/stock/today`: Stock en ruta.
- `POST /api/deliverer/stock/open`: Iniciar ruta y recibir items.
- `POST /api/deliverer/stock/deliver`: Registrar entrega física de producto.
- `POST /api/deliverer/stock/close`: Devolver sobrante y cerrar.

---

## 9. Métricas, Reportes y Marketing

- `GET /api/dashboard`: KPIs del día.
- `GET /api/business-metrics`: Rentabilidad.
- `POST /api/metrics/ad-spend`: Registro de gasto en publicidad.
- `GET /api/reports/tracking-comprehensive`: Auditoría de cambios de estado.
- `POST /api/facebook/events`: Envío de eventos CAPI.

---

## 10. Roles y Permisos

- `GET /api/roles`: Listado de roles del sistema.
- `POST /api/role`: Crear nuevo rol.

---

## 11. Cuentas de Empresa

- `GET /api/company-accounts`: Cuentas para conciliación bancaria.

---

## 12. Configuración y Referencia

- `GET /api/config/flow`: Reglas de transición de estados por rol.
- `GET /api/statuses`: Catálogo de estados.
- `GET /api/cities`: Ciudades.
- `GET /api/provinces`: Provincias.
- `GET /api/banks`: Bancos.
- `GET /api/currency`: Tasas de cambio (BCV, Binance).

---

## 13. Gestión de Tiendas y Horarios

- `GET /api/shops`: Listado de tiendas Shopify.
- `POST /api/shops/{id}/assign-sellers`: Vincular vendedores a tienda.
- `GET /api/settings/business-hours`: Horario comercial.
- `POST /api/business/open` / `POST /api/business/close`: Abrir o cerrar despacho.

---

## 14. Webhooks y Pruebas

- `POST /api/order/webhook/{shop_id?}`: Integración Shopify.
- `POST /api/test/notifications`: Probar sistema de notificaciones.

---

## Notas Técnicas
- **Autenticación:** Sanctum Bearer Token.
- **Headers:** `Accept: application/json`.
- **Formato Fecha:** `YYYY-MM-DD`.
- **Entorno:** Requiere HTTPS en producción.
