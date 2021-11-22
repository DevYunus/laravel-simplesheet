<?php

namespace Nikazooz\Simplesheet;

use Nikazooz\Simplesheet\Concerns\WithEvents;
use Nikazooz\Simplesheet\Concerns\WithMultipleSheets;
use Nikazooz\Simplesheet\Events\BeforeExport;
use Nikazooz\Simplesheet\Events\BeforeWriting;
use Nikazooz\Simplesheet\Factories\WriterFactory;
use Nikazooz\Simplesheet\Files\RemoteTemporaryFile;
use Nikazooz\Simplesheet\Files\TemporaryFile;
use Nikazooz\Simplesheet\Files\TemporaryFileFactory;
use Nikazooz\Simplesheet\Writers\Sheet;
use Box\Spout\Writer\Common\Creator\WriterFactory as SpoutWriterFactory;

class Writer
{
    use HasEventBus;

    /**
     * @var \Nikazooz\Simplesheet\Files\TemporaryFileFactory
     */
    protected $temporaryFileFactory;

    /**
     * @var \Box\Spout\Writer\WriterInterface
     */
    protected $spoutWriter;

    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * New Writer instance.
     *
     * @param  \Nikazooz\Simplesheet\Files\TemporaryFileFactory  $temporaryFileFactory
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(TemporaryFileFactory $temporaryFileFactory, int $chunkSize)
    {
        $this->chunkSize = $chunkSize;
        $this->temporaryFileFactory = $temporaryFileFactory;
    }

    /**
     * @param object $export
     * @param string $writerType
     * @param TemporaryFile|null $temporaryFile
     * @return TemporaryFile
     * @throws \Exception
     */
    public function export($export, string $writerType, TemporaryFile $temporaryFile = null): TemporaryFile
    {
        $this->open($export, $writerType);

        $sheetExports = [$export];
        if ($export instanceof WithMultipleSheets) {
            $sheetExports = $export->sheets();
        }

        foreach ($sheetExports as $sheetIndex => $sheetExport) {
            $this->addNewSheet($sheetIndex)->export($sheetExport);
        }

        $temporaryFile = $temporaryFile ?? $this->temporaryFileFactory->makeLocal();

        return $this->write($export, $temporaryFile, $writerType);
    }

    /**
     * @param  object  $export
     * @param  string  $writerType
     * @return $this
     */
    public function open($export, $writerType)
    {
        if ($export instanceof WithEvents) {
            $this->registerListeners($export->registerEvents());
        }

        $this->raise(new BeforeExport($this, $export));

        $this->spoutWriter = WriterFactory::make($writerType, $export);

        return $this;
    }

    /**
     * @param TemporaryFile $tempFile
     * @param string $writerType
     *
     * @return Writer
     * @throws \Box\Spout\Common\Exception\IOException
     */
    public function reopen(TemporaryFile $tempFile, string $writerType)
    {
        $this->spoutWriter = SpoutWriterFactory::createFromType($writerType);
        $this->spoutWriter->openToFile($tempFile->sync()->getLocalPath());

        return $this;
    }

    /**
     * @param  object  $export
     * @param  \Nikazooz\Simplesheet\Files\TemporaryFile  $temporaryFile
     * @param  string  $writerType
     * @return \Nikazooz\Simplesheet\Files\TemporaryFile
     */
    public function write($export, TemporaryFile $temporaryFile, string $writerType)
    {
        $this->throwExceptionIfWriterIsNotSet();

        $this->raise(new BeforeWriting($this, $export));

        $this->spoutWriter->openToFile($temporaryFile->getLocalPath());

        foreach ($this->getSheetExports($export) as $sheetIndex => $sheetExport) {
            $this->addNewSheet($sheetIndex)->export($sheetExport);
        }

        $this->cleanUp();

        if ($temporaryFile instanceof RemoteTemporaryFile) {
            $temporaryFile->updateRemote();
        }

        return $temporaryFile;
    }

    /**
     * @param  \Nikazooz\Simplesheet\Concerns\WithMultipleSheets|object  $export
     * @return array
     */
    private function getSheetExports($export)
    {
        if ($export instanceof WithMultipleSheets) {
            return $export->sheets();
        }

        return [$export];
    }

    /**
     * @return void
     */
    private function cleanUp()
    {
        $this->spoutWriter->close();
        unset($this->spoutWriter);
    }

    /**
     * @param  int|null  $sheetIndex
     * @return \Nikazooz\Simplesheet\Writers\Sheet
     *
     * @throws \Exception
     */
    public function addNewSheet(int $sheetIndex = null)
    {
        $this->throwExceptionIfWriterIsNotSet();

        return new Sheet($this->spoutWriter, $sheetIndex, $this->chunkSize);
    }

    /**
     * @param int $sheetIndex
     *
     * @return Sheet
     */
    public function getSheetByIndex(int $sheetIndex)
    {
        return new Sheet($this->spoutWriter, $sheetIndex, $this->chunkSize);
    }

    /**
     * @throws \Exception
     */
    private function throwExceptionIfWriterIsNotSet()
    {
        if (! $this->spoutWriter) {
            throw new \Exception('Writer must be opened first!');
        }
    }
}
