<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProCentric PMS Middleware</title>
    <style>
        :root, [data-theme="dark"] {
            --bg-primary: #0f1117;
            --bg-secondary: #1a1d27;
            --bg-card: #222636;
            --bg-input: #2a2e3d;
            --border: #363a4a;
            --text-primary: #e8eaf0;
            --text-secondary: #9498a8;
            --accent-blue: #4a90d9;
            --accent-green: #3ddc84;
            --accent-red: #f44336;
            --accent-orange: #ff9800;
            --accent-purple: #b388ff;
            --shadow: 0 4px 24px rgba(0,0,0,0.3);
            --logo-body: #222636;
            --logo-screen: #1a1d27;
            --checkin-text: #0f1117;
            --scrollbar-track: #0f1117;
        }

        [data-theme="light"] {
            --bg-primary: #f0f2f5;
            --bg-secondary: #ffffff;
            --bg-card: #f7f8fa;
            --bg-input: #e8eaef;
            --border: #d0d4dc;
            --text-primary: #1a1d27;
            --text-secondary: #5f6578;
            --accent-blue: #3a7bd5;
            --accent-green: #2eab68;
            --accent-red: #e53935;
            --accent-orange: #e68a00;
            --accent-purple: #7c4dff;
            --shadow: 0 4px 24px rgba(0,0,0,0.08);
            --logo-body: #e8eaef;
            --logo-screen: #f0f2f5;
            --checkin-text: #ffffff;
            --scrollbar-track: #f0f2f5;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .header {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header h1 {
            font-size: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .header h1 span {
            color: var(--accent-blue);
        }

        .connection-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent-red);
            transition: background 0.3s;
        }

        .status-dot.connected {
            background: var(--accent-green);
            box-shadow: 0 0 8px rgba(61, 220, 132, 0.5);
        }

        .main-container {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 0;
            height: calc(100vh - 60px);
        }

        .sidebar {
            background: var(--bg-secondary);
            border-right: 1px solid var(--border);
            overflow-y: auto;
            padding: 24px;
        }

        .tab-bar {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: var(--bg-primary);
            border-radius: 8px;
            padding: 4px;
        }

        .tab-btn {
            flex: 1;
            padding: 10px 12px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .tab-btn.active {
            background: var(--accent-blue);
            color: #fff;
        }

        .tab-btn:hover:not(.active) {
            color: var(--text-primary);
            background: var(--bg-input);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: var(--accent-blue);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .btn {
            width: 100%;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-checkin {
            background: var(--accent-green);
            color: var(--checkin-text);
        }

        .btn-checkin:hover {
            background: #4ae892;
            box-shadow: 0 0 20px rgba(61, 220, 132, 0.3);
        }

        .btn-checkout {
            background: var(--accent-red);
            color: #fff;
        }

        .btn-checkout:hover {
            background: #f55a4e;
            box-shadow: 0 0 20px rgba(244, 67, 54, 0.3);
        }

        .btn-save {
            background: var(--accent-blue);
            color: #fff;
        }

        .btn-save:hover {
            background: #5a9ee0;
        }

        .btn-secondary {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 20px;
        }

        .result-box {
            margin-top: 16px;
            padding: 14px;
            background: var(--bg-primary);
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: 13px;
            font-family: 'Consolas', 'Fira Code', monospace;
            display: none;
            max-height: 300px;
            overflow-y: auto;
            word-break: break-all;
            white-space: pre-wrap;
        }

        .result-box.success {
            border-color: var(--accent-green);
            display: block;
        }

        .result-box.error {
            border-color: var(--accent-red);
            display: block;
        }

        /* Config section */
        .config-section {
            margin-bottom: 20px;
        }

        .config-section h3 {
            font-size: 14px;
            font-weight: 600;
            color: var(--accent-blue);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        /* Log panel */
        .log-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            background: var(--bg-primary);
        }

        .log-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
        }

        .log-header h2 {
            font-size: 16px;
            font-weight: 600;
        }

        .log-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .log-controls button {
            padding: 6px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-secondary);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .log-controls button:hover {
            color: var(--text-primary);
            border-color: var(--accent-blue);
        }

        .log-filter {
            padding: 6px 12px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 12px;
            outline: none;
            width: 160px;
        }

        .log-body {
            flex: 1;
            overflow-y: auto;
            padding: 6px;
            font-family: 'Consolas', 'Fira Code', monospace;
            font-size: 12px;
            line-height: 1.3;
        }

        .log-entry {
            padding: 3px 8px;
            border-radius: 3px;
            margin-bottom: 1px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            transition: background 0.15s;
        }

        .log-entry:hover {
            background: var(--bg-card);
        }
        .log-entry.has-raw {
            cursor: pointer;
        }

        .log-time {
            color: var(--text-secondary);
            white-space: nowrap;
            min-width: 80px;
        }

        .log-dir {
            font-weight: 700;
            min-width: 36px;
            text-align: center;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
        }

        .log-dir.out {
            background: rgba(74, 144, 217, 0.2);
            color: var(--accent-blue);
        }

        .log-dir.in {
            background: rgba(61, 220, 132, 0.2);
            color: var(--accent-green);
        }

        .log-dir.error {
            background: rgba(244, 67, 54, 0.2);
            color: var(--accent-red);
        }

        .log-dir.config {
            background: rgba(179, 136, 255, 0.2);
            color: var(--accent-purple);
        }

        .log-type {
            color: var(--accent-orange);
            min-width: 70px;
        }

        .log-message {
            color: var(--text-primary);
            flex: 1;
            word-break: break-all;
        }

        .log-raw {
            display: none;
            padding: 4px 8px;
            margin: 2px 0 2px 100px;
            background: var(--bg-input);
            border-radius: 3px;
            color: var(--text-secondary);
            font-size: 11px;
            word-break: break-all;
            border-left: 2px solid var(--border);
        }

        .log-entry.expanded .log-raw {
            display: block;
        }

        .auto-scroll-indicator {
            font-size: 11px;
            color: var(--text-secondary);
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .main-container {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
            }
            .sidebar {
                border-right: none;
                border-bottom: 1px solid var(--border);
                max-height: 50vh;
            }
        }

        .room-card {
            margin-top: 12px;
            background: var(--bg-card);
            border-radius: 8px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .room-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
        }
        .room-card-header.checked-in {
            background: rgba(61, 220, 132, 0.1);
            border-bottom: 2px solid var(--accent-green);
        }
        .room-card-header.checked-out {
            background: rgba(244, 67, 54, 0.08);
            border-bottom: 2px solid var(--accent-red);
        }
        .room-card-room {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .room-card-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .room-card-badge.in {
            background: var(--accent-green);
            color: var(--checkin-text);
        }
        .room-card-badge.out {
            background: var(--accent-red);
            color: #fff;
        }
        .room-card-body {
            padding: 12px 16px;
        }
        .room-card-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
        }
        .room-card-row:last-child {
            border-bottom: none;
        }
        .room-card-label {
            color: var(--text-secondary);
        }
        .room-card-value {
            color: var(--text-primary);
            font-weight: 500;
        }

        .error-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-secondary);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .error-btn:hover {
            border-color: var(--accent-red);
            color: var(--accent-red);
        }
        .error-btn .badge {
            background: var(--accent-red);
            color: #fff;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }
        .error-btn .badge.zero {
            background: var(--border);
            color: var(--text-secondary);
        }
        .error-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 200;
            justify-content: center;
            align-items: center;
        }
        .error-overlay.active {
            display: flex;
        }
        .error-panel {
            background: var(--bg-secondary);
            border: 1px solid var(--accent-red);
            border-radius: 12px;
            width: 700px;
            max-width: 90vw;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 40px rgba(244,67,54,0.2);
        }
        .error-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        .error-panel-header h3 {
            color: var(--accent-red);
            font-size: 16px;
        }
        .error-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 8px;
            font-family: 'Consolas', 'Fira Code', monospace;
            font-size: 12px;
        }
        .error-item {
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 4px;
            background: var(--bg-primary);
            border-left: 3px solid var(--accent-red);
        }
        .error-item-time {
            color: var(--text-secondary);
            font-size: 11px;
        }
        .error-item-type {
            color: var(--accent-orange);
            font-weight: 600;
            font-size: 11px;
            margin-left: 8px;
        }
        .error-item-msg {
            color: var(--text-primary);
            margin-top: 3px;
        }
        .error-item-raw {
            color: var(--text-secondary);
            font-size: 11px;
            margin-top: 3px;
            word-break: break-all;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 12px;
            color: var(--accent-blue);
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            padding: 6px 0;
            letter-spacing: 0.5px;
            z-index: 100;
        }

        .lang-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .lang-table th {
            background: var(--bg-primary);
            color: var(--text-secondary);
            padding: 5px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .lang-table td {
            padding: 3px 8px;
            border-bottom: 1px solid var(--border);
        }
        .lang-table input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-primary);
            padding: 3px 6px;
            font-size: 12px;
            width: 50px;
            text-align: center;
            outline: none;
        }
        .lang-table input:focus {
            border-color: var(--accent-blue);
        }
        .lang-table .del-btn {
            background: none;
            border: none;
            color: var(--accent-red);
            cursor: pointer;
            font-size: 14px;
            padding: 2px 6px;
            opacity: 0.6;
        }
        .lang-table .del-btn:hover {
            opacity: 1;
        }
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-secondary);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .theme-toggle:hover {
            border-color: var(--accent-blue);
            color: var(--text-primary);
        }
        .theme-toggle svg {
            width: 14px;
            height: 14px;
        }
    </style>
