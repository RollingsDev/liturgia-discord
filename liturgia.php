<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const API_URL = 'https://liturgia.up.railway.app/v2/';
const DISCORD_CONTENT_LIMIT = 1800;

$webhookUrl = trim((string) getenv('DISCORD_WEBHOOK_URL'));
$forcePost = filter_var(getenv('FORCE_POST') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$stateFile = __DIR__ . '/.state/last-posted-date.txt';
$today = date('d/m/Y');

if (!$forcePost && is_file($stateFile) && trim((string) file_get_contents($stateFile)) === $today) {
    echo "A liturgia de {$today} já foi publicada. Nada a fazer.\n";
    exit(0);
}

if ($webhookUrl === '') {
    throw new RuntimeException('DISCORD_WEBHOOK_URL não configurado.');
}

function httpGetJson(string $url): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar o cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: liturgia-discord/1.0',
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("Erro ao consultar a API. HTTP {$status}. {$error}");
    }

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('A API retornou um JSON inválido.', 0, $e);
    }

    if (!is_array($data)) {
        throw new RuntimeException('A API retornou uma estrutura inesperada.');
    }

    return $data;
}

function discordPost(string $webhookUrl, array $payload): void
{
    $payload['allowed_mentions'] = ['parse' => []];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $separator = str_contains($webhookUrl, '?') ? '&' : '?';
        $ch = curl_init($webhookUrl . $separator . 'wait=true');

        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL para o Discord.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $status >= 200 && $status < 300) {
            usleep(650_000);
            return;
        }

        if ($status === 429) {
            $rate = json_decode((string) $response, true);
            $retryAfter = is_array($rate) ? (float) ($rate['retry_after'] ?? 1.5) : 1.5;
            usleep((int) (($retryAfter + 0.25) * 1_000_000));
            continue;
        }

        if ($attempt < 5 && ($status === 0 || $status >= 500)) {
            sleep($attempt);
            continue;
        }

        throw new RuntimeException("Erro ao enviar mensagem ao Discord. HTTP {$status}. {$error}");
    }

    throw new RuntimeException('Número máximo de tentativas ao Discord excedido.');
}

function splitText(string $text, int $limit = DISCORD_CONTENT_LIMIT): array
{
    $text = trim(str_replace("\r", '', $text));

    if ($text === '') {
        return [];
    }

    $chunks = [];

    while (mb_strlen($text, 'UTF-8') > $limit) {
        $candidate = mb_substr($text, 0, $limit, 'UTF-8');
        $newline = mb_strrpos($candidate, "\n", 0, 'UTF-8');
        $space = mb_strrpos($candidate, ' ', 0, 'UTF-8');

        $cut = max(
            $newline === false ? 0 : $newline,
            $space === false ? 0 : $space,
        );

        if ($cut < (int) ($limit * 0.55)) {
            $cut = $limit;
        }

        $chunks[] = trim(mb_substr($text, 0, $cut, 'UTF-8'));
        $text = trim(mb_substr($text, $cut, null, 'UTF-8'));
    }

    if ($text !== '') {
        $chunks[] = $text;
    }

    return $chunks;
}

function sendSection(string $webhookUrl, string $title, string $body): void
{
    $body = trim($body);

    if ($body === '') {
        return;
    }

    $titleBlock = "**{$title}**\n\n";
    $firstLimit = DISCORD_CONTENT_LIMIT - mb_strlen($titleBlock, 'UTF-8');
    $chunks = splitText($body, max(1000, $firstLimit));

    foreach ($chunks as $index => $chunk) {
        $content = $index === 0
            ? $titleBlock . $chunk
            : "**↳ Continuação — {$title}**\n\n{$chunk}";

        discordPost($webhookUrl, ['content' => $content]);
    }
}

function liturgicalColor(string $color): int
{
    $normalized = mb_strtolower(trim($color), 'UTF-8');

    return match ($normalized) {
        'verde' => 0x2E8B57,
        'vermelho' => 0xB22222,
        'roxo', 'violeta' => 0x6F42C1,
        'rosa', 'róseo', 'roseo' => 0xFF69B4,
        'preto' => 0x23272A,
        'branco' => 0xF2F3F5,
        default => 0xC9A227,
    };
}

function readingBody(array $reading): string
{
    $parts = [];

    $reference = trim((string) ($reading['referencia'] ?? ''));
    $title = trim((string) ($reading['titulo'] ?? ''));
    $refrain = trim((string) ($reading['refrao'] ?? ''));
    $text = trim((string) ($reading['texto'] ?? ''));

    if ($reference !== '') {
        $parts[] = "**{$reference}**";
    }

    if ($title !== '') {
        $parts[] = "*{$title}*";
    }

    if ($refrain !== '') {
        $parts[] = "**R.: {$refrain}**";
    }

    if ($text !== '') {
        $parts[] = $text;
    }

    return implode("\n\n", $parts);
}

