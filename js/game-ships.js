/* ============================================
   UNOBIX - Ship Designs v4.0
   File: js/game-ships.js
   8 Naves com cores únicas — modelo único de caça
   Seleção persiste no localStorage
   ============================================ */

const SHIP_DESIGNS = {
    PHOENIX: {
        id: 'PHOENIX',
        primary: '#cc2233',
        secondary: '#ffcc00',
        accent: '#ff6644',
        details: '#ffeedd',
        engineGlow: '#ff4400',
        cockpitTint: '#44aaff',
        name: 'Phoenix Crimson',
        number: 1,
        description: 'Caça equilibrado e versátil'
    },
    GUARDIAN: {
        id: 'GUARDIAN',
        primary: '#2d5a27',
        secondary: '#8b6914',
        accent: '#4a7c3f',
        details: '#c4b998',
        engineGlow: '#88cc44',
        cockpitTint: '#66ffaa',
        name: 'Forest Guardian',
        number: 2,
        description: 'Blindagem resistente a danos'
    },
    THUNDER: {
        id: 'THUNDER',
        primary: '#1a4a8a',
        secondary: '#00d4ff',
        accent: '#4488ff',
        details: '#aaddff',
        engineGlow: '#00ccff',
        cockpitTint: '#00ffff',
        name: 'Thunder Strike',
        number: 3,
        description: 'Interceptor de alta velocidade'
    },
    INFERNO: {
        id: 'INFERNO',
        primary: '#cc4400',
        secondary: '#ff2200',
        accent: '#ffaa00',
        details: '#ffddaa',
        engineGlow: '#ff6600',
        cockpitTint: '#ffcc44',
        name: 'Inferno Blaze',
        number: 4,
        description: 'Poder de fogo devastador'
    },
    NEBULA: {
        id: 'NEBULA',
        primary: '#4a1a6b',
        secondary: '#8844cc',
        accent: '#cc44ff',
        details: '#ddaaff',
        engineGlow: '#aa22ff',
        cockpitTint: '#ff44ff',
        name: 'Nebula Phantom',
        number: 5,
        description: 'Ágil e difícil de atingir'
    },
    VIPER: {
        id: 'VIPER',
        primary: '#1a4a2a',
        secondary: '#00ff66',
        accent: '#88ff44',
        details: '#ccffaa',
        engineGlow: '#44ff00',
        cockpitTint: '#00ff88',
        name: 'Toxic Viper',
        number: 6,
        description: 'Manobras ágeis e rápidas'
    },
    WOLF: {
        id: 'WOLF',
        primary: '#3a3a4a',
        secondary: '#6a6a7a',
        accent: '#8a8a9a',
        details: '#cacada',
        engineGlow: '#6688ff',
        cockpitTint: '#88aaff',
        name: 'Steel Wolf',
        number: 7,
        description: 'Assalto pesado e brutal'
    },
    HAWK: {
        id: 'HAWK',
        primary: '#8B6914',
        secondary: '#FFD700',
        accent: '#FFC107',
        details: '#FFF8DC',
        engineGlow: '#FFAB00',
        cockpitTint: '#80D8FF',
        name: 'Solar Hawk',
        number: 8,
        description: 'Explorador solar, alcance máximo'
    }
};

function getRandomShipDesign() {
    const designs = Object.values(SHIP_DESIGNS);
    return designs[Math.floor(Math.random() * designs.length)];
}

