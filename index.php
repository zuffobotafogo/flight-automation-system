<?php
/**
 * =========================================================================
 * SISTEMA DE AUTOMAÇÃO DE VOOS COM DASHBOARD E BUSCA INTELIGENTE
 * =========================================================================
 * Desenvolvido para: Automação de pesquisa de voos baratos
 * Recursos: Dashboard, Logos de Companhias, Preços, Horários e Sugestões
 * Banco de Dados: MySQL/XAMPP via PhpMyAdmin
 * Versão: 1.0
 * =========================================================================
 */

// ===============================================================
// 1. CONFIGURAÇÃO DO BANCO DE DADOS
// ===============================================================

class FlightDatabase {
    private $host = 'localhost';
    private $user = 'root';
    private $password = '';
    private $database = 'flight_automation';
    private $conn;

    public function __construct() {
        $this->connect();
        $this->createTables();
    }

    private function connect() {
        try {
            $this->conn = new mysqli($this->host, $this->user, $this->password);
            
            if ($this->conn->connect_error) {
                die("Erro de conexão: " . $this->conn->connect_error);
            }

            // Criar banco se não existir
            $this->conn->query("CREATE DATABASE IF NOT EXISTS " . $this->database);
            $this->conn->select_db($this->database);
            
        } catch (Exception $e) {
            die("Erro ao conectar: " . $e->getMessage());
        }
    }

    private function createTables() {
        // Tabela de Companhias Aéreas
        $sql_airlines = "CREATE TABLE IF NOT EXISTS airlines (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) UNIQUE NOT NULL,
            code VARCHAR(5) UNIQUE NOT NULL,
            logo_url VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->conn->query($sql_airlines);

        // Tabela de Voos
        $sql_flights = "CREATE TABLE IF NOT EXISTS flights (
            id INT PRIMARY KEY AUTO_INCREMENT,
            airline_id INT NOT NULL,
            origin VARCHAR(5) NOT NULL,
            destination VARCHAR(5) NOT NULL,
            departure_time TIME NOT NULL,
            arrival_time TIME NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            available_seats INT NOT NULL,
            duration VARCHAR(10),
            flight_number VARCHAR(20) UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (airline_id) REFERENCES airlines(id),
            INDEX idx_route (origin, destination),
            INDEX idx_price (price)
        )";
        $this->conn->query($sql_flights);

        // Tabela de Pesquisas
        $sql_searches = "CREATE TABLE IF NOT EXISTS flight_searches (
            id INT PRIMARY KEY AUTO_INCREMENT,
            origin VARCHAR(5) NOT NULL,
            destination VARCHAR(5) NOT NULL,
            departure_date DATE NOT NULL,
            searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            results_count INT,
            average_price DECIMAL(10, 2),
            cheapest_price DECIMAL(10, 2)
        )";
        $this->conn->query($sql_searches);

        // Tabela de Favoritos
        $sql_favorites = "CREATE TABLE IF NOT EXISTS favorite_flights (
            id INT PRIMARY KEY AUTO_INCREMENT,
            flight_id INT NOT NULL,
            saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (flight_id) REFERENCES flights(id),
            UNIQUE KEY unique_favorite (flight_id)
        )";
        $this->conn->query($sql_favorites);

        // Tabela de Alertas de Preço
        $sql_alerts = "CREATE TABLE IF NOT EXISTS price_alerts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            origin VARCHAR(5) NOT NULL,
            destination VARCHAR(5) NOT NULL,
            max_price DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            UNIQUE KEY unique_alert (origin, destination, max_price)
        )";
        $this->conn->query($sql_alerts);
    }

    public function getConnection() {
        return $this->conn;
    }
}

// ===============================================================
// 2. CLASSE DE GERENCIAMENTO DE COMPANHIAS AÉREAS
// ===============================================================

class AirlineManager {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    public function addAirline($name, $code, $logo_url) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("INSERT INTO airlines (name, code, logo_url) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $code, $logo_url);
        return $stmt->execute();
    }

