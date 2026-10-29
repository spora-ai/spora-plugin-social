<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

/**
 * A `multipart/form-data` body, built by hand.
 *
 * Symfony's HTTP client sends an array body as `x-www-form-urlencoded`
 * and does not build multipart bodies itself, and Meta's photo endpoint
 * wants the file as a multipart part. So the body is assembled here, with
 * the boundary the header names.
 */
final class MultipartBody
{
    /**
     * @param array<string, string> $fields
     * @param array<string, array{filename: string, content: string, content_type: string}> $files
     */
    public static function build(string $boundary, array $fields, array $files): string
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($files as $name => $file) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$file['filename']}\"\r\n";
            $body .= "Content-Type: {$file['content_type']}\r\n\r\n";
            $body .= $file['content'] . "\r\n";
        }

        return $body . "--{$boundary}--\r\n";
    }

    /**
     * A boundary that cannot appear in the body it delimits.
     */
    public static function boundary(): string
    {
        return 'spora-' . bin2hex(random_bytes(16));
    }
}
