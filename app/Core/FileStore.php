<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;

/**
 * Encrypted file storage outside the web root. Each file is encrypted with AES-256-GCM
 * (associated data binds it to its random storage key), its name is encrypted in the
 * database, and a SHA-256 of the plaintext is checked on every read.
 */
final class FileStore
{
    public const SCANNED_RECORD_TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/tiff' => ['tif', 'tiff'],
    ];

    public const SUPPORTING_DOCUMENT_TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    ];

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** @return array{id:int,name:string,mime_type:string,size_bytes:int} */
    public static function store(?array $upload, array $allowedTypes, int $maxBytes, int $userId): array
    {
        if ($upload === null || !isset($upload['error']) || is_array($upload['error'])) {
            throw HttpException::validation(['file' => 'Choose one file to upload.']);
        }
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw HttpException::validation(['file' => 'The file is too large.']);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            throw HttpException::validation(['file' => 'The upload failed. Please try again.']);
        }
        $size = (int) filesize($upload['tmp_name']);
        if ($size <= 0) {
            throw HttpException::validation(['file' => 'The file is empty.']);
        }
        if ($size > $maxBytes) {
            throw HttpException::validation(['file' => sprintf('The file is too large (maximum %d MB).', intdiv($maxBytes, 1048576))]);
        }

        $name = self::cleanName((string) $upload['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($extension === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $mime = self::DOCX; // older magic databases report .docx as a zip archive
        }
        if (!isset($allowedTypes[$mime]) || !in_array($extension, $allowedTypes[$mime], true)) {
            $allowed = implode(', ', array_map('strtoupper', array_unique(array_merge(...array_values($allowedTypes)))));
            throw HttpException::validation(['file' => "This type of file is not accepted. Allowed: $allowed."]);
        }

        $content = (string) file_get_contents($upload['tmp_name']);
        $key = bin2hex(random_bytes(32));
        $path = self::pathFor($key);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create storage directory.');
        }
        if (file_put_contents($path, Crypto::encrypt($content, 'file:' . $key), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write encrypted file.');
        }

        try {
            $id = Database::insert(
                'INSERT INTO stored_files (storage_key, original_name_enc, mime_type, size_bytes, sha256, uploaded_at, uploaded_by)
                 VALUES (:k, :n, :m, :s, :h, UTC_TIMESTAMP(3), :by)',
                ['k' => $key, 'n' => Crypto::encrypt($name, 'stored_files.original_name'), 'm' => $mime, 's' => $size, 'h' => hash('sha256', $content, true), 'by' => $userId]
            );
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        return ['id' => $id, 'name' => $name, 'mime_type' => $mime, 'size_bytes' => $size];
    }

    /** @return array{content:string,name:string,mime_type:string,size_bytes:int} */
    public static function read(int $fileId): array
    {
        $row = Database::one('SELECT storage_key, original_name_enc, mime_type, size_bytes, sha256 FROM stored_files WHERE id = :id', ['id' => $fileId])
            ?? throw HttpException::notFound('File not found.');
        $path = self::pathFor($row['storage_key']);
        if (!is_file($path)) {
            throw new \RuntimeException("Encrypted file missing for stored_files.id=$fileId");
        }
        $content = (string) Crypto::decrypt((string) file_get_contents($path), 'file:' . $row['storage_key']);
        if (!hash_equals($row['sha256'], hash('sha256', $content, true))) {
            throw new \RuntimeException("Checksum mismatch for stored_files.id=$fileId");
        }
        return [
            'content' => $content,
            'name' => (string) Crypto::decrypt($row['original_name_enc'], 'stored_files.original_name'),
            'mime_type' => $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
        ];
    }

    public static function decryptName(string $ciphertext): string
    {
        return (string) Crypto::decrypt($ciphertext, 'stored_files.original_name');
    }

    private static function pathFor(string $key): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            throw new \RuntimeException('Invalid storage key.');
        }
        return Config::path('storage') . '/files/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key . '.bin';
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        return mb_substr(trim($name), 0, 150) ?: 'file';
    }
}
