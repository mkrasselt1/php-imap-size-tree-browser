// Globale Variablen
let imapData = null;
let currentData = null;
let history = [];
let selectedUid = null;
let currentModal = null;
let lastScanType = 'normal'; // Merken des verwendeten Scan-Typs
let csrfToken = '';

// Security: HTML-Escaping für User-Daten
function escapeHtml(str) {
  if (str == null) return '';
  const div = document.createElement('div');
  div.appendChild(document.createTextNode(String(str)));
  return div.innerHTML;
}

// Credential helpers: password from sessionStorage, rest from localStorage
function getCredential(key) {
  if (key === 'pass') {
    return sessionStorage.getItem('pass') || '';
  }
  return localStorage.getItem(key) || '';
}

// CSRF token management
async function fetchCsrfToken() {
  try {
    const res = await fetch('csrf-token.php');
    const data = await res.json();
    csrfToken = data.token || '';
  } catch (e) {
    console.error('CSRF-Token konnte nicht geladen werden:', e);
  }
}

// Append CSRF token to FormData
function appendCsrfToken(formData) {
  formData.append('csrf_token', csrfToken);
}

// ALTCHA challenge solver
async function solveAltchaChallenge() {
  try {
    const res = await fetch('altcha-challenge.php');
    const challenge = await res.json();

    for (let n = 0; n <= challenge.maxnumber; n++) {
      const hash = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(challenge.salt + n)
      );
      const hashHex = Array.from(new Uint8Array(hash))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
      if (hashHex === challenge.challenge) {
        return btoa(JSON.stringify({
          algorithm: challenge.algorithm,
          challenge: challenge.challenge,
          number: n,
          salt: challenge.salt,
          signature: challenge.signature
        }));
      }
    }
    throw new Error('Challenge konnte nicht gelöst werden');
  } catch (e) {
    console.error('ALTCHA-Fehler:', e);
    return '';
  }
}

// Append ALTCHA token to FormData
async function appendAltchaToken(formData) {
  const token = await solveAltchaChallenge();
  formData.append('altcha', token);
}

// Append both CSRF + ALTCHA to FormData
async function appendSecurityTokens(formData) {
  appendCsrfToken(formData);
  await appendAltchaToken(formData);
}

// Demo data for testing
const demoData = {
  name: 'INBOX',
  type: 'folder',
  size: 52428800, // 50MB
  children: [
    {
      name: 'INBOX',
      type: 'folder',
      size: 20971520, // 20MB
      children: [
        {
          name: 'Re: Projektbesprechung nächste Woche',
          type: 'mail',
          size: 2097152, // 2MB
          uid: 'demo-1',
          from: 'kollege@firma.de',
          subject: 'Re: Projektbesprechung nächste Woche',
          date: new Date('2025-07-15').toISOString()
        },
        {
          name: 'Newsletter: Neue Features verfügbar',
          type: 'mail',
          size: 1048576, // 1MB
          uid: 'demo-2',
          from: 'news@service.com',
          subject: 'Newsletter: Neue Features verfügbar',
          date: new Date('2025-07-14').toISOString()
        },
        {
          name: 'Urlaubsantrag genehmigt',
          type: 'mail',
          size: 512000, // 500KB
          uid: 'demo-3',
          from: 'hr@firma.de',
          subject: 'Urlaubsantrag genehmigt',
          date: new Date('2025-07-13').toISOString()
        }
      ]
    },
    {
      name: 'Sent',
      type: 'folder',
      size: 15728640, // 15MB
      children: [
        {
          name: 'Projektbesprechung nächste Woche',
          type: 'mail',
          size: 1048576, // 1MB
          uid: 'demo-4',
          from: 'user@firma.de',
          subject: 'Projektbesprechung nächste Woche',
          date: new Date('2025-07-12').toISOString()
        },
        {
          name: 'Fwd: Wichtige Dokumentation',
          type: 'mail',
          size: 5242880, // 5MB
          uid: 'demo-5',
          from: 'user@firma.de',
          subject: 'Fwd: Wichtige Dokumentation',
          date: new Date('2025-07-11').toISOString()
        }
      ]
    },
    {
      name: 'Trash',
      type: 'folder',
      size: 15728640, // 15MB
      children: [
        {
          name: 'Alte Rechnungen',
          type: 'mail',
          size: 3145728, // 3MB
          uid: 'demo-6',
          from: 'billing@service.com',
          subject: 'Alte Rechnungen',
          date: new Date('2025-06-01').toISOString()
        },
        {
          name: 'Spam-Nachricht',
          type: 'mail',
          size: 102400, // 100KB
          uid: 'demo-7',
          from: 'spam@spammer.com',
          subject: 'Spam-Nachricht',
          date: new Date('2025-06-01').toISOString()
        },
        {
          name: 'Nicht gescannte E-Mails',
          type: 'other-mails',
          size: 12480512, // 12MB
          count: 850,
          warning: true,
          folderFull: 'Trash'
        }
      ]
    }
  ]
};

