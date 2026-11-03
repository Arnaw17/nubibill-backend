<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $groups = [
            [
                'id' => 'staff_attendance',
                'name' => 'Staff Attendance',
                'permissions' => [
                    ['id' => 'attendance.view', 'name' => 'View attendance'],
                    ['id' => 'attendance.manage', 'name' => 'Manage attendance'],
                    ['id' => 'attendance.approve', 'name' => 'Approve attendance'],
                    ['id' => 'attendance.enroll', 'name' => 'Enroll staff attendance'],
                    ['id' => 'attendance.revoke', 'name' => 'Revoke staff attendance'],
                    ['id' => 'staff_khata_charge', 'name' => 'Staff khata charge'],
                ],
            ],
            [
                'id' => 'staff_salary',
                'name' => 'Staff Salary Khata',
                'permissions' => [
                    ['id' => 'salary.view', 'name' => 'View salary khata'],
                    ['id' => 'salary.manage', 'name' => 'Manage salary khata'],
                    ['id' => 'salary.approve', 'name' => 'Approve salary'],
                    ['id' => 'salary.pay', 'name' => 'Pay salary'],
                    ['id' => 'salary.report', 'name' => 'Salary reports'],
                ],
            ],
            [
                'id' => 'outlets',
                'name' => 'Outlets',
                'permissions' => [
                    ['id' => 'outlet.create', 'name' => 'Create outlets'],
                    ['id' => 'outlet.read', 'name' => 'View outlets'],
                    ['id' => 'outlet.update', 'name' => 'Update outlets'],
                    ['id' => 'outlet.delete', 'name' => 'Delete outlets'],
                ],
            ],
            [
                'id' => 'customers',
                'name' => 'Customers',
                'permissions' => [
                    ['id' => 'customer.create', 'name' => 'Create customers'],
                    ['id' => 'customer.read', 'name' => 'View customers'],
                    ['id' => 'customer.update', 'name' => 'Update customers'],
                    ['id' => 'customer.delete', 'name' => 'Delete customers'],
                ],
            ],
            [
                'id' => 'employees',
                'name' => 'Employees',
                'permissions' => [
                    ['id' => 'employee.create', 'name' => 'Create employees'],
                    ['id' => 'employee.read', 'name' => 'View employees'],
                    ['id' => 'employee.update', 'name' => 'Update employees'],
                    ['id' => 'employee.delete', 'name' => 'Delete employees'],
                ],
            ],
            [
                'id' => 'godowns',
                'name' => 'Godowns',
                'permissions' => [
                    ['id' => 'godown.create', 'name' => 'Create godowns'],
                    ['id' => 'godown.read', 'name' => 'View godowns'],
                    ['id' => 'godown.update', 'name' => 'Update godowns'],
                    ['id' => 'godown.delete', 'name' => 'Delete godowns'],
                ],
            ],
            [
                'id' => 'inventory',
                'name' => 'Inventory',
                'permissions' => [
                    ['id' => 'inventory.read', 'name' => 'Read inventory'],
                    ['id' => 'inventory.transfer', 'name' => 'Transfer inventory'],
                    ['id' => 'inventory.update', 'name' => 'Update inventory'],
                    ['id' => 'inventory.adjust', 'name' => 'Adjust inventory'],
                ],
            ],
            [
                'id' => 'payments',
                'name' => 'Payments',
                'permissions' => [
                    ['id' => 'payment.create', 'name' => 'Create payments'],
                    ['id' => 'payment.delete', 'name' => 'Delete payments'],
                    ['id' => 'payment.read', 'name' => 'View payments'],
                    ['id' => 'payment.refund', 'name' => 'Refund payments'],
                    ['id' => 'payment.update', 'name' => 'Update payments'],
                ],
            ],
            [
                'id' => 'products',
                'name' => 'Products',
                'permissions' => [
                    ['id' => 'product.create', 'name' => 'Create products'],
                    ['id' => 'product.delete', 'name' => 'Delete products'],
                    ['id' => 'product.read', 'name' => 'View products'],
                    ['id' => 'product.update', 'name' => 'Update products'],
                ],
            ],
            [
                'id' => 'purchases',
                'name' => 'Purchases',
                'permissions' => [
                    ['id' => 'purchase.create', 'name' => 'Create purchases'],
                    ['id' => 'purchase.approve', 'name' => 'Approve purchases'],
                    ['id' => 'purchase.cancel', 'name' => 'Cancel purchases'],
                    ['id' => 'purchase.delete', 'name' => 'Delete purchases'],
                    ['id' => 'purchase.read', 'name' => 'View purchases'],
                    ['id' => 'purchase.update', 'name' => 'Update purchases'],
                ],
            ],
            [
                'id' => 'reports',
                'name' => 'Reports',
                'permissions' => [
                    ['id' => 'reports.export', 'name' => 'Export reports'],
                    ['id' => 'reports.read', 'name' => 'View reports'],
                    ['id' => 'reports.print', 'name' => 'Print reports'],
                ],
            ],
            [
                'id' => 'suppliers',
                'name' => 'Suppliers',
                'permissions' => [
                    ['id' => 'supplier.create', 'name' => 'Create suppliers'],
                    ['id' => 'supplier.delete', 'name' => 'Delete suppliers'],
                    ['id' => 'supplier.read', 'name' => 'View suppliers'],
                    ['id' => 'supplier.update', 'name' => 'Update suppliers'],
                    ['id' => 'supplier.reports', 'name' => 'Supplier reports'],
                ],
            ],
            [
                'id' => 'roles',
                'name' => 'Roles',
                'permissions' => [
                    ['id' => 'role.create', 'name' => 'Create roles'],
                    ['id' => 'role.delete', 'name' => 'Delete roles'],
                    ['id' => 'role.read', 'name' => 'View roles'],
                    ['id' => 'role.update', 'name' => 'Update roles'],
                ],
            ],
            [
                'id' => 'sales',
                'name' => 'Sales',
                'permissions' => [
                    ['id' => 'sales.create', 'name' => 'Create sales'],
                    ['id' => 'sales.delete', 'name' => 'Delete sales'],
                    ['id' => 'sales.discount', 'name' => 'Apply sales discount'],
                    ['id' => 'sales.print', 'name' => 'Print sales'],
                    ['id' => 'sales.read', 'name' => 'View sales'],
                    ['id' => 'sales.return', 'name' => 'Return sales'],
                    ['id' => 'sales.share', 'name' => 'Share sales'],
                    ['id' => 'sales.update', 'name' => 'Update sales'],
                ],
            ],
            [
                'id' => 'stock_movement',
                'name' => 'Stock Movement',
                'permissions' => [
                    ['id' => 'stock_movement.create', 'name' => 'Create stock movements'],
                    ['id' => 'stock_movement.delete', 'name' => 'Delete stock movements'],
                    ['id' => 'stock_movement.read', 'name' => 'View stock movements'],
                    ['id' => 'stock_movement.update', 'name' => 'Update stock movements'],
                ],
            ],
        ];

        $allPermissionIds = [];
        foreach ($groups as $group) {
            foreach ($group['permissions'] as $permission) {
                $allPermissionIds[] = $permission['id'];
                $parts = explode('.', $permission['id'], 2);
                $fields = [
                    'permissionId' => $permission['id'],
                    'permissionName' => $permission['name'],
                    'name' => $permission['name'],
                    'module' => $parts[0],
                    'action' => $parts[1] ?? null,
                    'groupId' => $group['id'],
                    'groupName' => $group['name'],
                    'description' => $permission['name'],
                    'displayOrder' => count($allPermissionIds),
                    'status' => 'active',
                    'isActive' => true,
                    'isSystemPermission' => true,
                ];

                DB::table('permissions')->updateOrInsert(
                    ['id' => $permission['id']],
                    [
                        'document_path' => "permissions/{$permission['id']}",
                        'status' => 'active',
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => $fields]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        $byModule = static fn (string $module): array => array_values(array_filter(
            $allPermissionIds,
            static fn (string $permission): bool => str_starts_with($permission, "{$module}."),
        ));
        $roles = [
            ['id' => 'ROLE_ACCOUNTANT', 'name' => 'Accountant', 'permissions' => array_values(array_unique(array_merge(
                // Reports & Financials
                $byModule('reports'), ['reports', 'reports.read', 'reports.export', 'reports.print'],
                // Purchases & Suppliers (Full CRUD)
                $byModule('purchase'), $byModule('supplier'), ['suppliers', 'supplier.payment'],
                // Payments & Ledgers (Cash, Bank, UPI, Expenses)
                $byModule('payment'), ['expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete', 'expenses'],
                // Staff Salary / Payroll (View, Review, Approve, Report)
                ['staff_salary', 'salary.view', 'salary.approve', 'salary.report', 'SALARY_VIEW', 'SALARY_APPROVE', 'SALARY_REPORT'],
                // Sales & Customers (Audit Access Only)
                ['sales.read', 'sales.print', 'sales.share', 'customer.read', 'inventory.read', 'product.read', 'stock_movement.read'],
            )))],
            ['id' => 'ROLE_BILLING_STAFF', 'name' => 'Billing Staff', 'permissions' => array_values(array_unique(array_merge(
                ['billing', 'sales.create', 'sales.read', 'sales.print', 'sales.share', 'sales.return', 'payment.create', 'payment.read',
                    'customer.create', 'customer.read', 'customer.update', 'product.read', 'inventory.read'],
            )))],
            ['id' => 'ROLE_GODOWN_MANAGER', 'name' => 'Godown Manager', 'permissions' => array_values(array_unique(array_merge(
                $byModule('godown'), $byModule('inventory'), $byModule('stock_movement'),
            )))],
            ['id' => 'ROLE_INVENTORY_STAFF', 'name' => 'Inventory Staff', 'permissions' => array_values(array_unique(array_merge(
                $byModule('inventory'), $byModule('stock_movement'), ['product.read', 'godown.read'],
            )))],
            ['id' => 'ROLE_MANAGER', 'name' => 'Manager', 'permissions' => $allPermissionIds],
            ['id' => 'ROLE_OWNER', 'name' => 'Owner', 'permissions' => $allPermissionIds],
            ['id' => 'ROLE_PURCHASE_MANAGER', 'name' => 'Purchase Manager', 'permissions' => array_values(array_unique(array_merge(
                $byModule('purchase'), $byModule('supplier'), ['payment.create', 'payment.read', 'inventory.read', 'stock_movement.read'],
            )))],
            ['id' => 'ROLE_REPORTS_VIEWER', 'name' => 'Reports Viewer', 'permissions' => array_values(array_unique(array_merge(
                $byModule('reports'), ['sales.read', 'purchase.read', 'inventory.read', 'customer.read', 'supplier.read'],
            )))],
            ['id' => 'ROLE_SALES_MANAGER', 'name' => 'Sales Manager', 'permissions' => array_values(array_unique(array_merge(
                $byModule('sales'), $byModule('customer'), $byModule('payment'), ['product.read', 'inventory.read'],
            )))],
            ['id' => 'ROLE_SUPPLIER_MANAGER', 'name' => 'Supplier Manager', 'permissions' => array_values(array_unique(array_merge(
                $byModule('supplier'), $byModule('purchase'), $byModule('payment'), ['inventory.read'],
            )))],
        ];

        foreach ($roles as $order => $role) {
            $fields = [
                'roleId' => $role['id'],
                'roleName' => $role['name'],
                'name' => $role['name'],
                'description' => "System role: {$role['name']}",
                'permissions' => $role['permissions'],
                'permissionIds' => $role['permissions'],
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
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('id', [
            'ROLE_ACCOUNTANT', 'ROLE_BILLING_STAFF', 'ROLE_GODOWN_MANAGER', 'ROLE_INVENTORY_STAFF',
            'ROLE_MANAGER', 'ROLE_OWNER', 'ROLE_PURCHASE_MANAGER', 'ROLE_REPORTS_VIEWER',
            'ROLE_SALES_MANAGER', 'ROLE_SUPPLIER_MANAGER',
        ])->delete();

        DB::table('permissions')->whereIn('id', [
            'attendance.view', 'attendance.manage', 'attendance.approve', 'attendance.enroll', 'attendance.revoke',
            'staff_khata_charge', 'salary.view', 'salary.manage', 'salary.approve', 'salary.pay', 'salary.report',
            'outlet.create', 'outlet.read', 'outlet.update', 'outlet.delete', 'customer.create', 'customer.read',
            'customer.update', 'customer.delete', 'employee.create', 'employee.read', 'employee.update', 'employee.delete',
            'godown.create', 'godown.read', 'godown.update', 'godown.delete', 'inventory.read', 'inventory.transfer',
            'inventory.update', 'inventory.adjust', 'payment.create', 'payment.delete', 'payment.read', 'payment.refund',
            'payment.update', 'product.create', 'product.delete', 'product.read', 'product.update', 'purchase.create',
            'purchase.approve', 'purchase.cancel', 'purchase.delete', 'purchase.read', 'purchase.update', 'reports.export',
            'reports.read', 'reports.print', 'supplier.create', 'supplier.delete', 'supplier.read', 'supplier.update',
            'supplier.reports', 'role.create', 'role.delete', 'role.read', 'role.update', 'sales.create', 'sales.delete',
            'sales.discount', 'sales.print', 'sales.read', 'sales.return', 'sales.share', 'sales.update',
            'stock_movement.create', 'stock_movement.delete', 'stock_movement.read', 'stock_movement.update',
        ])->delete();
    }
};
