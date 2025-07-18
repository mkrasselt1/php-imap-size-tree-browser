// Globale Variablen
let imapData = null;
let currentData = null;
let history = [];
let selectedUid = null;
let currentModal = null;
let lastScanType = 'normal'; // Merken des verwendeten Scan-Typs

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
});

function loadFormData() {
  ['server', 'port', 'user', 'ssl', 'pass'].forEach(id => {
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
  
  // Lade den letzten Scan-Typ
  const savedScanType = localStorage.getItem('lastScanType');
  if (savedScanType) {
    lastScanType = savedScanType;
  }
}

function saveFormData() {
  ['server', 'port', 'user', 'ssl', 'pass'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
      const value = element.type === 'checkbox' ? element.checked : element.value;
      localStorage.setItem(id, value);
    }
  });
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
}

async function handleFormSubmit(e) {
  e.preventDefault();
  
  saveFormData();
  showLoading();

  const formData = new FormData(e.target);
  
  try {
    // Check if demo mode is enabled
    const isDemoMode = document.getElementById('demo').checked;
    
    if (isDemoMode) {
      // Use demo data
      updateLoadingText('Demo-Daten werden geladen...');
      await new Promise(resolve => setTimeout(resolve, 1000)); // Simulate loading
      
      imapData = demoData;
      currentData = imapData;
      localStorage.setItem('imapTreeData', JSON.stringify(imapData));
      
      updateLoadingText('Demo-Daten erfolgreich geladen!');
      await new Promise(resolve => setTimeout(resolve, 500));
      
      showVisualization();
      return;
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
  lastScanType = 'normal'; // Scan-Typ merken
  localStorage.setItem('lastScanType', 'normal'); // Scan-Typ speichern
  
  const response = await fetch('imap-scan.php', {
    method: 'POST',
    body: formData
  });

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
  lastScanType = 'progressive'; // Scan-Typ merken
  localStorage.setItem('lastScanType', 'progressive'); // Scan-Typ speichern
  
  // Schritt 1: Initialisierung
  updateLoadingText('Verbindung wird aufgebaut...');
  
  formData.append('action', 'init');
  const initResponse = await fetch('imap-scan-progressive.php', {
    method: 'POST',
    body: formData
  });

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
    const folderFormData = new FormData();
    folderFormData.append('server', localStorage.getItem('server'));
    folderFormData.append('port', localStorage.getItem('port'));
    folderFormData.append('user', localStorage.getItem('user'));
    folderFormData.append('pass', localStorage.getItem('pass'));
    folderFormData.append('ssl', localStorage.getItem('ssl'));
    folderFormData.append('action', 'scan');
    folderFormData.append('cacheKey', cacheKey);
    folderFormData.append('folderIndex', i.toString());
    
    const scanResponse = await fetch('imap-scan-progressive.php', {
      method: 'POST',
      body: folderFormData
    });

    if (!scanResponse.ok) {
      console.warn(`Fehler beim Scannen von Ordner ${i}: HTTP ${scanResponse.status}`);
      continue;
    }

    const scanData = await scanResponse.json();
    
    if (scanData.error) {
      console.warn(`Fehler beim Scannen von Ordner ${i}: ${scanData.error}`);
      continue;
    }

    // Fortschritt aktualisieren
    updateProgress(scanData.progress.percent, 
      `${scanData.progress.current}/${scanData.progress.total} - ${scanData.folder.name}`);
    
    results.push(scanData.folder);
    
    // Kurze Pause zwischen Requests
    await new Promise(resolve => setTimeout(resolve, 100));
  }

  // Schritt 3: Finalisierung
  updateLoadingText('Ergebnisse werden zusammengefasst...');
  
  const finalizeFormData = new FormData();
  finalizeFormData.append('server', localStorage.getItem('server'));
  finalizeFormData.append('port', localStorage.getItem('port'));
  finalizeFormData.append('user', localStorage.getItem('user'));
  finalizeFormData.append('pass', localStorage.getItem('pass'));
  finalizeFormData.append('ssl', localStorage.getItem('ssl'));
  finalizeFormData.append('action', 'finalize');
  finalizeFormData.append('cacheKey', cacheKey);
  
  const finalResponse = await fetch('imap-scan-progressive.php', {
    method: 'POST',
    body: finalizeFormData
  });

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
    current = current.parent; // Assuming parent reference exists
  }
  
  let html = '<span class="breadcrumb-item" onclick="navigateToRoot()">🏠 Root</span>';
  path.forEach((item, index) => {
    html += ` <span style="color: rgba(255,255,255,0.5);">›</span> `;
    html += `<span class="breadcrumb-item ${index === path.length - 1 ? 'active' : ''}" 
               onclick="navigateToLevel(${index})">${item.name}</span>`;
  });
  
  breadcrumb.innerHTML = html;
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
    
    const icon = item.type === 'mail' ? '📧' : 
                 item.type === 'other-mails' ? '📦' : '📁';
    
    // Gekürzte und vollständige Namen vorbereiten
    const shortName = item.type === 'mail' ? truncateText(item.name, 25) : item.name;
    const fullName = item.name;
    
    element.innerHTML = `
      <span class="item-text" title="${fullName}">${icon} ${shortName}</span>
      <span style="color: #666; font-size: 0.9rem;">${formatSize(item.size || item.childrenTotalSize || 0)}</span>
      ${item.children && item.children.length > 0 ? '<span class="sidebar-toggle">▶</span>' : ''}
    `;
    
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
    .sum(d => d.size || 0)
    .sort((a, b) => b.value - a.value);
  
  // Wähle Tiling-Algorithmus basierend auf Anzahl der Elemente
  const leafCount = root.leaves().length;
  let tileMethod;
  
  if (leafCount <= 10) {
    // Wenige Elemente: Slice-and-Dice für nebeneinander
    tileMethod = d3.treemapSliceDice;
  } else if (leafCount <= 50) {
    // Mittlere Anzahl: Squarify mit ausgewogenem Ratio
    tileMethod = d3.treemapSquarify.ratio(1.5);
  } else {
    // Viele Elemente: Binäre Aufteilung
    tileMethod = d3.treemapBinary;
  }
  
  d3.treemap()
    .size([width, height])
    .padding(2)
    .round(true)
    .tile(tileMethod)
    (root);
  
  const svg = d3.select(container)
    .append('svg')
    .attr('width', width)
    .attr('height', height);
  
  const baseColors = [
    '#e63946', '#457b9d', '#2a9d8f', '#f4a261',
    '#a8dadc', '#b7b7a4', '#ffb4a2', '#6d6875'
  ];
  
  // Parent folders
  svg.selectAll('.parent-node')
    .data(root.descendants().filter(d => d.depth === 1))
    .enter()
    .append('rect')
    .attr('class', 'parent-node')
    .attr('x', d => d.x0 - 2)
    .attr('y', d => d.y0 - 2)
    .attr('width', d => d.x1 - d.x0 + 4)
    .attr('height', d => d.y1 - d.y0 + 4)
    .attr('fill', 'rgba(255,255,255,0.1)')
    .attr('stroke', 'rgba(51,51,51,0.3)')
    .attr('stroke-width', 2)
    .attr('rx', 6);
  
  // Parent labels
  svg.selectAll('.parent-label')
    .data(root.descendants().filter(d => d.depth === 1))
    .enter()
    .append('text')
    .attr('class', 'parent-label')
    .attr('x', d => d.x0 + 6)
    .attr('y', d => d.y0 + 16)
    .attr('font-size', '12px')
    .attr('font-weight', 'bold')
    .attr('fill', '#333')
    .each(function(d) {
      const element = d3.select(this);
      const width = d.x1 - d.x0;
      const height = d.y1 - d.y0;
      
      // Nur bei ausreichend großen Parent-Boxen Text anzeigen
      if (width < 80 || height < 25) {
        element.style('display', 'none');
        return;
      }
      
      // Maximale Textlänge basierend auf Boxbreite
      const maxChars = Math.max(5, Math.floor((width - 20) / 8)); // ~8px pro Zeichen für Bold
      const name = truncateText(d.data.name, maxChars);
      
      element.text(`📁 ${name}`)
        .style('max-width', (width - 12) + 'px');
    });
  
  // Leaf nodes
  const nodes = svg.selectAll('.node')
    .data(root.leaves())
    .enter()
    .append('g')
    .attr('class', 'node')
    .attr('transform', d => `translate(${d.x0},${d.y0})`);
  
  nodes.append('rect')
    .attr('width', d => d.x1 - d.x0)
    .attr('height', d => d.y1 - d.y0)
    .attr('fill', d => {
      // Warnung für nicht gescannte Mails
      if (d.data.warning) {
        return '#fff3cd';
      }
      
      let rootChild = d;
      while (rootChild.depth > 1) rootChild = rootChild.parent;
      const idx = root.children.indexOf(rootChild);
      const baseColor = baseColors[idx % baseColors.length];
      const intensity = Math.min((d.depth - 1) * 0.15, 0.6);
      return shadeColor(baseColor, intensity);
    })
    .attr('stroke', d => d.data.warning ? '#ffc107' : 'rgba(255,255,255,0.8)')
    .attr('stroke-width', d => d.data.warning ? 2 : 1)
    .attr('stroke-dasharray', d => d.data.warning ? '5,5' : 'none')
    .attr('rx', d => d.data.type === 'mail' ? 4 : 2)
    .style('cursor', 'pointer')
    .each(function(d) {
      const width = d.x1 - d.x0;
      const height = d.y1 - d.y0;
      
      // Sehr kleine Boxen als "tiny" markieren
      if (width < 30 || height < 15) {
        d3.select(this.parentNode).classed('tiny', true);
      }
      
      // Warnung-Klasse hinzufügen
      if (d.data.warning) {
        d3.select(this.parentNode).classed('warning-node', true);
      }
    })
    .on('mouseover', function(event, d) {
      const width = d.x1 - d.x0;
      const height = d.y1 - d.y0;
      
      // Nur bei ausreichend großen Boxen Hover-Effekt
      if (width >= 30 && height >= 15) {
        d3.select(this)
          .attr('stroke', 'var(--primary-color)')
          .attr('stroke-width', 2)
          .style('filter', 'drop-shadow(2px 2px 4px rgba(0,119,204,0.3))');
      }
    })
    .on('mouseout', function(event, d) {
      d3.select(this)
        .attr('stroke', 'rgba(255,255,255,0.8)')
        .attr('stroke-width', 1)
        .style('filter', 'none');
    });
  
  nodes.append('text')
    .attr('x', 4)
    .attr('y', 16)
    .attr('font-size', '10px')
    .attr('fill', '#333')
    .style('pointer-events', 'none')
    .style('overflow', 'hidden')
    .style('text-overflow', 'ellipsis')
    .style('white-space', 'nowrap')
    .each(function(d) {
      const element = d3.select(this);
      const width = d.x1 - d.x0;
      const height = d.y1 - d.y0;
      
      // Mindestgröße für Textanzeige: 60px Breite und 20px Höhe
      if (width < 60 || height < 20) {
        element.style('display', 'none');
        return;
      }
      
      const icon = d.data.type === 'mail' ? '📧' : 
                  d.data.type === 'other-mails' ? '📦' : '📁';
      
      // Maximale Textlänge basierend auf Boxbreite
      const maxChars = Math.max(5, Math.floor((width - 20) / 6)); // ~6px pro Zeichen
      const name = d.data.type === 'mail' ? 
                   truncateText(d.data.name, maxChars) : 
                   truncateText(d.data.name, maxChars);
      
      element.text(`${icon} ${name}`)
        .attr('width', width - 8) // Padding berücksichtigen
        .style('max-width', (width - 8) + 'px');
    });
  
  // Tooltip für vollständige Namen
  nodes.append('title')
    .text(d => {
      const icon = d.data.type === 'mail' ? '📧' : 
                  d.data.type === 'other-mails' ? '📦' : '📁';
      return `${icon} ${d.data.name}\nGröße: ${formatSize(d.data.size || 0)}`;
    });
  
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
  let html = `<h3>📄 ${item.name}</h3>`;
  html += `<p><strong>Größe:</strong> ${formatSize(item.size || 0)}</p>`;
  
  if (item.type === 'mail') {
    html += `<p><strong>Von:</strong> ${item.from || 'Unbekannt'}</p>`;
    html += `<p><strong>Datum:</strong> ${item.date || 'Unbekannt'}</p>`;
    html += `<p><strong>UID:</strong> ${item.uid || 'Unbekannt'}</p>`;
  } else if (item.type === 'other-mails') {
    html += `<p><strong>Typ:</strong> Zusammengefasste E-Mails</p>`;
    html += `<p><strong>Anzahl:</strong> ${item.count || 'Unbekannt'}</p>`;
    
    // Warnung für nicht gescannte Mails
    if (item.warning && item.details) {
      html += `<div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 0.75rem; margin: 1rem 0;">`;
      html += `<p style="margin: 0; color: #856404;"><strong>⚠️ Hinweis:</strong></p>`;
      html += `<p style="margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;">${item.details}</p>`;
      html += `<button class="btn btn-warning" onclick="extendedScanFolder('${item.folderFull}')" style="margin-top: 0.5rem;">`;
      html += `🔍 Vollständigen Scan starten</button>`;
      html += `</div>`;
    }
  }
  
  if (history.length > 0) {
    html += `<button class="btn btn-secondary" onclick="navigateBack()" style="margin-top: 1rem;">⬅️ Zurück</button>`;
  }
  
  infoPanel.innerHTML = html;
}

