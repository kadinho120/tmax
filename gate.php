<?php
session_start();
/**
 * @file gate.php
 * @description Sistema de verificação (Cloaker) que atua como portão de entrada.
 * Este script valida o visitante e decide se mostra a página de oferta (Black Page)
 * ou uma página de segurança (White Page).
 */

// ======================================================================
// 1. CONFIGURAÇÕES PRINCIPAIS
// ======================================================================

ini_set('display_errors', 0); // Desativar erros em produção
error_reporting(0);

/** @var string URL da API de Geolocalização. */
$geoApiUrl = 'https://gogeoip-gogeoip.tutv5u.easypanel.host/api/v1/ip/';

/** @var string Chave de API para o serviço de Geolocalização. */
$geoApiKey = 'test-key';

/** @var string URL da "White Page" para onde o tráfego indesejado será enviado. */
$whitePageUrl = 'https://alpha7.instaboost.com.br/whitepage';

/** @var string Nome do arquivo da sua página de oferta real (a que criamos). */
$blackPageFile = 'index.html';


// ======================================================================
// 2. FUNÇÕES AUXILIARES DO SISTEMA
// ======================================================================

/**
 * Obtém o endereço de IP real do usuário.
 * @return string O endereço de IP do usuário.
 */
function get_user_ip() {
    $headers = [
        'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 
        'HTTP_CLIENT_IP', 'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (isset($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Busca e exibe o conteúdo da "White Page" sem redirecionar o usuário.
 * @param string $url A URL da White Page.
 * @param string $reason Motivo do bloqueio (para logs).
 */
function displayWhitePageContent($url, $reason = "Acesso Bloqueado") {
    error_log("Cloaker ativado: IP " . get_user_ip() . " mostrando conteúdo da White Page. Motivo: " . $reason);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Segue redirecionamentos da White Page, se houver
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Similar à sua outra chamada cURL
    
    $whitePageContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch) || $httpCode !== 200) {
        // Log do erro ao buscar a white page
        error_log("Falha ao buscar conteúdo da White Page. URL: $url, HTTP Code: $httpCode, Erro: " . curl_error($ch));
        // Exibe uma mensagem de erro genérica para o usuário
        echo "Ocorreu um erro ao carregar a página. Tente novamente mais tarde.";
    } else {
        // Adiciona uma tag <base> para corrigir caminhos relativos (CSS, JS, imagens)
        // Isso garante que os assets da White Page carreguem corretamente
        $parsedUrl = parse_url($url);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/';
        $contentWithBaseTag = preg_replace('/<head[^>]*>/i', '$0<base href="' . $baseUrl . '">', $whitePageContent, 1);
        
        echo $contentWithBaseTag;
    }
    
    curl_close($ch);
    exit(); // Encerra o script para não carregar a Black Page
}

/**
 * Executa todas as verificações de segurança no visitante.
 * Se qualquer verificação falhar, exibe a White Page e encerra o script.
 */
function run_cloaker_checks($whitePageUrl, $geoApiUrl, $geoApiKey) {
    // Verificação 1: Parâmetros de URL (gclid, fbclid, etc.)
    if (!isset($_GET['gclid']) && !isset($_GET['gbraid']) && !isset($_GET['fbclid'])) {
        displayWhitePageContent($whitePageUrl, "Parâmetro de rastreamento ausente");
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (empty($userAgent)) {
        displayWhitePageContent($whitePageUrl, "User Agent Vazio");
    }

    // Verificação 2: Palavras-chave de Bots no User Agent
    $blockedKeywords = [
        'bot', 'crawler', 'spider', 'slurp', 'facebookexternalhit', 'googlebot', 
        'bingbot', 'ahrefsbot', 'semrushbot', 'yandexbot', 'adsbot-google', 
        'mediapartners-google', 'apis-google', 'dataprovider', 'validator', 
        'preview', 'HeadlessChrome'
    ];
    foreach ($blockedKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            displayWhitePageContent($whitePageUrl, "User Agent de Bot/Crawler: " . $userAgent);
        }
    }

    // Verificação 4: Geolocalização e Proxy/VPN
    $user_ip = get_user_ip();

    if ($user_ip === '0.0.0.0') {
         displayWhitePageContent($whitePageUrl, "IP inválido ou local: " . $user_ip);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $geoApiUrl . $user_ip);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $geoApiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $geoResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        // Se a API falhar, é mais seguro mostrar a white page
        displayWhitePageContent($whitePageUrl, "Falha na API de Geo (HTTP: $httpCode)");
    }

    $geoData = json_decode($geoResponse, true);

    if (!is_array($geoData)) {
        displayWhitePageContent($whitePageUrl, "Resposta inválida da API de Geo");
    }
    
    // Validação do País (deve ser BR)
    if (strtoupper($geoData['iso_country_code'] ?? '') !== 'BR') {
        displayWhitePageContent($whitePageUrl, "Geolocalização fora do Brasil: " . ($geoData['iso_country_code'] ?? 'N/A'));
    }

    // Verificação de Proxy/VPN
    if ($geoData['is_anonymous_proxy'] ?? false) {
        displayWhitePageContent($whitePageUrl, "Acesso via Proxy/VPN detectado");
    }
}


// ======================================================================
// 3. EXECUÇÃO DO SISTEMA
// ======================================================================

// Roda a verificação em todos os visitantes
run_cloaker_checks($whitePageUrl, $geoApiUrl, $geoApiKey);

// Se o script chegou até aqui, o visitante é legítimo.
// Mostra a página de oferta real.
include $blackPageFile;
exit();

?>
