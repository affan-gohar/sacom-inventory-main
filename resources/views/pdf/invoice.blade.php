<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
    .teal { color: #1F4E5A; } .orange { color: #E8832A; }
    table { width: 100%; border-collapse: collapse; }
    .brand { font-size: 34px; font-weight: bold; letter-spacing: 1px; line-height: 1; }
    .brand2 { font-size: 20px; font-weight: bold; letter-spacing: 2px; }
    .title { font-size: 26px; font-weight: bold; color: #4b5563; text-align: right; line-height: 1.1; }
    .meta td { padding: 1px 0; }
    .label { font-weight: bold; font-size: 11px; margin-bottom: 2px; }
    .stamp { display: inline-block; padding: 5px 10px; border: 1.5px solid; border-radius: 4px; font-weight: bold; font-size: 11px; }
    .stamp.paid { color: #15803d; border-color: #15803d; background: #f0fdf4; }
    .stamp.part { color: #b45309; border-color: #b45309; background: #fffbeb; }
    .stamp.unpaid { color: #b91c1c; border-color: #b91c1c; background: #fef2f2; }
    .items th { background: #555; color: #fff; padding: 7px 5px; font-size: 10px; border: 1px solid #555; }
    .items td { border: 1px solid #9ca3af; padding: 6px 5px; vertical-align: middle; }
    .r { text-align: right; } .c { text-align: center; }
    .pill { font-weight: bold; font-size: 9.5px; padding: 2px 5px; border-radius: 3px; display: inline-block; margin: 1px 0; }
    .p-paid { color: #15803d; background: #dcfce7; } .p-unpaid { color: #b91c1c; background: #fee2e2; }
    .p-ret { color: #374151; background: #e5e7eb; } .p-due { color: #b45309; background: #fef3c7; }
    .totals td { padding: 3px 5px; }
    .due td { background: #e5e7eb; border-top: 1.5px solid #6b7280; border-bottom: 1.5px solid #6b7280; font-weight: bold; font-size: 12.5px; padding: 7px 5px; }
    .muted { color: #6b7280; }
</style>
</head>
<body>
@php $rm = fn ($v) => number_format((float) $v, 2); $isT2 = $invoice->shop->type === \App\Enums\ShopType::Tier2; @endphp

<table>
    <tr>
        <td style="width:55%">
            <div class="brand teal">SACOM</div>
            <div class="brand2 orange">INVENTORY</div>
        </td>
        <td class="title">{{ $isT2 ? 'BRANCH' : 'CREDIT' }}<br>INVOICE</td>
    </tr>
</table>

<table style="margin-top:22px">
    <tr>
        <td style="width:55%; vertical-align:top">
            <div class="label">FROM:</div>
            <div style="font-weight:bold; font-size:12px">{{ $business['name'] }} (Main Shop)</div>
            @if($business['address'])<div>{{ $business['address'] }}</div>@endif
            @if($business['email'])<div>Email: {{ $business['email'] }}</div>@endif
            @if($business['phone'])<div>Phone: {{ $business['phone'] }}</div>@endif

            <div class="label" style="margin-top:14px">BILL TO:</div>
            <div style="font-weight:bold; font-size:12px">{{ $invoice->shop->name }} ({{ $isT2 ? 'Tier 2' : 'Tier 3' }})</div>
            <div>Store ID: {{ $invoice->shop->code }}</div>
            @if($invoice->shop->address)<div>{{ $invoice->shop->address }}</div>@endif
            @if($invoice->shop->phone)<div>Phone: {{ $invoice->shop->phone }}</div>@endif
        </td>
        <td style="vertical-align:top">
            <table class="meta">
                <tr><td class="r">Invoice No:</td><td class="r" style="font-weight:bold">{{ $invoice->number }}</td></tr>
                <tr><td class="r">Invoice Date:</td><td class="r">{{ $invoice->issued_at->format('d M Y') }}</td></tr>
                <tr><td class="r">Print Date:</td><td class="r">{{ $printedAt->format('d M Y, H:i') }}</td></tr>
                <tr><td class="r">Terms:</td><td class="r">{{ $isT2 ? 'Due when sold' : 'Month-end credit' }}</td></tr>
            </table>
            <div class="r" style="margin-top:16px">
                @if($status === 'Paid')
                    <span class="stamp paid">&#9679; PAID IN FULL</span>
                @elseif($status === 'Partially paid')
                    <span class="stamp part">&#9679; PARTIALLY PAID — BALANCE PENDING</span>
                @else
                    <span class="stamp unpaid">&#9679; UNPAID</span>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:18px">
    <thead>
        <tr>
            <th style="width:5%">No.</th>
            <th style="text-align:left">Item Description</th>
            <th style="width:7%">Qty</th>
            <th style="width:13%">Unit Price (RM)</th>
            <th style="width:14%">Total (RM)</th>
            <th style="width:22%">Payment Status</th>
        </tr>
    </thead>
    <tbody>
    @foreach($lines as $i => $line)
        @php $inShop = $line->qtyInShop(); $soldUnpaid = $line->qtySoldUnpaid(); @endphp
        <tr>
            <td class="c">{{ $i + 1 }}</td>
            <td>{{ $line->product->name }}</td>
            <td class="c">{{ $line->qty }}</td>
            <td class="r">{{ $rm($line->unit_price) }}</td>
            <td class="r">{{ $rm($line->qty * (float) $line->unit_price) }}</td>
            <td class="c">
                @if($line->qty_paid)<span class="pill p-paid">&#9679; {{ $line->qty_paid }} PAID</span><br>@endif
                @if($soldUnpaid)<span class="pill p-due">&#9679; {{ $soldUnpaid }} SOLD — DUE</span><br>@endif
                @if($inShop)<span class="pill p-unpaid">&#9679; {{ $inShop }} UNPAID</span><br><span class="muted" style="font-size:8.5px">(unsold in branch stock)</span><br>@endif
                @if($line->qty_returned)<span class="pill p-ret">{{ $line->qty_returned }} RETURNED</span>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top:14px">
    <tr>
        <td style="width:38%; vertical-align:top">
            @if($qr)
                <div style="text-align:center; width:150px; border:1px solid #d1d5db; border-radius:6px; padding:8px">
                    <img src="{{ $qr }}" style="width:130px; height:130px" alt="Payment QR">
                    <div style="font-size:9.5px; font-weight:bold; margin-top:4px">{{ $qrCaption ?: 'Scan to pay' }}</div>
                    <div class="muted" style="font-size:8.5px">Ref: {{ $invoice->number }}</div>
                </div>
            @endif
        </td>
        <td style="vertical-align:top">
            <table class="totals">
    <tr><td class="r">Total Items Transferred:</td><td class="r" style="width:22%">{{ $totals['units'] }} Units</td></tr>
    <tr><td class="r">Total Goods Value:</td><td class="r">RM {{ $rm($totals['goods']) }}</td></tr>
    @if((float) $totals['returned'] > 0)
    <tr><td class="r">Less: Returned to Main Shop:</td><td class="r">- RM {{ $rm($totals['returned']) }}</td></tr>
    @endif
    <tr><td class="r">Less: Paid / Cleared:</td><td class="r">- RM {{ $rm($totals['paid']) }}</td></tr>
    <tr class="due"><td class="r">Pending Balance Due (Unpaid Credit):</td><td class="r">RM {{ $rm($totals['due']) }}</td></tr>
</table>
        </td>
    </tr>
</table>

<table style="margin-top:60px">
    <tr>
        <td style="width:55%; vertical-align:bottom" class="muted">{{ $business['footer'] }}</td>
        <td class="c" style="vertical-align:bottom">
            <div style="border-top:1px dotted #374151; width:75%; margin:0 auto; padding-top:5px">Sign / Verified By</div>
        </td>
    </tr>
</table>
</body>
</html>
