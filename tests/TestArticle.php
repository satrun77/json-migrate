<?php

namespace Silverstripe\JsonMigrate\Tests;

use SilverStripe\Assets\Image;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Taxonomy\TaxonomyTerm;

/**
 * Dummy DataObject for testing.
 */
class TestArticle extends DataObject implements TestOnly
{
    private static string $table_name = 'TestArticle';
    private static bool $validation_enabled = true;

    private static array $db = [
        'Title' => 'Varchar',
        'PublishDate' => 'Datetime',
    ];

    private static array $many_many = [
        'Gallery' => Image::class,
        'Categories' => TaxonomyTerm::class,
    ];

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Title) {
            $result->addError('Title must not be empty', 'TOO_SHORT_TITLE');
        }

        return $result;
    }
}
