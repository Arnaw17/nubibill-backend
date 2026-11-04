<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure all system permissions are present in the permissions table
        $permissionsCatalog = [
            // Attendance
            ['id' => 'attendance.view', 'name' => 'View attendance', 'module' => 'attendance', 'action' => 'view', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],
            ['id' => 'attendance.manage', 'name' => 'Manage attendance', 'module' => 'attendance', 'action' => 'manage', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],
            ['id' => 'attendance.approve', 'name' => 'Approve attendance', 'module' => 'attendance', 'action' => 'approve', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],
            ['id' => 'attendance.enroll', 'name' => 'Enroll staff attendance', 'module' => 'attendance', 'action' => 'enroll', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],
            ['id' => 'attendance.revoke', 'name' => 'Revoke staff attendance', 'module' => 'attendance', 'action' => 'revoke', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],
            ['id' => 'staff_khata_charge', 'name' => 'Staff khata charge', 'module' => 'attendance', 'action' => 'charge', 'groupId' => 'staff_attendance', 'groupName' => 'Staff Attendance'],

            // Staff Salary Khata
            ['id' => 'staff_salary', 'name' => 'Staff Salary Khata Overview', 'module' => 'staff_salary', 'action' => 'view', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],
            ['id' => 'salary.view', 'name' => 'View salary khata', 'module' => 'salary', 'action' => 'view', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],
            ['id' => 'salary.manage', 'name' => 'Manage salary khata', 'module' => 'salary', 'action' => 'manage', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],
            ['id' => 'salary.approve', 'name' => 'Approve salary', 'module' => 'salary', 'action' => 'approve', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],
            ['id' => 'salary.pay', 'name' => 'Pay salary', 'module' => 'salary', 'action' => 'pay', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],
            ['id' => 'salary.report', 'name' => 'Salary reports', 'module' => 'salary', 'action' => 'report', 'groupId' => 'staff_salary', 'groupName' => 'Staff Salary Khata'],

            // Outlets
            ['id' => 'outlet.create', 'name' => 'Create outlets', 'module' => 'outlet', 'action' => 'create', 'groupId' => 'outlets', 'groupName' => 'Outlets'],
            ['id' => 'outlet.read', 'name' => 'View outlets', 'module' => 'outlet', 'action' => 'read', 'groupId' => 'outlets', 'groupName' => 'Outlets'],
            ['id' => 'outlet.update', 'name' => 'Update outlets', 'module' => 'outlet', 'action' => 'update', 'groupId' => 'outlets', 'groupName' => 'Outlets'],
            ['id' => 'outlet.delete', 'name' => 'Delete outlets', 'module' => 'outlet', 'action' => 'delete', 'groupId' => 'outlets', 'groupName' => 'Outlets'],

            // Customers
            ['id' => 'customer.create', 'name' => 'Create customers', 'module' => 'customer', 'action' => 'create', 'groupId' => 'customers', 'groupName' => 'Customers'],
            ['id' => 'customer.read', 'name' => 'View customers', 'module' => 'customer', 'action' => 'read', 'groupId' => 'customers', 'groupName' => 'Customers'],
            ['id' => 'customer.update', 'name' => 'Update customers', 'module' => 'customer', 'action' => 'update', 'groupId' => 'customers', 'groupName' => 'Customers'],
            ['id' => 'customer.delete', 'name' => 'Delete customers', 'module' => 'customer', 'action' => 'delete', 'groupId' => 'customers', 'groupName' => 'Customers'],

            // Employees
            ['id' => 'employee.create', 'name' => 'Create employees', 'module' => 'employee', 'action' => 'create', 'groupId' => 'employees', 'groupName' => 'Employees'],
            ['id' => 'employee.read', 'name' => 'View employees', 'module' => 'employee', 'action' => 'read', 'groupId' => 'employees', 'groupName' => 'Employees'],
            ['id' => 'employee.update', 'name' => 'Update employees', 'module' => 'employee', 'action' => 'update', 'groupId' => 'employees', 'groupName' => 'Employees'],
            ['id' => 'employee.delete', 'name' => 'Delete employees', 'module' => 'employee', 'action' => 'delete', 'groupId' => 'employees', 'groupName' => 'Employees'],

            // Godowns
            ['id' => 'godown.create', 'name' => 'Create godowns', 'module' => 'godown', 'action' => 'create', 'groupId' => 'godowns', 'groupName' => 'Godowns'],
            ['id' => 'godown.read', 'name' => 'View godowns', 'module' => 'godown', 'action' => 'read', 'groupId' => 'godowns', 'groupName' => 'Godowns'],
            ['id' => 'godown.update', 'name' => 'Update godowns', 'module' => 'godown', 'action' => 'update', 'groupId' => 'godowns', 'groupName' => 'Godowns'],
            ['id' => 'godown.delete', 'name' => 'Delete godowns', 'module' => 'godown', 'action' => 'delete', 'groupId' => 'godowns', 'groupName' => 'Godowns'],

            // Inventory
            ['id' => 'inventory.read', 'name' => 'Read inventory', 'module' => 'inventory', 'action' => 'read', 'groupId' => 'inventory', 'groupName' => 'Inventory'],
            ['id' => 'inventory.transfer', 'name' => 'Transfer inventory', 'module' => 'inventory', 'action' => 'transfer', 'groupId' => 'inventory', 'groupName' => 'Inventory'],
            ['id' => 'inventory.update', 'name' => 'Update inventory', 'module' => 'inventory', 'action' => 'update', 'groupId' => 'inventory', 'groupName' => 'Inventory'],
            ['id' => 'inventory.adjust', 'name' => 'Adjust inventory', 'module' => 'inventory', 'action' => 'adjust', 'groupId' => 'inventory', 'groupName' => 'Inventory'],

            // Payments
            ['id' => 'payment.create', 'name' => 'Create payments', 'module' => 'payment', 'action' => 'create', 'groupId' => 'payments', 'groupName' => 'Payments'],
            ['id' => 'payment.delete', 'name' => 'Delete payments', 'module' => 'payment', 'action' => 'delete', 'groupId' => 'payments', 'groupName' => 'Payments'],
            ['id' => 'payment.read', 'name' => 'View payments', 'module' => 'payment', 'action' => 'read', 'groupId' => 'payments', 'groupName' => 'Payments'],
            ['id' => 'payment.refund', 'name' => 'Refund payments', 'module' => 'payment', 'action' => 'refund', 'groupId' => 'payments', 'groupName' => 'Payments'],
            ['id' => 'payment.update', 'name' => 'Update payments', 'module' => 'payment', 'action' => 'update', 'groupId' => 'payments', 'groupName' => 'Payments'],

            // Products
            ['id' => 'product.create', 'name' => 'Create products', 'module' => 'product', 'action' => 'create', 'groupId' => 'products', 'groupName' => 'Products'],
            ['id' => 'product.delete', 'name' => 'Delete products', 'module' => 'product', 'action' => 'delete', 'groupId' => 'products', 'groupName' => 'Products'],
            ['id' => 'product.read', 'name' => 'View products', 'module' => 'product', 'action' => 'read', 'groupId' => 'products', 'groupName' => 'Products'],
            ['id' => 'product.update', 'name' => 'Update products', 'module' => 'product', 'action' => 'update', 'groupId' => 'products', 'groupName' => 'Products'],

            // Purchases
            ['id' => 'purchase', 'name' => 'Purchases Overview', 'module' => 'purchases', 'action' => 'view', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.create', 'name' => 'Create purchases', 'module' => 'purchase', 'action' => 'create', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.approve', 'name' => 'Approve purchases', 'module' => 'purchase', 'action' => 'approve', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.cancel', 'name' => 'Cancel purchases', 'module' => 'purchase', 'action' => 'cancel', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.delete', 'name' => 'Delete purchases', 'module' => 'purchase', 'action' => 'delete', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.read', 'name' => 'View purchases', 'module' => 'purchase', 'action' => 'read', 'groupId' => 'purchases', 'groupName' => 'Purchases'],
            ['id' => 'purchase.update', 'name' => 'Update purchases', 'module' => 'purchase', 'action' => 'update', 'groupId' => 'purchases', 'groupName' => 'Purchases'],

            // Reports
            ['id' => 'reports', 'name' => 'Reports Overview', 'module' => 'reports', 'action' => 'view', 'groupId' => 'reports', 'groupName' => 'Reports'],
            ['id' => 'reports.export', 'name' => 'Export reports', 'module' => 'reports', 'action' => 'export', 'groupId' => 'reports', 'groupName' => 'Reports'],
            ['id' => 'reports.read', 'name' => 'View reports', 'module' => 'reports', 'action' => 'read', 'groupId' => 'reports', 'groupName' => 'Reports'],
            ['id' => 'reports.print', 'name' => 'Print reports', 'module' => 'reports', 'action' => 'print', 'groupId' => 'reports', 'groupName' => 'Reports'],

            // Suppliers
            ['id' => 'suppliers', 'name' => 'Suppliers Overview', 'module' => 'suppliers', 'action' => 'view', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.create', 'name' => 'Create suppliers', 'module' => 'supplier', 'action' => 'create', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.delete', 'name' => 'Delete suppliers', 'module' => 'supplier', 'action' => 'delete', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.read', 'name' => 'View suppliers', 'module' => 'supplier', 'action' => 'read', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.update', 'name' => 'Update suppliers', 'module' => 'supplier', 'action' => 'update', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.reports', 'name' => 'Supplier reports', 'module' => 'supplier', 'action' => 'reports', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],
            ['id' => 'supplier.payment', 'name' => 'Supplier Payments & Ledger', 'module' => 'suppliers', 'action' => 'payment', 'groupId' => 'suppliers', 'groupName' => 'Suppliers'],

            // Expenses
            ['id' => 'expenses', 'name' => 'Expenses Overview', 'module' => 'expenses', 'action' => 'view', 'groupId' => 'expenses', 'groupName' => 'Expenses'],
            ['id' => 'expenses.view', 'name' => 'View Expenses', 'module' => 'expenses', 'action' => 'view', 'groupId' => 'expenses', 'groupName' => 'Expenses'],
            ['id' => 'expenses.create', 'name' => 'Create Expenses', 'module' => 'expenses', 'action' => 'create', 'groupId' => 'expenses', 'groupName' => 'Expenses'],
            ['id' => 'expenses.edit', 'name' => 'Edit Expenses', 'module' => 'expenses', 'action' => 'edit', 'groupId' => 'expenses', 'groupName' => 'Expenses'],
            ['id' => 'expenses.delete', 'name' => 'Delete Expenses', 'module' => 'expenses', 'action' => 'delete', 'groupId' => 'expenses', 'groupName' => 'Expenses'],

            // Roles
            ['id' => 'role.create', 'name' => 'Create roles', 'module' => 'role', 'action' => 'create', 'groupId' => 'roles', 'groupName' => 'Roles'],
            ['id' => 'role.delete', 'name' => 'Delete roles', 'module' => 'role', 'action' => 'delete', 'groupId' => 'roles', 'groupName' => 'Roles'],
            ['id' => 'role.read', 'name' => 'View roles', 'module' => 'role', 'action' => 'read', 'groupId' => 'roles', 'groupName' => 'Roles'],
            ['id' => 'role.update', 'name' => 'Update roles', 'module' => 'role', 'action' => 'update', 'groupId' => 'roles', 'groupName' => 'Roles'],

            // Sales & Billing
            ['id' => 'billing', 'name' => 'POS Billing', 'module' => 'sales', 'action' => 'billing', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.create', 'name' => 'Create sales', 'module' => 'sales', 'action' => 'create', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.delete', 'name' => 'Delete sales', 'module' => 'sales', 'action' => 'delete', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.discount', 'name' => 'Apply sales discount', 'module' => 'sales', 'action' => 'discount', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.print', 'name' => 'Print sales', 'module' => 'sales', 'action' => 'print', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.read', 'name' => 'View sales', 'module' => 'sales', 'action' => 'read', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.return', 'name' => 'Return sales', 'module' => 'sales', 'action' => 'return', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.share', 'name' => 'Share sales', 'module' => 'sales', 'action' => 'share', 'groupId' => 'sales', 'groupName' => 'Sales'],
            ['id' => 'sales.update', 'name' => 'Update sales', 'module' => 'sales', 'action' => 'update', 'groupId' => 'sales', 'groupName' => 'Sales'],

            // Stock Movement
            ['id' => 'stock_movement.create', 'name' => 'Create stock movements', 'module' => 'stock_movement', 'action' => 'create', 'groupId' => 'stock_movement', 'groupName' => 'Stock Movement'],
            ['id' => 'stock_movement.delete', 'name' => 'Delete stock movements', 'module' => 'stock_movement', 'action' => 'delete', 'groupId' => 'stock_movement', 'groupName' => 'Stock Movement'],
            ['id' => 'stock_movement.read', 'name' => 'View stock movements', 'module' => 'stock_movement', 'action' => 'read', 'groupId' => 'stock_movement', 'groupName' => 'Stock Movement'],
            ['id' => 'stock_movement.update', 'name' => 'Update stock movements', 'module' => 'stock_movement', 'action' => 'update', 'groupId' => 'stock_movement', 'groupName' => 'Stock Movement'],
        ];

        $allPermissionIds = [];
        foreach ($permissionsCatalog as $order => $perm) {
            $allPermissionIds[] = $perm['id'];
            $fields = [
                'permissionId' => $perm['id'],
                'permissionName' => $perm['name'],
                'name' => $perm['name'],
                'module' => $perm['module'],
                'action' => $perm['action'],
                'groupId' => $perm['groupId'],
                'groupName' => $perm['groupName'],
                'description' => $perm['name'],
                'displayOrder' => $order + 1,
                'status' => 'active',
                'isActive' => true,
                'isSystemPermission' => true,
            ];

            DB::table('permissions')->updateOrInsert(
                ['id' => $perm['id']],
                [
                    'document_path' => "permissions/{$perm['id']}",
                    'status' => 'active',
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => $fields]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // 2. Define the Complete Role Specifications
        $roles = [
            // 1. Owner (Super Admin) - Full Unrestricted Access
            [
                'id' => 'ROLE_OWNER',
                'name' => 'Owner',
                'description' => 'Super Admin: Full unrestricted access across all modules, businesses, outlets, and settings',
                'permissions' => $allPermissionIds,
            ],

            // 2. Manager (Store / Branch Manager) - Full operational access for assigned outlet(s), excluding global roles & tax settings
            [
                'id' => 'ROLE_MANAGER',
                'name' => 'Manager',
                'description' => 'Store / Branch Manager: Full operational access for assigned outlets, POS, inventory, purchases, suppliers, and reports',
                'permissions' => [
                    // Sales & POS
                    'billing', 'sales.create', 'sales.read', 'sales.update', 'sales.delete', 'sales.print', 'sales.share', 'sales.return', 'sales.discount',
                    // Customers
                    'customer.create', 'customer.read', 'customer.update', 'customer.delete',
                    // Products & Inventory
                    'product.create', 'product.read', 'product.update', 'product.delete',
                    'inventory.read', 'inventory.transfer', 'inventory.update', 'inventory.adjust',
                    'stock_movement.create', 'stock_movement.read', 'stock_movement.update', 'stock_movement.delete',
                    // Purchases & Suppliers
                    'purchase', 'purchase.create', 'purchase.read', 'purchase.update', 'purchase.delete', 'purchase.approve', 'purchase.cancel',
                    'suppliers', 'supplier.create', 'supplier.read', 'supplier.update', 'supplier.delete', 'supplier.reports', 'supplier.payment',
                    // Godowns
                    'godown.create', 'godown.read', 'godown.update', 'godown.delete',
                    // Payments & Expenses
                    'payment.create', 'payment.read', 'payment.update', 'payment.delete', 'payment.refund',
                    'expenses', 'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
                    // Staff & Attendance (read & attendance management)
                    'attendance.view', 'attendance.manage', 'attendance.approve', 'attendance.enroll', 'attendance.revoke', 'staff_khata_charge', 'employee.read',
                    // Reports (outlet level)
                    'reports', 'reports.read', 'reports.export', 'reports.print',
                    // Outlets (read assigned)
                    'outlet.read',
                ],
            ],

            // 3. Sales Manager - Sales, POS, customer relations, and sales analytics
            [
                'id' => 'ROLE_SALES_MANAGER',
                'name' => 'Sales Manager',
                'description' => 'Sales & POS Manager: Retail sales, customer khata, POS billing, returns, and sales analytics',
                'permissions' => [
                    'billing', 'sales.create', 'sales.read', 'sales.update', 'sales.delete', 'sales.print', 'sales.share', 'sales.return', 'sales.discount',
                    'customer.create', 'customer.read', 'customer.update', 'customer.delete',
                    'payment.create', 'payment.read', 'payment.update', 'payment.delete', 'payment.refund',
                    'product.read', 'inventory.read',
                    'reports.read', 'reports.print', 'reports.export',
                ],
            ],

            // 4. Purchase Manager - Procurement, supplier relations, and stock inflow
            [
                'id' => 'ROLE_PURCHASE_MANAGER',
                'name' => 'Purchase Manager',
                'description' => 'Procurement & Supplier Manager: Full CRUD on purchases, vendor profiles, ledgers, and stock inflow',
                'permissions' => [
                    'purchase', 'purchase.create', 'purchase.read', 'purchase.update', 'purchase.delete', 'purchase.approve', 'purchase.cancel',
                    'suppliers', 'supplier.create', 'supplier.read', 'supplier.update', 'supplier.delete', 'supplier.reports', 'supplier.payment',
                    'payment.create', 'payment.read', 'payment.update', 'payment.refund',
                    'inventory.read', 'stock_movement.read', 'stock_movement.create', 'product.read',
                ],
            ],

            // 5. Inventory Staff - Stock tracking, counts, and adjustments
            [
                'id' => 'ROLE_INVENTORY_STAFF',
                'name' => 'Inventory Staff',
                'description' => 'Inventory Staff: Stock counts, adjustments, godown transfers, and product catalogs',
                'permissions' => [
                    'inventory.read', 'inventory.transfer', 'inventory.update', 'inventory.adjust',
                    'stock_movement.read', 'stock_movement.create', 'stock_movement.update',
                    'product.read', 'godown.read',
                ],
            ],

            // 6. Godown Manager - Warehouse & godown logistics
            [
                'id' => 'ROLE_GODOWN_MANAGER',
                'name' => 'Godown Manager',
                'description' => 'Warehouse Logistics: Godown management, in/out tracking, audits, and incoming stock delivery',
                'permissions' => [
                    'godown.create', 'godown.read', 'godown.update', 'godown.delete',
                    'inventory.read', 'inventory.transfer', 'inventory.update', 'inventory.adjust',
                    'stock_movement.create', 'stock_movement.read', 'stock_movement.update', 'stock_movement.delete',
                    'product.read',
                ],
            ],

            // 7. Supplier Manager - Vendor and creditor accounts
            [
                'id' => 'ROLE_SUPPLIER_MANAGER',
                'name' => 'Supplier Manager',
                'description' => 'Supplier & Creditor Manager: Vendor CRUD, purchase history per supplier, and vendor payment khata',
                'permissions' => [
                    'suppliers', 'supplier.create', 'supplier.read', 'supplier.update', 'supplier.delete', 'supplier.reports', 'supplier.payment',
                    'purchase.read',
                    'payment.create', 'payment.read', 'payment.update', 'payment.refund',
                    'inventory.read',
                ],
            ],

            // 8. Reports Viewer - Read-only analytics and auditing
            [
                'id' => 'ROLE_REPORTS_VIEWER',
                'name' => 'Reports Viewer',
                'description' => 'Reports & Audit Viewer: Strictly read-only access to all business reports and registers',
                'permissions' => [
                    'reports', 'reports.read', 'reports.export', 'reports.print',
                    'sales.read', 'purchase.read', 'inventory.read', 'customer.read', 'supplier.read',
                    'stock_movement.read', 'godown.read', 'product.read', 'payment.read',
                ],
            ],

            // 9. Accountant - Financials, books, audit, purchases, expenses & payroll review
            [
                'id' => 'ROLE_ACCOUNTANT',
                'name' => 'Accountant',
                'description' => 'Accountant: Financial statements, purchase bills, payment ledgers, expenses, and payroll verification',
                'permissions' => [
                    'reports', 'reports.read', 'reports.export', 'reports.print',
                    'purchase', 'purchase.create', 'purchase.read', 'purchase.update', 'purchase.delete', 'purchase.approve', 'purchase.cancel',
                    'suppliers', 'supplier.create', 'supplier.read', 'supplier.update', 'supplier.delete', 'supplier.reports', 'supplier.payment',
                    'payment.create', 'payment.read', 'payment.update', 'payment.delete', 'payment.refund',
                    'expenses', 'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
                    'staff_salary', 'salary.view', 'salary.approve', 'salary.report',
                    'sales.read', 'sales.print', 'sales.share',
                    'customer.read', 'inventory.read', 'product.read', 'stock_movement.read',
                ],
            ],

            // 10. Billing Staff - POS counter billing and customer sales
            [
                'id' => 'ROLE_BILLING_STAFF',
                'name' => 'Billing Staff',
                'description' => 'Billing Staff: Counter POS billing, customer creation, sales returns, and cash/UPI collections',
                'permissions' => [
                    'billing', 'sales.create', 'sales.read', 'sales.print', 'sales.share', 'sales.return',
                    'payment.create', 'payment.read',
                    'customer.create', 'customer.read', 'customer.update',
                    'product.read', 'inventory.read',
                ],
            ],
        ];

        foreach ($roles as $order => $role) {
            $uniquePermissions = array_values(array_unique($role['permissions']));
            $fields = [
                'roleId' => $role['id'],
                'roleName' => $role['name'],
                'name' => $role['name'],
                'description' => $role['description'],
                'permissions' => $uniquePermissions,
                'permissionIds' => $uniquePermissions,
                'status' => 'active',
                'isActive' => true,
                'isSystemRole' => true,
                'displayOrder' => $order + 1,
            ];

            DB::table('roles')->updateOrInsert(
                ['id' => $role['id']],
                [
                    'document_path' => "roles/{$role['id']}",
                    'status' => 'active',
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => $fields]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Safe rollback if required
    }
};