</head>
<body>

<div class="footer">Made by Jan, Joerg, and Christian</div>

<div class="header">
    <div style="display:flex;align-items:center;gap:14px;">
        <svg width="38" height="38" viewBox="0 0 38 38" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- TV body -->
            <rect x="3" y="6" width="32" height="22" rx="3" fill="var(--logo-body)" stroke="var(--accent-blue)" stroke-width="1.5"/>
            <!-- Screen -->
            <rect x="6" y="9" width="26" height="16" rx="1.5" fill="var(--logo-screen)"/>
            <!-- Signal waves on screen -->
            <path d="M14 17 Q19 11 24 17" stroke="var(--accent-green)" stroke-width="1.5" fill="none" stroke-linecap="round"/>
            <path d="M16 20 Q19 15 22 20" stroke="var(--accent-green)" stroke-width="1.5" fill="none" stroke-linecap="round" opacity="0.7"/>
            <circle cx="19" cy="22" r="1" fill="var(--accent-green)" opacity="0.5"/>
            <!-- TV stand -->
            <line x1="15" y1="28" x2="12" y2="33" stroke="var(--accent-blue)" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="23" y1="28" x2="26" y2="33" stroke="var(--accent-blue)" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="10" y1="33" x2="28" y2="33" stroke="var(--accent-blue)" stroke-width="1.5" stroke-linecap="round"/>
            <!-- Connection arrow -->
            <path d="M30 14 L34 14" stroke="var(--accent-orange)" stroke-width="1.2" stroke-linecap="round"/>
            <path d="M32 12 L34 14 L32 16" stroke="var(--accent-orange)" stroke-width="1.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <h1><span>ProCentric</span> PMS Middleware</h1>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
        <button class="theme-toggle" onclick="toggleTheme()" id="themeToggleBtn" title="Farbschema wechseln">
            <svg id="themeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            <span id="themeLabel">Hell</span>
        </button>
        <button class="error-btn" onclick="toggleErrors()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Fehler <span class="badge zero" id="errorBadge">0</span>
        </button>
        <div class="error-btn" id="fiasHeaderBox" title="Letzter Check: -" style="cursor:default;">
            <div class="status-dot" id="fiasDotHeader"></div>
            FIAS
        </div>
        <div class="error-btn" id="pcsHeaderBox" title="Letzter Check: -" style="cursor:default;">
            <div class="status-dot" id="statusDot"></div>
            PCS
        </div>
    </div>
