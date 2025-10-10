# 🏭 Boundless Warehouse Management System (BWMS)

## 📦 Description

**BWMS** (Boundless Warehouse Management System) is a comprehensive, full-stack warehouse and inventory management system built with **Laravel** and **Vue.js**.  
It empowers businesses to manage their entire supply chain operations — from inventory tracking to order processing and advanced reporting — all in one integrated platform.

---

## ✨ Key Features

### 📊 Dashboard & Analytics

-   Real-time business metrics and KPIs
-   Sales and purchase order statistics
-   Inventory level monitoring
-   Low stock alerts and notifications
-   Visual charts and trend analysis

### 📦 Inventory Management

-   Multi-warehouse inventory tracking
-   Real-time stock level monitoring
-   Automatic stock allocation
-   Low stock threshold alerts
-   Product categorization and organization
-   Barcode/SKU support

### 🏢 Customer & Supplier Management

-   Comprehensive customer and supplier database
-   Contact and payment terms management
-   Credit limit tracking
-   Supplier order history

### 🛒 Sales Order Processing

-   Create and manage sales orders
-   Multi-item order support
-   Dynamic pricing and discounts
-   Tax and total calculations
-   Order status lifecycle (Draft, Pending, Fulfilled, Cancelled)
-   Customer order history and automatic stock deductions

### 📥 Purchase Order Management

-   Generate and manage purchase orders
-   Supplier order tracking
-   Partial receiving capability
-   Order status management
-   Purchase history and cost tracking
-   Automatic inventory updates upon receiving

### 🏭 Warehouse Operations

-   Multiple warehouse support
-   Warehouse-specific stock control
-   Stock transfers between warehouses
-   Location management and capacity tracking

### 📈 Advanced Reporting

-   Sales Reports: revenue, top customers, product performance
-   Purchase Reports: supplier analysis, purchase trends
-   Inventory Reports: stock valuation, warehouse distribution
-   Product Performance: best sellers, slow movers
-   Date range filtering and export (PDF/CSV)

### ⚙️ Settings & Configuration

-   Company and warehouse setup
-   Inventory settings (thresholds, negative stock)
-   Order configuration (prefixes, tax rates, payment terms)
-   Notification and user preference management

### 👤 User Management & Profile

-   Secure authentication with **Laravel Sanctum**
-   Role-based access control (RBAC)
-   User profile and password management
-   Avatar upload

---

## 🛠️ Technology Stack

### Backend

-   **Framework:** Laravel 11.x
-   **Authentication:** Laravel Sanctum
-   **Database:** MySQL
-   **API:** RESTful architecture
-   **Storage:** Laravel Storage for uploads

### Frontend

-   **Framework:** Vue.js 3 (Composition API)
-   **State Management:** Pinia
-   **Routing:** Vue Router
-   **HTTP Client:** Axios
-   **Styling:** Tailwind CSS
-   **Build Tool:** Vite

---

## 🚀 Installation

### Prerequisites

-   PHP ≥ 8.2
-   Composer
-   Node.js ≥ 18.x
-   MySQL ≥ 8.0
-   npm or yarn

---

### ⚙️ Setup Instructions

#### 1️⃣ Clone the Repository

```bash
git clone https://github.com/yourusername/bwms.git
cd bwms

2️⃣ Install PHP Dependencies
composer install

3️⃣ Install Node Dependencies
npm install

4️⃣ Configure Environment
cp .env.example .env
php artisan key:generate


Edit your .env file:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bwms
DB_USERNAME=root
DB_PASSWORD=your_password

5️⃣ Run Migrations & Seeders
php artisan migrate
php artisan db:seed

6️⃣ Create Storage Link
php artisan storage:link

7️⃣ Build Frontend Assets
npm run build
# or for development
npm run dev

8️⃣ Start the Server
php artisan serve


Access the application at:
👉 http://127.0.0.1:8000

🔑 Default Login Credentials
Email: admin@bwms.com
Password: password

📱 Features Overview
Inventory Tracking

Real-time multi-warehouse stock monitoring

Automatic stock allocation

Low stock notifications

Stock movement history

Order Management

Complete order lifecycle control

Inventory sync with sales and purchase orders

Order fulfillment tracking

Multi-payment term support

Multi-Warehouse Support

Warehouse-specific inventory levels

Inter-warehouse transfers

Location-based tracking and reports

Comprehensive Reporting

Custom date filters

Export to CSV/PDF

Real-time analytics and charts

🗂️ Project Structure
bwms/
├── app/
│   ├── Http/Controllers/Api/
│   ├── Models/
│   └── Services/
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── js/
│   │   ├── components/
│   │   ├── views/
│   │   ├── stores/
│   │   ├── services/
│   │   └── router/
│   └── css/
├── routes/
│   ├── api.php
│   └── web.php
└── public/

🔐 Security Features

Laravel Sanctum authentication

CSRF protection

Password hashing with bcrypt

SQL injection prevention

XSS and input sanitization

Secure file upload handling

📊 Database Schema

Core Tables:

Users

Products

Categories

Customers

Suppliers

Warehouses

Inventory

Sales Orders & Items

Purchase Orders & Items

Settings

🤝 Contributing

Contributions are welcome! 🙌
Please follow these steps:

# 1. Fork the repository
# 2. Create a feature branch
git checkout -b feature/AmazingFeature

# 3. Commit your changes
git commit -m "Add some AmazingFeature"

# 4. Push the branch
git push origin feature/AmazingFeature

# 5. Open a Pull Request

📝 License

This project is open-source software licensed under the MIT License.

👨‍💻 Author

@nnamdielege

📍 Project Link: https://github.com/nnamdielege/BWMS

🙏 Acknowledgments

Laravel Framework

Vue.js Community

Tailwind CSS

All contributors and testers

🗺️ Roadmap

📱 Mobile App (React Native or Vue Native)

🧾 PDF Report Generation

📦 Barcode Scanning Integration

💬 Email Notifications

💹 Advanced Analytics Dashboard

💱 Multi-Currency Support

📘 API Documentation with Swagger

🧪 Automated Testing Suite

🐳 Docker Containerization

📞 Support

For help or inquiries:
📧 business@boundlessanalytics.com.au

or open an issue in the GitHub repository




```
