<?php

namespace App\Services;

class MessageImportService
{
    public function __construct(private MessageIngestService $ingest) {}

    /**
     * Read a JSON file (as produced by MessageExportService) and replay its
     * events through the ingest pipeline.
     *
     * @return array{created:int, updated:int, deleted:int, skipped:int}
     */
    public function importFromFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Could not decode JSON in {$path}");
        }
        if (empty($data['source']) || !isset($data['events']) || !is_array($data['events'])) {
            throw new \RuntimeException("Missing 'source' or 'events' in {$path}");
        }
        return $this->ingest->ingestBatch($data['source'], $data['events']);
    }
}
