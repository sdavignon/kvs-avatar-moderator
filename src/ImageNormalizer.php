<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class ImageNormalizer
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxDimension,
        private readonly int $outputSize,
        private readonly int $jpegQuality,
    ) {
    }

    /** @return array{mime: string, width: int, height: int, sha256: string} */
    public function normalize(string $source, string $destination, ?string $outputMime = null): array
    {
        clearstatcache(true, $source);
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > $this->maxBytes) {
            throw new InvalidImageException('Image file size is outside the allowed range');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME, true)) {
            throw new InvalidImageException('Only valid JPEG, PNG, and WebP files are accepted');
        }

        $geometry = @getimagesize($source);
        if (!is_array($geometry) || ($geometry[0] ?? 0) < 1 || ($geometry[1] ?? 0) < 1) {
            throw new InvalidImageException('Image dimensions could not be read');
        }
        [$width, $height] = [(int) $geometry[0], (int) $geometry[1]];
        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new InvalidImageException('Image dimensions exceed the configured maximum');
        }

        $outputMime ??= $mime;
        if (!in_array($outputMime, self::ALLOWED_MIME, true)) {
            throw new InvalidImageException('Unsupported output image format');
        }

        if (extension_loaded('imagick')) {
            $this->normalizeWithImagick($source, $destination, $outputMime);
        } elseif (extension_loaded('gd')) {
            $this->normalizeWithGd($source, $destination, $mime, $outputMime);
        } else {
            throw new \RuntimeException('The Imagick or GD PHP extension is required');
        }

        @chmod($destination, 0640);
        return [
            'mime' => $outputMime,
            'width' => $this->outputSize,
            'height' => $this->outputSize,
            'sha256' => hash_file('sha256', $source) ?: '',
        ];
    }

    public static function mimeForPath(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }

    private function normalizeWithImagick(string $source, string $destination, string $outputMime): void
    {
        try {
            $image = new \Imagick($source);
            if ($image->getNumberImages() !== 1) {
                throw new InvalidImageException('Animated or multi-frame images are not allowed');
            }
            $image->setIteratorIndex(0);
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }
            $image->stripImage();

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $side = min($width, $height);
            $image->cropImage($side, $side, intdiv($width - $side, 2), intdiv($height - $side, 2));
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage($this->outputSize, $this->outputSize, \Imagick::FILTER_LANCZOS, 1, false);

            $format = match ($outputMime) {
                'image/jpeg' => 'jpeg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            };
            if ($outputMime === 'image/jpeg') {
                $image->setImageBackgroundColor('white');
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $image->setImageFormat($format);
            $image->setImageCompressionQuality($outputMime === 'image/jpeg' ? $this->jpegQuality : 90);
            if (!$image->writeImage($destination)) {
                throw new \RuntimeException('ImageMagick could not write the normalized image');
            }
            $image->clear();
            $image->destroy();
        } catch (InvalidImageException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidImageException('ImageMagick rejected the uploaded image', 0, $exception);
        }
    }

    private function normalizeWithGd(string $source, string $destination, string $sourceMime, string $outputMime): void
    {
        $loader = match ($sourceMime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
        };
        if (!function_exists($loader)) {
            throw new \RuntimeException("GD cannot decode {$sourceMime}");
        }
        $sourceImage = @$loader($source);
        if (!$sourceImage instanceof \GdImage) {
            throw new InvalidImageException('GD rejected the uploaded image');
        }

        if ($sourceMime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $sourceImage = $this->applyGdOrientation($sourceImage, $orientation);
        }

        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $side = min($width, $height);
        $destinationImage = imagecreatetruecolor($this->outputSize, $this->outputSize);
        if (!$destinationImage instanceof \GdImage) {
            imagedestroy($sourceImage);
            throw new \RuntimeException('GD could not allocate the output image');
        }

        if ($outputMime === 'image/jpeg') {
            $white = imagecolorallocate($destinationImage, 255, 255, 255);
            imagefill($destinationImage, 0, 0, $white);
        } else {
            imagealphablending($destinationImage, false);
            imagesavealpha($destinationImage, true);
            $transparent = imagecolorallocatealpha($destinationImage, 0, 0, 0, 127);
            imagefill($destinationImage, 0, 0, $transparent);
        }

        imagecopyresampled(
            $destinationImage,
            $sourceImage,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $this->outputSize,
            $this->outputSize,
            $side,
            $side,
        );

        $written = match ($outputMime) {
            'image/jpeg' => imagejpeg($destinationImage, $destination, $this->jpegQuality),
            'image/png' => imagepng($destinationImage, $destination, 6),
            'image/webp' => function_exists('imagewebp') && imagewebp($destinationImage, $destination, 90),
        };
        imagedestroy($sourceImage);
        imagedestroy($destinationImage);
        if (!$written) {
            throw new \RuntimeException("GD could not encode {$outputMime}");
        }
    }

    private function applyGdOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated instanceof \GdImage) {
            throw new \RuntimeException('GD could not apply image orientation');
        }
        imagedestroy($image);
        return $rotated;
    }
}
