<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Manual CSV Export</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root{
            --primary:#8B0000;
            --secondary:#FF4136;
            --bg0:#000000;
            --bg1:#0b0b0c;
            --text:#FFFFFF;
            --muted:#B7BDC6;
            --border:rgba(255,255,255,0.10);
            --shadow: 0 18px 50px rgba(0,0,0,.55);
            --radius: 18px;
            --radius-sm: 14px;
            --focus: 0 0 0 4px rgba(255,65,54,.22);
            --gap: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: var(--text);
            background: radial-gradient(1200px 700px at 12% 0%, rgba(139,0,0,.30), transparent 60%),
                        radial-gradient(900px 500px at 88% 18%, rgba(255,65,54,.18), transparent 55%),
                        linear-gradient(135deg, #000, #0b0b0c 55%, #000);
            height: 100vh;
            overflow: hidden;
            padding: 22px 18px;
        }

        .container {
            max-width: 1060px;
            margin: 0 auto;
            height: calc(100vh - 44px);
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 0;
        }

        .top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand img {
            height: 46px;
            width: auto;
            display: block;
            filter: drop-shadow(0 10px 25px rgba(0,0,0,.45));
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .brand-text .app {
            font-weight: 900;
            letter-spacing: .2px;
            opacity: .95;
        }

        .brand-text .page {
            font-weight: 700;
            color: var(--muted);
            font-size: .92rem;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .secret-input {
            width: min(340px, 90vw);
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(0,0,0,0.45);
            color: var(--text);
            font-size: .95rem;
            font-family: inherit;
            outline: none;
            font-weight: 800;
            margin-right: 10px;
        }

        .secret-input:focus {
            border-color: rgba(255,65,54,.65);
            box-shadow: var(--focus);
        }

        .store-input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(0,0,0,.45);
            color: var(--text);
            font-weight: 800;
            font-size: .95rem;
            margin-bottom: 16px;
            transition: border 0.3s, box-shadow 0.3s;
        }

        .store-input:focus {
            border-color: rgba(255,65,54,.65);
            box-shadow: var(--focus);
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            flex: 1 1 auto;
            min-height: 0;
            display: grid;
            grid-template-rows: auto 1fr; /* header + scroll body */
        }

        .card-head {
            padding: 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(180deg, rgba(0,0,0,0.35), rgba(0,0,0,0.10));
        }

        h1 {
            font-size: 1.55rem;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        h1 i {
            color: var(--secondary);
        }

        .subtitle {
            color: var(--muted);
            margin-top: 4px;
            font-weight: 700;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            padding: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .full {
            grid-column: 1 / -1;
        }

        input[type="date"],
        select {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(0,0,0,.45);
            color: #fff;
            font-weight: 800;
            font-size: .95rem;
            transition: border 0.3s, box-shadow 0.3s;
        }

        input[type="date"]:focus,
        select:focus {
            border-color: rgba(255,65,54,.65);
            box-shadow: var(--focus);
        }

        button {
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.06);
            color: #fff;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            gap: 10px;
            align-items: center;
            text-decoration: none;
            transition: background 0.3s, transform 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
        }

        .btn-primary:hover {
            transform: scale(1.05);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.14);
        }

        .btn-secondary:hover {
            transform: scale(1.05);
            background: rgba(255,255,255,0.12);
        }

        .ui-lock {
            position: absolute;
            inset: 0;
            z-index: 9000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.88);
            backdrop-filter: blur(6px);
        }

        .ui-lock.hidden {
            display: none;
        }

        .lock-box {
            max-width: 520px;
            width: calc(100% - 36px);
            padding: 18px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(0,0,0,0.80);
            box-shadow: var(--shadow);
            text-align: center;
        }

        .lock-box h2 {
            font-size: 1.15rem;
            font-weight: 900;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }

        .lock-box h2 i {
            color: var(--secondary);
        }

        .lock-box p {
            color: var(--muted);
            font-weight: 800;
        }

        .lock-box .hint {
            margin-top: 10px;
            font-weight: 800;
            color: rgba(255,255,255,0.88);
        }

                /* Buttons (modern, flatter, no "glass") */
        .btn{
            padding: 12px 16px;
            border-radius: 14px;            /* less pill */
            border: 1px solid transparent;
            font-weight: 900;
            font-size: .98rem;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:10px;
            font-family: inherit;
            user-select:none;
            white-space:nowrap;
            transition: transform .12s ease, opacity .12s ease, border-color .12s ease, background .12s ease;
            text-decoration: none;
        }
        .btn:focus{ outline:none; box-shadow: var(--focus); }
        .btn:hover:not(:disabled){ transform: translateY(-1px); }
        .btn:active:not(:disabled){ transform: translateY(0px); }
                .btn-secondary{
            background: rgba(255,255,255,0.06);
            color:#fff;
            border-color: rgba(255,255,255,0.14);
        }
        .btn-secondary:hover:not(:disabled){
            background: rgba(255,255,255,0.09);
            border-color: rgba(255,255,255,0.18);
        }
    </style>
</head>

<body>

<div class="container">
    <div class="top">
        <div class="brand">
            <img src="{{ asset('rndlogo.png') }}" alt="R&D Logo">
            <div class="brand-text">
                <div class="app">R&amp;D</div>
                <div class="page">Manual CSV Export</div>
            </div>
        </div>

        <div class="top-actions">
            <a href="{{ route('manual.import.index') }}" class="btn btn-secondary">
                <i class="fas fa-file-export"></i> Manual Import
            </a>

            <input id="secretKeyInput" class="secret-input" type="password" placeholder="Enter Secret Key (X-Secret-Key)" autocomplete="off">
        </div>
    </div>

    <div class="card">
        <div id="uiLock" class="ui-lock">
            <div class="lock-box">
                <h2><i class="fas fa-lock"></i> Locked</h2>
                <p>Enter the secret key above to unlock export actions.</p>
                <div class="hint"><i class="fas fa-shield-halved" style="color:var(--secondary)"></i> The key is stored only for this tab session.</div>
            </div>
        </div>

        <div class="card-head">
            <h1><i class="fas fa-file-export"></i> Export Data</h1>
            <p class="subtitle">Select model, store, and date range for export.</p>
        </div>

        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Model</label>
                    <select id="model">
                        <!-- Add your model options here -->
                        <option value="detail_orders">Detail Orders</option>
                        <option value="order_line">Order Line</option>
                        <option value="detail_transactions">Detail Transactions</option>
                        <option value="summary_sales">Summary Sales</option>
                        <option value="summary_items">Summary Items</option>
                        <option value="summary_transactions">Summary Transactions</option>
                        <option value="waste">Waste</option>
                        <option value="cash_management">Cash Management</option>
                        <option value="financial_views">Financial Views</option>
                        <option value="alta_inventory_waste">Alta Inventory Waste</option>
                        <option value="alta_inventory_ingredient_usage">Alta Inventory Ingredient Usage</option>
                        <option value="alta_inventory_ingredient_orders">Alta Inventory Ingredient Orders</option>
                        <option value="alta_inventory_cogs">Alta Inventory Cogs</option>
                        <option value="yearly_store_summary">Yearly Store Summary</option>
                        <option value="yearly_item_summary">Yearly Item Summary</option>
                        <option value="weekly_store_summary">Weekly Store Summary</option>
                        <option value="weekly_item_summary">Weekly Item Summary</option>
                        <option value="quarterly_store_summary">Quarterly Store Summary</option>
                        <option value="quarterly_item_summary">Quarterly Item Summary</option>
                        <option value="monthly_store_summary">Monthly Store Summary</option>
                        <option value="monthly_item_summary">Monthly Item Summary</option>
                        <option value="daily_store_summary">Daily Store Summary</option>
                        <option value="daily_item_summary">Daily Item Summary</option>
                        <option value="hourly_store_summary">Hourly Store Summary</option>
                        <option value="hourly_item_summary">Hourly Item Summary</option>
                        <option value="all">All Models</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Store (optional)</label>
                    <input id="store" class="store-input" type="text" placeholder="e.g. 03795">
                </div>

                <div class="form-group">
                    <label>Start Date</label>
                    <input id="start" type="date">
                </div>

                <div class="form-group">
                    <label>End Date</label>
                    <input id="end" type="date">
                </div>

                <div class="form-group full">
                    <button class="btn btn-primary" onclick="runExport()">
                        <i class="fas fa-download"></i> Download Export
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const STORAGE_KEY = 'manual_export_secret';
    const secretInput = document.getElementById('secretKeyInput');
    const uiLock = document.getElementById('uiLock');

    // restore
    const saved = sessionStorage.getItem(STORAGE_KEY);
    if (saved) {
        secretInput.value = saved;
        uiLock.classList.add('hidden');
    }

    secretInput.addEventListener('input', () => {
        const v = secretInput.value.trim();
        if (v) {
            sessionStorage.setItem(STORAGE_KEY, v);
            uiLock.classList.add('hidden');
        } else {
            sessionStorage.removeItem(STORAGE_KEY);
            uiLock.classList.remove('hidden');
        }
    });

    function runExport() {
        const secret = sessionStorage.getItem(STORAGE_KEY);
        if (!secret) return;

        const params = new URLSearchParams({
            model: document.getElementById('model').value,
            store: document.getElementById('store').value,
            start: document.getElementById('start').value,
            end: document.getElementById('end').value,
        });

        const url = `/api/export/csv?${params.toString()}`;

        fetch(url, {
            headers: { 'X-Secret-Key': secret }
        })
        .then(res => {
            if (!res.ok) throw new Error('Export failed');
            return res.blob();
        })
        .then(blob => {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = '';
            a.click();
            URL.revokeObjectURL(a.href);
        })
        .catch(err => alert(err.message));
    }
</script>

</body>
</html>
