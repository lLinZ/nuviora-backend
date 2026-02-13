# 🚀 Nuviora Backend (nuviora-backend)

![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-00000F?style=for-the-badge&logo=mysql&logoColor=white)
![Sanctum](https://img.shields.io/badge/Sanctum-Auth-blue?style=for-the-badge)

**Nuviora** is a robust order management and logistics ecosystem designed to streamline e-commerce operations. This repository contains the core API, business logic, and database management for the entire platform.

---

## 🌟 Key Features

### 📦 Order Lifecycle Management
- **Smart Routing:** Automated assignment of orders to regional agencies based on delivery cities.
- **Role-Based Workflows:** Strict status transition rules enforced for Admins, Sellers, Agencies, and Deliverers.
- **Kanban Driven:** Logic optimized for a dynamic Kanban board interface.

### 💰 Financial & Commission Engine
- **Multi-Method Payments:** Support for USD (Cash, Binance), Bolivares (Pago Móvil, Transfer), and Euros.
- **Automated Commissions:** Real-time calculation of seller earnings and agency settlements.
- **Exchange Rates:** Integration for daily BCV and Binance rates.

### 🚛 Logistics & Inventory
- **Real-Time Stock:** Multi-warehouse inventory tracking with automated deduction on delivery.
- **Deliverer Portal Stock:** Mobile-ready logic for drivers to "pick up" and "deliver" items physically.
- **Proof of Delivery:** Mandatory payment receipts and photo evidence for successful deliveries.

### 🔗 Integrations
- **Shopify Webhooks:** Global order syncing with automated customer creation.
- **Facebook CAPI:** Conversion event tracking for marketing optimization.

---

## 🛠 Tech Stack

- **Framework:** [Laravel 10+](https://laravel.com)
- **Language:** PHP 8.2+
- **Database:** MySQL / MariaDB
- **Auth:** Laravel Sanctum (Token-based)
- **Queue/Jobs:** Database drivers for automated reports and notifications.

---

## 🚀 Getting Started

### Prerequisites
- PHP 8.2+
- Composer
- MySQL 8.0+

### Installation

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd nuviora-backend
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Environment Setup:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database Configuration:**
   Configure your database credentials in the `.env` file and run:
   ```bash
   php artisan migrate --seed
   ```

5. **Serve the API:**
   ```bash
   php artisan serve
   ```

---

## 👥 Core Roles

| Role | Description |
| :--- | :--- |
| **Admin** | Full system control and financial overrides. |
| **Gerente** | Operational management and detailed reporting. |
| **Vendedor** | Order creation, upsells, and customer follow-up. |
| **Repartidor** | Route management and physical delivery confirmation. |
| **Agencia** | Inventory warehousing and regional distribution control. |

---

<p align="center">
  Developed with ❤️ by the Nuviora Team.
</p>