// Utility-Funktionen
const formatSize = (bytes) => {
  if (!bytes || bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const k = 1024;
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${units[i]}`;
};

const formatNumber = (num) => {
  return new Intl.NumberFormat('de-DE').format(num);
};

const debounce = (func, wait) => {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
};

// Hilfsfunktion für Textkürzung
const truncateText = (text, maxLength = 30) => {
  if (!text || text.length <= maxLength) return text;
  return text.substring(0, maxLength) + '...';
};

// Hilfsfunktion für erweiterte Mail-Namen
const formatMailName = (item, truncate = true) => {
  if (!item || item.type !== 'mail') return item?.name || '';
  
  const icon = '📧';
  const name = item.name || 'Kein Betreff';
  
  if (truncate) {
    return `${icon} ${truncateText(name, 25)}`;
  } else {
    return `${icon} ${name}`;
  }
};

// Initialisierung
document.addEventListener('DOMContentLoaded', function() {
  loadFormData();
  checkExistingData();
  setupEventListeners();
  fetchCsrfToken();

  console.log('IMAP Analyse Tool geladen');
  console.log('Submit-Button gefunden:', document.getElementById('imapForm'));
});

function loadFormData() {
  ['server', 'port', 'user', 'ssl'].forEach(id => {
    const element = document.getElementById(id);
    const value = localStorage.getItem(id);
    if (value && element) {
      if (element.type === 'checkbox') {
        element.checked = value === 'true';
      } else {
        element.value = value;
      }
    }
  });
  // Password from sessionStorage
  const passEl = document.getElementById('pass');
  const passVal = sessionStorage.getItem('pass');
  if (passVal && passEl) {
    passEl.value = passVal;
  }
  
  // Lade den letzten Scan-Typ
  const savedScanType = localStorage.getItem('lastScanType');
  if (savedScanType) {
    lastScanType = savedScanType;
  }
}

function saveFormData() {
  ['server', 'port', 'user', 'ssl'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
      const value = element.type === 'checkbox' ? element.checked : element.value;
      localStorage.setItem(id, value);
    }
  });
  // Password in sessionStorage (not localStorage)
  const passEl = document.getElementById('pass');
  if (passEl) {
    sessionStorage.setItem('pass', passEl.value);
  }
}

function checkExistingData() {
  const existingData = localStorage.getItem('imapTreeData');
  if (existingData) {
    try {
      imapData = JSON.parse(existingData);
      currentData = imapData;
      showVisualization();
    } catch (e) {
      console.error('Fehler beim Laden der gespeicherten Daten:', e);
      localStorage.removeItem('imapTreeData');
    }
  }
}

function setupEventListeners() {
  document.getElementById('imapForm').addEventListener('submit', handleFormSubmit);
  window.addEventListener('resize', debounce(handleResize, 300));

  // Data-attribute based event delegation (no inline onclick)
  document.addEventListener('click', (e) => {
    const action = e.target.closest('[data-action]')?.dataset.action;
    if (action === 'showHelp') showHelp();
    else if (action === 'hideHelp') hideHelp();
    else if (action === 'showLogin') showLogin();
    else if (action === 'showExtendedScanOptions') showExtendedScanOptions();
    else if (action === 'navigateBack') navigateBack();
    else if (action === 'navigateToRoot') navigateToRoot();
    else if (action === 'closeModal') closeModal();

    const preset = e.target.closest('[data-preset]')?.dataset.preset;
    if (preset) setPreset(preset);
  });
}

async function handleFormSubmit(e) {
  e.preventDefault();
  
  saveFormData();
  showLoading();

  const formData = new FormData(e.target);

  // Get ALTCHA value from widget if present
  const altchaWidget = document.querySelector('altcha-widget');
  if (altchaWidget && altchaWidget.value) {
    formData.append('altcha', altchaWidget.value);
  }
  appendCsrfToken(formData);
  
  try {
    // Check if demo mode is enabled
    const isDemoMode = document.getElementById('demo').checked;
    
    if (isDemoMode) {
      // Use demo data from external JSON file
      updateLoadingText('Demo-Daten werden geladen...');
      await new Promise(resolve => setTimeout(resolve, 500)); // Simulate loading
      
      try {
        const response = await fetch('demo-data.json');
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        const demoData = await response.json();
        
        imapData = demoData;
        currentData = imapData;
        localStorage.setItem('imapTreeData', JSON.stringify(imapData));
        
        updateLoadingText('Demo-Daten erfolgreich geladen!');
        await new Promise(resolve => setTimeout(resolve, 500));
        
        showVisualization();
        return;
      } catch (error) {
        console.error('Fehler beim Laden der Demo-Daten:', error);
        showError('Fehler beim Laden der Demo-Daten: ' + error.message);
        showLogin();
        return;
      }
    }
    
    // Entscheidung zwischen normalem und progressivem Scan
    const useProgressiveScan = confirm(
      'Ihr Postfach wird analysiert.\n\n' +
      'Für große Postfächer (>5GB) empfehlen wir den progressiven Scan.\n\n' +
      'Klicken Sie "OK" für progressiven Scan oder "Abbrechen" für normalen Scan.'
    );

    if (useProgressiveScan) {
      await handleProgressiveScan(formData);
    } else {
      await handleNormalScan(formData);
    }
    
  } catch (error) {
    console.error('Fehler beim Laden der IMAP-Daten:', error);
    showError('Verbindung fehlgeschlagen: ' + error.message);
    showLogin();
  }
}

async function handleNormalScan(formData) {
  lastScanType = 'normal';
  localStorage.setItem('lastScanType', 'normal');

  const response = await fetch('imap-scan.php', {
    method: 'POST',
    body: formData
  });

  await fetchCsrfToken();

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  const data = await response.json();
  
  if (data.error) {
    throw new Error(data.error);
  }

  imapData = data;
  currentData = data;
  localStorage.setItem('imapTreeData', JSON.stringify(data));
  showVisualization();
}

async function handleProgressiveScan(formData) {
  lastScanType = 'progressive';
  localStorage.setItem('lastScanType', 'progressive');

  // Schritt 1: Initialisierung
  updateLoadingText('Verbindung wird aufgebaut...');

  formData.append('action', 'init');
  const initResponse = await fetch('imap-scan-progressive.php', {
    method: 'POST',
    body: formData
  });

  await fetchCsrfToken();

  if (!initResponse.ok) {
    throw new Error(`HTTP ${initResponse.status}: ${initResponse.statusText}`);
  }

  const initData = await initResponse.json();
  
  if (initData.error) {
    throw new Error(initData.error);
  }

  // Schritt 2: Ordner scannen
  showProgressBar();
  updateLoadingText('Ordner werden analysiert...');
  
  const results = [];
  const cacheKey = initData.cacheKey;
  
  for (let i = 0; i < initData.totalFolders; i++) {
    let scanData = null;
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        // FormData pro Versuch neu erstellen (fetch konsumiert den Body)
        const folderFormData = new FormData();
        folderFormData.append('server', getCredential('server'));
        folderFormData.append('port', getCredential('port'));
        folderFormData.append('user', getCredential('user'));
        folderFormData.append('pass', getCredential('pass'));
        folderFormData.append('ssl', getCredential('ssl'));
        folderFormData.append('action', 'scan');
        folderFormData.append('cacheKey', cacheKey);
        folderFormData.append('folderIndex', i.toString());
        await appendSecurityTokens(folderFormData);

        const scanResponse = await fetch('imap-scan-progressive.php', {
          method: 'POST',
          body: folderFormData
        });

        if (!scanResponse.ok) {
          console.warn(`Ordner ${i}: HTTP ${scanResponse.status} (Versuch ${attempt + 1})`);
          await new Promise(resolve => setTimeout(resolve, 2000));
          continue;
        }

        const text = await scanResponse.text();
        if (!text) {
          console.warn(`Ordner ${i}: Leere Antwort (Versuch ${attempt + 1})`);
          await new Promise(resolve => setTimeout(resolve, 3000));
          continue;
        }

        scanData = JSON.parse(text);
        break;
      } catch (e) {
        console.warn(`Ordner ${i}: ${e.message} (Versuch ${attempt + 1})`);
        await new Promise(resolve => setTimeout(resolve, 3000));
      }
    }

    if (!scanData || scanData.error) {
      console.warn(`Ordner ${i} übersprungen: ${scanData?.error || 'keine Antwort nach 3 Versuchen'}`);
      continue;
    }

    // Fortschritt aktualisieren
    updateProgress(scanData.progress.percent,
      `${scanData.progress.current}/${scanData.progress.total} - ${scanData.folder.name}`);

    results.push(scanData.folder);

    // Pause zwischen Requests — IMAP-Server Rate-Limiting vermeiden
    await new Promise(resolve => setTimeout(resolve, 1000));
  }

  // Schritt 3: Finalisierung
  updateLoadingText('Ergebnisse werden zusammengefasst...');
  
  const finalizeFormData = new FormData();
  finalizeFormData.append('server', getCredential('server'));
  finalizeFormData.append('port', getCredential('port'));
  finalizeFormData.append('user', getCredential('user'));
  finalizeFormData.append('pass', getCredential('pass'));
  finalizeFormData.append('ssl', getCredential('ssl'));
  finalizeFormData.append('action', 'finalize');
  finalizeFormData.append('cacheKey', cacheKey);
  await appendSecurityTokens(finalizeFormData);

  const finalResponse = await fetch('imap-scan-progressive.php', {
    method: 'POST',
    body: finalizeFormData
  });

  await fetchCsrfToken();

  if (!finalResponse.ok) {
    throw new Error(`HTTP ${finalResponse.status}: ${finalResponse.statusText}`);
  }

  const finalData = await finalResponse.json();
  
  if (finalData.error) {
    throw new Error(finalData.error);
  }

  imapData = finalData;
  currentData = finalData;
  localStorage.setItem('imapTreeData', JSON.stringify(finalData));
  showVisualization();
}

function updateLoadingText(text) {
  const loadingText = document.getElementById('loadingText');
  if (loadingText) {
    loadingText.textContent = text;
  }
}

function showProgressBar() {
  const progressContainer = document.getElementById('progressContainer');
  if (progressContainer) {
    progressContainer.style.display = 'block';
  }
}

function updateProgress(percent, details) {
  const progressFill = document.getElementById('progressFill');
  const progressText = document.getElementById('progressText');
  const progressDetails = document.getElementById('progressDetails');
  
  if (progressFill) {
    progressFill.style.width = percent + '%';
  }
  
  if (progressText) {
    progressText.textContent = `${percent}% - Analyse läuft...`;
  }
  
  if (progressDetails && details) {
    progressDetails.textContent = `Aktueller Ordner: ${details}`;
  }
}

function showLogin() {
  document.getElementById('loginSection').style.display = 'block';
  document.getElementById('loadingSection').classList.remove('active');
  document.getElementById('visualizationSection').classList.remove('active');
  history = [];
  selectedUid = null;
}

function showLoading() {
  document.getElementById('loginSection').style.display = 'none';
  document.getElementById('loadingSection').classList.add('active');
  document.getElementById('visualizationSection').classList.remove('active');
  
  // Fortschrittsanzeige zurücksetzen
  const progressContainer = document.getElementById('progressContainer');
  const progressFill = document.getElementById('progressFill');
  const progressText = document.getElementById('progressText');
  const progressDetails = document.getElementById('progressDetails');
  
  if (progressContainer) progressContainer.style.display = 'none';
  if (progressFill) progressFill.style.width = '0%';
  if (progressText) progressText.textContent = '0% - Initialisierung...';
  if (progressDetails) progressDetails.textContent = 'Bitte haben Sie Geduld. Große Postfächer werden schrittweise analysiert.';
}

function showVisualization() {
  document.getElementById('loginSection').style.display = 'none';
  document.getElementById('loadingSection').classList.remove('active');
  document.getElementById('visualizationSection').classList.add('active');
  
  updateStats();
  updateBreadcrumb();
  renderSidebar();
  renderTreemap();
}

function showError(message) {
  const alert = document.createElement('div');
  alert.className = 'alert alert-error';
  alert.textContent = message;
  
  const container = document.querySelector('.container');
  container.insertBefore(alert, container.firstChild);
  
  setTimeout(() => {
    alert.remove();
  }, 5000);
}

function showSuccess(message) {
  const alert = document.createElement('div');
  alert.className = 'alert alert-success';
  alert.textContent = message;
  
  const container = document.querySelector('.container');
  container.insertBefore(alert, container.firstChild);
  
  setTimeout(() => {
    alert.remove();
  }, 5000);
}

function updateStats() {
  if (!currentData) return;

  const stats = calculateStats(currentData);
  const statsGrid = document.getElementById('statsGrid');
  if (!statsGrid) return;

  statsGrid.innerHTML = `
    <div class="stat-card">
      <div class="stat-value">${formatNumber(stats.totalMails)}</div>
      <div class="stat-label">E-Mails</div>
    </div>
    <div class="stat-card">
      <div class="stat-value">${formatSize(stats.totalSize)}</div>
      <div class="stat-label">Gesamtgröße</div>
    </div>
    <div class="stat-card">
      <div class="stat-value">${formatNumber(stats.totalFolders)}</div>
      <div class="stat-label">Ordner</div>
    </div>
    <div class="stat-card">
      <div class="stat-value">${formatSize(stats.avgMailSize)}</div>
      <div class="stat-label">⌀ Mail-Größe</div>
    </div>
  `;
}

function calculateStats(data) {
  const stats = {
    totalMails: 0,
    totalSize: 0,
    totalFolders: 0,
    avgMailSize: 0
  };

  function traverse(node) {
    if (node.type === 'mail') {
      stats.totalMails++;
      stats.totalSize += node.size || 0;
    } else if (node.type === 'other-mails') {
      stats.totalMails += node.count || 0;
      stats.totalSize += node.size || 0;
    } else if (node.children) {
      stats.totalFolders++;
      node.children.forEach(traverse);
    }
  }

  traverse(data);
  stats.avgMailSize = stats.totalMails > 0 ? stats.totalSize / stats.totalMails : 0;
  return stats;
}

function updateBreadcrumb() {
  const breadcrumb = document.getElementById('breadcrumb');
  const path = [];

  let current = currentData;
  while (current && current !== imapData) {
    path.unshift(current);
    current = current.parent;
  }

  breadcrumb.textContent = '';

  const rootSpan = document.createElement('span');
  rootSpan.className = 'breadcrumb-item';
  rootSpan.textContent = '\uD83C\uDFE0 Root';
  rootSpan.addEventListener('click', navigateToRoot);
  breadcrumb.appendChild(rootSpan);

  path.forEach((item, index) => {
    const sep = document.createElement('span');
    sep.style.color = 'rgba(255,255,255,0.5)';
    sep.textContent = ' \u203A ';
    breadcrumb.appendChild(sep);

    const span = document.createElement('span');
    span.className = 'breadcrumb-item' + (index === path.length - 1 ? ' active' : '');
    span.textContent = item.name;
    span.addEventListener('click', () => navigateToLevel(index));
    breadcrumb.appendChild(span);
  });
}

function navigateToRoot() {
  history = [];
  currentData = imapData;
  showVisualization();
}

function navigateToLevel(level) {
  // Implementation for navigating to specific level
  // This would require storing the navigation path
}

function renderSidebar() {
  const sidebar = document.getElementById('sidebar');
  sidebar.innerHTML = '';
  
  if (currentData && currentData.children) {
    renderSidebarItems(currentData.children, sidebar, 0);
  }
}

function renderSidebarItems(items, container, depth) {
  items.forEach(item => {
    const element = document.createElement('div');
    element.className = 'sidebar-item';
    element.style.paddingLeft = `${depth * 20 + 16}px`;

    if (item.children) {
      element.classList.add('folder');
    }

    const icon = item.type === 'mail' ? '\uD83D\uDCE7' :
                 item.type === 'other-mails' ? '\uD83D\uDCE6' : '\uD83D\uDCC1';

    const shortName = item.type === 'mail' ? truncateText(item.name, 25) : item.name;

    // Build with DOM API (no innerHTML with user data)
    const textSpan = document.createElement('span');
    textSpan.className = 'item-text';
    textSpan.title = item.name;
    textSpan.textContent = `${icon} ${shortName}`;
    element.appendChild(textSpan);

    const sizeSpan = document.createElement('span');
    sizeSpan.style.cssText = 'color: #666; font-size: 0.9rem;';
    sizeSpan.textContent = formatSize(item.size || item.childrenTotalSize || 0);
    element.appendChild(sizeSpan);

    if (item.children && item.children.length > 0) {
      const toggleSpan = document.createElement('span');
      toggleSpan.className = 'sidebar-toggle';
      toggleSpan.textContent = '\u25B6';
      element.appendChild(toggleSpan);
    }
    
    element.addEventListener('click', (e) => {
      e.stopPropagation();
      handleSidebarItemClick(item);
    });
    
    container.appendChild(element);
    
    if (item.children) {
      const childContainer = document.createElement('div');
      childContainer.style.display = 'none';
      renderSidebarItems(item.children, childContainer, depth + 1);
      container.appendChild(childContainer);
      
      const toggle = element.querySelector('.sidebar-toggle');
      if (toggle) {
        toggle.addEventListener('click', (e) => {
          e.stopPropagation();
          const isExpanded = childContainer.style.display === 'block';
          childContainer.style.display = isExpanded ? 'none' : 'block';
          toggle.textContent = isExpanded ? '▶' : '▼';
        });
      }
    }
  });
}

function handleSidebarItemClick(item) {
  if (item.type === 'mail') {
    selectedUid = item.uid;
    highlightSelectedItem();
    showItemInfo(item);
    showMailModal(item);
  } else if (item.type === 'other-mails') {
    selectedUid = item.name;
    highlightSelectedItem();
    showItemInfo(item);
    
    // Automatisch erweiteten Scan anbieten für Warnungen
    if (item.warning && item.folderFull) {
      setTimeout(() => {
        const shouldScan = confirm(
          `Dieser Ordner enthält ${item.count} nicht gescannte E-Mails.\n\n` +
          `Möchten Sie eine vollständige Analyse starten?\n\n` +
          `⚠️ Hinweis: Dies kann bei sehr großen Ordnern einige Minuten dauern.`
        );
        if (shouldScan) {
          extendedScanFolder(item.folderFull);
        }
      }, 500);
    }
  } else if (item.children) {
    history.push(currentData);
    currentData = item;
    showVisualization();
  }
}

// Treemap rendering cache
let treemapCache = new Map();
let lastRenderData = null;

function renderTreemap() {
  const container = document.getElementById('chartContainer');
  
  if (!currentData || !currentData.children) {
    container.innerHTML = '';
    return;
  }
  
  // Check if we can use cached rendering
  const dataKey = JSON.stringify(currentData);
  const containerSize = `${container.clientWidth}x${container.clientHeight}`;
  const cacheKey = `${dataKey}-${containerSize}`;
  
  if (treemapCache.has(cacheKey) && lastRenderData === dataKey) {
    // Use cached version
    container.innerHTML = treemapCache.get(cacheKey);
    reattachTreemapEventListeners();
    return;
  }
  
  // Clear container and cache if data changed
  container.innerHTML = '';
  if (lastRenderData !== dataKey) {
    treemapCache.clear();
    lastRenderData = dataKey;
  }
  
  const width = container.clientWidth;
  const height = container.clientHeight;
  
  const root = d3.hierarchy(currentData)
    .sum(d => d.children ? 0 : (d.size || 0))
    .sort((a, b) => b.value - a.value);

  const leafCount = root.leaves().length;

  // Squarify füllt den gesamten Raum in 2D, mit Ordner-Padding für Labels
  d3.treemap()
    .size([width, height])
    .paddingOuter(1)
    .paddingTop(18)
    .paddingInner(1)
    .round(false)
    .tile(d3.treemapSquarify)
    (root);

  // SVG mit Gradient-Definitionen für Cushion-Effekt
  const svg = d3.select(container)
    .append('svg')
    .attr('width', width)
    .attr('height', height);

  const defs = svg.append('defs');

  // Kräftige, satte Farben wie WinDirStat
  const baseColors = [
    '#c41e3a', '#1a6fb5', '#1d8348', '#d4731a',
    '#7d3c98', '#2e86c1', '#b7950b', '#a93226',
    '#148f77', '#6c3483', '#d68910', '#1f618d'
  ];

  // Farbe basierend auf Ordner-Index
  function nodeColor(d) {
    let rootChild = d;
    while (rootChild.depth > 1) rootChild = rootChild.parent;
    const idx = root.children ? root.children.indexOf(rootChild) : 0;
    return baseColors[Math.abs(idx) % baseColors.length];
  }

  // Cushion-Gradient pro Blatt erzeugen
  const leaves = root.leaves();
  leaves.forEach((d, i) => {
    const base = nodeColor(d);
    const lighter = shadeColor(base, 0.4);
    const darker = shadeColor(base, -0.35);

    const grad = defs.append('linearGradient')
      .attr('id', `cushion-${i}`)
      .attr('x1', '0%').attr('y1', '0%')
      .attr('x2', '100%').attr('y2', '100%');
    grad.append('stop').attr('offset', '0%').attr('stop-color', lighter);
    grad.append('stop').attr('offset', '50%').attr('stop-color', base);
    grad.append('stop').attr('offset', '100%').attr('stop-color', darker);
  });

  // Ordner-Hintergründe mit dunklen Rahmen
  const folders = root.descendants().filter(d => d.children && d.depth >= 1);
  svg.selectAll('.folder-bg')
    .data(folders)
    .enter()
    .append('rect')
    .attr('class', 'folder-bg')
    .attr('x', d => d.x0)
    .attr('y', d => d.y0)
    .attr('width', d => Math.max(0, d.x1 - d.x0))
    .attr('height', d => Math.max(0, d.y1 - d.y0))
    .attr('fill', d => {
      const base = nodeColor(d);
      return shadeColor(base, 0.6);
    })
    .attr('stroke', '#222')
    .attr('stroke-width', d => Math.max(1, 3 - d.depth));

  // Ordner-Labels (weiß auf dunklem Hintergrund)
  svg.selectAll('.folder-label')
    .data(folders)
    .enter()
    .append('text')
    .attr('class', 'folder-label')
    .attr('x', d => d.x0 + 4)
    .attr('y', d => d.y0 + 13)
    .attr('font-size', '11px')
    .attr('font-weight', 'bold')
    .attr('fill', '#fff')
    .style('pointer-events', 'none')
    .style('text-shadow', '1px 1px 2px rgba(0,0,0,0.7)')
    .each(function(d) {
      const el = d3.select(this);
      const w = d.x1 - d.x0;
      const h = d.y1 - d.y0;
      if (w < 50 || h < 18) { el.style('display', 'none'); return; }
      const maxChars = Math.max(4, Math.floor((w - 12) / 7));
      el.text(truncateText(d.data.name, maxChars));
    });

  // Blätter mit Cushion-Gradient
  const nodes = svg.selectAll('.node')
    .data(leaves)
    .enter()
    .append('g')
    .attr('class', 'node')
    .attr('transform', d => `translate(${d.x0},${d.y0})`);

  nodes.append('rect')
    .attr('width', d => Math.max(0, d.x1 - d.x0))
    .attr('height', d => Math.max(0, d.y1 - d.y0))
    .attr('fill', (d, i) => `url(#cushion-${i})`)
    .attr('stroke', '#111')
    .attr('stroke-width', 0.5)
    .style('cursor', 'pointer')
    .on('mouseover', function() {
      d3.select(this)
        .attr('stroke', '#fff')
        .attr('stroke-width', 2)
        .style('filter', 'brightness(1.2)');
    })
    .on('mouseout', function() {
      d3.select(this)
        .attr('stroke', '#111')
        .attr('stroke-width', 0.5)
        .style('filter', 'none');
    });

  // Blatt-Labels (weiß mit Schatten)
  nodes.append('text')
    .attr('x', 3)
    .attr('y', 12)
    .attr('font-size', '9px')
    .attr('fill', '#fff')
    .style('pointer-events', 'none')
    .style('text-shadow', '1px 1px 1px rgba(0,0,0,0.8)')
    .each(function(d) {
      const el = d3.select(this);
      const w = d.x1 - d.x0;
      const h = d.y1 - d.y0;
      if (w < 40 || h < 14) { el.style('display', 'none'); return; }
      const maxChars = Math.max(3, Math.floor((w - 8) / 6));
      el.text(truncateText(d.data.name, maxChars));
    });

  // Tooltips
  nodes.append('title')
    .text(d => `${d.data.name}\n${formatSize(d.data.size || 0)}`);
  
  attachTreemapEventListeners(nodes);
  
  // Cache the rendered content
  treemapCache.set(cacheKey, container.innerHTML);
}

function attachTreemapEventListeners(nodes) {
  nodes.on('click', (event, d) => {
    selectedUid = d.data.uid || d.data.name;
    highlightSelectedItem();
    showItemInfo(d.data);
    
    if (d.data.type === 'mail') {
      showMailModal(d.data);
    } else if (d.data.type === 'other-mails' && d.data.warning && d.data.folderFull) {
      // Automatisch erweiterten Scan anbieten für Warnungen
      setTimeout(() => {
        const shouldScan = confirm(
          `Dieser Ordner enthält ${d.data.count} nicht gescannte E-Mails.\n\n` +
          `Möchten Sie eine vollständige Analyse starten?\n\n` +
          `⚠️ Hinweis: Dies kann bei sehr großen Ordnern einige Minuten dauern.`
        );
        if (shouldScan) {
          extendedScanFolder(d.data.folderFull);
        }
      }, 500);
    }
  });
}

function reattachTreemapEventListeners() {
  // Reattach event listeners for cached content
  const container = document.getElementById('chartContainer');
  const nodes = d3.select(container).selectAll('.node');
  
  nodes.on('click', (event, d) => {
    // Extract data from DOM element
    const title = d3.select(event.target.parentNode).select('title').text();
    const matches = title.match(/^([📧📦📁])\s(.+)\nGröße:\s(.+)$/);
    
    if (matches) {
      const [, icon, name, size] = matches;
      const data = {
        name: name,
        size: size,
        type: icon === '📧' ? 'mail' : icon === '📦' ? 'other-mails' : 'folder'
      };
      
      selectedUid = data.name;
      highlightSelectedItem();
      showItemInfo(data);
    }
  });
}

// Remove the old renderTreemap function since we've replaced it above
// function renderTreemap() {
function shadeColor(color, percent) {
  const f = parseInt(color.slice(1), 16);
  const t = percent < 0 ? 0 : 255;
  const p = percent < 0 ? percent * -1 : percent;
  const R = f >> 16;
  const G = (f >> 8) & 0x00FF;
  const B = f & 0x0000FF;
  
  return "#" + (0x1000000 + (Math.round((t - R) * p) + R) * 0x10000 + 
               (Math.round((t - G) * p) + G) * 0x100 + 
               (Math.round((t - B) * p) + B)).toString(16).slice(1);
}

function highlightSelectedItem() {
  // Remove previous highlights
  document.querySelectorAll('.sidebar-item.active').forEach(item => {
    item.classList.remove('active');
  });
  
  // Add highlight to selected item
  const sidebarItems = document.querySelectorAll('.sidebar-item');
  sidebarItems.forEach(item => {
    if (item.textContent.includes(selectedUid)) {
      item.classList.add('active');
    }
  });
}

function showItemInfo(item) {
  const infoPanel = document.getElementById('infoPanel');
  infoPanel.textContent = '';

  const h3 = document.createElement('h3');
  h3.textContent = '\uD83D\uDCC4 ' + (item.name || '');
  infoPanel.appendChild(h3);

  const sizeP = document.createElement('p');
  sizeP.innerHTML = '<strong>Gr\u00F6\u00DFe:</strong> ';
  sizeP.appendChild(document.createTextNode(formatSize(item.size || 0)));
  infoPanel.appendChild(sizeP);

  if (item.type === 'mail') {
    const fromP = document.createElement('p');
    fromP.innerHTML = '<strong>Von:</strong> ';
    fromP.appendChild(document.createTextNode(item.from || 'Unbekannt'));
    infoPanel.appendChild(fromP);

    const dateP = document.createElement('p');
    dateP.innerHTML = '<strong>Datum:</strong> ';
    dateP.appendChild(document.createTextNode(item.date || 'Unbekannt'));
    infoPanel.appendChild(dateP);

    const uidP = document.createElement('p');
    uidP.innerHTML = '<strong>UID:</strong> ';
    uidP.appendChild(document.createTextNode(item.uid || 'Unbekannt'));
    infoPanel.appendChild(uidP);
  } else if (item.type === 'other-mails') {
    const typeP = document.createElement('p');
    typeP.innerHTML = '<strong>Typ:</strong> Zusammengefasste E-Mails';
    infoPanel.appendChild(typeP);

    const countP = document.createElement('p');
    countP.innerHTML = '<strong>Anzahl:</strong> ';
    countP.appendChild(document.createTextNode(item.count || 'Unbekannt'));
    infoPanel.appendChild(countP);

    if (item.warning && item.details) {
      const warningDiv = document.createElement('div');
      warningDiv.style.cssText = 'background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 0.75rem; margin: 1rem 0;';

      const warningTitle = document.createElement('p');
      warningTitle.style.cssText = 'margin: 0; color: #856404;';
      warningTitle.innerHTML = '<strong>\u26A0\uFE0F Hinweis:</strong>';
      warningDiv.appendChild(warningTitle);

      const warningText = document.createElement('p');
      warningText.style.cssText = 'margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;';
      warningText.textContent = item.details;
      warningDiv.appendChild(warningText);

      const scanBtn = document.createElement('button');
      scanBtn.className = 'btn btn-warning';
      scanBtn.style.marginTop = '0.5rem';
      scanBtn.textContent = '\uD83D\uDD0D Vollst\u00E4ndigen Scan starten';
      scanBtn.addEventListener('click', () => extendedScanFolder(item.folderFull));
      warningDiv.appendChild(scanBtn);

      infoPanel.appendChild(warningDiv);
    }
  }

  // "Ordner leeren" Button für Ordner
  if (item.folderFull && item.children) {
    const clearBtn = document.createElement('button');
    clearBtn.className = 'btn btn-danger';
    clearBtn.style.cssText = 'margin-top: 1rem; margin-right: 0.5rem;';
    clearBtn.textContent = '🗑️ Ordner komplett leeren';
    clearBtn.addEventListener('click', () => clearFolder(item));
    infoPanel.appendChild(clearBtn);
  }

  if (history.length > 0) {
    const backBtn = document.createElement('button');
    backBtn.className = 'btn btn-secondary';
    backBtn.style.marginTop = '1rem';
    backBtn.textContent = '\u2B05\uFE0F Zur\u00FCck';
    backBtn.setAttribute('data-action', 'navigateBack');
    infoPanel.appendChild(backBtn);
  }
}

async function extendedScanFolder(folderPath) {
  try {
    showLoading();
    updateLoadingText('Erweiterte Analyse wird gestartet...');

    const formData = new FormData();
    formData.append('server', getCredential('server'));
    formData.append('port', getCredential('port'));
    formData.append('user', getCredential('user'));
    formData.append('pass', getCredential('pass'));
    formData.append('ssl', getCredential('ssl'));
    formData.append('action', 'extended-scan');
    formData.append('folderFullPath', folderPath);
    formData.append('startIndex', '0');
    formData.append('batchSize', '1000');
    await appendSecurityTokens(formData);

    const response = await fetch('imap-scan-progressive.php', {
      method: 'POST',
      body: formData
    });

    await fetchCsrfToken();
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const result = await response.json();
    
    if (result.error) {
      throw new Error(result.error);
    }
    
    // Zeige Ergebnis
    showSuccess(`Erweiterte Analyse abgeschlossen! ${result.mails.length} große E-Mails gefunden.`);
    
    // Aktualisiere die Daten mit den neuen Erkenntnissen
    await refreshDataAfterDelete();
    
  } catch (error) {
    console.error('Fehler bei erweiterter Analyse:', error);
    showError('Erweiterte Analyse fehlgeschlagen: ' + error.message);
    showVisualization();
  }
}

function navigateBack() {
  if (history.length > 0) {
    currentData = history.pop();
    showVisualization();
  }
}

function handleResize() {
  if (document.getElementById('visualizationSection').classList.contains('active')) {
    renderTreemap();
  }
}

// Add responsive algorithm info to the stats grid
function updateResponsiveInfo(width, height, leafCount, selectedMethod) {
  const containerArea = width * height;
  const avgItemSize = containerArea / leafCount;
  
  // Add responsive info to stats if in development mode
  if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    const responsiveInfo = document.createElement('div');
    responsiveInfo.className = 'stat-item responsive-info';
    responsiveInfo.innerHTML = `
      <h4>📐 Layout-Algorithmus</h4>
      <p><strong>${selectedMethod}</strong></p>
      <p class="stat-detail">
        ${leafCount} Elemente auf ${Math.round(containerArea/1000)}k px²<br>
        ⌀ ${Math.round(avgItemSize)} px² pro Element
      </p>
    `;
    
    // Remove existing responsive info
    const existing = document.querySelector('.responsive-info');
    if (existing) existing.remove();
    
    // Add to stats grid
    const statsGrid = document.getElementById('statsGrid');
    if (statsGrid) {
      statsGrid.appendChild(responsiveInfo);
    }
  }
}

async function showMailModal(mail) {
  try {
    const mailContent = await fetchMailContent(mail);
    createMailModal(mailContent, mail);
  } catch (error) {
    console.error('Fehler beim Laden der Mail:', error);
    showError('Fehler beim Laden der E-Mail-Details');
  }
}

async function fetchMailContent(mail) {
  const formData = new FormData();
  formData.append('server', getCredential('server'));
  formData.append('port', getCredential('port'));
  formData.append('user', getCredential('user'));
  formData.append('pass', getCredential('pass'));
  formData.append('ssl', getCredential('ssl'));
  formData.append('folder', mail.folderFull || mail.folder || '');
  formData.append('uid', mail.uid);
  await appendSecurityTokens(formData);

  const response = await fetch('imap-mail.php', {
    method: 'POST',
    body: formData
  });

  await fetchCsrfToken();
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  
  const result = await response.json();
  
  if (result.error) {
    console.error('Mail loading error:', result);
    // Erweiterte Fehlerbehandlung für Trash/Papierkorb
    if (result.error.includes('nicht gefunden') && result.availableUids) {
      console.log('Available UIDs in folder:', result.availableUids);
      console.log('Total UIDs:', result.totalUids);
      console.log('Requested UID:', mail.uid);
      console.log('Message Number:', result.msgno);
    }
  }
  
  return result;
}

function createMailModal(mailContent, mail) {
  if (currentModal) {
    currentModal.remove();
  }

  const modal = document.createElement('div');
  modal.className = 'modal';

  const content = document.createElement('div');
  content.className = 'modal-content';

  // Header
  const header = document.createElement('div');
  header.className = 'modal-header';
  const h2 = document.createElement('h2');
  h2.textContent = '\uD83D\uDCE7 ' + (mailContent.subject || 'Kein Betreff');
  header.appendChild(h2);
  const closeBtn = document.createElement('button');
  closeBtn.className = 'close-btn';
  closeBtn.textContent = '\u00D7';
  closeBtn.addEventListener('click', closeModal);
  header.appendChild(closeBtn);
  content.appendChild(header);

  // Body
  const body = document.createElement('div');
  body.className = 'modal-body';

  // Mail details
  const details = [
    ['Von', mailContent.from || 'Unbekannt'],
    ['Datum', mailContent.date || 'Unbekannt'],
    ['Gr\u00F6\u00DFe', formatSize(mail.size || 0)],
    ['UID', (mail.uid || '') + ' (Ordner: ' + (mail.folderFull || mail.folder || '').replace(/^.*\}/, '') + ')']
  ];

  details.forEach(([label, value]) => {
    const detail = document.createElement('div');
    detail.className = 'mail-detail';
    const labelEl = document.createElement('label');
    labelEl.textContent = label + ':';
    detail.appendChild(labelEl);
    const span = document.createElement('span');
    span.textContent = value;
    detail.appendChild(span);
    body.appendChild(detail);
  });

  // Attachments
  if (mailContent.attachments && mailContent.attachments.length > 0) {
    const attDetail = document.createElement('div');
    attDetail.className = 'mail-detail';
    const attLabel = document.createElement('label');
    attLabel.textContent = 'Anh\u00E4nge:';
    attDetail.appendChild(attLabel);

    const attList = document.createElement('div');
    attList.className = 'attachment-list';

    mailContent.attachments.forEach(att => {
      const attItem = document.createElement('div');
      attItem.className = 'attachment-item';

      const attInfo = document.createElement('div');
      attInfo.className = 'attachment-info';
      const attName = document.createElement('div');
      attName.className = 'attachment-name';
      attName.textContent = '\uD83D\uDCCE ' + att.filename;
      attInfo.appendChild(attName);
      const attSize = document.createElement('div');
      attSize.className = 'attachment-size';
      attSize.textContent = formatSize(att.size);
      attInfo.appendChild(attSize);
      attItem.appendChild(attInfo);

      const dlBtn = document.createElement('button');
      dlBtn.className = 'btn btn-secondary';
      dlBtn.textContent = '\uD83D\uDCBE Download';
      dlBtn.addEventListener('click', () => downloadAttachment(att.filename, att.partNum, mail.uid, mail.folderFull || mail.folder));
      attItem.appendChild(dlBtn);

      attList.appendChild(attItem);
    });

    attDetail.appendChild(attList);
    body.appendChild(attDetail);
  }

  // Mail body — use sandboxed iframe for HTML content
  const bodyDetail = document.createElement('div');
  bodyDetail.className = 'mail-detail';
  const bodyLabel = document.createElement('label');
  bodyLabel.textContent = 'Nachricht:';
  bodyDetail.appendChild(bodyLabel);

  if (mailContent.html) {
    const iframe = document.createElement('iframe');
    iframe.sandbox = 'allow-same-origin';
    iframe.style.cssText = 'width: 100%; min-height: 200px; border: 1px solid #ccc; border-radius: 4px;';
    iframe.srcdoc = mailContent.html;
    bodyDetail.appendChild(iframe);
  } else {
    const pre = document.createElement('pre');
    pre.style.whiteSpace = 'pre-wrap';
    pre.textContent = mailContent.text || 'Kein Inhalt verf\u00FCgbar';
    bodyDetail.appendChild(pre);
  }
  body.appendChild(bodyDetail);

  // Action buttons
  const actions = document.createElement('div');
  actions.style.cssText = 'display: flex; gap: 1rem; margin-top: 1.5rem;';

  const deleteBtn = document.createElement('button');
  deleteBtn.className = 'btn btn-danger';
  deleteBtn.textContent = '\uD83D\uDDD1\uFE0F E-Mail l\u00F6schen';
  deleteBtn.addEventListener('click', () => deleteMail(mail.uid, mail.folderFull || mail.folder));
  actions.appendChild(deleteBtn);

  const closeBtn2 = document.createElement('button');
  closeBtn2.className = 'btn btn-secondary';
  closeBtn2.textContent = 'Schlie\u00DFen';
  closeBtn2.addEventListener('click', closeModal);
  actions.appendChild(closeBtn2);

  body.appendChild(actions);
  content.appendChild(body);
  modal.appendChild(content);

  document.body.appendChild(modal);
  currentModal = modal;

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      closeModal();
    }
  });
}

