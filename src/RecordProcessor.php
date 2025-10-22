<?php

namespace Silverstripe\JsonMigrate;

use DateTime;
use Exception;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Taxonomy\TaxonomyTerm;
use SilverStripe\Versioned\Versioned;

class RecordProcessor
{
    protected string $dataObjectClass;
    protected array $fieldMap;
    protected string $imageFolder;
    protected bool $stopOnError;
    protected Logger $logger;

    protected array $retryableErrors = [
        'Duplicate entry',
        'Deadlock found',
        'Lock wait timeout exceeded',
        'Serialization failure',
        'try restarting transaction',
    ];

    public function __construct(
        string $dataObjectClass,
        array  $fieldMap,
        string $imageFolder,
        bool   $stopOnError,
        Logger $logger
    )
    {
        $this->dataObjectClass = $dataObjectClass;
        $this->fieldMap = $fieldMap;
        $this->imageFolder = $imageFolder;
        $this->stopOnError = $stopOnError;
        $this->logger = $logger;
    }

    public function process(array $record): void
    {
        $class = $this->dataObjectClass;
        /** @var DataObject $obj */
        $obj = Injector::inst()->create($class);

        $uniqueInfo = $this->findUniqueRecord($record);
        if ($uniqueInfo && $uniqueInfo['record']) {
            $obj = $uniqueInfo['record'];
        }

        foreach ($this->fieldMap as $src => $map) {
            $value = $record[$src] ?? null;
            if ($value === null) {
                continue;
            }

            $type = $map['type'] ?? 'scalar';

            match ($type) {
                'images' => $this->handleImage($obj, $map, $value),
                'taxonomy' => $this->handleTaxonomy($obj, $map, $value),
                default => $this->setScalar($obj, $map, $value),
            };
        }

        $results = $obj->validate();
        if (!$results->isValid()) {
            $msg = sprintf(
                'Validation failed for record: %s Errors: %s',
                json_encode($record),
                json_encode($results->getMessages())
            );
            $this->logger->log($msg);
            if ($this->stopOnError) {
                throw new RuntimeException($msg);
            }

            return;
        }

        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                Versioned::set_stage(Versioned::DRAFT);
                $obj->write();
                return;
            } catch (Exception $e) {
                if ($this->isRetryableError($e->getMessage())) {
                    usleep(200000 * ($i + 1));
                    continue;
                }
                throw $e;
            }
        }
    }

    protected function findUniqueRecord(array $item): ?array
    {
        foreach ($this->fieldMap as $jsonField => $map) {
            if (($map['type'] ?? '') === 'unique' && !empty($map['dest']) && isset($item[$jsonField])) {
                $value = $item[$jsonField];
                $existing = $this->dataObjectClass::get()->filter($map['dest'], $value)->first();
                return ['record' => $existing, 'value' => $value];
            }
        }
        return null;
    }

    protected function isRetryableError(string $message): bool
    {
        return (bool)array_filter(
            $this->retryableErrors,
            fn($pattern) => stripos($message, $pattern) !== false
        );
    }

    protected function setScalar(DataObject $obj, array $map, mixed $value): void
    {
        $dest = $map['dest'] ?? null;
        if (!$dest) {
            return;
        }

        if (($map['transform'] ?? null) === 'date') {
            $value = $this->transformDate($value, $map['input_format'] ?? null);
        }

        $obj->{$dest} = $value;
    }

    protected function handleTaxonomy(DataObject $obj, array $map, $value): void
    {
        $relation = $map['dest'] ?? null;
        $titleField = $map['title_field'] ?? 'Name';
        if (!$relation) {
            throw new Exception('Taxonomy mapping requires a "relation" key');
        }

        $terms = is_array($value) ? $value : array_map('trim', explode(',', (string)$value));

        foreach ($terms as $termTitle) {
            if ($termTitle === '') {
                continue;
            }
            $term = TaxonomyTerm::get()->filter($titleField, $termTitle)->first();
            if (!$term) {
                $term = TaxonomyTerm::create();
                $term->{$titleField} = $termTitle;
                $term->write();
            }

            if ($this->isRelationMany($obj, $relation)) {
                $obj->{$relation}()->add($term);
            } else {
                $idField = $relation . 'ID';
                $obj->{$idField} = $term->ID;
            }
        }
    }

    protected function handleImage(DataObject $obj, array $map, $value): void
    {
        $relation = $map['dest'] ?? null;
        if (!$relation) {
            throw new Exception('Image mapping requires a "relation" key');
        }

        $imagesData = [];
        if (!is_array($value)) {
            // Handle string value (single URL or comma-separated)
            $multiple = $map['multiple'] ?? false;
            $urls = $multiple ? array_map('trim', explode(',', (string)$value)) : [trim((string)$value)];
            foreach ($urls as $url) {
                if ($url) {
                    $imagesData[] = ['src' => $url];
                }
            }
        } else if (isset($value['src']) && is_string($value['src'])) {
            // Handle single image object
            $imagesData[] = $value;
        } else {
            // Handle array of image objects or array of strings
            foreach ($value as $item) {
                if (is_array($item) && !empty($item['src'])) {
                    $imagesData[] = $item;
                } else if (is_string($item) && trim($item)) {
                    $imagesData[] = ['src' => trim($item)];
                }
            }
        }

        $folder = Folder::find_or_make($this->imageFolder);
        if (!$folder) {
            throw new Exception('Unable to ensure asset folder: ' . $this->imageFolder);
        }

        foreach ($imagesData as $imageData) {
            $url = $imageData['src'] ?? '';
            if (!$url) {
                continue;
            }

            $existing = $this->findExistingFileByUrl($url);
            if ($existing) {
                $fileObj = $existing;
            } else {
                $fileObj = $this->downloadAndCreateImage($url, $folder);
            }

            if (!$fileObj) {
                continue;
            }

            if (!empty($imageData['title']) && $fileObj->Title !== $imageData['title']) {
                $fileObj->Title = $imageData['title'];
                $fileObj->write();
            }

            if ($this->isRelationMany($obj, $relation)) {
                $obj->{$relation}()->add($fileObj);
            } else {
                $idField = $relation . 'ID';
                $obj->{$idField} = $fileObj->ID;
            }
        }
    }

    protected function downloadAndCreateImage(string $url, Folder $folder): ?Image
    {
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH) ?: '');
        $ext = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : 'jpg';
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;

        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

        $success = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($tmpFile, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_USERAGENT, 'SilverStripe-importer/1.0');
            $r = curl_exec($ch);
            curl_close($ch);
            fclose($fp);
            if ($r !== false && file_exists($tmpFile) && filesize($tmpFile) > 0) {
                $success = true;
            } else {
                @unlink($tmpFile);
            }
        } else {
            $context = stream_context_create([
                'http' => ['timeout' => 60, 'header' => "User-Agent: SilverStripe-importer/1.0\r\n"]
            ]);
            $data = @file_get_contents($url, false, $context);
            if ($data !== false) {
                file_put_contents($tmpFile, $data);
                $success = true;
            }
        }

        if (!$success) {
            return null;
        }

        $image = Image::create();
        $image->setFromLocalFile($tmpFile, $filename);
        $image->ParentID = $folder->ID;
        $image->Title = $pathInfo['filename'] ?? $filename;
        $image->write();

        @unlink($tmpFile);

        return $image;
    }

    protected function findExistingFileByUrl(string $url): ?File
    {
        $fileName = pathinfo(parse_url($url, PHP_URL_PATH) ?: '')['basename'] ?? null;
        if (!$fileName) {
            return null;
        }

        $folder = Folder::find_or_make($this->imageFolder);
        if (!$folder) {
            return null;
        }

        return File::get()->filter([
            'Name' => $fileName,
            'ParentID' => $folder->ID
        ])->first();
    }

    protected function isRelationMany(DataObject $obj, string $relation): bool
    {
        $class = get_class($obj);
        $hasMany = $class::config()->get('has_many') ?? [];
        $manyMany = $class::config()->get('many_many') ?? [];
        return array_key_exists($relation, $hasMany) || array_key_exists($relation, $manyMany);
    }

    protected function transformDate(mixed $value, ?string $inputFormat = null): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $date = $inputFormat
                ? DateTime::createFromFormat($inputFormat, $value)
                : new DateTime((string)$value);

            return $date ? $date->format('Y-m-d H:i:s') : null;
        } catch (Exception) {
            return null;
        }
    }

    public function setLogger(Logger $logger): static
    {
        $this->logger = $logger;
        return $this;
    }
}
