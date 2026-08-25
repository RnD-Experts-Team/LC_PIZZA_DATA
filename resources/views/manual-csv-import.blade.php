<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Manual CSV Import</title>

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
            --border2:rgba(255,255,255,0.14);

            --shadow: 0 18px 50px rgba(0,0,0,.55);

            --radius: 18px;
            --radius-sm: 14px;

            --focus: 0 0 0 4px rgba(255,65,54,.22);

            --success:#10b981;
            --danger:#ef4444;
            --warn:#f59e0b;

            --cardPad: 18px;
            --gap: 12px;
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; }

        body{
            font-family:'Inter',sans-serif;
            line-height:1.6;
            color:var(--text);
            background:
                radial-gradient(1200px 700px at 12% 0%, rgba(139,0,0,.30), transparent 60%),
                radial-gradient(900px 500px at 88% 18%, rgba(255,65,54,.18), transparent 55%),
                linear-gradient(135deg, #000, #0b0b0c 55%, #000);
            height:100vh;
            overflow:hidden;
            padding: 22px 18px;
        }

        .container{
            max-width: 1060px;
            margin: 0 auto;
            height: calc(100vh - 44px);
            display:flex;
            flex-direction:column;
            gap:14px;
            min-height:0;
        }

        /* Top bar */
        .top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex: 0 0 auto;
            flex-wrap: wrap;
        }

        .brand{
            display:flex;
            align-items:center;
            gap:12px;
        }
        .brand img{
            height: 46px;
            width:auto;
            display:block;
            filter: drop-shadow(0 10px 25px rgba(0,0,0,.45));
        }
        .brand-text{
            display:flex;
            flex-direction:column;
            gap:2px;
        }
        .brand-text .app{
            font-weight: 900;
            letter-spacing:.2px;
            opacity:.95;
        }
        .brand-text .page{
            font-weight: 700;
            color: var(--muted);
            font-size: .92rem;
        }

        /* ✅ NEW: top actions (nav + secret) */
        .top-actions{
            display:flex;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }
        .secret-input{
            width: min(340px, 90vw);
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(0,0,0,0.45);
            color: var(--text);
            font-size: .95rem;
            font-family: inherit;
            outline:none;
            font-weight: 800;
        }
        .secret-input:focus{
            border-color: rgba(255,65,54,.65);
            box-shadow: var(--focus);
        }

        /* Card */
        .card{
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position:relative;
            overflow:hidden;
            flex: 1 1 auto;
            min-height:0;
            display:grid;
            grid-template-rows: auto 1fr; /* header + scroll body */
        }
        .card::before{
            content:"";
            position:absolute;
            inset:-2px;
            background: radial-gradient(760px 260px at 20% -10%, rgba(255,65,54,.22), transparent 56%);
            pointer-events:none;
        }
        .card > *{ position:relative; }

        .card-head{
            padding: var(--cardPad);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(180deg, rgba(0,0,0,0.35), rgba(0,0,0,0.10));
        }

        h1{
            font-size: 1.55rem;
            font-weight: 900;
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom: 4px;
        }
        h1 i{ color: var(--secondary); }

        .subtitle{
            color: var(--muted);
            margin-top: 4px;
            font-weight: 700;
        }

        .chips{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top: 12px;
        }
        .chip{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.88);
            font-size: .9rem;
            font-weight: 700;
        }
        .chip i{ color: var(--secondary); }

        .card-body{
            padding: var(--cardPad);
            min-height:0;
            overflow:auto; /* only this area scrolls */
        }

        .card-body::-webkit-scrollbar{ width: 10px; }
        .card-body::-webkit-scrollbar-track{ background: transparent; }
        .card-body::-webkit-scrollbar-thumb{
            background: rgba(255,255,255,0.22);
            border-radius: 999px;
        }
        .card-body::-webkit-scrollbar-thumb:hover{
            background: rgba(255,255,255,0.34);
        }

        /* Upload zone */
        .upload-zone{
            border: 2px dashed rgba(255,255,255,0.18);
            border-radius: var(--radius);
            padding: 18px;
            text-align:center;
            background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(0,0,0,0.18));
            cursor:pointer;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease;
            position:relative;
            overflow:hidden;
            user-select:none;
        }
        .upload-zone::after{
            content:"";
            position:absolute;
            inset:0;
            background: radial-gradient(520px 220px at 50% 0%, rgba(255,65,54,.20), transparent 72%);
            opacity:.75;
            pointer-events:none;
        }
        .upload-zone:hover{
            transform: translateY(-2px);
            border-color: rgba(255,65,54,.55);
            box-shadow: 0 0 0 6px rgba(255,65,54,.10);
        }
        .upload-zone.drag-over{
            transform: scale(1.01);
            border-color: rgba(255,65,54,.85);
            box-shadow: 0 0 0 8px rgba(255,65,54,.12);
            background: linear-gradient(180deg, rgba(255,65,54,0.10), rgba(0,0,0,0.18));
        }

        .upload-icon{
            font-size: 2.6rem;
            color: var(--secondary);
            margin-bottom: 6px;
            animation: float 3s ease-in-out infinite;
            position:relative;
            z-index:1;
        }
        @keyframes float{
            0%,100%{ transform: translateY(0); }
            50%{ transform: translateY(-8px); }
        }
        .upload-zone h3{
            font-size: 1.05rem;
            font-weight: 900;
            margin-bottom: 4px;
            position:relative;
            z-index:1;
        }
        .upload-zone p{
            color: var(--muted);
            position:relative;
            z-index:1;
            font-weight: 700;
        }
        #fileInput{ display:none; }

        /* Loading */
        .loading{
            display:none;
            text-align:center;
            padding: 12px 0 0;
        }
        .loading.active{ display:block; }
        .loading-spinner{
            display:inline-block;
            width: 44px;
            height: 44px;
            border: 5px solid rgba(255,255,255,0.14);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin{ to{ transform: rotate(360deg); } }

        /* Section blocks */
        .block{
            margin-top: 14px;
            padding: 14px;
            border-radius: var(--radius-sm);
            border:1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
        }

        /* File list */
        .file-list{
            margin-top: 14px;
            display:flex;
            flex-direction:column;
            gap: 10px;
        }
        .file-list-head{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap: 12px;
            flex-wrap:wrap;
        }
        .file-list-head h4{
            font-size: 1rem;
            font-weight: 900;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .file-stats{
            color: var(--muted);
            font-weight: 800;
            font-size: .92rem;
        }

        /* Panel is grid: scrollable list + action bar */
        .file-panel{
            display:grid;
            grid-template-rows: 1fr auto;
            gap: 10px;
            min-height: 360px;
        }

        .files-scroll{
            overflow:auto;
            padding-right: 6px;
            border-radius: 16px;
        }

        .files-scroll::-webkit-scrollbar{ width: 8px; }
        .files-scroll::-webkit-scrollbar-track{ background: transparent; }
        .files-scroll::-webkit-scrollbar-thumb{
            background: rgba(255,255,255,0.22);
            border-radius: 999px;
        }
        .files-scroll::-webkit-scrollbar-thumb:hover{
            background: rgba(255,255,255,0.36);
        }

        .empty-state{
            padding: 18px;
            border-radius: 16px;
            border: 1px dashed rgba(255,255,255,0.16);
            background: rgba(0,0,0,0.28);
            color: rgba(255,255,255,0.86);
        }
        .empty-state .title{
            font-weight: 900;
            margin-bottom: 6px;
            display:flex;
            gap:10px;
            align-items:center;
        }
        .empty-state .title i{ color: var(--secondary); }
        .empty-state p{ color: var(--muted); font-weight: 700; }

        .file-item{
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 12px;
            display:grid;
            grid-template-columns: auto 1fr auto;
            gap: 14px;
            align-items:flex-start;
            transition: transform .18s ease, border-color .18s ease;
        }
        .file-item:hover{
            transform: translateY(-1px);
            border-color: rgba(255,65,54,.28);
        }

        .file-icon{
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size: 1.15rem;
            color: var(--secondary);
            background: rgba(255,65,54,.12);
            border: 1px solid rgba(255,65,54,.20);
            margin-top: 2px;
        }

        .file-info{
            min-width:0;
            display:flex;
            flex-direction:column;
            gap:10px;
        }
        .file-name{
            font-weight: 900;
            color: rgba(255,255,255,0.92);
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .file-name small{
            color: var(--muted);
            font-weight:800;
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

        .btn-primary{
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color:#fff;
            border-color: rgba(255,255,255,0.12);
            box-shadow: none;
        }
        .btn-primary:hover:not(:disabled){
            border-color: rgba(255,65,54,.45);
        }

        .btn-secondary{
            background: rgba(255,255,255,0.06);
            color:#fff;
            border-color: rgba(255,255,255,0.14);
        }
        .btn-secondary:hover:not(:disabled){
            background: rgba(255,255,255,0.09);
            border-color: rgba(255,255,255,0.18);
        }

        .btn-danger{
            background: rgba(239,68,68,0.92);
            color:#fff;
            border-color: rgba(255,255,255,0.10);
            box-shadow: none;
        }
        .btn-danger:hover:not(:disabled){
            border-color: rgba(239,68,68,0.55);
        }

        .btn:disabled{ opacity:.45; cursor:not-allowed; transform:none; }

        /* Action bar (no glass slab) */
        .file-actions{
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.10);
            background: transparent;
            border-radius: 0;
            padding: 10px 0 0;
        }
        .file-actions-inner{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        /* Custom Processor Dropdown */
        .proc-select{ position: relative; }

        .proc-trigger{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.40);
            cursor:pointer;
            font-weight: 900;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .proc-trigger:hover{ border-color: rgba(255,65,54,0.50); }
        .proc-trigger:focus{ outline:none; box-shadow: var(--focus); }
        .proc-trigger[aria-expanded="true"]{
            border-color: rgba(255,65,54,0.70);
            background: rgba(255,65,54,0.08);
        }

        .proc-label{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .proc-label i{
            color: var(--secondary);
            flex: 0 0 auto;
        }

        /* Processor menu overlays (fixed) and never affects layout */
        .proc-menu{
            position: fixed;
            background: #0b0b0c;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.80);
            max-height: 280px;
            overflow:auto;
            display:none;
            z-index: 9999;
            width: 360px; /* overridden by JS */
        }
        .proc-menu.open{ display:block; }

        /* ✅ MATCH SCROLLBAR TO FILE LIST (8px, same thumb/track) */
        .proc-menu::-webkit-scrollbar{ width: 8px; }
        .proc-menu::-webkit-scrollbar-track{ background: transparent; }
        .proc-menu::-webkit-scrollbar-thumb{
            background: rgba(255,255,255,0.22);
            border-radius: 999px;
        }
        .proc-menu::-webkit-scrollbar-thumb:hover{
            background: rgba(255,255,255,0.36);
        }

        .proc-option{
            display:flex;
            align-items:center;
            gap:14px;
            padding: 12px 14px;
            cursor:pointer;
            transition: background .12s ease;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .proc-option:last-child{ border-bottom:none; }
        .proc-option:hover{ background: rgba(255,65,54,0.12); }

        .proc-option i{
            color: var(--secondary);
            width: 18px;
            text-align:center;
            flex: 0 0 auto;
        }

        .proc-option .meta{
            display:flex;
            flex-direction:column;
            gap:2px;
            min-width:0;
            flex: 1;
        }
        .proc-option .meta strong{
            font-weight: 900;
            color: rgba(255,255,255,0.95);
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .proc-option .meta small{
            color: var(--muted);
            font-weight: 700;
            font-size: .86rem;
        }

        /* Selected mark */
        .proc-option .selected-mark{
            margin-left:auto;
            color: rgba(255,255,255,0.85);
            opacity: 0;
            transform: scale(0.95);
            transition: opacity .12s ease, transform .12s ease;
        }
        .proc-option.is-selected{
            background: rgba(255,65,54,0.10);
        }
        .proc-option.is-selected .selected-mark{
            opacity: 1;
            transform: scale(1);
        }

        /* Progress */
        .progress-section{
            margin-top: 14px;
            display:none;
            padding: 14px;
            border-radius: var(--radius-sm);
            border:1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
        }
        .progress-section.active{ display:block; }

        .progress-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap:wrap;
        }
        .progress-title{
            font-weight: 900;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .progress-title i{ color: var(--secondary); }
        .progress-stats{
            font-size: .92rem;
            color: var(--muted);
            font-weight: 800;
        }
        .progress-bar{
            width:100%;
            height: 22px;
            background: rgba(255,255,255,0.08);
            border-radius: 999px;
            overflow:hidden;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .progress-fill{
            height:100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width .45s ease;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-size: .85rem;
            font-weight: 900;
            text-shadow: 0 1px 0 rgba(0,0,0,0.2);
        }
        .progress-details{
            margin-top: 10px;
            padding: 12px;
            background: rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            font-size: .92rem;
            color: rgba(255,255,255,0.88);
        }
        .spinner{
            display:inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.20);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* Results */
        .results{ margin-top: 14px; }
        .results h4{
            font-size: 1rem;
            font-weight: 900;
            margin-bottom: 10px;
            display:flex;
            align-items:center;
            gap:10px;
        }

        .result-item{
            padding: 14px;
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            display:flex;
            align-items:flex-start;
            gap: 12px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
        }
        .result-item i{ margin-top: 2px; font-size: 1.1rem; }

        .result-item.success{
            border-color: rgba(16,185,129,0.35);
            background: rgba(16,185,129,0.10);
            color: #D1FAE5;
        }
        .result-item.success i{ color: #34D399; }

        .result-item.failed{
            border-color: rgba(239,68,68,0.35);
            background: rgba(239,68,68,0.10);
            color: #FEE2E2;
        }
        .result-item.failed i{ color: #F87171; }

        /* Aggregation */
        .aggregation-section{
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.10);
        }
        .aggregation-section h3{
            display:flex;
            align-items:center;
            gap:10px;
            font-size: 1.05rem;
            font-weight: 900;
            margin-bottom: 6px;
        }
        .aggregation-section h3 i{ color: var(--secondary); }

        label{
            display:block;
            font-weight: 900;
            margin-bottom: 8px;
            color: rgba(255,255,255,0.92);
        }
        input[type="date"], select#aggregationType{
            width:100%;
            padding: 12px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.38);
            color: var(--text);
            font-size: .95rem;
            font-family: inherit;
            outline:none;
        }
        input[type="date"]:focus, select#aggregationType:focus{
            border-color: rgba(255,65,54,.65);
            box-shadow: var(--focus);
        }
        select#aggregationType option{ color:#0b0b0b; }

        .form-group{ margin-bottom: 12px; }
        .date-inputs{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Toast */
        .toast{
            position: fixed;
            top: 18px;
            right: 18px;
            background: rgba(0,0,0,0.92);
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 18px 40px rgba(0,0,0,0.6);
            display:none;
            align-items:center;
            gap: 10px;
            z-index:1000;
            animation: slideIn .25s ease;
            max-width: min(520px, calc(100vw - 36px));
        }
        @keyframes slideIn{
            from{ transform: translateX(40px); opacity:0; }
            to{ transform: translateX(0); opacity:1; }
        }
        .toast.success{ border-left: 4px solid var(--success); }
        .toast.error{ border-left: 4px solid var(--danger); }
        .toast.show{ display:flex; }
        .toast span{ color: rgba(255,255,255,0.92); font-weight: 800; }

        @media (max-width: 820px){
            .top{ flex-direction:column; align-items:flex-start; }
            .date-inputs{ grid-template-columns: 1fr; }
            .file-item{ grid-template-columns: 1fr; }
            .file-icon{ display:none; }
            .file-actions-inner .btn{ width:100%; }
            .secret-input{ width: 100%; }
        }

        /* ===== MENU PORTAL (prevents dropdown from breaking layout / being clipped) ===== */
        .menu-portal{
            position: fixed;
            inset: 0;
            z-index: 2147483647;
            pointer-events: none;
        }
        .menu-portal .proc-menu{
            pointer-events: auto;
        }

        /* ✅ NEW: LOCK OVERLAY (auto-lock until secret entered) */
        .ui-lock{
            position:absolute;
            inset:0;
            z-index: 9000;
            display:flex;
            align-items:center;
            justify-content:center;
            background: rgba(0,0,0,0.88);
            backdrop-filter: blur(6px);
        }
        .ui-lock.hidden{ display:none; }
        .lock-box{
            max-width: 520px;
            width: calc(100% - 36px);
            padding: 18px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(0,0,0,0.80);
            box-shadow: var(--shadow);
            text-align:center;
        }
        .lock-box h2{
            font-size: 1.15rem;
            font-weight: 900;
            display:flex;
            gap:10px;
            align-items:center;
            justify-content:center;
            margin-bottom: 8px;
        }
        .lock-box h2 i{ color: var(--secondary); }
        .lock-box p{
            color: var(--muted);
            font-weight: 800;
        }
        .lock-box .hint{
            margin-top: 10px;
            font-weight: 800;
            color: rgba(255,255,255,0.88);
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
                <div class="page">Manual CSV Import</div>
            </div>
        </div>

        <!-- ✅ NEW: NAV + SECRET INPUT -->
        <div class="top-actions">
            <a href="{{ route('manual.export.index') }}" class="btn btn-secondary">
                <i class="fas fa-file-export"></i> Manual Export
            </a>

            <input id="secretKeyInput" class="secret-input" type="password" placeholder="Enter Secret Key (X-Secret-Key)" autocomplete="off">
        </div>
    </div>

    <div class="card">

        <!-- ✅ NEW: AUTO-LOCK OVERLAY -->
        <div id="uiLock" class="ui-lock">
            <div class="lock-box">
                <h2><i class="fas fa-lock"></i> Locked</h2>
                <p>Enter the secret key above to unlock upload & processing actions.</p>
                <div class="hint"><i class="fas fa-shield-halved" style="color:var(--secondary)"></i> The key is stored only for this tab session.</div>
            </div>
        </div>

        <div class="card-head">
            <h1><i class="fas fa-file-csv"></i> Manual CSV Import</h1>
            <p class="subtitle">Upload multiple CSV files or a ZIP archive. Map each file to a data processor.</p>

            <div class="chips">
                <div class="chip"><i class="fas fa-file-csv"></i> CSV</div>
                <div class="chip"><i class="fas fa-file-archive"></i> ZIP (auto-inspect)</div>
                <div class="chip"><i class="fas fa-shield-halved"></i> CSRF-protected</div>
                <div class="chip"><i class="fas fa-bolt"></i> Live progress</div>
                <div class="chip"><i class="fas fa-database"></i> Up to 1GB</div>
            </div>
        </div>

        <div class="card-body">
            <div class="upload-zone" id="uploadZone" role="button" tabindex="0" aria-label="Upload CSV or ZIP">
                <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <h3>Drag &amp; Drop CSV or ZIP files here</h3>
                <p>or click to browse • Multiple CSVs supported</p>
                <input type="file" id="fileInput" accept=".csv,.zip" multiple>
            </div>

            <div id="loadingSection" class="loading">
                <div class="loading-spinner"></div>
                <p style="margin-top:10px;color:var(--muted);font-weight:800;">Inspecting ZIP contents...</p>
            </div>

            <div id="fileList" class="file-list"></div>

            <div id="progressSection" class="progress-section">
                <div class="progress-header">
                    <div class="progress-title">
                        <i class="fas fa-sync spinner"></i> Processing Files...
                    </div>
                    <div class="progress-stats" id="progressStats">0 / 0 files</div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill" style="width: 0%">0%</div>
                </div>
                <div class="progress-details" id="progressDetails">
                    Waiting for jobs to start...
                </div>
            </div>

            <div id="results" class="results"></div>

            <div id="aggregationSection" class="aggregation-section" style="display:none;">
                <h3><i class="fas fa-chart-line"></i> Re-aggregate Data</h3>
                <p class="subtitle">Update aggregation tables for the imported date range</p>

                <div class="date-inputs">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" id="startDate">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" id="endDate">
                    </div>
                </div>

                <div class="form-group">
                    <label>Aggregation Type</label>
                    <select id="aggregationType">
                        <option value="hourly">Hourly</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                        <option value="all">All (Complete Rebuild)</option>
                    </select>
                </div>

                <button class="btn btn-primary" onclick="runAggregation()" id="aggBtn">
                    <i class="fas fa-sync"></i> Run Aggregation
                </button>

                <div id="aggProgressSection" class="progress-section">
                    <div class="progress-header">
                        <div class="progress-title">
                            <i class="fas fa-sync spinner"></i> Aggregating...
                        </div>
                        <div class="progress-stats" id="aggProgressStats">0 / 0 dates</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="aggProgressFill" style="width: 0%">0%</div>
                    </div>
                    <div class="progress-details" id="aggProgressDetails">
                        Waiting for aggregation to start...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<!-- ===== MENU PORTAL ROOT (menus are moved here when opened) ===== -->
<div id="menuPortal" class="menu-portal"></div>

<script>
    const processors = @json($processors);

    const processorMeta = {
        detail_orders: { icon: "fa-clipboard-list", hint: "Order headers & core details" },
        order_lines: { icon: "fa-list-check", hint: "Order line items (items / modifiers)" },
        detail_transactions: { icon: "fa-money-check-dollar", hint: "Payment/tender detail per order" },
        summary_sales: { icon: "fa-chart-line", hint: "Sales totals & KPIs (summary)" },
        summary_items: { icon: "fa-box", hint: "Item performance (summary)" },
        summary_transactions: { icon: "fa-receipt", hint: "Transactions summary (payments / totals)" },
        waste: { icon: "fa-trash-arrow-up", hint: "Waste report" },
        cash_management: { icon: "fa-cash-register", hint: "Cash drawer & cash movements" },
        financial_views: { icon: "fa-chart-pie", hint: "Financial views / dashboards export" },
        inventory_cogs: { icon: "fa-coins", hint: "Cost of goods sold (COGS)" },
        inventory_orders: { icon: "fa-truck-ramp-box", hint: "Inventory purchasing / orders" },
        inventory_usage: { icon: "fa-layer-group", hint: "Inventory usage tracking" },
        inventory_waste: { icon: "fa-skull-crossbones", hint: "Inventory waste / shrink" },
    };

    function getProcMetaByKeyOrLabel(key){
        return processorMeta[key] || { icon: "fa-gears", hint: "Data processor" };
    }

    function baseName(path){
        return String(path).split(/[/\\]/).pop();
    }

    function escapeHtml(str){
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    let selectedFiles = [];
    let zipTempId = null;
    let currentUploadId = null;
    let currentAggregationId = null;
    let progressInterval = null;
    let aggProgressInterval = null;

    // ✅ NEW: persist file -> processor mapping so removing a file doesn't reset everything
    let fileMappings = {};

    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('fileInput');

    // ===== Portal root (menus get moved here when opened) =====
    const menuPortal = document.getElementById('menuPortal');

    function portalizeMenu(menu){
        if (!menu) return;
        if (!menu._homeParent) {
            menu._homeParent = menu.parentNode;
            menu._homeNext = menu.nextSibling; // restore exact position
        }
        menuPortal.appendChild(menu);
    }

    function restoreMenu(menu){
        if (!menu || !menu._homeParent) return;
        if (menu._homeNext && menu._homeNext.parentNode === menu._homeParent) {
            menu._homeParent.insertBefore(menu, menu._homeNext);
        } else {
            menu._homeParent.appendChild(menu);
        }
    }

    // ---------- Floating proc-menu positioning ----------
    function positionProcMenu(index){
        const menu = document.getElementById(`procMenu_${index}`);
        const trigger = document.getElementById(`procTrigger_${index}`);
        if (!menu || !trigger) return;

        const rect = trigger.getBoundingClientRect();

        // Match menu width to trigger width (cap)
        const w = Math.min(Math.max(rect.width, 320), 520);
        menu.style.width = w + 'px';

        const margin = 10;
        let left = rect.left;
        left = Math.max(margin, Math.min(left, window.innerWidth - w - margin));

        const menuMaxH = 280;
        const spaceBelow = window.innerHeight - rect.bottom - margin;
        const spaceAbove = rect.top - margin;

        let top;
        if (spaceBelow >= 160) {
            top = rect.bottom + 8;
            menu.style.maxHeight = Math.min(menuMaxH, spaceBelow) + 'px';
        } else {
            const h = Math.min(menuMaxH, Math.max(160, spaceAbove));
            menu.style.maxHeight = h + 'px';
            top = rect.top - 8 - h;
        }

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    function closeAllProcMenus(){
        document.querySelectorAll('.proc-menu.open').forEach(m => {
            m.classList.remove('open');
            const t = m._triggerEl || document.getElementById(`procTrigger_${(m.id || '').split('_')[1]}`);
            if (t) t.setAttribute('aria-expanded', 'false');
            restoreMenu(m);
        });
    }

    // Keep floating menu aligned on scroll/resize
    window.addEventListener('resize', () => {
        const openMenu = document.querySelector('.proc-menu.open');
        if (!openMenu) return;
        const idx = openMenu.id.split('_')[1];
        positionProcMenu(idx);
    });

    document.addEventListener('scroll', () => {
        const openMenu = document.querySelector('.proc-menu.open');
        if (!openMenu) return;
        const idx = openMenu.id.split('_')[1];
        positionProcMenu(idx);
    }, true);

    // ✅ Close menu when scrolling anywhere OUTSIDE the proc-menu
    document.addEventListener('wheel', (e) => {
        const openMenu = document.querySelector('.proc-menu.open');
        if (!openMenu) return;
        if (e.target.closest('.proc-menu')) return;
        closeAllProcMenus();
    }, { capture: true, passive: true });

    document.addEventListener('touchmove', (e) => {
        const openMenu = document.querySelector('.proc-menu.open');
        if (!openMenu) return;
        if (e.target.closest('.proc-menu')) return;
        closeAllProcMenus();
    }, { capture: true, passive: true });

    // Close menus on outside click / ESC
    document.addEventListener('click', (e) => {
        const clickedInsideSelect = e.target.closest('.proc-select');
        const clickedInsideMenu = e.target.closest('.proc-menu');
        if (!clickedInsideSelect && !clickedInsideMenu) closeAllProcMenus();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAllProcMenus();
    });

    /* ============================================================
       ✅ NEW: SECRET KEY + AUTO-LOCK (session only)
       - The key persists while tab is open (sessionStorage)
       - Overlay blocks UI until key exists
       - Every request adds X-Secret-Key header
    ============================================================ */
    const SECRET_STORAGE_KEY = 'manual_import_secret_key';
    const secretInput = document.getElementById('secretKeyInput');
    const uiLock = document.getElementById('uiLock');

    function hasSecretKey(){
        return !!(sessionStorage.getItem(SECRET_STORAGE_KEY) || '').trim();
    }

    function syncLockState(){
        if (hasSecretKey()) {
            uiLock.classList.add('hidden');
        } else {
            uiLock.classList.remove('hidden');
        }
    }

    // Restore secret key
    const savedSecret = sessionStorage.getItem(SECRET_STORAGE_KEY);
    if (savedSecret) {
        secretInput.value = savedSecret;
    }
    syncLockState();

    // Persist on change
    secretInput.addEventListener('input', () => {
        const v = (secretInput.value || '').trim();
        if (v) {
            sessionStorage.setItem(SECRET_STORAGE_KEY, v);
        } else {
            sessionStorage.removeItem(SECRET_STORAGE_KEY);
        }
        syncLockState();
    });

    function authHeaders(extra = {}) {
        const secret = (sessionStorage.getItem(SECRET_STORAGE_KEY) || '').trim();
        if (!secret) {
            showToast('Secret key is required', 'error');
            throw new Error('Missing secret key');
        }
        return { ...extra, 'X-Secret-Key': secret };
    }

    // Click/keyboard open file picker (locked UI blocks clicks anyway, but keep safe checks)
    uploadZone.addEventListener('click', () => {
        if (!hasSecretKey()) { showToast('Enter secret key to unlock', 'error'); return; }
        fileInput.click();
    });
    uploadZone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (!hasSecretKey()) { showToast('Enter secret key to unlock', 'error'); return; }
            fileInput.click();
        }
    });

    uploadZone.addEventListener('dragover', (e) => {
        if (!hasSecretKey()) return;
        e.preventDefault();
        uploadZone.classList.add('drag-over');
    });

    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('drag-over');
    });

    uploadZone.addEventListener('drop', (e) => {
        if (!hasSecretKey()) { showToast('Enter secret key to unlock', 'error'); return; }
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        handleFiles(e.dataTransfer.files);
    });

    fileInput.addEventListener('change', (e) => {
        if (!hasSecretKey()) { showToast('Enter secret key to unlock', 'error'); return; }
        handleFiles(e.target.files);
    });

    async function handleFiles(files) {
        const fileArray = Array.from(files || []);
        const zipFile = fileArray.find(f => f.name.toLowerCase().endsWith('.zip'));

        if (zipFile) {
            await inspectZip(zipFile);
        } else {
            selectedFiles = fileArray.filter(f => f.name.toLowerCase().endsWith('.csv'));
            zipTempId = null;
            renderFileList();
            if (selectedFiles.length === 0) showToast('Please select CSV or ZIP files.', 'error');
        }
    }

    async function inspectZip(zipFile) {
        document.getElementById('loadingSection').classList.add('active');

        const formData = new FormData();
        formData.append('file', zipFile);

        try {
            const response = await fetch('{{ route("manual.import.inspect.zip") }}', {
                method: 'POST',
                headers: authHeaders({
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }),
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                zipTempId = data.temp_id;
                selectedFiles = data.csv_files.map(csv => ({
                    name: csv.name,
                    size: csv.size,
                    size_mb: csv.size_mb,
                    isFromZip: true
                }));
                renderFileList();
                showToast(`Found ${selectedFiles.length} CSV file(s) in ZIP`, 'success');
            } else {
                showToast(data.message || 'ZIP inspection failed', 'error');
            }

        } catch (error) {
            showToast('Failed to inspect ZIP: ' + error.message, 'error');
        } finally {
            document.getElementById('loadingSection').classList.remove('active');
        }
    }

    function toggleProcMenu(index){
        closeAllProcMenus();

        const menu = document.getElementById(`procMenu_${index}`);
        const trigger = document.getElementById(`procTrigger_${index}`);
        if (!menu || !trigger) return;

        const willOpen = !menu.classList.contains('open');

        if (willOpen) {
            menu._triggerEl = trigger;
            portalizeMenu(menu);

            menu.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');

            updateMenuSelectedState(index);
            positionProcMenu(index);
        } else {
            menu.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            restoreMenu(menu);
        }
    }

    function updateMenuSelectedState(index){
        const select = document.getElementById(`mapping_${index}`);
        const menu = document.getElementById(`procMenu_${index}`);
        if (!select || !menu) return;

        const selectedKey = select.value || '';

        menu.querySelectorAll('.proc-option').forEach(opt => {
            const key = opt.getAttribute('data-key') || '';
            const isSel = selectedKey && key === selectedKey;
            opt.classList.toggle('is-selected', !!isSel);
        });
    }

    function selectProcessor(index, key){
        const select = document.getElementById(`mapping_${index}`);
        const file = selectedFiles[index];

        if (!select || !file) return;

        // ✅ persist mapping by filename
        fileMappings[file.name] = key;

        const label = processors[key] || key;
        const meta = getProcMetaByKeyOrLabel(key);

        select.value = key;

        const labelEl = document.getElementById(`procLabel_${index}`);
        if (labelEl) {
            labelEl.innerHTML = `<i class="fas ${meta.icon}"></i><span>${escapeHtml(label)}</span>`;
        }

        updateMenuSelectedState(index);

        const menu = document.getElementById(`procMenu_${index}`);
        if (menu) {
            menu.classList.remove('open');
            restoreMenu(menu);
        }

        const trigger = document.getElementById(`procTrigger_${index}`);
        if (trigger) trigger.setAttribute('aria-expanded', 'false');

        checkReadyToUpload();
    }

    function renderFileList() {
        const fileList = document.getElementById('fileList');

        if (!selectedFiles || selectedFiles.length === 0) {
            fileList.innerHTML = `
                <div class="block empty-state">
                    <div class="title"><i class="fas fa-folder-open"></i> No files selected</div>
                    <p>Drop CSV/ZIP files above, or click the upload area to browse.</p>
                </div>
            `;
            return;
        }

        const totalMB = selectedFiles.reduce((sum, f) => {
            const mb = Number(f.size_mb || (f.size ? (f.size / 1024 / 1024) : 0)) || 0;
            return sum + mb;
        }, 0);

        let html = `
            <div class="file-list-head">
                <h4><i class="fas fa-folder-open" style="color:var(--secondary)"></i> Files to Import</h4>
                <div class="file-stats">${selectedFiles.length} file(s) • ~${totalMB.toFixed(2)} MB</div>
            </div>

            <div class="file-panel">
                <div class="files-scroll">
        `;

        selectedFiles.forEach((file, index) => {
            const sizeMB = file.size_mb || (file.size / 1024 / 1024).toFixed(2);
            const displayName = baseName(file.name);

            // ✅ restore saved mapping if exists
            const savedMapping = fileMappings[file.name] || '';

            const optionsHtml = `
                <option value="">-- Select Processor --</option>
                ${Object.entries(processors).map(([key, label]) =>
                    `<option value="${escapeHtml(key)}" ${savedMapping === key ? 'selected' : ''}>${escapeHtml(label)}</option>`
                ).join('')}
            `;

            const menuOptions = Object.entries(processors).map(([key, label]) => {
                const meta = getProcMetaByKeyOrLabel(key);
                return `
                    <div class="proc-option" data-key="${escapeHtml(key)}" role="option" onclick="selectProcessor(${index}, '${escapeHtml(key)}')">
                        <i class="fas ${meta.icon}"></i>
                        <div class="meta">
                            <strong>${escapeHtml(label)}</strong>
                            <small>${escapeHtml(meta.hint || "Data processor")}</small>
                        </div>
                        <i class="fas fa-check selected-mark"></i>
                    </div>
                `;
            }).join('');

            // ✅ if savedMapping exists, show it in trigger label
            let triggerLabelHtml = `
                <i class="fas fa-wand-magic-sparkles"></i>
                <span>Select processor</span>
            `;
            if (savedMapping) {
                const meta = getProcMetaByKeyOrLabel(savedMapping);
                triggerLabelHtml = `<i class="fas ${meta.icon}"></i><span>${escapeHtml(processors[savedMapping] || savedMapping)}</span>`;
            }

            html += `
                <div class="file-item">
                    <div class="file-icon"><i class="fas fa-file-csv"></i></div>

                    <div class="file-info">
                        <div class="file-name" title="${escapeHtml(file.name)}">
                            ${escapeHtml(displayName)} <small>(${escapeHtml(sizeMB)} MB)</small>
                        </div>

                        <div class="proc-select">
                            <select id="mapping_${index}" onchange="checkReadyToUpload()" style="display:none;">
                                ${optionsHtml}
                            </select>

                            <div
                                class="proc-trigger"
                                id="procTrigger_${index}"
                                tabindex="0"
                                role="button"
                                aria-haspopup="listbox"
                                aria-expanded="false"
                                onclick="toggleProcMenu(${index})"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleProcMenu(${index});}"
                            >
                                <div class="proc-label" id="procLabel_${index}">
                                    ${triggerLabelHtml}
                                </div>
                                <i class="fas fa-chevron-down" style="color:rgba(255,255,255,0.65);"></i>
                            </div>

                            <div class="proc-menu" id="procMenu_${index}" role="listbox">
                                ${menuOptions}
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-danger" onclick="removeFile(${index})" style="padding:10px 12px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        });

        html += `
                </div>

                <div class="file-actions">
                    <div class="file-actions-inner">
                        <button class="btn btn-primary" id="uploadBtn" onclick="uploadFiles()" disabled>
                            <i class="fas fa-upload"></i> Upload &amp; Process
                        </button>
                        <button class="btn btn-secondary" onclick="resetUpload()">
                            <i class="fas fa-redo"></i> Start Over
                        </button>
                    </div>
                </div>
            </div>
        `;

        fileList.innerHTML = html;

        // ✅ After DOM paint, update selected menu state for each file
        selectedFiles.forEach((_, index) => updateMenuSelectedState(index));

        checkReadyToUpload();
    }

    function removeFile(index) {
        closeAllProcMenus();

        const removed = selectedFiles[index];
        if (removed && removed.name && fileMappings[removed.name]) {
            // ✅ remove only this file mapping
            delete fileMappings[removed.name];
        }

        selectedFiles.splice(index, 1);
        renderFileList();

        if (selectedFiles.length === 0) resetUpload();
    }

    function resetUpload() {
        closeAllProcMenus();
        selectedFiles = [];
        zipTempId = null;

        // ✅ reset mappings when user explicitly starts over
        fileMappings = {};

        document.getElementById('fileList').innerHTML = `
            <div class="block empty-state">
                <div class="title"><i class="fas fa-folder-open"></i> No files selected</div>
                <p>Drop CSV/ZIP files above, or click the upload area to browse.</p>
            </div>
        `;
        fileInput.value = '';
    }

    function checkReadyToUpload() {
        if (!selectedFiles || selectedFiles.length === 0) {
            const btn = document.getElementById('uploadBtn');
            if (btn) btn.disabled = true;
            return;
        }

        const allMapped = selectedFiles.every((file, index) => {
            const select = document.getElementById(`mapping_${index}`);
            return select && select.value !== '';
        });

        const btn = document.getElementById('uploadBtn');
        if (btn) btn.disabled = !allMapped;
    }

    async function uploadFiles() {
        closeAllProcMenus();

        const formData = new FormData();

        const mappings = {};
        selectedFiles.forEach((file, index) => {
            const select = document.getElementById(`mapping_${index}`);
            if (select && select.value) {
                mappings[file.name] = select.value;
            }
        });

        if (zipTempId) {
            formData.append('temp_id', zipTempId);
            formData.append('mappings', JSON.stringify(mappings));
        } else {
            selectedFiles.forEach(file => {
                if (file instanceof File) formData.append('files[]', file);
            });
            formData.append('mappings', JSON.stringify(mappings));
        }

        const uploadBtn = document.getElementById('uploadBtn');
        if (uploadBtn) uploadBtn.disabled = true;

        document.getElementById('progressSection').classList.add('active');

        try {
            const response = await fetch('{{ route("manual.import.upload") }}', {
                method: 'POST',
                headers: authHeaders({
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }),
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                currentUploadId = data.upload_id;
                startProgressPolling();
                showToast('Upload started! Processing...', 'success');
            } else {
                showToast(data.message || 'Upload failed', 'error');
                document.getElementById('progressSection').classList.remove('active');
                if (uploadBtn) uploadBtn.disabled = false;
            }

        } catch (error) {
            showToast('Upload failed: ' + error.message, 'error');
            document.getElementById('progressSection').classList.remove('active');
            if (uploadBtn) uploadBtn.disabled = false;
        }
    }

    function startProgressPolling() {
        if (progressInterval) clearInterval(progressInterval);

        progressInterval = setInterval(async () => {
            try {
                const response = await fetch(`/manual-import/progress/${currentUploadId}`, {
                    headers: authHeaders()
                });

                const data = await response.json();

                if (data.success) {
                    updateProgress(data.progress);

                    if (data.progress.status === 'completed') {
                        clearInterval(progressInterval);
                        showResults(data.progress);
                    }
                }
            } catch (error) {
                console.error('Progress polling error:', error);
            }
        }, 1000);
    }

    function updateProgress(progress) {
        const percentage = progress.total_files > 0
            ? Math.round((progress.processed_files / progress.total_files) * 100)
            : 0;

        document.getElementById('progressFill').style.width = percentage + '%';
        document.getElementById('progressFill').textContent = percentage + '%';
        document.getElementById('progressStats').textContent =
            `${progress.processed_files} / ${progress.total_files} files`;

        let details = `Current file: ${escapeHtml(progress.current_file || 'None')}<br>`;
        details += `Total rows processed: ${escapeHtml(progress.total_rows || 0)}`;
        if (progress.processed_rows) details += `<br>Current file rows: ${escapeHtml(progress.processed_rows)}`;

        document.getElementById('progressDetails').innerHTML = details;
    }

    function showResults(progress) {
        document.getElementById('progressSection').classList.remove('active');

        const resultsDiv = document.getElementById('results');

        let html = '<h4><i class="fas fa-chart-bar" style="color:var(--secondary)"></i> Import Results</h4>';
        html += `<p style="color:var(--muted);margin-bottom:12px;font-weight:800;">
            ${progress.processed_files} files processed | ${progress.total_rows} total rows
        </p>`;

        (progress.results || []).forEach(result => {
            html += `
                <div class="result-item ${result.status}">
                    <i class="fas fa-${result.status === 'success' ? 'check-circle' : 'times-circle'}"></i>
                    <div style="flex:1;">
                        <strong>${escapeHtml(result.file)}</strong>: ${escapeHtml(result.rows || 0)} rows
                        ${result.dates ? `<br><small>Dates: ${(result.dates || []).map(escapeHtml).join(', ')}</small>` : ''}
                        ${result.duration ? `<br><small>Duration: ${escapeHtml(result.duration)}s</small>` : ''}
                        ${result.error ? `<br><small>Error: ${escapeHtml(result.error)}</small>` : ''}
                    </div>
                </div>
            `;
        });

        resultsDiv.innerHTML = html;

        const allDates = [];
        (progress.results || []).forEach(r => { if (r.dates) allDates.push(...r.dates); });

        if (allDates.length > 0) {
            const uniqueDates = [...new Set(allDates)].sort();
            document.getElementById('startDate').value = uniqueDates[0];
            document.getElementById('endDate').value = uniqueDates[uniqueDates.length - 1];
            document.getElementById('aggregationSection').style.display = 'block';
        }

        showToast('Import completed!', 'success');
        resetUpload();
    }

    async function runAggregation() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const type = document.getElementById('aggregationType').value;

        if (!startDate || !endDate) {
            showToast('Please select date range', 'error');
            return;
        }

        document.getElementById('aggBtn').disabled = true;
        document.getElementById('aggProgressSection').classList.add('active');

        try {
            const response = await fetch('{{ route("manual.import.reaggregate") }}', {
                method: 'POST',
                headers: authHeaders({
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }),
                body: JSON.stringify({ start_date: startDate, end_date: endDate, type })
            });

            const data = await response.json();

            if (data.success) {
                currentAggregationId = data.aggregation_id;
                startAggregationPolling();
                showToast('Aggregation started!', 'success');
            } else {
                showToast(data.message || 'Aggregation failed', 'error');
                document.getElementById('aggProgressSection').classList.remove('active');
                document.getElementById('aggBtn').disabled = false;
            }

        } catch (error) {
            showToast('Aggregation failed: ' + error.message, 'error');
            document.getElementById('aggProgressSection').classList.remove('active');
            document.getElementById('aggBtn').disabled = false;
        }
    }

    function startAggregationPolling() {
        if (aggProgressInterval) clearInterval(aggProgressInterval);

        aggProgressInterval = setInterval(async () => {
            try {
                const response = await fetch(`/manual-import/aggregation-progress/${currentAggregationId}`, {
                    headers: authHeaders()
                });

                const data = await response.json();

                if (data.success) {
                    updateAggProgress(data.progress);

                    if (data.progress.status === 'completed') {
                        clearInterval(aggProgressInterval);
                        showToast('Aggregation completed!', 'success');
                        document.getElementById('aggProgressSection').classList.remove('active');
                        document.getElementById('aggBtn').disabled = false;
                    }
                }
            } catch (error) {
                console.error('Aggregation polling error:', error);
            }
        }, 1000);
    }

    function updateAggProgress(progress) {
        const percentage = progress.total > 0
            ? Math.round((progress.processed / progress.total) * 100)
            : 0;

        document.getElementById('aggProgressFill').style.width = percentage + '%';
        document.getElementById('aggProgressFill').textContent = percentage + '%';
        document.getElementById('aggProgressStats').textContent =
            `${progress.processed} / ${progress.total} dates`;

        let details = `Type: ${escapeHtml(progress.type)}<br>`;
        details += `Current date: ${escapeHtml(progress.current_date || 'None')}`;

        document.getElementById('aggProgressDetails').innerHTML = details;
    }

    function showToast(message, type) {
        const toast = document.getElementById('toast');
        toast.className = `toast ${type} show`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${escapeHtml(message)}</span>
        `;

        setTimeout(() => toast.classList.remove('show'), 5000);
    }

    // Initial empty state
    resetUpload();
</script>
</body>
</html>
