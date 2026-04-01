<?php

namespace Src\Kernel\Support;

/**
 * Persists sent email history to storage/email_history.json
 */
class EmailHistory
{
    private string $file;

    public function __construct(string $storageDir)
    {
        $this->file = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'email_history.json';
    }

    public function save(array $entry): array
    {
        $history = $this->all();
        $entry['id'] = $this->generateId();
        $entry['created_at'] = date('c');
        array_unshift($history, $entry); // newest first
        // Keep max 500 entries
        if (count($history) > 500) {
            $history = array_slice($history, 0, 500);
        }
        file_put_contents($this->file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $entry;
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['id'] ?? '') === $id) {
                return $entry;
            }
        }
        return null;
    }

    public function update(string $id, array $fields): bool
    {
        $history = $this->all();
        foreach ($history as &$entry) {
            if (($entry['id'] ?? '') === $id) {
                $entry = array_merge($entry, $fields, ['id' => $id]);
                file_put_contents($this->file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return true;
            }
        }
        return false;
    }

    public function delete(string $id): bool
    {
        $history = $this->all();
        $filtered = array_values(array_filter($history, fn($e) => ($e['id'] ?? '') !== $id));
        if (count($filtered) === count($history)) {
            return false;
        }
        file_put_contents($this->file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return true;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
