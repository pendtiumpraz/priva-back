<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class DocumentParserService
{
    /**
     * Parse an uploaded document and extract structured data.
     *
     * @return array ['sections' => [...], 'tables' => [...], 'raw_text' => '...', 'metadata' => [...]]
     */
    public function parse(string $filePath, string $fileType): array
    {
        return match (strtolower($fileType)) {
            'docx' => $this->parseDocx($filePath),
            'xlsx', 'xls' => $this->parseXlsx($filePath),
            'csv' => $this->parseCsv($filePath),
            'pdf' => $this->parsePdf($filePath),
            default => throw new \InvalidArgumentException("Tipe file '{$fileType}' belum didukung."),
        };
    }

    /**
     * Parse a DOCX file using PhpWord.
     */
    private function parseDocx(string $filePath): array
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);

        $sections = [];
        $tables = [];
        $rawText = '';
        $currentSection = ['title' => 'Main', 'content' => ''];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    $text = $this->extractTextRunContent($element);
                    $currentSection['content'] .= $text . "\n";
                    $rawText .= $text . "\n";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                    $text = $element->getText();
                    $currentSection['content'] .= $text . "\n";
                    $rawText .= $text . "\n";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Title) {
                    // Save current and start new section
                    if (trim($currentSection['content'])) {
                        $sections[] = $currentSection;
                    }
                    $titleText = $element->getText();
                    if ($titleText instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        $titleText = $this->extractTextRunContent($titleText);
                    }
                    $currentSection = ['title' => $titleText, 'content' => ''];
                    $rawText .= "\n### {$titleText}\n";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    $tableData = $this->extractTable($element);
                    $tables[] = $tableData;
                    // Also append table to raw text
                    foreach ($tableData as $row) {
                        $rawText .= implode(' | ', $row) . "\n";
                    }
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItemRun) {
                    $text = $this->extractTextRunContent($element);
                    $currentSection['content'] .= "• {$text}\n";
                    $rawText .= "• {$text}\n";
                }
            }
        }

        // Save last section
        if (trim($currentSection['content'])) {
            $sections[] = $currentSection;
        }

        return [
            'sections' => $sections,
            'tables' => $tables,
            'raw_text' => trim($rawText),
            'metadata' => [
                'format' => 'docx',
                'section_count' => count($sections),
                'table_count' => count($tables),
                'character_count' => mb_strlen($rawText),
            ],
        ];
    }

    /**
     * Parse an XLSX file using PhpSpreadsheet.
     */
    private function parseXlsx(string $filePath): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);

        $sections = [];
        $tables = [];
        $rawText = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetName = $sheet->getTitle();
            $rows = [];
            $headers = [];
            $sheetText = '';

            foreach ($sheet->getRowIterator() as $rowIdx => $row) {
                $cellValues = [];
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                foreach ($cellIterator as $cell) {
                    $cellValues[] = trim((string) $cell->getValue());
                }

                // Skip completely empty rows
                if (implode('', $cellValues) === '') continue;

                if ($rowIdx === 1) {
                    $headers = $cellValues;
                } else {
                    $rows[] = $cellValues;
                }

                $sheetText .= implode(' | ', $cellValues) . "\n";
            }

            // Build key-value pairs if headers exist
            $keyValuePairs = [];
            if ($headers) {
                foreach ($rows as $row) {
                    $pair = [];
                    foreach ($headers as $i => $header) {
                        if ($header) {
                            $pair[$header] = $row[$i] ?? '';
                        }
                    }
                    $keyValuePairs[] = $pair;
                }
            }

            $sections[] = [
                'title' => $sheetName,
                'content' => $sheetText,
                'headers' => $headers,
                'key_value_pairs' => $keyValuePairs,
            ];

            $tables[] = array_merge([$headers], $rows);
            $rawText .= "=== Sheet: {$sheetName} ===\n{$sheetText}\n";
        }

        return [
            'sections' => $sections,
            'tables' => $tables,
            'raw_text' => trim($rawText),
            'metadata' => [
                'format' => 'xlsx',
                'sheet_count' => count($sections),
                'table_count' => count($tables),
                'character_count' => mb_strlen($rawText),
            ],
        ];
    }

    /**
     * Parse a CSV file.
     */
    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle);
        $rows = [];
        $rawText = implode(' | ', $headers) . "\n";

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
            $rawText .= implode(' | ', $row) . "\n";
        }
        fclose($handle);

        // Build key-value pairs
        $keyValuePairs = [];
        foreach ($rows as $row) {
            $pair = [];
            foreach ($headers as $i => $header) {
                $pair[$header] = $row[$i] ?? '';
            }
            $keyValuePairs[] = $pair;
        }

        return [
            'sections' => [[
                'title' => basename($filePath),
                'content' => $rawText,
                'headers' => $headers,
                'key_value_pairs' => $keyValuePairs,
            ]],
            'tables' => [array_merge([$headers], $rows)],
            'raw_text' => trim($rawText),
            'metadata' => [
                'format' => 'csv',
                'row_count' => count($rows),
                'column_count' => count($headers),
                'character_count' => mb_strlen($rawText),
            ],
        ];
    }

    /**
     * Parse a PDF file using smalot/pdfparser.
     */
    private function parsePdf(string $filePath): array
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($filePath);

        $sections = [];
        $rawText = '';
        $pages = $pdf->getPages();

        foreach ($pages as $idx => $page) {
            $pageText = $page->getText();
            $pageNum = $idx + 1;

            if (trim($pageText)) {
                $sections[] = [
                    'title' => "Halaman {$pageNum}",
                    'content' => trim($pageText),
                ];
                $rawText .= "=== Halaman {$pageNum} ===\n{$pageText}\n\n";
            }
        }

        // Also try to extract document metadata
        $pdfMeta = [];
        try {
            $details = $pdf->getDetails();
            $pdfMeta = [
                'title' => $details['Title'] ?? null,
                'author' => $details['Author'] ?? null,
                'creator' => $details['Creator'] ?? null,
                'pages' => $details['Pages'] ?? count($pages),
            ];
        } catch (\Exception $e) {}

        return [
            'sections' => $sections,
            'tables' => [], // PDF table extraction is limited without OCR
            'raw_text' => trim($rawText),
            'metadata' => [
                'format' => 'pdf',
                'page_count' => count($pages),
                'section_count' => count($sections),
                'character_count' => mb_strlen($rawText),
                'pdf_metadata' => $pdfMeta,
            ],
        ];
    }

    // ========= Helpers =========

    private function extractTextRunContent($textRun): string
    {
        $text = '';
        foreach ($textRun->getElements() as $el) {
            if (method_exists($el, 'getText')) {
                $text .= $el->getText();
            }
        }
        return $text;
    }

    private function extractTable(\PhpOffice\PhpWord\Element\Table $table): array
    {
        $data = [];
        foreach ($table->getRows() as $row) {
            $rowData = [];
            foreach ($row->getCells() as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $el) {
                    if ($el instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        $cellText .= $this->extractTextRunContent($el);
                    } elseif ($el instanceof \PhpOffice\PhpWord\Element\Text) {
                        $cellText .= $el->getText();
                    }
                }
                $rowData[] = trim($cellText);
            }
            $data[] = $rowData;
        }
        return $data;
    }
}
