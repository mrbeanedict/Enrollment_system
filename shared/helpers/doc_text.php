<?php
// Plain-text extraction from uploaded slide decks, for the AI quiz generator
// (shared/helpers/ai_quiz.php). A .pptx is a ZIP of XML files; this reads it
// in pure PHP because the zip extension (ZipArchive) is not enabled on every
// install. Nothing is ever written to disk, and every size is capped so a
// crafted archive can't exhaust memory (zip bombs).

const DOC_ZIP_MAX_ENTRY_BYTES = 5 * 1024 * 1024;   // per extracted XML file
const DOC_ZIP_MAX_TOTAL_BYTES = 25 * 1024 * 1024;  // all extracted files together
const DOC_ZIP_MAX_ENTRIES     = 5000;
const DOC_TEXT_MAX_CHARS      = 150000;

// Extract only the entries whose name satisfies $wanted(string): string => bool.
// Returns [name => contents]. Returns null if the data is not a usable,
// unencrypted, non-zip64 ZIP archive, or if any wanted entry is too large.
function doc_zip_extract(string $bytes, callable $wanted): ?array {
    $len = strlen($bytes);
    if ($len < 22) return null;

    // End of central directory record: scan back through the allowed comment.
    $eocd = strrpos(substr($bytes, max(0, $len - 65557)), "PK\x05\x06");
    if ($eocd === false) return null;
    $eocd += max(0, $len - 65557);
    if ($eocd + 22 > $len) return null;
    $h = unpack('vdisk/vcdDisk/vcdCountDisk/vcount/Vcdsize/Vcdoffset', substr($bytes, $eocd + 4, 18));
    if ($h['count'] === 0xFFFF || $h['cdoffset'] === 0xFFFFFFFF || $h['cdsize'] === 0xFFFFFFFF) return null; // zip64
    if ($h['count'] > DOC_ZIP_MAX_ENTRIES || $h['cdoffset'] + $h['cdsize'] > $len) return null;

    $out   = [];
    $total = 0;
    $pos   = $h['cdoffset'];
    for ($i = 0; $i < $h['count']; $i++) {
        if ($pos + 46 > $len || substr($bytes, $pos, 4) !== "PK\x01\x02") return null;
        $e = unpack('vflags/vmethod/x4/x4/Vcsize/Vusize/vnlen/velen/vclen/x4/x4/Voffset', substr($bytes, $pos + 8, 38));
        $name = substr($bytes, $pos + 46, $e['nlen']);
        $pos += 46 + $e['nlen'] + $e['elen'] + $e['clen'];

        if (!$wanted($name)) continue;
        if ($e['flags'] & 1) return null; // encrypted
        if ($e['usize'] > DOC_ZIP_MAX_ENTRY_BYTES) return null;

        $lo = $e['offset'];
        if ($lo + 30 > $len || substr($bytes, $lo, 4) !== "PK\x03\x04") return null;
        $l = unpack('vnlen/velen', substr($bytes, $lo + 26, 4));
        $dataStart = $lo + 30 + $l['nlen'] + $l['elen'];
        if ($dataStart + $e['csize'] > $len) return null;
        $raw = substr($bytes, $dataStart, $e['csize']);

        if ($e['method'] === 0) {
            $data = $raw;
        } elseif ($e['method'] === 8) {
            // The limit stops a lying header from inflating past the cap.
            $data = @gzinflate($raw, DOC_ZIP_MAX_ENTRY_BYTES + 1);
            if ($data === false) return null;
        } else {
            return null;
        }
        if (strlen($data) > DOC_ZIP_MAX_ENTRY_BYTES) return null;
        $total += strlen($data);
        if ($total > DOC_ZIP_MAX_TOTAL_BYTES) return null;
        $out[$name] = $data;
    }
    return $out;
}

// Text of every <a:p> paragraph (runs <a:t> joined) in one DrawingML XML part.
function doc_xml_paragraphs(string $xml): array {
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) return []; // none expected in real files
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return [];

    $ns = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $paras = [];
    foreach ($dom->getElementsByTagNameNS($ns, 'p') as $p) {
        $text = '';
        foreach ($p->getElementsByTagNameNS($ns, 't') as $t) $text .= $t->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text !== '') $paras[] = $text;
    }
    return $paras;
}

// Readable text of a .pptx: each slide's text, then its speaker notes.
// Returns null if the data is not a PowerPoint file; '' if it has no text
// (e.g. image-only slides); otherwise "Slide N:" blocks, capped in size.
function doc_pptx_text(string $bytes): ?string {
    $parts = doc_zip_extract($bytes, fn($n) => (bool) preg_match('#^ppt/(slides/slide|notesSlides/notesSlide)\d+\.xml$#', $n));
    if ($parts === null) return null;

    $slides = []; $notes = [];
    foreach ($parts as $name => $xml) {
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) $slides[(int) $m[1]] = $xml;
        elseif (preg_match('#^ppt/notesSlides/notesSlide(\d+)\.xml$#', $name, $m)) $notes[(int) $m[1]] = $xml;
    }
    if (!$slides) return null; // a ZIP, but not a presentation
    ksort($slides);

    $out = '';
    foreach ($slides as $n => $xml) {
        $body = doc_xml_paragraphs($xml);
        $note = isset($notes[$n]) ? doc_xml_paragraphs($notes[$n]) : [];
        if (!$body && !$note) continue;
        $out .= "Slide $n:\n" . implode("\n", $body) . "\n";
        if ($note) $out .= "Speaker notes: " . implode(' ', $note) . "\n";
        $out .= "\n";
        if (mb_strlen($out) > DOC_TEXT_MAX_CHARS) break;
    }
    return trim(mb_substr($out, 0, DOC_TEXT_MAX_CHARS));
}
