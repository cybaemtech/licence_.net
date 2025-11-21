# Overview

LicenseHub is an enterprise software license management system designed to track, monitor, and manage software licenses efficiently. It offers features like automated expiry notifications, multi-currency cost tracking, client management, and detailed reporting. The system aims to centralize software procurement, renewal cycles, and compliance tracking to streamline operations and enhance regulatory adherence, ultimately improving regulatory adherence and operational efficiency.

## Recent Updates (November 21, 2025)

### Permission-Based Access Control System (November 21, 2025)
- **Implemented Granular RBAC**: Users can now only access modules and perform actions (create, read, update, delete) based on their assigned permissions
- **Frontend Components**:
  - `src/contexts/PermissionContext.tsx` - Reactive permission state with server validation
  - `src/components/ProtectedRoute.tsx` - Route-level access control
  - Updated `src/components/Navbar.tsx` - Dynamic navigation based on permissions
  - `src/utils/permissionHelper.ts` - Permission checking utilities
- **Backend Components**:
  - `api/validate-session.php` - Session validation endpoint (fetches fresh permissions from DB)
  - `api/utils/PermissionHelper.php` - Server-side permission checking (already existed)
  - Controllers use `PermissionHelper::hasPermission()` for API endpoint protection
- **Security Features**:
  - Frontend validates permissions with backend on mount and every 5 minutes
  - Admin permission updates trigger immediate refresh via events
  - Storage key unified to `auth_session` for consistency
  - Removed unsafe fallback to default permissions
- **⚠️ IMPORTANT SECURITY LIMITATIONS** (Not Production-Ready):
  - ⚠️ **No Token Validation**: `api/utils/Auth.php` has hardcoded user ID, not token-based auth
  - ⚠️ **Session Validation Weakness**: `/api/validate-session.php` accepts userId from client without proper authentication
  - ⚠️ **Backend User Identification**: All API calls currently execute as hardcoded admin user
  - ⚠️ **Client-Side Tampering**: Users can still manipulate localStorage between validation intervals
- **📋 Production Requirements** (To Be Implemented):
  1. **Token-Based Authentication**: Implement JWT or server-side sessions with secure token storage
  2. **Session Management**: Create `sessions` table and validate tokens on every API call
  3. **Fix Backend Auth**: Replace `Auth::getUserId()` hardcoded logic with real user identification from tokens
  4. **API Middleware**: Add authentication middleware to all protected endpoints
  5. **Remove Client-Controlled IDs**: Backend should derive user from validated token, not accept userId from client
- **Current State**: Permission system works for UI/UX and demonstrates RBAC concept, but requires proper authentication infrastructure for production security

## Previous Updates (November 20, 2025)

### Document Upload Functionality for Clients & Vendors (November 20, 2025)
- **Implemented File Upload**: Added document upload capability to Clients and Vendors APIs
  - `api/clients.php` - Added multipart/form-data handling with secure file validation
  - `api/controllers/VendorsController.php` - Same secure upload implementation
- **Security Measures**:
  - Extension whitelist: pdf, doc, docx, xls, xlsx, jpg, jpeg, png
  - Multi-extension detection (rejects files like payload.php.jpg)
  - MIME type validation using finfo_file
  - Files stored in `public/uploads/clients/` and `public/uploads/vendors/`
  - .htaccess protection with RemoveHandler/RemoveType directives
- **Frontend Integration**: Eye icon appears in Actions column when document_path exists
- **Known Security Consideration**: For maximum security in public-facing deployments, consider implementing a PHP download controller to serve files from outside web root. Current implementation uses defense-in-depth approach suitable for internal enterprise environments.

### Critical Localhost Fix
- **Fixed "Database connection failed" Error**: Modified `api/config/database.php` to properly allow empty MySQL passwords (XAMPP default). Changed validation from `!$this->password` to `$this->password === false`, enabling localhost development with default XAMPP/WAMP/MAMP configurations while still detecting truly missing credentials.
- **Created LOCALHOST_MYSQL_SETUP.md**: Comprehensive step-by-step troubleshooting guide covering all common localhost setup issues, MySQL installation verification, .env file creation, database import, and error solutions.

### Replit Environment Setup
- Installed MySQL80 for Replit development environment
- Created automated MySQL setup scripts in `scripts/` directory:
  - `start_mysql.sh` - Starts MySQL server with proper initialization
  - `wait_for_mysql.sh` - Waits for MySQL to be ready
  - `bootstrap_db.sh` - Creates database and imports schema automatically
- Updated environment variable handling to support both Replit secrets and .env files

