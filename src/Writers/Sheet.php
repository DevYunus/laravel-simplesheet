<?php

namespace Nikazooz\Simplesheet\Writers;

use Box\Spout\Common\Entity\Cell;
use Box\Spout\Common\Entity\Row;
use Illuminate\Support\Facades\Log;
use Box\Spout\Writer\WriterInterface;
use Box\Spout\Writer\WriterMultiSheetsAbstract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Nikazooz\Simplesheet\Concerns\FromArray;
use Nikazooz\Simplesheet\Concerns\FromCollection;
use Nikazooz\Simplesheet\Concerns\FromIterator;
use Nikazooz\Simplesheet\Concerns\FromQuery;
use Nikazooz\Simplesheet\Concerns\WithCustomChunkSize;
use Nikazooz\Simplesheet\Concerns\WithEvents;
use Nikazooz\Simplesheet\Concerns\WithHeadings;
use Nikazooz\Simplesheet\Concerns\WithMapping;
use Nikazooz\Simplesheet\Concerns\WithTitle;
use Nikazooz\Simplesheet\Events\AfterSheet;
use Nikazooz\Simplesheet\Events\BeforeSheet;
use Nikazooz\Simplesheet\HasEventBus;
use Nikazooz\Simplesheet\Helpers\ArrayHelper;

class Sheet
{
    use HasEventBus;

    /**
     * @var \Box\Spout\Writer\WriterInterface
     */
    protected $spoutWriter;

    /**
     * @var int
     */
    protected $index;

    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @var object
     */
    protected $exportable;

    /**
     * New Sheet.
     *
     * @param  \Box\Spout\Writer\WriterInterface  $spoutWriter
     * @param  int  $index
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(WriterInterface $spoutWriter, int $index, int $chunkSize)
    {
        $this->spoutWriter = $spoutWriter;
        $this->index = $index;
        $this->chunkSize = $chunkSize;
    }

    /**
     * @param object $sheetExport
     */
    public function open($sheetExport)
    {
        $this->exportable = $sheetExport;

        if ($sheetExport instanceof WithEvents) {
            $this->registerListeners($sheetExport->registerEvents());
        }

        $this->raise(new BeforeSheet($this, $this->exportable));

        // CSV files don't support multiple sheets, we can only write the first one.
        if ($this->multipleSheetsAreNotSupported() && $this->isNotTheFirstOne()) {
            return;
        }

        $this->setSheetAsActive();

        if ($sheetExport instanceof WithTitle) {
            $this->setSheetTitle($sheetExport);
        }

        if ($sheetExport instanceof WithHeadings && $sheetExport->headings()) {
            $headings = ArrayHelper::ensureMultipleRows($sheetExport->headings());

            foreach ($headings as $heading) {
                $this->appendRow($heading);
            }
        }
    }

    /**
     * @param  object  $sheetExport
     */
    public function export($sheetExport)
    {
        $this->open($sheetExport);

        if ($sheetExport instanceof FromQuery) {
            $this->fromQuery($sheetExport);
        }

        if ($sheetExport instanceof FromCollection) {
            $this->fromCollection($sheetExport);
        }

        if ($sheetExport instanceof FromArray) {
            $this->fromArray($sheetExport);
        }

        if ($sheetExport instanceof FromIterator) {
            $this->fromIterator($sheetExport);
        }

        $this->close($sheetExport);
    }

