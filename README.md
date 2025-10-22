# SilverStripe JSON Migrate

A SilverStripe module to assist with migrating data stored in JSON/JSONL files into SilverStripe DataObjects.

---

## ✨ Features

*   **Batch Processing**: Import large JSON files in manageable chunks.
*   **Resumable Imports**: Automatically resumes from the last checkpoint if an import is interrupted.
*   **Flexible Field Mapping**: Map JSON fields to SilverStripe DataObject fields, including support for images and taxonomy.
*   **YAML Configuration**: Define your migrations in a simple YAML file.
*   **Command-Line Interface**: Run your migrations from the command line using a `BuildTask`.

---

## 📋 Requirements

*   PHP: `^8.3`
*   silverstripe/framework: `^6`
*   halaxa/json-machine: `^1.2`

---

## 🚀 Installation

```bash
composer require silverstripe/silverstripe-json-migrate
```

---

## 💻 Usage with CLI

1.  **Create a YAML configuration file.**

    Create a `json-migrations.yml` file in your `app/_config` directory. This file will define your migrations.

    ```yaml
    JsonMigrations:
      - file: /path/to/your/data.json
        class: App\Models\MyDataObject
        targetFolder: 'imported-images'
        fieldMap:
          # Simple scalar mapping
          JSON_Title: 
            dest: Title
          # Unique identifier for finding existing records
          JSON_ID:
            type: unique
            dest: ExternalID
          # Image import
          JSON_Image:
            type: images
            dest: MyImageRelation # has_one, has_many, or many_many
          # Taxonomy import
          JSON_Tags:
            type: taxonomy
            dest: MyTagsRelation # has_one, has_many, or many_many
        batchSize: 200
        resume: true
        stopOnError: false
    ```

2.  **Run the migration task.**

    Run the `json-migrate` task from the command line.

    ```bash
    sake dev/tasks/json-migrate
    ```

    You can also specify a custom configuration file path:

    ```bash
    sake dev/tasks/json-migrate --config=/path/to/your/config.yml
    ```

    And enable debug output:

    ```bash
    sake dev/tasks/json-migrate --debug
    ```

---

## 👨‍💻 Programmatic Usage

While the `MigrationTask` is convenient for running migrations from a YAML file, you can also use the `JsonImporter` class directly in your own code for more complex scenarios.

```php
use Silverstripe\JsonMigrate\JsonImporter;
use App\Models\MyDataObject;

// Example of using the importer in your own BuildTask or script

$fieldMap = [
    'JSON_ID' => ['type' => 'unique', 'dest' => 'ExternalID'],
    'JSON_Title' => ['dest' => 'Title'],
    'JSON_Content' => ['dest' => 'Content'],
    'JSON_Image' => ['type' => 'images', 'dest' => 'MyImageRelation'],
    'JSON_Tags' => ['type' => 'taxonomy', 'dest' => 'MyTagsRelation'],
];

$importer = new JsonImporter(
    '/path/to/your/data.json', // Path to the JSON/JSONL file
    MyDataObject::class,       // The DataObject class to import into
    $fieldMap,                 // The field mapping configuration
    'imported-images',         // The target folder for imported images
    200,                       // Batch size (optional, defaults to 100)
    true,                      // Enable debug logging (optional, defaults to false)
    false                      // Stop on error (optional, defaults to true)
);

// Run the import
// Pass `true` to the process method to resume from the last checkpoint
$importer->process(true);
```

---

## ⏱️ Checkpointing & Batching

To handle large datasets and prevent memory issues or timeouts, the importer processes files in chunks (batches). This feature is controlled by the `batchSize` option.

### How it works:

*   **Batching**: The importer reads the JSON file and processes a set number of records (`batchSize`) at a time. After each batch, it saves a checkpoint and the script can pause or continue. This is useful for very large files that can't be processed in a single run.

*   **Checkpointing**: After each batch is processed, the importer saves a "checkpoint" that marks the position of the last successfully imported record. This checkpoint is a small file stored in your system's temporary directory.

*   **Resuming**: If an import process is interrupted (e.g., due to an error, timeout, or manual cancellation), you can restart it. If the `resume` option is enabled (`true` by default), the importer will look for a checkpoint file. If one is found, it will skip all the records that have already been processed and resume the import from where it left off.

*   **Cleanup**: Once the entire JSON file has been processed successfully, the corresponding checkpoint file is automatically deleted.

This combination of batching and checkpointing makes the import process robust and reliable, even for massive datasets.

---

## ⚙️ Configuration Options

