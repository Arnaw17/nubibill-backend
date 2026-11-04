<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $additionalPermissions = [
            [
                'id' => 'billing',
                'name' => 'POS Billing',
                'module' => 'sales',
                'action' => 'billing',
                'groupId' => 'sales',
                'groupName' => 'Sales & Billing',
                'description' => 'POS counter sales and billing access',
            ],
            [
                'id' => 'reports',
                'name' => 'Reports Overview',
                'module' => 'reports',
                'action' => 'view',
                'groupId' => 'reports',
                'groupName' => 'Reports',
                'description' => 'View business reports overview',
            ],
            [
                'id' => 'purchase',
                'name' => 'Purchases Overview',
                'module' => 'purchases',
                'action' => 'view',
                'groupId' => 'purchases',
                'groupName' => 'Purchases',
                'description' => 'View purchases overview and bills',
            ],
            [
                'id' => 'suppliers',
                'name' => 'Suppliers Overview',
                'module' => 'suppliers',
                'action' => 'view',
                'groupId' => 'suppliers',
                'groupName' => 'Suppliers',
                'description' => 'View suppliers overview and vendors',
            ],
            [
                'id' => 'supplier.payment',
                'name' => 'Supplier Payments & Ledger',
                'module' => 'suppliers',
                'action' => 'payment',
                'groupId' => 'suppliers',
                'groupName' => 'Suppliers',
                'description' => 'Manage supplier payments and ledger settlements',
            ],
            [
                'id' => 'expenses',
                'name' => 'Expenses Overview',
                'module' => 'expenses',
                'action' => 'view',
                'groupId' => 'expenses',
                'groupName' => 'Expenses',
                'description' => 'View business expenses',
            ],
            [
                'id' => 'expenses.view',
                'name' => 'View Expenses',
                'module' => 'expenses',
                'action' => 'view',
                'groupId' => 'expenses',
                'groupName' => 'Expenses',
                'description' => 'View expense records',
            ],
            [
                'id' => 'expenses.create',
                'name' => 'Create Expenses',
                'module' => 'expenses',
                'action' => 'create',
                'groupId' => 'expenses',
                'groupName' => 'Expenses',
                'description' => 'Create new expense entries',
            ],
            [
                'id' => 'expenses.edit',
                'name' => 'Edit Expenses',
                'module' => 'expenses',
                'action' => 'edit',
                'groupId' => 'expenses',
                'groupName' => 'Expenses',
                'description' => 'Edit expense records',
            ],
            [
                'id' => 'expenses.delete',
                'name' => 'Delete Expenses',
                'module' => 'expenses',
                'action' => 'delete',
                'groupId' => 'expenses',
                'groupName' => 'Expenses',
                'description' => 'Delete expense records',
            ],
            [
                'id' => 'staff_salary',
                'name' => 'Staff Salary Khata Overview',
                'module' => 'staff_salary',
                'action' => 'view',
                'groupId' => 'staff_salary',
                'groupName' => 'Staff Salary Khata',
                'description' => 'Access staff salary khata overview',
            ],
        ];

        $order = DB::table('permissions')->count() + 1;
        foreach ($additionalPermissions as $perm) {
            $fields = [
                'permissionId' => $perm['id'],
                'permissionName' => $perm['name'],
                'name' => $perm['name'],
                'module' => $perm['module'],
                'action' => $perm['action'],
                'groupId' => $perm['groupId'],
                'groupName' => $perm['groupName'],
                'description' => $perm['description'],
                'displayOrder' => $order++,
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

        // Align ROLE_ACCOUNTANT and ROLE_BILLING_STAFF roles to have active permission keys
        $accountantPermissions = [
            'reports', 'reports.read', 'reports.export', 'reports.print',
            'purchase', 'purchase.create', 'purchase.read', 'purchase.update', 'purchase.delete', 'purchase.approve', 'purchase.cancel',
            'suppliers', 'supplier.create', 'supplier.read', 'supplier.update', 'supplier.delete', 'supplier.reports', 'supplier.payment',
            'payment.create', 'payment.read', 'payment.update', 'payment.delete', 'payment.refund',
            'expenses', 'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
            'staff_salary', 'salary.view', 'salary.approve', 'salary.report',
            'sales.read', 'sales.print', 'sales.share',
            'customer.read', 'inventory.read', 'product.read', 'stock_movement.read',
        ];

        $billingStaffPermissions = [
            'billing', 'sales.create', 'sales.read', 'sales.print', 'sales.share', 'sales.return',
            'payment.create', 'payment.read',
            'customer.create', 'customer.read', 'customer.update',
            'product.read', 'inventory.read',
        ];

        foreach ([
            'ROLE_ACCOUNTANT' => ['name' => 'Accountant', 'permissions' => $accountantPermissions],
            'ROLE_BILLING_STAFF' => ['name' => 'Billing Staff', 'permissions' => $billingStaffPermissions],
        ] as $roleId => $roleInfo) {
            $row = DB::table('roles')->where('id', $roleId)->first();
            $fields = [
                'roleId' => $roleId,
                'roleName' => $roleInfo['name'],
                'name' => $roleInfo['name'],
                'description' => "System role: {$roleInfo['name']}",
                'permissions' => $roleInfo['permissions'],
                'permissionIds' => $roleInfo['permissions'],
                'status' => 'active',
                'isActive' => true,
                'isSystemRole' => true,
                'displayOrder' => $roleId === 'ROLE_ACCOUNTANT' ? 1 : 2,
            ];

            DB::table('roles')->updateOrInsert(
                ['id' => $roleId],
                [
                    'document_path' => "roles/{$roleId}",
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
        DB::table('permissions')->whereIn('id', [
            'billing', 'reports', 'purchase', 'suppliers', 'supplier.payment',
            'expenses', 'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
            'staff_salary',
        ])->delete();
    }
};
