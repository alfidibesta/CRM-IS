<?php
/**
 * Form template helpers: a tiny line-based markup compiled into blocks,
 * then rendered either to HTML (preview) or to Word (.docx download).
 *
 * Markup
 *   # Title / ## Heading / ### Sub-heading
 *   > subtitle line
 *   plain paragraph
 *   - bullet          or    1. numbered item
 *   [ ] checkbox line
 *   | cell | cell |   (a row whose cells are **bold** becomes the header row)
 *   ---               horizontal rule
 *   {{FIELD}}         fill-in placeholder
 */

declare(strict_types=1);

function parse_template_body(string $body): array
{
    $lines = preg_split('/\r\n|\r|\n/', str_replace("\t", '    ', $body)) ?: [];
    $blocks = [];
    $table = [];
    $prev  = null;

    $flush = function () use (&$table, &$blocks) {
        if (!$table) return;
        $head = false;
        $first = $table[0];
        $bold = [];
        foreach ($first as $c) {
            $bold[] = (bool) preg_match('/^\*\*.+\*\*$/u', trim($c));
        }
        $head = $bold && count(array_filter($bold)) === count($bold);
        $rows = [];
        foreach ($table as $i => $row) {
            $cells = [];
            foreach ($row as $c) {
                $c = trim($c);
                if ($i === 0 && $head) $c = trim($c, '*');
                $cells[] = $c;
            }
            $rows[] = $cells;
        }
        $blocks[] = ['t' => 'table', 'rows' => $rows, 'head' => $head];
        $table = [];
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw);

        // table rows keep collecting
        if (preg_match('/^\|(.+)\|\s*$/', $line, $m)) {
            $cells = array_map('trim', explode('|', $m[1]));
            $table[] = $cells;
            $prev = 'table';
            continue;
        }
        if ($prev === 'table') {
            $flush();
        }

        if (trim($line) === '') {
            if ($prev !== 'space') {
                $blocks[] = ['t' => 'space'];
                $prev = 'space';
            }
            continue;
        }
        if (preg_match('/^---+$/', trim($line))) {
            $blocks[] = ['t' => 'hr'];
            $prev = 'hr';
            continue;
        }
        if (preg_match('/^###\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'h3', 'text' => $m[1]];
        } elseif (preg_match('/^##\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'h2', 'text' => $m[1]];
        } elseif (preg_match('/^#\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'h1', 'text' => $m[1]];
        } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'subtitle', 'text' => $m[1]];
        } elseif (preg_match('/^\[\s\]\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'check', 'text' => $m[1]];
        } elseif (preg_match('/^(\d+)[.)]\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'li', 'n' => (int) $m[1], 'text' => $m[2]];
        } elseif (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
            $blocks[] = ['t' => 'li', 'n' => null, 'text' => $m[1]];
        } else {
            $blocks[] = ['t' => 'p', 'text' => $line];
        }
        $prev = 'p';
    }
    $flush();

    return $blocks;
}

/** HTML rendering of the same block list (placeholders highlighted). */
function blocks_to_html(array $blocks, array $fill = []): string
{
    $out = '';
    foreach ($blocks as $b) {
        $t = $b['t'] ?? 'p';
        $text = $b['text'] ?? '';
        if ($t === 'table') {
            $out .= '<table class="doc-table">';
            foreach (($b['rows'] ?? []) as $i => $row) {
                $out .= '<tr>';
                $isHead = ($b['head'] ?? false) && $i === 0;
                foreach ($row as $c) {
                    $tag = $isHead ? 'th' : 'td';
                    $out .= '<' . $tag . '>' . tpl_inline((string) $c, $fill) . '</' . $tag . '>';
                }
                $out .= '</tr>';
            }
            $out .= '</table>';
            continue;
        }
        switch ($t) {
            case 'title':     $out .= '<h1 class="doc-title">' . tpl_inline($text, $fill) . '</h1>'; break;
            case 'subtitle':  $out .= '<p class="doc-sub">' . tpl_inline($text, $fill) . '</p>'; break;
            case 'h1':        $out .= '<h2 class="doc-h1">' . tpl_inline($text, $fill) . '</h2>'; break;
            case 'h2':        $out .= '<h3 class="doc-h2">' . tpl_inline($text, $fill) . '</h3>'; break;
            case 'h3':        $out .= '<h4 class="doc-h3">' . tpl_inline($text, $fill) . '</h4>'; break;
            case 'li':        $out .= '<p class="doc-li">' . (($b['n'] ?? null) !== null ? '<b>' . (int) $b['n'] . '.</b> ' : '• ') . tpl_inline($text, $fill) . '</p>'; break;
            case 'check':     $out .= '<p class="doc-li doc-check">☐ ' . tpl_inline($text, $fill) . '</p>'; break;
            case 'hr':        $out .= '<hr class="doc-hr">'; break;
            case 'space':     $out .= '<p class="doc-space"></p>'; break;
            case 'pagebreak': $out .= '<hr class="doc-break">'; break;
            default:          $out .= '<p>' . tpl_inline($text, $fill) . '</p>';
        }
    }
    return $out;
}

/** Inline rendering: **bold** and {{PLACEHOLDER}}. */
function tpl_inline(string $text, array $fill = []): string
{
    $html = e($text);
    $html = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
    $html = (string) preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/i', function ($m) use ($fill) {
        $key = strtoupper($m[1]);
        $val = '';
        foreach ($fill as $k => $v) {
            if (strtoupper((string) $k) === $key && trim((string) $v) !== '') {
                $val = (string) $v;
                break;
            }
        }
        if ($val !== '') {
            return '<span class="doc-fill doc-fill--on">' . e($val) . '</span>';
        }
        return '<span class="doc-fill">' . e(placeholder_label($key)) . '</span>';
    }, $html);
    return $html;
}

/** Header blocks prepended to every generated template document. */
function template_document_blocks(array $tpl, string $ref = ''): array
{
    $blocks = [
        ['t' => 'title', 'text' => $tpl['name']],
        ['t' => 'subtitle', 'text' => $tpl['code'] . ' · Version ' . $tpl['version'] . ' · ' . ($tpl['owner'] ?: 'SecOps CRM')],
    ];
    if ($ref !== '') {
        $blocks[] = ['t' => 'subtitle', 'text' => 'Reference: ' . $ref];
    }
    $blocks[] = ['t' => 'hr'];
    $blocks = array_merge($blocks, parse_template_body((string) ($tpl['body'] ?? '')));
    $blocks[] = ['t' => 'hr'];
    $blocks[] = ['t' => 'subtitle', 'text' => 'Generated ' . date('d M Y') . ' by SecOps CRM · Uncontrolled when printed'];
    return $blocks;
}