function sendReadings(string $webhookUrl, array $readings, string $key, string $label): void
{
    $items = $readings[$key] ?? [];

    if (!is_array($items) || $items === []) {
        return;
    }

    $count = count($items);

    foreach ($items as $index => $reading) {
        if (!is_array($reading)) {
            continue;
        }

        $suffix = $count > 1 ? ' — Opção ' . ($index + 1) : '';
        sendSection($webhookUrl, $label . $suffix, readingBody($reading));
    }
}

$liturgy = httpGetJson(API_URL . '?_=' . time());

$date = trim((string) ($liturgy['data'] ?? date('d/m/Y')));
$celebration = trim((string) ($liturgy['liturgia'] ?? 'Liturgia do Dia'));
$colorName = trim((string) ($liturgy['cor'] ?? ''));

$expectedDate = $today;
if ($date !== '' && $date !== $expectedDate) {
    throw new RuntimeException(
        "A API retornou a liturgia de {$date}, mas hoje em America/Sao_Paulo é {$expectedDate}."
    );
}

discordPost($webhookUrl, [
    'embeds' => [[
        'title' => "✝️ Liturgia do Dia — {$date}",
        'description' => "**{$celebration}**",
        'color' => liturgicalColor($colorName),
        'fields' => [[
            'name' => 'Cor litúrgica',
            'value' => $colorName !== '' ? $colorName : 'Não informada',
            'inline' => true,
        ]],
        'footer' => [
            'text' => 'O Caminho • dados: liturgia.up.railway.app',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
]);

$prayers = is_array($liturgy['oracoes'] ?? null) ? $liturgy['oracoes'] : [];
$readings = is_array($liturgy['leituras'] ?? null) ? $liturgy['leituras'] : [];
$antiphons = is_array($liturgy['antifonas'] ?? null) ? $liturgy['antifonas'] : [];

if (!empty($antiphons['entrada'])) {
    sendSection($webhookUrl, '🕯️ Antífona de Entrada', (string) $antiphons['entrada']);
}

if (!empty($prayers['coleta'])) {
    sendSection($webhookUrl, '🙏 Oração da Coleta', (string) $prayers['coleta']);
}

sendReadings($webhookUrl, $readings, 'primeiraLeitura', '📖 Primeira Leitura');
sendReadings($webhookUrl, $readings, 'salmo', '🎵 Salmo Responsorial');
sendReadings($webhookUrl, $readings, 'segundaLeitura', '📖 Segunda Leitura');

$extraReadings = $readings['extras'] ?? [];
if (is_array($extraReadings)) {
    foreach ($extraReadings as $index => $extra) {
        if (!is_array($extra)) {
            continue;
        }

        $type = trim((string) ($extra['tipo'] ?? $extra['titulo'] ?? 'Leitura Extra'));
        $title = $type !== '' ? "📜 {$type}" : '📜 Leitura Extra ' . ($index + 1);
        sendSection($webhookUrl, $title, readingBody($extra));
    }
}

sendReadings($webhookUrl, $readings, 'evangelho', '✠ Evangelho');

$extraPrayers = $prayers['extras'] ?? [];
if (is_array($extraPrayers)) {
    foreach ($extraPrayers as $extra) {
        if (!is_array($extra)) {
            continue;
        }

        $title = trim((string) ($extra['titulo'] ?? 'Oração Extra'));
        $text = trim((string) ($extra['texto'] ?? ''));

        if ($text !== '') {
            sendSection($webhookUrl, "🙏 {$title}", $text);
        }
    }
}

if (!empty($prayers['oferendas'])) {
    sendSection($webhookUrl, '🍞 Oração sobre as Oferendas', (string) $prayers['oferendas']);
}

if (!empty($antiphons['comunhao'])) {
    sendSection($webhookUrl, '🕊️ Antífona da Comunhão', (string) $antiphons['comunhao']);
}

if (!empty($prayers['comunhao'])) {
    sendSection($webhookUrl, '🙏 Oração depois da Comunhão', (string) $prayers['comunhao']);
}

if (!is_dir(dirname($stateFile)) && !mkdir(dirname($stateFile), 0775, true) && !is_dir(dirname($stateFile))) {
    throw new RuntimeException('Não foi possível criar o diretório de estado.');
}

if (file_put_contents($stateFile, $expectedDate . PHP_EOL) === false) {
    throw new RuntimeException('Não foi possível registrar a data da última publicação.');
}

echo "Liturgia de {$date} enviada com sucesso.\n";
