<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salary extends Model
{
    protected $table = 'salaries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'monthly_gross_salary' => 'float',
        'daily_rate' => 'float',
        'unpaid_leave_days' => 'float',
        'deduction_amount' => 'float',
        'unpaid_leave_deduction' => 'float',
        'other_deductions' => 'float',
        'bonus' => 'float',
        'overtime' => 'float',
        'net_payable' => 'float',
        'monthly_gross_salary_paise' => 'integer',
        'daily_rate_paise' => 'integer',
        'deduction_amount_paise' => 'integer',
        'unpaid_leave_deduction_paise' => 'integer',
        'other_deductions_paise' => 'integer',
        'bonus_paise' => 'integer',
        'overtime_paise' => 'integer',
        'net_payable_paise' => 'integer',
        'is_deleted' => 'boolean',
        'payload' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
