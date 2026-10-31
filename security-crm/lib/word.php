<?php
/**
 * Minimal DOCX generation.
 *
 * A .docx file is a ZIP of XML parts. This writes the ZIP itself (stored,
 * no compression) so the code works even when ext-zip is missing — the
 * fallback path for a hand-built Word file is provided in word_fallback_html().
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ zip */

function zip_store(array $files): string
{
    $local = '';
    $central = '';
    $count = 0;

    foreach ($files as $name => $data) {
        $name = (string) $name;
        $data = (string) $data;
        $len  = strlen($data);
        $crc  = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }
        $offset = strlen($local);

        $local .= pack('V', 0x04034b50)
            . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', strlen($name)) . pack('v', 0)
            . $name . $data;

        $central .= pack('V', 0x02014b50)
            . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', $offset)
            . $name;
        $count++;
    }

    $eocd = pack('V', 0x06054b50)
        . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central))
        . pack('V', strlen($local))
        . pack('v', 0);

    return $local . $central . $eocd;
}

/* -------------------------------------------------------------- helpers */

function wx(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Replace {{PLACEHOLDER}} tokens; unknown ones become [PLACEHOLDER]. */
function fill_text(string $text, array $fill): string
{
    return (string) preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/i', function ($m) use ($fill) {
        $key = strtoupper($m[1]);
        foreach ($fill as $k => $v) {
            if (strtoupper((string) $k) === $key && trim((string) $v) !== '') {
                return (string) $v;
            }
        }
        return '[' . $m[1] . ']';
    }, $text);
}

/** Distinct placeholder names inside a template body. */
function placeholders_of(string $body): array
{
    preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/i', $body, $m);
    $out = [];
    foreach ($m[1] as $key) {
        $key = strtoupper($key);
        $out[$key] = $key;
    }
    return $out;
}

/** Human label for a placeholder key: PARTY_A -> Party A. */
function placeholder_label(string $key): string
{
    return ucwords(strtolower(str_replace('_', ' ', $key)));
}

/* ----------------------------------------------------------- docx build */

function docx_run(string $text, array $o = []): string
{
    $rPr = '';
    if (!empty($o['b'])) $rPr .= '<w:b/>';
    if (!empty($o['i'])) $rPr .= '<w:i/>';
    if (!empty($o['u'])) $rPr .= '<w:u w:val="single"/>';
    if (!empty($o['color'])) $rPr .= '<w:color w:val="' . wx($o['color']) . '"/>';
    if (!empty($o['size'])) {
        $s = (int) $o['size'];
        $rPr .= '<w:sz w:val="' . $s . '"/><w:szCs w:val="' . $s . '"/>';
    }
    if (!empty($o['font'])) {
        $f = wx($o['font']);
        $rPr .= '<w:rFonts w:ascii="' . $f . '" w:hAnsi="' . $f . '" w:cs="' . $f . '"/>';
    }
    return '<w:r>' . ($rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '')
        . '<w:t xml:space="preserve">' . wx($text) . '</w:t></w:r>';
}

/** Bold text up front when the string starts with a **label** prefix. */
function docx_para(string $text, array $o = []): string
{
    $runs = '';
    if (preg_match('/^\*\*(.+?)\*\*\s*(.*)$/s', $text, $m)) {
        $runs .= docx_run($m[1], ['b' => true] + $o);
        if (trim($m[2]) !== '') {
            $runs .= docx_run(' ' . $m[2], $o);
        }
    } else {
        $runs .= docx_run($text, $o);
    }

    $pPr = '<w:spacing w:before="0" w:after="120" w:line="276" w:lineRule="auto"/>';
    if (!empty($o['align'])) $pPr .= '<w:jc w:val="' . wx($o['align']) . '"/>';
    if (!empty($o['ind'])) $pPr .= '<w:ind w:left="' . (int) $o['ind'] . '"/>';

    return '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $runs . '</w:p>';
}

function docx_table(array $rows, bool $head, array $widths = []): string
{
    $grid = '';
    if ($widths) {
        foreach ($widths as $w) {
            $grid .= '<w:gridCol w:w="' . (int) $w . '"/>';
        }
    } else {
        $cols = max(1, max(array_map('count', $rows)));
        for ($i = 0; $i < $cols; $i++) {
            $grid .= '<w:gridCol w:w="' . (int) (9360 / $cols) . '"/>';
        }
    }

    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="B9BEC6"/>'
        . '<w:left w:val="single" w:sz="4" w:color="B9BEC6"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="B9BEC6"/>'
        . '<w:right w:val="single" w:sz="4" w:color="B9BEC6"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="D8DBE0"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="D8DBE0"/>'
        . '</w:tblBorders>'
        . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="110" w:type="dxa"/>'
        . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tblCellMar>'
        . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';

    foreach ($rows as $ri => $row) {
        $isHead = $head && $ri === 0;
        $xml .= '<w:tr>';
        foreach ($row as $cell) {
            $opts = $isHead
                ? ['b' => true, 'size' => 20, 'color' => '14161A']
                : ['size' => 20];
            $shade = $isHead ? '<w:shd w:val="clear" w:color="auto" w:fill="F1F2F5"/>' : '';
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>' . $shade . '</w:tcPr>'
                . docx_para((string) $cell, $opts)
                . '</w:tc>';
        }
        $xml .= '</w:tr>';
    }

    return $xml . '</w:tbl>' . docx_para('');
}

