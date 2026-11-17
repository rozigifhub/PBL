<?php

/**
 * Penulis .xlsx minimal tanpa dependensi (tanpa ext-zip / PhpSpreadsheet; hemat memori untuk VPS kecil).
 *
 * xlsx_kirim("laporan.xlsx", ["Penjualan" => [["Tanggal", "Omzet"], ["2026-11-01", 150000]], ...]);
 * Baris pertama tiap sheet dicetak tebal. Angka (int/float) disimpan sebagai angka, lainnya sebagai teks.
 */

function xlsx_kolom(int $i): string
{
    $s = "";
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_teks(string $t): string
{
    $t = preg_replace("/[^\x09\x0A\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u", "", $t) ?? "";
    return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

function xlsx_sheet_xml(array $baris): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_values($baris) as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $nilai) {
            $ref   = xlsx_kolom($c) . ($r + 1);
            $gaya  = $r === 0 ? ' s="1"' : "";
            if ($nilai === null || $nilai === "") {
                continue;
            }
            if (is_int($nilai) || is_float($nilai)) {
                $xml .= '<c r="' . $ref . '"' . $gaya . '><v>' . $nilai . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $gaya . '><is><t xml:space="preserve">'
                      . xlsx_teks((string)$nilai) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

/** Susun isi file .xlsx (ZIP tanpa kompresi) dan kembalikan sebagai string. */
function xlsx_bangun(array $sheets): string
{
    $nama = array_keys($sheets);
    $n    = count($nama);

    $tipe = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
          . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rel  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    $berkas = [];
    for ($i = 0; $i < $n; $i++) {
        $no    = $i + 1;
        $judul = mb_substr(preg_replace("#[\\[\\]:*?/]#", " ", (string)$nama[$i]) ?? "Sheet", 0, 31);
        $tipe .= '<Override PartName="/xl/worksheets/sheet' . $no . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb   .= '<sheet name="' . xlsx_teks($judul) . '" sheetId="' . $no . '" r:id="rId' . $no . '"/>';
        $rel  .= '<Relationship Id="rId' . $no . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $no . '.xml"/>';
        $berkas["xl/worksheets/sheet{$no}.xml"] = xlsx_sheet_xml($sheets[$nama[$i]]);
    }
    $rel .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $berkas["[Content_Types].xml"] = $tipe . '</Types>';
    $berkas["_rels/.rels"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $berkas["xl/workbook.xml"] = $wb . '</sheets></workbook>';
    $berkas["xl/_rels/workbook.xml.rels"] = $rel;
    $berkas["xl/styles.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>';

    $berkas = ["[Content_Types].xml" => $berkas["[Content_Types].xml"]] + $berkas;   // Excel mengharapkan ini di depan
    $zip = "";
    $pusat = "";
    $jumlah = 0;
    $waktu = pack("v", 0);
    $tanggal = pack("v", 33);   // 1980-01-01
    foreach ($berkas as $path => $isi) {
        $crc  = pack("V", crc32($isi));
        $len  = pack("V", strlen($isi));
        $nm   = pack("v", strlen($path));
        $off  = pack("V", strlen($zip));
        $zip .= "PK" . pack("v", 0x0403) . pack("v", 20) . pack("v", 0) . pack("v", 0)
              . $waktu . $tanggal . $crc . $len . $len . $nm . pack("v", 0) . $path . $isi;
        $pusat .= "PK" . pack("v", 0x0201) . pack("v", 20) . pack("v", 20) . pack("v", 0) . pack("v", 0)
                . $waktu . $tanggal . $crc . $len . $len . $nm . pack("v", 0) . pack("v", 0)
                . pack("v", 0) . pack("v", 0) . pack("V", 0) . $off . $path;
        $jumlah++;
    }
    $akhir = "PK" . pack("v", 0x0605) . pack("v", 0) . pack("v", 0) . pack("v", $jumlah) . pack("v", $jumlah)
           . pack("V", strlen($pusat)) . pack("V", strlen($zip)) . pack("v", 0);
    return $zip . $pusat . $akhir;
}

/** Kirim sebagai unduhan .xlsx lalu hentikan skrip. */
function xlsx_kirim(string $namaFile, array $sheets): never
{
    $data = xlsx_bangun($sheets);
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header("Content-Disposition: attachment; filename=\"" . preg_replace("/[^A-Za-z0-9_.-]/", "_", $namaFile) . "\"");
    header("Content-Length: " . strlen($data));
    header("Cache-Control: no-store");
    echo $data;
    exit;
}