function closeModal() {
  if (currentModal) {
    currentModal.remove();
    currentModal = null;
  }
}

async function downloadAttachment(filename, partNum, uid, folder) {
  const altchaToken = await solveAltchaChallenge();

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'imap-download.php';
  form.target = '_blank';

  const fields = {
    server: getCredential('server'),
    port: getCredential('port'),
    user: getCredential('user'),
    pass: getCredential('pass'),
    ssl: getCredential('ssl'),
    folder: folder,
    uid: uid,
    partNum: partNum,
    filename: filename,
    csrf_token: csrfToken,
    altcha: altchaToken
  };

  Object.entries(fields).forEach(([key, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = value;
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
  await fetchCsrfToken();
}

// Ordner komplett leeren — 3-stufige Bestätigung
async function clearFolder(item) {
  const folderName = item.name || item.folderFull;
  const mailCount = item.children ? item.children.filter(c => c.type === 'mail').length : '?';

  // === STUFE 1: Normaler Confirm mit vertauschten Buttons ===
  // Wir bauen ein eigenes Modal statt confirm() — damit wir die Buttons tauschen können
  const result1 = await showClearConfirmModal(
    `⚠️ Ordner "${folderName}" leeren?`,
    `Dies wird alle E-Mails in diesem Ordner unwiderruflich löschen (${mailCount} sichtbare Mails).`,
    'Abbrechen',   // links (sieht aus wie "OK")
    'Ich bin sicher' // rechts
  );
  if (!result1) return;

  // === STUFE 2: Klick auf "Abbrechen" zum Bestätigen ===
  const result2 = await showClearConfirmModal(
    `🛑 Wirklich ALLE Mails in "${folderName}" löschen?`,
    'Diese Aktion kann NICHT rückgängig gemacht werden!\n\nKlicken Sie auf "Abbrechen" um fortzufahren.',
    'Abbrechen — Ja, wirklich löschen',  // links — ist eigentlich "bestätigen"
    'Nein, doch nicht'                     // rechts — ist eigentlich "abbrechen"
  );
  if (!result2) return;

  // === STUFE 3: Text eingeben ===
  const confirmText = 'LÖSCHEN';
  const result3 = await showClearInputModal(
    `🔥 Letzte Warnung!`,
    `Tippen Sie "${confirmText}" ein um den Ordner "${folderName}" endgültig zu leeren:`,
    confirmText
  );
  if (!result3) return;

  // === Löschen ausführen ===
  try {
    showLoading();
    updateLoadingText(`Lösche alle Mails in "${folderName}"...`);

    const formData = new FormData();
    formData.append('folder', item.folderFull);
    await appendSecurityTokens(formData);

    const response = await fetch('imap-delete-folder.php', {
      method: 'POST',
      body: formData
    });

    const text = await response.text();
    if (!text) throw new Error('Keine Antwort vom Server');

    const result = JSON.parse(text);

    if (result.success) {
      showSuccess(`${result.message}`);
      // Ordner lokal leeren
      if (item.children) {
        item.children = [];
        item.size = 0;
        item.childrenTotalSize = 0;
      }
      localStorage.setItem('imapTreeData', JSON.stringify(imapData));
      currentData = imapData;
      showVisualization();
    } else {
      throw new Error(result.error || 'Unbekannter Fehler');
    }
  } catch (error) {
    showError('Fehler beim Leeren des Ordners: ' + error.message);
    showVisualization();
  }
}

// Bestätigungs-Modal mit zwei Buttons (links/rechts vertauschbar)
function showClearConfirmModal(title, message, leftBtnText, rightBtnText) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.style.display = 'flex';

    const box = document.createElement('div');
    box.className = 'modal-content';
    box.style.maxWidth = '450px';

    const h2 = document.createElement('h2');
    h2.textContent = title;
    box.appendChild(h2);

    const p = document.createElement('p');
    p.style.whiteSpace = 'pre-line';
    p.textContent = message;
    box.appendChild(p);

    const btnRow = document.createElement('div');
    btnRow.style.cssText = 'display: flex; gap: 1rem; margin-top: 1.5rem; justify-content: flex-end;';

    const leftBtn = document.createElement('button');
    leftBtn.className = 'btn btn-danger';
    leftBtn.textContent = leftBtnText;
    leftBtn.addEventListener('click', () => { overlay.remove(); resolve(true); });

    const rightBtn = document.createElement('button');
    rightBtn.className = 'btn btn-secondary';
    rightBtn.textContent = rightBtnText;
    rightBtn.addEventListener('click', () => { overlay.remove(); resolve(false); });

    // Stufe 1: rechts = bestätigen, Stufe 2: links = bestätigen
    btnRow.appendChild(leftBtn);
    btnRow.appendChild(rightBtn);
    box.appendChild(btnRow);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  });
}

