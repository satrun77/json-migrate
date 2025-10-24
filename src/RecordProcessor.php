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

/**
 * Processes a single JSON record, mapping its data to a SilverStripe DataObject.
 * Handles creation, updates, and complex field types like images and taxonomy terms.
 */
class RecordProcessor
{
    /**
     * @var string The FQCN of the DataObject to import into.
     */
    protected string $dataObjectClass;

    /**
     * @var array The mapping of JSON fields to DataObject properties.
     */
    protected array $fieldMap;

    /**
     * @var string The folder to store imported images in.
     */
    protected string $imageFolder;

    /**
     * @var bool If true, the import will stop on the first error.
     */
    protected bool $stopOnError;

    /**
     * @var Logger Handles logging of import progress and errors.
     */
    protected Logger $logger;

    /**
     * @var array A list of database error messages that are considered temporary and can be retried.
     */
    protected array $retryableErrors = [
        'Duplicate entry',
        'Deadlock found',
        'Lock wait timeout exceeded',
        'Serialization failure',
        'try restarting transaction',
    ];

    /**
     * @param string $dataObjectClass The FQCN of the DataObject to import into.
     * @param array $fieldMap The mapping of JSON fields to DataObject properties.
     * @param string $imageFolder The folder to store imported images in.
     * @param bool $stopOnError If true, the import will stop on the first error.
     * @param Logger $logger Handles logging of import progress and errors.
     */
    public function __construct(
        string $dataObjectClass,
        array  $fieldMap,
        string $imageFolder,
        bool   $stopOnError,
        Logger $logger
    ) {
        $this->dataObjectClass = $dataObjectClass;
        $this->fieldMap = $fieldMap;
        $this->imageFolder = $imageFolder;
        $this->stopOnError = $stopOnError;
        $this->logger = $logger;
    }