/**
 * Render a block list into document.xml body content.
 * Blocks: title | h1 | h2 | h3 | p | li | table | space | hr | pagebreak
 */
function docx_body(array $blocks, array $fill = []): string
{
    $out = '';

    foreach ($blocks as $b) {
        $t = $b['t'] ?? 'p';
        switch ($t) {
            case 'title':
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), ['b' => true, 'size' => 34, 'align' => 'center']);
                break;
            case 'subtitle':
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), ['size' => 20, 'color' => '5B616B', 'align' => 'center']);
                break;
            case 'h1':
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), ['b' => true, 'size' => 27, 'color' => '2F6FED']);
                break;
            case 'h2':
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), ['b' => true, 'size' => 23, 'color' => '14161A']);
                break;
            case 'h3':
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), ['b' => true, 'size' => 21]);
                break;
            case 'li':
                $n = isset($b['n']) ? ((int) $b['n'] . '.') : '•';
                $out .= docx_para($n . '  ' . fill_text((string) ($b['text'] ?? ''), $fill), ['ind' => 360]);
                break;
            case 'check':
                $out .= docx_para('☐  ' . fill_text((string) ($b['text'] ?? ''), $fill), ['ind' => 360]);
                break;
            case 'table':
                $rows = [];
                foreach (($b['rows'] ?? []) as $row) {
                    $cells = [];
                    foreach ($row as $c) {
                        $cells[] = fill_text((string) $c, $fill);
                    }
                    $rows[] = $cells;
                }
                if ($rows) $out .= docx_table($rows, (bool) ($b['head'] ?? false));
                break;
            case 'space':
                $out .= '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
                break;
            case 'hr':
                $out .= docx_para(str_repeat('—', 46), ['color' => 'B9BEC6', 'align' => 'center']);
                break;
            case 'pagebreak':
                $out .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
                break;
            default:
                $out .= docx_para(fill_text((string) ($b['text'] ?? ''), $fill), $b['opts'] ?? []);
        }
    }

    return $out;
}

function docx_package(array $blocks, array $fill = [], array $props = []): string
{
    $title = (string) ($props['title'] ?? 'Document');
    $creator = (string) ($props['creator'] ?? 'SecOps CRM');

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . docx_body($blocks, $fill)
        . '<w:sectPr>'
        . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="720" w:footer="720" w:gutter="0"/>'
        . '</w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';

    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . wx($title) . '</dc:title>'
        . '<dc:creator>' . wx($creator) . '</dc:creator>'
        . '<cp:lastModifiedBy>' . wx($creator) . '</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>SecOps CRM</Application><Company>SecOps CRM</Company></Properties>';

    return zip_store([
        '[Content_Types].xml'   => $contentTypes,
        '_rels/.rels'           => $rels,
        'word/document.xml'     => $document,
        'word/_rels/document.xml.rels' => $docRels,
        'docProps/core.xml'     => $core,
        'docProps/app.xml'      => $app,
    ]);
}

/** Stream a .docx to the browser and stop. */
function docx_send(string $filename, string $bytes): void
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'document.docx';
    if (stripos($safe, '.docx') === false) {
        $safe .= '.docx';
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/**
 * Old-school Word HTML — used only if something downstream refuses the docx.
 * Kept as an explicit fallback helper so it is testable.
 */
function word_fallback_html(array $blocks, array $fill = []): string
{
    $out = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">'
        . '<head><meta charset="utf-8"><title>Document</title></head><body style="font-family:Calibri,sans-serif">';
    foreach ($blocks as $b) {
        $t = $b['t'] ?? 'p';
        $text = htmlspecialchars(fill_text((string) ($b['text'] ?? ''), $fill), ENT_QUOTES, 'UTF-8');
        switch ($t) {
            case 'title': $out .= '<h1 style="text-align:center">' . $text . '</h1>'; break;
            case 'h1':    $out .= '<h1 style="color:#2f6fed">' . $text . '</h1>'; break;
            case 'h2':    $out .= '<h2>' . $text . '</h2>'; break;
            case 'h3':    $out .= '<h3>' . $text . '</h3>'; break;
            case 'li':    $out .= '<p style="margin-left:24px">' . $text . '</p>'; break;
            case 'check': $out .= '<p style="margin-left:24px">☐ ' . $text . '</p>'; break;
            case 'hr':    $out .= '<hr>'; break;
            case 'space': $out .= '<p>&nbsp;</p>'; break;
            case 'table':
                $out .= '<table border="1" cellspacing="0" cellpadding="4">';
                foreach (($b['rows'] ?? []) as $i => $row) {
                    $out .= '<tr>';
                    foreach ($row as $c) {
                        $tag = (($b['head'] ?? false) && $i === 0) ? 'th' : 'td';
                        $out .= '<' . $tag . '>' . htmlspecialchars(fill_text((string) $c, $fill), ENT_QUOTES, 'UTF-8') . '</' . $tag . '>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table>';
                break;
            case 'subtitle': $out .= '<p style="text-align:center;color:#5b616b">' . $text . '</p>'; break;
            default: $out .= '<p>' . $text . '</p>';
        }
    }
    return $out . '</body></html>';
}
