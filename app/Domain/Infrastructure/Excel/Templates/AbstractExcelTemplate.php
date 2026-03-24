<?php

namespace App\Domain\Infrastructure\Excel\Templates;

use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

abstract class AbstractExcelTemplate
{
    protected Model $model;
    protected CompanySettings $companySettings;
    protected array $options = [];

    public function __construct(Model $model, array $options = [])
    {
        $this->model = $model;
        $this->options = $options;
        $this->companySettings = app(CompanySettings::class);
    }

    abstract public function getDocumentTitle(): string;

    abstract public function getDocumentType(): string;

    abstract protected function getHeaders(): array;

    abstract protected function getRows(): array;

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return $reference . '.xlsx';
    }

    public function generate(): string
    {
        $filename = $this->getFilename();
        $path = storage_path('app/temp/' . $filename);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Writer();
        $writer->openToFile($path);

        // Title row
        $titleStyle = (new Style())
            ->setFontBold()
            ->setFontSize(14);
        $writer->addRow(new Row(
            [Cell::fromValue($this->getDocumentTitle())],
            $titleStyle,
        ));

        // Company info row
        $writer->addRow(new Row(
            [Cell::fromValue($this->companySettings->company_name ?? '')],
            (new Style())->setFontSize(10)->setFontColor('808080'),
        ));

        // Empty row
        $writer->addRow(new Row([Cell::fromValue('')]));

        // Header row
        $headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('4472C4')
            ->setFontColor(Color::WHITE);

        $writer->addRow(new Row(
            array_map(fn ($h) => Cell::fromValue($h), $this->getHeaders()),
            $headerStyle,
        ));

        // Data rows
        $alternateStyle = (new Style())->setBackgroundColor('F2F7FC');

        foreach ($this->getRows() as $index => $rowData) {
            $style = $index % 2 === 1 ? $alternateStyle : null;
            $writer->addRow(new Row(
                array_map(fn ($v) => Cell::fromValue($v ?? ''), $rowData),
                $style,
            ));
        }

        $writer->close();

        return $path;
    }
}
