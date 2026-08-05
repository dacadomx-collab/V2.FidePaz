<?php
declare(strict_types=1);

/**
 * Generador mínimo de .xlsx real (no CSV con extensión falsa) usando
 * `ZipArchive` -- extensión núcleo de PHP, ya confirmada disponible en el
 * hosting (sin agregar Composer/PhpSpreadsheet, que no existe en este
 * proyecto). Un .xlsx es un ZIP con un subconjunto fijo de XML (OOXML
 * SpreadsheetML); esta clase escribe solo lo mínimo necesario para que
 * Excel/LibreOffice/Google Sheets lo abran sin advertencias: una sola
 * hoja, celdas de texto con "inline strings" (evita tener que mantener un
 * `sharedStrings.xml` aparte).
 */
final class Xlsx
{
    /**
     * @param array<int, array<int, string>> $rows Primera fila = encabezados.
     */
    public static function build(array $rows): string
    {
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $sheetXml .= '<row r="' . ($rowIndex + 1) . '">';
            foreach ($row as $colIndex => $value) {
                $ref = self::columnLetter($colIndex) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $tmpPath = tempnam(sys_get_temp_dir(), 'fidepaz_xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $binary = file_get_contents($tmpPath);
        unlink($tmpPath);

        return $binary;
    }

    /** Convierte un índice de columna base-0 a letra de columna Excel (0->A, 26->AA, ...). */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        do {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letter;
    }

    public static function send(string $binary, string $filename): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($binary));
        }
        echo $binary;
        exit;
    }
}
