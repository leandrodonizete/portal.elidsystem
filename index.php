<?php
/**
 * portal.elidsystem.com.br — o repassador.
 *
 * O sistema ElidSystem mora em UM site só (frota.elidsystem.com.br): o mesmo
 * código, o mesmo banco, as mesmas fotos e comprovantes, a mesma chave do
 * cofre (certificado A1, senha do e-mail). Este site existe para o endereço
 * portal.elidsystem.com.br ter o próprio certificado SSL na Umbler — o
 * certificado dela não cobre domínio adicional — e repassa cada acesso, como
 * veio, para o sistema, devolvendo a resposta como ela veio.
 *
 * O que se ajusta no caminho:
 *   - ida: Host do sistema; Referer/Origin com o endereço do sistema (o
 *     "Voltar" do sistema só aceita a página anterior dele mesmo); o IP de
 *     quem acessa em X-Forwarded-For e o https em X-Forwarded-Proto;
 *   - volta: Location absoluto apontando para o sistema vira este endereço.
 *
 * Os cookies (a sessão) vão e voltam sem domínio: ficam com este endereço.
 * Arquivo enviado (multipart) vai cru com `enable_post_data_reading = Off`
 * (.user.ini); sem ela, é remontado a partir de $_POST e $_FILES.
 */

declare(strict_types=1);

const DESTINO_PADRAO = 'https://frota.elidsystem.com.br';
const SALTO = ['host', 'connection', 'content-length', 'transfer-encoding', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'upgrade', 'expect'];
const SALTO_VOLTA = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'upgrade'];

$destino = rtrim((string) (getenv('PORTAL_DESTINO') ?: DESTINO_PADRAO), '/');
$hostDestino = (string) parse_url($destino, PHP_URL_HOST) . (parse_url($destino, PHP_URL_PORT) ? ':' . parse_url($destino, PHP_URL_PORT) : '');
$hostAqui = (string) ($_SERVER['HTTP_HOST'] ?? 'portal.elidsystem.com.br');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$origemAqui = ($https ? 'https' : 'http') . '://' . $hostAqui;
$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

// ------------------------------------------------------------- ida

$cabecalhos = [];
foreach (cabecalhosDaRequisicao() as $nome => $valor) {
    $n = strtolower($nome);
    if (in_array($n, SALTO, true) || str_starts_with($n, 'x-forwarded-')) {
        continue;
    }
    if ($n === 'referer' || $n === 'origin') {
        $valor = trocarOrigem($valor, $origemAqui, $destino);
    }
    $cabecalhos[$n] = $nome . ': ' . $valor;
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$antes = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
$cabecalhos['x-forwarded-for'] = 'X-Forwarded-For: ' . ($antes !== '' ? $antes . ($ip !== '' ? ', ' . $ip : '') : $ip);
$cabecalhos['x-forwarded-proto'] = 'X-Forwarded-Proto: ' . ($https ? 'https' : 'http');
$cabecalhos['x-forwarded-host'] = 'X-Forwarded-Host: ' . $hostAqui;
$cabecalhos['expect'] = 'Expect:';

$corpo = null;
if (!in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $cru = (string) file_get_contents('php://input');
    if ($cru !== '') {
        $corpo = $cru;
    } elseif ($_FILES !== []) {
        // multipart lido pelo PHP: remontado (o curl escreve o boundary novo).
        unset($cabecalhos['content-type']);
        $corpo = remontarMultipart($_POST, $_FILES);
    } elseif ($_POST !== []) {
        $corpo = http_build_query($_POST);
    } else {
        $corpo = '';
    }
}

// ------------------------------------------------------------- volta

$status = 502;
$volta = [];
$enviou = false;
$emitir = static function () use (&$status, &$volta, &$enviou, $destino, $origemAqui, $https): void {
    if ($enviou) {
        return;
    }
    $enviou = true;
    http_response_code($status);
    foreach ($volta as $l) {
        [$nome, $valor] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
        $n = strtolower($nome);
        if (in_array($n, SALTO_VOLTA, true) || ($n === 'strict-transport-security' && !$https)) {
            continue;
        }
        if ($n === 'location') {
            $valor = trocarOrigem($valor, $destino, $origemAqui);
        }
        header($nome . ': ' . $valor, false);
    }
};

$c = curl_init($destino . $uri);
curl_setopt_array($c, [
    CURLOPT_CUSTOMREQUEST  => $metodo,
    CURLOPT_HTTPHEADER     => array_values($cabecalhos),
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_NOBODY         => $metodo === 'HEAD',
    CURLOPT_HEADERFUNCTION => static function ($c, string $linha) use (&$status, &$volta): int {
        $t = trim($linha);
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $t, $m) === 1) {
            // Um novo bloco de cabeçalhos (100 Continue, por exemplo) recomeça a lista.
            $status = (int) $m[1];
            $volta = [];
        } elseif ($t !== '' && str_contains($t, ':')) {
            $volta[] = $t;
        }
        return strlen($linha);
    },
    CURLOPT_WRITEFUNCTION  => static function ($c, string $pedaco) use ($emitir): int {
        $emitir();
        echo $pedaco;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return strlen($pedaco);
    },
]);
if ($corpo !== null) {
    curl_setopt($c, CURLOPT_POSTFIELDS, $corpo);
}
$ok = curl_exec($c);
$erro = curl_error($c);
curl_close($c);

