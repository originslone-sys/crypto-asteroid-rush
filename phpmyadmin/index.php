<?php
// ============================================
// phpMyAdmin Minimal - Redireciona para Cloud SQL Console
// ============================================

session_start();

// Senha do admin (mesma do painel admin)
$admin_password = getenv('ADMIN_PASSWORD') ?: 'Admin@Unobix2024!';

// Verificar autenticação
if (!isset($_SESSION['phpmyadmin_auth']) || $_SESSION['phpmyadmin_auth'] !== true) {
    if (isset($_POST['password']) && $_POST['password'] === $admin_password) {
        $_SESSION['phpmyadmin_auth'] = true;
    } else {
        // Mostrar formulário de login
        show_login_form();
        exit;
    }
}

// Se autenticado, mostrar dashboard
show_dashboard();

// ============================================
// FUNÇÕES
// ============================================

function show_login_form() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>phpMyAdmin - Cloud SQL Manager</title>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                color: white; 
                margin: 0;
                padding: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-container {
                width: 100%;
                max-width: 450px;
                padding: 20px;
            }
            .login-box { 
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 20px;
                padding: 40px 30px;
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            h2 { 
                margin: 0 0 10px 0;
                font-size: 28px;
                background: linear-gradient(90deg, #4cc9f0, #4361ee);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                text-align: center;
            }
            .subtitle {
                text-align: center;
                color: #a0a0c0;
                margin-bottom: 30px;
                font-size: 14px;
            }
            .input-group {
                margin-bottom: 25px;
            }
            label {
                display: block;
                margin-bottom: 8px;
                color: #b0b0d0;
                font-size: 14px;
            }
            input[type="password"] { 
                width: 100%; 
                padding: 15px;
                background: rgba(255, 255, 255, 0.08);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 10px;
                color: white;
                font-size: 16px;
                box-sizing: border-box;
                transition: all 0.3s;
            }
            input[type="password"]:focus {
                outline: none;
                border-color: #4cc9f0;
                background: rgba(255, 255, 255, 0.12);
                box-shadow: 0 0 0 3px rgba(76, 201, 240, 0.2);
            }
            button { 
                width: 100%;
                background: linear-gradient(90deg, #4361ee, #3a0ca3);
                color: white; 
                padding: 16px;
                border: none;
                border-radius: 10px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                letter-spacing: 0.5px;
                transition: all 0.3s;
                margin-top: 10px;
            }
            button:hover {
                background: linear-gradient(90deg, #3a0ca3, #4361ee);
                transform: translateY(-2px);
                box-shadow: 0 7px 20px rgba(67, 97, 238, 0.3);
            }
            button:active {
                transform: translateY(0);
            }
            .info-box {
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                padding: 15px;
                margin-top: 25px;
                font-size: 13px;
                color: #a0a0c0;
                border-left: 4px solid #4361ee;
            }
            .info-box strong {
                color: #4cc9f0;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-box">
                <h2>🔐 Cloud SQL Manager</h2>
                <div class="subtitle">Acesso restrito ao administrador</div>
                
                <form method="POST">
                    <div class="input-group">
                        <label for="password">Senha de administração</label>
                        <input type="password" id="password" name="password" placeholder="Digite a senha do painel admin" required autofocus>
                    </div>
                    
                    <button type="submit">🔑 Entrar no Cloud SQL</button>
                </form>
                
                <div class="info-box">
                    <strong>💡 Informação:</strong> Esta interface redireciona para o Console Web oficial do Google Cloud SQL, que é a maneira mais segura e completa de gerenciar seu banco de dados.
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function show_dashboard() {
    $cloud_sql_url = "https://console.cloud.google.com/sql/instances/unobix/overview?project=project-7be1cae5-5f08-45fb-aca";
    $run_url = "https://console.cloud.google.com/run?project=project-7be1cae5-5f08-45fb-aca";
    $logging_url = "https://console.cloud.google.com/logs?project=project-7be1cae5-5f08-45fb-aca";
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Cloud SQL Manager - Dashboard</title>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                color: #f1f5f9;
                min-height: 100vh;
                padding: 20px;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
            }
            
            header {
                text-align: center;
                margin-bottom: 40px;
                padding: 30px 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            h1 {
                font-size: 2.5rem;
                background: linear-gradient(90deg, #60a5fa, #3b82f6);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 10px;
            }
            
            .subtitle {
                color: #94a3b8;
                font-size: 1.1rem;
            }
            
            .dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 25px;
                margin-bottom: 40px;
            }
            
            .card {
                background: rgba(30, 41, 59, 0.7);
                backdrop-filter: blur(10px);
                border-radius: 16px;
                padding: 30px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                transition: all 0.3s ease;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            }
            
            .card:hover {
                transform: translateY(-5px);
                border-color: #3b82f6;
                box-shadow: 0 15px 35px rgba(59, 130, 246, 0.15);
            }
            
            .card-icon {
                font-size: 2.5rem;
                margin-bottom: 20px;
                display: inline-block;
            }
            
            .card-title {
                font-size: 1.5rem;
                margin-bottom: 15px;
                color: #f8fafc;
            }
            
            .card-description {
                color: #cbd5e1;
                line-height: 1.6;
                margin-bottom: 25px;
                font-size: 0.95rem;
            }
            
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 14px 28px;
                background: linear-gradient(90deg, #3b82f6, #1d4ed8);
                color: white;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                font-size: 1rem;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
                width: 100%;
                text-align: center;
            }
            
            .btn:hover {
                background: linear-gradient(90deg, #1d4ed8, #3b82f6);
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
            }
            
            .btn-secondary {
                background: linear-gradient(90deg, #475569, #334155);
            }
            
            .btn-secondary:hover {
                background: linear-gradient(90deg, #334155, #475569);
            }
            
            .connection-info {
                background: rgba(30, 41, 59, 0.9);
                border-radius: 12px;
                padding: 25px;
                margin-top: 30px;
                border-left: 4px solid #10b981;
            }
            
            .info-title {
                font-size: 1.2rem;
                margin-bottom: 15px;
                color: #10b981;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            
            .info-item {
                background: rgba(255, 255, 255, 0.05);
                padding: 15px;
                border-radius: 8px;
                font-family: 'Courier New', monospace;
                font-size: 0.9rem;
                word-break: break-all;
            }
            
            .info-label {
                color: #94a3b8;
                font-size: 0.8rem;
                margin-bottom: 5px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .logout-btn {
                position: fixed;
                top: 20px;
                right: 20px;
                background: rgba(239, 68, 68, 0.9);
                color: white;
                padding: 10px 20px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
            }
            
            .logout-btn:hover {
                background: rgba(220, 38, 38, 0.9);
                transform: translateY(-2px);
            }
            
            @media (max-width: 768px) {
                .dashboard-grid {
                    grid-template-columns: 1fr;
                }
                
                h1 {
                    font-size: 2rem;
                }
                
                .container {
                    padding: 10px;
                }
            }
        </style>
    </head>
    <body>
        <a href="?logout=1" class="logout-btn">🚪 Sair</a>
        
        <div class="container">
            <header>
                <h1>☁️ Cloud SQL Manager</h1>
                <p class="subtitle">Interface de gerenciamento do banco de dados Unobix</p>
            </header>
            
            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-icon">🗄️</div>
                    <h3 class="card-title">Console Web do Cloud SQL</h3>
                    <p class="card-description">
                        Acesse a interface oficial do Google Cloud SQL para gerenciar databases, 
                        executar queries SQL, monitorar performance e configurar usuários.
                    </p>
                    <a href="<?php echo $cloud_sql_url; ?>" target="_blank" class="btn">
                        🔗 Abrir Console Web
                    </a>
                </div>
                
                <div class="card">
                    <div class="card-icon">🚀</div>
                    <h3 class="card-title">Cloud Run (Aplicação)</h3>
                    <p class="card-description">
                        Gerencie a aplicação Crypto Asteroid Rush no Cloud Run. 
                        Veja logs, métricas, escalabilidade e configurações do serviço.
                    </p>
                    <a href="<?php echo $run_url; ?>" target="_blank" class="btn btn-secondary">
                        📊 Gerenciar Aplicação
                    </a>
                </div>
                
                <div class="card">
                    <div class="card-icon">📊</div>
                    <h3 class="card-title">Logs & Monitoramento</h3>
                    <p class="card-description">
                        Acesse os logs detalhados da aplicação e do Cloud SQL. 
                        Monitore erros, performance e atividade do sistema.
                    </p>
                    <a href="<?php echo $logging_url; ?>" target="_blank" class="btn btn-secondary">
                        📈 Ver Logs
                    </a>
                </div>
            </div>
            
            <div class="connection-info">
                <h3 class="info-title">🔗 Informações de Conexão</h3>
                <p>Use estas credenciais para conectar diretamente via MySQL Workbench ou linha de comando:</p>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Host / IP</div>
                        34.168.76.127
                    </div>
                    <div class="info-item">
                        <div class="info-label">Porta</div>
                        3306
                    </div>
                    <div class="info-item">
                        <div class="info-label">Usuário</div>
                        unobix_user
                    </div>
                    <div class="info-item">
                        <div class="info-label">Database</div>
                        unobix_db
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.05); border-radius: 8px;">
                    <div style="color: #fbbf24; margin-bottom: 10px;">💡 Dica Rápida:</div>
                    <div style="font-size: 0.9rem; color: #cbd5e1;">
                        Para conectar via linha de comando:<br>
                        <code style="background: rgba(0,0,0,0.3); padding: 5px 10px; border-radius: 4px; display: inline-block; margin-top: 5px;">
                            mysql -h 34.168.76.127 -u unobix_user -p
                        </code>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
            // Logout handler
            document.querySelector('.logout-btn').addEventListener('click', function(e) {
                if (!confirm('Deseja realmente sair?')) {
                    e.preventDefault();
                }
            });
            
            // Add some interactive effects
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        </script>
    </body>
    </html>
    <?php
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>