*   `file`: The absolute path to the JSON or JSONL file to import.
*   `class`: The SilverStripe `DataObject` class to import the data into.
*   `targetFolder`: The folder in your `assets` directory to store imported images. Defaults to `Uploads`.
*   `fieldMap`: A map of JSON field names to `DataObject` field names and types. See "Field Mapping" below.
*   `batchSize`: The number of records to process in each batch. Defaults to `500`.
*   `resume`: Whether to resume the import from the last checkpoint. Defaults to `true`.
*   `stopOnError`: Whether to stop the import if an error occurs. Defaults to `false`.

---

## 🗺️ Field Mapping

The `fieldMap` is the core of the import configuration. It tells the importer how to map data from your JSON file to your `DataObject` fields.

### Scalar

For simple text, number, or boolean fields. This is the default type if no `type` is specified.

```yaml
fieldMap:
  JSON_FieldName:
    dest: DataObjectFieldName
```

### Unique

Use this to specify a unique identifier for your records. The importer will use this field to find existing records and update them instead of creating new ones.

```yaml
fieldMap:
  JSON_ID:
    type: unique
    dest: ExternalID
```

### Images

For importing images from URLs. The importer will download the image, create a `SilverStripe\Assets\Image` object, and attach it to your `DataObject`.

*   `dest`: The name of the image relation on your `DataObject` (can be `has_one`, `has_many`, or `many_many`).
*   `multiple`: Set to `true` if the JSON field contains a comma-separated list of URLs.

**JSON Structure for Images**

You can structure the image data in your JSON file in several ways:

*   **Single URL String**: `"JSON_Image": "http://example.com/image.jpg"`
*   **Array of URL Strings**: `"JSON_Image": ["http://example.com/image1.jpg", "http://example.com/image2.jpg"]`
*   **Single Image Object**: `"JSON_Image": {"src": "http://example.com/image.jpg", "title": "My Image"}`
*   **Array of Image Objects**: `"JSON_Image": [{"src": "http://example.com/image1.jpg", "title": "Image 1"}, {"src": "http://example.com/image2.jpg", "title": "Image 2"}]`

### Taxonomy

For importing taxonomy terms. The importer will find or create `SilverStripe\Taxonomy\TaxonomyTerm` objects and attach them to your `DataObject`.

*   `dest`: The name of the taxonomy relation on your `DataObject` (can be `has_one`, `has_many`, or `many_many`).
*   `title_field`: The field on the `TaxonomyTerm` to match against. Defaults to `Name`.

**JSON Structure for Taxonomy**

*   **Single String**: `"JSON_Tags": "My Tag"`
*   **Comma-separated String**: `"JSON_Tags": "Tag 1, Tag 2, Tag 3"`
*   **Array of Strings**: `"JSON_Tags": ["Tag 1", "Tag 2"]`

---

## 🧠 How it Works

The `MigrationTask` reads the YAML configuration file and runs one or more migrations using the `JsonImporter`.

The `JsonImporter` iterates over the JSON/JSONL file, processing the data in batches. It uses a `CheckpointManager` to keep track of the last successfully imported record, so that it can resume from that point if the import is interrupted.

For each record in the JSON file, the `RecordProcessor` creates or updates a `DataObject` and maps the JSON fields to the `DataObject` fields according to the `fieldMap`.

---

## 🤔 Troubleshooting & Notes

*   **Memory Issues**: If you encounter memory limit errors, try reducing the `batchSize` in your YAML configuration. This will process fewer records at a time, using less memory.

*   **Image Import Failures**: If images are not being imported, check the following:
    *   The image URLs in your JSON are correct and accessible.
    *   The `targetFolder` has the correct file permissions.
    *   The `curl` or `file_get_contents` functions are available in your PHP environment.

*   **Data Not Importing Correctly**: If data isn't appearing in the correct fields, double-check your `fieldMap` configuration. Ensure the `dest` and `type` values are correct. Running the task with the `--debug` flag can provide more insight into how the data is being processed.

*   **Task Fails to Start**: If the migration task fails to start, check for syntax errors in your YAML file. You can use an online YAML validator to verify your configuration. Also, ensure the `file` path in your configuration is correct.

*   **Forcing a Re-import**: If you need to re-import all data from scratch, you can disable the `resume` option (`resume: false`) in your YAML configuration. Alternatively, you can manually delete the checkpoint file from your system's temporary directory. The checkpoint file is named based on a hash of the JSON file path.

---

## 🧪 Testing

This module is tested with PHPUnit. To run the tests, run the following command:

```bash
composer test
```

---

## 🤝 Contributing

Contributions, bug reports and pull requests are welcome. Please follow the repository coding standards (see `phpcs.xml`) and include tests for new behaviors.

1. Fork the repo
2. Create a feature branch
3. Run tests and ensure they pass
4. Create a PR with a description of changes

---

## 📜 License

This module is licensed under BSD-3-Clause. See `LICENSE.md`.