if ($ok === false && !$enviou) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>ElidSystem</title><body style="font-family:Arial,sans-serif;padding:40px;color:#16202b">'
        . '<h2>O sistema não respondeu agora</h2><p>Tente de novo em alguns instantes. Se continuar, use <a href="' . htmlspecialchars($destino . $uri, ENT_QUOTES) . '">' . htmlspecialchars($hostDestino, ENT_QUOTES) . '</a>.</p></body>';
    error_log('portal: ' . $erro);
    exit;
}
$emitir();

// ------------------------------------------------------------- apoio

/** @return array<string, string> */
function cabecalhosDaRequisicao(): array
{
    if (function_exists('getallheaders')) {
        return getallheaders() ?: [];
    }
    $h = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $h[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = (string) $v;
        } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $h[str_replace('_', '-', ucwords(strtolower($k), '_'))] = (string) $v;
        }
    }
    return $h;
}

/** Troca o começo (esquema + host) de um endereço: de um site para o outro. */
function trocarOrigem(string $valor, string $de, string $para): string
{
    foreach ([$de, preg_replace('#^https?://#', 'http://', $de), preg_replace('#^https?://#', 'https://', $de)] as $origem) {
        if ($origem !== null && stripos($valor, $origem) === 0) {
            return $para . substr($valor, strlen($origem));
        }
    }
    return $valor;
}

/**
 * $_POST e $_FILES de volta para o formato do curl (campos "a[b][c]" planos;
 * arquivos como CURLFile).
 *
 * @param array<string, mixed> $post @param array<string, mixed> $arquivos
 * @return array<string, mixed>
 */
function remontarMultipart(array $post, array $arquivos): array
{
    $campos = [];
    $achatar = static function (array $dados, string $prefixo) use (&$achatar, &$campos): void {
        foreach ($dados as $k => $v) {
            $nome = $prefixo === '' ? (string) $k : $prefixo . '[' . $k . ']';
            if (is_array($v)) {
                $achatar($v, $nome);
            } else {
                $campos[$nome] = (string) $v;
            }
        }
    };
    $achatar($post, '');
    foreach ($arquivos as $campo => $f) {
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $nomeArq) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $campos[$campo . '[' . $i . ']'] = new CURLFile($f['tmp_name'][$i], (string) $f['type'][$i], (string) $nomeArq);
                }
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $campos[$campo] = new CURLFile($f['tmp_name'], (string) $f['type'], (string) $f['name']);
        }
    }
    return $campos;
}