### Previous Updates
- **Fixed Purchased Quantity Tracking**: Added `purchased_quantity` column to `license_purchases` table to preserve original purchase amounts
- Created localhost troubleshooting tools (`test_localhost_env.php`, `check_database.php`, `fix_company_settings.php`)
- Created comprehensive guides: `LOCALHOST_FIX.md` and `DATABASE_IMPORT_GUIDE.md`

# User Preferences

Preferred communication style: Simple, everyday language.

# System Architecture

## Frontend Architecture
- **Framework**: React 18 with TypeScript.
- **Styling**: Tailwind CSS, supporting dark mode and responsive design.
- **State Management**: React Hooks.
- **Routing**: React Router DOM for SPA navigation.
- **Build Tool**: Vite.
- **Component Structure**: Modular, with reusable components.

## Backend Architecture
- **Runtime**: PHP 8.2+.
- **Architecture**: RESTful API with JSON responses.
- **Authentication**: Session-based with PHP sessions.
- **Database Driver**: PDO for MySQL interaction.
- **Email Service**: PHP native `mail()` function with custom SimpleMailService wrapper.

## Data Storage Solutions
- **Primary Database**: MySQL (cPanel hosted).
- **Key Tables**: `users`, `license_purchases`, `clients`, `currencies`, `email_notifications`, `notification_settings`, `vendors`, `tools`, `license_allocations`.

## Authentication & Authorization
- **Authentication**: Email/password with bcrypt hashing.
- **Session Management**: Basic session handling.
- **User Roles**: Supports Admin, Accounts, and User roles with CRUD permission controls.

## Notification System Architecture
- **Email Provider**: PHP native `mail()` function.
- **Trigger Points**: Configurable days before license expiry.
- **Delivery Method**: Professional HTML email templates with urgency-based color coding.
- **Recipients**: Client email addresses with automatic CC to admin.
- **Per-User Settings**: Notification preferences stored in `notification_settings` table.
- **Automated Scheduler**: Runs via cPanel cron job daily.
- **Configuration UI**: Web-based settings interface.
- **Duplicate Prevention**: Database-backed tracking prevents sending duplicate notifications.
- **Hybrid Email System**: Supports development mode (logs to files) and production mode (sends actual emails).

## UI/UX Decisions
- **Design Template**: Admin dashboard template.
- **Theming**: Dark mode support via Tailwind CSS.
- **Forms**: Multi-step license form, direct input fields for clients/vendors.
- **Currency Display**: Prioritizes INR display with real-time conversion; includes a dedicated currency management interface.
- **Dashboard Analytics**: Features a two-tab dashboard (Purchase and Sales Analytics) with 2-column grid layouts, donut charts, and enhanced KPI sections.
- **Table Enhancements**: License tables feature smart invoice sorting, reordered columns, and new purchase cost breakdown details in the selling table.
- **Loading Indicators**: Animated loading spinners with text during data fetching.

## Technical Implementations
- **Cross-platform Compatibility**: `cross-env` for environment variables.
- **Automated Client/Vendor Creation**: License form automatically creates client and vendor records if new.
- **Data Validation**: Robust validation for fields (e.g., GST Number, exchange rates).
- **Dynamic API URL Detection**: Uses `window.location.origin` for dynamic API URL detection.
- **Environment Variable Handling**: PHP `load_env.php` for securely loading environment variables from `.env` files.
- **UUID Support**: Database tables are configured to support UUIDs for primary keys.
- **Automatic Invoice Numbering**: Implemented an auto-incrementing invoice number system (e.g., CYB0001) shared across sales and purchases, with intelligent numeric sorting.
- **File Uploads**: Bill/invoice upload capability (PDF, JPG, PNG, DOC, DOCX up to 10MB) to license purchase forms with validation.

# External Dependencies

## Core Services
- **cPanel MySQL Database**: Primary database storage.
- **PHP 8.2+**: Backend runtime.
- **PHP mail()**: Native email sending.

## Development & Build Tools
- **Vite**: Frontend build tool and development server.
- **TypeScript**: For static type checking on frontend.
- **ESLint**: Code linting.
- **Tailwind CSS**: Utility-first CSS framework.
- **Concurrently**: For running PHP and React servers simultaneously.

## Frontend Libraries (React)
- **React**: v18.3.1 - UI library.
- **React Router DOM**: v6.22.2 - Client-side routing.
- **Recharts**: v2.12.2 - Data visualization.
- **Lucide React**: v0.344.0 - Icon library.
- **date-fns**: v3.3.1 - Date manipulation.