// Bestätigungs-Modal mit Texteingabe
function showClearInputModal(title, message, expectedText) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.style.display = 'flex';

    const box = document.createElement('div');
    box.className = 'modal-content';
    box.style.maxWidth = '450px';

    const h2 = document.createElement('h2');
    h2.textContent = title;
    box.appendChild(h2);

    const p = document.createElement('p');
    p.textContent = message;
    box.appendChild(p);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.placeholder = expectedText;
    input.style.cssText = 'margin: 1rem 0; font-size: 1.1rem; text-align: center; padding: 0.5rem;';
    input.autocomplete = 'off';
    box.appendChild(input);

    const btnRow = document.createElement('div');
    btnRow.style.cssText = 'display: flex; gap: 1rem; margin-top: 1rem; justify-content: flex-end;';

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn btn-danger';
    confirmBtn.textContent = '🔥 Endgültig löschen';
    confirmBtn.disabled = true;
    confirmBtn.addEventListener('click', () => { overlay.remove(); resolve(true); });

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-secondary';
    cancelBtn.textContent = 'Abbrechen';
    cancelBtn.addEventListener('click', () => { overlay.remove(); resolve(false); });

    input.addEventListener('input', () => {
      confirmBtn.disabled = input.value !== expectedText;
    });

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && input.value === expectedText) {
        overlay.remove();
        resolve(true);
      }
    });

    btnRow.appendChild(cancelBtn);
    btnRow.appendChild(confirmBtn);
    box.appendChild(btnRow);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    input.focus();
  });
}

