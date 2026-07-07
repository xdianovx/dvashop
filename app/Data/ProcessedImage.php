<?php

namespace App\Data;

class ProcessedImage
{
    /**
     * @param array<string, array{disk:string,path:string,width:int,height:int,size:int,mime:string}>|null $conversions
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $mime,
        public readonly string $checksum,
        public readonly ?string $originalUrl = null,
        public readonly ?array $conversions = null,
        public readonly ?string $originalPath = null,
    ) {}

    /** @return array<string, mixed> */
    public function toProductImageAttributes(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'original_path' => $this->originalPath,
            'source_url' => $this->originalUrl,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'conversions' => $this->conversions,
        ];
    }
}