function selectShipForSession(designKey) {
    if (SHIP_DESIGNS[designKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[designKey];
        localStorage.setItem('selectedShipKey', designKey);
        updateShipSelectionUI(designKey);
        updateSelectedShipInfo(gameState.selectedShipDesign);
        showNotification('NAVE SELECIONADA', gameState.selectedShipDesign.name, true);
        console.log('\u{1F680} Nave selecionada:', gameState.selectedShipDesign.name);
    }
}

function getShipForGame() {
    if (gameState.selectedShipDesign) {
        return gameState.selectedShipDesign;
    }
    
    const savedShipKey = localStorage.getItem('selectedShipKey');
    if (savedShipKey && SHIP_DESIGNS[savedShipKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[savedShipKey];
        return SHIP_DESIGNS[savedShipKey];
    }
    
    return getRandomShipDesign();
}

function updateShipSelectionUI(selectedKey) {
    document.querySelectorAll('.ship-btn').forEach(btn => {
        const key = btn.dataset.design;
        if (key === selectedKey) {
            btn.classList.add('selected');
        } else {
            btn.classList.remove('selected');
        }
    });
}

function clearShipSelection() {
    gameState.selectedShipDesign = null;
    localStorage.removeItem('selectedShipKey');
    updateShipSelectionUI(null);
}

function addShipSelectionUI() {
    const shipSelection = document.getElementById('shipSelection');
    if (!shipSelection) return;
    
    shipSelection.innerHTML = Object.entries(SHIP_DESIGNS).map(([key, design]) => `
        <div class="ship-btn" data-design="${key}"
             style="background: linear-gradient(135deg, ${design.primary}, ${shadeColor(design.primary, -20)});"
             title="${design.name} - ${design.description}">
            <svg class="ship-preview" viewBox="-50 -50 100 100">
                <!-- Fuselagem -->
                <polygon points="0,-38 14,-5 14,22 8,28 -8,28 -14,22 -14,-5" fill="${design.primary}" stroke="${lightenColor(design.primary, 20)}" stroke-width="1" opacity="0.95"/>
                <!-- Asa esquerda -->
                <polygon points="-8,5 -48,-5 -42,20 -8,25" fill="${design.primary}" opacity="0.85"/>
                <!-- Asa direita -->
                <polygon points="8,5 48,-5 42,20 8,25" fill="${design.primary}" opacity="0.85"/>
                <!-- Spine central -->
                <polygon points="0,-32 3,-25 3,15 0,22 -3,15 -3,-25" fill="${design.secondary}" opacity="0.7"/>
                <!-- Wingtip esq -->
                <ellipse cx="-46" cy="0" rx="4" ry="8" fill="${design.secondary}" opacity="0.8"/>
                <!-- Wingtip dir -->
                <ellipse cx="46" cy="0" rx="4" ry="8" fill="${design.secondary}" opacity="0.8"/>
                <!-- Cockpit -->
                <polygon points="0,-29 9,-17 7,-9 -7,-9 -9,-17" fill="${design.cockpitTint}" opacity="0.55"/>
                <!-- Reflexo cockpit -->
                <polygon points="-3,-27 3,-27 5,-21 -2,-21" fill="white" opacity="0.25"/>
                <!-- Motor esq -->
                <rect x="-16" y="20" width="12" height="10" rx="2" fill="#1a1a24" opacity="0.8"/>
                <!-- Motor dir -->
                <rect x="4" y="20" width="12" height="10" rx="2" fill="#1a1a24" opacity="0.8"/>
                <!-- Chama esq -->
                <ellipse cx="-10" cy="34" rx="4" ry="8" fill="${design.engineGlow}" opacity="0.7"/>
                <ellipse cx="-10" cy="32" rx="2" ry="4" fill="white" opacity="0.5"/>
                <!-- Chama dir -->
                <ellipse cx="10" cy="34" rx="4" ry="8" fill="${design.engineGlow}" opacity="0.7"/>
                <ellipse cx="10" cy="32" rx="2" ry="4" fill="white" opacity="0.5"/>
                <!-- Armas -->
                <rect x="-18" y="-34" width="4" height="14" rx="1" fill="#3a3a4a" opacity="0.7"/>
                <rect x="14" y="-34" width="4" height="14" rx="1" fill="#3a3a4a" opacity="0.7"/>
                <circle cx="-16" cy="-34" r="1.5" fill="${design.accent}" opacity="0.8"/>
                <circle cx="16" cy="-34" r="1.5" fill="${design.accent}" opacity="0.8"/>
            </svg>
            <span class="ship-number">${design.number}</span>
        </div>
    `).join('');
    
    document.querySelectorAll('.ship-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            selectShipForSession(btn.dataset.design);
        });
    });
    
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

function shadeColor(color, percent) {
    if (!color || color.length < 7) return color;
    const num = parseInt(color.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent);
    const R = Math.max(0, Math.min(255, (num >> 16) + amt));
    const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
    const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

function lightenColor(color, percent) {
    return shadeColor(color, percent);
}

window.SHIP_DESIGNS = SHIP_DESIGNS;
window.getRandomShipDesign = getRandomShipDesign;
window.selectShipForSession = selectShipForSession;
window.getShipForGame = getShipForGame;
window.updateShipSelectionUI = updateShipSelectionUI;
window.addShipSelectionUI = addShipSelectionUI;
window.clearShipSelection = clearShipSelection;

console.log('\u{1F4E6} game-ships.js v4.0 carregado (8 naves)');