// Mail lokal aus dem Datenbaum entfernen und Größen aktualisieren
function removeMailFromTree(node, uid, folder) {
  if (!node.children) return false;

  for (let i = 0; i < node.children.length; i++) {
    const child = node.children[i];

    // Gefunden: Mail mit passender UID im richtigen Ordner
    if (child.type === 'mail' && String(child.uid) === String(uid) &&
        (child.folderFull === folder || child.folder === folder)) {
      const removedSize = child.size || 0;
      node.children.splice(i, 1);

      // Größen im Elternknoten aktualisieren
      if (node.childrenTotalSize) {
        node.childrenTotalSize -= removedSize;
      }
      if (node.size) {
        node.size -= removedSize;
      }
      return true;
    }

    // Rekursiv in Unterordnern suchen
    if (child.children && removeMailFromTree(child, uid, folder)) {
      // Größen nach oben propagieren
      if (node.childrenTotalSize && child.size) {
        // Neu berechnen
        let total = 0;
        node.children.forEach(c => {
          total += c.size || c.childrenTotalSize || 0;
        });
        node.childrenTotalSize = total;
      }
      return true;
    }
  }
  return false;
}

async function deleteMail(uid, folder) {
  if (!confirm('Möchten Sie diese E-Mail wirklich löschen?')) {
    return;
  }
  
  try {
    const formData = new FormData();
    formData.append('server', getCredential('server'));
    formData.append('port', getCredential('port'));
    formData.append('user', getCredential('user'));
    formData.append('pass', getCredential('pass'));
    formData.append('ssl', getCredential('ssl'));
    formData.append('folder', folder);
    formData.append('uid', uid);
    await appendSecurityTokens(formData);

    const response = await fetch('imap-delete.php', {
      method: 'POST',
      body: formData
    });

    await fetchCsrfToken();
    
    const result = await response.json();
    
    if (result.success) {
      closeModal();
      showSuccess('E-Mail erfolgreich gelöscht!');

      // Mail lokal aus dem Datenbaum entfernen statt komplettem Rescan
      removeMailFromTree(imapData, uid, folder);
      localStorage.setItem('imapTreeData', JSON.stringify(imapData));
      currentData = imapData;
      showVisualization();
      
    } else {
      throw new Error(result.error || 'Unbekannter Fehler');
    }
  } catch (error) {
    console.error('Fehler beim Löschen:', error);
    showError('Fehler beim Löschen der E-Mail: ' + error.message);
  }
}

