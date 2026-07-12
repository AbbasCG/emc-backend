<?php

namespace App\Enums;

enum MaterialType: string
{
    case Pdf                = 'pdf';
    case Image              = 'image';
    case Video              = 'video';
    case Audio              = 'audio';
    case Document           = 'document';
    case Spreadsheet        = 'spreadsheet';
    case Presentation       = 'presentation';
    case ProgrammingProject = 'programming_project';
    case GithubRepository   = 'github_repository';
    case GoogleColab        = 'google_colab';
    case YoutubeVideo       = 'youtube_video';
    case VimeoVideo         = 'vimeo_video';
    case ExternalResource   = 'external_resource';
    case Link               = 'link';
    case Other              = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Pdf                => 'PDF',
            self::Image              => 'صورة',
            self::Video              => 'فيديو',
            self::Audio              => 'صوت',
            self::Document           => 'مستند',
            self::Spreadsheet        => 'جدول بيانات',
            self::Presentation       => 'عرض تقديمي',
            self::ProgrammingProject => 'مشروع برمجي (ZIP)',
            self::GithubRepository   => 'مستودع GitHub',
            self::GoogleColab        => 'Google Colab',
            self::YoutubeVideo       => 'فيديو YouTube',
            self::VimeoVideo         => 'فيديو Vimeo',
            self::ExternalResource   => 'رابط خارجي',
            self::Link               => 'رابط',
            self::Other              => 'أخرى',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pdf                => '📄',
            self::Image              => '🖼',
            self::Video              => '🎥',
            self::Audio              => '🎙',
            self::Document           => '📘',
            self::Spreadsheet        => '📊',
            self::Presentation       => '📑',
            self::ProgrammingProject => '💻',
            self::GithubRepository   => '🐙',
            self::GoogleColab        => '📓',
            self::YoutubeVideo       => '▶️',
            self::VimeoVideo         => '🎬',
            self::ExternalResource   => '🌍',
            self::Link               => '🔗',
            self::Other              => '📁',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pdf                => 'red',
            self::Image              => 'green',
            self::Video              => 'blue',
            self::Audio              => 'indigo',
            self::Document           => 'slate',
            self::Spreadsheet        => 'emerald',
            self::Presentation       => 'amber',
            self::ProgrammingProject => 'purple',
            self::GithubRepository   => 'gray',
            self::GoogleColab        => 'orange',
            self::YoutubeVideo       => 'red',
            self::VimeoVideo         => 'cyan',
            self::ExternalResource   => 'teal',
            self::Link               => 'sky',
            self::Other              => 'zinc',
        };
    }

    /** URL-only types require no file upload — only an external_url. */
    public function isUrlOnly(): bool
    {
        return in_array($this, [
            self::GithubRepository,
            self::GoogleColab,
            self::YoutubeVideo,
            self::VimeoVideo,
            self::ExternalResource,
            self::Link,
        ], true);
    }

    public function requiresFile(): bool
    {
        return ! $this->isUrlOnly();
    }

    public function isImage(): bool
    {
        return $this === self::Image;
    }

    public function isZip(): bool
    {
        return $this === self::ProgrammingProject;
    }

    /** Maximum upload size in kilobytes for this type. */
    public function maxSizeKb(): int
    {
        return config('materials.max_size.' . $this->value)
            ?? config('materials.max_size.other', 51200);
    }

    /** Allowed MIME types for this type (empty = no restriction). */
    public function allowedMimes(): array
    {
        return config('materials.allowed_mimes.' . $this->value, []);
    }

    /** Allowed file extensions for this type. */
    public function allowedExtensions(): array
    {
        return config('materials.allowed_extensions.' . $this->value, []);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
