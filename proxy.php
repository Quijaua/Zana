<?php
/**
 * SimpleProxy - Web Proxy para diagnóstico de acesso
 * Uso: hospede este arquivo PHP e compartilhe a URL com seus cursistas.
 */

// ─── Configurações ────────────────────────────────────────────────────────────
define('PROXY_TITLE',   'Verificador de Acesso');
define('PROXY_VERSION', '1.1');
define('TIMEOUT', 20);
define('UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
// Diretório gravável para o jar de cookies (deve ter permissão de escrita)
define('COOKIE_DIR', sys_get_temp_dir());

// ─── Opções recebidas ─────────────────────────────────────────────────────────
$opt_cookies = isset($_GET['cookies']) && $_GET['cookies'] === '1';   // Allow Cookies
$opt_noscript = isset($_GET['noscript']) && $_GET['noscript'] === '1'; // Remove Scripts

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Resolve URLs relativas a partir de uma base */
function resolve_url(string $base, string $rel): string {
    if (preg_match('#^https?://#i', $rel)) return $rel;
    if (str_starts_with($rel, '//')) return parse_url($base, PHP_URL_SCHEME) . ':' . $rel;
    $parts  = parse_url($base);
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host']   ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    if (str_starts_with($rel, '/')) return "$scheme://$host$port$rel";
    $path = rtrim(dirname($parts['path'] ?? '/'), '/') . '/';
    return "$scheme://$host$port$path$rel";
}

/** Monta a query string de opções para repassar nos links reescritos */
function opts_qs(): string {
    global $opt_cookies, $opt_noscript;
    $parts = [];
    if ($opt_cookies)  $parts[] = 'cookies=1';
    if ($opt_noscript) $parts[] = 'noscript=1';
    return $parts ? '&' . implode('&', $parts) : '';
}

/** Reescreve URLs dentro do HTML para passarem pelo proxy */
function rewrite_html(string $html, string $base_url): string {
    $proxy = $_SERVER['PHP_SELF'];
    $qs    = opts_qs();

    $html = preg_replace_callback(
        '/(?:href|src|action)=["\']([^"\']*)["\']|url\(["\']?([^"\')\s]+)["\']?\)/i',
        function ($m) use ($base_url, $proxy, $qs) {
            $original = (isset($m[1]) && $m[1] !== '') ? $m[1] : ($m[2] ?? '');
            if (!$original
                || str_starts_with($original, 'data:')
                || str_starts_with($original, 'javascript:')
                || str_starts_with($original, '#')
                || str_starts_with($original, 'mailto:')
                || str_starts_with($original, 'tel:')) {
                return $m[0];
            }
            $abs     = resolve_url($base_url, $original);
            $proxied = $proxy . '?url=' . urlencode($abs) . '&mode=proxy' . $qs;
            return str_replace($original, $proxied, $m[0]);
        },
        $html
    );
    return $html;
}

/** Remove todas as tags <script> do HTML */
function remove_scripts(string $html): string {
    // Remove blocos <script>...</script>
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    // Remove atributos de evento inline (onclick, onload etc.)
    $html = preg_replace('/\s+on\w+=["\'][^"\']*["\']/i', '', $html);
    return $html;
}

/** Gera um nome de arquivo de cookie baseado no domínio da sessão */
function cookie_file(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?? 'default';
    $host = preg_replace('/[^a-z0-9\-\.]/i', '_', $host);
    return COOKIE_DIR . '/sproxy_cookies_' . $host . '.txt';
}

/** Faz a requisição via cURL */
function fetch_url(string $url, bool $use_cookies = false): array {
    $parsed = parse_url($url);
    $host   = $parsed['host'] ?? '';
    $origin = ($parsed['scheme'] ?? 'https') . '://' . $host;
    $referer = $origin . '/';

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER         => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            "Origin: $origin",
            "Referer: $referer",
            "Host: $host",
        ],
    ];

    if ($use_cookies) {
        $cfile = cookie_file($url);
        $opts[CURLOPT_COOKIEJAR]  = $cfile;  // salva cookies recebidos
        $opts[CURLOPT_COOKIEFILE] = $cfile;  // envia cookies armazenados
    }

    curl_setopt_array($ch, $opts);

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    $hsize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) return [0, [], null, $err ?: 'Falha desconhecida no cURL'];

    $raw_headers = substr($raw, 0, $hsize);
    $body        = substr($raw, $hsize);

    // Parseia headers (mantém apenas a última ocorrência de cada key)
    $headers = [];
    foreach (explode("\r\n", $raw_headers) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $headers, $body, ''];
}

