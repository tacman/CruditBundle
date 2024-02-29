<?php

namespace Lle\CruditBundle\Exporter;

use Lle\CruditBundle\Dto\Field\Field;
use Lle\CruditBundle\Dto\FieldView;
use Lle\CruditBundle\Dto\ResourceView;
use Lle\CruditBundle\Field\BooleanField;
use Lle\CruditBundle\Field\CurrencyField;
use Lle\CruditBundle\Field\IntegerField;
use Lle\CruditBundle\Field\NumberField;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Contracts\Translation\TranslatorInterface;

class PdfExporter extends AbstractExporter
{
    protected TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function getSupportedFormat(): string
    {
        return Exporter::PDF;
    }

    public function export(iterable $resources, ExportParams $params): Response
    {
        $spreadsheet = new Spreadsheet();
        $this->pageSetup($spreadsheet, $params);
        $sheet = $spreadsheet->getActiveSheet();
        $headersAdded = false;
        $row = 1;

        /** @var ResourceView $resource */
        foreach ($resources as $resource) {
            if ($params->getIncludeHeaders() && !$headersAdded) {
                $headers = $this->getHeaders($resource->getFields());
                foreach ($headers as $j => $header) {
                    $cell = Coordinate::stringFromColumnIndex($j + 1) . $row;
                    $sheet->setCellValue($cell, ' ' . $header);
                    $sheet->getStyle($cell)->getAlignment()->setHorizontal('center');
                    $sheet->getStyle($cell)->getAlignment()->setVertical('center');
                    $sheet->getStyle($cell)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'borders' => [
                            'bottom' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            ],
                            'left' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            ],
                            'right' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            ],
                            'top' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            ],
                        ],
                        'alignment' => [
                            'wrapText' => true,
                        ],
                    ]);
                }
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;
                $headersAdded = true;
            }

            /** @var FieldView $field */
            foreach ($resource->getFields() as $j => $field) {
                $cell = Coordinate::stringFromColumnIndex($j + 1) . $row;

                $sheet->setCellValueExplicit($cell, $this->getValue($field), $this->getType($field));
                $sheet->getStyle($cell)->getAlignment()->setVertical('center');

                $this->formatParticualarFieldType($sheet, $cell, $field);

                $sheet->getStyle($cell)->getAlignment()->setIndent(1);
                if ($row & 1) {
                    $sheet->getStyle($cell)->applyFromArray([
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_GRADIENT_LINEAR,
                            'rotation' => 90,
                            'startColor' => [
                                'argb' => 'E8E8E8',
                            ],
                            'endColor' => [
                                'argb' => 'E8E8E8',
                            ],
                        ],
                        'borders' => [

                        ],
                    ]);
                }
                $sheet->getStyle($cell)->applyFromArray([
                    'borders' => [
                        'left' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                        'right' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                    ],
                ]);
            }

            $row++;
        }

        foreach ($sheet->getColumnIterator("A", $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }

        $writer = new Mpdf($spreadsheet);
        $pdfParmas = $params->getPdfParams();
        if ($pdfParmas['header-footer']) {
            $writer->setEditHtmlCallback($this->getHeaderAndFooter($pdfParmas['header-footer']));
        }
        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/pdf');
        $filename = $params->getFilename() ?? "export";
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            "$filename.pdf"
        );
        $response->headers->set("Content-Disposition", $disposition);

        return $response;
    }

    protected function getHeaders(array $fields): array
    {
        $result = [];

        /** @var FieldView $field */
        foreach ($fields as $field) {
            $result[] = $this->translator->trans($field->getField()->getLabel());
        }

        return $result;
    }

    protected function getType(FieldView $field): string
    {
        if ($field->getRawValue() === null || $field->getRawValue() === "") {
            return DataType::TYPE_NULL;
        }

        return match ($field->getField()->getType()) {
            "bigint", "smallint", "float", "integer", "decimal", NumberField::class, IntegerField::class,
            => DataType::TYPE_NUMERIC,
            "boolean", BooleanField::class => DataType::TYPE_BOOL,
            default => DataType::TYPE_STRING,
        };
    }

    public function pageSetup(Spreadsheet &$spreadsheet, ExportParams $exportParams): void
    {
        $params = $exportParams->getPdfParams();
        $spreadsheet->getProperties()->setTitle($params['title']);
        $spreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
        $spreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);

        $spreadsheet->getActiveSheet()->getPageMargins()->setTop(0.8);
        $spreadsheet->getActiveSheet()->getPageMargins()->setRight(0.25);
        $spreadsheet->getActiveSheet()->getPageMargins()->setLeft(0.25);
        $spreadsheet->getActiveSheet()->getPageMargins()->setBottom(0.8);

        $spreadsheet->getActiveSheet()->getPageSetup()->setOrientation($params['orientation']);
        $spreadsheet->getActiveSheet()->getPageSetup()->setPaperSize($params['paper_size']);

        \PhpOffice\PhpSpreadsheet\Settings::setLocale($params['locale']);
        \PhpOffice\PhpSpreadsheet\Shared\StringHelper::setDecimalSeparator($params['decimal_separator']);
        \PhpOffice\PhpSpreadsheet\Shared\StringHelper::setThousandsSeparator($params['thousands_separator']);
    }

    public function getHeaderAndFooter(array $params): callable
    {
            return function (string $html) use ($params): string {
                $pagerepl = <<<EOF
                    @page page0 {
                    odd-header-name: html_myHeader1;
                    even-header-name: html_myHeader1;
                    odd-footer-name: html_myFooter2;
                    even-footer-name: html_myFooter2;                   
                EOF;
                $html = preg_replace('/@page page0 {/', $pagerepl, $html) ?? '';
                $bodystring = '/<body>/';
                $topLeft = $params['header-left'] ?? '';
                $topCenter = $params['header-center'] ?? '';
                $topRight = $params['header-right'] ?? '';
                $bottomLeft = $params['footer-left'] ?? '';
                $bottomCenter = $params['footer-center'] ?? '';
                $bottomRight = $params['footer-right'] ?? '';

                $bodyrepl = <<<EOF
                    <body style="font-family: 'Arial';">
                        <htmlpageheader name="myHeader1">
                            <table width="100%">
                                <tr>
                                    <td width="33%">$topLeft</td>
                                    <td width="33%" align="center">$topCenter</td>
                                    <td width="33%" style="text-align: right;">$topRight</td>
                                </tr>
                            </table>
                        </htmlpageheader>
                    
                        <htmlpagefooter name="myFooter2">
                            <table width="100%">
                                <tr>
                                    <td width="33%">$bottomLeft</td>
                                    <td width="33%" align="center">$bottomCenter</td>
                                    <td width="33%" style="text-align: right;">$bottomRight</td>
                                </tr>
                            </table>
                        </htmlpagefooter>
                    
                    EOF;

                return preg_replace($bodystring, $bodyrepl, $html) ?? '';
            };
    }

    public function getNumberDecimalFormat(Field $field): string
    {
        $options = $field->getOptions();
        $decimalNumber = 2;
        if (array_key_exists('decimals', $options)) {
            $decimalNumber = $options['decimals'];
        }

        return '#,##0.' . str_repeat('0', $decimalNumber);
    }

    protected function formatParticualarFieldType(Worksheet $sheet, string $cell, FieldView $field): void
    {
        if ($this->getType($field) === DataType::TYPE_NUMERIC) {
            $sheet->getStyle($cell)->getNumberFormat()
                ->setFormatCode($this->getNumberDecimalFormat($field->getField()));
        }
        if ($field->getField()->getType() === CurrencyField::class) {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();

            $currency = '';
            if ($field->getOptions()['property'] && $field->getResource()) {
                $path = explode('.', $field->getOptions()['property']);
                $object = $field->getResource();
                foreach ($path as $property) {
                    $object = $propertyAccessor->getValue($object, $property);
                }
                $currency = $object;
            } elseif ($field->getOptions()['currency']) {
                $currency = $field->getOptions()['currency'];
            }
            $formatter = \NumberFormatter::create($field->getOptions()['locale'], \NumberFormatter::CURRENCY);
            $result = numfmt_format_currency($formatter, $field->getRawValue(), $currency);
            $sheet->setCellValueExplicit($cell, $result, $this->getType($field));
            $sheet->getStyle($cell)->getAlignment()->setHorizontal('right');
        }

        if ($field->getField()->getType() === 'currency') {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();

            $currency = '';
            if ($field->getOptions()['property'] && $field->getResource()) {
                $path = explode('.', $field->getOptions()['property']);
                $object = $field->getResource();
                foreach ($path as $property) {
                    $object = $propertyAccessor->getValue($object, $property);
                }
                $currency = $object;
            } elseif ($field->getOptions()['currency']) {
                $currency = $field->getOptions()['currency'];
            }
            $formatter = \NumberFormatter::create($field->getOptions()['locale'], \NumberFormatter::CURRENCY);
            $result = numfmt_format_currency($formatter, (float)trim($field->getValue() ?? ''), $currency);
            $sheet->setCellValueExplicit($cell, $result, $this->getType($field));
            $sheet->getStyle($cell)->getAlignment()->setHorizontal('right');
        }
        if ($sheet->getStyle($cell)->getAlignment()->getHorizontal() === 'general') {
            $sheet->getStyle($cell)->getAlignment()->setHorizontal('left')->setIndent(1);
        }
    }
}