</div>

<!-- Error Overlay -->
<div class="error-overlay" id="errorOverlay" onclick="if(event.target===this)toggleErrors()">
    <div class="error-panel">
        <div class="error-panel-header">
            <h3>Major Errors</h3>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary" onclick="clearErrors()" style="font-size:11px;padding:5px 12px;text-transform:none;">L&ouml;schen</button>
                <button class="btn btn-secondary" onclick="toggleErrors()" style="font-size:11px;padding:5px 12px;text-transform:none;">Schlie&szlig;en</button>
            </div>
        </div>
        <div class="error-panel-body" id="errorList">
            <div style="color:var(--text-secondary);text-align:center;padding:20px;">Keine Fehler</div>
        </div>
    </div>
</div>

<div class="main-container">
    <div class="sidebar">
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="operations"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Steuerung</button>
            <button class="tab-btn" data-tab="fias"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg> FIAS</button>
            <button class="tab-btn" data-tab="config"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Einstellungen</button>
        </div>

        <!-- Steuerung Tab -->
        <div class="tab-content active" id="tab-operations">
            <div class="form-group">
                <label>Zimmernummer</label>
                <input type="text" id="roomNumber" placeholder="z.B. 201" autocomplete="off">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Nachname</label>
                    <input type="text" id="lastName" placeholder="Nachname">
                </div>
                <div class="form-group">
                    <label>Vorname</label>
                    <input type="text" id="firstName" placeholder="Vorname">
                </div>
            </div>

            <div class="form-group">
                <label>Gastsprache</label>
                <select id="guestLanguage">
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                    <option value="fr">Fran&ccedil;ais</option>
                    <option value="es">Espa&ntilde;ol</option>
                    <option value="it">Italiano</option>
                    <option value="nl">Nederlands</option>
                    <option value="pt">Portugu&ecirc;s</option>
                    <option value="ru">Русский</option>
                    <option value="zh">中文</option>
                    <option value="ja">日本語</option>
                    <option value="ko">한국어</option>
                    <option value="ar">العربية</option>
                    <option value="pl">Polski</option>
                    <option value="tr">T&uuml;rk&ccedil;e</option>
                </select>
            </div>

            <div class="form-group">
                <label>Gast-ID (optional)</label>
                <input type="text" id="guestId" placeholder="Automatisch: Zimmer_1">
            </div>

            <div class="btn-row">
                <button class="btn btn-checkin" onclick="doCheckin()">Check-In</button>
                <button class="btn btn-checkout" onclick="doCheckout()">Check-Out</button>
            </div>

            <div style="margin-top: 12px;">
                <button class="btn btn-secondary" onclick="checkRoomStatus()" style="font-size: 12px; padding: 8px; text-transform: none;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Zimmerstatus abfragen
                </button>
            </div>

            <div class="result-box" id="resultBox"></div>
            <div id="roomStatusCard" style="display:none;"></div>
        </div>

        <!-- FIAS Connector Tab -->
        <div class="tab-content" id="tab-fias">
            <div class="config-section">
                <h3>FIAS Verbindungsstatus</h3>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <div class="status-dot" id="fiasDot"></div>
                    <span id="fiasStatusText" style="font-size:13px;color:var(--text-secondary);">Unbekannt</span>
                </div>
                <div class="btn-row">
                    <button class="btn btn-checkin" onclick="fiasStart()" style="font-size:12px;padding:8px;text-transform:none;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg> Starten
                    </button>
                    <button class="btn btn-checkout" onclick="fiasStop()" style="font-size:12px;padding:8px;text-transform:none;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="16" height="16" rx="2"/></svg> Stoppen
                    </button>
                </div>
                <div style="margin-top:8px;">
                    <button class="btn btn-secondary" onclick="fiasRefreshStatus()" style="font-size:12px;padding:8px;text-transform:none;width:100%;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Status aktualisieren
                    </button>
                </div>
            </div>

            <div class="config-section">
                <h3>FIAS Konfiguration</h3>
                <div class="form-group">
                    <label>Host / IP</label>
                    <input type="text" id="cfg-fias-host" placeholder="172.23.56.253">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" id="cfg-fias-port" placeholder="5091">
                    </div>
                    <div class="form-group">
                        <label>Heartbeat (s)</label>
                        <input type="number" id="cfg-fias-heartbeat" placeholder="60">
                    </div>
                </div>
            </div>

            <div class="config-section">
                <h3>Sprach-Uebersetzung (FIAS -> PCS)</h3>
                <div style="max-height:200px;overflow-y:auto;">
                    <table class="lang-table">
                        <thead><tr><th>FIAS Code</th><th>PCS Code</th><th></th></tr></thead>
                        <tbody id="langTableBody"></tbody>
                    </table>
                </div>
                <div style="margin-top:8px;padding:8px 10px;border:1px solid #555;border-radius:6px;display:flex;align-items:center;gap:8px;">
                    <input type="text" id="newLangFias" placeholder="FIAS" style="width:50px;padding:4px 6px;background:var(--bg-input);border:1px solid #555;border-radius:4px;color:var(--text-primary);font-size:12px;text-align:center;text-transform:uppercase;outline:none;">
                    <input type="text" id="newLangPcs" placeholder="PCS" style="width:50px;padding:4px 6px;background:var(--bg-input);border:1px solid #555;border-radius:4px;color:var(--text-primary);font-size:12px;text-align:center;outline:none;">
                    <button class="btn btn-secondary" onclick="addLangRow()" style="font-size:11px;padding:4px 10px;text-transform:none;flex:1;border-color:#555;color:var(--text-primary);">+ Hinzuf&uuml;gen</button>
                </div>
            </div>

            <div class="btn-row" style="margin-top:12px;">
                <button class="btn btn-save" onclick="saveConfig()">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Speichern
                </button>
                <button class="btn btn-secondary" onclick="fiasDbSwap()" style="font-size:12px;text-transform:none;border-color:var(--accent-orange);color:var(--accent-orange);">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg> DB-Swap anfordern
                </button>
            </div>

            <div class="result-box" id="fiasResultBox"></div>
        </div>

        <!-- Config Tab -->
        <div class="tab-content" id="tab-config">
            <div class="config-section">
                <h3>ProCentric Server</h3>
                <div class="form-group">
                    <label>Host / IP</label>
                    <input type="text" id="cfg-pc-host" placeholder="192.168.122.10">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" id="cfg-pc-port" placeholder="60080">
                    </div>
                    <div class="form-group">
                        <label>SSL</label>
                        <select id="cfg-pc-ssl">
                            <option value="0">Nein</option>
                            <option value="1">Ja</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>API Prefix</label>
                    <input type="text" id="cfg-pc-prefix" placeholder="/api/pms/v2">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Client ID</label>
                        <input type="text" id="cfg-pc-clientid" placeholder="0123456">
                    </div>
                    <div class="form-group">
                        <label>Client Secret</label>
                        <input type="text" id="cfg-pc-secret" placeholder="secret1">
                    </div>
                </div>
            </div>

            <div class="config-section">
                <h3>Middleware</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Listen Port</label>
                        <input type="number" id="cfg-mw-port" placeholder="80">
                    </div>
                    <div class="form-group">
                        <label>Max Log-Eintr&auml;ge</label>
                        <input type="number" id="cfg-mw-maxlog" placeholder="500">
                    </div>
                </div>
            </div>

            <div class="config-section">
                <h3>TV-Resync (t&auml;glich)</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Aktiviert</label>
                        <select id="cfg-resync-enabled">
                            <option value="1">Ja</option>
                            <option value="0">Nein</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nur bei FIAS-Ausfall</label>
                        <select id="cfg-resync-fiasonly">
                            <option value="1">Ja</option>
                            <option value="0">Nein (immer)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Auto-Discovery Filter (nur Zimmernummern)</label>
                    <select id="cfg-resync-roomfilter">
                        <option value="1">Aktiv (nur 1-5 Ziffern + opt. Buchstabe)</option>
                        <option value="0">Aus (alle PCS-IDs uebernehmen)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Zimmerliste</label>
                    <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;">
                        <table class="lang-table">
                            <thead><tr><th>Zimmer</th><th>Status</th><th>Sprache</th><th></th></tr></thead>
                            <tbody id="roomsTableBody"></tbody>
                        </table>
                    </div>
                    <div style="margin-top:8px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;gap:8px;">
                        <input type="text" id="newRoomNumber" placeholder="Zimmer" style="width:80px;padding:4px 6px;background:var(--bg-input);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:12px;text-align:center;outline:none;">
                        <button class="btn btn-secondary" onclick="addRoomRow()" style="font-size:11px;padding:4px 10px;text-transform:none;flex:1;">+ Hinzuf&uuml;gen</button>
                        <button class="btn btn-secondary" onclick="refreshAllRoomStatuses()" id="roomStatusBtn" style="font-size:11px;padding:4px 10px;text-transform:none;flex:1;" title="Status &amp; Sprache aller Zimmer von PCS abrufen">&#x21bb; Status laden</button>
                    </div>
                    <div id="roomsCount" style="margin-top:6px;font-size:11px;color:var(--text-secondary);"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Pseudo-Vorname</label>
                        <input type="text" id="cfg-resync-fname" placeholder="Max">
                    </div>
                    <div class="form-group">
                        <label>Pseudo-Nachname</label>
                        <input type="text" id="cfg-resync-lname" placeholder="Mustermann">
                    </div>
                </div>
                <div class="form-group">
                    <label>Pseudo-Sprache</label>
                    <select id="cfg-resync-lang">
                        <option value="de">Deutsch</option>
                        <option value="en">English</option>
                        <option value="fr">Fran&ccedil;ais</option>
                        <option value="es">Espa&ntilde;ol</option>
                        <option value="it">Italiano</option>
                    </select>
                </div>
                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn btn-checkout" onclick="triggerResyncCheckout()" style="font-size:12px;padding:8px;text-transform:none;">Alle auschecken</button>
                    <button class="btn btn-checkin" onclick="triggerResyncCheckin()" style="font-size:12px;padding:8px;text-transform:none;">Alle einchecken</button>
                </div>
            </div>

            <div class="btn-row">
                <button class="btn btn-save" onclick="saveConfig()">Speichern</button>
                <button class="btn btn-secondary" onclick="testConnection()">Verbindung testen</button>
            </div>

            <div class="result-box" id="configResultBox"></div>
        </div>
    </div>

    <!-- Log Panel -->
    <div class="log-panel">
        <div class="log-header">
            <h2>API Log</h2>
            <div class="log-controls">
                <input type="text" class="log-filter" id="logFilter" placeholder="Filter...">
                <select class="log-filter" id="logMaxLines" style="width:70px;" title="Max Zeilen">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                </select>
                <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-secondary);cursor:pointer;">
                    <input type="checkbox" id="autoRefresh" checked> Auto
                </label>
                <button onclick="refreshLogs()">Aktualisieren</button>
                <button onclick="clearLogs()">L&ouml;schen</button>
            </div>
        </div>
        <div class="log-body" id="logBody">
            <div style="color: var(--text-secondary); text-align: center; margin-top: 40px;">
                Noch keine Log-Eintr&auml;ge vorhanden
            </div>
        </div>
    </div>
