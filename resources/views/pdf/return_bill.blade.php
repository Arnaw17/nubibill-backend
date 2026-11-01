<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $return->type === 'sale_return' ? 'Credit Note' : 'Debit Note' }} - {{ $return->return_no }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 11px;
            line-height: 1.4;
            margin: 0;
            padding: 15px;
        }
        .header-table, .meta-table, .items-table, .tax-table, .footer-table {
            width: 100%;
            border-collapse: collapse;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        
        .header-box {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .doc-title {
            font-size: 18px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-subtitle {
            font-size: 10px;
            color: #64748b;
        }
        
        .meta-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px;
            margin-bottom: 12px;
        }
        .meta-box td {
            vertical-align: top;
            padding: 3px 5px;
        }
        
        .items-table {
            margin-bottom: 15px;
            border: 1px solid #cbd5e1;
        }
        .items-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 10px;
            font-weight: bold;
            padding: 6px 4px;
            border-bottom: 1px solid #cbd5e1;
            border-right: 1px solid #e2e8f0;
        }
        .items-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            font-size: 10px;
        }
        .items-table tr:nth-child(even) td {
            background-color: #fcfcfd;
        }
        
        .total-row td {
            font-weight: bold;
            background-color: #f8fafc !important;
            border-top: 1px solid #cbd5e1;
        }
        .grand-total-box {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: bold;
            color: #1e40af;
            text-align: right;
            margin-top: 5px;
            border-radius: 4px;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-sale { background-color: #dbeafe; color: #1e40af; }
        .badge-purchase { background-color: #fef3c7; color: #92400e; }
        .badge-cancelled { background-color: #fee2e2; color: #991b1b; }
        
        .footer-note {
            margin-top: 25px;
            font-size: 9px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header-box">
        <table class="header-table">
            <tr>
                <td style="width: 60%;">
                    <div class="doc-title">{{ $return->type === 'sale_return' ? 'CREDIT NOTE' : 'DEBIT NOTE' }}</div>
                    <div class="doc-subtitle">Issued under Section 34 of CGST Act, 2017</div>
                    @if($return->isCancelled())
                        <div style="margin-top: 4px;"><span class="badge badge-cancelled">CANCELLED / REVERSED</span></div>
                    @endif
                </td>
                <td style="width: 40%;" class="text-right">
                    <span class="badge {{ $return->type === 'sale_return' ? 'badge-sale' : 'badge-purchase' }}">
                        {{ $return->type === 'sale_return' ? 'Sales Return' : 'Purchase Return' }}
                    </span>
                    <div style="font-size: 13px; font-weight: bold; color: #0f172a; margin-top: 4px;">{{ $return->return_no }}</div>
                    <div style="color: #475569;">Date: {{ $return->return_date ? \Carbon\Carbon::parse($return->return_date)->format('d M Y') : 'N/A' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Parties & Reference Information -->
    <div class="meta-box">
        <table class="meta-table">
            <tr>
                <td style="width: 50%;">
                    <div class="text-bold" style="color: #475569; text-transform: uppercase; font-size: 9px;">ISSUED BY:</div>
                    <div class="text-bold" style="font-size: 12px; color: #0f172a;">{{ $businessName ?? 'NubiBilling Store' }}</div>
                    @if(!empty($return->business_state_code))
                        <div>State Code: <strong>{{ $return->business_state_code }}</strong></div>
                    @endif
                    <div>Generated By: {{ $return->created_by ?? 'System' }}</div>
                </td>
                <td style="width: 50%;">
                    <div class="text-bold" style="color: #475569; text-transform: uppercase; font-size: 9px;">
                        {{ $return->type === 'sale_return' ? 'CUSTOMER (BILL TO):' : 'SUPPLIER (BILL FROM):' }}
                    </div>
                    <div class="text-bold" style="font-size: 12px; color: #0f172a;">{{ $return->party_name ?? 'Walk-in Party' }}</div>
                    @if(!empty($return->party_phone))
                        <div>Phone: {{ $return->party_phone }}</div>
                    @endif
                    @if(!empty($return->party_gstin))
                        <div>GSTIN: <strong>{{ $return->party_gstin }}</strong></div>
                    @endif
                    @if(!empty($return->party_state_code))
                        <div>State Code: <strong>{{ $return->party_state_code }}</strong></div>
                    @endif
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 6px; border-top: 1px dashed #cbd5e1;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 33%;">
                                <strong>Original Invoice No:</strong> {{ $return->original_bill_no ?? ($return->original_bill_id ?? 'None (Direct Return)') }}
                            </td>
                            <td style="width: 33%;">
                                <strong>Original Date:</strong> {{ $return->original_bill_date ? \Carbon\Carbon::parse($return->original_bill_date)->format('d M Y') : 'N/A' }}
                            </td>
                            <td style="width: 34%;" class="text-right">
                                <strong>Refund Mode:</strong> {{ strtoupper(str_replace('_', ' ', $return->refund_mode)) }}
                            </td>
                        </tr>
                        @if(!empty($return->reason_note) || !empty($return->reason))
                            <tr>
                                <td colspan="3" style="padding-top: 4px;">
                                    <strong>Return Reason:</strong> {{ $return->reason?->name ?? 'Standard Return' }}
                                    @if(!empty($return->reason_note))
                                        - <em>"{{ $return->reason_note }}"</em>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 32%;">Item Description</th>
                <th style="width: 10%;" class="text-center">HSN</th>
                <th style="width: 8%;" class="text-right">Qty</th>
                <th style="width: 10%;" class="text-right">Rate (₹)</th>
                <th style="width: 10%;" class="text-right">Taxable (₹)</th>
                <th style="width: 8%;" class="text-center">Tax %</th>
                <th style="width: 7%;" class="text-right">Tax (₹)</th>
                <th style="width: 10%;" class="text-right">Total (₹)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($return->items as $idx => $item)
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td>
                        <div class="text-bold">{{ $item->product_name }}</div>
                        <div style="font-size: 8px; color: #64748b;">
                            Condition: <strong>{{ ucfirst($item->condition) }}</strong> 
                            {{ $item->restock ? '(Restocked)' : '(Not restocked)' }}
                        </div>
                    </td>
                    <td class="text-center">{{ $item->hsn_code ?? '-' }}</td>
                    <td class="text-right">{{ number_format($item->qty_returned, 3) }} {{ $item->unit ?? '' }}</td>
                    <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format($item->taxable_amount, 2) }}</td>
                    <td class="text-center">{{ number_format($item->tax_rate, 1) }}%</td>
                    <td class="text-right">{{ number_format($item->tax_amount, 2) }}</td>
                    <td class="text-right text-bold">{{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5" class="text-right">TOTALS</td>
                <td class="text-right">{{ number_format($return->subtotal, 2) }}</td>
                <td></td>
                <td class="text-right">{{ number_format($return->tax_total, 2) }}</td>
                <td class="text-right">{{ number_format($return->grand_total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <!-- Tax Breakdown & Grand Total -->
    <table style="width: 100%;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <table style="width: 95%; border: 1px solid #e2e8f0; border-collapse: collapse; font-size: 9px;">
                    <tr style="background-color: #f8fafc;">
                        <th style="padding: 4px; border: 1px solid #e2e8f0; text-align: left;">Tax Component</th>
                        <th style="padding: 4px; border: 1px solid #e2e8f0; text-align: right;">Amount (₹)</th>
                    </tr>
                    @if($return->cgst_total > 0 || $return->sgst_total > 0)
                        <tr>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0;">Central GST (CGST)</td>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0; text-align: right;">{{ number_format($return->cgst_total, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0;">State GST (SGST)</td>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0; text-align: right;">{{ number_format($return->sgst_total, 2) }}</td>
                        </tr>
                    @endif
                    @if($return->igst_total > 0)
                        <tr>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0;">Integrated GST (IGST)</td>
                            <td style="padding: 3px 4px; border: 1px solid #e2e8f0; text-align: right;">{{ number_format($return->igst_total, 2) }}</td>
                        </tr>
                    @endif
                    <tr style="font-weight: bold; background-color: #f1f5f9;">
                        <td style="padding: 4px; border: 1px solid #e2e8f0;">Total GST Amount</td>
                        <td style="padding: 4px; border: 1px solid #e2e8f0; text-align: right;">₹ {{ number_format($return->tax_total, 2) }}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table style="width: 100%;">
                    <tr>
                        <td style="padding: 2px 0;">Subtotal (Taxable Value):</td>
                        <td class="text-right" style="padding: 2px 0;">₹ {{ number_format($return->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0;">Total Tax (GST):</td>
                        <td class="text-right" style="padding: 2px 0;">₹ {{ number_format($return->tax_total, 2) }}</td>
                    </tr>
                </table>
                <div class="grand-total-box">
                    Grand Total: ₹ {{ number_format($return->grand_total, 2) }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Footer Note -->
    <div class="footer-note">
        <table style="width: 100%;">
            <tr>
                <td style="width: 65%;">
                    * This {{ $return->type === 'sale_return' ? 'Credit Note' : 'Debit Note' }} adjusts the party ledger as per GST guidelines.<br/>
                    * This is a computer-generated document and does not require a physical signature.
                </td>
                <td style="width: 35%; text-align: right; vertical-align: bottom;">
                    <div style="border-top: 1px solid #94a3b8; display: inline-block; padding-top: 4px; width: 140px; text-align: center;">
                        Authorized Signatory
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
