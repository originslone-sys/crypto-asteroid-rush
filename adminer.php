<?php
// ============================================
// ADMINER - Interface Web para MySQL
// Versão simplificada e segura
// ============================================

require_once 'api/config-cloudrun.php';

// Segurança básica - permitir com senha em produção
$adminerPassword = getenv('ADMINER_PASSWORD') ?: 'unobix_admin_2024';
$providedPassword = $_GET['password'] ?? $_POST['password'] ?? '';

if (getenv('APP_ENV') === 'production' && $providedPassword !== $adminerPassword) {
    // Mostrar formulário de login
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo '<div style="padding: 20px; background: #f44336; color: white;">Senha incorreta</div>';
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Adminer - Login</title><style>
        body { font-family: sans-serif; background: #1a1a1a; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-box { background: #2d2d2d; padding: 30px; border-radius: 10px; text-align: center; }
        input { padding: 10px; margin: 10px; width: 200px; }
        button { padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style></head>
    <body>
        <div class="login-box">
            <h2>🔐 Adminer - Crypto Asteroid Rush</h2>
            <p>Digite a senha de administração:</p>
            <form method="post">
                <input type="password" name="password" placeholder="Senha" required>
                <br>
                <button type="submit">Acessar</button>
            </form>
            <p style="margin-top: 20px; font-size: 12px; color: #888;">
                Dica: Verifique variável de ambiente ADMINER_PASSWORD
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Função para conectar
function getAdminerConnection() {
    return getDatabaseConnection();
}

// Interface simples
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Adminer - Crypto Asteroid Rush</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a1a; color: #e0e0e0; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        header { background: #2d2d2d; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1 { color: #4CAF50; }
        .subtitle { color: #888; margin-top: 5px; }
        .card { background: #2d2d2d; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .card h2 { color: #64B5F6; margin-bottom: 15px; border-bottom: 1px solid #444; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #3d3d3d; text-align: left; padding: 12px; border-bottom: 2px solid #444; }
        td { padding: 10px 12px; border-bottom: 1px solid #444; }
        tr:hover { background: #383838; }
        .success { color: #4CAF50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
        .sql-box { background: #1e1e1e; padding: 15px; border-radius: 4px; font-family: monospace; margin: 10px 0; }
        .btn { background: #4CAF50; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #45a049; }
        .btn-danger { background: #f44336; }
        .btn-danger:hover { background: #d32f2f; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #aaa; }
        input, textarea, select { width: 100%; padding: 10px; background: #3d3d3d; border: 1px solid #555; border-radius: 4px; color: white; }
        textarea { font-family: monospace; min-height: 100px; }
        .tabs { display: flex; border-bottom: 1px solid #444; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; border-bottom: 3px solid transparent; }
        .tab.active { border-bottom-color: #4CAF50; background: #383838; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔧 Adminer - Crypto Asteroid Rush</h1>
            <div class="subtitle">Interface de administração do banco de dados</div>
        </header>

        <?php
        try {
            $pdo = getAdminerConnection();
            if (!$pdo) {
                echo '<div class="card"><h2>❌ Erro de Conexão</h2><p>Não foi possível conectar ao banco de dados.</p></div>';
                exit;
            }
            
            echo '<div class="card success"><h2>✅ Conectado ao Banco</h2>';
            echo '<p>Host: ' . DB_HOST . ' | Database: ' . DB_NAME . ' | User: ' . DB_USER . '</p></div>';
            
            // Abas
            echo '<div class="tabs" id="tabs">';
            echo '<div class="tab active" data-tab="tables">📊 Tabelas</div>';
            echo '<div class="tab" data-tab="users">👥 Users</div>';
            echo '<div class="tab" data-tab="players">🎮 Players</div>';
            echo '<div class="tab" data-tab="sessions">🕹️ Sessions</div>';
            echo '<div class="tab" data-tab="sql">💻 SQL</div>';
            echo '</div>';
            
            // Conteúdo das abas
            echo '<div id="tab-contents">';
            
            // TABELA: Lista de tabelas
            echo '<div class="tab-content active" id="tab-tables">';
            echo '<div class="card"><h2>📊 Tabelas do Banco</h2>';
            
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($tables)) {
                echo '<p class="warning">Nenhuma tabela encontrada.</p>';
            } else {
                echo '<table>';
                echo '<tr><th>Tabela</th><th>Ações</th></tr>';
                foreach ($tables as $table) {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($table) . '</strong><br><small>' . $count . ' registros</small></td>';
                    echo '<td><a href="#' . $table . '" class="btn" onclick="showTable(\'' . $table . '\')">Ver Dados</a></td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            echo '</div></div>';
            
            // TABELA: Users
            echo '<div class="tab-content" id="tab-users">';
            echo '<div class="card"><h2>👥 Tabela: users</h2>';
            
            $stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 50");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($users)) {
                echo '<p class="warning">Tabela users está vazia.</p>';
                echo '<p class="error">⚠️ Isso explica porque game-start.php falha!</p>';
                echo '<p>Solução: Faça login no jogo para auth-google.php criar o user.</p>';
            } else {
                echo '<p>Total: ' . count($users) . ' users</p>';
                echo '<table>';
                echo '<tr><th>ID</th><th>Google UID</th><th>Email</th><th>Balance BRL</th><th>Criado</th></tr>';
                foreach ($users as $user) {
                    echo '<tr>';
                    echo '<td>' . $user['id'] . '</td>';
                    echo '<td title="' . htmlspecialchars($user['google_uid']) . '">';
                    echo substr($user['google_uid'], 0, 20) . (strlen($user['google_uid']) > 20 ? '...' : '');
                    echo '</td>';
                    echo '<td>' . htmlspecialchars($user['email'] ?? '') . '</td>';
                    echo '<td>' . $user['balance_brl'] . '</td>';
                    echo '<td>' . $user['created_at'] . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            echo '</div></div>';
            
            // TABELA: Players
            echo '<div class="tab-content" id="tab-players">';
            echo '<div class="card"><h2>🎮 Tabela: players</h2>';
            
            $stmt = $pdo->query("SELECT * FROM players ORDER BY id DESC LIMIT 50");
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($players)) {
                echo '<p class="warning">Tabela players está vazia.</p>';
            } else {
                echo '<p>Total: ' . count($players) . ' players</p>';
                echo '<table>';
                echo '<tr><th>ID</th><th>Google UID</th><th>Balance BRL</th><th>Total Played</th><th>Criado</th></tr>';
                foreach ($players as $player) {
                    echo '<tr>';
                    echo '<td>' . $player['id'] . '</td>';
                    echo '<td title="' . htmlspecialchars($player['google_uid']) . '">';
                    echo substr($player['google_uid'], 0, 20) . (strlen($player['google_uid']) > 20 ? '...' : '');
                    echo '</td>';
                    echo '<td>' . $player['balance_brl'] . '</td>';
                    echo '<td>' . $player['total_played'] . '</td>';
                    echo '<td>' . $player['created_at'] . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            echo '</div></div>';
            
            // TABELA: Game Sessions
            echo '<div class="tab-content" id="tab-sessions">';
            echo '<div class="card"><h2>🕹️ Tabela: game_sessions</h2>';
            
            $stmt = $pdo->query("SELECT * FROM game_sessions ORDER BY id DESC LIMIT 20");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($sessions)) {
                echo '<p class="warning">Tabela game_sessions está vazia.</p>';
                echo '<p class="error">⚠️ game-start.php nunca criou uma sessão com sucesso!</p>';
            } else {
                echo '<p>Total: ' . count($sessions) . ' sessões</p>';
                echo '<table>';
                echo '<tr><th>ID</th><th>User ID</th><th>Session UUID</th><th>Status</th><th>Criado</th></tr>';
                foreach ($sessions as $session) {
                    echo '<tr>';
                    echo '<td>' . $session['id'] . '</td>';
                    echo '<td>' . $session['user_id'] . '</td>';
                    echo '<td title="' . htmlspecialchars($session['session_uuid']) . '">';
                    echo substr($session['session_uuid'], 0, 10) . '...';
                    echo '</td>';
                    echo '<td>' . $session['status'] . '</td>';
                    echo '<td>' . $session['created_at'] . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            echo '</div></div>';
            
            // TABELA: SQL Executor
            echo '<div class="tab-content" id="tab-sql">';
            echo '<div class="card"><h2>💻 Executor SQL</h2>';
            
            echo '<form method="post" id="sql-form">';
            echo '<div class="form-group">';
            echo '<label for="sql">Consulta SQL:</label>';
            echo '<textarea name="sql" id="sql" placeholder="SELECT * FROM users LIMIT 10">SELECT * FROM users LIMIT 10</textarea>';
            echo '</div>';
            echo '<button type="submit" class="btn">Executar</button>';
            echo '</form>';
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sql'])) {
                $sql = $_POST['sql'];
                echo '<div class="sql-box">' . htmlspecialchars($sql) . '</div>';
                
                try {
                    $stmt = $pdo->query($sql);
                    
                    if ($stmt) {
                        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($results)) {
                            echo '<table>';
                            // Cabeçalhos
                            echo '<tr>';
                            foreach (array_keys($results[0]) as $col) {
                                echo '<th>' . htmlspecialchars($col) . '</th>';
                            }
                            echo '</tr>';
                            
                            // Dados
                            foreach ($results as $row) {
                                echo '<tr>';
                                foreach ($row as $value) {
                                    echo '<td>' . htmlspecialchars($value) . '</td>';
                                }
                                echo '</tr>';
                            }
                            echo '</table>';
                        } else {
                            echo '<p class="success">✅ Consulta executada (0 resultados)</p>';
                        }
                    }
                } catch (Exception $e) {
                    echo '<p class="error">❌ Erro SQL: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }
            
            echo '</div></div>';
            
            echo '</div>'; // Fim tab-contents
            
        } catch (Exception $e) {
            echo '<div class="card error"><h2>❌ Erro</h2>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        ?>
    </div>

    <script>
        // Sistema de abas
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.getAttribute('data-tab');
                
                // Atualizar abas
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Atualizar conteúdo
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });
        
        function showTable(tableName) {
            // Ativar aba correspondente
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.getAttribute('data-tab') === tableName) {
                    tab.click();
                }
            });
        }
        
        // Auto-executar consulta users na carga
        document.addEventListener('DOMContentLoaded', () => {
            const sqlForm = document.getElementById('sql-form');
            if (sqlForm) {
                setTimeout(() => {
                    sqlForm.querySelector('button[type="submit"]').click();
                }, 100);
            }
        });
    </script>
</body>
</html>