async function refreshDataAfterDelete() {
  try {
    const refreshFormData = new FormData();
    refreshFormData.append('server', getCredential('server'));
    refreshFormData.append('port', getCredential('port'));
    refreshFormData.append('user', getCredential('user'));
    refreshFormData.append('pass', getCredential('pass'));
    refreshFormData.append('ssl', getCredential('ssl'));
    await appendSecurityTokens(refreshFormData);
    
    showLoading(); // Zeige den Loading-Bereich
    
    if (lastScanType === 'progressive') {
      // Progressiver Scan
      updateLoadingText('Aktualisiere Daten (progressiv)...');
      await handleProgressiveScan(refreshFormData);
      // handleProgressiveScan aktualisiert imapData, currentData und localStorage bereits
    } else {
      // Normaler Scan
      updateLoadingText('Aktualisiere Daten...');
      const refreshResponse = await fetch('imap-scan.php', {
        method: 'POST',
        body: refreshFormData
      });

      await fetchCsrfToken();
      
      if (!refreshResponse.ok) {
        throw new Error(`HTTP ${refreshResponse.status}: ${refreshResponse.statusText}`);
      }
      
      const newData = await refreshResponse.json();
      
      if (newData.error) {
        throw new Error(newData.error);
      }
      
      imapData = newData;
      currentData = newData;
      localStorage.setItem('imapTreeData', JSON.stringify(newData));
    }
    
    showVisualization();
    
  } catch (error) {
    console.error('Fehler beim Aktualisieren der Daten:', error);
    showError('Fehler beim Aktualisieren der Daten: ' + error.message);
    showLogin(); // Zurück zum Login bei Fehlern
  }
}