async function extendedScanFolder(folderPath) {
  try {
    showLoading();
    updateLoadingText('Erweiterte Analyse wird gestartet...');
    
    const formData = new FormData();
    formData.append('server', localStorage.getItem('server'));
    formData.append('port', localStorage.getItem('port'));
    formData.append('user', localStorage.getItem('user'));
    formData.append('pass', localStorage.getItem('pass'));
    formData.append('ssl', localStorage.getItem('ssl'));
    formData.append('action', 'extended-scan');
    formData.append('folderFullPath', folderPath);
    formData.append('startIndex', '0');
    formData.append('batchSize', '1000');
    
    const response = await fetch('imap-scan-progressive.php', {
      method: 'POST',
      body: formData
    });
    
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
  formData.append('server', localStorage.getItem('server'));
  formData.append('port', localStorage.getItem('port'));
  formData.append('user', localStorage.getItem('user'));
  formData.append('pass', localStorage.getItem('pass'));
  formData.append('ssl', localStorage.getItem('ssl'));
  formData.append('folder', mail.folderFull || mail.folder || '');
  formData.append('uid', mail.uid);
  
  console.log('Requesting mail details:', {
    folder: mail.folderFull || mail.folder,
    uid: mail.uid,
    mailObject: mail
  });
  
  const response = await fetch('imap-mail.php', {
    method: 'POST',
    body: formData
  });
  
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
  // Remove existing modal
  if (currentModal) {
    currentModal.remove();
  }
  
  // Debug-Informationen für Inkonsistenz-Prüfung
  console.log('Mail from scan:', {
    name: mail.name,
    uid: mail.uid,
    folder: mail.folder,
    folderFull: mail.folderFull,
    rawSubject: mail.rawSubject
  });
  
  console.log('Mail content from server:', {
    subject: mailContent.subject,
    debug: mailContent.debug
  });
  
  // Warnung bei Inkonsistenz
  if (mailContent.debug && mailContent.debug.rawSubject !== mail.rawSubject) {
    console.warn('Subject mismatch detected:', {
      scanSubject: mail.rawSubject,
      serverSubject: mailContent.debug.rawSubject
    });
  }
  
  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="modal-content">
      <div class="modal-header">
        <h2>📧 ${mailContent.subject || 'Kein Betreff'}</h2>
        <button class="close-btn" onclick="closeModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="mail-detail">
          <label>Von:</label>
          <span>${mailContent.from || 'Unbekannt'}</span>
        </div>
        <div class="mail-detail">
          <label>Datum:</label>
          <span>${mailContent.date || 'Unbekannt'}</span>
        </div>
        <div class="mail-detail">
          <label>Größe:</label>
          <span>${formatSize(mail.size || 0)}</span>
        </div>
        <div class="mail-detail">
          <label>UID:</label>
          <span>${mail.uid} (Ordner: ${mail.folder})</span>
        </div>
        ${mailContent.debug && mailContent.debug.rawSubject !== mail.rawSubject ? `
          <div class="mail-detail" style="background: #fff3cd; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0;">
            <label style="color: #856404;">⚠️ Debug-Info:</label>
            <div style="font-size: 0.9rem; color: #856404;">
              <div>Scan-Betreff: ${mail.rawSubject || 'leer'}</div>
              <div>Server-Betreff: ${mailContent.debug.rawSubject || 'leer'}</div>
            </div>
          </div>
        ` : ''}
        ${mailContent.attachments && mailContent.attachments.length > 0 ? `
          <div class="mail-detail">
            <label>Anhänge:</label>
            <div class="attachment-list">
              ${mailContent.attachments.map(att => `
                <div class="attachment-item">
                  <div class="attachment-info">
                    <div class="attachment-name">📎 ${att.filename}</div>
                    <div class="attachment-size">${formatSize(att.size)}</div>
                  </div>
                  <button class="btn btn-secondary" onclick="downloadAttachment('${att.filename}', '${att.partNum}', '${mail.uid}', '${mail.folderFull || mail.folder}')">
                    💾 Download
                  </button>
                </div>
              `).join('')}
            </div>
          </div>
        ` : ''}
        <div class="mail-detail">
          <label>Nachricht:</label>
          <div class="mail-content">${mailContent.html || mailContent.text || 'Kein Inhalt verfügbar'}</div>
        </div>
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
          <button class="btn btn-danger" onclick="deleteMail('${mail.uid}', '${mail.folderFull || mail.folder}')">
            🗑️ E-Mail löschen
          </button>
          <button class="btn btn-secondary" onclick="closeModal()">
            Schließen
          </button>
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  currentModal = modal;
  
  // Close on outside click
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
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'imap-download.php';
  form.target = '_blank';
  
  const fields = {
    server: localStorage.getItem('server'),
    port: localStorage.getItem('port'),
    user: localStorage.getItem('user'),
    pass: localStorage.getItem('pass'),
    ssl: localStorage.getItem('ssl'),
    folder: folder,
    uid: uid,
    partNum: partNum,
    filename: filename
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
}

async function deleteMail(uid, folder) {
  if (!confirm('Möchten Sie diese E-Mail wirklich löschen?')) {
    return;
  }
  
  try {
    const formData = new FormData();
    formData.append('server', localStorage.getItem('server'));
    formData.append('port', localStorage.getItem('port'));
    formData.append('user', localStorage.getItem('user'));
    formData.append('pass', localStorage.getItem('pass'));
    formData.append('ssl', localStorage.getItem('ssl'));
    formData.append('folder', folder);
    formData.append('uid', uid);
    
    const response = await fetch('imap-delete.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      closeModal();
      showSuccess('E-Mail erfolgreich gelöscht!');
      
      // Refresh data mit dem gleichen Scan-Typ wie ursprünglich verwendet
      await refreshDataAfterDelete();
      
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
    refreshFormData.append('server', localStorage.getItem('server'));
    refreshFormData.append('port', localStorage.getItem('port'));
    refreshFormData.append('user', localStorage.getItem('user'));
    refreshFormData.append('pass', localStorage.getItem('pass'));
    refreshFormData.append('ssl', localStorage.getItem('ssl'));
    
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

async function handleFormSubmit(e) {
  e.preventDefault();
  
  // Teste mit Demo-Daten
  currentData = demoData;
  imapData = demoData;
  showVisualization();
  
  /*
  saveFormData();
  showLoading();

  const formData = new FormData(e.target);
  
  try {
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
  */
}