// ─── Lógica principal ─────────────────────────────────────────────────────────

$target_url = trim($_GET['url'] ?? '');
$mode       = $_GET['mode'] ?? 'check';
$result     = null;

// Normaliza URL
if ($target_url && !preg_match('#^https?://#i', $target_url)) {
    $target_url = 'https://' . $target_url;
}

if ($target_url) {
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $result = ['ok' => false, 'msg' => 'URL inválida.'];
    } else {
        [$status, $headers, $body, $err] = fetch_url($target_url, $opt_cookies);

        if ($err) {
            $result = ['ok' => false, 'msg' => "Erro de conexão: $err", 'status' => 0];
        } elseif ($status === 0) {
            $result = ['ok' => false, 'msg' => 'Sem resposta do servidor.', 'status' => 0];
        } else {
            $result = [
                'ok'      => $status >= 200 && $status < 400,
                'status'  => $status,
                'msg'     => "HTTP $status",
                'headers' => $headers,
                'body'    => $body,
                'ct'      => $headers['content-type'] ?? '',
            ];
        }

        // ── Modo proxy: serve o conteúdo ──────────────────────────────────────
        if ($mode === 'proxy' && !empty($result['ok']) && $body !== null) {
            $ct = $result['ct'];

            if (str_contains($ct, 'text/html')) {
                $rewritten = rewrite_html($body, $target_url);

                if ($opt_noscript) {
                    $rewritten = remove_scripts($rewritten);
                }

                // ── Barra de navegação ────────────────────────────────────────
                $badge_cookies  = $opt_cookies  ? '<span style="background:#1a3a1a;color:#4dca7a;border:1px solid #2d6644;border-radius:4px;padding:1px 6px;font-size:10px">🍪 cookies</span>' : '';
                $badge_noscript = $opt_noscript ? '<span style="background:#1a1a3a;color:#7aacf5;border:1px solid #2d4466;border-radius:4px;padding:1px 6px;font-size:10px">🚫 scripts</span>' : '';

                $bar_style = 'position:fixed;top:0;left:0;width:100%;height:38px;z-index:2147483647;'
                           . 'background:rgba(13,15,20,.98);color:#fff;font:11px/38px monospace;'
                           . 'padding:0 12px;display:flex;gap:8px;align-items:center;'
                           . 'box-shadow:0 2px 10px rgba(0,0,0,.6);border-bottom:1px solid #2a2f3d;';

                $back_url = htmlspecialchars(
                    $_SERVER['PHP_SELF'] . '?url=' . urlencode($target_url)
                    . opts_qs()
                );

                $bar = '<style>html{margin-top:38px!important}</style>'
                     . '<div id="__proxy_bar__" style="' . $bar_style . '">'
                     . '<span style="opacity:.4;white-space:nowrap;font-size:13px">&#128225;</span>'
                     . $badge_cookies
                     . $badge_noscript
                     . '<span style="flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;opacity:.65;font-size:10px">'
                     .   htmlspecialchars($target_url)
                     . '</span>'
                     . '<a href="' . $back_url . '" style="color:#44aaff;text-decoration:none;white-space:nowrap;font-size:11px">&larr; Voltar</a>'
                     . '</div>';

                if (preg_match('/<head[^>]*>/i', $rewritten)) {
                    $rewritten = preg_replace('/(<head[^>]*>)/i', '$1' . $bar, $rewritten, 1);
                } else {
                    $rewritten = $bar . $rewritten;
                }

                header('Content-Type: text/html; charset=utf-8');
                echo $rewritten;

            } else {
                // CSS, JS, imagens, fontes etc. — repassa direto
                if ($ct) header("Content-Type: $ct");
                echo $body;
            }
            exit;
        }
    }
}