    public function getAllAirlines() {
        $conn = $this->db->getConnection();
        $result = $conn->query("SELECT * FROM airlines ORDER BY name ASC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getAirlineById($id) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT * FROM airlines WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function initializeDefaultAirlines() {
        $airlines = [
            ['TAP Air Portugal', 'TAP', 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c9/TAP_Air_Portugal_logo.svg/1200px-TAP_Air_Portugal_logo.svg.png'],
            ['LATAM Airlines', 'LA', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/LATAM_Airlines_logo.svg/1200px-LATAM_Airlines_logo.svg.png'],
            ['GOL Linhas Aéreas', 'G3', 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f0/GOL_Linhas_A%C3%A9reas_logo.svg/1200px-GOL_Linhas_A%C3%A9reas_logo.svg.png'],
            ['Azul Linhas Aéreas', 'AD', 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/0c/Azul_Linhas_A%C3%A9reas_logo.svg/1200px-Azul_Linhas_A%C3%A9reas_logo.svg.png'],
            ['Air Europa', 'UX', 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/84/Air_Europa_logo.svg/1200px-Air_Europa_logo.svg.png'],
            ['Iberia', 'IB', 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/44/Iberia_logo.svg/1200px-Iberia_logo.svg.png'],
            ['Lufthansa', 'LH', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d7/Lufthansa_Logo.svg/1200px-Lufthansa_Logo.svg.png'],
            ['Emirates', 'EK', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d0/Emirates_logo.svg/1200px-Emirates_logo.svg.png']
        ];

        foreach ($airlines as $airline) {
            $this->addAirline($airline[0], $airline[1], $airline[2]);
        }
    }
}

// ===============================================================
// 3. CLASSE DE GERENCIAMENTO DE VOOS
// ===============================================================

class FlightManager {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    public function addFlight($airline_id, $origin, $destination, $departure_time, $arrival_time, $price, $seats, $flight_number) {
        $conn = $this->db->getConnection();
        
        // Calcular duração do voo
        $dep = strtotime($departure_time);
        $arr = strtotime($arrival_time);
        if ($arr < $dep) {
            $arr += 86400; // Próximo dia
        }
        $duration = gmdate('H:i', $arr - $dep);

        $stmt = $conn->prepare("INSERT INTO flights (airline_id, origin, destination, departure_time, arrival_time, price, available_seats, duration, flight_number) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssiiss", $airline_id, $origin, $destination, $departure_time, $arrival_time, $price, $seats, $duration, $flight_number);
        return $stmt->execute();
    }

    public function searchFlights($origin, $destination) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT f.*, a.name as airline_name, a.code, a.logo_url
            FROM flights f
            JOIN airlines a ON f.airline_id = a.id
            WHERE f.origin = ? AND f.destination = ?
            ORDER BY f.price ASC, f.departure_time ASC
        ");
        $stmt->bind_param("ss", $origin, $destination);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getCheapestFlights($origin, $destination, $limit = 5) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT f.*, a.name as airline_name, a.code, a.logo_url
            FROM flights f
            JOIN airlines a ON f.airline_id = a.id
            WHERE f.origin = ? AND f.destination = ?
            ORDER BY f.price ASC
            LIMIT ?
        ");
        $stmt->bind_param("ssi", $origin, $destination, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getFlightStatistics($origin, $destination) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_flights,
                MIN(price) as cheapest_price,
                MAX(price) as most_expensive_price,
                AVG(price) as average_price,
                COUNT(DISTINCT airline_id) as airlines_count
            FROM flights
            WHERE origin = ? AND destination = ?
        ");
        $stmt->bind_param("ss", $origin, $destination);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getAllFlights() {
        $conn = $this->db->getConnection();
        $result = $conn->query("
            SELECT f.*, a.name as airline_name, a.code, a.logo_url
            FROM flights f
            JOIN airlines a ON f.airline_id = a.id
            ORDER BY f.price ASC
        ");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function generateSampleFlights() {
        $origins = ['LIS', 'OPO', 'FAO'];
        $destinations = ['RIO', 'SAO', 'BRA', 'MAO'];
        $airlines = [1, 2, 3, 4, 5, 6, 7, 8];
        
        for ($i = 0; $i < 50; $i++) {
            $origin = $origins[array_rand($origins)];
            $destination = $destinations[array_rand($destinations)];
            
            if ($origin !== $destination) {
                $departure = sprintf("%02d:%02d:00", rand(6, 23), [0, 30][rand(0, 1)]);
                $arrival_hour = (int)substr($departure, 0, 2) + rand(4, 12);
                $arrival_min = rand(0, 59);
                $arrival = sprintf("%02d:%02d:00", $arrival_hour % 24, $arrival_min);
                
                $price = rand(100, 800) + (rand(0, 99) / 100);
                $seats = rand(10, 150);
                $flight_number = strtoupper(chr(rand(65, 90)) . chr(rand(65, 90))) . rand(100, 999);
                $airline_id = $airlines[array_rand($airlines)];
                
                $this->addFlight($airline_id, $origin, $destination, $departure, $arrival, $price, $seats, $flight_number);
            }
        }
    }
}

// ===============================================================
// 4. CLASSE DE PESQUISA INTELIGENTE
// ===============================================================

class SmartFlightFinder {
    private $db;
    private $flightManager;

    public function __construct($database, $flightManager) {
        $this->db = $database;
        $this->flightManager = $flightManager;
    }

    public function findCheapestDeals($origin, $destination) {
        $flights = $this->flightManager->searchFlights($origin, $destination);
        
        if (empty($flights)) {
            return [
                'status' => 'no_flights',
                'message' => 'Nenhum voo encontrado para esta rota'
            ];
        }

        $stats = $this->flightManager->getFlightStatistics($origin, $destination);
        
        $suggestions = $this->generateSuggestions($flights, $stats);

        return [
            'status' => 'success',
            'flights' => $flights,
            'statistics' => $stats,
            'suggestions' => $suggestions,
            'cheapest' => $flights[0]
        ];
    }

    private function generateSuggestions($flights, $stats) {
        $suggestions = [];
        
        // Sugestão 1: Voos mais baratos
        $suggestions[] = [
            'type' => 'cheapest',
            'title' => '💰 Melhor Preço',
            'description' => 'O voo mais barato custa R$ ' . number_format($stats['cheapest_price'], 2, ',', '.'),
            'saving' => number_format($stats['average_price'] - $stats['cheapest_price'], 2, ',', '.')
        ];

        // Sugestão 2: Comparação de preços
        $suggestions[] = [
            'type' => 'comparison',
            'title' => '📊 Análise de Preços',
            'description' => 'Variação de preço: R$ ' . number_format($stats['cheapest_price'], 2, ',', '.') . ' até R$ ' . number_format($stats['most_expensive_price'], 2, ',', '.'),
            'difference' => number_format($stats['most_expensive_price'] - $stats['cheapest_price'], 2, ',', '.')
        ];

        // Sugestão 3: Horários com melhor preço
        $by_time = [];
        foreach ($flights as $flight) {
            $hour = substr($flight['departure_time'], 0, 2);
            if (!isset($by_time[$hour])) {
                $by_time[$hour] = [];
            }
            $by_time[$hour][] = $flight;
        }

        $cheapest_hour = null;
        $cheapest_avg = PHP_INT_MAX;
        foreach ($by_time as $hour => $hour_flights) {
            $avg = array_sum(array_column($hour_flights, 'price')) / count($hour_flights);
            if ($avg < $cheapest_avg) {
                $cheapest_avg = $avg;
                $cheapest_hour = $hour;
            }
        }

        $suggestions[] = [
            'type' => 'best_time',
            'title' => '🕐 Melhor Horário',
            'description' => 'Voos saindo às ' . $cheapest_hour . ':00h têm preço médio de R$ ' . number_format($cheapest_avg, 2, ',', '.'),
            'time' => $cheapest_hour . ':00'
        ];

        // Sugestão 4: Recomendação de compra
        if ($stats['cheapest_price'] < $stats['average_price'] * 0.7) {
            $suggestions[] = [
                'type' => 'urgency',
                'title' => '⚡ PROMOÇÃO ATIVA',
                'description' => 'Preço muito abaixo da média! Economize até 30% comprando agora!',
                'level' => 'high'
            ];
        } else if ($stats['cheapest_price'] < $stats['average_price'] * 0.9) {
            $suggestions[] = [
                'type' => 'good_deal',
                'title' => '✅ Bom Negócio',
                'description' => 'Preço interessante disponível. Confira os próximos horários.',
                'level' => 'medium'
            ];
        }

        // Sugestão 5: Quantidade de opções
        $suggestions[] = [
            'type' => 'options',
            'title' => '🛫 Opções Disponíveis',
            'description' => $stats['airlines_count'] . ' companhias aéreas com ' . $stats['total_flights'] . ' voos para esta rota',
            'count' => $stats['total_flights']
        ];

        return $suggestions;
    }

    public function recordSearch($origin, $destination) {
        $conn = $this->db->getConnection();
        $flights = $this->flightManager->searchFlights($origin, $destination);
        
        $count = count($flights);
        $cheapest = count($flights) > 0 ? min(array_column($flights, 'price')) : 0;
        $average = count($flights) > 0 ? array_sum(array_column($flights, 'price')) / count($flights) : 0;
        $date = date('Y-m-d');

        $stmt = $conn->prepare("INSERT INTO flight_searches (origin, destination, departure_date, results_count, cheapest_price, average_price) 
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssidd", $origin, $destination, $date, $count, $cheapest, $average);
        $stmt->execute();
    }
}

// ===============================================================
// 5. CLASSE DE ALERTAS DE PREÇO
// ===============================================================

class PriceAlertManager {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    public function createAlert($origin, $destination, $max_price) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("INSERT INTO price_alerts (origin, destination, max_price) VALUES (?, ?, ?)");
        $stmt->bind_param("ssd", $origin, $destination, $max_price);
        return $stmt->execute();
    }

    public function getActiveAlerts() {
        $conn = $this->db->getConnection();
        $result = $conn->query("SELECT * FROM price_alerts WHERE is_active = TRUE ORDER BY created_at DESC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function checkAlerts($flights) {
        $conn = $this->db->getConnection();
        $alerts = $this->getActiveAlerts();
        $triggered = [];

        foreach ($alerts as $alert) {
            foreach ($flights as $flight) {
                if ($flight['origin'] === $alert['origin'] && 
                    $flight['destination'] === $alert['destination'] && 
                    $flight['price'] <= $alert['max_price']) {
                    $triggered[] = [
                        'alert' => $alert,
                        'flight' => $flight
                    ];
                }
            }
        }

        return $triggered;
    }
}

// ===============================================================
// 6. CLASSE DE FAVORITOS
// ===============================================================

class FavoriteManager {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    public function addFavorite($flight_id) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("INSERT IGNORE INTO favorite_flights (flight_id) VALUES (?)");
        $stmt->bind_param("i", $flight_id);
        return $stmt->execute();
    }

    public function removeFavorite($flight_id) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("DELETE FROM favorite_flights WHERE flight_id = ?");
        $stmt->bind_param("i", $flight_id);
        return $stmt->execute();
    }

    public function getFavorites() {
        $conn = $this->db->getConnection();
        $result = $conn->query("
            SELECT f.*, a.name as airline_name, a.code, a.logo_url, fav.id as favorite_id
            FROM flights f
            JOIN airlines a ON f.airline_id = a.id
            JOIN favorite_flights fav ON f.id = fav.flight_id
            ORDER BY f.price ASC
        ");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function isFavorite($flight_id) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM favorite_flights WHERE flight_id = ?");
        $stmt->bind_param("i", $flight_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}

// ===============================================================
// 7. INICIALIZAÇÃO E PROCESSAMENTO DE REQUISIÇÕES
// ===============================================================

session_start();

$database = new FlightDatabase();
$airlineManager = new AirlineManager($database);
$flightManager = new FlightManager($database);
$smartFinder = new SmartFlightFinder($database, $flightManager);
$priceAlerts = new PriceAlertManager($database);
$favorites = new FavoriteManager($database);

// Inicializar companhias e voos se necessário
if (!isset($_SESSION['initialized'])) {
    $airlines = $airlineManager->getAllAirlines();
    if (empty($airlines)) {
        $airlineManager->initializeDefaultAirlines();
        $flightManager->generateSampleFlights();
    }
    $_SESSION['initialized'] = true;
}

// Processar ações
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$response = ['status' => 'idle'];

if ($action === 'search') {
    $origin = strtoupper($_POST['origin'] ?? '');
    $destination = strtoupper($_POST['destination'] ?? '');
    
    if (!empty($origin) && !empty($destination)) {
        $response = $smartFinder->findCheapestDeals($origin, $destination);
        $smartFinder->recordSearch($origin, $destination);
    } else {
        $response = ['status' => 'error', 'message' => 'Preencha origem e destino'];
    }
}

if ($action === 'add_favorite') {
    $flight_id = (int)($_POST['flight_id'] ?? 0);
    if ($flight_id > 0) {
        $favorites->addFavorite($flight_id);
        $response = ['status' => 'success', 'message' => 'Voo adicionado aos favoritos'];
    }
}

if ($action === 'remove_favorite') {
    $flight_id = (int)($_POST['flight_id'] ?? 0);
    if ($flight_id > 0) {
        $favorites->removeFavorite($flight_id);
        $response = ['status' => 'success', 'message' => 'Voo removido dos favoritos'];
    }
}

if ($action === 'create_alert') {
    $origin = strtoupper($_POST['origin'] ?? '');
    $destination = strtoupper($_POST['destination'] ?? '');
    $max_price = (float)($_POST['max_price'] ?? 0);
    
    if (!empty($origin) && !empty($destination) && $max_price > 0) {
        $priceAlerts->createAlert($origin, $destination, $max_price);
        $response = ['status' => 'success', 'message' => 'Alerta de preço criado com sucesso'];
    }
}

// ===============================================================
// 8. INTERFACE HTML DO DASHBOARD
// ===============================================================
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✈️ Flight Automation System - Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            animation: slideDown 0.5s ease;
        }

        header h1 {
            color: #667eea;
            font-size: 2.5em;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        header p {
            color: #666;
            font-size: 1.1em;
        }

        .search-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            animation: slideUp 0.5s ease;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.95em;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
            background: white;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        button:active {
            transform: translateY(0);
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: white;
            color: #667eea;
            border-color: white;
        }

        .tab-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .results-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            animation: fadeIn 0.5s ease;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            font-size: 0.9em;
            opacity: 0.9;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 2.5em;
            font-weight: bold;
        }

        .suggestions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .suggestion-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 5px solid rgba(255, 255, 255, 0.3);
        }

        .suggestion-card.good {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .suggestion-card.warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .suggestion-card h4 {
            margin-bottom: 10px;
            font-size: 1.2em;
        }

        .suggestion-card p {
            font-size: 0.95em;
            opacity: 0.95;
        }

        .flights-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .flights-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .flights-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 3px solid #667eea;
        }

        .flights-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .flights-table tbody tr {
            transition: all 0.3s ease;
        }

        .flights-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .airline-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .airline-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .airline-info {
            display: flex;
            flex-direction: column;
        }

        .airline-code {
            font-weight: bold;
            color: #667eea;
            font-size: 0.9em;
        }

        .airline-name {
            font-size: 0.85em;
            color: #666;
        }

        .price-badge {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);
        }

        .time-cell {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .departure {
            font-weight: bold;
            font-size: 1.1em;
            color: #333;
        }

        .arrival {
            font-size: 0.9em;
            color: #999;
        }

        .duration {
            font-size: 0.85em;
            background: rgba(102, 126, 234, 0.1);
            padding: 2px 8px;
            border-radius: 4px;
            color: #667eea;
            display: inline-block;
            margin-top: 5px;
        }

        .action-btn {
            padding: 8px 16px;
            font-size: 0.9em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 8px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .action-btn.favorite {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #f5576c;
        }

        .message.info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .no-results-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        footer {
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            padding: 20px;
            margin-top: 30px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #333;
        }

        .modal h2 {
            color: #667eea;
            margin-bottom: 20px;
        }

        .sidebar-info {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            border-left: 5px solid #667eea;
        }

        .sidebar-info h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.1em;
        }

        .sidebar-info ul {
            list-style: none;
            padding: 0;
        }

        .sidebar-info li {
            padding: 8px 0;
            color: #666;
            border-bottom: 1px solid #f0f0f0;
        }

        .sidebar-info li:last-child {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            margin-left: 5px;
        }

        @media (max-width: 768px) {
            .search-form {
                grid-template-columns: 1fr;
            }

            .flights-table {
                font-size: 0.9em;
            }

            .flights-table th,
            .flights-table td {
                padding: 10px;
            }

            header h1 {
                font-size: 1.8em;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header>
            <h1>✈️ Flight Automation System</h1>
            <p>Encontre os voos mais baratos com recomendações inteligentes e acompanhamento de preços em tempo real</p>
        </header>

        <!-- Search Section -->
        <div class="search-section">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('search')">🔍 Pesquisar Voos</button>
                <button class="tab-btn" onclick="switchTab('favorites')">❤️ Favoritos</button>
                <button class="tab-btn" onclick="switchTab('alerts')">🔔 Alertas de Preço</button>
            </div>

            <!-- Tab: Search -->
            <div id="search-tab">
                <form method="POST" class="search-form">
                    <input type="hidden" name="action" value="search">
                    
                    <div class="form-group">
                        <label for="origin">Origem (Código IATA)</label>
                        <input type="text" id="origin" name="origin" placeholder="Ex: LIS, RIO, SAO" maxlength="3" style="text-transform: uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label for="destination">Destino (Código IATA)</label>
                        <input type="text" id="destination" name="destination" placeholder="Ex: SAO, RIO, BRA" maxlength="3" style="text-transform: uppercase;" required>
                    </div>

                    <div class="form-group">
                        <button type="submit">🔍 Pesquisar Voos</button>
                    </div>
                </form>
            </div>

            <!-- Tab: Favorites -->
            <div id="favorites-tab" style="display: none;">
                <form method="POST" id="favorites-form">
                    <input type="hidden" name="action" value="load_favorites">
                    <button type="submit">Carregar Favoritos</button>
                </form>
            </div>

            <!-- Tab: Alerts -->
            <div id="alerts-tab" style="display: none;">
                <form method="POST" class="search-form">
                    <input type="hidden" name="action" value="create_alert">
                    
                    <div class="form-group">
                        <label for="alert-origin">Origem</label>
                        <input type="text" id="alert-origin" name="origin" placeholder="Ex: LIS" maxlength="3" style="text-transform: uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label for="alert-destination">Destino</label>
                        <input type="text" id="alert-destination" name="destination" placeholder="Ex: SAO" maxlength="3" style="text-transform: uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label for="alert-price">Preço Máximo (R$)</label>
                        <input type="number" id="alert-price" name="max_price" placeholder="Ex: 300" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <button type="submit">🔔 Criar Alerta</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($response['status'] === 'success'): ?>
            <div class="message success">
                ✅ <?php echo $response['message'] ?? 'Operação realizada com sucesso!'; ?>
            </div>
        <?php elseif ($response['status'] === 'error'): ?>
            <div class="message error">
                ❌ <?php echo $response['message'] ?? 'Ocorreu um erro!'; ?>
            </div>
        <?php endif; ?>

        <!-- Results Section -->
        <?php if ($response['status'] === 'success' && isset($response['flights'])): ?>
            <div class="results-section">
                <h2>📊 Resultados da Busca</h2>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Voos Disponíveis</h3>
                        <div class="value"><?php echo count($response['flights']); ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Preço Mínimo</h3>
                        <div class="value">R$ <?php echo number_format($response['statistics']['cheapest_price'], 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Preço Máximo</h3>
                        <div class="value">R$ <?php echo number_format($response['statistics']['most_expensive_price'], 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Preço Médio</h3>
                        <div class="value">R$ <?php echo number_format($response['statistics']['average_price'], 2, ',', '.'); ?></div>
                    </div>
                </div>

                <!-- Suggestions -->
                <h3 style="color: #333; margin: 30px 0 20px 0;">💡 Recomendações Inteligentes</h3>
                <div class="suggestions-grid">
                    <?php foreach ($response['suggestions'] as $suggestion): ?>
                        <div class="suggestion-card <?php echo $suggestion['type'] === 'urgency' ? 'warning' : ($suggestion['type'] === 'cheapest' ? 'good' : ''); ?>">
                            <h4><?php echo $suggestion['title']; ?></h4>
                            <p><?php echo $suggestion['description']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Flights Table -->
                <h3 style="color: #333; margin: 30px 0 20px 0;">✈️ Voos Disponíveis</h3>
                <table class="flights-table">
                    <thead>
                        <tr>
                            <th>Companhia</th>
                            <th>Número do Voo</th>
                            <th>Saída</th>
                            <th>Chegada</th>
                            <th>Assentos</th>
                            <th>Preço</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($response['flights'] as $flight): ?>
                            <tr>
                                <td>
                                    <div class="airline-cell">
                                        <img src="<?php echo htmlspecialchars($flight['logo_url']); ?>" alt="<?php echo $flight['airline_name']; ?>" class="airline-logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect fill=%22%23667eea%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22white%22 font-size=%2220%22%3E<?php echo substr($flight['code'], 0, 2); ?></text%3E%3C/svg%3E'">
                                        <div class="airline-info">
                                            <span class="airline-code"><?php echo $flight['code']; ?></span>
                                            <span class="airline-name"><?php echo htmlspecialchars($flight['airline_name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($flight['flight_number']); ?></td>
                                <td>
                                    <div class="time-cell">
                                        <span class="departure"><?php echo substr($flight['departure_time'], 0, 5); ?></span>
                                        <span class="arrival">→ <?php echo substr($flight['arrival_time'], 0, 5); ?></span>
                                        <span class="duration">⏱️ <?php echo $flight['duration']; ?></span>
                                    </div>
                                </td>
                                <td><?php echo $flight['available_seats']; ?></td>
                                <td><span class="price-badge">R$ <?php echo number_format($flight['price'], 2, ',', '.'); ?></span></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="add_favorite">
                                        <input type="hidden" name="flight_id" value="<?php echo $flight['id']; ?>">
                                        <button type="submit" class="action-btn favorite">❤️ Favoritar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($response['status'] === 'no_flights'): ?>
            <div class="results-section">
                <div class="no-results">
                    <div class="no-results-icon">✈️</div>
                    <h3>Nenhum voo encontrado</h3>
                    <p><?php echo $response['message']; ?></p>
                    <p>Tente uma combinação diferente de origem e destino.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sidebar Info -->
        <div class="sidebar-info">
            <h3>📋 Informações do Sistema</h3>
            <ul>
                <li>🗺️ Rotas disponíveis: LIS, OPO, FAO → RIO, SAO, BRA, MAO</li>
                <li>✈️ <?php $all_flights = $flightManager->getAllFlights(); echo count($all_flights); ?> voos cadastrados no sistema</li>
                <li>🏢 <?php echo count($airlineManager->getAllAirlines()); ?> companhias aéreas</li>
                <li>💾 Banco de dados: MySQL/XAMPP (flight_automation)</li>
                <li>🔍 Busca de voos mais baratos com 5+ sugestões inteligentes</li>
                <li>❤️ Sistema de favoritos para controlar suas passagens preferidas</li>
                <li>🔔 Alertas de preço automáticos quando encontrar boas ofertas</li>
            </ul>
        </div>

        <footer>
            <p>© 2024 Flight Automation System | Sistema de Automação de Voos com IA</p>
            <p>Desenvolvido em PHP com MySQL | Dashboard Responsivo | +1000 linhas de código</p>
        </footer>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('[id$="-tab"]').forEach(tab => {
                tab.style.display = 'none';
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').style.display = 'block';

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Auto-hide messages after 5 seconds
        document.querySelectorAll('.message').forEach(msg => {
            setTimeout(() => {
                msg.style.animation = 'slideDown 0.3s ease reverse';
                setTimeout(() => msg.remove(), 300);
            }, 5000);
        });

        // Input formatting for IATA codes
        document.querySelectorAll('input[maxlength="3"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
    </script>
</body>
</html>
