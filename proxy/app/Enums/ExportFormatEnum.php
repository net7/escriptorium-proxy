<?php

namespace App\Enums;

enum ExportFormatEnum: string
{
    case TeiXml = 'teixml';
    case Text = 'text';
    case PageXml = 'pagexml';
    case Alto = 'alto';
    case OpenItiMarkdown = 'openitimarkdown';

    public function getLabel(): string
    {
        return match ($this) {
            self::TeiXml => 'TEI XML',
            self::Text => 'Plain Text',
            self::PageXml => 'PAGE XML',
            self::Alto => 'ALTO XML',
            self::OpenItiMarkdown => 'OpenITI mARkdown',
        };
    }

    /**
     * Whether this format produces a ZIP file.
     * Text format produces a plain .txt file.
     */
    public function isZip(): bool
    {
        return match ($this) {
            self::Text => false,
            default => true,
        };
    }

    /**
     * Whether the text field should be populated with content.
     */
    public function hasTextContent(): bool
    {
        return match ($this) {
            self::TeiXml, self::Text => true,
            self::PageXml, self::Alto, self::OpenItiMarkdown => false,
        };
    }

    /**
     * File extension for the export output.
     */
    public function fileExtension(): string
    {
        return match ($this) {
            self::Text => 'txt',
            default => 'zip',
        };
    }

    /**
     * MIME type for serving the file via download endpoint.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Text => 'text/plain',
            default => 'application/zip',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
