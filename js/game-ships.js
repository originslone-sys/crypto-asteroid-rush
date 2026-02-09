/* ============================================
   UNOBIX - Ship Designs v4.0
   File: js/game-ships.js
   8 Naves com identidade visual única
   
   v4.0: Redesign completo
   - Cores totalmente distintas entre si
   - Propriedade shipShape define silhueta única
   - SVG previews melhorados na seleção
   - Descrições em PT-BR
   ============================================ */

const SHIP_DESIGNS = {
    // Ship 1: Phoenix Crimson — Caça equilibrado
    // Silhueta: Fighter clássico (nariz fino, asas angulares)
    PHOENIX: {
        id: 'PHOENIX',
        primary: '#C42030',
        secondary: '#FFB800',
        accent: '#FF6B35',
        details: '#FFE0CC',
        engineGlow: '#FF4400',
        cockpitTint: '#44AAFF',
        name: 'Phoenix Crimson',
        number: 1,
        description: 'Caça equilibrado e versátil',
        shipShape: 'fighter',
        // Preview SVG customizado
        svgPreview: `
            <polygon points="0,-38 12,20 -12,20" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <polygon points="-10,5 -42,-8 -36,18" fill="PRIM" opacity="0.9"/>
            <polygon points="10,5 42,-8 36,18" fill="PRIM" opacity="0.9"/>
            <ellipse cx="0" cy="-12" rx="6" ry="10" fill="COCK" opacity="0.6"/>
            <circle cx="-10" cy="24" r="3" fill="ENG" opacity="0.9"/>
            <circle cx="10" cy="24" r="3" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 2: Forest Guardian — Tanque resistente
    // Silhueta: Bombardeiro largo (corpo largo, asas curtas e grossas)
    GUARDIAN: {
        id: 'GUARDIAN',
        primary: '#2B6B35',
        secondary: '#C8A84E',
        accent: '#5DAF4C',
        details: '#D4CAA0',
        engineGlow: '#88CC44',
        cockpitTint: '#66FFAA',
        name: 'Forest Guardian',
        number: 2,
        description: 'Blindagem pesada, resistente a danos',
        shipShape: 'bomber',
        svgPreview: `
            <polygon points="0,-30 18,22 -18,22" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <rect x="-30" y="0" width="20" height="14" rx="3" fill="PRIM" opacity="0.9"/>
            <rect x="10" y="0" width="20" height="14" rx="3" fill="PRIM" opacity="0.9"/>
            <rect x="-6" y="-18" width="12" height="14" rx="2" fill="COCK" opacity="0.5"/>
            <circle cx="-8" cy="26" r="4" fill="ENG" opacity="0.9"/>
            <circle cx="8" cy="26" r="4" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 3: Thunder Strike — Interceptor veloz
    // Silhueta: Asa delta (triangular, aerodinâmico)
    THUNDER: {
        id: 'THUNDER',
        primary: '#1654A8',
        secondary: '#00D4FF',
        accent: '#4C8CFF',
        details: '#B0DDFF',
        engineGlow: '#00CCFF',
        cockpitTint: '#00FFFF',
        name: 'Thunder Strike',
        number: 3,
        description: 'Interceptor de alta velocidade',
        shipShape: 'delta',
        svgPreview: `
            <polygon points="0,-36 38,22 -38,22" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <polygon points="0,-36 8,10 -8,10" fill="SEC" opacity="0.3"/>
            <ellipse cx="0" cy="-10" rx="5" ry="12" fill="COCK" opacity="0.6"/>
            <circle cx="0" cy="24" r="4" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 4: Inferno Blaze — Canhão de fogo
    // Silhueta: Caça pesado (corpo robusto, canhões laterais)
    INFERNO: {
        id: 'INFERNO',
        primary: '#D44800',
        secondary: '#FF2200',
        accent: '#FFB300',
        details: '#FFE0AA',
        engineGlow: '#FF6600',
        cockpitTint: '#FFCC44',
        name: 'Inferno Blaze',
        number: 4,
        description: 'Poder de fogo devastador',
        shipShape: 'heavy',
        svgPreview: `
            <polygon points="0,-32 14,24 -14,24" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <rect x="-24" y="-20" width="6" height="34" rx="2" fill="ACC" opacity="0.8"/>
            <rect x="18" y="-20" width="6" height="34" rx="2" fill="ACC" opacity="0.8"/>
            <circle cx="-21" cy="-22" r="2.5" fill="ENG" opacity="0.9"/>
            <circle cx="21" cy="-22" r="2.5" fill="ENG" opacity="0.9"/>
            <ellipse cx="0" cy="-12" rx="6" ry="8" fill="COCK" opacity="0.6"/>
            <circle cx="-7" cy="26" r="3" fill="ENG" opacity="0.9"/>
            <circle cx="7" cy="26" r="3" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 5: Nebula Phantom — Nave furtiva
    // Silhueta: Stealth (angular, facetado, assimétrico)
    NEBULA: {
        id: 'NEBULA',
        primary: '#6B1FA0',
        secondary: '#B44CFF',
        accent: '#E066FF',
        details: '#E0BBFF',
        engineGlow: '#AA22FF',
        cockpitTint: '#FF44FF',
        name: 'Nebula Phantom',
        number: 5,
        description: 'Nave furtiva, difícil de atingir',
        shipShape: 'stealth',
        svgPreview: `
            <polygon points="0,-36 30,8 22,22 -22,22 -30,8" fill="PRIM" stroke="SEC" stroke-width="1.5"/>
            <polygon points="0,-36 6,0 -6,0" fill="SEC" opacity="0.3"/>
            <polygon points="-30,8 -40,14 -22,22" fill="PRIM" opacity="0.7"/>
            <polygon points="30,8 40,14 22,22" fill="PRIM" opacity="0.7"/>
            <ellipse cx="0" cy="-8" rx="5" ry="8" fill="COCK" opacity="0.5"/>
            <circle cx="-12" cy="24" r="2.5" fill="ENG" opacity="0.9"/>
            <circle cx="12" cy="24" r="2.5" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 6: Toxic Viper — Serpente ágil
    // Silhueta: Estreita e longa (corpo fino, asas swept-back)
    VIPER: {
        id: 'VIPER',
        primary: '#0D803A',
        secondary: '#00FF66',
        accent: '#A8FF44',
        details: '#CCFFAA',
        engineGlow: '#44FF00',
        cockpitTint: '#00FF88',
        name: 'Toxic Viper',
        number: 6,
        description: 'Serpente ágil, manobras rápidas',
        shipShape: 'viper',
        svgPreview: `
            <polygon points="0,-40 8,24 -8,24" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <polygon points="-6,8 -36,18 -30,24 -8,18" fill="PRIM" opacity="0.85"/>
            <polygon points="6,8 36,18 30,24 8,18" fill="PRIM" opacity="0.85"/>
            <ellipse cx="0" cy="-16" rx="4" ry="10" fill="COCK" opacity="0.6"/>
            <circle cx="0" cy="28" r="3" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 7: Steel Wolf — Assalto pesado
    // Silhueta: Brawler (largo, simétrico, escudos laterais)
    WOLF: {
        id: 'WOLF',
        primary: '#404058',
        secondary: '#8888AA',
        accent: '#A0A0C0',
        details: '#D0D0E8',
        engineGlow: '#6688FF',
        cockpitTint: '#88AAFF',
        name: 'Steel Wolf',
        number: 7,
        description: 'Assalto pesado, máxima destruição',
        shipShape: 'brawler',
        svgPreview: `
            <polygon points="0,-28 20,20 -20,20" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <rect x="-34" y="-4" width="14" height="22" rx="4" fill="SEC" opacity="0.5"/>
            <rect x="20" y="-4" width="14" height="22" rx="4" fill="SEC" opacity="0.5"/>
            <polygon points="-20,4 -34,0 -34,14 -20,18" fill="PRIM" opacity="0.7"/>
            <polygon points="20,4 34,0 34,14 20,18" fill="PRIM" opacity="0.7"/>
            <rect x="-5" y="-16" width="10" height="12" rx="3" fill="COCK" opacity="0.5"/>
            <circle cx="-10" cy="24" r="3.5" fill="ENG" opacity="0.9"/>
            <circle cx="10" cy="24" r="3.5" fill="ENG" opacity="0.9"/>
        `
    },
    
    // Ship 8: Solar Hawk — Explorador solar
    // Silhueta: Asas duplas em X (X-wing style, painéis solares)
    HAWK: {
        id: 'HAWK',
        primary: '#B8860B',
        secondary: '#FFD700',
        accent: '#FFF176',
        details: '#FFF8DC',
        engineGlow: '#FFAB00',
        cockpitTint: '#80D8FF',
        name: 'Solar Hawk',
        number: 8,
        description: 'Explorador solar, alcance máximo',
        shipShape: 'xwing',
        svgPreview: `
            <polygon points="0,-36 10,22 -10,22" fill="PRIM" stroke="SEC" stroke-width="2"/>
            <polygon points="-8,-8 -40,-22 -36,-14" fill="PRIM" opacity="0.85"/>
            <polygon points="8,-8 40,-22 36,-14" fill="PRIM" opacity="0.85"/>
            <polygon points="-8,10 -38,20 -34,26" fill="PRIM" opacity="0.75"/>
            <polygon points="8,10 38,20 34,26" fill="PRIM" opacity="0.75"/>
            <line x1="-40" y1="-22" x2="-38" y2="20" stroke="SEC" stroke-width="1.5" opacity="0.6"/>
            <line x1="40" y1="-22" x2="38" y2="20" stroke="SEC" stroke-width="1.5" opacity="0.6"/>
            <ellipse cx="0" cy="-12" rx="5" ry="9" fill="COCK" opacity="0.6"/>
            <circle cx="-5" cy="26" r="3" fill="ENG" opacity="0.9"/>
            <circle cx="5" cy="26" r="3" fill="ENG" opacity="0.9"/>
        `
    }
};

// Get random ship design
function getRandomShipDesign() {
    const designs = Object.values(SHIP_DESIGNS);
    return designs[Math.floor(Math.random() * designs.length)];
}

// Select ship for current session
function selectShipForSession(designKey) {
    if (SHIP_DESIGNS[designKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[designKey];
        localStorage.setItem('selectedShipKey', designKey);
        updateShipSelectionUI(designKey);
        updateSelectedShipInfo(gameState.selectedShipDesign);
        showNotification('NAVE SELECIONADA', gameState.selectedShipDesign.name, true);
        console.log('🚀 Ship selected:', gameState.selectedShipDesign.name);
    }
}

// Get ship for game
function getShipForGame() {
    if (gameState.selectedShipDesign) {
        return gameState.selectedShipDesign;
    }
    
    const savedShipKey = localStorage.getItem('selectedShipKey');
    if (savedShipKey && SHIP_DESIGNS[savedShipKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[savedShipKey];
        return SHIP_DESIGNS[savedShipKey];
    }
    
    const randomDesign = getRandomShipDesign();
    return randomDesign;
}

// Update ship selection UI
function updateShipSelectionUI(selectedKey) {
    document.querySelectorAll('.ship-card').forEach(card => {
        const key = card.dataset.design;
        if (key === selectedKey) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    });
}

// Clear ship selection
function clearShipSelection() {
    gameState.selectedShipDesign = null;
    localStorage.removeItem('selectedShipKey');
    updateShipSelectionUI(null);
}

/**
 * Gera SVG preview com cores aplicadas
 */
function _buildShipSVG(design) {
    let svg = design.svgPreview || '';
    svg = svg.replace(/PRIM/g, design.primary);
    svg = svg.replace(/SEC/g, design.secondary);
    svg = svg.replace(/ACC/g, design.accent);
    svg = svg.replace(/COCK/g, design.cockpitTint);
    svg = svg.replace(/ENG/g, design.engineGlow);
    return svg;
}

/**
 * Create ship selection UI — Cards com preview e info
 */
function addShipSelectionUI() {
    const shipSelection = document.getElementById('shipSelection');
    if (!shipSelection) return;
    
    shipSelection.innerHTML = Object.entries(SHIP_DESIGNS).map(([key, design]) => `
        <div class="ship-card" data-design="${key}" title="${design.name}">
            <div class="ship-card-preview">
                <svg viewBox="-50 -50 100 80" class="ship-svg">
                    ${_buildShipSVG(design)}
                </svg>
            </div>
            <div class="ship-card-info">
                <span class="ship-card-name" style="color: ${design.secondary}">${design.name}</span>
                <span class="ship-card-desc">${design.description}</span>
            </div>
            <div class="ship-card-number">${design.number}</div>
        </div>
    `).join('');
    
    // Event listeners
    document.querySelectorAll('.ship-card').forEach(card => {
        card.addEventListener('click', (e) => {
            e.stopPropagation();
            selectShipForSession(card.dataset.design);
        });
    });
    
    // Restaurar seleção do localStorage
    const savedShipKey = localStorage.getItem('selectedShipKey');
    if (savedShipKey && SHIP_DESIGNS[savedShipKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[savedShipKey];
        updateShipSelectionUI(savedShipKey);
        updateSelectedShipInfo(SHIP_DESIGNS[savedShipKey]);
    } else {
        gameState.selectedShipDesign = null;
        updateSelectedShipInfo(null);
    }
}

// Helper function
function shadeColor(color, percent) {
    if (!color || color.length < 7) return color;
    const num = parseInt(color.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent);
    const R = Math.max(0, Math.min(255, (num >> 16) + amt));
    const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
    const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

// Export
window.SHIP_DESIGNS = SHIP_DESIGNS;
window.getRandomShipDesign = getRandomShipDesign;
window.selectShipForSession = selectShipForSession;
window.getShipForGame = getShipForGame;
window.updateShipSelectionUI = updateShipSelectionUI;
window.addShipSelectionUI = addShipSelectionUI;
window.clearShipSelection = clearShipSelection;

console.log('📦 game-ships.js v4.0 carregado (8 naves únicas)');