    /**
     * Processes a single JSON record.
     *
     * @param array $record The JSON record to process.
     * @throws Exception If a non-retryable error occurs during database write.
     * @throws RuntimeException If validation fails and stopOnError is true.
     */
    public function process(array $record): void
    {
        $class = $this->dataObjectClass;
        /** @var DataObject $obj */
        $obj = Injector::inst()->create($class);

        // Check for an existing record using the unique identifier
        $uniqueInfo = $this->findUniqueRecord($record);
        if ($uniqueInfo && $uniqueInfo['record']) {
            $obj = $uniqueInfo['record'];
        }

        // Map fields from the JSON record to the DataObject
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

        // Validate the DataObject before writing
        $results = $obj->validate();
        if (!$results->isValid()) {
            $msg = sprintf(
                'Validation failed for record: %s Errors: %s',
                json_encode($record),
                json_encode($results->getMessages()),
            );
            $this->logger->log($msg);

            if ($this->stopOnError) {
                throw new RuntimeException($msg);
            }

            return;
        }

        // Write to the database with retry logic for common transient errors
        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                Versioned::set_stage(Versioned::DRAFT);
                $obj->write();

                return; // Success
            } catch (\Throwable $e) {
                if ($this->isRetryableError($e->getMessage())) {
                    usleep(200000 * ($i + 1)); // Wait before retrying

                    continue;
                }

                throw $e; // Non-retryable error
            }
        }
    }

    /**
     * Finds an existing DataObject based on a unique field specified in the field map.
     *
     * @param array $item The JSON record.
     * @return array|null An array containing the existing record and its unique value, or null if not found.
     */
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

    /**
     * Checks if a database error message indicates a retryable condition.
     *
     * @param string $message The error message.
     * @return bool True if the error is retryable.
     */
    protected function isRetryableError(string $message): bool
    {
        return (bool)array_filter(
            $this->retryableErrors,
            fn($pattern) => stripos($message, $pattern) !== false,
        );
    }

    /**
     * Sets a scalar value on the DataObject, with optional date transformation.
     *
     * @param DataObject $obj The DataObject to modify.
     * @param array $map The field mapping configuration.
     * @param mixed $value The value to set.
     */
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

    /**
     * Handles mapping of taxonomy terms to a DataObject relation.
     *
     * @param DataObject $obj The DataObject to modify.
     * @param array $map The field mapping configuration.
     * @param mixed $value The taxonomy data from the JSON record.
     * @throws Exception If the 'dest' key is missing in the mapping.
     */
    protected function handleTaxonomy(DataObject $obj, array $map, mixed $value): void
    {
        $relation = $map['dest'] ?? null;
        $titleField = $map['title_field'] ?? 'Name';

        if (!$relation) {
            throw new Exception('Taxonomy mapping requires a "relation" key');
        }

        // Standardize input to an array of term titles
        $terms = is_array($value) ? $value : array_map('trim', explode(',', (string)$value));

        foreach ($terms as $termTitle) {
            if ($termTitle === '') {
                continue;
            }

            // Find or create the taxonomy term
            $term = TaxonomyTerm::get()->filter($titleField, $termTitle)->first();
            if (!$term) {
                $term = TaxonomyTerm::create();
                $term->{$titleField} = $termTitle;
                $term->write();
            }

            // Add the term to the relation
            if ($this->isRelationMany($obj, $relation)) {
                $obj->{$relation}()->add($term);
            } else {
                $idField = $relation . 'ID';
                $obj->{$idField} = $term->ID;
            }
        }
    }

    /**
     * Handles mapping of images to a DataObject relation.
     *
     * @param DataObject $obj The DataObject to modify.
     * @param array $map The field mapping configuration.
     * @param mixed $value The image data from the JSON record.
     * @throws Exception If the 'dest' key is missing or the asset folder cannot be created.
     */
    protected function handleImage(DataObject $obj, array $map, mixed $value): void
    {
        $relation = $map['dest'] ?? null;
        if (!$relation) {
            throw new Exception('Image mapping requires a "relation" key');
        }

        // Standardize input to an array of image data arrays
        $imagesData = [];
        if (!is_array($value)) {
            $multiple = $map['multiple'] ?? false;
            $urls = $multiple ? array_map('trim', explode(',', (string)$value)) : [trim((string)$value)];

            foreach ($urls as $url) {
                if ($url) {
                    $imagesData[] = ['src' => $url];
                }
            }
        } elseif (isset($value['src']) && is_string($value['src'])) {
            $imagesData[] = $value;
        } else {
            foreach ($value as $item) {
                if (is_array($item) && !empty($item['src'])) {
                    $imagesData[] = $item;
                } elseif (is_string($item) && trim($item)) {
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

            // Find existing or download and create new image
            $existing = $this->findExistingFileByUrl($url);

            if ($existing) {
                $fileObj = $existing;
            } else {
                $fileObj = $this->downloadAndCreateImage($url, $folder);
            }

            if (!$fileObj) {
                continue;
            }

            // Update image title if provided
            if (!empty($imageData['title']) && $fileObj->Title !== $imageData['title']) {
                $fileObj->Title = $imageData['title'];
                $fileObj->write();
            }

            // Add the image to the relation
            if ($this->isRelationMany($obj, $relation)) {
                $obj->{$relation}()->add($fileObj);
            } else {
                $idField = $relation . 'ID';
                $obj->{$idField} = $fileObj->ID;
            }
        }
    }

    /**
     * Downloads an image from a URL and creates an Image object.
     *
     * @param string $url The URL of the image to download.
     * @param Folder $folder The folder to store the image in.
     * @return Image|null The created Image object, or null on failure.
     */
    protected function downloadAndCreateImage(string $url, Folder $folder): ?Image
    {
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH) ?: '');
        $ext = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : 'jpg';
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;

        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

        // Download the file using cURL or file_get_contents
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
                'http' => ['timeout' => 60, 'header' => "User-Agent: SilverStripe-importer/1.0\r\n"],
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

        // Create the SilverStripe Image object
        $image = Image::create();
        $image->setFromLocalFile($tmpFile, $filename);
        $image->ParentID = $folder->ID;
        $image->Title = $pathInfo['filename'] ?? $filename;
        $image->write();

        @unlink($tmpFile);

        return $image;
    }

    /**
     * Finds an existing file in the target folder by its original URL.
     *
     * @param string $url The original URL of the file.
     * @return File|null The existing File object, or null if not found.
     */
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
            'ParentID' => $folder->ID,
        ])->first();
    }

    /**
     * Checks if a relation on a DataObject is a has_many or many_many relationship.
     *
     * @param DataObject $obj The DataObject.
     * @param string $relation The name of the relation.
     * @return bool True if it is a has-many or many-many relation.
     */
    protected function isRelationMany(DataObject $obj, string $relation): bool
    {
        $class = get_class($obj);
        $hasMany = $class::config()->get('has_many') ?? [];
        $manyMany = $class::config()->get('many_many') ?? [];
        return array_key_exists($relation, $hasMany) || array_key_exists($relation, $manyMany);
    }

    /**
     * Transforms a date string into a format suitable for the database.
     *
     * @param mixed $value The date value from the JSON.
     * @param string|null $inputFormat The format of the input date string.
     * @return string|null The formatted date string, or null on failure.
     */
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
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param Logger $logger
     * @return static
     */
    public function setLogger(Logger $logger): static
    {
        $this->logger = $logger;

        return $this;
    }
}
