<?php require_once __DIR__.'/security.php'; securityHeaders(); ?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111116">
  <meta name="description" content="TFv1 — Team Fight version.1. Register, build your squad, and run a fair round-robin tournament.">
  <title>TFv1 — Team Fight version.1</title>
  <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
  <link rel="stylesheet" href="assets/style.css">
  <script src="assets/theme.js"></script>
  <script src="assets/app.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <aside class="sidebar" id="sidebar">
    <a href="#overview" class="brand"><img src="assets/logo.svg" alt="TFv1 shield" width="43" height="48"><span>TF<span class="brand-v">v1</span><small>TEAM FIGHT VERSION.1</small></span></a>
    <div class="workspace-label">TOURNAMENT WORKSPACE</div>
    <nav id="navigation" aria-label="Main navigation"></nav>
    <div class="sidebar-bottom">
      <div class="fair-card"><span class="fair-icon" data-icon="shield"></span><strong>Great fights. Fair play.</strong><p>Skill decides the winner.<br>Respect keeps us together.</p><button class="text-button" data-action="rules">Our fair-play code</button></div>
      <button class="theme-toggle" id="theme-toggle"><span data-icon="sun"></span><span id="theme-label">Switch to light mode</span><span class="toggle-track"><i></i></span></button>
      <button type="button" class="sidebar-admin-signin" id="admin-signin" data-action="admin_login"><span class="admin-mark" data-icon="admin" aria-hidden="true"></span><span>Organizer sign in</span></button>
      <div class="version">TFv1 <span>Built for the love of the game</span></div>
    </div>
  </aside>
  <div class="app-shell">
    <header class="topbar">
      <button class="icon-button mobile-menu" aria-label="Toggle navigation" id="menu-toggle"><span data-icon="menu"></span></button>
      <div class="breadcrumb">Workspace <span>/</span> <strong id="breadcrumb-title">Overview</strong></div>
      <div class="topbar-actions"><span class="local-clock" id="clock"></span><div class="divider"></div><div id="account"></div></div>
    </header>
    <main id="main" tabindex="-1"><div class="loading-state"><span class="spinner"></span><p>Getting your arena ready…</p></div></main>
    <footer class="footer"><span>TFv1 <span class="muted">/ Team Fight version.1</span></span><span>Play with purpose. Win with integrity.</span></footer>
  </div>
  <dialog id="modal"><div id="modal-content"></div></dialog>
  <div id="toast" role="status" aria-live="polite"></div>
</body>
</html>