    /**
     * @param object $sheetExport
     */
    public function close($sheetExport)
    {
        $this->exportable = $sheetExport;

        $this->raise(new AfterSheet($this, $this->exportable));
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\FromQuery  $sheetExport
     * @return void
     */
    public function fromQuery(FromQuery $sheetExport)
    {
        $sheetExport->query()->chunk($this->getChunkSize($sheetExport), function ($chunk) use ($sheetExport) {
            $this->appendRows($chunk, $sheetExport);
        });
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\FromCollection  $sheetExport
     * @return void
     */
    public function fromCollection(FromCollection $sheetExport)
    {
        $this->appendRows($sheetExport->collection()->all(), $sheetExport);
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\FromArray  $sheetExport
     * @return void
     */
    public function fromArray(FromArray $sheetExport)
    {
        $this->appendRows($sheetExport->array(), $sheetExport);
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\FromIterator  $sheetExport
     * @return void
     */
    public function fromIterator(FromIterator $sheetExport)
    {
        $this->appendRows($sheetExport->iterator(), $sheetExport);
    }

    /**
     * @param string $concern
     *
     * @return string
     */
    public function hasConcern(string $concern): string
    {
        return $this->exportable instanceof $concern;
    }

    /**
     * @param  iterable  $rows
     * @param  object  $sheetExport
     * @return void
     */
    public function appendRows($rows, $sheetExport)
    {
        $rows = (new Collection($rows))->flatMap(function ($row) use ($sheetExport) {
            if ($sheetExport instanceof WithMapping) {
                $row = $sheetExport->map($row);
            }

            return ArrayHelper::ensureMultipleRows(
                static::mapArraybleRow($row)
            );
        })->toArray();

        foreach ($rows as $row) {
            $this->appendRow($row);
        }
    }

    public function getRowsToAppend($rows, $sheetExport): array
    {
        $rows = (new Collection($rows))->flatMap(function ($row) use ($sheetExport) {
            if ($sheetExport instanceof WithMapping) {
                $row = $sheetExport->map($row);
            }

            return ArrayHelper::ensureMultipleRows(
                static::mapArraybleRow($row)
            );
        })->toArray();

        $rowsToAppend = [];
        foreach ($rows as $row) {
            $rowsToAppend[] = $this->getRowToAppend($row);
        }

        return $rowsToAppend;
    }

    public function getRowToAppend($row): Row
    {
        $cells = array_map(function ($value) {
            return new Cell($value);
        }, $row);

        return new Row($cells, null);
    }

    /**
     * @param  mixed  $row
     * @return array
     */
    public static function mapArraybleRow($row)
    {
        // When dealing with eloquent models, we'll skip the relations
        // as we won't be able to display them anyway.
        if (is_object($row) && method_exists($row, 'attributesToArray')) {
            return $row->attributesToArray();
        }

        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        // Convert objects to arrays
        if (is_object($row)) {
            return json_decode(json_encode($row), true);
        }

        return $row;
    }

    /**
     * Append row to the spreadsheet.
     *
     * @param  array  $row
     * @return void
     */
    public function appendRow($row)
    {
        $cells = array_map(function ($value) {
            return new Cell($value);
        }, $row);

        $this->spoutWriter->addRow(new Row($cells, null));
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\WithCustomChunkSize|object  $export
     * @return int
     */
    protected function getChunkSize($export)
    {
        if ($export instanceof WithCustomChunkSize) {
            return $export->chunkSize();
        }

        return $this->chunkSize;
    }

    /**
     * @param  int  $chunkSize
     * @return \Nikazooz\Simplesheet\Sheet
     */
    public function chunkSize(int $chunkSize)
    {
        $this->chunkSize = $chunkSize;

        return $this;
    }

    /**
     * @return bool
     */
    protected function multipleSheetsAreNotSupported()
    {
        return ! $this->multipleSheetsAreSupported();
    }

    /**
     * @return bool
     */
    protected function multipleSheetsAreSupported()
    {
        return $this->spoutWriter instanceof WriterMultiSheetsAbstract;
    }

    /**
     * @return bool
     */
    protected function isNotTheFirstOne()
    {
        return $this->index > 0;
    }

    /**
     * @return void
     */
    public function setSheetAsActive()
    {
        // If we're working with format that doesn't support multiple sheets,
        // (like CSV), we only have one sheet and it is already active.
        if ($this->multipleSheetsAreSupported()) {
            $this->spoutWriter->setCurrentSheet($this->getSpoutSheet());
        }
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\WithTitle  $sheetExport
     * @return void
     */
    protected function setSheetTitle(WithTitle $sheetExport)
    {
        if ($this->multipleSheetsAreSupported()) {
            $this->spoutWriter->getCurrentSheet()->setName($sheetExport->title());
        }
    }

    /**
     * @return void
     */
    protected function ensureSheetExists()
    {
        $desiredSheetCount = $this->index + 1;
        $sheetCount = count($this->spoutWriter->getSheets());

        while ($sheetCount <= $desiredSheetCount) {
            $this->spoutWriter->addNewSheetAndMakeItCurrent();

            $sheetCount++;
        }
    }

    /**
     * @return \Box\Spout\Writer\Common\Sheet
     */
    protected function getSpoutSheet()
    {
        // If we don't have as much sheets as we need, we make new ones.
        $this->ensureSheetExists();

        $sheets = $this->spoutWriter->getSheets();

        return $sheets[$this->index];
    }
}