// Help Section Functions
function showHelp() {
  document.getElementById('loginSection').style.display = 'none';
  document.getElementById('loadingSection').style.display = 'none';
  document.getElementById('visualizationSection').style.display = 'none';
  document.getElementById('helpSection').style.display = 'block';
}

function hideHelp() {
  document.getElementById('helpSection').style.display = 'none';
  
  // Show appropriate section based on current state
  if (imapData) {
    showVisualization();
  } else {
    showLogin();
  }
}

// Extended Scan Modal Functions
function showExtendedScanOptions() {
  if (!imapData || !imapData.children) {
    showError('Keine Daten verfügbar. Bitte führen Sie zuerst eine Analyse durch.');
    return;
  }
  
  // Find folders that might have unscanned emails
  const largeFolders = findLargeFolders(imapData);
  
  if (largeFolders.length === 0) {
    showInfo('Keine Ordner mit unvollständigen Scans gefunden.');
    return;
  }
  
  // Create modal
  const modal = document.createElement('div');
  modal.className = 'modal';

  const modalContent = document.createElement('div');
  modalContent.className = 'modal-content';

  const modalHeader = document.createElement('div');
  modalHeader.className = 'modal-header';
  const h2 = document.createElement('h2');
  h2.textContent = '\uD83D\uDD0D Erweiterte Analyse';
  modalHeader.appendChild(h2);
  const closeBtnEl = document.createElement('button');
  closeBtnEl.className = 'close-btn';
  closeBtnEl.textContent = '\u00D7';
  closeBtnEl.addEventListener('click', closeExtendedScanModal);
  modalHeader.appendChild(closeBtnEl);
  modalContent.appendChild(modalHeader);

  const modalBody = document.createElement('div');
  modalBody.className = 'modal-body';

  const introP = document.createElement('p');
  introP.textContent = 'W\u00E4hlen Sie die Ordner aus, die vollst\u00E4ndig analysiert werden sollen:';
  modalBody.appendChild(introP);

  const folderList = document.createElement('div');
  folderList.className = 'folder-scan-list';
  largeFolders.forEach(folder => {
    const item = document.createElement('div');
    item.className = 'folder-scan-item';

    const info = document.createElement('div');
    info.className = 'folder-info';
    const name = document.createElement('div');
    name.className = 'folder-name';
    name.textContent = folder.name;
    info.appendChild(name);
    const warning = document.createElement('div');
    warning.className = 'folder-warning';
    warning.textContent = '\u26A0\uFE0F M\u00F6glicherweise unvollst\u00E4ndig gescannt';
    info.appendChild(warning);
    item.appendChild(info);

    const scanBtn = document.createElement('button');
    scanBtn.className = 'btn btn-sm btn-warning';
    scanBtn.textContent = '\uD83D\uDD0D Vollst\u00E4ndig scannen';
    scanBtn.addEventListener('click', () => extendedScanFolder(folder.name));
    item.appendChild(scanBtn);

    folderList.appendChild(item);
  });
  modalBody.appendChild(folderList);

  const btnRow = document.createElement('div');
  btnRow.style.marginTop = '1rem';

  const scanAllBtn = document.createElement('button');
  scanAllBtn.className = 'btn btn-primary';
  scanAllBtn.textContent = '\uD83D\uDD0D Alle scannen';
  scanAllBtn.addEventListener('click', extendedScanAll);
  btnRow.appendChild(scanAllBtn);

  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn btn-secondary';
  cancelBtn.textContent = '\u274C Abbrechen';
  cancelBtn.addEventListener('click', closeExtendedScanModal);
  btnRow.appendChild(cancelBtn);

  modalBody.appendChild(btnRow);
  modalContent.appendChild(modalBody);
  modal.appendChild(modalContent);

  document.body.appendChild(modal);
  currentModal = modal;
}

