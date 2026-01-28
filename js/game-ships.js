/* ============================================
   CRYPTO ASTEROID RUSH - Ship Designs v3.1
   File: js/game-ships.js
   7 Unique Fighter Ships
   FIX: Seleção de nave persiste corretamente
   ============================================ */

const SHIP_DESIGNS = {
    // Ship 1: Phoenix Crimson - Red & Gold Fighter
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
        description: 'Balanced fighter with good speed'
    },
    
    // Ship 2: Forest Guardian - Military Green
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
        description: 'Durable hull for tough missions'
    },
    
    // Ship 3: Thunder Strike - Electric Blue
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
        description: 'High-speed interceptor'
    },
    
    // Ship 4: Inferno Blaze - Volcanic Orange
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
        description: 'Powerful engines, fast attacks'
    },
    
    // Ship 5: Nebula Phantom - Cosmic Purple
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
        description: 'Agile and hard to hit'
    },
    
    // Ship 6: Toxic Viper - Toxic Green
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
        description: 'Nimble serpent-class fighter'
    },
    
    // Ship 7: Steel Wolf - Metallic Gray
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
        description: 'Heavy assault fighter'
    }
};

// Get random ship design
function getRandomShipDesign() {
    const designs = Object.values(SHIP_DESIGNS);
    return designs[Math.floor(Math.random() * designs.length)];
}

// Select ship for current session
// FIX: Agora salva no localStorage para persistir entre recarregamentos
function selectShipForSession(designKey) {
    if (SHIP_DESIGNS[designKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[designKey];
        
        // FIX: Salvar no localStorage
        localStorage.setItem('selectedShipKey', designKey);
        
        updateShipSelectionUI(designKey);
        updateSelectedShipInfo(gameState.selectedShipDesign);
        showNotification('SHIP SELECTED', gameState.selectedShipDesign.name, true);
        console.log('🚀 Ship selected:', gameState.selectedShipDesign.name);
    }
}

// Get ship for game
// FIX: Verifica localStorage se gameState.selectedShipDesign estiver null
function getShipForGame() {
    // Primeiro, verificar se há uma nave selecionada no gameState
    if (gameState.selectedShipDesign) {
        console.log('🎮 Using selected ship:', gameState.selectedShipDesign.name);
        return gameState.selectedShipDesign;
    }
    
    // FIX: Verificar localStorage como fallback
    const savedShipKey = localStorage.getItem('selectedShipKey');
    if (savedShipKey && SHIP_DESIGNS[savedShipKey]) {
        console.log('🎮 Using saved ship from localStorage:', SHIP_DESIGNS[savedShipKey].name);
        gameState.selectedShipDesign = SHIP_DESIGNS[savedShipKey];
        return SHIP_DESIGNS[savedShipKey];
    }
    
    // Se nada foi selecionado, usar aleatória
    const randomDesign = getRandomShipDesign();
    console.log('🎮 Using random ship:', randomDesign.name);
    return randomDesign;
}

// Update ship selection UI
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

// FIX: Limpar seleção de nave (chamar APENAS quando o jogador voltar ao menu)
function clearShipSelection() {
    gameState.selectedShipDesign = null;
    localStorage.removeItem('selectedShipKey');
    updateShipSelectionUI(null);
    console.log('🧹 Ship selection cleared');
}

// Create ship selection UI
function addShipSelectionUI() {
    const shipSelection = document.getElementById('shipSelection');
    if (!shipSelection) return;
    
    shipSelection.innerHTML = Object.entries(SHIP_DESIGNS).map(([key, design]) => `
        <div class="ship-btn" data-design="${key}"
             style="background: linear-gradient(135deg, ${design.primary}, ${shadeColor(design.primary, -20)});"
             title="${design.name} - ${design.description}">
            <svg class="ship-preview" viewBox="-50 -50 100 100">
                <!-- Mini ship preview -->
                <polygon points="0,-35 15,25 -15,25" fill="${design.primary}" stroke="${design.secondary}" stroke-width="2"/>
                <polygon points="-8,5 -40,-5 -35,18" fill="${design.primary}" opacity="0.9"/>
                <polygon points="8,5 40,-5 35,18" fill="${design.primary}" opacity="0.9"/>
                <ellipse cx="0" cy="-15" rx="8" ry="6" fill="${design.cockpitTint}" opacity="0.7"/>
                <circle cx="0" cy="30" r="4" fill="${design.engineGlow}"/>
            </svg>
            <span class="ship-number">${design.number}</span>
        </div>
    `).join('');
    
    // Add event listeners
    document.querySelectorAll('.ship-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const key = btn.dataset.design;
            selectShipForSession(key);
        });
    });
    
    // FIX: Restaurar seleção do localStorage se existir
    const savedShipKey = localStorage.getItem('selectedShipKey');
    if (savedShipKey && SHIP_DESIGNS[savedShipKey]) {
        gameState.selectedShipDesign = SHIP_DESIGNS[savedShipKey];
        updateShipSelectionUI(savedShipKey);
        updateSelectedShipInfo(SHIP_DESIGNS[savedShipKey]);
        console.log('🚀 Restored ship selection:', SHIP_DESIGNS[savedShipKey].name);
    } else {
        // Nenhuma nave salva, não selecionar nada
        gameState.selectedShipDesign = null;
        updateSelectedShipInfo(null);
    }
}

// Helper function for ship preview
function shadeColor(color, percent) {
    if (!color || color.length < 7) return color;
    const num = parseInt(color.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent);
    const R = Math.max(0, Math.min(255, (num >> 16) + amt));
    const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
    const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

// Export functions
window.SHIP_DESIGNS = SHIP_DESIGNS;
window.getRandomShipDesign = getRandomShipDesign;
window.selectShipForSession = selectShipForSession;
window.getShipForGame = getShipForGame;
window.updateShipSelectionUI = updateShipSelectionUI;
window.addShipSelectionUI = addShipSelectionUI;
window.clearShipSelection = clearShipSelection;