// ─── Interface HTML ───────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= PROXY_TITLE ?></title>
<style>
  :root {
    --bg:    #0d0f14;
    --card:  #161a22;
    --bord:  #2a2f3d;
    --accent:#44aaff;
    --green: #4dca7a;
    --red:   #e05a5a;
    --text:  #e0e4ef;
    --muted: #6b7280;
    --mono:  ui-monospace, 'Cascadia Code', 'Fira Mono', Consolas, Menlo, Monaco, monospace;
    --sans:  system-ui, -apple-system, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 48px 16px 80px;
  }

  body::before {
    content: '';
    position: fixed; inset: 0; z-index: -1;
    background-image:
      linear-gradient(var(--bord) 1px, transparent 1px),
      linear-gradient(90deg, var(--bord) 1px, transparent 1px);
    background-size: 40px 40px;
    opacity: .3;
  }

  .logo {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 32px;
  }
  .logo span { color: var(--accent); }

  h1 {
    font-family: var(--mono);
    font-size: clamp(20px, 5vw, 34px);
    font-weight: 600;
    letter-spacing: -.02em;
    color: var(--text);
    margin-bottom: 8px;
    text-align: center;
  }

  .subtitle {
    font-size: 14px;
    color: var(--muted);
    margin-bottom: 36px;
    text-align: center;
    max-width: 440px;
    line-height: 1.6;
  }

  .card {
    background: var(--card);
    border: 1px solid var(--bord);
    border-radius: 12px;
    padding: 28px 28px 24px;
    width: 100%;
    max-width: 580px;
    box-shadow: 0 24px 64px #0008;
  }

  .field-label {
    display: block;
    font-family: var(--mono);
    font-size: 10px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }

  input[type=text] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--bord);
    border-radius: 8px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    padding: 11px 14px;
    outline: none;
    transition: border-color .2s;
  }
  input[type=text]:focus { border-color: var(--accent); }

  /* ── Opções (checkboxes) ── */
  .options {
    display: flex;
    gap: 12px;
    margin-top: 14px;
    flex-wrap: wrap;
  }

  .opt {
    display: flex;
    align-items: center;
    gap: 7px;
    cursor: pointer;
    user-select: none;
    padding: 7px 12px;
    border-radius: 8px;
    border: 1px solid var(--bord);
    background: var(--bg);
    transition: border-color .2s, background .2s;
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
  }
  .opt:hover { border-color: #444c5e; }
  .opt input[type=checkbox] { display: none; }
  .opt .check-box {
    width: 14px; height: 14px;
    border: 1px solid var(--bord);
    border-radius: 3px;
    background: var(--bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px;
    transition: background .15s, border-color .15s;
    flex-shrink: 0;
  }
  .opt.active {
    border-color: var(--accent);
    background: #0d1a2a;
    color: var(--text);
  }
  .opt.active .check-box {
    background: var(--accent);
    border-color: var(--accent);
    color: #0d0f14;
  }

  .opt-desc {
    font-size: 9px;
    color: var(--muted);
    margin-top: 2px;
    display: block;
    line-height: 1.3;
  }

  /* ── Botões ── */
  .btns {
    display: flex;
    gap: 8px;
    margin-top: 16px;
  }

  button[type=submit] {
    flex: 1;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-family: var(--mono);
    font-size: 12px;
    padding: 11px 16px;
    transition: opacity .15s, transform .1s;
    white-space: nowrap;
  }
  button[type=submit]:active { transform: scale(.97); }

  .btn-proxy { background: var(--accent); color: #0d0f14; font-weight: 600; }
  .btn-check { background: var(--bord);   color: var(--text); }
  button:hover { opacity: .85; }

  /* ── Separador ── */
  .sep {
    border: none;
    border-top: 1px solid var(--bord);
    margin: 22px 0 0;
  }

  /* ── Resultado ── */
  .result {
    margin-top: 20px;
    border-radius: 10px;
    padding: 16px 18px;
    font-family: var(--mono);
    font-size: 13px;
    line-height: 1.7;
    border: 1px solid;
  }
  .result.ok  { background: #0d2318; border-color: #2d6644; color: var(--green); }
  .result.err { background: #200f0f; border-color: #6e2020; color: var(--red); }

  .result .tag {
    font-size: 10px; letter-spacing: .15em;
    text-transform: uppercase; opacity: .6;
    display: block; margin-bottom: 5px;
  }
  .result .big { font-size: 20px; font-weight: 600; }

  .headers-toggle {
    margin-top: 10px; font-size: 11px; color: var(--muted);
    cursor: pointer; text-decoration: underline;
    background: none; border: none; padding: 0;
    font-family: var(--mono);
  }
  .headers-box {
    display: none; margin-top: 8px;
    background: var(--bg); border: 1px solid var(--bord);
    border-radius: 6px; padding: 10px;
    font-size: 11px; color: var(--muted);
    max-height: 180px; overflow-y: auto;
  }
  .headers-box.open { display: block; }
  .headers-box dt { color: var(--accent); }
  .headers-box dd { margin-left: 14px; word-break: break-all; }

  /* ── Dicas ── */
  .tips {
    margin-top: 24px; font-size: 12px; color: var(--muted);
    max-width: 580px; text-align: center; line-height: 1.8;
  }
  .tips strong { color: var(--text); }

  @media(max-width: 480px) {
    .btns { flex-direction: column; }
    .options { gap: 8px; }
  }
</style>
</head>
<body>

<p class="logo"><span>//</span> SimpleProxy <?= PROXY_VERSION ?></p>

<h1>Verificador de Acesso</h1>
<p class="subtitle">Cole a URL do site, escolha as opções e clique em <strong>Abrir via Proxy</strong> ou <strong>Só Verificar</strong>.</p>

<div class="card">
  <form method="get" action="" id="proxyForm">

    <label class="field-label" for="url_input">URL do site</label>
    <input
      type="text"
      id="url_input"
      name="url"
      placeholder="https://moodle.suainstituicao.edu.br"
      value="<?= htmlspecialchars($target_url) ?>"
      autocomplete="off"
      autocapitalize="none"
      spellcheck="false"
    >

    <!-- Opções -->
    <div class="options">

      <label class="opt <?= $opt_cookies  ? 'active' : '' ?>" id="lbl_cookies" title="Mantém sessão e login no site (necessário para Moodle e sistemas com login)">
        <input type="checkbox" name="cookies" value="1" id="chk_cookies" <?= $opt_cookies  ? 'checked' : '' ?>>
        <span class="check-box"><?= $opt_cookies  ? '✓' : '' ?></span>
        <span>
          🍪 Allow Cookies
          <span class="opt-desc">Mantém sessão / login</span>
        </span>
      </label>

      <label class="opt <?= $opt_noscript ? 'active' : '' ?>" id="lbl_noscript" title="Remove todo JavaScript da página (útil quando scripts quebram o layout no proxy)">
        <input type="checkbox" name="noscript" value="1" id="chk_noscript" <?= $opt_noscript ? 'checked' : '' ?>>
        <span class="check-box"><?= $opt_noscript ? '✓' : '' ?></span>
        <span>
          🚫 Remove Scripts
          <span class="opt-desc">Remove JS da página</span>
        </span>
      </label>

    </div>

    <hr class="sep">

    <div class="btns">
      <button type="submit" name="mode" value="proxy" class="btn-proxy">🌐 Abrir via Proxy</button>
      <button type="submit" name="mode" value="check" class="btn-check">🔍 Só Verificar</button>
    </div>

  </form>

  <?php if ($result): ?>
  <div class="result <?= $result['ok'] ? 'ok' : 'err' ?>">
    <span class="tag"><?= $result['ok'] ? '✓ Acessível' : '✗ Falhou' ?></span>
    <span class="big"><?= htmlspecialchars($result['msg']) ?></span>

    <?php if (!empty($result['headers'])): ?>
    <button class="headers-toggle" onclick="this.nextElementSibling.classList.toggle('open')">
      Ver cabeçalhos HTTP ▾
    </button>
    <dl class="headers-box">
      <?php foreach ($result['headers'] as $k => $v): ?>
        <dt><?= htmlspecialchars($k) ?></dt>
        <dd><?= htmlspecialchars($v) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>

    <?php if ($result['ok'] && $mode === 'check'): ?>
    <p style="margin-top:10px;font-size:11px;opacity:.75">
      ✅ O servidor alcança <strong><?= htmlspecialchars(parse_url($target_url, PHP_URL_HOST)) ?></strong>.
      Se o cursista não consegue, o problema é na internet <em>dele</em>.
    </p>
    <?php elseif (!$result['ok'] && isset($result['status']) && $result['status'] === 0): ?>
    <p style="margin-top:10px;font-size:11px;opacity:.75">
      ⚠️ O servidor <em>também</em> não conseguiu acessar. Site fora do ar ou bloqueio geral.
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<p class="tips">
  <strong>🍪 Allow Cookies:</strong> necessário para sites com login (Moodle, sistemas EAD).<br>
  <strong>🚫 Remove Scripts:</strong> use quando a página ficar com layout quebrado no proxy.<br>
  <strong>🔍 Só Verificar:</strong> checa se o servidor alcança o site sem abrir o conteúdo.
</p>

<script>
// Estilo visual dos checkboxes
document.querySelectorAll('.opt input[type=checkbox]').forEach(chk => {
  chk.addEventListener('change', function() {
    const lbl  = this.closest('.opt');
    const box  = lbl.querySelector('.check-box');
    if (this.checked) {
      lbl.classList.add('active');
      box.textContent = '✓';
    } else {
      lbl.classList.remove('active');
      box.textContent = '';
    }
  });
});
</script>

</body>
</html>