function closeExtendedScanModal() {
  if (currentModal) {
    currentModal.remove();
    currentModal = null;
  }
}

function findLargeFolders(data) {
  const folders = [];
  
  function traverse(node) {
    if (node.children) {
      node.children.forEach(child => {
        if (child.type === 'folder') {
          // Check if folder might have unscanned emails
          const hasWarning = child.children && child.children.some(grandchild => 
            grandchild.type === 'other-mails' && grandchild.warning
          );
          
          if (hasWarning || (child.children && child.children.length > 500)) {
            folders.push({
              name: child.name,
              size: child.size,
              path: child.name
            });
          }
          
          traverse(child);
        }
      });
    }
  }
  
  traverse(data);
  return folders;
}

function extendedScanAll() {
  if (!imapData || !imapData.children) return;
  const largeFolders = findLargeFolders(imapData);
  closeExtendedScanModal();

  (async () => {
    for (const folder of largeFolders) {
      await extendedScanFolder(folder.name);
    }
  })();
}

// Email provider presets
const emailPresets = {
  'gmail': {
    server: 'imap.gmail.com',
    port: 993,
    ssl: true,
    info: 'Gmail - Verwenden Sie ein App-Passwort statt Ihres normalen Passworts'
  },
  'outlook': {
    server: 'imap-mail.outlook.com',
    port: 993,
    ssl: true,
    info: 'Outlook/Hotmail - Moderne Authentifizierung erforderlich'
  },
  'yahoo': {
    server: 'imap.mail.yahoo.com',
    port: 993,
    ssl: true,
    info: 'Yahoo Mail - App-Passwort erforderlich'
  },
  'icloud': {
    server: 'imap.mail.me.com',
    port: 993,
    ssl: true,
    info: 'iCloud - App-spezifisches Passwort erforderlich'
  },
  'web.de': {
    server: 'imap.web.de',
    port: 993,
    ssl: true,
    info: 'Web.de - Standard IMAP-Zugang'
  },
  'gmx': {
    server: 'imap.gmx.net',
    port: 993,
    ssl: true,
    info: 'GMX - Standard IMAP-Zugang'
  }
};

function setPreset(provider) {
  const preset = emailPresets[provider];
  if (!preset) return;
  
  document.getElementById('server').value = preset.server;
  document.getElementById('port').value = preset.port;
  document.getElementById('ssl').checked = preset.ssl;
  
  // Show info about the provider
  showInfo(`${provider.toUpperCase()}: ${preset.info}`);
}

function showInfo(message) {
  const alert = document.createElement('div');
  alert.className = 'alert alert-info';

  const iconSpan = document.createElement('span');
  iconSpan.className = 'alert-icon';
  iconSpan.textContent = '\u2139\uFE0F';
  alert.appendChild(iconSpan);

  const textSpan = document.createElement('span');
  textSpan.className = 'alert-text';
  textSpan.textContent = message;
  alert.appendChild(textSpan);

  const closeBtn = document.createElement('button');
  closeBtn.className = 'alert-close';
  closeBtn.textContent = '\u00D7';
  closeBtn.addEventListener('click', () => alert.remove());
  alert.appendChild(closeBtn);

  document.querySelector('.container').prepend(alert);

  setTimeout(() => {
    if (alert.parentElement) {
      alert.remove();
    }
  }, 10000);
}
