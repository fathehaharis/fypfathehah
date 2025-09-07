# TimeLess Car Rental System

A comprehensive web-based application for managing an online car rental service. This system facilitates customer bookings, administrative management, delivery staff operations, and payment processing, providing an end-to-end solution for a modern car rental business.

## ✨ Features

- **Role-Based User Access:** Three distinct portals for Customers, Administrators, and Delivery Staff.
- **Customer Functions:** User registration & profile verification, car browsing, booking management, online payment processing, and rental history.
- **Admin Dashboard:** Comprehensive overview for managing user profiles, verifying customer documents, managing car inventory, approving bookings, processing refunds, and generating financial reports.
- **Delivery Staff Portal:** View assigned pick-up/drop-off tasks, perform vehicle inspection checks, and update job statuses.
- **Dynamic Reporting:** Automated generation of reports for daily/monthly/yearly income and popular car models.

## 🛠 Technology Stack

| Component | Technology |
| :--- | :--- |
| **Frontend** | HTML5, CSS3, JavaScript, Bootstrap 5 |
| **Backend** | PHP |
| **Database** | MySQL |
| **Server** | XAMPP / WAMP Stack |
| **Version Control** | Git, GitHub |

## 🛠 Installation Guide

Follow these steps to set up the project locally for development and testing.

### Prerequisites

1.  **XAMPP/WAMP:** Ensure you have XAMPP or WAMP installed on your system.
2.  **Web Browser:** Any modern browser like Chrome, Firefox, etc.

### Step-by-Step Setup

1.  **Start Services:**
    - Launch your XAMPP/WAMP control panel.
    - Start the **Apache** and **MySQL** modules.

2.  **Obtain Source Code:**
    - Download the project ZIP and extract it, or clone the repository.
    - Move the entire project folder (e.g., `TimeLess-Car-Rental`) into your server's root directory.
        - **XAMPP:** `C:\xampp\htdocs\`
        - **WAMP:** `C:\wamp64\www\`

3.  **Database Configuration:**
    - Open phpMyAdmin via `http://localhost/phpmyadmin`.
    - Create a new database named `timelesscarrental`.
    - Click on the **"Import"** tab.
    - Choose the SQL file from the project's `database/` folder (`timelesscarrental.sql`).
    - Click **"Go"** to import the database structure and sample data.

4.  **Configure Connection:**
    - Locate the `connect.php` file 
    - Update the database credentials if necessary (default XAMPP/WAMP user is `root` with no password).
    ```php
    define('DB_SERVER', 'localhost');
    define('DB_USERNAME', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'timelesscarrental');
    ```

5.  **Run the Application:**
    - Open your browser and go to 
    - customer login and registration; `http://localhost:3000/index.php`.
    - admin login; `http://localhost:3000/admin/admin_login.php`
    - Delivery staff login; `http://localhost:3000/staff/delivery_staff_login.php`


## 📦 Database Configuration

The database schema and sample data are contained in `/database/timelesscarrental.sql`. This file creates all tables (Users, Cars, Bookings, Payments, etc.) and populates them with initial data for testing.

## 📝 Usage

1.  **Register** as a new customer on the homepage.
2.  **Log in** and complete your profile (requires admin verification).
3.  **Browse** available cars from the catalog.
4.  **Create a booking** by selecting dates, providing guarantor details, and signing the rental agreement.
5.  An **Admin** user must approve the booking before payment.
6.  **Make a payment** through the integrated payment flow.
7.  **Admin** can assign a Delivery Staff member, who will perform the vehicle inspection.
8.  After the rental period, the process is completed with a return inspection and any applicable refunds.

**Test Credentials:**
- **Admin Panel:** `http://localhost:3000/admin/admin_login.php`
  - Username: `afaizal`
  - Password: `faizal123`
- **Customer Login:** register a new account.

## 📜 License

This project was developed for academic purposes as part of a final year project. All source code is provided as-is.