</div>

<script>
// --- State ---
let autoRefreshEnabled = true;
let autoRefreshTimer = null;
let logAutoScroll = true;

// --- Tabs ---
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

// --- API calls ---
async function apiCall(action, data = null) {
    const opts = { headers: { 'Content-Type': 'application/json' } };
    if (data) {
        opts.method = 'POST';
        opts.body = JSON.stringify({ ...data, action });
    }
    const url = data ? `api.php?action=${action}` : `api.php?action=${action}`;
    if (data) {
        opts.method = 'POST';
    }
    const resp = await fetch(url, opts);
    return await resp.json();
}

// --- Check-In ---
async function doCheckin() {
    const room = document.getElementById('roomNumber').value.trim();
    const lastName = document.getElementById('lastName').value.trim();
    const firstName = document.getElementById('firstName').value.trim();
    const language = document.getElementById('guestLanguage').value;
    const guestId = document.getElementById('guestId').value.trim();

    if (!room || !lastName) {
        showResult('resultBox', false, 'Bitte Zimmernummer und Nachname eingeben.');
        return;
    }

    try {
        const result = await apiCall('checkin', {
            room, last_name: lastName, first_name: firstName,
            language, guest_id: guestId || undefined
        });
        showResult('resultBox', result.success,
            result.success
                ? `Check-In erfolgreich: ${result.guest} in Zimmer ${result.room} (${result.language})`
                : (result.error || 'Check-In fehlgeschlagen')
        );
        refreshLogs();
    } catch (e) {
        showResult('resultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Check-Out ---
async function doCheckout() {
    const room = document.getElementById('roomNumber').value.trim();
    const guestId = document.getElementById('guestId').value.trim();

    if (!room) {
        showResult('resultBox', false, 'Bitte Zimmernummer eingeben.');
        return;
    }

    try {
        const result = await apiCall('checkout', {
            room, guest_id: guestId || undefined
        });
        showResult('resultBox', result.success,
            result.success
                ? `Check-Out erfolgreich: Zimmer ${result.room}`
                : (result.error || 'Check-Out fehlgeschlagen')
        );
        refreshLogs();
    } catch (e) {
        showResult('resultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Room Status ---
async function checkRoomStatus() {
    const room = document.getElementById('roomNumber').value.trim();
    const card = document.getElementById('roomStatusCard');
    if (!room) {
        showResult('resultBox', false, 'Bitte Zimmernummer eingeben.');
        card.style.display = 'none';
        return;
    }
    try {
        const result = await apiCall('room_status&room=' + encodeURIComponent(room));
        if (result.success && result.data) {
            const d = result.data;
            const guests = d.guests || [];
            const checkedIn = guests.length > 0;
            let guestRows = '';
            guests.forEach((g, i) => {
                const name = g.name ? (g.name.full || ((g.name.first || '') + ' ' + (g.name.last || '')).trim()) : '?';
                const lang = g.language || '-';
                const vip = g.vip_status || '-';
                const id = g.id || '-';
                const prefix = guests.length > 1 ? `Gast ${i+1}: ` : '';
                guestRows += `
                    <div class="room-card-row"><span class="room-card-label">${prefix}Name</span><span class="room-card-value">${name}</span></div>
                    <div class="room-card-row"><span class="room-card-label">${prefix}Sprache</span><span class="room-card-value">${lang}</span></div>
                    <div class="room-card-row"><span class="room-card-label">${prefix}VIP</span><span class="room-card-value">${vip}</span></div>
                    <div class="room-card-row"><span class="room-card-label">${prefix}Gast-ID</span><span class="room-card-value">${id}</span></div>
                `;
            });
            card.innerHTML = `
                <div class="room-card">
                    <div class="room-card-header ${checkedIn ? 'checked-in' : 'checked-out'}">
                        <span class="room-card-room">Zimmer ${d.id || room}</span>
                        <span class="room-card-badge ${checkedIn ? 'in' : 'out'}">${checkedIn ? 'Eingecheckt' : 'Frei'}</span>
                    </div>
                    <div class="room-card-body">
                        ${checkedIn ? guestRows : '<div style="color:var(--text-secondary);text-align:center;padding:8px;">Keine Gäste</div>'}
                    </div>
                </div>`;
            card.style.display = 'block';
        } else {
            card.innerHTML = `
                <div class="room-card">
                    <div class="room-card-header checked-out">
                        <span class="room-card-room">Zimmer ${room}</span>
                        <span class="room-card-badge out">Frei</span>
                    </div>
                    <div class="room-card-body">
                        <div style="color:var(--text-secondary);text-align:center;padding:8px;">Zimmer nicht belegt</div>
                    </div>
                </div>`;
            card.style.display = 'block';
        }
        refreshLogs();
    } catch (e) {
        showResult('resultBox', false, 'Fehler: ' + e.message);
        card.style.display = 'none';
    }
}

// --- Connection Test ---
async function testConnection() {
    try {
        const result = await apiCall('check_connection', {});
        const ok = result.success;
        const pcsOk = result.pcs_reachable === 'OK';
        const subs = result.subscriptions || 0;
        let msg = `Lokale API: ${result.local_api || 'FAIL'}\nPCS (${result.pcs_host || '?'}): ${result.pcs_reachable || 'FAIL'}\nSubscriptions: ${subs}`;
        showResult('configResultBox', ok && pcsOk, msg);
        document.getElementById('statusDot').classList.toggle('connected', ok && pcsOk);
        document.getElementById('statusText').textContent = (ok && pcsOk) ? `Verbunden (${subs} Sub.)` : 'Nicht verbunden';
        refreshLogs();
    } catch (e) {
        showResult('configResultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Config ---
async function loadConfig() {
    try {
        const cfg = await apiCall('get_config');
        const pc = cfg.procentric || {};
        const fias = cfg.fias || {};
        const mw = cfg.middleware || {};

        document.getElementById('cfg-pc-host').value = pc.host || '';
        document.getElementById('cfg-pc-port').value = pc.port || 60080;
        document.getElementById('cfg-pc-ssl').value = pc.ssl ? '1' : '0';
        document.getElementById('cfg-pc-prefix').value = pc.api_prefix || '/api/pms/v2';
        document.getElementById('cfg-pc-clientid').value = pc.client_id || '';
        document.getElementById('cfg-pc-secret').value = pc.client_secret || '';
        document.getElementById('cfg-fias-host').value = fias.host || '';
        document.getElementById('cfg-fias-port').value = fias.port || 5091;
        document.getElementById('cfg-fias-heartbeat').value = fias.heartbeat_interval || 60;
        renderLangTable(fias.language_map || {});
        document.getElementById('cfg-mw-port').value = mw.listen_port || 80;
        document.getElementById('cfg-mw-maxlog').value = mw.log_max_entries || 500;

        const resync = cfg.resync || {};
        document.getElementById('cfg-resync-enabled').value = resync.enabled ? '1' : '0';
        document.getElementById('cfg-resync-fiasonly').value = (resync.only_when_fias_down !== false) ? '1' : '0';
        document.getElementById('cfg-resync-roomfilter').value = (resync.room_filter !== false) ? '1' : '0';
        renderRoomsTable(resync.rooms || []);
        document.getElementById('cfg-resync-fname').value = resync.pseudo_first_name || 'Max';
        document.getElementById('cfg-resync-lname').value = resync.pseudo_last_name || 'Mustermann';
        document.getElementById('cfg-resync-lang').value = resync.pseudo_language || 'de';
    } catch (e) {
        console.error('Config load failed:', e);
    }
}

async function saveConfig() {
    const config = {
        procentric: {
            host: document.getElementById('cfg-pc-host').value,
            port: parseInt(document.getElementById('cfg-pc-port').value) || 60080,
            ssl: document.getElementById('cfg-pc-ssl').value === '1',
            client_id: document.getElementById('cfg-pc-clientid').value,
            client_secret: document.getElementById('cfg-pc-secret').value,
            api_prefix: document.getElementById('cfg-pc-prefix').value || '/api/pms/v2',
        },
        fias: {
            host: document.getElementById('cfg-fias-host').value,
            port: parseInt(document.getElementById('cfg-fias-port').value) || 5091,
            heartbeat_interval: parseInt(document.getElementById('cfg-fias-heartbeat').value) || 60,
            language_map: getLangMapFromTable(),
        },
        middleware: {
            listen_port: parseInt(document.getElementById('cfg-mw-port').value) || 80,
            log_max_entries: parseInt(document.getElementById('cfg-mw-maxlog').value) || 500,
        },
        resync: {
            enabled: document.getElementById('cfg-resync-enabled').value === '1',
            only_when_fias_down: document.getElementById('cfg-resync-fiasonly').value === '1',
            room_filter: document.getElementById('cfg-resync-roomfilter').value === '1',
            rooms: getRoomsFromTable(),
            pseudo_first_name: document.getElementById('cfg-resync-fname').value || 'Max',
            pseudo_last_name: document.getElementById('cfg-resync-lname').value || 'Mustermann',
            pseudo_language: document.getElementById('cfg-resync-lang').value || 'de',
        }
    };

    try {
        const result = await apiCall('save_config', config);
        showResult('configResultBox', result.success,
            result.success ? 'Konfiguration gespeichert!' : (result.error || 'Fehler beim Speichern')
        );
        refreshLogs();
    } catch (e) {
        showResult('configResultBox', false, 'Fehler: ' + e.message);
    }
}

// --- FIAS ---
async function fiasRefreshStatus() {
    try {
        const result = await apiCall('fias_status', {});
        const d = result.data || {};
        const dot = document.getElementById('fiasDot');
        const dotHeader = document.getElementById('fiasDotHeader');
        const txt = document.getElementById('fiasStatusText');
        const ts = new Date().toLocaleTimeString();
        const fiasBox = document.getElementById('fiasHeaderBox');
        if (d.daemon) {
            dot.className = 'status-dot connected';
            dot.style.background = '';
            dotHeader.className = 'status-dot connected';
            txt.textContent = `Daemon aktiv (PID: ${d.pid}) - ${d.host}`;
            fiasBox.title = `Daemon aktiv (PID: ${d.pid}) - Letzter Check: ${ts}`;
        } else {
            dot.className = 'status-dot';
            dot.style.background = '';
            dotHeader.className = 'status-dot';
            txt.textContent = `Daemon gestoppt - ${d.host}`;
            fiasBox.title = `Daemon gestoppt - Letzter Check: ${ts}`;
        }
    } catch (e) {
        document.getElementById('fiasStatusText').textContent = 'Fehler: ' + e.message;
        document.getElementById('fiasHeaderBox').title = 'Fehler - Letzter Check: ' + new Date().toLocaleTimeString();
    }
}

async function fiasStart() {
    try {
        const result = await apiCall('fias_start', {});
        showResult('resultBox', result.success, result.success ? result.message : result.error);
        setTimeout(fiasRefreshStatus, 1000);
        refreshLogs();
    } catch (e) {
        showResult('resultBox', false, 'Fehler: ' + e.message);
    }
}

async function fiasStop() {
    try {
        const result = await apiCall('fias_stop', {});
        showResult('resultBox', result.success, result.success ? result.message : result.error);
        setTimeout(fiasRefreshStatus, 500);
        refreshLogs();
    } catch (e) {
        showResult('resultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Major Errors ---
async function pollErrors() {
    try {
        const result = await apiCall('get_errors', {});
        const badge = document.getElementById('errorBadge');
        const count = result.count || 0;
        badge.textContent = count;
        badge.className = 'badge' + (count === 0 ? ' zero' : '');
    } catch (e) {}
}

function toggleErrors() {
    const overlay = document.getElementById('errorOverlay');
    const active = overlay.classList.toggle('active');
    if (active) loadErrors();
}

async function loadErrors() {
    try {
        const result = await apiCall('get_errors', {});
        const list = document.getElementById('errorList');
        const errors = result.data || [];
        if (errors.length === 0) {
            list.innerHTML = '<div style="color:var(--text-secondary);text-align:center;padding:20px;">Keine Fehler</div>';
            return;
        }
        let html = '';
        [...errors].reverse().forEach(e => {
            html += `<div class="error-item">
                <span class="error-item-time">${e.timestamp}</span>
                <span class="error-item-type">${e.type}</span>
                <div class="error-item-msg">${escapeHtml(e.message)}</div>
                ${e.raw ? '<div class="error-item-raw">' + escapeHtml(e.raw) + '</div>' : ''}
            </div>`;
        });
        list.innerHTML = html;
    } catch (e) {
        document.getElementById('errorList').innerHTML = '<div style="color:var(--accent-red);padding:20px;">Fehler beim Laden</div>';
    }
}

async function clearErrors() {
    await apiCall('clear_errors', {});
    loadErrors();
    pollErrors();
}

// --- Language Map ---
function renderLangTable(map) {
    const tbody = document.getElementById('langTableBody');
    let html = '';
    for (const [fias, pcs] of Object.entries(map || {})) {
        html += `<tr>
            <td><input type="text" value="${fias}" data-lang-fias="${fias}" readonly style="background:transparent;border-color:transparent;font-weight:600;"></td>
            <td><input type="text" value="${pcs}" data-lang-pcs="${fias}" style="width:60px;"></td>
            <td><button class="del-btn" onclick="this.closest('tr').remove()" title="Entfernen">x</button></td>
        </tr>`;
    }
    tbody.innerHTML = html;
}

function getLangMapFromTable() {
    const map = {};
    document.querySelectorAll('#langTableBody tr').forEach(tr => {
        const inputs = tr.querySelectorAll('input');
        const fias = inputs[0]?.value.trim().toUpperCase();
        const pcs = inputs[1]?.value.trim().toLowerCase();
        if (fias && pcs) map[fias] = pcs;
    });
    return map;
}

function addLangRow() {
    const fias = document.getElementById('newLangFias').value.trim().toUpperCase();
    const pcs = document.getElementById('newLangPcs').value.trim().toLowerCase();
    if (!fias || !pcs) return;
    const tbody = document.getElementById('langTableBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="text" value="${fias}" data-lang-fias="${fias}" readonly style="background:transparent;border-color:transparent;font-weight:600;"></td>
        <td><input type="text" value="${pcs}" data-lang-pcs="${fias}" style="width:60px;"></td>
        <td><button class="del-btn" onclick="this.closest('tr').remove()" title="Entfernen">x</button></td>`;
    tbody.appendChild(tr);
    document.getElementById('newLangFias').value = '';
    document.getElementById('newLangPcs').value = '';
}

// --- Rooms Table ---
function roomRowHtml(room) {
    return `<td><input type="text" value="${escapeHtml(room)}" data-room-id style="width:80px;text-align:center;font-weight:600;"></td>
        <td class="room-status" style="font-size:11px;color:var(--text-secondary);">&ndash;</td>
        <td class="room-lang" style="font-size:11px;color:var(--text-secondary);">&ndash;</td>
        <td><button class="del-btn" onclick="this.closest('tr').remove();updateRoomsCount();" title="Entfernen">x</button></td>`;
}

function renderRoomsTable(rooms) {
    const tbody = document.getElementById('roomsTableBody');
    rooms = (rooms || []).slice().sort((a, b) =>
        String(a).localeCompare(String(b), undefined, {numeric: true, sensitivity: 'base'}));
    let html = '';
    rooms.forEach(room => {
        html += `<tr>${roomRowHtml(room)}</tr>`;
    });
    tbody.innerHTML = html;
    updateRoomsCount();
}

function getRoomsFromTable() {
    const rooms = [];
    document.querySelectorAll('#roomsTableBody tr').forEach(tr => {
        const v = tr.querySelector('input[data-room-id]')?.value.trim();
        if (v) rooms.push(v);
    });
    return rooms;
}

function updateRoomsCount() {
    const n = document.querySelectorAll('#roomsTableBody tr').length;
    const el = document.getElementById('roomsCount');
    if (el) el.textContent = n + ' Zimmer in der Liste';
}

function addRoomRow() {
    const room = document.getElementById('newRoomNumber').value.trim();
    if (!room) return;
    // Dedupe
    const existing = getRoomsFromTable();
    if (existing.includes(room)) {
        document.getElementById('newRoomNumber').value = '';
        return;
    }
    const tbody = document.getElementById('roomsTableBody');
    const tr = document.createElement('tr');
    tr.innerHTML = roomRowHtml(room);
    tbody.appendChild(tr);
    document.getElementById('newRoomNumber').value = '';
    updateRoomsCount();
    fetchRoomStatus(room, tr);
}

async function fetchRoomStatus(room, tr) {
    const statusCell = tr.querySelector('.room-status');
    const langCell = tr.querySelector('.room-lang');
    try {
        const result = await apiCall('room_status&room=' + encodeURIComponent(room));
        if (result.success && result.data) {
            const guests = result.data.guests || [];
            if (guests.length > 0) {
                statusCell.innerHTML = '<span style="color:var(--accent-green);font-weight:600;">Eingecheckt</span>';
                const langs = [...new Set(guests.map(g => g.language || '-').filter(Boolean))];
                langCell.textContent = langs.join(', ') || '-';
            } else {
                statusCell.innerHTML = '<span style="color:var(--text-secondary);">Frei</span>';
                langCell.textContent = '-';
            }
        } else {
            statusCell.innerHTML = '<span style="color:var(--accent-orange);">?</span>';
            langCell.textContent = '-';
        }
    } catch (e) {
        statusCell.innerHTML = '<span style="color:var(--accent-red);">Fehler</span>';
        langCell.textContent = '-';
    }
}

async function refreshAllRoomStatuses() {
    const btn = document.getElementById('roomStatusBtn');
    const rows = [...document.querySelectorAll('#roomsTableBody tr')];
    if (!rows.length) return;
    const total = rows.length;
    let done = 0;
    btn.disabled = true;
    const origLabel = btn.innerHTML;
    const updateBtn = () => { btn.innerHTML = `Lade ${done}/${total}…`; };
    updateBtn();

    // Concurrency-Limit, damit PCS nicht zu fluten
    const queue = rows.slice();
    const workers = Array(6).fill(null).map(async () => {
        while (queue.length) {
            const tr = queue.shift();
            const room = tr.querySelector('input[data-room-id]')?.value.trim();
            if (room) await fetchRoomStatus(room, tr);
            done++;
            updateBtn();
        }
    });
    await Promise.all(workers);
    btn.innerHTML = origLabel;
    btn.disabled = false;
}

// --- FIAS DB-Swap ---
async function fiasDbSwap() {
    try {
        const result = await apiCall('fias_dbswap', {});
        showResult('fiasResultBox', result.success, result.success ? result.message : (result.error || 'Fehler'));
        refreshLogs();
    } catch (e) {
        showResult('fiasResultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Resync ---
async function triggerResyncCheckout() {
    showResult('configResultBox', true, 'Massen-Checkout gestartet...');
    try {
        const result = await apiCall('resync_checkout', {});
        showResult('configResultBox', result.success, result.message || JSON.stringify(result));
        refreshLogs();
    } catch (e) {
        showResult('configResultBox', false, 'Fehler: ' + e.message);
    }
}

async function triggerResyncCheckin() {
    showResult('configResultBox', true, 'Massen-Checkin gestartet...');
    try {
        const result = await apiCall('resync_checkin', {});
        showResult('configResultBox', result.success, result.message || JSON.stringify(result));
        refreshLogs();
    } catch (e) {
        showResult('configResultBox', false, 'Fehler: ' + e.message);
    }
}

// --- Results ---
function showResult(id, success, text) {
    const box = document.getElementById(id);
    box.className = 'result-box ' + (success ? 'success' : 'error');
    box.textContent = text;
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 8000);
}

// --- Logs ---
async function refreshLogs() {
    try {
        const filter = document.getElementById('logFilter').value.toLowerCase();
        const maxLines = parseInt(document.getElementById('logMaxLines').value) || 100;
        const entries = await apiCall('get_logs&max=' + maxLines);
        const logBody = document.getElementById('logBody');

        if (!entries || entries.length === 0) {
            logBody.innerHTML = '<div style="color: var(--text-secondary); text-align: center; margin-top: 40px;">Noch keine Log-Eintr&auml;ge vorhanden</div>';
            return;
        }

        let html = '';
        entries.reverse();
        entries.forEach((entry, i) => {
            const dir = entry.direction || 'IN';
            const dirLower = dir.toLowerCase();
            const isError = entry.type?.includes('ERROR') || entry.type?.includes('WARN');
            const isConfig = entry.type?.includes('CONFIG');
            const isPCS = entry.type?.startsWith('PCS');
            const dirClass = isError ? 'error' : isConfig ? 'config' : (dirLower === 'out' ? 'out' : 'in');

            // Filter
            if (filter) {
                const searchStr = `${entry.timestamp} ${entry.direction} ${entry.type} ${entry.message} ${entry.raw || ''}`.toLowerCase();
                if (!searchStr.includes(filter)) return;
            }

            const time = entry.timestamp ? entry.timestamp.substring(11, 19) : '';
            const rawBlock = entry.raw
                ? `<div class="log-raw">${escapeHtml(entry.raw)}</div>`
                : '';

            html += `<div class="log-entry${rawBlock ? ' has-raw' : ''}" onclick="this.classList.toggle('expanded')">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <span class="log-time">${time}</span>
                    <span class="log-dir ${dirClass}">${dir}</span>
                    <span class="log-type">${escapeHtml(entry.type || '')}</span>
                    <span class="log-message">${escapeHtml(entry.message || '')}</span>
                </div>
                ${rawBlock}
            </div>`;
        });

        logBody.innerHTML = html || '<div style="color:var(--text-secondary);text-align:center;margin-top:40px;">Keine passenden Eintr&auml;ge</div>';

        // Neueste Einträge oben - Scroll bleibt am Anfang
        logBody.scrollTop = 0;
    } catch (e) {
        console.error('Log refresh failed:', e);
    }
}

async function clearLogs() {
    await apiCall('clear_logs', {});
    refreshLogs();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// --- Auto-refresh ---
document.getElementById('autoRefresh').addEventListener('change', function() {
    autoRefreshEnabled = this.checked;
    if (autoRefreshEnabled) {
        startAutoRefresh();
    } else {
        clearInterval(autoRefreshTimer);
    }
});

function startAutoRefresh() {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = setInterval(refreshLogs, 3000);
}

// --- Log scroll detection ---
document.getElementById('logBody').addEventListener('scroll', function() {
    const el = this;
    logAutoScroll = (el.scrollHeight - el.scrollTop - el.clientHeight) < 50;
});

// --- Connection status polling ---
async function pollConnectionStatus() {
    try {
        const result = await apiCall('check_connection', {});
        const ok = result.success;
        const pcsOk = result.pcs_reachable === 'OK';
        const subs = result.subscriptions || 0;
        document.getElementById('statusDot').classList.toggle('connected', ok && pcsOk);
        document.getElementById('pcsHeaderBox').title = 'Letzter Check: ' + new Date().toLocaleTimeString();
    } catch (e) {
        document.getElementById('statusDot').classList.remove('connected');
        document.getElementById('pcsHeaderBox').title = 'Letzter Check: ' + new Date().toLocaleTimeString() + ' (Fehler)';
    }
}

// --- Theme Toggle ---
function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('pms-theme', next);
    updateThemeButton(next);
}

function updateThemeButton(theme) {
    const icon = document.getElementById('themeIcon');
    const label = document.getElementById('themeLabel');
    if (theme === 'light') {
        // Show moon icon (switch to dark)
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
        label.textContent = 'Dunkel';
    } else {
        // Show sun icon (switch to light)
        icon.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
        label.textContent = 'Hell';
    }
}

// Apply saved theme on load
(function() {
    const saved = localStorage.getItem('pms-theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeButton(saved);
})();

// --- Init ---
document.addEventListener('DOMContentLoaded', () => {
    loadConfig();
    refreshLogs();
    startAutoRefresh();
    pollConnectionStatus();
    fiasRefreshStatus();
    pollErrors();
    setInterval(pollConnectionStatus, 30000);
    setInterval(fiasRefreshStatus, 15000);
    setInterval(pollErrors, 10000);
});
</script>

</body>
</html>
